<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/** Faktura z paczki: `acquisitionDate` to data nadania numeru KSeF, `permanentStorageDate` służy tylko kursorowi. */
final readonly class KsefExportedInvoice
{
    public function __construct(
        public string $ksefNumber,
        public \DateTimeImmutable $acquisitionDate,
        public \DateTimeImmutable $permanentStorageDate,
        public string $xml,
    ) {}
}
