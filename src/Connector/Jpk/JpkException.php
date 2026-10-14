<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

/** Odmowa API JPK albo kontroli przed wysyłką; `resultCode` trafia do `approval.result_code` i `filing.status_code`. */
final class JpkException extends \RuntimeException
{
    /**
     * @param ?string $originalReference numer referencyjny oryginału przy duplikacie (170)
     * @param string $detail opis bez danych osobowych, dopisany do komunikatu
     */
    public function __construct(public readonly string $resultCode, public readonly ?string $originalReference = null, string $detail = '')
    {
        parent::__construct('jpk_' . $resultCode . ($detail === '' ? '' : ': ' . $detail));
    }

    /** Brak oficjalnego schematu w `resources/xsd`: metadane i `AuthData` nie powstają bez walidacji XSD. */
    public static function schemaMissing(string $file): self
    {
        return new self(JpkError::SchemaMissing->value, null, sprintf('brak oficjalnego schematu %s w resources/xsd; walidacja XSD przed wysyłką jest obowiązkowa, plik dostarczasz ze źródła MF z sumą w checksums.json.', $file));
    }

    public static function of(JpkError $error): self
    {
        return new self($error->value);
    }
}
