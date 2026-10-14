<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;

/**
 * Kryterium `subject1-cancels-approval` (F3.2 część b): faktura wystawiona poza kokpitem i pobrana z Subject1, o tym samym
 * `kind` i P_2 co szkic, anuluje żywe zatwierdzenie z `manual_invoice`; szkic → `cancelled_before_send`, a faktura z KSeF
 * przejmuje jego `freshbooks_id`. Zero wywołań zapisujących. Numery KSeF, NIP i kwoty są fikcyjne.
 */
final class Subject1CancelsApprovalTest extends SalesTestCase
{
    public function testSubject1InvoiceCancelsApprovedDraft(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $tools = $this->corrections($sales);
        $id = $sales->pending();
        $approval = $sales->approvalId($id);
        $sales->gate->approve($approval, $sales->userId);
        self::assertSame('approved', $sales->invoice($id)['status']);

        $ksef = $tools->importSubject1('FB-2026-0201', '2026-11-16', 'FIKCYJNY-KSEF-0601');
        $row = $this->db()->fetchAssociative('SELECT id, subject_type, subject_id, status, cancel_reason, finished_at FROM approval WHERE id=?', [$approval]);
        self::assertIsArray($row);
        self::assertSame(['cancelled', 'manual_invoice'], [$row['status'], $row['cancel_reason']]);
        $draft = $this->db()->fetchAssociative('SELECT status, freshbooks_id, invoice_number_locked_at FROM invoice WHERE id=?', [$id]);
        self::assertSame(['status' => 'cancelled_before_send','freshbooks_id' => null,'invoice_number_locked_at' => null], $draft);
        $imported = $this->db()->fetchAssociative('SELECT status, ksef_number, freshbooks_id, panel_task FROM invoice WHERE id=?', [$ksef]);
        self::assertSame(['status' => 'accepted','ksef_number' => 'FIKCYJNY-KSEF-0601','freshbooks_id' => '2001','panel_task' => null], $imported);
        self::assertSame(1, $sales->count("SELECT count(*) FROM audit_log WHERE action='approval.cancelled' AND approval_id=?", [$approval]));
        self::assertSame(0, $sales->count("SELECT count(*) FROM connector_call WHERE connector='ksef'"));
        EvidenceArtefacts::write('db/subject1-cancels-approval-approval.csv', EvidenceArtefacts::csv([$row]));
    }

    public function testSubject1InvoiceCancelsPendingDraft(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $tools = $this->corrections($sales);
        $id = $sales->pending();
        $approval = $sales->approvalId($id);
        $tools->importSubject1('FB-2026-0201', '2026-11-16', 'FIKCYJNY-KSEF-0602');
        self::assertSame(['status' => 'cancelled','cancel_reason' => 'manual_invoice'], $this->db()->fetchAssociative('SELECT status, cancel_reason FROM approval WHERE id=?', [$approval]));
        self::assertSame('cancelled_before_send', $sales->invoice($id)['status']);
        // Kolejny odczyt FreshBooks łączy fakturę z KSeF, bez nowego szkicu i bez prośby.
        $sales->poll();
        self::assertSame(1, $sales->count("SELECT count(*) FROM approval WHERE subject_id=?", [$id]));
        self::assertSame(0, $sales->count("SELECT count(*) FROM approval WHERE status IN ('pending','approved')"));
    }
}
