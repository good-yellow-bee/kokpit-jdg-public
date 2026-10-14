<?php

declare(strict_types=1);

namespace App\Approval;

/**
 * Znacznik wysyłki w toku. `OfficialWritesHandler` ustawia go na czas jednego wywołania `submit`, a konektor
 * zapisujący odmawia bez niego, więc metoda zapisująca wołana spoza workera bramki rzuca wyjątek przed żądaniem.
 */
final class DispatchGuard
{
    private ?string $approvalId = null;

    public function enter(string $approvalId): void
    {
        if ($this->approvalId !== null) {
            throw new \LogicException('Wysyłka już trwa.');
        }
        $this->approvalId = $approvalId;
    }

    public function leave(): void
    {
        $this->approvalId = null;
    }

    public function assertDispatching(string $approvalId): void
    {
        if ($this->approvalId === null || $this->approvalId !== $approvalId) {
            throw new GateBypassException('Zapis urzędowy wyłącznie z workera bramki (official_writes).');
        }
    }
}
