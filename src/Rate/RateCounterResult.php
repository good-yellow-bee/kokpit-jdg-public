<?php

declare(strict_types=1);

namespace App\Rate;

final readonly class RateCounterResult
{
    public function __construct(public int $count, public ?\DateTimeImmutable $blockedUntil) {}

    public function isBlocked(\DateTimeImmutable $now): bool
    {
        return $this->blockedUntil !== null && $this->blockedUntil > $now;
    }
}
