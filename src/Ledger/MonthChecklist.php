<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Config\ConnectorConfiguration;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Bramki kompletności kroku 4 zamknięcia miesiąca, które działają przed F5 (docs/compliance-calendar.md,
 * „Zamknięcie miesiąca”; docs/phases/f2-ewidencja.md, „Zakres” F2.3). Kokpit tylko liczy i porównuje: niespełniona
 * bramka to alert, nie odmowa (D-49). Lista DI i bilans otwarcia przychodzą z F5.
 */
final readonly class MonthChecklist
{
    public const GATES = ['sales_final', 'costs_reviewed', 'fx_rates', 'vies_eu_b2b'];

    public function __construct(private Connection $db, private ClockInterface $clock, private ConnectorConfiguration $configuration) {}

    /** @return array{checked_at:string,period_start:string,ok:bool,gates:array<string,array{ok:bool,count:int}>} */
    public function evaluate(string $date): array
    {
        $month = Periods::month($date);
        $next = (new \DateTimeImmutable($month))->modify('+1 month')->format('Y-m-d');
        $params = ['env' => $this->configuration->environment(),'month' => $month,'next' => $next];
        $queries = [
            // Faktury sprzedaży z okresem VAT M w stanach końcowych.
            'sales_final' => "SELECT count(*) FROM invoice WHERE environment=:env AND direction='sale' AND vat_period_start=:month
                AND status NOT IN ('accepted','corrected','superseded','cancelled_before_send')",
            // Żaden koszt z wystawieniem albo otrzymaniem do końca M nie czeka na przegląd; koszt firmowy ma okres.
            'costs_reviewed' => "SELECT count(*) FROM invoice WHERE environment=:env AND direction='cost'
                AND (issue_date < :next OR ksef_number_assigned_at < (:next::date::timestamp AT TIME ZONE 'Europe/Warsaw') OR received_on < :next)
                AND (cost_status='imported' OR (cost_review='business' AND cost_status<>'assigned'))",
            // Dokument w walucie z okresem VAT M ma kurs VAT; sprzedaż ma też kurs PIT.
            'fx_rates' => "SELECT count(*) FROM invoice WHERE environment=:env AND vat_period_start=:month AND currency<>'PLN'
                AND (fx_vat_rate_id IS NULL OR (direction='sale' AND fx_pit_rate_id IS NULL))
                AND (direction='cost' OR status NOT IN ('cancelled_before_send','rejected'))",
            // Sprzedaż `eu_b2b` z M ma VIES `valid` na dzień wystawienia albo dowód ręczny.
            'vies_eu_b2b' => "SELECT count(*) FROM invoice i WHERE i.environment=:env AND i.direction='sale' AND i.tax_scenario='eu_b2b'
                AND i.vat_period_start=:month AND i.status NOT IN ('cancelled_before_send','rejected','superseded')
                AND NOT EXISTS (SELECT 1 FROM verification v WHERE v.environment=i.environment AND v.counterparty_id=i.counterparty_id
                    AND ((v.source='vies' AND v.result='valid' AND v.checked_for_date<=i.issue_date) OR v.result='manual_evidence'))",
        ];
        $gates = [];
        foreach ($queries as $gate => $sql) {
            $count = $this->db->fetchOne($sql, $params);
            $count = is_numeric($count) ? (int) $count : throw new \LogicException('Niepoprawny wynik bramki.');
            $gates[$gate] = ['ok' => $count === 0,'count' => $count];
        }
        return [
            'checked_at' => $this->clock->now()->format('Y-m-d\TH:i:s.uP'),'period_start' => $month,
            'ok' => array_filter($gates, static fn(array $gate): bool => !$gate['ok']) === [],'gates' => $gates,
        ];
    }
}
