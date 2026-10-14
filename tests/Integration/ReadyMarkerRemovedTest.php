<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Messenger\Message\ApprovalDispatchMessage;
use App\Tests\Support\SalesTestTools;

/**
 * Kryterium `ready-marker-removed` (F3.2): zdjęcie znacznika przed decyzją cofa fakturę do `draft`, a zatwierdzenie
 * dostaje `superseded` bez następcy; po zatwierdzeniu zdjęcie znacznika nie ma skutku. Dane fikcyjne.
 */
final class ReadyMarkerRemovedTest extends SalesTestCase
{
    public function testRemovingMarkerBeforeDecisionSupersedesApproval(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $id = $tools->pending();
        $approval = $tools->approvalId($id);
        $tools->poll([SalesTestTools::PL => ['po_number' => null]]);
        $invoice = $tools->invoice($id);
        self::assertSame(['draft', null, null], [$invoice['status'], $invoice['ready_at'], $invoice['ready_blockers']]);
        $row = $tools->approval($id);
        self::assertSame([$approval, 'superseded', null], [$row['id'] ?? null, $row['status'] ?? null, $row['superseded_by_id'] ?? null]);
        self::assertSame(1, $tools->count("SELECT count(*) FROM audit_log WHERE action='approval.superseded' AND approval_id=?", [$approval]));
        // Ponowny znacznik daje nową prośbę.
        $tools->poll();
        self::assertSame('ready', $tools->invoice($id)['status']);
        self::assertSame('pending', $tools->approval($id)['status'] ?? null);
        self::assertSame(2, $tools->count('SELECT count(*) FROM approval WHERE subject_id=?', [$id]));
    }

    public function testRemovingMarkerAfterDecisionHasNoEffect(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $id = $tools->pending();
        $approval = $tools->approvalId($id);
        $tools->gate->approve($approval, $tools->userId);
        $tools->poll([SalesTestTools::PL => ['po_number' => null]]);
        $invoice = $tools->invoice($id);
        self::assertSame('approved', $invoice['status']);
        self::assertNotNull($invoice['invoice_number_locked_at']);
        self::assertSame('approved', $tools->approval($id)['status'] ?? null);
        self::assertSame(1, $tools->count('SELECT count(*) FROM approval WHERE subject_id=?', [$id]));
        // Wysyłka czeka w szynie `official_writes` (F3.3); nic nie wywołuje KSeF.
        self::assertCount(1, array_filter($tools->bus->messages, static fn(object $message): bool => $message instanceof ApprovalDispatchMessage));
        self::assertSame(0, $tools->count("SELECT count(*) FROM connector_call WHERE connector='ksef'"));
    }
}
