<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/**
 * Własny interfejs nad biblioteką KSeF (docs/modules/ksef.md, „Biblioteka i `KsefGateway`”). Domena zna tylko ten
 * interfejs. Implementacja idzie przez `ConnectorHttpClient`, więc 429 ustawia `rate_counter.blocked_until`
 * i kończy się `RateLimitExceededException`; odczyty nigdy nie wołają metod zapisu.
 */
interface KsefGateway
{
    /**
     * `POST /invoices/exports` z `dateType` = `PermanentStorage` i `restrictToPermanentStorageHwmDate` = `true`.
     * Bez `$to` KSeF przyjmuje bieżącą chwilę w UTC.
     *
     * @param string $stream `subject1` albo `subject2`
     * @return string `referenceNumber` eksportu
     */
    public function startExport(string $stream, \DateTimeImmutable $from, ?\DateTimeImmutable $to): string;

    /**
     * `GET /invoices/exports/{referenceNumber}`; po zbudowaniu paczki pobiera i odszyfrowuje części.
     *
     * @return ?KsefExportPackage null, dopóki KSeF buduje paczkę
     * @throws KsefException z `KsefError::InvoiceNotYetAvailable` (21165), gdy faktura nie jest jeszcze dostępna
     */
    public function exportPackage(string $referenceNumber): ?KsefExportPackage;
}
