<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/** Odpowiedź `POST /sessions/online`: numer referencyjny sesji i koniec jej życia (`validUntil`). */
final readonly class KsefSession
{
    public function __construct(public string $referenceNumber, public \DateTimeImmutable $validUntil) {}
}
