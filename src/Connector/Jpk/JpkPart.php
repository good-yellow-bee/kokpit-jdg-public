<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

/** Zaszyfrowana część archiwum ZIP; istnieje tylko w pamięci workera, kokpit jej nie przechowuje. */
final readonly class JpkPart
{
    public function __construct(public int $ordinal, public string $fileName, public string $bytes, public string $md5Base64) {}

    public function length(): int
    {
        return strlen($this->bytes);
    }
}
