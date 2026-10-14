<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\SalesConditions;
use App\Tests\Support\SalesTestTools;
use Symfony\Component\Clock\MockClock;

/**
 * Kryterium `approval-p1-future` (F3.2, D-16): P_1 późniejsza niż dziś w Europe/Warsaw blokuje prośbę i decyzję.
 * Generator z zegarem o dzień do przodu daje payload z P_1 jutro. Dane fikcyjne.
 */
final class ApprovalP1FutureTest extends SalesTestCase
{
    public function testPayloadWithTomorrowP1BlocksRequest(): void
    {
        $tools = $this->tools(builderClock: new MockClock('2026-11-17T09:00:00Z'));
        $tools->prepare();
        $id = $tools->pending();
        $invoice = $tools->invoice($id);
        self::assertSame('draft', $invoice['status']);
        self::assertContains('P_1 2026-11-17 jest w przyszłości (dziś 2026-11-16); prośba i decyzja zablokowane (D-16).', $tools->blockers($id));
        self::assertSame(0, $tools->count('SELECT count(*) FROM approval'));
        self::assertSame(0, $tools->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"));
    }

    public function testTomorrowP1BlocksDecision(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $id = $tools->pending();
        $approval = $tools->approvalId($id);
        // P_1 zapisana przy prośbie zmieniona na jutro: decyzja odmawia, prośba zostaje `pending`.
        $this->db()->update('invoice', ['issue_date' => '2026-11-17'], ['id' => $id]);
        try {
            $tools->gate->approve($approval, $tools->userId);
            self::fail('Decyzja z P_1 w przyszłości przeszła.');
        } catch (\DomainException $refused) {
            self::assertStringContainsString('P_1 2026-11-17 jest w przyszłości', $refused->getMessage());
        }
        self::assertSame('pending', $tools->approval($id)['status'] ?? null);
        self::assertNull($tools->invoice($id)['invoice_number_locked_at']);
    }

    public function testP1RuleUsesWarsawDay(): void
    {
        self::assertNotNull(SalesConditions::p1Problem('2026-11-17', '2026-11-16'));
        self::assertNull(SalesConditions::p1Problem('2026-11-16', '2026-11-16'));
        self::assertNull(SalesConditions::p1Problem('2026-11-15', '2026-11-16'));
        self::assertSame(SalesTestTools::NOW, '2026-11-16T09:00:00Z');
    }
}
