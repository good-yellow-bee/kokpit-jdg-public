<?php

declare(strict_types=1);

namespace App\Approval;

use App\Architecture\OfficialWrite;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Metoda zapisująca konektora. Woła ją tylko `OfficialWritesHandler` (reguła PHPStan `architecture.officialWrite`
 * i `DispatchGuard` w czasie działania); odczyt statusu jest w `StatusReader`.
 */
#[AutoconfigureTag('app.official_writer')]
interface OfficialWriter
{
    public function connector(): string;

    /**
     * Warunki z kolumną „wysyłka” o charakterze przejściowym: cel i włączenie konektora, `blocked_until`, limit.
     * Zwraca powód czekania albo NULL, gdy wysyłka może ruszyć; nic nie wysyła.
     */
    public function waitingReason(string $environment): ?string;

    #[OfficialWrite]
    public function submit(DispatchRequest $request): SubmissionResult;
}
