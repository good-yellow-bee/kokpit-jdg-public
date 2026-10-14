<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/** Odszyfrowana paczka eksportu: XML faktur i pola `_metadata.json` potrzebne kursorowi. */
final readonly class KsefExportPackage
{
    /** @param list<KsefExportedInvoice> $invoices */
    public function __construct(
        public array $invoices,
        public bool $isTruncated,
        public ?\DateTimeImmutable $lastPermanentStorageDate,
        public ?\DateTimeImmutable $permanentStorageHwmDate,
    ) {}
}
