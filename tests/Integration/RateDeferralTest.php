<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Messenger\Message\ConnectorCallMessage;
use App\Messenger\RateLimitDeferralMiddleware;
use App\Rate\RateLimitExceededException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RateDeferralTest extends TestCase
{
    public function testRateCounterLimitDefersReadsWithoutFailedTransport(): void
    {
        $reads = new InMemoryTransport();
        $notifications = new InMemoryTransport();
        $clock = new MockClock('2026-10-09T10:00:00Z');
        $message = new ConnectorCallMessage('heartbeat');
        $bus = new MessageBus([new RateLimitDeferralMiddleware($reads, $notifications, $clock),new HandleMessageMiddleware(new HandlersLocator([ConnectorCallMessage::class => [static function () use ($clock): void {
            throw new RateLimitExceededException($clock->now()->modify('+2 minutes'));
        }]]))]);
        $result = $bus->dispatch(new Envelope($message, [new ReceivedStamp('reads')]));
        self::assertSame($message, $result->getMessage());
        self::assertCount(1, $reads->getSent());
        self::assertSame(120000, $reads->getSent()[0]->last(DelayStamp::class)?->getDelay());
        self::assertNull($reads->getSent()[0]->last(ReceivedStamp::class));
        self::assertSame([], $notifications->getSent());
    }
}
