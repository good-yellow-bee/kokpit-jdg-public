<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\ClosedPeriod;
use App\Ledger\RevenueDocument;
use App\Setting\SettingKeys;
use App\Tax\ZusPayments;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Okres miesiąca (F2.3, D-48): `ledger.entry_check`, blokada zamkniętego M i „korekta wymagana”
 * (docs/compliance-calendar.md, „Blokada okresu”; docs/modules/ewidencja.md, „Wpis do 20. dnia”). Dane fikcyjne.
 */
final class PeriodTest extends DatabaseTestCase
{
    private const MAP = [['service_type' => 'software','freshbooks_item' => null,'p7_match' => ['usługa 1'],'pkwiu' => '62.01.1','rate_pct' => '12.00','default' => false]];

    public function testLedgerEntryCheckClosesLedgerEntryObligationOnlyWhenMonthIsComplete(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        ObligationTestTools::seed($this->db(), $clock);
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $settings = ObligationTestTools::settings($this->db(), $clock);
        $settings->set(SettingKeys::RYCZALT_SERVICE_MAP, self::MAP, '2026-01-01', null, 'fikcyjna mapa');
        // Przed przełączeniem faktura nie dostaje wpisu, a kontrola niczego nie zamyka.
        F1TestTools::subject1($this->db(), $clock)->import($this->vat('FV/5/11/2026'), 'FIKCYJNY-KSEF-0501', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $check = F1TestTools::entryCheck($this->db(), $clock);
        $inactive = $check->run();
        self::assertSame(['2026-11-01', false, false], [$inactive->month, $inactive->active, $inactive->closed]);

        $settings->set(SettingKeys::LEDGER_AUTO_ENTRIES, true, '2026-01-01', null);
        $result = $check->run();
        self::assertSame([true, false], [$result->active, $result->closed]);
        self::assertSame([['type' => 'invoice','number' => 'FV/5/11/2026','date' => '2026-11-05']], array_map(static fn(array $row): array => array_diff_key($row, ['id' => true]), $result->missing));
        self::assertSame('open', $this->obligation('2026-11-01'));
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM period WHERE ledger_closed_at IS NOT NULL"));

        // Wpis ręczny tej samej faktury (klucz: numer i rok) uzupełnia ostatni brak i zamyka obowiązek za 11.2026.
        F1TestTools::writer($this->db(), $clock)->write(new RevenueDocument('manual', null, 'FV/5/11/2026', null, 'PL', F1TestTools::CLIENT_NIP, '2026-11-05', 'PLN', null, ['12.00' => '1000.00']), $owner);
        self::assertSame([], $check->missing('2026-11-01'));
        self::assertSame('done', $this->obligation('2026-11-01'));
        self::assertIsString($this->db()->fetchOne("SELECT ledger_closed_at FROM period WHERE period_start='2026-11-01'"));
        self::assertFalse($check->run()->closed, 'Drugi przebieg nie zamyka ponownie.');
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='period.ledger_closed'"));
        EvidenceArtefacts::write('db/ledger-entry-check-obligation.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative("SELECT type, period_start, status FROM obligation WHERE type='ledger_entry' AND period_start='2026-11-01'")));
        EvidenceArtefacts::write('db/ledger-entry-check-period.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT period_start, status, ledger_closed_at IS NOT NULL AS ledger_closed FROM period ORDER BY period_start')));
    }

    public function testLedgerEntryCheckClosesMonthWithoutDocumentsOnFirstRun(): void
    {
        $clock = new MockClock('2026-11-15T07:00:00Z');
        ObligationTestTools::settings($this->db(), $clock)->set(SettingKeys::LEDGER_AUTO_ENTRIES, true, '2026-01-01', null);
        $result = F1TestTools::entryCheck($this->db(), $clock)->run();
        self::assertSame(['2026-10-01', [], true, true], [$result->month, $result->missing, $result->active, $result->closed]);
    }

