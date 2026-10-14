<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\SalesTestTools;

/**
 * Kryterium `approval-p2-lock` (F3.2): decyzja ze step-upem blokuje P_2 (`invoice_number_locked_at`); szkic z numerem
 * faktury z Subject1 nie dostaje prośby; zmiana numeru we FreshBooks przed wysyłką daje `superseded` i nowe `pending`
 * z innym sha256. Numery, NIP i numery KSeF są fikcyjne.
 */
final class ApprovalP2LockTest extends SalesTestCase
{
    public function testDecisionLocksNumberAndChangeSupersedes(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $id = $tools->pending();
        $first = $tools->approval($id);
        self::assertIsArray($first);
        $tools->gate->approve($tools->approvalId($id), $tools->userId);
        $locked = $tools->invoice($id);
        self::assertSame(['approved', 'FB-2026-0201'], [$locked['status'], $locked['invoice_number']]);
        self::assertNotNull($locked['invoice_number_locked_at']);

        // Zmiana numeru we FreshBooks przed wysyłką: stare zatwierdzenie `superseded`, nowe `pending` z nowym payloadem.
        self::assertSame(['draft_updated' => 1], $tools->poll([SalesTestTools::PL => ['invoice_number' => 'FB-2026-0299']]));
        $old = $this->db()->fetchAssociative('SELECT status, superseded_by_id FROM approval WHERE id=?', [$first['id']]);
        $new = $tools->approval($id);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame(['superseded', $new['id']], [$old['status'], $old['superseded_by_id']]);
        self::assertSame('pending', $new['status']);
        self::assertNotSame($first['payload_sha256'], $new['payload_sha256']);
        $invoice = $tools->invoice($id);
        self::assertSame(['ready', 'FB-2026-0299', null, 2], [$invoice['status'], $invoice['invoice_number'], $invoice['invoice_number_locked_at'], $invoice['version']]);
        self::assertStringContainsString('<P_2>FB-2026-0299</P_2>', $tools->files->read(is_string($new['payload_file_id']) ? $new['payload_file_id'] : ''));
        EvidenceArtefacts::write('db/approval-p2-lock-invoice.csv', EvidenceArtefacts::csv([$locked, $invoice]));
    }

    public function testNumberOfSubject1InvoiceBlocksRequest(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $xml = F1TestTools::fa3(['number' => 'FB-2026-0201','issue' => '2026-11-02','service' => '2026-11-02','currency' => 'PLN','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.'),'lines' => [['net' => '1000.00','code' => '23','vat' => '230.00']]]);
        F1TestTools::subject1($this->db(), $tools->clock)->import($xml, 'FIKCYJNY-KSEF-0501', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        // Szkic o numerze faktury już przyjętej w KSeF łączy się z nią; drugi szkic o tym numerze to kolizja.
        $tools->poll([SalesTestTools::PL => ['id' => 2003,'invoiceid' => 2003]]);
        self::assertSame(['number_conflict' => 1], $tools->poll([SalesTestTools::PL => ['id' => 2009,'invoiceid' => 2009]]));
        self::assertSame(0, $tools->count("SELECT count(*) FROM invoice WHERE freshbooks_id='2009'"));
        self::assertSame(0, $tools->count('SELECT count(*) FROM approval'));
    }
}
