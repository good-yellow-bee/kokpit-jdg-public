<?php

declare(strict_types=1);

namespace App\Connector\Dummy;

/**
 * Stan encji zastępczych `dummy` do F3 (docs/phases/f0b-bramka-kalendarz.md, „`dummy` jako przedmiot”):
 * `approval.subject_id` nie ma klucza obcego, więc wersję źródła, scenariusz wysyłki i stan encji trzyma ta usługa.
 * Tylko `when@dev` i `when@test`; dane wyłącznie fikcyjne.
 */
final class DummySubjects
{
    public const SCENARIOS = ['accept', 'reject', 'duplicate', 'timeout', 'poll_reject', 'poll_pending', 'rate_limited'];

    /** @var array<string,int> */
    private array $versions = [];
    /** @var array<string,string> */
    private array $scenarios = [];
    /** @var array<string,string> */
    private array $states = [];
    /** Flaga „konektor włączony” atrapy; przypadek wyłączonego konektora testuje `approval.dispatch_waiting`. */
    public bool $writeEnabled = true;
    /** Wynik rekoncyliacji `unknown` odczytem: `accepted`, `rejected` albo `pending` (nieustalony). */
    public string $reconcileOutcome = 'accepted';
    /** Obserwator testów: wołany na początku `submit`, gdy wiersz jest w `dispatching`. @var (\Closure(string):void)|null */
    public ?\Closure $onSubmit = null;

    public function version(string $subjectId): int
    {
        return $this->versions[$subjectId] ?? 1;
    }

    /** Zmiana źródła encji: nowa wersja. */
    public function touch(string $subjectId): void
    {
        $this->versions[$subjectId] = $this->version($subjectId) + 1;
    }

    public function scenario(string $subjectId): string
    {
        return $this->scenarios[$subjectId] ?? 'accept';
    }

    public function setScenario(string $subjectId, string $scenario): void
    {
        if (!in_array($scenario, self::SCENARIOS, true)) {
            throw new \InvalidArgumentException('Nieznany scenariusz atrapy.');
        }
        $this->scenarios[$subjectId] = $scenario;
    }

    public function state(string $subjectId): ?string
    {
        return $this->states[$subjectId] ?? null;
    }

    public function setState(string $subjectId, string $state): void
    {
        $this->states[$subjectId] = $state;
    }
}
