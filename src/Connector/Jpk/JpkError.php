<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

/**
 * Kody wyników API JPK 5.6.0 jako wyliczenie (docs/modules/jpk.md, „Kody wyników”) oraz kody kokpitu dla odmów przed
 * wysyłką. Kod spoza listy zostaje liczbą w `filing.status_code` i `approval.result_code`.
 */
enum JpkError: string
{
    /** `InitUploadSigned`: podpis kwalifikowany i dane autoryzujące naraz. */
    case SignatureWithAuthData = '136';
    /** `InitUploadSigned`: metadane niezgodne z `initupload.xsd`. */
    case MetadataSchema = '140';
    /** `InitUploadSigned`: niepoprawne wartości w metadanych. */
    case MetadataValues = '142';
    /** `InitUploadSigned`: za dużo wywołań `InitUpload`; nic nie złożono. */
    case TooManyUploads = '158';
    /** `InitUploadSigned`: duplikat przetworzonego dokumentu z numerem oryginału. */
    case DuplicateWithOriginal = '170';
    /** `Status`: sesja rozpoczęta. */
    case Started = '100';
    /** `Status`: odebrano części. */
    case PartsReceived = '101';
    /** `Status`: trwa weryfikacja. */
    case Verifying = '120';
    /** `Status`: przetwarzanie poprawne, UPO. */
    case Processed = '200';
    /** `Status`: nieprawidłowy numer referencyjny. */
    case InvalidReference = '300';
    /** `Status`: duplikat z numerem oryginału. */
    case DuplicateOriginal = '407';
    /** `Status`: identyczny dokument już złożony, bez numeru oryginału. */
    case IdenticalDocument = '411';
    /** Adres uploadu poza magazynem celu (`HostAllowlist`); Put Blob nie wyszedł. */
    case StorageHostRejected = 'storage_host_not_allowed';
    /** Lista adresów uploadu nie odpowiada częściom pliku. */
    case UploadTargetsMismatch = 'upload_targets_mismatch';
    /** Put Blob bez odpowiedzi 201; bez `FinishUpload` sesja przepada. */
    case PutBlobFailed = 'put_blob_failed';
    /** `FinishUpload` bez odpowiedzi; wynik nieznany. */
    case FinishUploadTimeout = 'finish_upload_timeout';
    /** Odpowiedź API bez oczekiwanych pól. */
    case InvalidResponse = 'invalid_response';
    /** Certyfikat klucza publicznego MF celu: brak, wygasły albo niepoprawny. */
    case MfKeyInvalid = 'mf_key_invalid';
    /** Brak schematu `InitUpload` albo SIG-2008 w `resources/xsd`. */
    case SchemaMissing = 'xsd_missing';
    /** Metadane `InitUpload` niezgodne z lokalnymi kontrolami (XSD, rozmiar, `FileName`, podpis i `AuthData`). */
    case MetadataInvalid = 'metadata_invalid';
    /** `auth_data` albo `company.identity` niekompletne lub niezgodne z SIG-2008. */
    case AuthDataInvalid = 'auth_data_invalid';
    /** Brak numeru referencyjnego: `InitUploadSigned` bez odpowiedzi, nic nie złożono. */
    case NoReference = 'no_reference';

    /** Kody `Status` w toku: dalsze odpytywanie. */
    public const IN_PROGRESS = [100, 101, 120];
}
