<?php

declare(strict_types=1);

namespace App\Connector\Latarnia;

/**
 * Status dostępności KSeF z Latarni (`/status`) i tytuł aktywnego komunikatu. Status inny niż `AVAILABLE` wstrzymuje
 * wysyłki KSeF (docs/modules/ksef.md, „Awarie KSeF i Latarnia”).
 */
final readonly class LatarniaStatus
{
    public const AVAILABLE = 'AVAILABLE';
    public const STATUSES = [self::AVAILABLE, 'MAINTENANCE', 'FAILURE', 'TOTAL_FAILURE'];

    public function __construct(public string $status, public ?string $message, public \DateTimeImmutable $checkedAt)
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \UnexpectedValueException('Nieznany status Latarni.');
        }
    }

    public function available(): bool
    {
        return $this->status === self::AVAILABLE;
    }
}
