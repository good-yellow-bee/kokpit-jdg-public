<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Config\ConnectorConfiguration;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;

/**
 * `ledger.entry_check` (docs/modules/ewidencja.md, „Wpis do 20. dnia”): dokumenty przychodu miesiąca M bez wierszy
 * ewidencji. Komplet ustawia `period.ledger_closed_at`, co zamyka obowiązek `ledger_entry` za M. Przed przełączeniem
 * (`ledger.auto_entries_enabled`) wpis robi narzędzie właściciela, więc kontrola niczego nie zamyka (D-48).
 */
final readonly class LedgerEntryCheck
{
    public function __construct(private Connection $db, private BusinessDate $businessDate, private SettingStore $settings, private Periods $periods, private ConnectorConfiguration $configuration) {}

    /** Przebieg zadania 15. i 20. dnia: poprzedni miesiąc. */
    public function run(): LedgerEntryCheckResult
    {
        $previous = (new \DateTimeImmutable(Periods::month($this->businessDate->today())))->modify('-1 month')->format('Y-m-d');
        return $this->check($previous);
    }

    /** Zapis do minionego miesiąca, który może uzupełnić ostatni brak. */
    public function closeIfComplete(string $date): void
    {
        if (Periods::month($date) < Periods::month($this->businessDate->today())) {
            $this->check($date);
        }
    }

    public function check(string $date): LedgerEntryCheckResult
    {
        $month = Periods::month($date);
        if ($this->settings->value(SettingKeys::LEDGER_AUTO_ENTRIES) !== true) {
            return new LedgerEntryCheckResult($month, [], false, false);
        }
        $missing = $this->missing($month);
        $closed = $missing === [] && $this->periods->markLedgerClosed($month);
        return new LedgerEntryCheckResult($month, $missing, true, $closed);
    }

    /**
     * Dokumenty M bez wierszy, szukane kluczem deduplikacji w każdym `source`.
     *
     * @return list<array{type:string,id:string,number:string,date:string}>
     */
    public function missing(string $date): array
    {
        $month = Periods::month($date);
        $next = (new \DateTimeImmutable($month))->modify('+1 month')->format('Y-m-d');
        $environment = $this->configuration->environment();
        $rows = $this->db->fetchAllAssociative(
            "SELECT 'invoice' AS type, i.id, i.invoice_number AS number, i.revenue_date AS date FROM invoice i
             WHERE i.environment=:env AND i.direction='sale' AND i.kind='VAT' AND i.status IN ('accepted','corrected','superseded')
             AND i.revenue_date >= :month AND i.revenue_date < :next
             AND NOT EXISTS (SELECT 1 FROM revenue_entry r WHERE r.environment=i.environment AND r.kind='regular'
                 AND (r.invoice_id=i.id OR r.ksef_number=i.ksef_number OR (r.document_number=i.invoice_number AND r.year=extract(year FROM i.revenue_date)::int)))
             UNION ALL
             SELECT 'correction', i.id, i.invoice_number, i.issue_date FROM invoice i
             WHERE i.environment=:env AND i.direction='sale' AND i.kind='KOR' AND i.status IN ('accepted','corrected','superseded')
             AND i.issue_date >= :month AND i.issue_date < :next
             AND NOT EXISTS (SELECT 1 FROM revenue_entry r WHERE r.invoice_id=i.id AND r.kind='correction')
             UNION ALL
             SELECT 'payment', p.id, p.freshbooks_payment_id, p.paid_on FROM payment p
             WHERE p.environment=:env AND p.currency<>'PLN' AND p.matched_at IS NOT NULL AND p.fx_posted_at IS NULL
             AND p.paid_on >= :month AND p.paid_on < :next
             ORDER BY 4, 3",
            ['env' => $environment,'month' => $month,'next' => $next],
        );
        return array_map(static fn(array $row): array => ['type' => self::str($row['type']),'id' => self::str($row['id']),'number' => self::str($row['number']),'date' => self::str($row['date'])], $rows);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
