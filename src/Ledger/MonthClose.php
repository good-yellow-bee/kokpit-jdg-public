<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Audit\AuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Krok 6 zamknięcia miesiąca po przyjętym JPK_V7M z bramki (docs/compliance-calendar.md, „Zamknięcie miesiąca”, D-65):
 * jak złożenie `manual` z D-49 poprzednie przyjęte złożenie okresu dostaje `superseded`, okres `closed` z
 * `closed_by_filing_id`, obowiązek `vat_jpk_v7m` za M `done` z `filing_id`, a `vat_ue` bez sprzedaży `eu_b2b`
 * `not_applicable`. Działa w transakcji przejścia zatwierdzenia (`FilingSubject::onStatus`).
 */
final readonly class MonthClose
{
    public function __construct(private Connection $db, private ClockInterface $clock, private AuditLog $audit, private Periods $periods) {}

    public function accepted(string $filingId, ?string $userId): void
    {
        $filing = $this->db->fetchAssociative("SELECT environment, period_start FROM filing WHERE id=? AND type='JPK_V7M'", [$filingId]);
        if ($filing === false) {
            throw new \LogicException('Zamknięcie miesiąca tylko po JPK_V7M.');
        }
        $environment = self::str($filing['environment']);
        $periodStart = self::str($filing['period_start']);
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        // Korekta zastępuje każde wcześniejsze przyjęte złożenie okresu (także `manual`).
        foreach ($this->db->fetchFirstColumn("SELECT id FROM filing WHERE environment=? AND type='JPK_V7M' AND period_start=? AND status='accepted' AND id<>?", [$environment, $periodStart, $filingId]) as $previous) {
            $this->db->update('filing', ['status' => 'superseded', 'updated_at' => $now], ['id' => self::str($previous)]);
        }
        $this->periods->close($periodStart, $filingId);
        $obligation = $this->db->fetchOne("SELECT id FROM obligation WHERE environment=? AND type='vat_jpk_v7m' AND period_start=? AND status='open'", [$environment, $periodStart]);
        if (is_string($obligation)) {
            $this->db->update('obligation', ['status' => 'done', 'done_at' => $now, 'done_by' => $userId, 'filing_id' => $filingId, 'updated_at' => $now], ['id' => $obligation]);
            $this->audit->append('obligation.completed', ['type' => 'vat_jpk_v7m', 'done_by' => $userId, 'source' => 'filing.accepted', 'filing_id' => $filingId], $environment, 'obligation', $obligation, $userId);
        }
        $next = (new \DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-d');
        $sale = $this->db->fetchOne("SELECT 1 FROM invoice WHERE environment=? AND direction='sale' AND tax_scenario='eu_b2b' AND status NOT IN ('cancelled_before_send','rejected','superseded') AND tax_point_date>=? AND tax_point_date<?", [$environment, $periodStart, $next]);
        $vatUe = $this->db->fetchOne("SELECT id FROM obligation WHERE environment=? AND type='vat_ue' AND period_start=? AND status='open'", [$environment, $periodStart]);
        if ($sale === false && is_string($vatUe)) {
            $this->db->update('obligation', ['status' => 'not_applicable', 'done_at' => $now, 'done_by' => null, 'updated_at' => $now], ['id' => $vatUe]);
            $this->audit->append('obligation.marked_not_applicable', ['type' => 'vat_ue', 'source' => 'period.checklist'], $environment, 'obligation', $vatUe);
        }
    }

    /**
     * Przyjęty VAT-UE albo VAT-UEK za M (D-66): poprzednie przyjęte złożenie VAT-UE okresu dostaje `superseded`,
     * a otwarty obowiązek `vat_ue` za M `done` z `filing_id`. Okres VAT nie zmienia stanu: zamyka go JPK_V7M.
     */
    public function vatUeAccepted(string $filingId, ?string $userId): void
    {
        $filing = $this->db->fetchAssociative("SELECT environment, type, period_start FROM filing WHERE id=? AND type IN ('VAT_UE','VAT_UEK')", [$filingId]);
        if ($filing === false) {
            throw new \LogicException('Zamknięcie VAT-UE tylko po VAT_UE albo VAT_UEK.');
        }
        $environment = self::str($filing['environment']);
        $periodStart = self::str($filing['period_start']);
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        foreach ($this->db->fetchFirstColumn("SELECT id FROM filing WHERE environment=? AND type IN ('VAT_UE','VAT_UEK') AND period_start=? AND status='accepted' AND id<>?", [$environment, $periodStart, $filingId]) as $previous) {
            $this->db->update('filing', ['status' => 'superseded', 'updated_at' => $now], ['id' => self::str($previous)]);
        }
        $obligation = $this->db->fetchOne("SELECT id FROM obligation WHERE environment=? AND type='vat_ue' AND period_start=? AND status='open'", [$environment, $periodStart]);
        if (is_string($obligation)) {
            $this->db->update('obligation', ['status' => 'done', 'done_at' => $now, 'done_by' => $userId, 'filing_id' => $filingId, 'updated_at' => $now], ['id' => $obligation]);
            $this->audit->append('obligation.completed', ['type' => 'vat_ue', 'done_by' => $userId, 'source' => 'filing.accepted', 'filing_id' => $filingId, 'filing_type' => self::str($filing['type'])], $environment, 'obligation', $obligation, $userId);
        }
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość złożenia.');
    }
}
