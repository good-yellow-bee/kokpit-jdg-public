<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestFiles;
use App\Tests\Support\EvidenceArtefacts;

/**
 * Kryterium `ksef-send-hash-mismatch` (F3.3): bajty `xml_sent` podmienione w `FileStore` po decyzji. Worker liczy sha256
 * ponownie i anuluje wysyłkę przed jakimkolwiek wywołaniem `ksef`; faktura wraca do `ready`, push `connector_failure`.
 */
final class KsefSendHashMismatchTest extends KsefSendTestCase
{
    public function testChangedBytesCancelWithoutAnyKsefCall(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $path = $this->db()->fetchOne('SELECT s.path FROM stored_file s JOIN approval a ON a.payload_file_id=s.id WHERE a.id=?', [$approvalId]);
        self::assertIsString($path);
        $file = TestFiles::root('sales') . '/' . $path;
        $original = (string) file_get_contents($file);
        chmod($file, 0640);
        file_put_contents($file, str_replace('<P_2>FB-2026-0201</P_2>', '<P_2>FB-2026-0299</P_2>', $original));
        try {
            self::assertNull($send->handler->dispatch($approvalId));
        } finally {
            file_put_contents($file, $original);
            chmod($file, 0440);
        }
        $row = $send->approval($approvalId);
        self::assertSame(['cancelled', 'hash_mismatch'], [$row['status'], $row['cancel_reason']]);
        self::assertSame('ready', $send->invoice($id)['status']);
        self::assertSame(0, $send->calls());
        self::assertSame(0, $send->calls(null, 'latarnia'));
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM notification WHERE category='connector_failure' AND approval_id=? AND channel='push'", [$approvalId]));
        EvidenceArtefacts::write('db/ksef-send-hash-mismatch-connector_call.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative("SELECT connector, operation, approval_id FROM connector_call WHERE connector='ksef' ORDER BY sequence")));
    }
}
