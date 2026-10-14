<?php

declare(strict_types=1);

namespace App\Approval;

/** Wysyłka jednego zatwierdzenia w stanie `dispatching`; `record` zapisuje `external_refs` po każdym kroku API. */
final readonly class DispatchRequest
{
    /** @param \Closure(array<string,string>):void $record */
    public function __construct(public string $approvalId, public string $subjectType, public string $subjectId, public string $environment, public string $bytes, public string $sha256, private \Closure $record) {}

    /** @param array<string,string> $refs */
    public function record(array $refs): void
    {
        ($this->record)($refs);
    }
}
