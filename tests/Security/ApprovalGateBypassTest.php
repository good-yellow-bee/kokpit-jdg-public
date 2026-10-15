<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Approval\DispatchRequest;
use App\Approval\GateBypassException;
use App\Connector\EDeklaracje\EDeklaracjeCall;
use App\Messenger\Message\ApprovalDispatchMessage;
use App\Tests\Integration\DatabaseTestCase;
use App\Security\SecretStore;
use App\Tests\Support\ApprovalTestTools;
use App\Tests\Support\JpkSendTestTools;
use Symfony\Component\Clock\MockClock;

/**
 * `gate-bypass`: metoda zapisująca `dummy` poza workerem bramki i wiadomość dla stanu innego niż `approved`; F5.5 dokłada
 * `edeklaracje`: `EDeklaracjeWriter::submit` i `sendUnsignDocument` poza workerem rzucają przed żądaniem (D-66).
 */
final class ApprovalGateBypassTest extends DatabaseTestCase
{
    public function testWriteOutsideGateWorkerThrowsBeforeRequest(): void
    {
        $tools = new ApprovalTestTools($this->db(), new MockClock('2026-10-12T08:00:00Z'));
        $id = $tools->approved();
        $bytes = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<DummyFiling fictional=\"true\" scenario=\"accept\"/>\n";
        $request = new DispatchRequest($id, 'filing', '019a0000-0000-7000-8000-000000000001', 'test', $bytes, hash('sha256', $bytes), static function (array $refs): void {});
        try {
            $tools->writer->submit($request); // @phpstan-ignore architecture.officialWrite (test celowo omija bramkę)
            self::fail('Zapis poza bramką przeszedł.');
        } catch (GateBypassException) {
        }
        // Znacznik innej wysyłki też nie otwiera zapisu dla tego zatwierdzenia.
        $tools->guard->enter('019a0000-0000-7000-8000-000000000002');
        try {
            $tools->writer->submit($request); // @phpstan-ignore architecture.officialWrite (test celowo omija bramkę)
            self::fail('Zapis z cudzym znacznikiem przeszedł.');
        } catch (GateBypassException) {
        } finally {
            $tools->guard->leave();
        }
        self::assertSame(0, $tools->writes());
        self::assertSame('approved', $tools->status($id));
    }

    public function testOfficialWritesMessageForOtherStatesHasNoEffect(): void
    {
        $tools = new ApprovalTestTools($this->db(), new MockClock('2026-10-12T08:00:00Z'));
        $pending = $tools->request();
        $cancelled = $tools->request();
        $tools->gate->cancel($cancelled, $tools->userId);
        $submitted = $tools->approved('filing', 'poll_pending');
        $tools->handler->dispatch($submitted);
        self::assertSame(1, $tools->writes());
        $audit = $tools->auditCount();
        foreach (['pending' => $pending, 'cancelled' => $cancelled, 'submitted' => $submitted] as $state => $id) {
            $tools->handler->onDispatch(new ApprovalDispatchMessage($id));
            self::assertSame($state, $tools->status($id));
        }
        self::assertSame(1, $tools->writes());
        self::assertSame($audit, $tools->auditCount());
    }

    public function testEDeklaracjeWriteOutsideGateWorkerThrowsBeforeRequest(): void
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $send = new JpkSendTestTools($this->db(), $secrets, '2026-12-10T09:00:00Z');
        $bytes = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Deklaracja fictional=\"true\"/>\n";
        $approvalId = '019a0000-0000-7000-8000-0000000000e1';
        $request = new DispatchRequest($approvalId, 'filing', '019a0000-0000-7000-8000-0000000000e2', 'test', $bytes, hash('sha256', $bytes), static function (array $refs): void {});
        try {
            $send->edeklaracjeWriter->submit($request); // @phpstan-ignore architecture.officialWrite (test celowo omija bramkę)
            self::fail('Zapis e-Deklaracji poza bramką przeszedł.');
        } catch (GateBypassException) {
        }
        try {
            $send->edeklaracje->gateway->sendUnsignDocument(EDeklaracjeCall::read($approvalId, '019a0000-0000-7000-8000-0000000000e3', 'test'), $bytes);
            self::fail('sendUnsignDocument z wywołaniem odczytu przeszedł.');
        } catch (GateBypassException) {
        }
        self::assertSame([], $send->edeklaracje->recordings->requests);
        self::assertSame(0, $send->count("SELECT count(*) FROM connector_call WHERE connector='edeklaracje'"));
    }
}
