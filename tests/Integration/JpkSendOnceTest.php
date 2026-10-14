<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\InvalidTransitionException;
use App\Messenger\Message\ApprovalDispatchMessage;

/**
 * Kryterium `jpk-send-once` (F5.1): drugie kliknięcie „Zatwierdź”, dwa przebiegi workera i duplikat wiadomości
 * `official_writes` dają jedno `InitUploadSigned`. Nagranie ma jedną sesję, więc drugie żądanie zapisu skończyłoby
 * test błędem.
 */
final class JpkSendOnceTest extends JpkSendTestCase
{
    public function testDoubleClickTwoWorkersAndDuplicateMessageSendOnce(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        try {
            $send->gate->approve($approvalId, $send->userId);
            self::fail('Drugie kliknięcie przeszło.');
        } catch (InvalidTransitionException | \DomainException) {
        }
        self::assertCount(1, array_filter($send->bus->messages, static fn(object $message): bool => $message instanceof ApprovalDispatchMessage));
        $send->loadUpload();

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertNull($send->handler->dispatch($approvalId));
        $send->handler->onDispatch(new ApprovalDispatchMessage($approvalId));
        $send->handler->onDispatch(new ApprovalDispatchMessage($approvalId));

        self::assertSame(1, $send->calls('init_upload_signed'));
        self::assertSame(1, $send->fake->requests('/InitUploadSigned'));
        self::assertSame(1, $send->calls('finish_upload'));
        self::assertCount(1, $send->fake->puts());
        self::assertSame('submitted', $send->approval($approvalId)['status']);
    }
}
