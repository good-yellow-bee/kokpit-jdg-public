<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Rate\RateLimitExceededException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final readonly class RateLimitDeferralMiddleware implements MiddlewareInterface
{
    public function __construct(private SenderInterface $reads, private SenderInterface $notifications, private ClockInterface $clock) {}
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException|RateLimitExceededException $failure) {
            $exception = $failure instanceof HandlerFailedException ? $failure->getPrevious() : $failure;
            $transport = $envelope->last(ReceivedStamp::class)?->getTransportName();
            if (!$exception instanceof RateLimitExceededException || !in_array($transport, ['reads','notifications'], true)) {
                throw $failure;
            }
            $delay = max(1, $exception->blockedUntil->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000;
            ($transport === 'reads' ? $this->reads : $this->notifications)->send($envelope->withoutAll(ReceivedStamp::class)->with(new DelayStamp($delay)));
            return $envelope;
        }
    }
}