    public function testClosedPeriodRevenueSetsCorrectionRequired(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $periods = F1TestTools::periods($this->db(), $clock);
        $periods->close('2026-11-01', $this->filing('2026-11-01'));
        self::assertTrue($periods->closed('2026-11-17'));
        self::assertSame([], $periods->corrections());

        $clock->modify('2026-12-11T09:00:00Z');
        // `revenue_entry` jest tylko dopisywana: zapis przechodzi, ale miesiąc dostaje zadanie „korekta wymagana”.
        F1TestTools::writer($this->db(), $clock)->write(new RevenueDocument('manual', null, 'FV/9/11/2026', null, 'PL', F1TestTools::CLIENT_NIP, '2026-11-20', 'PLN', null, ['12.00' => '500.00']), $owner);
        $corrections = $periods->corrections();
        self::assertSame(['2026-11-01'], array_column($corrections, 'period_start'));
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='period.correction_required' AND details->>'cause'='revenue_entry'"));
        // Zapis do otwartego miesiąca nie tworzy zadania.
        F1TestTools::writer($this->db(), $clock)->write(new RevenueDocument('manual', null, 'FV/1/12/2026', null, 'PL', F1TestTools::CLIENT_NIP, '2026-12-02', 'PLN', null, ['12.00' => '500.00']), $owner);
        self::assertCount(1, $periods->corrections());
        EvidenceArtefacts::write('db/closed-period-revenue-period.csv', EvidenceArtefacts::csv($corrections));
    }

    public function testClosedPeriodCostAndZusAreRefusedWithEarliestOpenPeriod(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $id = F1TestTools::costs($this->db(), $clock)->importKsef(F1TestTools::fa3(), 'FIKCYJNY-KSEF-0502', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        // JPK_V7M za 11.2026 przyjęty, zanim okres dostał wiersz: miesiąc jest zamknięty.
        $this->filing('2026-11-01');
        $review = F1TestTools::review($this->db(), $clock);
        $review->review($id, 'business', $owner);
        try {
            $review->assign($id, '2026-11-01', $owner);
            self::fail('Koszt trafił do zamkniętego miesiąca.');
        } catch (ClosedPeriod $e) {
            self::assertSame(['2026-11-01', '2026-12-01'], [$e->period, $e->proposed]);
            self::assertStringContainsString('2026-12', $e->getMessage());
        }
        self::assertSame('2026-12-01', $review->assign($id, '2026-12-01', $owner));
        try {
            (new ZusPayments($this->db(), $clock, F1TestTools::audit($this->db(), $clock), ConnectorTestTools::configuration(false), F1TestTools::periods($this->db(), $clock)))->record($owner, '2026-11', '1000.00', '400.00', '100.00', '2026-12-08');
            self::fail('Składki za zamknięty miesiąc przeszły.');
        } catch (ClosedPeriod $e) {
            self::assertSame('2026-12-01', $e->proposed);
        }
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM zus_payment'));
    }

    private function vat(string $number): string
    {
        return F1TestTools::fa3(['number' => $number,'issue' => '2026-11-05','service' => '2026-11-05','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.')]);
    }

    /** Przyjęty JPK_V7M za miesiąc (fikcyjny). */
    private function filing(string $month): string
    {
        $id = (string) UuidV7::generate();
        $stamp = '2026-12-01T10:00:00+00:00';
        $end = (new \DateTimeImmutable($month))->modify('last day of this month')->format('Y-m-d');
        $this->db()->insert('filing', ['id' => $id,'environment' => 'test','type' => 'JPK_V7M','period_start' => $month,'period_end' => $end,'purpose' => 1,'seq' => 0,'status' => 'accepted','schema_version' => 'JPK_V7M(3)','totals' => '{}','created_at' => $stamp,'updated_at' => $stamp]);
        return $id;
    }

    private function obligation(string $month): mixed
    {
        return $this->db()->fetchOne("SELECT status FROM obligation WHERE type='ledger_entry' AND period_start=?", [$month]);
    }
}
