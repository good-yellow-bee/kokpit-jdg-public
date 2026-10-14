<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

/** Odpowiedź `Status/{referenceNumber}`: kod, opis, UPO przy 200 i numer oryginału przy duplikacie (407). */
final readonly class JpkStatus
{
    public function __construct(public int $code, public string $description, public ?string $upo, public ?string $originalReference) {}

    public function inProgress(): bool
    {
        return in_array($this->code, JpkError::IN_PROGRESS, true);
    }
}
