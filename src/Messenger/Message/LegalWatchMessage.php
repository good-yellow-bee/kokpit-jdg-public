<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadanie cykliczne obserwacji prawa: `legal_watch.check` (D-75). */
final readonly class LegalWatchMessage
{
    public function __construct(public string $id) {}
}
