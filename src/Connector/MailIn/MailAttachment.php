<?php

declare(strict_types=1);

namespace App\Connector\MailIn;

/** Załącznik po zdekodowaniu: nazwa i typ z nagłówków nadawcy (niezaufane), bajty do sprawdzenia sygnatury pliku. */
final readonly class MailAttachment
{
    public function __construct(public ?string $filename, public string $declaredType, public string $bytes) {}
}
