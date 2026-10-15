<?php

declare(strict_types=1);

namespace App\Connector\EDeklaracje;

use App\Approval\DispatchGuard;

/**
 * Kontekst jednego wywołania bramki e-Deklaracji: `approval_id`, `correlation_id` i `environment` w `connector_call`.
 * `sendUnsignDocument` przyjmuje tylko `dispatch`, który wymaga trwającej wysyłki w `DispatchGuard` (worker
 * `official_writes`). `requestUPO` działa na `read`.
 */
final readonly class EDeklaracjeCall
{
    private function __construct(public string $approvalId, public string $correlationId, public string $environment, public bool $dispatching) {}

    /** Wywołanie z `EDeklaracjeWriter::submit`; poza workerem bramki rzuca `GateBypassException`. */
    public static function dispatch(DispatchGuard $guard, string $approvalId, string $correlationId, string $environment): self
    {
        $guard->assertDispatching($approvalId);
        return new self($approvalId, $correlationId, $environment, true);
    }

    /** Odczyt UPO i rekoncyliacja; bez prawa do wysyłki. */
    public static function read(string $approvalId, string $correlationId, string $environment): self
    {
        return new self($approvalId, $correlationId, $environment, false);
    }
}
