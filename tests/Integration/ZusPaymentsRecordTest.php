<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tax\ZusPayments;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;

/** Walidacja wpisu składek, nadpisanie tylko tego samego miesiąca i treść wpisu `audit_log`. Kwoty fikcyjne. */
final class ZusPaymentsRecordTest extends DatabaseTestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-12-05T08:00:00Z');
    }

    public function testRejectsMalformedMonthOrPaymentDayWithMessage(): void
    {
        // `2026-1` i `2026-02-30` daty PHP przyjęłyby z przeniesieniem; walidacja odrzuca je przed zapisem.
        foreach ([['2026-1', '2026-12-05'], ['2026-11', '2026-02-30'], ['2026-11', '05.12.2026']] as [$month, $paidOn]) {
            try {
                $this->record($month, '1600.00', '830.58', $paidOn);
                self::fail('Przyjęto ' . $month . ' / ' . $paidOn . '.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Miesiąc RRRR-MM i dzień zapłaty RRRR-MM-DD.', $exception->getMessage());
            }
        }
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM zus_payment'));
    }

    public function testAmountErrorNamesFieldWithCapital(): void
    {
        foreach ([['1.5', '0.00', 'Składki społeczne'], ['0.00', 'x', 'Składka zdrowotna']] as [$social, $health, $label]) {
            try {
                $this->record('2026-11', $social, $health, '2026-12-05');
                self::fail('Przyjęto kwotę.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($label . ' to kwota z groszami, np. 1926.76.', $exception->getMessage());
            }
        }
    }

    public function testCorrectionChangesOnlyItsMonthAndAuditsTotal(): void
    {
        $october = $this->record('2026-10', '1600.00', '830.58', '2026-11-10');
        $first = $this->record('2026-11', '1600.00', '830.58', '2026-12-05');
        $second = $this->record('2026-11', '1600.00', '498.35', '2026-12-05');
        self::assertSame($first, $second);
        self::assertNotSame($october, $first);
        self::assertSame(['830.58', '498.35'], $this->db()->fetchFirstColumn("SELECT health_pln FROM zus_payment ORDER BY month"));
        self::assertSame([
            ['month' => '2026-11','paid_on' => '2026-12-05','replaced' => false,'total_pln' => '2430.58'],
            ['month' => '2026-11','paid_on' => '2026-12-05','replaced' => true,'total_pln' => '2098.35'],
        ], $this->details($first));
        self::assertSame($october, $this->db()->fetchOne("SELECT target_id FROM audit_log WHERE action='zus.payment_recorded' ORDER BY sequence LIMIT 1"));
    }

    private function record(string $month, string $social, string $health, string $paidOn): string
    {
        return (new ZusPayments($this->db(), $this->clock, F1TestTools::audit($this->db(), $this->clock), ConnectorTestTools::configuration(false), F1TestTools::periods($this->db(), $this->clock)))->record(F1TestTools::owner($this->db(), $this->clock), $month, $social, $health, '0.00', $paidOn);
    }

    /** @return list<array<string,mixed>> szczegóły wpisów `zus.payment_recorded` z kluczami po kolei */
    private function details(string $id): array
    {
        $result = [];
        foreach ($this->db()->fetchFirstColumn("SELECT details FROM audit_log WHERE action='zus.payment_recorded' AND target_id=? ORDER BY sequence", [$id]) as $json) {
            self::assertIsString($json);
            $details = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($details);
            ksort($details);
            $result[] = $details;
        }
        return $result;
    }
}
