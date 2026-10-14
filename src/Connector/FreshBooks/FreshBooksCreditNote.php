<?php

declare(strict_types=1);

namespace App\Connector\FreshBooks;

/**
 * Credit note FreshBooks ([FreshBooks, credit notes](https://www.freshbooks.com/api/credit_notes)) jako źródło szkicu KOR
 * (Q-27, D-53). Pola wspólne z fakturą czyta `FreshBooksInvoice::fromApi` (numer credit note to P_2 korekty, pozycje to
 * kwoty uznania). Fakturę korygowaną wskazuje linia `Faktura: <numer>` w `notes`, bo API nie wiąże credit note z fakturą.
 */
final readonly class FreshBooksCreditNote
{
    /** Linia w `notes` z numerem faktury korygowanej (D-53). */
    public const REFERENCE = '/^\s*Faktura:\s*(\S{1,256})\s*$/mu';

    public function __construct(public FreshBooksInvoice $document, public ?string $originalNumber) {}

    /** @param array<mixed> $row */
    public static function fromApi(array $row): self
    {
        $notes = $row['notes'] ?? null;
        $original = is_string($notes) && preg_match(self::REFERENCE, $notes, $match) ? $match[1] : null;
        $document = FreshBooksInvoice::fromApi([
            'id' => $row['creditid'] ?? $row['id'] ?? null,'invoice_number' => $row['credit_number'] ?? null,'v3_status' => $row['status'] ?? null,
            'customerid' => $row['clientid'] ?? null,'po_number' => null,
        ] + $row);
        return new self($document, $original);
    }
}
