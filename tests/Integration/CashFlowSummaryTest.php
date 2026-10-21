<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fixtures\FictionalIdentifiers;
use App\Ledger\CashFlow;
use App\Tests\Support\BankTestTools;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Time\BusinessDate;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Cash-flow na 90 dni (D-88): kolejność miesięcy z zaległymi na górze, sumy PLN jako napisy i ruch rachunków z wyciągów (D-110). Dane fikcyjne. */
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
        self::assertSame([[], null], [$summary['bank'], $summary['bank_until']]);
    }

    public function testBankAccountsShowMovementOfConfirmedLinesOutsideBalance(): void
    {
        $clock = new MockClock('2026-10-05T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $statements = BankTestTools::statements($this->db(), $clock);
        $id = $statements->upload(BankTestTools::fixture(), null, $owner);
        $cashFlow = new CashFlow($this->db(), ConnectorTestTools::configuration(false), new BusinessDate($clock));
        self::assertSame([[], null], [$cashFlow->summary()['bank'], $cashFlow->summary()['bank_until']]);
        $statements->confirm($id, $owner, $statements->preview($id)->fingerprint(), []);
        $later = $statements->upload(str_replace('01.09.2026;30.09.2026;', '01.09.2026;04.10.2026;', BankTestTools::fixture()), null, $owner);
        $statements->discard($later, $owner);

        $summary = $cashFlow->summary();
        self::assertSame([
            ['account' => FictionalIdentifiers::account(1), 'currency' => 'PLN', 'first_booked' => '2026-09-01', 'last_booked' => '2026-09-29', 'items' => 13, 'inflow' => '15160.00', 'outflow' => '-10985.10', 'net' => '4174.90'],
            ['account' => FictionalIdentifiers::account(3), 'currency' => 'PLN', 'first_booked' => '2026-09-12', 'last_booked' => '2026-09-15', 'items' => 2, 'inflow' => '460.00', 'outflow' => '-300.00', 'net' => '160.00'],
        ], $summary['bank']);
        self::assertSame('2026-09-30', $summary['bank_until']);
        self::assertSame('0.00', $summary['pln']['balance']);
    }

    private function obligation(MockClock $clock, string $type, string $due, string $amount): void
    {
        $stamp = $clock->now()->format('c');
        $this->db()->insert('obligation', ['id' => (string) UuidV7::generate(), 'environment' => 'test', 'type' => $type, 'rule_key' => 'fikcyjna', 'period_start' => '2026-11-01', 'nominal_due_date' => $due, 'due_date' => $due,
            'amount_due' => $amount, 'status' => 'open', 'dedupe_key' => 'fikcyjny-' . $type . '-' . $due, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }
}
