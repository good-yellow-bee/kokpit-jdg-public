<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\ManualFiling;
use App\Ledger\MonthChecklist;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Złożenie poza kokpitem, bramki `period.checklist`, ponowne otwarcie i `vat_ue` po bramkach (F2.3, D-49).
 * UPO, pliki JPK i passkey są fikcyjne.
 */
final class ManualFilingTest extends DatabaseTestCase
{
    private const UPO = "%PDF-1.4\n% fikcyjne UPO (fictional)\n";
    private const STEP_UP = 'fictional-step-up-credential';

    public function testManualFilingJpkV7mIsAcceptedWithoutApprovalAndClosesPeriod(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        ObligationTestTools::seed($this->db(), $clock);
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        // Fikcyjny koszt z października czeka na przegląd: bramka `costs_reviewed` niespełniona, ale to tylko alert.
        F1TestTools::costs($this->db(), $clock)->importKsef(F1TestTools::fa3(['issue' => '2026-10-20','service' => '2026-10-20']), 'FIKCYJNY-KSEF-0601', new \DateTimeImmutable('2026-10-20T10:05:00Z'));

        $id = $this->filings($clock)->record($owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO, 'application/pdf', 'FIKCYJNY-REF-0001', null, '0');

        $filing = $this->db()->fetchAssociative("SELECT type, period_start, purpose, seq, status, submitted_via, reference_number, totals, accepted_at IS NOT NULL AS accepted FROM filing WHERE id=?", [$id]);
        self::assertSame(['type' => 'JPK_V7M','period_start' => '2026-10-01','purpose' => 1,'seq' => 0,'status' => 'accepted','submitted_via' => 'manual','reference_number' => 'FIKCYJNY-REF-0001','totals' => '{"p62": "0"}','accepted' => true], $filing);
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM approval'));
        $upo = $this->db()->fetchOne("SELECT sha256 FROM stored_file WHERE owner_table='filing' AND owner_id=? AND kind='upo'", [$id]);
        self::assertSame(hash('sha256', self::UPO), $upo);
        $period = $this->db()->fetchAssociative("SELECT period_start, status, closed_by_filing_id, checklist FROM period WHERE period_start='2026-10-01'");
        self::assertIsArray($period);
        self::assertSame(['closed', $id], [$period['status'], $period['closed_by_filing_id']]);
        self::assertIsString($period['checklist']);
        $checklist = json_decode($period['checklist'], true);
        self::assertIsArray($checklist);
        self::assertFalse($checklist['ok']);
        $gates = $checklist['gates'];
        self::assertIsArray($gates);
        // jsonb nie zachowuje kolejności kluczy.
        self::assertEqualsCanonicalizing(MonthChecklist::GATES, array_keys($gates));
        self::assertSame(['ok' => false,'count' => 1], $gates['costs_reviewed']);
        self::assertSame(['ok' => true,'count' => 0], $gates['sales_final']);
        $audit = $this->db()->fetchAllAssociative("SELECT action, target_id, actor_user_id, payload_sha256 FROM audit_log WHERE action='filing.manual_recorded'");
        self::assertSame([['action' => 'filing.manual_recorded','target_id' => $id,'actor_user_id' => $owner,'payload_sha256' => hash('sha256', self::UPO)]], $audit);
        self::assertSame(['done', $owner], array_values((array) $this->db()->fetchAssociative("SELECT status, done_by FROM obligation WHERE type='vat_jpk_v7m' AND period_start='2026-10-01'")));
        EvidenceArtefacts::write('db/manual-filing-filing.csv', EvidenceArtefacts::csv([$filing + ['upo_sha256' => $upo]]));
        EvidenceArtefacts::write('db/manual-filing-period.csv', EvidenceArtefacts::csv([$period]));
        EvidenceArtefacts::write('db/manual-filing-audit_log.csv', EvidenceArtefacts::csv($audit));
        EvidenceArtefacts::write('db/manual-filing-approval.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT id FROM approval')));
    }

    public function testManualFilingCorrectionNeedsReopenAndSupersedesPrevious(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $filings = $this->filings($clock);
        $periods = F1TestTools::periods($this->db(), $clock);
        $first = $filings->record($owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO, 'application/pdf', null, null, '120');
        try {
            $filings->record($owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO, 'application/pdf', null, null, '100');
            self::fail('Korekta zamkniętego okresu przeszła bez ponownego otwarcia.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('ponownym otwarciu', $e->getMessage());
        }
        try {
            $periods->reopen('2026-10-01', '   ', $owner, self::STEP_UP);
            self::fail('Ponowne otwarcie bez powodu przeszło.');
        } catch (\InvalidArgumentException) {
            self::assertSame('closed', $periods->status('2026-10-01'));
        }
        $clock->modify('2026-11-21T09:00:00Z');
        $periods->reopen('2026-10-01', 'Fikcyjna faktura kosztowa przypisana do złego miesiąca', $owner, self::STEP_UP);
        self::assertSame('reopened', $periods->status('2026-10-01'));
        self::assertFalse($periods->closed('2026-10-15'), 'Ponowne otwarcie zdejmuje blokadę mimo przyjętego JPK_V7M.');

        $clock->modify('2026-11-22T09:00:00Z');
        $correction = $filings->record($owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO . "korekta\n", 'application/pdf', null, null, '100');
        $rows = $this->db()->fetchAllAssociative("SELECT id, purpose, seq, status, supersedes_id FROM filing WHERE type='JPK_V7M' ORDER BY seq");
        self::assertSame([['id' => $first,'purpose' => 1,'seq' => 0,'status' => 'superseded','supersedes_id' => null], ['id' => $correction,'purpose' => 2,'seq' => 1,'status' => 'accepted','supersedes_id' => $first]], $rows);
        self::assertSame($correction, $this->db()->fetchOne("SELECT closed_by_filing_id FROM period WHERE period_start='2026-10-01' AND status='closed'"));

        // Zamknięcie bez korekty: powód w `audit_log` i zadanie „korekta wymagana” znika.
        $periods->reopen('2026-10-01', 'Fikcyjny powód drugiego otwarcia', $owner, self::STEP_UP);
        $clock->modify('2026-11-23T09:00:00Z');
        $periods->correctionRequired('2026-10-05', 'revenue_entry');
        try {
            $periods->reclose('2026-11-01', 'Fikcyjny powód', $owner, self::STEP_UP);
            self::fail('Zamknięcie bez korekty otwartego miesiąca przeszło.');
        } catch (\DomainException) {
            self::assertNull($periods->status('2026-11-01'));
        }
        $clock->modify('2026-11-24T09:00:00Z');
        $periods->reclose('2026-10-01', 'Fikcyjny zapis nie zmienia JPK_V7M', $owner, self::STEP_UP);
        self::assertSame('closed', $periods->status('2026-10-01'));
        self::assertSame([], $periods->corrections());
        $audit = $this->db()->fetchAllAssociative("SELECT action, details->>'reason' AS reason, details->>'step_up_credential_id' AS step_up FROM audit_log WHERE action IN ('period.reopened','period.reclosed') ORDER BY sequence");
        self::assertSame(['period.reopened','period.reopened','period.reclosed'], array_column($audit, 'action'));
        self::assertSame([self::STEP_UP], array_values(array_unique(array_column($audit, 'step_up'))));
    }

    public function testManualFilingJpkV7mNeedsP62FromFileOrInput(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $filings = $this->filings($clock);
        try {
            $filings->record($owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO, 'application/pdf');
            self::fail('JPK_V7M bez P_62 przeszedł.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('P_62', $e->getMessage());
        }
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM filing'));
        // Fikcyjny wycinek JPK_V7M: P_62 z deklaracji, plik zapisany jako `xml_sent`.
        $xml = '<?xml version="1.0" encoding="UTF-8"?><JPK xmlns="http://crd.gov.pl/wzor/2025/12/19/14090/"><Deklaracja><PozycjeSzczegolowe><P_51>0</P_51><P_62>345</P_62></PozycjeSzczegolowe></Deklaracja><!-- fictional --></JPK>';
        $id = $filings->record($owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO, 'application/pdf', null, $xml);
        self::assertSame('{"p62": "345"}', $this->db()->fetchOne('SELECT totals FROM filing WHERE id=?', [$id]));
        self::assertSame(hash('sha256', $xml), $this->db()->fetchOne("SELECT sha256 FROM stored_file WHERE owner_id=? AND kind='xml_sent'", [$id]));
        // VAT-UE nie potrzebuje P_62 i nie zamyka okresu.
        $vatUe = $filings->record($owner, self::STEP_UP, 'VAT_UE', '2026-09', self::UPO, 'application/pdf');
        self::assertSame(['VAT-UE(5)', '{}'], array_values((array) $this->db()->fetchAssociative('SELECT schema_version, totals FROM filing WHERE id=?', [$vatUe])));
        self::assertFalse(F1TestTools::periods($this->db(), $clock)->closed('2026-09-01'));
    }

    public function testVatUeAfterChecklistBecomesNotApplicableOnlyAfterManualFiling(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        ObligationTestTools::seed($this->db(), $clock);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $engine->recompute();
        // Bez sprzedaży eu_b2b w 10.2026, po 15. dniu M+1, ale przed zapisem `period.checklist`: obowiązek otwarty.
        self::assertSame('open', $this->vatUe('2026-10-01'));
        $this->filings($clock)->record($owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO, 'application/pdf', null, null, '0');
        self::assertSame(['not_applicable', null], array_values((array) $this->db()->fetchAssociative("SELECT status, done_by FROM obligation WHERE type='vat_ue' AND period_start='2026-10-01'")));
        // Kursor Subject1 daleko za 15.12.2026: od pierwszego zamkniętego miesiąca listopad czeka na swoje bramki.
        $stamp = $clock->now()->format('c');
        $this->db()->executeStatement("INSERT INTO sync_cursor (id,environment,connector,stream,last_hwm,created_at,updated_at) VALUES (?,'test','ksef','subject1',?,?,?)", [(string) UuidV7::generate(), '2027-01-10T06:00:00+01:00', $stamp, $stamp]);
        $engine->recompute();
        self::assertSame('not_applicable', $this->vatUe('2026-10-01'));
        self::assertSame('open', $this->vatUe('2026-11-01'));
        EvidenceArtefacts::write('db/vat-ue-after-checklist-obligation.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative("SELECT period_start, status, done_by FROM obligation WHERE type='vat_ue' AND period_start IN ('2026-10-01','2026-11-01') ORDER BY period_start")));
    }

    private function vatUe(string $month): mixed
    {
        return $this->db()->fetchOne("SELECT status FROM obligation WHERE type='vat_ue' AND period_start=?", [$month]);
    }

    private function filings(MockClock $clock): ManualFiling
    {
        return new ManualFiling($this->db(), $clock, F1TestTools::audit($this->db(), $clock), F1TestTools::files($this->db(), $clock), F1TestTools::periods($this->db(), $clock), new MonthChecklist($this->db(), $clock, ConnectorTestTools::configuration(false)), ConnectorTestTools::configuration(false));
    }
}
