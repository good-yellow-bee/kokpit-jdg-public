<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;

/**
 * Kryterium `jpk-send-accepted` (F5.1): zatwierdzony JPK_EWP(4) idzie przez `OfficialWritesHandler` i `JpkWriter`
 * na `FakeJpkGateway`: `InitUploadSigned`, Put Blob, `FinishUpload`, `Status` 120, potem 200 z UPO. Nic nie wychodzi
 * do sieci; dane fikcyjne.
 */
final class JpkSendAcceptedTest extends JpkSendTestCase
{
    public function testApprovedFilingIsSubmittedThenAcceptedWithUpo(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->loadUpload();
        $send->fake->load('api-status-120', 'api-status-200');
        $payloadSha = $send->approval($approvalId)['payload_sha256'];
        self::assertIsString($payloadSha);
        self::assertSame('jpk', $send->approval($approvalId)['connector']);

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        $refs = $send->refs($approvalId);
        self::assertSame(JpkSendTestTools::REFERENCE, $refs['reference_number']);
        foreach (['correlation_id', 'init_upload_at', 'timeout_s', 'blob_names', 'finish_upload_at'] as $key) {
            self::assertArrayHasKey($key, $refs);
        }
        $filing = $send->filing();
        self::assertSame(['submitted', JpkSendTestTools::REFERENCE, 'jpk'], [$filing['status'], $filing['reference_number'], $filing['submitted_via']]);

        // Status 120: w toku, bez zmiany; potem 200 z UPO.
        self::assertSame(0, $send->reconciler->poll());
        self::assertSame('submitted', $send->approval($approvalId)['status']);
        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($approvalId);
        self::assertSame(['accepted', '200'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($approvalId);
        $filing = $send->filing();
        self::assertSame(['accepted', 'jpk', 200], [$filing['status'], $filing['submitted_via'], $filing['status_code']]);
        self::assertNotNull($filing['accepted_at']);
        $upo = $this->db()->fetchAssociative("SELECT id, owner_table, owner_id FROM stored_file WHERE kind='upo'");
        self::assertIsArray($upo);
        self::assertSame([$refs['upo_file_id'], 'filing', $filing['id']], [$upo['id'], $upo['owner_table'], $upo['owner_id']]);
        self::assertStringContainsString(JpkSendTestTools::REFERENCE, $send->files->read($refs['upo_file_id']));
        self::assertSame(['approval.requested', 'approval.approved', 'dispatch.started', 'dispatch.submitted', 'dispatch.accepted'], $send->auditActions($approvalId));

        // Wysłany plik to dokładnie `xml_sent`: odszyfrowanie fikcyjnym kluczem i rozpakowanie dają te same bajty.
        $metadata = $send->fake->metadata();
        [, $xml] = JpkSendTestTools::unzip(JpkSendTestTools::decrypt($send->fake->puts(), self::text($metadata, 'EncryptionKey'), self::text($metadata, 'IV')));
        self::assertSame($payloadSha, hash('sha256', $xml));
        self::assertSame(base64_encode(hash('sha256', $send->payload($approvalId), true)), self::text($metadata, 'HashValue'));
        // `AuthData` w metadanych tylko zaszyfrowane kluczem pliku: po odszyfrowaniu to `DaneAutoryzujace`.
        $authData = JpkSendTestTools::decrypt([(string) base64_decode(self::text($metadata, 'AuthData'), true)], self::text($metadata, 'EncryptionKey'), self::text($metadata, 'IV'));
        self::assertStringContainsString('DaneAutoryzujace', $authData);
        self::assertStringNotContainsString('DaneAutoryzujace', $metadata);

        $calls = $this->db()->fetchAllAssociative("SELECT operation, http_method, host, path, http_status, approval_id, correlation_id, request_sha256 FROM connector_call WHERE connector='jpk' ORDER BY sequence");
        self::assertSame(['init_upload_signed', 'put_blob', 'finish_upload', 'status', 'status'], array_column($calls, 'operation'));
        foreach ($calls as $call) {
            self::assertSame([$approvalId, $refs['correlation_id']], [$call['approval_id'], $call['correlation_id']]);
            if ($call['http_method'] !== 'GET') {
                self::assertIsString($call['request_sha256']);
            }
        }
        self::assertSame(hash('sha256', $metadata), $calls[0]['request_sha256']);
        self::assertSame(hash('sha256', $send->fake->puts()[0]), $calls[1]['request_sha256']);
        self::assertSame(0, $send->fake->recordings->remaining());

        EvidenceArtefacts::write('db/jpk-send-accepted-filing.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT id, type, period_start, status, reference_number, status_code, submitted_via, accepted_at FROM filing WHERE id=?', [$filing['id']])));
        EvidenceArtefacts::write('db/jpk-send-accepted-connector_call.csv', EvidenceArtefacts::csv($calls));
    }

    private static function text(string $xml, string $name): string
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml, LIBXML_NONET));
        $node = $dom->getElementsByTagNameNS('*', $name)->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);
        return $node->textContent;
    }
}
