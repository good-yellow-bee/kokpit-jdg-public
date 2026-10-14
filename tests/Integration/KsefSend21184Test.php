<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;

/**
 * Kryterium `ksef-send-21184` (F3.3, API 2.8.0): 21184 „Sesja tymczasowo niedostępna” przy wysyłce w pierwszej sesji.
 * Worker zamyka sesję i otwiera nową z tymi samymi bajtami; przyjęta jest jedna faktura.
 */
final class KsefSend21184Test extends KsefSendTestCase
{
    public function testSessionUnavailableClosesAndReopensWithSamePayload(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/status-available.json', 'Ksef/send-21184.json');

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame('accepted', $send->approval($approvalId)['status']);
        self::assertSame('accepted', $send->invoice($id)['status']);

        $sessions = $this->db()->fetchAllAssociative('SELECT reference_number, form_code, status_code, opened_at, closed_at FROM ksef_session ORDER BY opened_at, reference_number');
        self::assertCount(2, $sessions);
        self::assertSame(21184, $sessions[0]['status_code']);
        self::assertNull($sessions[1]['status_code']);
        self::assertNotNull($sessions[0]['closed_at']);
        self::assertNotNull($sessions[1]['closed_at']);
        self::assertSame($sessions[1]['reference_number'], $send->refs($approvalId)['session_reference']);
        // Te same bajty w obu sesjach, a sha256 równe `payload_sha256`.
        self::assertCount(2, $send->gateway->sent);
        self::assertSame($send->gateway->sent[0], $send->gateway->sent[1]);
        self::assertSame($send->approval($approvalId)['payload_sha256'], hash('sha256', $send->gateway->sent[1]));
        self::assertSame(2, $send->calls('session_open'));
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM invoice WHERE ksef_number IS NOT NULL"));
        self::assertSame(0, $send->recordings->remaining());
        EvidenceArtefacts::write('db/ksef-send-21184-ksef_session.csv', EvidenceArtefacts::csv($sessions));
    }
}
