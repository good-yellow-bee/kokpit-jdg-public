<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Approval\DispatchRequest;
use App\Approval\GateBypassException;
use App\Messenger\Message\ApprovalDispatchMessage;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ApprovalTestTools;
use Symfony\Component\Clock\MockClock;

/** `gate-bypass`: metoda zapisująca `dummy` poza workerem bramki i wiadomość dla stanu innego niż `approved`. */
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
}
