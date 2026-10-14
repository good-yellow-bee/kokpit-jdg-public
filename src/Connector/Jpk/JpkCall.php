<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

use App\Approval\DispatchGuard;

/**
 * Kontekst jednego wywołania API JPK: `approval_id`, `correlation_id` i `environment` w `connector_call`. Metody
 * zapisujące bramki (`InitUploadSigned`, Put Blob, `FinishUpload`) przyjmują tylko `dispatch`, który wymaga trwającej
 * wysyłki w `DispatchGuard` (worker `official_writes`). `Status` działa na `read`.
 */
final readonly class JpkCall
{
    private function __construct(public string $approvalId, public string $correlationId, public string $environment, public bool $dispatching) {}

    /** Wywołanie z `JpkWriter::submit`; poza workerem bramki rzuca `GateBypassException`. */
    public static function dispatch(DispatchGuard $guard, string $approvalId, string $correlationId, string $environment): self
    {
        $guard->assertDispatching($approvalId);
        return new self($approvalId, $correlationId, $environment, true);
    }

    /** Odczyt statusu i rekoncyliacji; bez prawa do wysyłki. */
    public static function read(string $approvalId, string $correlationId, string $environment): self
    {
        return new self($approvalId, $correlationId, $environment, false);
    }
}
