<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Messenger\Message\ApprovalDispatchMessage;
use App\Messenger\Message\ApprovalDispatchWaitingMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

/**
 * `messenger:failed:retry` nie ponawia zapisów urzędowych (docs/operations.md): wiadomość z `official_writes`
 * odebrana z innego transportu, np. `failed`, kończy się bez wywołania handlera. Ponowienie to tylko nowe
 * zatwierdzenie z panelu.
 */
final readonly class OfficialWritesRetryGuard implements MiddlewareInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class)?->getTransportName();
        if ($received === null) {
            return $stack->next()->handle($envelope, $stack);
        }
        // `FailedMessageProcessingMiddleware` podmienia `ReceivedStamp` na transport pierwotny, więc ponowienie z `failed`
        // rozpoznaje znacznik `SentToFailureTransportStamp`, a nie nazwa transportu.
        $replayed = $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName() === 'official_writes';
        $official = $envelope->getMessage() instanceof ApprovalDispatchMessage || $envelope->getMessage() instanceof ApprovalDispatchWaitingMessage;
        if ($replayed || ($official && $received !== 'official_writes')) {
            $this->logger->warning('Odrzucono ponowienie zapisu urzędowego spoza official_writes.', ['transport' => $received]);
            return $envelope;
        }
        return $stack->next()->handle($envelope, $stack);
    }
}
