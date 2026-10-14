<?php

declare(strict_types=1);

namespace App\Approval;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Odczyty statusu i rekoncyliacji jednego konektora; nigdy nie wysyła ponownie (docs/approval-gate.md, „Statusy i rekoncyliacja”). */
#[AutoconfigureTag('app.status_reader')]
interface StatusReader
{
    public function connector(): string;

    /**
     * Status zatwierdzenia `submitted`: `accepted`, `rejected` albo `pending`.
     *
     * @param array<string,string> $externalRefs
     */
    public function status(string $approvalId, array $externalRefs, string $sha256): SubmissionResult;

    /**
     * Rekoncyliacja `unknown` po referencjach i sha256: `accepted`, `rejected` albo `pending` (wynik nieustalony).
     *
     * @param array<string,string> $externalRefs
     */
    public function reconcile(string $approvalId, array $externalRefs, string $sha256): SubmissionResult;

    /**
     * Czy ręczne ponowienie jest dozwolone: nic nie dotarło albo duplikat chroni przed drugim dokumentem.
     *
     * @param array<string,string> $externalRefs
     */
    public function retryAllowed(string $approvalId, array $externalRefs): bool;
}
