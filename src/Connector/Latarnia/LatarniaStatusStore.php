<?php

declare(strict_types=1);

namespace App\Connector\Latarnia;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Ostatni status Latarni dla panelu (zadanie `latarnia.status` i sprawdzenie przed wysyłką), w puli `cache.scheduler`. */
final readonly class LatarniaStatusStore
{
    private const KEY = 'ksef.latarnia_status';

    public function __construct(private CacheInterface $schedulerState) {}

    public function record(LatarniaStatus $status): void
    {
        $value = ['status' => $status->status, 'message' => $status->message, 'checked_at' => $status->checkedAt->format(DATE_ATOM)];
        $this->schedulerState->delete(self::KEY);
        $this->schedulerState->get(self::KEY, static function (ItemInterface $item) use ($value): array {
            $item->expiresAfter(86400);
            return $value;
        });
    }

    /** @return array{status:string,message:?string,checked_at:string}|null */
    public function last(): ?array
    {
        /** @var mixed $value */
        $value = $this->schedulerState->get(self::KEY, static function (ItemInterface $item): ?array {
            $item->expiresAfter(1);
            return null;
        });
        if (!is_array($value) || !is_string($value['status'] ?? null) || !is_string($value['checked_at'] ?? null)) {
            return null;
        }
        return ['status' => $value['status'], 'message' => is_string($value['message'] ?? null) ? $value['message'] : null, 'checked_at' => $value['checked_at']];
    }
}
