<?php

declare(strict_types=1);

namespace App\Approval;

/** Wynik wysyłki albo odczytu statusu; `pending` znaczy „w toku”, `null` w rekoncyliacji „brak wyniku”. */
final readonly class SubmissionResult
{
    public const OUTCOMES = ['submitted', 'accepted', 'rejected', 'unknown', 'pending'];

    /** @param array<string,string> $externalRefs */
    private function __construct(public string $outcome, public array $externalRefs, public ?string $resultCode) {}

    /** @param array<string,string> $externalRefs */
    public static function of(string $outcome, array $externalRefs = [], ?string $resultCode = null): self
    {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('Niepoprawny wynik wysyłki.');
        }
        return new self($outcome, $externalRefs, $resultCode);
    }
}
