<?php

declare(strict_types=1);

namespace App\Invoice;

/** Wizualizacja faktury z KOD I (F3.4): HTML szablonu, bajty PDF, adres KOD I i obraz PNG kodu QR z tym adresem. */
final readonly class InvoicePdfDocument
{
    public function __construct(public string $html, public string $pdf, public string $qrUrl, public string $qrPng, public string $ksefNumber, public string $invoiceNumber) {}
}
