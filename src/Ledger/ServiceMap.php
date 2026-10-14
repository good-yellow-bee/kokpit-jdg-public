<?php

declare(strict_types=1);

namespace App\Ledger;

/**
 * `ryczalt.service_map`: usługa → PKWiU 2015 → stawka ryczałtu (docs/tax-rules.md, „Stawki ryczałtu”).
 * Stawki są napisami dziesiętnymi, nigdy liczbą JSON. Dopasowanie: `freshbooks_item`, potem `p7_match`, potem `default`.
 */
final readonly class ServiceMap
{
    private const RATE = '/^(0|[1-9][0-9]?)\.[0-9]{2}$/D';
    private const FIELDS = ['service_type','freshbooks_item','p7_match','pkwiu','rate_pct','default'];

    /** @param list<array{service_type:string,freshbooks_item:?string,p7_match:list<string>,pkwiu:string,rate_pct:string,default:bool}> $entries */
    public function __construct(public array $entries) {}

    public static function fromSetting(mixed $value): self
    {
        return new self($value === null ? [] : self::normalize($value));
    }

    /** @return list<array{service_type:string,freshbooks_item:?string,p7_match:list<string>,pkwiu:string,rate_pct:string,default:bool}> */
    public static function normalize(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('ryczalt.service_map to lista wpisów.');
        }
        $entries = [];
        $types = [];
        $defaults = 0;
        foreach ($value as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), self::FIELDS) !== []) {
                throw new \InvalidArgumentException('Wpis ryczalt.service_map ma pola: ' . implode(', ', self::FIELDS) . '.');
            }
            $type = $entry['service_type'] ?? null;
            if (!is_string($type) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $type) || isset($types[$type])) {
                throw new \InvalidArgumentException('service_type to unikalny klucz z małych liter, cyfr i _.');
            }
            $types[$type] = true;
            $item = $entry['freshbooks_item'] ?? null;
            if ($item !== null && (!is_string($item) || trim($item) === '' || mb_strlen($item) > 256)) {
                throw new \InvalidArgumentException('freshbooks_item to nazwa pozycji albo null.');
            }
            $match = $entry['p7_match'] ?? [];
            if (!is_array($match) || !array_is_list($match)) {
                throw new \InvalidArgumentException('p7_match to lista fragmentów opisu.');
            }
            $fragments = [];
            foreach ($match as $fragment) {
                if (!is_string($fragment) || trim($fragment) === '') {
                    throw new \InvalidArgumentException('Fragment p7_match nie może być pusty.');
                }
                $fragments[] = trim($fragment);
            }
            $pkwiu = $entry['pkwiu'] ?? null;
            if (!is_string($pkwiu) || !preg_match('/^[0-9]{2}(\.[0-9]{1,2}){0,3}$/D', $pkwiu)) {
                throw new \InvalidArgumentException('pkwiu to kod PKWiU 2015, np. 62.01.1.');
            }
            $rate = $entry['rate_pct'] ?? null;
            if (!is_string($rate) || !preg_match(self::RATE, $rate) || $rate === '0.00') {
                throw new \InvalidArgumentException('rate_pct to stawka jako napis z dwoma miejscami, np. "12.00".');
            }
            $default = $entry['default'] ?? false;
            if (!is_bool($default)) {
                throw new \InvalidArgumentException('default przyjmuje true albo false.');
            }
            $defaults += $default ? 1 : 0;
            $entries[] = ['service_type' => $type,'freshbooks_item' => $item === null ? null : trim($item),'p7_match' => $fragments,'pkwiu' => $pkwiu,'rate_pct' => $rate,'default' => $default];
        }
        if ($defaults > 1) {
            throw new \InvalidArgumentException('default = true ma najwyżej jeden wpis.');
        }
        return $entries;
    }

    /**
     * Wpis dla pozycji; null to brak stawki (alert i brak wpisu do ewidencji).
     *
     * @return array{service_type:string,freshbooks_item:?string,p7_match:list<string>,pkwiu:string,rate_pct:string,default:bool}|null
     */
    public function match(?string $freshbooksItem, ?string $description): ?array
    {
        if ($freshbooksItem !== null) {
            foreach ($this->entries as $entry) {
                if ($entry['freshbooks_item'] !== null && mb_strtolower($entry['freshbooks_item']) === mb_strtolower(trim($freshbooksItem))) {
                    return $entry;
                }
            }
        }
        if ($description !== null) {
            $text = mb_strtolower($description);
            foreach ($this->entries as $entry) {
                foreach ($entry['p7_match'] as $fragment) {
                    if (str_contains($text, mb_strtolower($fragment))) {
                        return $entry;
                    }
                }
            }
        }
        foreach ($this->entries as $entry) {
            if ($entry['default']) {
                return $entry;
            }
        }
        return null;
    }

    /** @return array{service_type:string,freshbooks_item:?string,p7_match:list<string>,pkwiu:string,rate_pct:string,default:bool}|null */
    public function byServiceType(string $serviceType): ?array
    {
        foreach ($this->entries as $entry) {
            if ($entry['service_type'] === $serviceType) {
                return $entry;
            }
        }
        return null;
    }
}
