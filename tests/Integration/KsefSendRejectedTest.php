<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\SalesTestTools;

/**
 * Kryterium `ksef-send-rejected` (F3.3): status faktury 450 (błąd weryfikacji semantyki) daje `rejected` z
 * `result_code`, a faktura `rejected`. Faktura odrzucona nie została wystawiona: poprawka szkicu we FreshBooks daje
 * `ready` i nowe `pending`, bez KOR.
 */
final class KsefSendRejectedTest extends KsefSendTestCase
{
    public function testValidationRejectionThenFixGivesNewPendingWithoutKor(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/status-available.json', 'Ksef/send-rejected.json');

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($approvalId);
        self::assertSame(['rejected', '450'], [$row['status'], $row['result_code']]);
        self::assertNotNull($row['finished_at']);
        $invoice = $send->invoice($id);
        self::assertSame('rejected', $invoice['status']);
        self::assertNull($invoice['ksef_number']);
        $session = $this->db()->fetchOne('SELECT closed_at FROM ksef_session');
        self::assertNotNull($session);
        self::assertSame(0, $send->recordings->remaining());

        // Poprawka we FreshBooks: cena jednostkowa pozycji 450 zamiast 500.
        $line = ['lineid' => 1,'name' => 'Development','description' => 'Fikcyjne prace programistyczne','qty' => '2','unit_cost' => ['amount' => '450.00','code' => 'PLN'],'amount' => ['amount' => '900.00','code' => 'PLN'],'taxAmount1' => '207.00','taxAmount2' => '0'];
        self::assertSame(['draft_updated' => 1], $send->sales->poll([SalesTestTools::PL => ['lines' => [$line],'amount' => ['amount' => '1107.00','code' => 'PLN']]]));
        self::assertSame('ready', $send->invoice($id)['status']);
        $new = $send->sales->approval($id);
        self::assertIsArray($new);
        self::assertSame('pending', $new['status']);
        self::assertNotSame($approvalId, $new['id']);
        self::assertNotSame($row['payload_sha256'], $new['payload_sha256']);
        self::assertSame('rejected', $send->approval($approvalId)['status']);
        self::assertSame(0, $send->sales->count("SELECT count(*) FROM invoice WHERE kind='KOR'"));
        self::assertSame(0, $send->sales->count("SELECT count(*) FROM approval WHERE subject_type='correction_invoice'"));
    }
}
