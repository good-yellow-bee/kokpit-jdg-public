<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Config\ConnectorConfiguration;
use App\Invoice\VatFx;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Wiersze `regular` z dokumentu przychodu (docs/modules/ewidencja.md, „Model wpisu”): jeden na stawkę, `lp` pod
 * blokadą roku, K_2 = dzień zapisu (D-23), K_8 = netto × kurs PIT. Dokument już zaewidencjonowany w dowolnym
 * `source` (najpierw `ksef_number`, potem numer, kontrahent i rok) nie daje drugiego zestawu wierszy.
 */
final readonly class RevenueEntryWriter
{
    public const LOCK_NAMESPACE = 7071402;

    public function __construct(private Connection $db, private ClockInterface $clock, private BusinessDate $businessDate, private JpkEwpSchema $schema, private ConnectorConfiguration $configuration) {}

    public function write(RevenueDocument $document, ?string $recordedBy = null): RevenueWriteResult
    {
        if ($document->source === 'manual' && $recordedBy === null) {
            throw new \InvalidArgumentException('Wpis ręczny wymaga zalogowanego użytkownika.');
        }
        $rates = [];
        foreach ($document->netByRate as $rate => $net) {
            $code = $this->schema->rateCode(BigDecimal::of((string) $rate));
            if ($code === null) {
                throw new \DomainException(sprintf('Stawka %s spoza słownika K_9 JPK_EWP(4).', $rate));
            }
            $rates[(string) BigDecimal::of((string) $rate)->toScale(2)] = BigDecimal::of($net)->toScale(2);
        }
        if ($document->counterpartyCountry !== null && !$this->schema->isCountry($document->counterpartyCountry)) {
            throw new \DomainException(sprintf('Kod kraju %s spoza słownika K_6.', $document->counterpartyCountry));
        }
        $year = (int) substr($document->revenueDate, 0, 4);
        $environment = $this->configuration->environment();
        return $this->db->transactional(function () use ($document, $rates, $year, $environment, $recordedBy): RevenueWriteResult {
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $year]);
            $existing = $this->existing($document, $environment, $year);
            if ($existing !== []) {
                $dates = array_values(array_unique(array_map(static fn(array $row): string => self::str($row['revenue_date']), $existing)));
                $other = array_values(array_filter($dates, static fn(string $date): bool => $date !== $document->revenueDate));
                return new RevenueWriteResult([], array_map(static fn(array $row): string => self::str($row['id']), $existing), $other[0] ?? null);
            }
            $lp = $this->db->fetchOne('SELECT coalesce(max(lp),0) FROM revenue_entry WHERE environment=? AND year=?', [$environment, $year]);
            $lp = is_numeric($lp) ? (int) $lp : 0;
            $today = $this->businessDate->today();
            $recordedAt = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $written = [];
            ksort($rates, SORT_STRING);
            foreach ($rates as $rate => $net) {
                $amount = $document->fxRate === null ? (string) $net : VatFx::convert((string) $net, $document->fxRate->mid);
                $id = (string) UuidV7::generate($this->clock->now());
                $this->db->insert('revenue_entry', [
                    'id' => $id,'environment' => $environment,'year' => $year,'lp' => ++$lp,'entry_date' => $today,'revenue_date' => $document->revenueDate,
                    'document_number' => $document->documentNumber,'ksef_number' => $document->ksefNumber,'counterparty_country' => $document->counterpartyCountry,
                    'counterparty_tax_id' => $document->counterpartyTaxId,'amount_pln' => $amount,'rate_pct' => (string) $rate,'remarks' => $document->remarks,
                    'source' => $document->source,'kind' => 'regular','invoice_id' => $document->invoiceId,
                    'original_amount' => $document->fxRate === null ? null : (string) $net,'original_currency' => $document->fxRate === null ? null : $document->currency,
                    'fx_rate_id' => $document->fxRate?->id,'recorded_at' => $recordedAt,'recorded_by' => $recordedBy,
                ]);
                $written[] = $id;
            }
            return new RevenueWriteResult($written, []);
        });
    }

    /** @return list<array<string,mixed>> */
    private function existing(RevenueDocument $document, string $environment, int $year): array
    {
        if ($document->invoiceId !== null) {
            $rows = $this->db->fetchAllAssociative("SELECT id,revenue_date FROM revenue_entry WHERE invoice_id=? AND kind='regular' ORDER BY lp", [$document->invoiceId]);
            if ($rows !== []) {
                return $rows;
            }
        }
        if ($document->ksefNumber !== null) {
            $rows = $this->db->fetchAllAssociative("SELECT id,revenue_date FROM revenue_entry WHERE environment=? AND ksef_number=? AND kind='regular' ORDER BY lp", [$environment, $document->ksefNumber]);
            if ($rows !== []) {
                return $rows;
            }
        }
        return $this->db->fetchAllAssociative("SELECT id,revenue_date FROM revenue_entry WHERE environment=? AND year=? AND document_number=? AND counterparty_tax_id IS NOT DISTINCT FROM ? AND kind='regular' ORDER BY lp", [$environment, $year, $document->documentNumber, $document->counterpartyTaxId]);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
