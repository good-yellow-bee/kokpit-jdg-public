<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\CorrectionTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\KsefSendTestTools;

/**
 * Kryterium `ksef-send-accepted` (F3.3): zatwierdzona faktura `pl_b2b` idzie przez `OfficialWritesHandler` i `KsefWriter`
 * na `FakeKsefGateway` z fikcyjnym nagraniem: sesja, wysyłka, status 200, zamknięcie, UPO. Nic nie wychodzi do sieci.
 */
final class KsefSendAcceptedTest extends KsefSendTestCase
{
    public function testApprovedInvoiceIsSubmittedThenAcceptedWithNumberAndUpo(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/status-available.json', 'Ksef/send-accepted.json');
        $payloadSha = $send->approval($approvalId)['payload_sha256'];
        self::assertIsString($payloadSha);

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        $submitted = $send->refs($approvalId);
        self::assertSame(['correlation_id', 'invoice_reference', 'ksef_session_id', 'sent_at', 'session_reference'], self::sortedKeys($submitted));
        self::assertSame([KsefSendTestTools::SESSION, KsefSendTestTools::INVOICE], [$submitted['session_reference'], $submitted['invoice_reference']]);
        $invoice = $send->invoice($id);
        self::assertSame('submitted', $invoice['status']);
        self::assertNotNull($invoice['sent_at']);
        self::assertSame($submitted['ksef_session_id'], $invoice['ksef_session_id']);

        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($approvalId);
        self::assertSame(['accepted', '200'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($approvalId);
        self::assertSame(CorrectionTestTools::KSEF_NUMBER, $refs['ksef_number']);
        self::assertSame($payloadSha, bin2hex((string) base64_decode($refs['invoice_hash'], true)));
        self::assertSame(['approval.requested', 'approval.approved', 'dispatch.started', 'dispatch.submitted', 'dispatch.accepted'], $send->audit($approvalId));

        $invoice = $send->invoice($id);
        self::assertSame(['accepted', CorrectionTestTools::KSEF_NUMBER, 'online'], [$invoice['status'], $invoice['ksef_number'], $invoice['ksef_issue_mode']]);
        self::assertNotNull($invoice['sent_at']);
        self::assertSame('2026-11-16 09:00:07+00', $invoice['ksef_number_assigned_at']);

        $upo = $this->db()->fetchAssociative("SELECT id, owner_table, owner_id FROM stored_file WHERE kind='upo'");
        self::assertIsArray($upo);
        self::assertSame([$refs['upo_file_id'], 'ksef_session', $refs['ksef_session_id']], [$upo['id'], $upo['owner_table'], $upo['owner_id']]);
        self::assertStringContainsString(CorrectionTestTools::KSEF_NUMBER, $send->sales->files->read($refs['upo_file_id']));
        $session = $this->db()->fetchAssociative('SELECT reference_number, form_code, closed_at FROM ksef_session WHERE id=?', [$refs['ksef_session_id']]);
        self::assertIsArray($session);
        self::assertSame([KsefSendTestTools::SESSION, 'FA(3) 1-0E'], [$session['reference_number'], $session['form_code']]);
        self::assertNotNull($session['closed_at']);

        // Wysłane bajty to dokładnie `xml_sent`: sha256 równe `payload_sha256`, także `invoiceHash` w żądaniu.
        self::assertCount(1, $send->gateway->sent);
        self::assertSame($payloadSha, hash('sha256', $send->gateway->sent[0]));
        $request = array_values(array_filter($send->recordings->requests, static fn(array $request): bool => str_ends_with($request['path'], '/invoices') && $request['method'] === 'POST'));
        self::assertCount(1, $request);
        $body = json_decode($request[0]['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['invoiceHash']);
        self::assertSame($payloadSha, bin2hex((string) base64_decode($body['invoiceHash'], true)));

        $calls = $this->db()->fetchAllAssociative("SELECT connector, operation, http_method, path, http_status, approval_id, correlation_id, request_sha256, request_bytes FROM connector_call WHERE connector IN ('ksef','latarnia') ORDER BY sequence");
        self::assertSame(['status', 'session_open', 'invoice_send', 'invoice_status', 'session_close', 'invoice_upo'], array_column($calls, 'operation'));
        foreach ($calls as $call) {
            if ($call['connector'] === 'ksef') {
                self::assertSame([$approvalId, $refs['correlation_id']], [$call['approval_id'], $call['correlation_id']]);
            }
        }
        self::assertSame(hash('sha256', $request[0]['body']), $calls[2]['request_sha256']);
        self::assertSame(0, $send->recordings->remaining());

        EvidenceArtefacts::write('db/ksef-send-accepted-approval.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT id, status, result_code, payload_sha256, dispatching_at, submitted_at, finished_at, external_refs FROM approval WHERE id=?', [$approvalId])));
        EvidenceArtefacts::write('db/ksef-send-accepted-connector_call.csv', EvidenceArtefacts::csv($calls));
        EvidenceArtefacts::write('cmd/ksef-send-accepted.txt', sprintf("xml_sent sha256:      %s\npayload_sha256:       %s\ninvoiceHash (base64): %s\nnumer KSeF:           %s\n", hash('sha256', $send->payload($approvalId)), $payloadSha, $refs['invoice_hash'], $refs['ksef_number']));
    }

    /**
     * @param array<string,string> $refs
     * @return list<string>
     */
    private static function sortedKeys(array $refs): array
    {
        $keys = array_keys($refs);
        sort($keys);
        return $keys;
    }
}
