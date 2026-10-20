<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Audit\AuditLog;
use App\Calendar\IsoDate;
use App\Config\ConnectorConfiguration;
use App\Invoice\VatFx;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * Korekta przychodu z przyjętej faktury KOR (docs/modules/ewidencja.md, „Korekty” i „Wiersz `correction` z KOR”):
 * zadanie w panelu, po jego zamknięciu wiersz `correction` na każdą stawkę. K_8 to różnica netto KOR w stawce
 * (pozycje po korekcie minus `StanPrzed`) × kurs PIT wiersza korygowanego; K_3 to P_1 KOR, a przy błędzie rachunkowym
 * albo oczywistej omyłce K_3 wiersza korygowanego.
 */
final readonly class CorrectionLedger
{
    public const REASONS = ['other','arithmetic_error'];

    public function __construct(
        private Connection $db,
        private RevenueEntryWriter $writer,
        private SettingStore $settings,
        private AuditLog $audit,
        private ConnectorConfiguration $configuration,
    ) {}

    /**
     * Zadania „korekta z KOR”: przyjęte KOR sprzedaży bez wierszy `correction`.
     *
     * @return list<array{id:string,invoice_number:string,ksef_number:?string,issue_date:string,corrected_invoice_number:?string}>
     */
    public function pending(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT i.id, i.invoice_number, i.ksef_number, i.issue_date, i.corrected_invoice_number FROM invoice i
             WHERE i.environment=? AND i.direction='sale' AND i.kind='KOR' AND i.status IN ('accepted','corrected','superseded')
             AND NOT EXISTS (SELECT 1 FROM revenue_entry r WHERE r.invoice_id=i.id AND r.kind='correction') ORDER BY i.issue_date, i.invoice_number",
            [$this->configuration->environment()],
        );
        return array_map(static fn(array $row): array => [
            'id' => self::str($row['id']),'invoice_number' => self::str($row['invoice_number']),'ksef_number' => is_string($row['ksef_number']) ? $row['ksef_number'] : null,
            'issue_date' => self::str($row['issue_date']),'corrected_invoice_number' => is_string($row['corrected_invoice_number']) ? $row['corrected_invoice_number'] : null,
        ], $rows);
    }

    /**
     * Zamknięcie zadania: wiersze `correction` na każdą stawkę z niezerową różnicą. `$entries` wskazuje wiersz korygowany
     * per stawka przy kilku trafieniach, `$fxRateId` kurs PIT, gdy wiersza korygowanego z kursem brak.
     *
     * @param array<string,string> $entries stawka (np. "12.00") → id wiersza `regular`
     * @return list<string> zapisane wiersze
     */
    public function close(string $korId, string $userId, string $reason = 'other', array $entries = [], ?string $fxRateId = null): array
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Przyczyna korekty: inna niż błąd albo błąd rachunkowy.');
        }
        return $this->db->transactional(function () use ($korId, $userId, $reason, $entries, $fxRateId): array {
            $kor = $this->db->fetchAssociative(
                "SELECT id,environment,invoice_number,ksef_number,issue_date,currency,corrects_invoice_id,corrected_invoice_number,buyer_snapshot FROM invoice
                 WHERE id=? AND direction='sale' AND kind='KOR' AND status IN ('accepted','corrected','superseded') FOR UPDATE",
                [$korId],
            );
            if ($kor === false) {
                throw new \DomainException('Brak przyjętej faktury KOR.');
            }
            if ($this->db->fetchOne("SELECT 1 FROM revenue_entry WHERE invoice_id=? AND kind='correction'", [$korId]) !== false) {
                throw new \DomainException('Korekta tej faktury jest już w ewidencji.');
            }
            $issue = self::str($kor['issue_date']);
            $differences = $this->differences($korId, $issue);
            $candidates = $this->corrected($kor);
            [$country, $taxId] = Subject1Ledger::counterparty(is_string($kor['buyer_snapshot']) ? $kor['buyer_snapshot'] : '{}');
            $byYear = [];
            foreach ($differences as $rate => $net) {
                if (BigDecimal::of($net)->isZero()) {
                    continue;
                }
                $atRate = array_values(array_filter($candidates, static fn(array $row): bool => (string) BigDecimal::of(self::str($row['rate_pct']))->toScale(2) === $rate));
                $chosen = $entries[$rate] ?? null;
                $source = null;
                if ($chosen !== null) {
                    $matches = array_values(array_filter($atRate, static fn(array $row): bool => $row['id'] === $chosen));
                    $source = $matches[0] ?? throw new \DomainException('Wskazany wiersz nie jest wierszem korygowanym w tej stawce.');
                } elseif (count($atRate) === 1) {
                    $source = $atRate[0];
                } elseif (count($atRate) > 1) {
                    throw new \DomainException(sprintf('Kilka wierszy korygowanych w stawce %s: wskaż wiersz w zadaniu.', $rate));
                }
                $currency = self::str($kor['currency']);
                $mid = null;
                if ($currency !== 'PLN') {
                    $rateId = is_string($source['fx_rate_id'] ?? null) ? $source['fx_rate_id'] : $fxRateId;
                    if ($rateId === null) {
                        throw new \DomainException('Wybierz kurs PIT korekty: wiersz korygowany nie ma kursu.');
                    }
                    $found = $this->db->fetchOne('SELECT mid FROM fx_rate WHERE id=?', [$rateId]);
                    $mid = is_string($found) ? $found : throw new \DomainException('Nieznany kurs PIT.');
                }
                $revenueDate = $reason === 'arithmetic_error' && $source !== null ? self::str($source['revenue_date']) : $issue;
                $byYear[IsoDate::year($revenueDate)][] = [
                    'revenue_date' => $revenueDate,'document_number' => self::str($kor['invoice_number']),'ksef_number' => is_string($kor['ksef_number']) ? $kor['ksef_number'] : null,
                    'counterparty_country' => $source !== null && is_string($source['counterparty_country']) ? $source['counterparty_country'] : $country,
                    'counterparty_tax_id' => $source !== null && is_string($source['counterparty_tax_id']) ? $source['counterparty_tax_id'] : $taxId,
                    'amount_pln' => $mid === null ? $net : VatFx::convert($net, $mid),'rate_pct' => $rate,'remarks' => null,
                    'source' => 'ksef_subject1','kind' => 'correction','invoice_id' => $korId,'corrects_entry_id' => $source === null ? null : self::str($source['id']),
                    'original_amount' => $mid === null ? null : $net,'original_currency' => $mid === null ? null : $currency,
                    'fx_rate_id' => $source !== null && is_string($source['fx_rate_id']) ? $source['fx_rate_id'] : $fxRateId,
                ];
            }
            if ($byYear === []) {
                throw new \DomainException('KOR nie zmienia kwoty netto w żadnej stawce.');
            }
            $written = [];
            ksort($byYear);
            foreach ($byYear as $year => $rows) {
                $written = array_merge($written, $this->writer->append($year, $rows));
            }
            $this->audit->append('ledger.correction_closed', ['reason' => $reason,'rows' => count($written)], $this->configuration->environment(), 'invoice', $korId, $userId);
            $this->writer->closeIfComplete($issue);
            return $written;
        });
    }

    /**
     * Różnica netto KOR per stawka ryczałtu: pozycje po korekcie minus `StanPrzed`. Stawka pozycji z `ryczalt_rate_pct`,
     * inaczej z `ryczalt.service_map` po opisie (P_7).
     *
     * @return array<string,string>
     */
    private function differences(string $korId, string $issue): array
    {
        $map = ServiceMap::fromSetting($this->settings->value(SettingKeys::RYCZALT_SERVICE_MAP, $issue));
        $result = [];
        foreach ($this->db->fetchAllAssociative('SELECT description,net_amount,state_before,ryczalt_rate_pct FROM invoice_line WHERE invoice_id=? ORDER BY line_no, state_before DESC', [$korId]) as $line) {
            $rate = is_string($line['ryczalt_rate_pct']) ? $line['ryczalt_rate_pct'] : ($map->match(null, is_string($line['description']) ? $line['description'] : null)['rate_pct'] ?? null);
            if ($rate === null) {
                throw new \DomainException('Pozycja KOR bez stawki w ryczalt.service_map.');
            }
            $key = (string) BigDecimal::of($rate)->toScale(2);
            $net = BigDecimal::of(self::str($line['net_amount']));
            $result[$key] = (string) BigDecimal::of($result[$key] ?? '0')->plus($line['state_before'] === true ? $net->negated() : $net)->toScale(2);
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * Wiersze `regular` faktury korygowanej: po `corrects_invoice_id`, inaczej po numerze faktury korygowanej z dowolnego `source`.
     *
     * @param array<string,mixed> $kor
     * @return list<array<string,mixed>>
     */
    private function corrected(array $kor): array
    {
        if (is_string($kor['corrects_invoice_id'])) {
            $rows = $this->db->fetchAllAssociative("SELECT * FROM revenue_entry WHERE invoice_id=? AND kind='regular' ORDER BY lp", [$kor['corrects_invoice_id']]);
            if ($rows !== []) {
                return $rows;
            }
        }
        if (!is_string($kor['corrected_invoice_number'])) {
            return [];
        }
        return $this->db->fetchAllAssociative("SELECT * FROM revenue_entry WHERE environment=? AND document_number=? AND kind='regular' ORDER BY year, lp", [$kor['environment'], $kor['corrected_invoice_number']]);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
