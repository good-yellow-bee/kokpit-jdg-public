<?php

declare(strict_types=1);

namespace App\Connector\EDeklaracje;

/** Odpowiedź `sendUnsignDocument`: numer referencyjny (`refId`), status i opis statusu. */
final readonly class EDeklaracjeReceipt
{
    public function __construct(public ?string $refId, public int $status, public string $description) {}
}
