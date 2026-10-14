<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/**
 * Własny interfejs nad biblioteką KSeF (docs/modules/ksef.md, „Biblioteka i `KsefGateway`”). Domena zna tylko ten
 * interfejs. Implementacja idzie przez `ConnectorHttpClient`, więc 429 ustawia `rate_counter.blocked_until`;
 * odczyty nigdy nie wołają metod zapisu. Każde wywołanie sesji niesie `KsefCall` (`approval_id`, `correlation_id`).
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

    /** Czy bramka umie wysyłać; `false` trzyma zatwierdzenia w `approved` (powód `gateway_unavailable`). */
    public function supportsWrites(): bool;

    /**
     * `POST /sessions/online` (grupa `ksef.session_open`) z `formCode` i kluczem AES zaszyfrowanym kluczem MF.
     * Wymaga `KsefCall::dispatch`.
     *
     * @param array{system_code:string,schema_version:string,value:string} $formCode
     * @throws KsefException `RateLimited` przy 429 albo `blocked_until`, `RequestRefused` przy innej odmowie
     */
    public function openSession(KsefCall $call, array $formCode): KsefSession;

    /**
     * `POST /sessions/online/{referenceNumber}/invoices` (grupa `ksef.invoice_send`): dokładne bajty `xml_sent`
     * zaszyfrowane kluczem sesji, `invoiceHash` = SHA-256 bajtów w Base64. Wymaga `KsefCall::dispatch`.
     *
     * @return string `referenceNumber` faktury
     * @throws KsefException `SessionUnavailable` (21184), `RateLimited`, `RequestRefused`
     */
    public function sendInvoice(KsefCall $call, string $sessionReference, string $bytes): string;

    /** `GET /sessions/{referenceNumber}/invoices/{invoiceReferenceNumber}` (grupa `ksef.invoice_status`). */
    public function invoiceStatus(KsefCall $call, string $sessionReference, string $invoiceReference): KsefInvoiceStatus;

    /** `POST /sessions/online/{referenceNumber}/close` (grupa `ksef.session_close`). */
    public function closeSession(KsefCall $call, string $sessionReference): void;

    /** `GET /sessions/{referenceNumber}/invoices/{invoiceReferenceNumber}/upo` (grupa `ksef.session_other`): bajty UPO. */
    public function invoiceUpo(KsefCall $call, string $sessionReference, string $invoiceReference): string;

    /**
     * `GET /sessions` z `sessionType` = `Online` i `dateCreatedFrom`/`dateCreatedTo` (grupa `ksef.sessions_list`).
     *
     * @return list<string> numery referencyjne sesji
     */
    public function sessions(KsefCall $call, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * `GET /sessions/{referenceNumber}/invoices` (grupa `ksef.session_invoices`).
     *
     * @return list<KsefInvoiceStatus>
     */
    public function sessionInvoices(KsefCall $call, string $sessionReference): array;

    /**
     * `GET /sessions/{referenceNumber}/invoices/failed` (grupa `ksef.session_failed`).
     *
     * @return list<KsefInvoiceStatus>
     */
    public function failedInvoices(KsefCall $call, string $sessionReference): array;
}
