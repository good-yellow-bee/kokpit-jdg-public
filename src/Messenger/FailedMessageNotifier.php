<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Messenger\Message\FailureNotificationMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final readonly class FailedMessageNotifier
{
    public function __construct(private SenderInterface $failed, private MessageBusInterface $bus) {}
    // Retry listener runs first. Marking the envelope prevents Symfony sending it twice.
    #[AsEventListener(priority: -90)]
    public function onFailure(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry() || $event->getEnvelope()->last(SentToFailureTransportStamp::class)) {
            return;
        }
        $envelope = $event->getEnvelope()->with(new SentToFailureTransportStamp($event->getReceiverName()), new DelayStamp(0));
        $sent = $this->failed->send($envelope);
        $event->addStamps(new SentToFailureTransportStamp($event->getReceiverName()));
        $id = $sent->last(TransportMessageIdStamp::class)?->getId();
        if (!is_int($id) && !is_string($id)) {
            throw new \LogicException('Brak identyfikatora nieudanej wiadomości.');
        }
        if (!$envelope->getMessage() instanceof FailureNotificationMessage) {
            $this->bus->dispatch(new FailureNotificationMessage((string) $id));
        }
    }
}
