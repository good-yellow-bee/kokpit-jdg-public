<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\EntryErrorCorrection;
use App\Ledger\ManualRevenueEntry;
use App\Setting\SettingKeys;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\LedgerTestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;

/** Wpis ręczny i błąd wpisu (F2.2, docs/modules/ewidencja.md). Dane i kursy fikcyjne. */
final class LedgerManualEntryTest extends DatabaseTestCase
{
    public function testLedgerManualEntryWritesManualRowWithEvidenceAndAudit(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $user = F1TestTools::owner($this->db(), $clock);
        $ids = $this->manual($clock)->record($user, '2026-11-30', 'UMOWA/FIKCYJNA/1', 'US', 'FIKCYJNY-US-1', 'EUR', '500.00', 'software', "fikcyjny dowód\n", 'text/plain');
        $rows = LedgerTestTools::rows($this->db());
        self::assertCount(1, $ids);
        // Kurs PIT z dnia przed 30.11.2026 (fikcyjny 4,2000): 500,00 EUR → 2 100,00 zł.
        self::assertSame([1, '2026-12-10', '2026-11-30', 'UMOWA/FIKCYJNA/1', 'US', 'FIKCYJNY-US-1', '2100.00', '12.00', 'manual', 'regular'], [$rows[0]['lp'], $rows[0]['k_2'], $rows[0]['k_3'], $rows[0]['k_4'], $rows[0]['k_6'], $rows[0]['k_7'], $rows[0]['k_8'], $rows[0]['k_9'], $rows[0]['source'], $rows[0]['kind']]);
        self::assertSame($user, $this->db()->fetchOne('SELECT recorded_by FROM revenue_entry WHERE id=?', [$ids[0]]));
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM stored_file WHERE owner_id=? AND kind='evidence'", [$ids[0]]));
        $audit = $this->db()->fetchAllAssociative("SELECT action, target_id, actor_user_id FROM audit_log WHERE action='ledger.manual_entry'");
        self::assertSame([['action' => 'ledger.manual_entry','target_id' => $ids[0],'actor_user_id' => $user]], $audit);
        $this->expectException(\DomainException::class);
        $this->manual($clock)->record($user, '2026-11-30', 'UMOWA/FIKCYJNA/1', 'US', 'FIKCYJNY-US-1', 'EUR', '500.00', 'software', "fikcyjny dowód\n", 'text/plain');
    }

    public function testLedgerManualEntryRefusesUnknownServiceAndMissingEvidence(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $user = F1TestTools::owner($this->db(), $clock);
        foreach ([['nieznana', 'x'], ['software', '']] as [$service, $evidence]) {
            try {
                $this->manual($clock)->record($user, '2026-11-30', 'UMOWA/FIKCYJNA/2', null, null, 'PLN', '100.00', $service, $evidence, 'text/plain');
                self::fail('Wpis przyjęty.');
            } catch (\InvalidArgumentException | \DomainException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM revenue_entry'));
    }

    public function testLedgerEntryErrorInAmountGivesOneDifferenceRow(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $user = F1TestTools::owner($this->db(), $clock);
        [$wrong] = $this->manual($clock)->record($user, '2026-11-30', 'UMOWA/FIKCYJNA/3', null, null, 'PLN', '1000.00', 'software', 'x', 'text/plain');
        $clock->modify('2026-12-11T09:00:00Z');
        $ids = $this->correction($clock)->correct($wrong, ['amount_pln' => '1200.00'], $user);
        $rows = LedgerTestTools::rows($this->db());
        self::assertCount(1, $ids);
        self::assertSame([2, '2026-12-11', '2026-11-30', 'UMOWA/FIKCYJNA/3', '200.00', '12.00', 'manual', 'correction'], [$rows[1]['lp'], $rows[1]['k_2'], $rows[1]['k_3'], $rows[1]['k_4'], $rows[1]['k_8'], $rows[1]['k_9'], $rows[1]['source'], $rows[1]['kind']]);
        self::assertSame($wrong, $this->db()->fetchOne('SELECT corrects_entry_id FROM revenue_entry WHERE id=?', [$ids[0]]));
        EvidenceArtefacts::write('golden/ledger-entry-error-amount.txt', EvidenceArtefacts::csv($rows));
    }

    public function testLedgerEntryErrorInRevenueDateGivesReversalAndCorrectRow(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $user = F1TestTools::owner($this->db(), $clock);
        [$wrong] = $this->manual($clock)->record($user, '2026-11-30', 'UMOWA/FIKCYJNA/4', null, null, 'PLN', '1000.00', 'software', 'x', 'text/plain');
        $ids = $this->correction($clock)->correct($wrong, ['revenue_date' => '2026-11-27'], $user);
        $rows = LedgerTestTools::rows($this->db());
        self::assertCount(2, $ids);
        self::assertSame([['2026-11-30', '-1000.00', 'correction'], ['2026-11-27', '1000.00', 'correction']], [[$rows[1]['k_3'], $rows[1]['k_8'], $rows[1]['kind']], [$rows[2]['k_3'], $rows[2]['k_8'], $rows[2]['kind']]]);
        self::assertSame(2, F1TestTools::count($this->db(), 'SELECT count(*) FROM revenue_entry WHERE corrects_entry_id=?', [$wrong]));
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='ledger.entry_corrected'"));
        EvidenceArtefacts::write('golden/ledger-entry-error-k3.txt', EvidenceArtefacts::csv($rows));
    }

    private function manual(MockClock $clock): ManualRevenueEntry
    {
        $settings = ObligationTestTools::settings($this->db(), $clock);
        if ($settings->value(SettingKeys::RYCZALT_SERVICE_MAP, '2026-01-01') === null) {
            $settings->set(SettingKeys::RYCZALT_SERVICE_MAP, [['service_type' => 'software','freshbooks_item' => null,'p7_match' => [],'pkwiu' => '62.01.1','rate_pct' => '12.00','default' => true]], '2026-01-01', null);
        }
        return new ManualRevenueEntry($this->db(), F1TestTools::writer($this->db(), $clock), F1TestTools::fxRates($this->db(), $clock, ['EUR' => ['2026-11-27' => '4.2000']]), $settings, F1TestTools::files($this->db(), $clock), F1TestTools::audit($this->db(), $clock), ConnectorTestTools::configuration(false));
    }

    private function correction(MockClock $clock): EntryErrorCorrection
    {
        return new EntryErrorCorrection($this->db(), F1TestTools::writer($this->db(), $clock), F1TestTools::schema(), F1TestTools::audit($this->db(), $clock), ConnectorTestTools::configuration(false));
    }
}
