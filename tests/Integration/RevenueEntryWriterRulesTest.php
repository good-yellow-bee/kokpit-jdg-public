<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\Fa3Party;
use App\Ledger\RevenueDocument;
use App\Ledger\RevenueEntryWriter;
use App\Setting\SettingKeys;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\LedgerTestTools;
use App\Tests\Support\ObligationTestTools;
use Brick\Math\Exception\RoundingNecessaryException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Zapis wierszy `regular` i `append`: kolejność `lp`, klucz deduplikacji, blokada roku i okresy. Dane fikcyjne. */
final class RevenueEntryWriterRulesTest extends DatabaseTestCase
{
    private MockClock $clock;
    private RevenueEntryWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-12-10T09:00:00Z');
        $this->writer = F1TestTools::writer($this->db(), $this->clock);
    }

    public function testManualSourceRequiresUserAndOtherSourcesKeepIt(): void
    {
        try {
            $this->writer->write(self::document('manual', 'FIKCYJNY/M/1'));
            self::fail('Wpis ręczny bez użytkownika przyjęty.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Wpis ręczny wymaga zalogowanego użytkownika.', $exception->getMessage());
        }
        $user = F1TestTools::owner($this->db(), $this->clock);
        [$id] = $this->writer->write(self::document('freshbooks', 'FIKCYJNY/FB/1'), $user)->written;
        self::assertSame($user, $this->db()->fetchOne('SELECT recorded_by FROM revenue_entry WHERE id=?', [$id]));
    }

    public function testRowsPerRateInStringOrderWithIdsOfWrittenRows(): void
    {
        $this->writer->append(2026, [self::appended('FIKCYJNY/A/1', '2026-03-31')]);
        $result = $this->writer->write(self::document('freshbooks', 'FIKCYJNY/FB/2', ['8.5' => '50', '12.0' => '100.10']));
        $rows = $this->db()->fetchAllAssociative("SELECT id, lp, rate_pct, amount_pln FROM revenue_entry WHERE document_number='FIKCYJNY/FB/2' ORDER BY lp");
        self::assertSame([[2, '12.00', '100.10'], [3, '8.50', '50.00']], array_map(static fn(array $row): array => [$row['lp'], $row['rate_pct'], $row['amount_pln']], $rows));
        self::assertSame(array_column($rows, 'id'), $result->written);
        self::assertSame([], $result->existing);
    }

    public function testNetWithMoreThanTwoDecimalsIsRefused(): void
    {
        $this->expectException(RoundingNecessaryException::class);
        try {
            $this->writer->write(self::document('freshbooks', 'FIKCYJNY/FB/3', ['12.00' => '100.005']));
        } finally {
            self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM revenue_entry'));
        }
    }

    public function testDuplicateByInvoiceIdListsAllItsRows(): void
    {
        $invoice = $this->invoice('FIKCYJNA/INV/1');
        $first = $this->writer->write(self::document('ksef_subject1', 'FIKCYJNA/INV/1', ['12.00' => '10.00', '8.50' => '20.00'], invoiceId: $invoice));
        // Ta sama faktura pod innym numerem i bez numeru KSeF: dopasowanie tylko po `invoice_id`.
        $again = $this->writer->write(self::document('ksef_subject1', 'FIKCYJNA/INV/1-BIS', ['12.00' => '10.00'], invoiceId: $invoice));
        self::assertTrue($again->duplicate());
        self::assertSame($first->written, $again->existing);
        self::assertNull($again->existingRevenueDate);
    }

    public function testInvoiceWithoutRowsFallsBackToNumberAndCounterparty(): void
    {
        $existing = $this->writer->write(self::document('freshbooks', 'FIKCYJNA/INV/2'));
        $again = $this->writer->write(self::document('ksef_subject1', 'FIKCYJNA/INV/2', invoiceId: $this->invoice('FIKCYJNA/INV/2')));
        self::assertSame($existing->written, $again->existing);
    }

    public function testDuplicateByKsefNumberAndFallbackWhenKsefNumberUnknown(): void
    {
        $existing = $this->writer->write(self::document('ksef_subject1', 'FIKCYJNA/K/1', ksef: 'FIKCYJNY-KSEF-0901'));
        self::assertSame($existing->written, $this->writer->write(self::document('freshbooks', 'FIKCYJNA/K/1-INNY', ksef: 'FIKCYJNY-KSEF-0901'))->existing);
        self::assertSame($existing->written, $this->writer->write(self::document('freshbooks', 'FIKCYJNA/K/1', ksef: 'FIKCYJNY-KSEF-0902'))->existing);
        $twoRates = $this->writer->write(self::document('ksef_subject1', 'FIKCYJNA/K/2', ['12.00' => '10.00', '8.50' => '20.00'], ksef: 'FIKCYJNY-KSEF-0903'));
        self::assertSame($twoRates->written, $this->writer->write(self::document('freshbooks', 'FIKCYJNA/K/2-INNY', ksef: 'FIKCYJNY-KSEF-0903'))->existing);
    }

    public function testExistingRevenueDateIsTheFirstOtherDate(): void
    {
        $ids = $this->writer->append(2026, [self::appended('FIKCYJNA/D/1', '2026-03-31'), self::appended('FIKCYJNA/D/1', '2026-04-30')]);
        $sameFirst = $this->writer->write(self::document('freshbooks', 'FIKCYJNA/D/1', date: '2026-03-31'));
        self::assertSame([$ids, '2026-04-30'], [$sameFirst->existing, $sameFirst->existingRevenueDate]);
        self::assertSame('2026-03-31', $this->writer->write(self::document('freshbooks', 'FIKCYJNA/D/1', date: '2026-05-31'))->existingRevenueDate);
        $single = $this->writer->append(2026, [self::appended('FIKCYJNA/D/2', '2026-03-31')]);
        self::assertSame([$single, '2026-03-31'], [$this->writer->write(self::document('freshbooks', 'FIKCYJNA/D/2', date: '2026-06-30'))->existing, $this->writer->write(self::document('freshbooks', 'FIKCYJNA/D/2', date: '2026-06-30'))->existingRevenueDate]);
        self::assertNull($this->writer->write(self::document('freshbooks', 'FIKCYJNA/D/2', date: '2026-03-31'))->existingRevenueDate);
    }

    public function testAppendNumbersRowsAfterLedgerAndMarksClosedPeriod(): void
    {
        $this->closePeriod('2026-10-01');
        $first = $this->writer->write(self::document('freshbooks', 'FIKCYJNY/FB/4', ['12.00' => '10.00', '8.50' => '20.00'], date: '2026-10-15'));
        $ids = $this->writer->append(2026, [self::appended('FIKCYJNA/A/2', '2026-10-20'), self::appended('FIKCYJNA/A/3', '2026-11-20')]);
        self::assertSame($ids, $this->db()->fetchFirstColumn("SELECT id FROM revenue_entry WHERE document_number IN ('FIKCYJNA/A/2','FIKCYJNA/A/3') ORDER BY lp"));
        self::assertSame([3, 4], $this->db()->fetchFirstColumn("SELECT lp FROM revenue_entry WHERE document_number IN ('FIKCYJNA/A/2','FIKCYJNA/A/3') ORDER BY lp"));
        // Zapis do zamkniętego października: „korekta wymagana” wskazuje pierwszy wiersz dokumentu i wiersz `append`.
        $targets = $this->db()->fetchFirstColumn("SELECT details->>'target_id' FROM audit_log WHERE action='period.correction_required' ORDER BY sequence");
        self::assertSame([$first->written[0], $ids[0]], $targets);
        self::assertNotNull($this->db()->fetchOne("SELECT correction_required_at FROM period WHERE period_start='2026-10-01'"));
        self::assertNull($this->db()->fetchOne("SELECT correction_required_at FROM period WHERE period_start='2026-11-01'"));
    }

    public function testWriteAndAppendWaitForYearLock(): void
    {
        LedgerTestTools::assertWaitsForYearLock($this->db(), 2026, fn() => $this->writer->write(self::document('freshbooks', 'FIKCYJNY/FB/5')));
        LedgerTestTools::assertWaitsForYearLock($this->db(), 2026, fn() => $this->writer->append(2026, [self::appended('FIKCYJNA/A/4', '2026-03-31')]));
        // Blokada zwolniona: kolejny zapis przechodzi.
        self::assertCount(1, $this->writer->write(self::document('freshbooks', 'FIKCYJNY/FB/5'))->written);
    }

    public function testCloseIfCompleteClosesPastCompleteMonthOnly(): void
    {
        ObligationTestTools::settings($this->db(), $this->clock)->set(SettingKeys::LEDGER_AUTO_ENTRIES, true, '2026-01-01', null);
        $this->writer->closeIfComplete('2026-11-15');
        $this->writer->closeIfComplete('2026-12-05');
        self::assertSame(['2026-11-01'], $this->db()->fetchFirstColumn('SELECT period_start FROM period WHERE ledger_closed_at IS NOT NULL'));
    }

    public function testEntryCheckReportsMonthAndClosesOnlyWhenEnabled(): void
    {
        $check = F1TestTools::entryCheck($this->db(), $this->clock);
        $off = $check->check('2026-11-20');
        self::assertSame(['2026-11-01', [], false, false], [$off->month, $off->missing, $off->active, $off->closed]);
        ObligationTestTools::settings($this->db(), $this->clock)->set(SettingKeys::LEDGER_AUTO_ENTRIES, true, '2026-01-01', null);
        $on = $check->check('2026-11-20');
        self::assertSame(['2026-11-01', [], true, true], [$on->month, $on->missing, $on->active, $on->closed]);
    }

    /** @param array<string,string> $net */
    private static function document(string $source, string $number, array $net = ['12.00' => '100.00'], ?string $invoiceId = null, ?string $ksef = null, string $date = '2026-11-30'): RevenueDocument
    {
        return new RevenueDocument($source, $invoiceId, $number, $ksef, 'PL', F1TestTools::CLIENT_NIP, $date, 'PLN', null, $net);
    }

    /** @return array<string,string|null> */
    private static function appended(string $number, string $date): array
    {
        return ['revenue_date' => $date,'document_number' => $number,'counterparty_country' => 'PL','counterparty_tax_id' => F1TestTools::CLIENT_NIP,'amount_pln' => '10.00','rate_pct' => '12.00','source' => 'freshbooks','kind' => 'regular'];
    }

    private function invoice(string $number): string
    {
        $counterparty = F1TestTools::directory($this->db(), $this->clock)->freshbooksClient('301', new Fa3Party(F1TestTools::CLIENT_NIP, null, null, null, null, false, 'Fikcyjny klient sp. z o.o.', ['country' => 'PL','line1' => null,'line2' => null]));
        $id = (string) UuidV7::generate($this->clock->now());
        $now = $this->clock->now()->format('c');
        $this->db()->insert('invoice', ['id' => $id,'environment' => 'test','direction' => 'sale','kind' => 'VAT','invoice_number' => $number,'counterparty_id' => $counterparty,'currency' => 'PLN','issue_date' => '2026-11-30','net_total' => '100.00','vat_total' => '23.00','gross_total' => '123.00','status' => 'accepted','created_at' => $now,'updated_at' => $now]);
        return $id;
    }

    private function closePeriod(string $month): void
    {
        $stamp = $this->clock->now()->format('c');
        $end = (new \DateTimeImmutable($month))->modify('last day of this month')->format('Y-m-d');
        $this->db()->insert('filing', ['id' => (string) UuidV7::generate($this->clock->now()),'environment' => 'test','type' => 'JPK_V7M','period_start' => $month,'period_end' => $end,'purpose' => 1,'seq' => 0,'status' => 'accepted','schema_version' => 'JPK_V7M(3)','totals' => '{}','created_at' => $stamp,'updated_at' => $stamp]);
    }
}
