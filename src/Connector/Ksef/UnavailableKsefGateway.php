<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/**
 * Bramka bez połączenia: każde wywołanie kończy się `GatewayUnavailable`. Implementacja nad `n1ebieski/ksef-php-client`
 * powstaje razem z nagraniami z api-test (D-42); do tego czasu `ksef.sync` i `ksef.backfill` nie są w harmonogramie.
 */
final readonly class UnavailableKsefGateway implements KsefGateway
{
    public function startExport(string $stream, \DateTimeImmutable $from, ?\DateTimeImmutable $to): string
    {
        throw new KsefException(KsefError::GatewayUnavailable);
    }

    public function exportPackage(string $referenceNumber): ?KsefExportPackage
    {
        throw new KsefException(KsefError::GatewayUnavailable);
    }
}
