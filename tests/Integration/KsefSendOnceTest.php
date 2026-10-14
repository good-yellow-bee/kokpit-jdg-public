<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\InvalidTransitionException;
use App\Messenger\Message\ApprovalDispatchMessage;

/**
 * Kryterium `ksef-send-once` (F3.3): drugie kliknięcie „Zatwierdź”, dwa przebiegi workera i duplikat wiadomości
 * `official_writes` dają jedno otwarcie sesji i jedną wysyłkę faktury. Nagranie ma tylko jedną sesję, więc drugie
 * żądanie zapisu skończyłoby test błędem.
 */
final class KsefSendOnceTest extends KsefSendTestCase
{
    public function testDoubleClickTwoWorkersAndDuplicateMessageSendOnce(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        try {
            $send->sales->gate->approve($approvalId, $send->sales->userId);
            self::fail('Drugie kliknięcie przeszło.');
        } catch (InvalidTransitionException | \DomainException) {
        }
        self::assertCount(1, array_filter($send->sales->bus->messages, static fn(object $message): bool => $message instanceof ApprovalDispatchMessage));
        $send->load('Latarnia/status-available.json', 'Ksef/send-accepted.json');

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        // Drugi worker i duplikat wiadomości: stan inny niż `approved` kończy handler bez skutku.
        self::assertNull($send->handler->dispatch($approvalId));
        $send->handler->onDispatch(new ApprovalDispatchMessage($approvalId));
        $send->handler->onDispatch(new ApprovalDispatchMessage($approvalId));

        self::assertSame(1, $send->calls('session_open'));
        self::assertSame(1, $send->calls('invoice_send'));
        self::assertCount(1, $send->gateway->sent);
        self::assertSame('submitted', $send->approval($approvalId)['status']);
    }
}
