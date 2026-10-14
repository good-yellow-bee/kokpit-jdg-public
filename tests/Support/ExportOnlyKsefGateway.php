<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Connector\Ksef\KsefCall;
use App\Connector\Ksef\KsefInvoiceStatus;
use App\Connector\Ksef\KsefSession;

/** Bramka testowa tylko z eksportem (F1): metody sesji i wysyłki KSeF kończą test błędem. */
trait ExportOnlyKsefGateway
{
    public function supportsWrites(): bool
    {
        return false;
    }

    public function openSession(KsefCall $call, array $formCode): KsefSession
    {
        throw new \LogicException('Bramka eksportu nie otwiera sesji.');
    }

    public function sendInvoice(KsefCall $call, string $sessionReference, string $bytes): string
    {
        throw new \LogicException('Bramka eksportu nie wysyła faktur.');
    }

    public function invoiceStatus(KsefCall $call, string $sessionReference, string $invoiceReference): KsefInvoiceStatus
    {
        throw new \LogicException('Bramka eksportu nie czyta statusów.');
    }

    public function closeSession(KsefCall $call, string $sessionReference): void
    {
        throw new \LogicException('Bramka eksportu nie zamyka sesji.');
    }

    public function invoiceUpo(KsefCall $call, string $sessionReference, string $invoiceReference): string
    {
        throw new \LogicException('Bramka eksportu nie pobiera UPO.');
    }

    public function sessions(KsefCall $call, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        throw new \LogicException('Bramka eksportu nie listuje sesji.');
    }

    public function sessionInvoices(KsefCall $call, string $sessionReference): array
    {
        throw new \LogicException('Bramka eksportu nie listuje faktur sesji.');
    }

    public function failedInvoices(KsefCall $call, string $sessionReference): array
    {
        throw new \LogicException('Bramka eksportu nie listuje faktur błędnych.');
    }
}
