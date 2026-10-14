<?php

declare(strict_types=1);

namespace App\Rate;

final class RateLimitExceededException extends \RuntimeException
{
    public function __construct(public readonly \DateTimeImmutable $blockedUntil)
    {
        parent::__construct('rate_limited');
    }
}
