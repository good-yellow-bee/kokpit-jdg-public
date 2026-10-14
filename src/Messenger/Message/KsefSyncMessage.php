<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadania `ksef.sync` i `ksef.backfill` na `reads`; niesie identyfikator zadania i rolę (`subject1`, `subject2`). */
final readonly class KsefSyncMessage
{
    public function __construct(public string $id, public string $stream) {}
}
