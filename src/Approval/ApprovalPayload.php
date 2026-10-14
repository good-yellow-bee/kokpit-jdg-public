<?php

declare(strict_types=1);

namespace App\Approval;

/** Payload prośby: dokładne bajty do wysłania i to, co pokazuje podgląd (docs/approval-gate.md, „Prośba o zatwierdzenie”). */
final readonly class ApprovalPayload
{
    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $preconditions
     */
    public function __construct(public string $bytes, public string $mediaType, public int $sourceVersion, public array $summary, public array $preconditions) {}
}
