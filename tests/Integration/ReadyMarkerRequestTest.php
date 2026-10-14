<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\SalesTestTools;

/**
 * Kryterium `ready-marker-request` (F3.2): szkic FreshBooks ze znacznikiem `KSEF-GOTOWA` przy włączonym
 * `freshbooks.sales_enabled` przechodzi do `ready` i daje jedną prośbę `pending` z payloadem zapisanym raz jako `xml_sent`.
 * Klient, NIP i kwoty są fikcyjne; nic nie trafia do KSeF.
 */
final class ReadyMarkerRequestTest extends SalesTestCase
{
    public function testMarkedDraftBecomesReadyWithOnePendingApproval(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        self::assertSame(['draft_created' => 1], $tools->poll());
        $id = $tools->invoiceId(SalesTestTools::PL);
        // Przed onboardingiem i datą usługi szkic czeka w `draft` z powodami w panelu.
        self::assertSame('draft', $tools->invoice($id)['status']);
        self::assertCount(2, $tools->blockers($id));
        $verification = $tools->verify($id, 'white_list', 'valid');
        $tools->readiness->setService($id, '2026-11-13', null, null, $tools->userId);

        $invoice = $tools->invoice($id);
        self::assertSame(['ready', null, '2026-11-16', 'pl_b2b', $verification], [$invoice['status'], $invoice['ready_blockers'], $invoice['issue_date'], $invoice['tax_scenario'], $invoice['verification_id']]);
        self::assertNotNull($invoice['ready_at']);
        $files = $this->db()->fetchAllAssociative("SELECT id, environment, kind, sha256, media_type, owner_table, owner_id FROM stored_file WHERE kind='xml_sent' AND owner_id=?", [$id]);
        self::assertCount(1, $files);
        $approvals = $this->db()->fetchAllAssociative("SELECT id, subject_type, subject_id, connector, status, payload_file_id, payload_sha256, source_version, expires_at FROM approval WHERE subject_id=?", [$id]);
        self::assertCount(1, $approvals);
        $approval = $approvals[0];
        self::assertSame(['sales_invoice', 'ksef', 'pending', $files[0]['id']], [$approval['subject_type'], $approval['connector'], $approval['status'], $approval['payload_file_id']]);
        $fileId = is_string($files[0]['id']) ? $files[0]['id'] : '';
        $bytes = $tools->files->read($fileId);
        self::assertSame(hash('sha256', $bytes), $approval['payload_sha256']);
        self::assertSame($files[0]['sha256'], $approval['payload_sha256']);
        self::assertStringContainsString('<P_2>FB-2026-0201</P_2>', $bytes);
        // Podgląd: nabywca, scenariusz, P_1, P_2, pozycje i sumy.
        $summary = json_decode(is_string($tools->approval($id)['summary'] ?? null) ? $tools->approval($id)['summary'] : '{}', true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        self::assertSame(['pl_b2b', '23', '2026-11-16', 'FB-2026-0201', '2026-11-13', '1000.00', '230.00', '1230.00'], [$summary['scenario'], $summary['p12'], $summary['p1'], $summary['p2'], $summary['p6'], $summary['net'], $summary['vat'], $summary['gross']]);
        self::assertSame(1, $tools->count("SELECT count(*) FROM notification WHERE category='approval_pending' AND approval_id=?", [$approval['id']]));
        self::assertSame(1, $tools->count("SELECT count(*) FROM audit_log WHERE action='approval.requested' AND approval_id=?", [$approval['id']]));
        // Bez wysyłki: brak wywołań konektora `ksef`.
        self::assertSame(0, $tools->count("SELECT count(*) FROM connector_call WHERE connector='ksef'"));
        // Kolejny odczyt bez zmian nie tworzy drugiej prośby.
        self::assertSame(['draft_unchanged' => 1], $tools->poll());
        self::assertSame(1, $tools->count('SELECT count(*) FROM approval WHERE subject_id=?', [$id]));
        EvidenceArtefacts::write('db/ready-marker-request-approval.csv', EvidenceArtefacts::csv($approvals));
        EvidenceArtefacts::write('db/ready-marker-request-stored_file.csv', EvidenceArtefacts::csv($files));
    }
}
