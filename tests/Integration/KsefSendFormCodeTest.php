<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * Kryterium `ksef-send-form-code` (F3.3): sesja otwarta z `formCode` FA (2), a faktura ma `schema_version` FA(3) 1-0E.
 * Worker zamyka sesję bez wysyłki faktury; zatwierdzenie nie dochodzi do `submitted` (`rejected` z
 * `form_code_mismatch`, nic nie wyszło, D-54).
 */
final class KsefSendFormCodeTest extends KsefSendTestCase
{
    public function testMismatchedSessionFormCodeClosesSessionWithoutInvoice(): void
    {
        $send = $this->send(formCode: ['system_code' => 'FA (2)', 'schema_version' => '1-0E', 'value' => 'FA']);
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/status-available.json', 'Ksef/send-form-code.json');

        self::assertSame('rejected', $send->handler->dispatch($approvalId));
        $row = $send->approval($approvalId);
        self::assertSame(['rejected', 'form_code_mismatch'], [$row['status'], $row['result_code']]);
        self::assertNull($row['submitted_at']);
        self::assertNotContains('dispatch.submitted', $send->audit($approvalId));
        self::assertSame(0, $send->calls('invoice_send'));
        self::assertSame([], $send->gateway->sent);
        $session = $this->db()->fetchAssociative('SELECT form_code, closed_at FROM ksef_session');
        self::assertIsArray($session);
        self::assertSame('FA(2) 1-0E', $session['form_code']);
        self::assertNotSame($send->invoice($id)['schema_version'], $session['form_code']);
        self::assertNotNull($session['closed_at']);
        self::assertSame(1, $send->calls('session_close'));
        self::assertSame(0, $send->recordings->remaining());
    }
}
