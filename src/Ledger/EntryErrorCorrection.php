<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Audit\AuditLog;
use App\Calendar\IsoDate;
use App\Config\ConnectorConfiguration;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * Błąd wpisu (docs/modules/ewidencja.md, „Korekty”, „Błąd wpisu”): nigdy `UPDATE`. Błąd tylko w K_8 daje jeden wiersz
 * `correction` z różnicą; błąd w K_3–K_7 albo K_9 daje wiersz odwracający (−K_8) i wiersz z poprawnymi polami.
 * Każdy wiersz ma `corrects_entry_id` błędnego wiersza, jego `invoice_id`, `source` = `manual` i `year` z własnego K_3.
 * Zapis przez `RevenueEntryWriter::append`, więc wiersz w zamkniętym miesiącu daje „korekta wymagana”. Akcja panelu `/ledger`.
 * Wiersz poprawiasz raz: kolejny błąd poprawiasz w wierszu korekty, więc ponowione żądanie nie dubluje różnicy.
 */
final readonly class EntryErrorCorrection
{
    public const FIELDS = ['revenue_date','document_number','ksef_number','counterparty_country','counterparty_tax_id','amount_pln','rate_pct'];

    public function __construct(private Connection $db, private RevenueEntryWriter $writer, private JpkEwpSchema $schema, private AuditLog $audit, private ConnectorConfiguration $configuration) {}

    /**
     * @param array<string,?string> $correct poprawne wartości pól z FIELDS; pominięte pole zostaje jak w błędnym wierszu
     * @return list<string>
     */
    public function correct(string $entryId, array $correct, string $userId): array
    {
        if (array_diff(array_keys($correct), self::FIELDS) !== []) {
            throw new \InvalidArgumentException('Korekta zmienia tylko pola: ' . implode(', ', self::FIELDS) . '.');
        }
        return $this->db->transactional(function () use ($entryId, $correct, $userId): array {
            $wrong = $this->db->fetchAssociative('SELECT * FROM revenue_entry WHERE id=? AND environment=?', [$entryId, $this->configuration->environment()]);
            if ($wrong === false) {
                throw new \DomainException('Brak wiersza ewidencji.');
            }
            // Blokada roku błędnego wiersza szereguje równoległe korekty tego wiersza przed sprawdzeniem poprzedniej.
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(?, ?)', [RevenueEntryWriter::LOCK_NAMESPACE, $wrong['year']]);
            if ($this->db->fetchOne("SELECT 1 FROM revenue_entry WHERE corrects_entry_id=? AND source='manual'", [$entryId]) !== false) {
                throw new \DomainException('Wiersz ma już korektę błędu wpisu: popraw wiersz korekty.');
            }
            $fixed = $wrong;
            foreach ($correct as $field => $value) {
                $fixed[$field] = $value;
            }
            $changed = array_values(array_filter(self::FIELDS, static fn(string $field): bool => self::norm($field, $fixed[$field]) !== self::norm($field, $wrong[$field])));
            if ($changed === []) {
                throw new \DomainException('Korekta nie zmienia żadnego pola.');
            }
            if ($this->schema->rateCode(BigDecimal::of(self::str($fixed['rate_pct']))) === null) {
                throw new \DomainException('Stawka spoza słownika K_9 JPK_EWP(4).');
            }
            if (is_string($fixed['counterparty_country']) && !$this->schema->isCountry($fixed['counterparty_country'])) {
                throw new \DomainException('Kod kraju spoza słownika K_6.');
            }
            if (is_string($fixed['ksef_number']) && !$this->schema->isKsefNumber($fixed['ksef_number'])) {
                throw new \DomainException('Numer KSeF K_5 niezgodny ze wzorcem JPK_EWP(4).');
            }
            $rows = [];
            if ($changed === ['amount_pln']) {
                $rows[] = ['amount_pln' => (string) BigDecimal::of(self::str($fixed['amount_pln']))->minus(self::str($wrong['amount_pln']))->toScale(2)] + $wrong;
            } else {
                $rows[] = ['amount_pln' => (string) BigDecimal::of(self::str($wrong['amount_pln']))->negated()->toScale(2)] + $wrong;
                $rows[] = ['amount_pln' => (string) BigDecimal::of(self::str($fixed['amount_pln']))->toScale(2)] + $fixed;
            }
            $ids = [];
            foreach ($rows as $row) {
                $ids = array_merge($ids, $this->writer->append(IsoDate::year(self::str($row['revenue_date'])), [self::row($row, $entryId, $userId)]));
            }
            $this->audit->append('ledger.entry_corrected', ['corrects_entry_id' => $entryId,'changed' => $changed,'rows' => count($ids)], $this->configuration->environment(), 'revenue_entry', $ids[0], $userId);
            return $ids;
        });
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string|null>
     */
    private static function row(array $row, string $correctsEntryId, string $userId): array
    {
        $columns = ['revenue_date','document_number','ksef_number','counterparty_country','counterparty_tax_id','amount_pln','remarks','invoice_id','fx_rate_id'];
        $values = array_map(static fn(string $column): ?string => $row[$column] === null ? null : self::str($row[$column]), array_combine($columns, $columns));
        return $values + ['rate_pct' => (string) BigDecimal::of(self::str($row['rate_pct']))->toScale(2),'source' => 'manual','kind' => 'correction','corrects_entry_id' => $correctsEntryId,'recorded_by' => $userId];
    }

    private static function norm(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = is_scalar($value) ? (string) $value : throw new \InvalidArgumentException('Niepoprawna wartość pola ' . $field . '.');
        return in_array($field, ['amount_pln','rate_pct'], true) ? (string) BigDecimal::of($text)->toScale(2) : $text;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
