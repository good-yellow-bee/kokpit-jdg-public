<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\Fa3Document;
use App\Invoice\Fa3Line;
use App\Invoice\Fa3Party;
use App\Ledger\Subject1Ledger;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Wyniki Subject1 w ewidencji (F2.2): stawki pozycji, kurs PIT, odtworzenie roku, K_6/K_7 i data przychodu. Dane i kursy fikcyjne. */
final class Subject1LedgerOutcomesTest extends DatabaseTestCase
{
    private MockClock $clock;
    private string $counterparty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-12-10T09:00:00Z');
        $this->counterparty = ChatTestTools::counterparty($this->db(), $this->clock, 'test', 'Fikcyjny klient sp. z o.o.', 'PL');
    }

    public function testReconstructCountsOutcomesSortedByName(): void
    {
        $this->invoice('FV/1/11/2026', '2026-11-03', 'PLN', [['500.00', '12.00']]);
        $this->invoice('FV/2/11/2026', '2026-11-04', 'PLN', [['700.00', '8.50']]);
        $this->invoice('FV/3/11/2026', '2026-11-05', 'PLN', [['900.00', null]]);
        self::assertSame(['rate_missing' => 1, 'written' => 2], $this->ledger()->reconstruct(2026, 'test'));
    }

    public function testLineRatesAreSummedPerRate(): void
    {
        $id = $this->invoice('FV/4/11/2026', '2026-11-06', 'PLN', [['100.05', '12.00'], ['200.10', '12.00'], ['50.00', '8.50']]);
        $result = $this->ledger()->apply($id, true);
        self::assertSame('written', $result->outcome);
        self::assertSame([['12.00', '300.15'], ['8.50', '50.00']], array_map(static fn(array $row): array => array_values($row), $this->db()->fetchAllAssociative('SELECT rate_pct, amount_pln FROM revenue_entry WHERE invoice_id=? ORDER BY lp', [$id])));
    }

    public function testLineRateOutsideDictionaryIsRefused(): void
    {
        $id = $this->invoice('FV/7/11/2026', '2026-11-06', 'PLN', [['100.00', '8.55']]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Stawka 8.55 spoza słownika K_9 JPK_EWP(4).');
        $this->ledger()->apply($id, true);
    }

    public function testKorIsLeftForPanelTask(): void
    {
        $id = $this->invoice('FK/1/11/2026', '2026-11-06', 'PLN', [['100.00', '12.00']], 'KOR');
        self::assertSame('not_vat', $this->ledger()->apply($id, true)->outcome);
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM revenue_entry'));
    }

    public function testForeignInvoiceWithoutRateWaitsForRate(): void
    {
        $id = $this->invoice('FV/5/11/2026', '2026-11-06', 'EUR', [['100.00', '12.00']]);
        self::assertSame('fx_rate_pending', $this->ledger()->apply($id, false)->outcome);
        self::assertNull($this->db()->fetchOne('SELECT fx_pit_rate_id FROM invoice WHERE id=?', [$id]));
    }

    public function testForeignInvoiceGetsPitRateAndUpdatedAt(): void
    {
        $id = $this->invoice('FV/6/11/2026', '2026-11-06', 'EUR', [['100.00', '12.00']]);
        $this->clock->modify('2026-12-11T08:30:00Z');
        $ledger = F1TestTools::ledger($this->db(), $this->clock, ['EUR' => ['2026-11-05' => '4.2500']]);
        self::assertSame('fields_only', $ledger->apply($id, false)->outcome);
        $invoice = $this->db()->fetchAssociative('SELECT fx_pit_rate_id IS NOT NULL AS rate, updated_at FROM invoice WHERE id=?', [$id]);
        self::assertSame(['rate' => true, 'updated_at' => '2026-12-11 08:30:00+00'], $invoice);
    }

    public function testCounterpartyReadsOnlyNonEmptyTextFields(): void
    {
        self::assertSame([null, null], Subject1Ledger::counterparty('{"nip":1234567890}'));
        self::assertSame([null, null], Subject1Ledger::counterparty('{"eu_vat_number":"999999999"}'));
        self::assertSame([null, null], Subject1Ledger::counterparty('{"eu_vat_prefix":"DE"}'));
        self::assertSame(['US', 'FIKC-123'], Subject1Ledger::counterparty('{"id_country":"US","foreign_tax_id":"FIKC-123"}'));
        self::assertSame(['GR', '999999999'], Subject1Ledger::counterparty('{"eu_vat_prefix":"EL","eu_vat_number":"999999999"}'));
        self::assertSame(['PL', F1TestTools::CLIENT_NIP], Subject1Ledger::counterparty('{"nip":"' . F1TestTools::CLIENT_NIP . '"}'));
    }

    public function testCounterpartyAcceptsSnapshotNestedUpToSixteenLevels(): void
    {
        $nested = static fn(int $levels): string => str_repeat('{"a":', $levels - 1) . '{"nip":"1"}' . str_repeat('}', $levels - 1);
        self::assertSame([null, null], Subject1Ledger::counterparty($nested(15)));
        $this->expectException(\JsonException::class);
        Subject1Ledger::counterparty($nested(16));
    }

    public function testRevenueDateIgnoresStateBeforeLines(): void
    {
        $lines = [$this->line(1, true, '2026-10-01'), $this->line(1, false, '2026-11-03'), $this->line(2, false, '2026-11-03')];
        self::assertSame('2026-11-03', Subject1Ledger::revenueDate($this->document($lines)));
        self::assertNull(Subject1Ledger::revenueDate($this->document([$this->line(1, true, '2026-10-01'), $this->line(1, false, '2026-11-03'), $this->line(2, false, '2026-11-04')])));
    }

    private function ledger(): Subject1Ledger
    {
        return F1TestTools::ledger($this->db(), $this->clock);
    }

    /** @param list<array{string,?string}> $lines netto i stawka ryczałtu pozycji */
    private function invoice(string $number, string $revenueDate, string $currency, array $lines, string $kind = 'VAT'): string
    {
        $id = (string) UuidV7::generate();
        $stamp = '2026-11-10T10:00:00+00:00';
        $this->db()->insert('invoice', ['id' => $id, 'environment' => 'test', 'direction' => 'sale', 'kind' => $kind, 'invoice_number' => $number, 'counterparty_id' => $this->counterparty, 'currency' => $currency,
            'ksef_number' => 'FIKCYJNY-KSEF-' . $number, 'issue_date' => $revenueDate, 'revenue_date' => $revenueDate, 'net_total' => '0.00', 'vat_total' => '0.00', 'gross_total' => '0.00', 'status' => 'accepted',
            'buyer_snapshot' => json_encode(['nip' => F1TestTools::CLIENT_NIP], JSON_THROW_ON_ERROR), 'created_at' => $stamp, 'updated_at' => $stamp]);
        foreach ($lines as $no => [$net, $rate]) {
            $this->db()->insert('invoice_line', ['id' => (string) UuidV7::generate(), 'invoice_id' => $id, 'line_no' => $no + 1, 'description' => 'Fikcyjna pozycja bez mapy', 'quantity' => '1', 'unit' => 'szt.',
                'net_amount' => $net, 'vat_code' => '23', 'ryczalt_rate_pct' => $rate, 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
        return $id;
    }

    private function line(int $no, bool $before, string $serviceDate): Fa3Line
    {
        return new Fa3Line($no, $before, 'Fikcyjna usługa', 'szt.', '1', '100.00', '100.00', '23', '23.00', $serviceDate, null);
    }

    /** @param list<Fa3Line> $lines */
    private function document(array $lines): Fa3Document
    {
        $party = new Fa3Party(F1TestTools::CLIENT_NIP, null, null, null, null, false, 'Fikcyjny klient', ['country' => 'PL', 'line1' => 'ul. Fikcyjna 1', 'line2' => null]);
        return new Fa3Document('FA(3)', 'KOR', 'FK/9/11/2026', '2026-11-05', null, null, null, 'PLN', $party, $party, $lines, '0.00', '0.00', '0.00', ['number' => null, 'issueDate' => null, 'ksefNumber' => null, 'reason' => null, 'type' => null]);
    }
}
