<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

use App\Security\Redactor;
use App\Security\SecretStore;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * `AuthData` według SIG-2008_v2-0 (docs/modules/jpk.md, „Dane autoryzujące”): NIP, pierwsze imię i nazwisko
 * z `company.identity`, data urodzenia i `Kwota` (przychód N−2, D-37) z sekretu `auth_data`. Dokument powstaje tylko
 * w pamięci workera przy wysyłce, przechodzi walidację XSD offline i jest szyfrowany kluczem pliku; nie trafia do
 * `xml_sent`, `connector_call` ani logów (`Redactor` dostaje wartości jako sekrety).
 */
final readonly class AuthDataDocument
{
    public const SCHEMA = 'SIG-2008_v2-0.xsd';
    public const NS = 'http://e-deklaracje.mf.gov.pl/Repozytorium/Definicje/Podpis/';

    public function __construct(private SettingStore $settings, private SecretStore $secrets, private XsdCatalog $catalog, private XsdValidator $validator, private Redactor $redactor) {}

    /** Czy schemat SIG-2008 jest w `resources/xsd`; bez niego wysyłka czeka (`xsd_missing`). */
    public function schemaAvailable(): bool
    {
        return $this->catalog->has(self::SCHEMA);
    }

    /** Jawny XML `AuthData`; tylko do szyfrowania kluczem pliku w tym samym przebiegu workera. */
    public function build(string $environment): string
    {
        if (!$this->schemaAvailable()) {
            throw JpkException::schemaMissing(self::SCHEMA);
        }
        $identity = $this->settings->value(SettingKeys::COMPANY_IDENTITY);
        $fields = [];
        foreach (['nip', 'first_name', 'last_name'] as $field) {
            $value = is_array($identity) ? ($identity[$field] ?? null) : null;
            if (!is_string($value) || trim($value) === '') {
                throw JpkException::of(JpkError::AuthDataInvalid);
            }
            $fields[$field] = trim($value);
        }
        try {
            $raw = $this->secrets->get('auth_data', $environment);
        } catch (\RuntimeException) {
            throw JpkException::of(JpkError::AuthDataInvalid);
        }
        $data = json_decode($raw, true, 4);
        $birth = is_array($data) ? ($data['birth_date'] ?? null) : null;
        $revenue = is_array($data) ? ($data['revenue_n2'] ?? null) : null;
        $date = is_string($birth) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $birth) : false;
        if (!is_string($birth) || $date === false || $date->format('Y-m-d') !== $birth || !is_string($revenue)) {
            throw JpkException::of(JpkError::AuthDataInvalid);
        }
        try {
            $amount = BigDecimal::of($revenue)->toScale(2);
        } catch (MathException) {
            throw JpkException::of(JpkError::AuthDataInvalid);
        }
        if ($amount->isNegative()) {
            throw JpkException::of(JpkError::AuthDataInvalid);
        }
        $this->redactor->registerSecret($birth);
        if (!$amount->isZero()) {
            // Zero („przy braku 0”) nie identyfikuje osoby, a maskowałoby każdą kwotę 0.00 w logach.
            $this->redactor->registerSecret((string) $amount);
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(self::NS, 'podp:DaneAutoryzujace');
        $document->appendChild($root);
        foreach (['NIP' => $fields['nip'], 'ImiePierwsze' => $fields['first_name'], 'Nazwisko' => $fields['last_name'], 'DataUrodzenia' => $birth, 'Kwota' => (string) $amount] as $name => $value) {
            $root->appendChild($document->createElementNS(self::NS, 'podp:' . $name))->appendChild($document->createTextNode($value));
        }
        $xml = $document->saveXML();
        if ($xml === false || $this->validator->validate($xml, self::SCHEMA) !== []) {
            // Błędy walidacji mogą cytować wartości osobowe, więc nie trafiają do wyjątku.
            throw JpkException::of(JpkError::AuthDataInvalid);
        }
        return $xml;
    }
}
