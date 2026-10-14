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

    /**
     * Nabywca z `invoice.buyer_snapshot` (kształt zapisu z `Subject1Importer`): `name`, `nip`, `eu_vat_prefix`,
     * `eu_vat_number`, `id_country`, `foreign_tax_id`, `no_id`, `address` {`country`, `line1`, `line2`}.
     */
    public static function fromSnapshot(string $json): self
    {
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new Fa3Exception('buyer_snapshot nie jest obiektem.');
        }
        $text = static fn(mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $address = $data['address'] ?? null;
        $address = is_array($address) ? $address : [];
        return new self(
            $text($data['nip'] ?? null),
            $text($data['eu_vat_prefix'] ?? null),
            $text($data['eu_vat_number'] ?? null),
            $text($data['id_country'] ?? null),
            $text($data['foreign_tax_id'] ?? null),
            ($data['no_id'] ?? false) === true,
            $text($data['name'] ?? null) ?? '',
            ['country' => $text($address['country'] ?? null) ?? 'PL','line1' => $text($address['line1'] ?? null),'line2' => $text($address['line2'] ?? null)],
        );
    }

    /** Kraj siedziby: z KodUE, z KodKraju przy NrID, z adresu, a przy NIP Polska. */
    public function country(): string
    {
        return $this->nip !== null ? 'PL' : ($this->euVatPrefix ?? $this->idCountry ?? $this->address['country']);
    }
}
