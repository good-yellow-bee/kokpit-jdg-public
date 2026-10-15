<?php

declare(strict_types=1);

namespace App\Connector\EDeklaracje;

/**
 * Statusy bramki e-Deklaracji jako wyliczenie (docs/modules/jpk.md, „Bramka e-Deklaracji (SOAP)”, specyfikacja 2.4)
 * oraz kody kokpitu dla odmów przed wysyłką i odpowiedzi niejednoznacznych. Status spoza listy zostaje liczbą
 * w `filing.status_code` i `approval.result_code`.
 */
enum EDeklaracjeError: string
{
    /** Błąd komunikatu albo prośba o ponowienie; kokpit nie wysyła ponownie, `requestUPO` ponawia odczyt. */
    case MessageError = '100';
    case RetryLater = '101';
    case ServiceBusy = '102';
    /** Przetwarzanie poprawne, UPO; także duplikat z UPO oryginału. */
    case Processed = '200';
    /** Brak dokumentu: wynik nieznany, rekoncyliacja odczytem. */
    case NoDocument = '300';
    /** Dokument w trakcie przetwarzania. */
    case Received = '301';
    case Verifying = '302';
    case Processing = '303';
    case Queued = '306';
    /** XSD, błędy przetwarzania, liczba elementów. */
    case SchemaError = '401';
    case ProcessingError = '408';
    case ElementCount = '409';
    /** Dokument bez podpisu nie może być korektą (VAT-UEK z danymi autoryzującymi; sprawdza F5.6). */
    case UnsignedCorrection = '410';
    /** Już złożono dokument z tym identyfikatorem: wynik nieznany, rekoncyliacja jak przy 300. */
    case AlreadyFiled = '411';
    /** Dane autoryzujące niezgodne albo błędne. */
    case AuthDataMismatch = '412';
    case AuthDataInvalid = '414';
    /** Wymagany podpis kwalifikowany: `rejected` i nowy rekord D-xx zastępujący D-27. */
    case QualifiedSignatureRequired = '418';
    case CorrectionPurposeMissing = '419';
    /** Dane autoryzujące tylko dla podatnika osoby fizycznej, nie pełnomocnika. */
    case NaturalPersonOnly = '422';
    case NotForProxy = '423';
    /** Odpowiedź bez oczekiwanych pól albo SOAP Fault. */
    case InvalidResponse = 'invalid_response';
    /** Dokument z `DaneAutoryzujace` niezgodny z kontrolami w pamięci (XSD deklaracji, SIG-2008, pozycja elementu). */
    case DocumentInvalid = 'document_invalid';
    /** `auth_data` albo `company.identity` niekompletne lub niezgodne z SIG-2008. */
    case AuthDataLocal = 'auth_data_invalid';
    /** `sendUnsignDocument` bez odpowiedzi: dokument mógł dotrzeć, wynik nieznany. */
    case SendNoResponse = 'send_no_response';
    /** Brak `refId`: nie ma czego odczytać przez `requestUPO`. */
    case NoReference = 'no_reference';

    /** `requestUPO`: dokument w trakcie przetwarzania, dalsze odpytywanie. */
    public const IN_PROGRESS = [301, 302, 303, 306];
    /** `requestUPO`: błąd komunikatu albo prośba o ponowienie; tylko ponowny odczyt, nigdy ponowna wysyłka. */
    public const READ_AGAIN = [100, 101, 102];
    /** `requestUPO`: wynik nieznany, rekoncyliacja odczytem. */
    public const UNKNOWN = [300, 411];
    /** Statusy wymagające decyzji o kanale (D-27): 410 i 418. */
    public const DECISION = [410, 418];
}
