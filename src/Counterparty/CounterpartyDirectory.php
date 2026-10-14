<?php

declare(strict_types=1);

namespace App\Counterparty;

use App\Config\ConnectorConfiguration;
use App\Invoice\Fa3Party;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Dopasowanie kontrahenta z faktury do `counterparty` po NIP, numerze VAT UE albo NrID z krajem
 * (docs/modules/rejestry-kursy.md). Bez dopasowania powstaje nowy wiersz; rola `is_supplier` albo `is_client` dochodzi.
 */
final readonly class CounterpartyDirectory
{
    public function __construct(private Connection $db, private ClockInterface $clock, private ConnectorConfiguration $configuration) {}

    public function supplier(Fa3Party $party): string
    {
        return $this->match($party, 'is_supplier');
    }

    public function client(Fa3Party $party): string
    {
        return $this->match($party, 'is_client');
    }

    /**
     * Wpis ręczny kosztu: istniejący kontrahent po identyfikatorze albo nowy wiersz z podanych pól.
     *
     * @param array{name:string,country:string,nip?:?string,eu_vat_prefix?:?string,eu_vat_number?:?string,foreign_tax_id?:?string,is_natural_person?:bool} $fields
     */
    public function manualSupplier(array $fields): string
    {
        $party = new Fa3Party($fields['nip'] ?? null, $fields['eu_vat_prefix'] ?? null, $fields['eu_vat_number'] ?? null, ($fields['foreign_tax_id'] ?? null) === null ? null : $fields['country'], $fields['foreign_tax_id'] ?? null, false, $fields['name'], ['country' => $fields['country'],'line1' => null,'line2' => null]);
        $id = $this->match($party, 'is_supplier');
        if (($fields['is_natural_person'] ?? false) === true) {
            $this->db->update('counterparty', ['is_natural_person' => 'true','updated_at' => $this->now()], ['id' => $id]);
        }
        return $id;
    }

    private function match(Fa3Party $party, string $role): string
    {
        $environment = $this->configuration->environment();
        $id = match (true) {
            $party->nip !== null => $this->db->fetchOne('SELECT id FROM counterparty WHERE environment=? AND nip=? FOR UPDATE', [$environment, $party->nip]),
            $party->euVatNumber !== null => $this->db->fetchOne('SELECT id FROM counterparty WHERE environment=? AND eu_vat_prefix=? AND eu_vat_number=? FOR UPDATE', [$environment, $party->euVatPrefix, $party->euVatNumber]),
            $party->foreignTaxId !== null => $this->db->fetchOne('SELECT id FROM counterparty WHERE environment=? AND country=? AND foreign_tax_id=? ORDER BY created_at LIMIT 1 FOR UPDATE', [$environment, $party->country(), $party->foreignTaxId]),
            default => false,
        };
        $now = $this->now();
        if (is_string($id)) {
            $this->db->executeStatement(sprintf('UPDATE counterparty SET %1$s=true, updated_at=? WHERE id=? AND NOT %1$s', $role), [$now, $id]);
            return $id;
        }
        if ($party->name === '') {
            throw new \DomainException('Kontrahent bez nazwy.');
        }
        $id = (string) UuidV7::generate($this->clock->now());
        $address = array_filter(['line1' => $party->address['line1'],'line2' => $party->address['line2'],'country' => $party->address['country']], static fn(?string $value): bool => $value !== null);
        $this->db->insert('counterparty', [
            'id' => $id,'environment' => $environment,'name' => $party->name,'country' => $party->country(),'nip' => $party->nip,
            'eu_vat_prefix' => $party->euVatPrefix,'eu_vat_number' => $party->euVatNumber,'foreign_tax_id' => $party->foreignTaxId,
            'address' => json_encode($address, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'is_client' => $role === 'is_client' ? 'true' : 'false','is_supplier' => $role === 'is_supplier' ? 'true' : 'false',
            'created_at' => $now,'updated_at' => $now,
        ]);
        return $id;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.uP');
    }
}
