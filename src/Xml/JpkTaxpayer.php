<?php

declare(strict_types=1);

namespace App\Xml;

use App\Security\SecretStore;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;

/**
 * Dane `Podmiot1` wspólne dla JPK_EWP(4) i JPK_ST(1): część JPK `company.identity` z ustawień i `DataUrodzenia`
 * z `auth_data` w `SecretStore` (D-37). Wartość daty urodzenia nie trafia do logów ani wyjątków.
 */
final readonly class JpkTaxpayer
{
    public function __construct(private SettingStore $settings, private SecretStore $secrets) {}

    /** @return array<string,string> część JPK `company.identity` z polami `nip`, `tax_office_code`, `first_name`, `last_name` */
    public function identity(): array
    {
        $identity = $this->settings->value(SettingKeys::COMPANY_IDENTITY);
        if (!is_array($identity)) {
            throw new JpkBuildRefused('Brak company.identity (część JPK) w ustawieniach.');
        }
        $result = [];
        foreach ($identity as $field => $value) {
            if (is_string($field) && is_string($value)) {
                $result[$field] = $value;
            }
        }
        foreach (['nip', 'tax_office_code', 'first_name', 'last_name'] as $required) {
            if (!isset($result[$required])) {
                throw new JpkBuildRefused(sprintf('company.identity bez pola %s.', $required));
            }
        }
        return $result;
    }

    /** `DataUrodzenia` z `auth_data` w `SecretStore`; wartość nie trafia do logów ani wyjątków. */
    public function birthDate(string $environment): string
    {
        try {
            $raw = $this->secrets->get('auth_data', $environment);
        } catch (\RuntimeException) {
            throw new JpkBuildRefused('Brak auth_data w sejfie; DataUrodzenia pochodzi tylko stamtąd.');
        }
        $data = json_decode($raw, true, 4);
        $date = is_array($data) ? ($data['birth_date'] ?? null) : null;
        if (!is_string($date) || !self::isDate($date)) {
            throw new JpkBuildRefused('auth_data bez poprawnego birth_date (RRRR-MM-DD).');
        }
        return $date;
    }

    /**
     * `Podmiot1` z `OsobaFizyczna` (NIP, imię, nazwisko i data urodzenia w przestrzeni `etd`, potem `Email` i `Telefon`)
     * w przestrzeni nazw dokumentu.
     *
     * @param array<string,string> $identity
     */
    public static function appendPerson(\DOMElement $subject, string $namespace, string $etdNamespace, array $identity, string $birthDate): void
    {
        $document = $subject->ownerDocument ?? throw new \LogicException('Element bez dokumentu.');
        $person = $subject->appendChild($document->createElementNS($namespace, 'OsobaFizyczna'));
        foreach (['NIP' => $identity['nip'], 'ImiePierwsze' => $identity['first_name'], 'Nazwisko' => $identity['last_name'], 'DataUrodzenia' => $birthDate] as $name => $value) {
            $person->appendChild($document->createElementNS($etdNamespace, 'etd:' . $name))->appendChild($document->createTextNode($value));
        }
        foreach (['Email' => $identity['email'] ?? null, 'Telefon' => $identity['phone'] ?? null] as $name => $value) {
            if ($value !== null) {
                $person->appendChild($document->createElementNS($namespace, $name))->appendChild($document->createTextNode($value));
            }
        }
    }

    public static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
