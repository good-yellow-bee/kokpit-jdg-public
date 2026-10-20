<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\CashFlow;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ConnectorTestTools;
use App\Time\BusinessDate;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Cash-flow na 90 dni (D-88): kolejność miesięcy z zaległymi na górze i sumy PLN jako napisy. Dane fikcyjne. */
final class CashFlowSummaryTest extends DatabaseTestCase
{
    public function testSummaryOrdersMonthsAscendingAfterOverdueAndReturnsPlnStrings(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $this->obligation($clock, 'zus', '2026-11-20', '100.00');
        $this->obligation($clock, 'vat_jpk_v7m', '2027-01-25', '200.00');
        $this->obligation($clock, 'zus', '2026-12-21', '300.00');
        $client = ChatTestTools::counterparty($this->db(), $clock, 'test', 'Fikcyjny klient', 'PL');
        ChatTestTools::sale($this->db(), $clock, 'test', $client, 'FV/1/12/2026', '2026-12-01', 'PLN', '1000.00', '230.00', '1000.00', 'accepted');
        ChatTestTools::cost($this->db(), $clock, 'test', $client, 'FIKC/K/1', '2026-12-02', 'PLN', '100.00', '23.00', '100.00', 'business');

        $summary = (new CashFlow($this->db(), ConnectorTestTools::configuration(false), new BusinessDate($clock)))->summary();
        self::assertSame(['2026-12-10', '2027-03-10'], [$summary['today'], $summary['until']]);
        self::assertSame([['zaległe', 'zus'], ['2026-12', 'zus'], ['2027-01', 'vat_jpk_v7m']], array_map(static fn(array $row): array => [$row['month'], $row['type']], $summary['by_month']));
        self::assertSame(['receivables' => '1230.00', 'obligations' => '600.00', 'payables' => '123.00', 'balance' => '507.00'], $summary['pln']);
    }

    private function obligation(MockClock $clock, string $type, string $due, string $amount): void
    {
        $stamp = $clock->now()->format('c');
        $this->db()->insert('obligation', ['id' => (string) UuidV7::generate(), 'environment' => 'test', 'type' => $type, 'rule_key' => 'fikcyjna', 'period_start' => '2026-11-01', 'nominal_due_date' => $due, 'due_date' => $due,
            'amount_due' => $amount, 'status' => 'open', 'dedupe_key' => 'fikcyjny-' . $type . '-' . $due, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }
}
