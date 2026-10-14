<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;

/**
 * Kryterium `invoice-approval-renew` (F3.2, D-16): prośba z P_1 16.11.2026 wygasa o północy w Warszawie. Faktura wraca do
 * `ready` bez nowego `xml_sent` i push; „Odnów” buduje payload z P_1 17.11.2026 i innym sha256, bez push. Dane fikcyjne.
 */
final class InvoiceApprovalRenewTest extends SalesTestCase
{
    public function testExpiredInvoiceApprovalRenewsWithNewP1(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $id = $tools->pending();
        $first = $tools->approval($id);
        self::assertIsArray($first);
        self::assertSame('2026-11-16', $tools->invoice($id)['issue_date']);
        $files = $tools->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent'");
        $pushes = $tools->count("SELECT count(*) FROM notification WHERE category='approval_pending'");

        // 17.11.2026 00:01 w Europe/Warsaw (UTC+1).
        $tools->clock->modify('2026-11-16T23:01:00Z');
        self::assertSame(1, $tools->tasks->expire());
        self::assertSame('expired', $tools->approval($id)['status'] ?? null);
        self::assertSame('ready', $tools->invoice($id)['status']);
        self::assertSame($files, $tools->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"));
        self::assertSame($pushes, $tools->count("SELECT count(*) FROM notification WHERE category='approval_pending'"));
        self::assertSame(0, $tools->count("SELECT count(*) FROM notification WHERE category='connector_failure'"));

        $renewed = $tools->gate->renew('sales_invoice', $id, $tools->userId);
        $row = $tools->approval($id);
        self::assertSame([$renewed, 'pending'], [$row['id'], $row['status']]);
        self::assertNotSame($first['payload_sha256'], $row['payload_sha256']);
        self::assertSame('2026-11-17', $tools->invoice($id)['issue_date']);
        self::assertStringContainsString('<P_1>2026-11-17</P_1>', $tools->files->read(is_string($row['payload_file_id']) ? $row['payload_file_id'] : ''));
        self::assertSame($files + 1, $tools->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"));
        self::assertSame($pushes, $tools->count("SELECT count(*) FROM notification WHERE category='approval_pending'"));
        self::assertSame('ready', $tools->invoice($id)['status']);
        $rows = $this->db()->fetchAllAssociative('SELECT id, status, payload_sha256, requested_at, expires_at, finished_at, superseded_by_id FROM approval WHERE subject_id=? ORDER BY requested_at, id', [$id]);
        self::assertCount(2, $rows);
        EvidenceArtefacts::write('db/invoice-approval-renew-approval.csv', EvidenceArtefacts::csv($rows));
    }
}
