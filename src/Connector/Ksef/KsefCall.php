<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

use App\Approval\DispatchGuard;

/**
 * Kontekst jednego wywołania KSeF: `approval_id` i `correlation_id` w `connector_call`. Otwarcie sesji i wysyłkę faktury
 * bramka przyjmuje tylko z `dispatch`, który wymaga trwającej wysyłki w `DispatchGuard` (worker `official_writes`).
 */
final readonly class KsefCall
{
    private function __construct(public string $approvalId, public string $correlationId, public bool $dispatching) {}

    /** Wywołanie z `OfficialWriter::submit`; poza workerem bramki rzuca `GateBypassException`. */
    public static function dispatch(DispatchGuard $guard, string $approvalId, string $correlationId): self
    {
        $guard->assertDispatching($approvalId);
        return new self($approvalId, $correlationId, true);
    }

    /** Odczyt statusu i rekoncyliacji oraz zamknięcie sesji; bez prawa do wysyłki. */
    public static function read(string $approvalId, string $correlationId): self
    {
        return new self($approvalId, $correlationId, false);
    }
}
