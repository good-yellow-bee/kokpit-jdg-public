<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;

/**
 * Kryterium `jpk-send-hash-mismatch` (F5.1): bajty `xml_sent` podmienione w `FileStore` po decyzji. Worker liczy sha256
 * ponownie i anuluje wysyłkę przed jakimkolwiek wywołaniem `jpk`; złożenie wraca do `draft` (plik-dom
 * docs/data-model.md, „Powiązanie z bramką zatwierdzeń”; D-57), push `connector_failure`.
 */
final class JpkSendHashMismatchTest extends JpkSendTestCase
{
    public function testChangedBytesCancelWithoutAnyJpkCall(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $path = $this->db()->fetchOne('SELECT s.path FROM stored_file s JOIN approval a ON a.payload_file_id=s.id WHERE a.id=?', [$approvalId]);
        self::assertIsString($path);
        $file = '/app/var/test-files/jpk/' . $path;
        $original = (string) file_get_contents($file);
        chmod($file, 0640);
        file_put_contents($file, str_replace('<KodUrzedu>', '<KodUrzedu> ', $original));
        try {
            self::assertNotSame($original, file_get_contents($file));
            self::assertNull($send->handler->dispatch($approvalId));
        } finally {
            file_put_contents($file, $original);
            chmod($file, 0440);
        }
        $row = $send->approval($approvalId);
        self::assertSame(['cancelled', 'hash_mismatch'], [$row['status'], $row['cancel_reason']]);
        self::assertSame('draft', $send->filing()['status']);
        self::assertSame(0, $send->calls());
        self::assertSame(0, count($send->fake->recordings->requests));
        self::assertSame(1, $send->pushes($approvalId));
        EvidenceArtefacts::write('db/jpk-send-hash-mismatch-approval.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT id, status, cancel_reason, payload_sha256 FROM approval WHERE id=?', [$approvalId])));
    }
}
