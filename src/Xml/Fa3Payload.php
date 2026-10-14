<?php

declare(strict_types=1);

namespace App\Xml;

use App\Fx\FxRate;

/**
 * Payload FA(3) po XSD i kontrolach przed wysyłką: dokładne bajty do zapisu jako `xml_sent` i ich sha256.
 * Kwoty w walucie faktury (przy KOR różnice), `netPln` i `vatPln` po kursie VAT.
 */
final readonly class Fa3Payload
{
    public function __construct(
        public string $xml,
        public string $schemaVersion,
        public string $issueDate,
        public string $taxPointDate,
        public string $netTotal,
        public string $vatTotal,
        public string $grossTotal,
        public ?FxRate $fxVatRate,
        public string $netPln,
        public string $vatPln,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->xml);
    }
}
