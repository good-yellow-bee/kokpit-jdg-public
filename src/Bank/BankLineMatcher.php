<?php

declare(strict_types=1);

namespace App\Bank;

use App\Calendar\IsoDate;
use App\Config\ConnectorConfiguration;
use App\Ledger\CashFlow;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Propozycje dopasowań wierszy wyciągu (D-108): wpływ → faktura sprzedaży albo płatność po numerze w opisie i kwocie,
 * przelew do ZUS albo US → otwarty obowiązek płatniczy z tą kwotą, za okres zakończony przed operacją; wydatek → niezapłacony koszt
 * z tą kwotą brutto, gdy rachunek odbiorcy, NIP dostawcy albo numer faktury wskazują dostawcę (D-110). Propozycja powstaje tylko
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
        $accounts = [SettingKeys::ZUS_ACCOUNT => $this->settings->value(SettingKeys::ZUS_ACCOUNT), SettingKeys::TAX_MICRO_ACCOUNT => $this->settings->value(SettingKeys::TAX_MICRO_ACCOUNT)];
        foreach ($lines as $line) {
            $candidates[$line->hash] = $this->candidates($line, $accounts);
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

    /**
     * @param array<string,mixed> $accounts klucz `setting` rachunku → wartość
     * @return list<BankMatch>
     */
    private function candidates(BankLine $line, array $accounts): array
    {
        if ($line->kind === BankLineKind::Internal) {
            return [];
        }
        $zus = $line->kind === BankLineKind::Zus || self::isAccount($line, $accounts[SettingKeys::ZUS_ACCOUNT]);
        if ($zus || $line->kind === BankLineKind::Tax || self::isAccount($line, $accounts[SettingKeys::TAX_MICRO_ACCOUNT])) {
            return $this->obligations($line, $zus);
        }
        return $line->kind === BankLineKind::Incoming ? [...$this->invoices($line), ...$this->revenuePayments($line)] : $this->costs($line);
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

    /**
     * Koszt bez `paid_on` z kwotą brutto równą obciążeniu i co najmniej jednym wskazaniem dostawcy: rachunek odbiorcy
     * z `counterparty.bank_accounts` albo potwierdzony w białej liście (`valid`), NIP (w podzielonej płatności pole `/IDC/`) albo
     * numer faktury lub numer KSeF w tytule. Blokada jak przy obowiązkach: `paid_on` nie zmieni się przed `markPaid`.
     *
     * @return list<BankMatch>
     */
    private function costs(BankLine $line): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT i.id, i.invoice_number, i.ksef_number, c.id AS counterparty_id, c.name, c.nip, c.bank_accounts::text AS bank_accounts FROM invoice i JOIN counterparty c ON c.id=i.counterparty_id
             WHERE i.environment=? AND ' . CashFlow::PAYABLE . ' AND i.currency=? AND i.gross_total=?
             AND NOT EXISTS (SELECT 1 FROM bank_statement_line b WHERE b.invoice_id=i.id) ORDER BY i.id FOR UPDATE OF i',
            [$this->configuration->environment(), $line->currency, $line->amount->abs()],
        );
        $matches = [];
        foreach ($rows as $row) {
            $number = self::text($row['invoice_number']);
            $accounts = [...self::entries(json_decode(self::text($row['bank_accounts']), true, 4, JSON_THROW_ON_ERROR)), ...$this->db->fetchFirstColumn(
                "SELECT bank_account FROM verification WHERE counterparty_id=? AND source='white_list' AND result='valid' AND bank_account IS NOT NULL",
                [$row['counterparty_id']],
            )];
            $evidence = array_keys(array_filter([
                'rachunek dostawcy' => in_array($line->counterpartyAccount, array_map(self::account(...), $accounts), true),
                'NIP w tytule' => is_string($row['nip']) && self::mentions($line->description, $row['nip']),
                'numer w tytule' => self::mentions($line->description, $number) || (is_string($row['ksef_number']) && self::mentions($line->description, $row['ksef_number'])),
            ]));
            if ($evidence !== []) {
                $matches[] = new BankMatch(BankMatchRule::Cost, sprintf('koszt %s od %s (%s)', $number, self::text($row['name']), implode(', ', $evidence)), self::text($row['id']));
            }
        }
        return $matches;
    }

    /** NRB z wpisu `bank_accounts` (tekst albo obiekt z polem `number`) bez spacji i prefiksu PL. */
    private static function account(mixed $entry): string
    {
        $value = is_array($entry) ? ($entry['number'] ?? '') : $entry;
        $compact = strtoupper((string) preg_replace('/\s/', '', is_string($value) ? $value : ''));
        return str_starts_with($compact, 'PL') ? substr($compact, 2) : $compact;
    }

    /** @return array<mixed> */
    private static function entries(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function isAccount(BankLine $line, mixed $account): bool
    {
        return $line->counterpartyAccount !== null && $line->counterpartyAccount === $account;
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
