<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class HeartbeatStore
{
    private const KEY = 'ops.last_heartbeat';

    public function __construct(private CacheInterface $schedulerState) {}

    public function record(\DateTimeInterface $now): void
    {
        $timestamp = $now->getTimestamp();
        $this->schedulerState->delete(self::KEY);
        $this->schedulerState->get(self::KEY, static function (ItemInterface $item) use ($timestamp): int {
            $item->expiresAfter(86400);

            return $timestamp;
        });
    }

    public function isFresh(\DateTimeInterface $now, int $maxAgeSeconds = 600): bool
    {
        /** @var mixed $timestamp */
        $timestamp = $this->schedulerState->get(self::KEY, static fn(ItemInterface $item): string => '0');

        if (!is_int($timestamp) && !(is_string($timestamp) && ctype_digit($timestamp))) {
            return false;
        }

        return (int) $timestamp >= $now->getTimestamp() - $maxAgeSeconds;
    }
}
