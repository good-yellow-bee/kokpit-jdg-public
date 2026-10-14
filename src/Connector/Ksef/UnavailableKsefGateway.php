<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/**
 * Bramka bez połączenia: każde wywołanie kończy się `GatewayUnavailable`. Implementacja nad `n1ebieski/ksef-php-client`
 * powstaje razem z nagraniami z api-test (D-42, F3.5); do tego czasu `ksef.sync` i `ksef.backfill` nie są
 * w harmonogramie, a zatwierdzenia KSeF czekają w `approved` (`supportsWrites` = `false`).
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

    public function supportsWrites(): bool
    {
        return false;
    }

    public function openSession(KsefCall $call, array $formCode): KsefSession
    {
        throw new KsefException(KsefError::GatewayUnavailable);
    }

    public function sendInvoice(KsefCall $call, string $sessionReference, string $bytes): string
    {
        throw new KsefException(KsefError::GatewayUnavailable);
    }

    public function invoiceStatus(KsefCall $call, string $sessionReference, string $invoiceReference): KsefInvoiceStatus
    {
        throw new KsefException(KsefError::GatewayUnavailable);
    }

    public function closeSession(KsefCall $call, string $sessionReference): void
    {
        throw new KsefException(KsefError::GatewayUnavailable);
    }

    public function invoiceUpo(KsefCall $call, string $sessionReference, string $invoiceReference): string
    {
        throw new KsefException(KsefError::GatewayUnavailable);
    }

    public function sessions(KsefCall $call, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        throw new KsefException(KsefError::GatewayUnavailable);
    }

    public function sessionInvoices(KsefCall $call, string $sessionReference): array
    {
        throw new KsefException(KsefError::GatewayUnavailable);
    }

    public function failedInvoices(KsefCall $call, string $sessionReference): array
    {
        throw new KsefException(KsefError::GatewayUnavailable);
    }
}
