<?php

declare(strict_types=1);

namespace App\Invoice;

/** Podmiot1 albo Podmiot2 z FA(3): identyfikator (NIP, KodUE z NrVatUE albo KodKraju z NrID), nazwa i adres. */
final readonly class Fa3Party
{
    /** @param array{country:string,line1:?string,line2:?string} $address */
    public function __construct(
        public ?string $nip,
        public ?string $euVatPrefix,
        public ?string $euVatNumber,
        public ?string $idCountry,
        public ?string $foreignTaxId,
        public bool $noId,
        public string $name,
        public array $address,
    ) {}

    /** Kraj siedziby: z KodUE, z KodKraju przy NrID, z adresu, a przy NIP Polska. */
    public function country(): string
    {
        return $this->nip !== null ? 'PL' : ($this->euVatPrefix ?? $this->idCountry ?? $this->address['country']);
    }
}
