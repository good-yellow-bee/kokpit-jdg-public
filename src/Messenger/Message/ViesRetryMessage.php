<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Ponowienie VIES po `unverified_retry` (docs/modules/rejestry-kursy.md, „Awarie”); pusty kontrahent to samosprawdzenie. */
final readonly class ViesRetryMessage
{
    public function __construct(public ?string $counterpartyId, public string $firstCheckedAt, public int $attempt) {}
}
