<?php

declare(strict_types=1);

namespace App\Bank;

use App\Calendar\IsoDate;
use App\Config\ConnectorConfiguration;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Propozycje dopasowań wierszy wyciągu (D-108): wpływ → faktura sprzedaży albo płatność po numerze w opisie i kwocie,
 * przelew do ZUS albo US → otwarty obowiązek płatniczy z tą kwotą, za okres zakończony przed operacją. Propozycja powstaje tylko
 * przy jednoznaczności w obie strony: wiersz ma jednego kandydata, a kandydat jeden wiersz wśród nowych operacji; cel
 * powiązany już w bazie odpada.
 */
final readonly class BankLineMatcher
{
    private const ZUS_TYPES = ['zus','health_reconciliation'];
    private const TAX_TYPES = ['ryczalt_advance','wht_payment'];
    /** Symbol formularza w tytule przelewu podatkowego → typ obowiązku; `null` = wpłata bez obowiązku płatniczego (VAT zamyka JPK). */
    private const SYMBOLS = ['VAT-7' => null,'VAT-7K' => null,'PPE' => 'ryczalt_advance','PIT-28' => 'ryczalt_advance','CIT-10Z' => 'wht_payment'];

    public function __construct(private Connection $db, private SettingStore $settings, private ConnectorConfiguration $configuration) {}

    /**
     * @param list<BankLine> $lines
     * @return array<string,BankMatch> `line_hash` → propozycja
     */
    public function propose(array $lines): array
    {
        $candidates = [];
        $claims = [];
        foreach ($lines as $line) {
            $candidates[$line->hash] = $this->candidates($line);
            foreach ($candidates[$line->hash] as $match) {
                foreach ($match->targets() as $target) {
                    $claims[$target] = ($claims[$target] ?? 0) + 1;
                }
            }
        }
        $proposals = [];
        foreach ($candidates as $hash => $matches) {
            if (count($matches) === 1 && array_all($matches[0]->targets(), static fn(string $target): bool => $claims[$target] === 1)) {
                $proposals[$hash] = $matches[0];
            }
        }
        return $proposals;
    }

    /** @return list<BankMatch> */
    private function candidates(BankLine $line): array
    {
        if ($line->kind === BankLineKind::Internal) {
            return [];
        }
        $zus = $line->kind === BankLineKind::Zus || $this->isAccount($line, SettingKeys::ZUS_ACCOUNT);
        if ($zus || $line->kind === BankLineKind::Tax || $this->isAccount($line, SettingKeys::TAX_MICRO_ACCOUNT)) {
            return $this->obligations($line, $zus);
        }
        return $line->kind === BankLineKind::Incoming ? [...$this->invoices($line), ...$this->revenuePayments($line)] : [];
    }

    /** @return list<BankMatch> */
    private function obligations(BankLine $line, bool $zus): array
    {
        if (!$line->amount->isNegative() || $line->currency !== 'PLN') {
            return [];
        }
        // Blokada do końca transakcji zatwierdzenia: kwota i stan obowiązku nie zmienią się przed `recordPayment`.
        $rows = $this->db->fetchAllAssociative(
            "SELECT o.id, o.type, o.rule_key, o.period_start, o.due_date FROM obligation o WHERE o.environment=? AND o.status='open' AND o.type IN (?) AND o.amount_due=?
             AND o.period_start + CASE WHEN o.rule_key LIKE '%.monthly' THEN interval '1 month' WHEN o.rule_key LIKE '%.quarterly' THEN interval '3 months' WHEN o.rule_key LIKE '%.yearly' THEN interval '1 year' ELSE interval '0 days' END <= ?::date
             AND NOT EXISTS (SELECT 1 FROM bank_statement_line b WHERE b.obligation_id=o.id) ORDER BY o.due_date, o.id FOR UPDATE OF o",
            [$this->configuration->environment(), $zus ? self::ZUS_TYPES : self::TAX_TYPES, $line->amount->abs(), $line->bookedOn],
            [1 => ArrayParameterType::STRING],
        );
        $symbolTypes = array_filter(self::SYMBOLS, static fn(string $symbol): bool => self::mentions($line->description, $symbol), ARRAY_FILTER_USE_KEY);
        preg_match_all('/(?<![0-9A-Z])[0-9]{2}(?:[MK][0-9]{2}|R)(?![0-9A-Z])/', strtoupper($line->description), $periods);
        $matches = [];
        foreach ($rows as $row) {
            $type = self::text($row['type']);
            $periodStart = self::text($row['period_start']);
            if (!$zus && $symbolTypes !== [] && !in_array($type, $symbolTypes, true)) {
                continue;
            }
            if ($periods[0] !== [] && !in_array(self::periodToken(self::text($row['rule_key']), $periodStart), $periods[0], true)) {
                continue;
            }
            $matches[] = new BankMatch($zus ? BankMatchRule::Zus : BankMatchRule::Tax, sprintf('obowiązek %s za %s, termin %s', $type, $periodStart, self::text($row['due_date'])), obligationId: self::text($row['id']));
        }
        return $matches;
    }

    /** @return list<BankMatch> */
    private function invoices(BankLine $line): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT i.id, i.invoice_number FROM invoice i WHERE i.environment=? AND i.direction='sale' AND i.kind='VAT' AND i.status='accepted' AND i.currency=? AND i.gross_total=?
             AND NOT EXISTS (SELECT 1 FROM bank_statement_line b WHERE b.invoice_id=i.id) ORDER BY i.id",
            [$this->configuration->environment(), $line->currency, $line->amount],
        );
        $matches = [];
        foreach ($rows as $row) {
            $number = self::text($row['invoice_number']);
            if (!self::mentions($line->description, $number)) {
                continue;
            }
            $payments = $this->db->fetchFirstColumn(
                'SELECT p.id FROM payment p WHERE p.environment=? AND p.invoice_id=? AND p.currency=? AND p.amount=? AND NOT EXISTS (SELECT 1 FROM bank_statement_line b WHERE b.payment_id=p.id)',
                [$this->configuration->environment(), $row['id'], $line->currency, $line->amount],
            );
            $payment = count($payments) === 1 ? self::text($payments[0]) : null;
            $matches[] = new BankMatch(BankMatchRule::Invoice, 'faktura ' . $number . ($payment === null ? '' : ' i jej płatność'), self::text($row['id']), $payment);
        }
        return $matches;
    }

    /** @return list<BankMatch> płatności z wierszem ewidencji bez faktury w bazie (sprzed przejścia, poza KSeF) */
    private function revenuePayments(BankLine $line): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.id, r.document_number FROM payment p JOIN revenue_entry r ON r.id=p.revenue_entry_id WHERE p.environment=? AND p.currency=? AND p.amount=?
             AND NOT EXISTS (SELECT 1 FROM bank_statement_line b WHERE b.payment_id=p.id) ORDER BY p.id',
            [$this->configuration->environment(), $line->currency, $line->amount],
        );
        $matches = [];
        foreach ($rows as $row) {
            $number = self::text($row['document_number']);
            if (self::mentions($line->description, $number)) {
                $matches[] = new BankMatch(BankMatchRule::Payment, 'płatność dokumentu ' . $number, paymentId: self::text($row['id']));
            }
        }
        return $matches;
    }

    private function isAccount(BankLine $line, string $key): bool
    {
        return $line->counterpartyAccount !== null && $line->counterpartyAccount === $this->settings->value($key);
    }

    /** Okres z tytułu przelewu (`RRMnn`, `RRKnn`; roczne `RRR` nie pasuje do żadnego obowiązku) według szablonu tytułów `ObligationEngine`; kwartał zaczyna się w 1., 4., 7. albo 10. miesiącu. */
    private static function periodToken(string $ruleKey, string $periodStart): string
    {
        $month = IsoDate::month($periodStart);
        return substr($periodStart, 2, 2) . ($ruleKey === 'ryczalt_advance.quarterly' ? sprintf('K%02d', intdiv($month, 3) + 1) : sprintf('M%02d', $month));
    }

    /** Całe słowo: przed i po nim nie stoi litera ani cyfra, więc „1/2026” nie trafia w „11/2026”. */
    private static function mentions(string $haystack, string $needle): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote(mb_strtoupper($needle), '/') . '(?![\p{L}\p{N}])/u', mb_strtoupper($haystack)) === 1;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
