# JPK i e-Deklaracje

Wysyłka JPK_EWP(4), JPK_ST(1) i JPK_V7M(3) przez API JPK 5.6.0 oraz VAT-UE i VAT-UEK przez bramkę e-Deklaracji. Stan na 8 października 2026.

## Jak czytać

| Faza | Sekcje |
| --- | --- |
| F2.4 (dry run) | „Zakres i kolejność”, „JPK_EWP(4) i JPK_ST(1)”, „Pliki XSD” |
| F5 | cały plik |

- Dry run w F2.4 idzie przed F0b i F1 (D-30): bez `approval`, bez konektora `jpk` i bez `FxRateProvider`.
- Stany `filing` i kolumny są w [data-model.md](../data-model.md). Stany zatwierdzeń, rekoncyliacja i `SubmissionReconciler` są w [approval-gate.md](../approval-gate.md).
- Mapowanie wierszy ewidencji na JPK_EWP(4) jest w [ewidencja.md](ewidencja.md). Reguły podatkowe (kursy, import usług) są w [tax-rules.md](../tax-rules.md).
- Terminy i zamknięcie miesiąca są w [compliance-calendar.md](../compliance-calendar.md). Nazwy pochodzą z [naming.md](../naming.md).

## Zakres i kolejność

| Plik | Kanał | Faza | Termin | Zapas |
| --- | --- | --- | --- | --- |
| JPK_EWP(4) za 2026 | API JPK, konektor `jpk` | dry run po F2.4 do 15.12.2026, cel własny (D-30); PROD w F5 do 31.03.2027 | 30.04.2027 ([broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf)) | ten sam XML przez Klient JPK WEB albo Formularz uproszczony |
| JPK_ST(1) za 2026 | API JPK | jak JPK_EWP, za flagą `jpk_st.enabled` | 30.04.2027 ([broszura JPK_ST(1)](https://podatki.gov.pl/media/jikpuwwp/broszura_informacyjna_dot_jpk_st-1.pdf)) | jak wyżej |
| JPK_V7M(3) | API JPK | F5, po JPK_EWP | 25. dzień za poprzedni miesiąc ([ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), art. 99 ust. 1) | e-mikrofirma albo Klient JPK WEB |
| VAT-UE(5), VAT-UEK(5) | bramka e-Deklaracji, konektor `edeklaracje` | F5 | 25. dzień (art. 100 ust. 3); korekta niezwłocznie (art. 101 [ustawy o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)) | formularz w e-Deklaracjach |
| PIT-28(27) za 2025 i kolejne warianty | bramka e-Deklaracji, konektor `edeklaracje` (D-96) | poza planem faz | 30.04 ([ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf), art. 21 ust. 2 pkt 2) | formularz w e-Deklaracjach albo Twój e-PIT |

Daty milowe i bramka 31.03.2027 są w [README.md](../README.md), narzędzia zapasowe w [narzędziach MF](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_pd/bezplatne-narzedzia). Każda wysyłka przechodzi przez bramkę zatwierdzeń i transport `official_writes` bez automatycznych ponowień.

## Konektor `jpk`: API JPK 5.6.0

Źródło: [specyfikacja interfejsów usług JPK, wersja 5.6.0.v1 z 10.09.2026](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf). Wersja 5.1.0 z dawnej specyfikacji jest nieaktualna.

### Środowiska

| `JPK_TARGET` | Interfejs | Magazyn plików |
| --- | --- | --- |
| `test` | `https://test-e-dokumenty.mf.gov.pl/api/Storage/` | `taxdocumentstorageNNtst.blob.core.windows.net` |
| `prod` | `https://e-dokumenty.mf.gov.pl/api/Storage/` | `taxdocumentstorageNN.blob.core.windows.net` |

- Metody: `InitUploadSigned`, Put Blob w Azure Storage, `FinishUpload`, `Status/{referenceNumber}`.
- `HostAllowlist` dopuszcza magazyn tylko dla bieżącego `JPK_TARGET` ([specyfikacja 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf)):
  - `test`: `taxdocumentstorage[0-9]{2}tst\.blob\.core\.windows\.net`;
  - `prod`: `taxdocumentstorage[0-9]{2}\.blob\.core\.windows\.net`.
- TLS 1.2 albo 1.3 i tylko sześć zestawów szyfrów: `TLS_AES_256_GCM_SHA384`, `TLS_AES_128_GCM_SHA256`, `TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384`, `TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256`, `TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384`, `TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256`. Test konektora sprawdza negocjowany zestaw; TE negocjuje TLS 1.3 `TLS_AES_256_GCM_SHA384` (D-69).
- Certyfikat klucza publicznego MF do szyfrowania klucza jest inny dla testu i produkcji i bywa wymieniany. Leży w konfiguracji per cel, z datą ważności monitorowaną w panelu ([pliki do pobrania MF](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_vat-z-deklaracja/pliki-do-pobrania)). Odświeża go zadanie `mf_keys.refresh` ([operations.md](../operations.md)). Serwis podatki.gov.pl odpowiada na zwykły `GET` wyzwaniem JavaScript (ochrona przed botami), więc nowy certyfikat dostarcza człowiek albo sesja z przeglądarką (D-69). Certyfikat celu `test` od 9.07.2025 jest ważny do 7.07.2027.
- Certyfikatów TLS serwera nie przypinasz.
- Adresy i wersje API są w konfiguracji, nie w kodzie (D-25 w [decisions.md](../decisions.md)).

### Przepływ wysyłki

1. XML z wierszy, gdzie `environment` = `JPK_TARGET`, w UTF-8. Walidacja XSD offline, `LIBXML_NONET` ([.claude/rules/integrations.md](../../.claude/rules/integrations.md)).
2. `FileStore` zapisuje dokładne bajty dokumentu JPK jako `xml_sent`, przed ZIP i szyfrowaniem. Skrót SHA-256 całego dokumentu trafia do metadanych w Base64.
3. Powstaje `approval` z podsumowaniem (sekcja „Podsumowanie w bramce”). Przy wysyłce worker liczy sha256 `xml_sent` ponownie i porównuje z `approval.payload_sha256`. Niezgodność daje `cancelled` z `cancel_reason` = `hash_mismatch` ([approval-gate.md](../approval-gate.md)).
4. ZIP z algorytmem DEFLATE, jeden dokument, bez dzielenia przez ZIP. Archiwum dzielone binarnie na części do 60 MB (62 914 560 bajtów).
5. Losowy klucz AES-256 i wektor IV (16 bajtów). Każda część szyfrowana AES-256-CBC z dopełnieniem PKCS#7.
6. Skrót MD5 każdej zaszyfrowanej części w Base64 (24 znaki, bez konwersji do hex).
7. Klucz AES szyfrowany RSA-2048, tryb ECB, dopełnienie PKCS#1, certyfikatem MF celu.
8. Metadane `InitUpload` do 100 KB: `DocumentType` = `JPK`, `Version` = `01.02.01.20160617`, `FormCode` z `kodSystemowy` i `wersjaSchemy`, unikalna `FileName` zgodna z `[a-zA-Z0-9_.-]{5,55}`, `ContentLength`, `HashValue`, lista części.
9. Uwierzytelnienie metadanych: `AuthData` z danymi autoryzującymi albo podpis XAdES-BES (podpis kwalifikowany albo zaufany). Nigdy oba naraz (kod 136).
10. `POST InitUploadSigned` zwraca numer referencyjny i adresy uploadu. Numer trafia od razu do `approval.external_refs`.
11. Put Blob każdej części (odpowiedź 201), potem `FinishUpload`.
12. `Status/{referenceNumber}` aż do kodu końcowego. Kod 200 zwraca UPO, które trafia do `stored_file` rodzaju `upo`.

Zaszyfrowanych części kokpit nie przechowuje ([data-model.md](../data-model.md)).

**Bajty zapisane a bajty wysłane**

| Kanał | `xml_sent` i hash zatwierdzenia | Dane autoryzujące | Ślad wysłanego żądania |
| --- | --- | --- | --- |
| API JPK | dokument JPK przed ZIP i szyfrowaniem | `AuthData` w metadanych `InitUpload`, nie w dokumencie | `request_sha256` i rozmiar każdego wywołania w `connector_call` |
| bramka e-Deklaracji | deklaracja przed wstawieniem `DaneAutoryzujace` | element `DaneAutoryzujace` w dokumencie | `request_sha256` i rozmiar w `connector_call` |

- `xml_sent` to bajty dokumentu przed danymi autoryzującymi; hash faktycznego żądania leży w `connector_call` ([../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md)).
- Dane autoryzujące i treść żądania istnieją tylko w pamięci workera. Treść nie trafia do `connector_call` ([data-model.md](../data-model.md)).

### Kody wyników

| Metoda | Kod | Znaczenie | Działanie kokpitu |
| --- | --- | --- | --- |
| `InitUploadSigned` (400) | 136 | podpis kwalifikowany i dane autoryzujące naraz | błąd kodu; poprawka i nowe zatwierdzenie |
| `InitUploadSigned` (400) | 140 | metadane `InitUpload` niezgodne z XSD (`initupload.xsd`) | błąd kodu, bo lokalna walidacja metadanych schematem z `resources/xsd` powinna to złapać |
| `InitUploadSigned` (400) | 142 | niepoprawne wartości w metadanych | błąd kodu |
| `InitUploadSigned` (400) | 158 | za dużo wywołań `InitUpload` | `rejected` z `result_code` 158; nic nie złożono (brak numeru referencyjnego); push; ponowienie tylko ręczne, nowym zatwierdzeniem po oknie limitu |
| `InitUploadSigned` (400) | 170 | duplikat przetworzonego dokumentu; podany numer oryginału | pobranie UPO oryginału; złożenie `accepted` |
| `InitUploadSigned` (400) | pozostałe | błąd żądania, podpisu, formularza albo rozmiaru | nic nie złożono; `rejected`; ponowienie ręczne nowym zatwierdzeniem |
| `Status` | 100, 101, 120 | sesja rozpoczęta, odebrano części, trwa weryfikacja | dalsze odpytywanie |
| `Status` | 200 | przetwarzanie poprawne, UPO | złożenie `accepted` |
| `Status` | 300 | nieprawidłowy numer referencyjny | po `FinishUpload`: `unknown` i rekoncyliacja jak przy 411; bez potwierdzonego `FinishUpload`: `rejected` z `result_code` 300, nic nie złożono ([approval-gate.md](../approval-gate.md)) |
| `Status` | 401, 408, 428 | XSD, błędy przetwarzania, reguły biznesowe | `rejected` |
| `Status` | 407 | duplikat; podany numer oryginału | pobranie UPO oryginału; `accepted` |
| `Status` | 411 | identyczny dokument już złożony, bez numeru oryginału | `unknown` → rekoncyliacja przez `SubmissionReconciler` (szukanie własnego złożenia z tym samym sha256) albo wgranie UPO jako `manual`; `accepted` dopiero z UPO |
| `Status` | 412, 413 | szyfrowanie albo suma kontrolna | `rejected`; błąd kodu |
| `Status` | 417, 418, 419, 426 | dane autoryzujące: szyfrowanie, XSD, treść, kodowanie | `rejected`; poprawa `auth_data` |
| `Status` | 420 | brak pełnomocnictwa; dotyczy zastępcy z UPL-1 | `rejected`; sprawdzasz UPL-1 |
| `Status` | 422 | dane autoryzujące tylko dla podatnika będącego osobą fizyczną | `rejected` |
| `Status` | 424 | dokument nie może być podpisany danymi autoryzującymi | `rejected` |
| `Status` | pozostałe 4xx | m.in. 410 (niepoprawny ZIP), 425, 429, 430, 432, 433 (rozmiar) | `rejected`, kod i opis w `filing.status_code` i `connector_call`; push |

Przejścia stanów `filing` są w [data-model.md](../data-model.md), stan `unknown` rozstrzyga [approval-gate.md](../approval-gate.md).

### Limity

Licznik w `rate_counter` sprawdza krok 3 wysyłki w bramce, przed pierwszym wywołaniem API ([approval-gate.md](../approval-gate.md)). Wyczerpany limit zostawia `approved`, a `approval.dispatch_waiting` próbuje później.

| Konektor | `rate_counter.bucket` | Okno | Wartość domyślna | Źródło |
| --- | --- | --- | --- | --- |
| `jpk` | `jpk.init_upload`: każde `InitUploadSigned` | doba od północy w Europe/Warsaw ([data-model.md](../data-model.md)) | 10 w konfiguracji aplikacji; kilka złożeń w miesiącu | kod 158 oznacza przekroczenie „dozwolonej liczby wysłanych plików w danym okresie”; [specyfikacja 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf) nie podaje wartości, więc wartość jest konserwatywna |
| `edeklaracje` | `edeklaracje.send`: każde `sendUnsignDocument` | jak wyżej | 10 w konfiguracji aplikacji | [specyfikacja e-Deklaracji 2.4](https://podatki-arch.mf.gov.pl/media/3616/edek_specyfikacja_we-wy_2-4_test_www.pdf) nie podaje limitu; wartość konserwatywna |

- Odpytywanie `Status` i `requestUPO` to odczyt na transporcie `reads`, bez tych liczników.
- Przy działającym liczniku kod 158 nie powinien wystąpić. Obsługa 158 jest w tabeli kodów wyżej.
- Nazwy liczników `jpk.init_upload` i `edeklaracje.send` są w [naming.md](../naming.md).

### Dane autoryzujące

| Schemat | Metody według specyfikacji 5.6.0 |
| --- | --- |
| JPK_V7M(3) | podpis kwalifikowany, podpis zaufany, dane autoryzujące |
| JPK_EWP(4) | podpis kwalifikowany, podpis zaufany, dane autoryzujące |
| JPK_ST(1) | podpis kwalifikowany, podpis zaufany, dane autoryzujące |

- `AuthData` to dokument zgodny z [SIG-2008_v2-0](https://podatki-arch.mf.gov.pl/media/10553/sig-2008_v2-0.xsd), zaszyfrowany AES-256-CBC tym samym kluczem co części pliku, w Base64.
- Pola: NIP albo PESEL, pierwsze imię, nazwisko, data urodzenia i `Kwota`. `Kwota` to przychód z zeznania za rok o dwa lata wcześniejszy niż rok złożenia; przy braku 0.
- Pozycja przychodu w PIT-28 zależy od wariantu formularza (niesprawdzone). Przy wpisie `auth_data` wskazujesz wariant i pole zeznania za N−2.
- Dane autoryzujące służą tylko podatnikowi będącemu osobą fizyczną (kod 422). Pełnomocnik podpisuje kwalifikowanym albo zaufanym podpisem. UPL-1 obejmuje też podpisanie JPK_EWP ([Dz.U. 2026 poz. 779](https://api.sejm.gov.pl/eli/acts/DU/2026/779/text.pdf)).
- Sposób uwierzytelnienia to strategia: dziś dane autoryzujące, później ewentualnie podpis kwalifikowany. Konektor nie zależy od jednej metody.
- Opcja bez przechowywania: dane wpisujesz przy wysyłce ze step-up i nie trafiają do bazy. Domyślnie `auth_data` jest sekretem statycznym w `SecretStore`, a `credential_state` trzyma tylko jego ważność ([security.md](../security.md)).
- `Kwota` zmienia się 1 stycznia. Przypomnienie `credential_expiry` jest w [compliance-calendar.md](../compliance-calendar.md).
- `Redactor` maskuje całą strukturę `auth_data` według listy kluczy i wzorców w [security.md](../security.md), w logach i widokach.

### Środowisko testowe

- Na `test-e-dokumenty` wysyłasz wyłącznie dane fikcyjne: fikcyjny zbiór faktur, ewidencji i fikcyjne dane autoryzujące ([testing.md](../testing.md)).
- Fikcyjny zbiór przechodzi tę samą ścieżkę kodu co produkcja.
- Parametr `enableValidateQualifiedSignature=true` działa tylko na środowisku testowym.
- TE weryfikuje dane autoryzujące z danymi podatnika: fikcyjne `AuthData` dają 419 („błąd w danych autoryzujących”) po pełnym przyjęciu i odszyfrowaniu pliku. Wynik F5.3 15.10.2026 dla JPK_EWP(4), JPK_ST(1) i JPK_V7M(3) w D-69; przyjęcie z UPO potwierdza pierwsze złożenie PROD.
- Ponowna wysyłka bajtów pliku odrzuconego kodem 419 dostaje nowy numer referencyjny i ten sam kod, bez duplikatu.

## JPK_V7M(3)

Struktura obowiązuje od okresu 02.2026 ([broszura JPK_V7M(3)](https://www.podatki.gov.pl/media/wgbkrejs/broszura-jpk_vat-z-deklaracj%C4%85-od-1-lutego-2026-r.pdf), [MF, nowe wzory](https://www.podatki.gov.pl/aktualnosci/nowe-wzory-struktur-jpk_vat-dostepne-na-epuap)). Kokpit buduje tylko JPK_V7M(3), bo wysyła okresy od go-live F5. Korekty okresów wcześniejszych składasz w narzędziach MF.

### Znaczniki KSeF w wierszach

Znacznik wylicza mapper z pól faktury; nie jest zapisywany ([data-model.md](../data-model.md)). Reguły według broszury i [objaśnień znaczników](https://podatki.gov.pl/media/o1jppu0e/jpk_vat-z-deklaracja-stosowanie-znacznikow_v11.pdf).

| Znacznik | Kiedy | Później |
| --- | --- | --- |
| `NrKSeF` | faktura ma numer KSeF w dniu złożenia, także z trybu offline24, awarii i niedostępności | – |
| `OFF` | faktura z trybu awarii KSeF (art. 106nf ust. 1) bez numeru w dniu złożenia | bez obowiązku korekty po otrzymaniu numeru |
| `BFK` | faktura elektroniczna albo papierowa spoza KSeF, w tym faktura zagraniczna i faktura z awarii całkowitej | – |
| `DI` | dowód inny niż faktura; także faktura offline24 albo z niedostępności bez numeru w dniu złożenia | po nadaniu numeru korekta na `NrKSeF` |

**Tabela znaczników.** Mapper sprawdza warunki przy budowie pliku, od góry; wygrywa pierwszy pasujący. Wartości `invoice.ksef_issue_mode` są w [naming.md](../naming.md).

| Kolejno | Pola faktury | Znacznik |
| --- | --- | --- |
| 1 | `jpk_doc_type` = `WEW` | `DI` z `WEW` |
| 2 | `ksef_number` niepusty | `NrKSeF` |
| 3 | `ksef_issue_mode` = `failure` (art. 106nf ust. 1) | `OFF` |
| 4 | `ksef_issue_mode` = `offline24` albo `maintenance` | `DI` |
| 5 | `ksef_issue_mode` = `total_failure` albo `outside_ksef` | `BFK` |
| 6 | `ksef_issue_mode` = `online` bez numeru | blokada budowy: faktura w toku (`submitted`, `unknown`) |

| Faktura | Wartość `ksef_issue_mode` |
| --- | --- |
| sprzedaż z kokpitu | `online`; tryby offline tylko po zmianie D-15 (Q-28, [ksef.md](ksef.md)) |
| faktura z numerem z importu KSeF (Subject1, koszt z `source` = `ksef`) | tryb z metadanych KSeF albo pusty; znacznik i tak `NrKSeF` |
| koszt z `source` innym niż `ksef` | domyślnie `outside_ksef`; tryb offline albo awarii wskazujesz przy przeglądzie, np. przy kodzie QR z napisem OFFLINE ([ksef.md](ksef.md)) |
| sprzedaż spoza KSeF, np. sprzed 1.04.2026 | `outside_ksef` |

- Przy polityce domyślnej D-15 wiersze 3 i 4 dotyczą głównie kosztów oznaczonych przez Ciebie.
- Zadanie `jpk.replace_di` codziennie szuka faktur z `vat_period_start` okresu, za który jest przyjęty JPK_V7M ([operations.md](../operations.md)).
- Warunek: `ksef_issue_mode` = `offline24` albo `maintenance`, a `ksef_number_assigned_at` jest późniejsze niż utworzenie `xml_sent` najnowszego przyjętego JPK_V7M okresu (nie `superseded`).
- Liczy się chwila budowy, nie `filing.accepted_at`: numer nadany między budową a przyjęciem też zostawia `DI` w pliku.
- Każda znaleziona faktura ustawia `period.correction_required_at`.
- `TypDokumentu` (sprzedaż) i `DokumentZakupu` (zakup) z wartością `WEW` oznaczają dowód wewnętrzny. Źródłem jest `invoice.jpk_doc_type`.

### Mapowanie dokumentów

| Dokument | Rejestr | Pola | Znacznik |
| --- | --- | --- | --- |
| sprzedaż `eu_b2b` (np II, art. 100 ust. 1 pkt 4) | sprzedaż | K_11 i K_12 | według tabeli znaczników |
| sprzedaż `non_eu_b2b` (np I) | sprzedaż | K_11 | według tabeli znaczników |
| sprzedaż `eu_b2b_unidentified` (np I, bez VAT-UE); do potwierdzenia przez doradcę, do tego czasu zatwierdzenie zablokowane (D-05, Q-29) | sprzedaż | K_11, bez K_12 | według tabeli znaczników |
| sprzedaż `pl_b2b` 23% | sprzedaż | K_19, K_20 | według tabeli znaczników |
| faktura KOR do sprzedaży | sprzedaż | pola faktury korygowanej ze znakiem różnicy; okres według TypKorekty ([ksef.md](ksef.md)) | według tabeli znaczników, z pól faktury KOR |
| import usług spoza UE albo poza art. 28b | sprzedaż i zakup | K_27, K_28 oraz K_42, K_43 | według tabeli znaczników; zwykle `BFK` albo `DI` z `WEW` |
| import usług art. 28b od podatnika z UE | sprzedaż i zakup | K_29, K_30 oraz K_42, K_43 | według tabeli znaczników; zwykle `BFK` albo `DI` z `WEW` |
| zakup krajowy w KSeF | zakup | K_42, K_43; środek trwały K_40, K_41 | według tabeli znaczników; zwykle `NrKSeF` |
| zakup krajowy spoza KSeF (do 31.12.2026) | zakup | K_42, K_43 | według tabeli znaczników; zwykle `BFK` |
| koszt `imported`, `private`, `not_mine`, `disputed` | – | poza plikiem; `imported` blokuje budowę | – |

- Kwoty w PLN po kursie VAT ([tax-rules.md](../tax-rules.md)). K_11 zawiera też kwotę z K_12.
- Import usług zwolniony po stronie nabywcy: podstawa w K_27 albo K_29, podatek 0,00 ([tax-rules.md](../tax-rules.md)).
- Kody GTU i procedur: domyślnie brak (Q-31). Kolumna `invoice_line.gtu` czeka na odpowiedź.
- Fixture F5 obejmuje rekordy `NrKSeF`, `BFK` i `DI`, sprzedaż np I i np II oraz dostawcę z UE i spoza UE.

### Deklaracja

Pola według [broszury JPK_V7M(3)](https://www.podatki.gov.pl/media/wgbkrejs/broszura-jpk_vat-z-deklaracj%C4%85-od-1-lutego-2026-r.pdf). Kwoty części deklaracyjnej są w pełnych złotych ([data-model.md](../data-model.md)).

| Pole | Treść | Źródło w kokpicie |
| --- | --- | --- |
| P_38 | łączny podatek należny; obowiązkowe, „0” przy braku | suma pól podatku z ewidencji |
| P_39 | nadwyżka z poprzedniej deklaracji (P_62) albo z decyzji | P_62 poprzedniego złożenia; w pierwszym miesiącu klucz `setting` `opening.vat_carry_forward_pln` z dowodem (D-29, [compliance-calendar.md](../compliance-calendar.md)) |
| P_48 | łączny podatek naliczony do odliczenia | suma P_39, P_41, P_43 i dalszych |
| P_51 | podatek do wpłaty; obowiązkowe, „0” przy braku | wyliczenie |
| P_53 | nadwyżka podatku naliczonego nad należnym | wyliczenie |
| P_54 | nadwyżka do zwrotu na rachunek | `vat.excess_policy` |
| P_540, P_55, P_56, P_560, P_58 | termin zwrotu: 15 dni, 25 dni na rachunek VAT, 25 dni, 40 dni, 180 dni | `vat.excess_policy` |
| P_59, P_60, P_61 | zaliczenie zwrotu na poczet przyszłych zobowiązań | `vat.excess_policy` |
| P_62 | nadwyżka do przeniesienia na następny okres | domyślnie cała P_53 (Q-31) |
| P_ORDZU | uzasadnienie przyczyn korekty | tekst przy korekcie |
| `Pouczenia` | „1” jako potwierdzenie pouczeń | stała |

- Deklaracja zerowa ma „0” w P_38 i P_51.
- Sprzedaż głównie np I i np II przy polskim VAT naliczonym daje częstą nadwyżkę. Polityka zwrotu albo przeniesienia to Q-31.
- Nowelizacja ustawy o VAT z 4.09.2026 ([Dz.U. 2026 poz. 1270](https://api.sejm.gov.pl/eli/acts/DU/2026/1270/text.pdf)): od 1.12.2026 bez skutku dla tej JDG. Pakiet od 1.01.2027 (m.in. art. 103, 105a) sprawdzasz pod kątem JPK_V7M(3) w kroku 0 F5. Obserwacja w [facts.md](../facts.md).

### Podsumowanie w bramce

Przy zatwierdzeniu panel pokazuje `approval.summary`: sumy z `filing.totals` (sprzedaż netto, VAT należny, VAT naliczony, liczba dokumentów) oraz P_51, P_53 i P_62. Listę pozycji `DI` panel pokazuje osobno. Bramki kompletności przed budową pliku są w [compliance-calendar.md](../compliance-calendar.md).

## JPK_EWP(4) i JPK_ST(1)

- Każdy z nich to osobne złożenie z własnym UPO. Dawna reguła „wysyłka razem z PIT-28” nie obowiązuje.
- Nagłówek, `Podmiot1` i wiersze JPK_EWP(4) mapuje [ewidencja.md](ewidencja.md). JPK_ST(1) ma ten sam nagłówek i `Podmiot1` ([broszura JPK_ST(1)](https://podatki.gov.pl/media/jikpuwwp/broszura_informacyjna_dot_jpk_st-1.pdf)).
- `CelZlozenia` 1 przy pierwszym złożeniu, 2 przy korekcie całego roku.
- Dry run do 15.12.2026 to cel własny (D-30). Pliki z wierszy `import` sty–lis 2026 ([ewidencja.md](ewidencja.md)) zostają lokalnie, z walidacją XSD.
- Dry run nie potrzebuje F0b ani F1: bez bramki, bez konektora `jpk`. Wysyłka na `test-e-dokumenty` tylko z fikcyjnym zbiorem, w F5.
- PROD do 31.03.2027. Pierwsze złożenie PROD idzie przez bramkę i konektor `jpk` (`submitted_via` = `jpk`), z prawdziwymi danymi autoryzującymi. `Kwota` pochodzi z zeznania za 2025.
- `[manual]` przy tym złożeniu oznacza odbiór kryterium przez Ciebie, nie wysyłkę poza kokpitem.
- Zapas po 31.03.2027: ten sam XML z kokpitu przez Klient JPK WEB albo plik z Formularza uproszczonego. UPO wgrywasz, a złożenie ma `submitted_via` = `manual`.

### JPK_ST(1): węzeł `EWP`

Ryczałtowiec wypełnia węzeł `EWP` z polami F_1–F_16 ([broszura JPK_ST(1)](https://podatki.gov.pl/media/jikpuwwp/broszura_informacyjna_dot_jpk_st-1.pdf)).

| Pole | Treść | Kolumna `fixed_asset` |
| --- | --- | --- |
| F_1 | liczba porządkowa | wyliczana przy budowie |
| F_2 | data nabycia albo wytworzenia środka trwałego | `acquisition_date` przy `intangible` = false |
| F_3 | data nabycia WNiP | `acquisition_date` przy `intangible` = true |
| F_4 | data przyjęcia do używania | `commissioning_date` |
| F_5 | numer dokumentu przyjęcia, opcjonalny | `acquisition_document_number` |
| F_6 | rodzaj dokumentu nabycia: S, D, N, W, F, I | `acquisition_document_type` |
| F_7 | nazwa | `name` |
| F_8 | symbol KŚT, opcjonalny | `kst_code` |
| F_9 | stawka amortyzacyjna podatkowa, 0,00–100,00 | `depreciation_rate_pct` |
| F_10 | wartość początkowa w PLN | `initial_value_pln` |
| F_11 | zaktualizowana wartość początkowa, opcjonalna | `updated_value_pln` |
| F_12 | data likwidacji, zbycia, niedoboru albo kradzieży | `disposal_date` |
| F_13 | przyczyna wykreślenia: S, X, K, D, N, I | `disposal_reason` (kod) |
| F_14 | numer dokumentu wykreślenia | `disposal_document_number` |
| węzeł `KSeF` (F_15) | numery KSeF dokumentów zbycia, opcjonalne | `disposal_ksef_numbers`; `ksef_number` dotyczy zakupu |
| F_16 | reklasyfikacja: 1 tak, 2 nie | `reclassified` |

Typy kolumn są w [data-model.md](../data-model.md).

- Plik za rok (`JpkStBuilder`, XSD 1-2, `ZnacznikST` 1) obejmuje środki przyjęte do używania do końca okresu i niewykreślone przed jego początkiem; wykreślenie po końcu okresu nie trafia do pliku. `--until` skraca okres wierszy, nagłówek obejmuje cały rok. F_1 numeruje wiersze pliku od 1 w kolejności F_4 (D-64).
- Prośba o zatwierdzenie JPK_ST powstaje tylko przy `jpk_st.enabled` = true na 31.12. roku, jak obowiązek `jpk_st`; inaczej komunikat Q-25 bez złożenia. Bramki JPK_EWP(4) nie dotyczą JPK_ST; wysyłka idzie tym samym konektorem `jpk` (D-64).
- JPK_ST działa za flagą `jpk_st.enabled`. Przy braku środków trwałych jest domyślnie wyłączona do rozstrzygnięcia, czy wymagany jest plik bez wierszy (Q-25, [facts.md](../facts.md), przed 15.12.2026). Po podpisaniu zwolnienia flaga zostaje wyłączona za 2026–2028.
- Projekt zwolnienia zwalnia ryczałtowców z wykazu w programie i z JPK_ST za 2026–2028; wykaz papierowy zostaje. Na 08.10.2026 projekt jest otwarty, niepodpisany ([projekt](https://legislacja.gov.pl/projekt/12413609)). Obserwacja w [facts.md](../facts.md).
- Zwolnienie z ewidencji 2027–2028 obejmuje tylko niebędących czynnymi podatnikami VAT, więc JPK_EWP zostaje obowiązkowy ([projekt](https://legislacja.gov.pl/projekt/12413609)).

## VAT-UE i VAT-UEK

### Obowiązek

| Fakt | Źródło |
| --- | --- |
| Informację podsumowującą składa podatnik zarejestrowany jako podatnik VAT UE; obejmuje usługi z art. 28b dla podatników z UE, gdy VAT rozlicza usługobiorca, bez usług zwolnionych albo z 0% u nabywcy | [ustawa o VAT, art. 100 ust. 1 pkt 4](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| Okresy miesięczne, tylko elektronicznie, do 25. dnia miesiąca po miesiącu powstania obowiązku podatkowego | art. 100 ust. 3 |
| Obowiązek podatkowy dla tych usług według art. 19a ust. 1–3 i 8 | art. 100 ust. 11 |
| Treść: Twój numer VAT UE, numer VAT UE nabywcy z kodem kraju i łączna wartość usług per kontrahent | art. 100 ust. 8 |
| Błąd w złożonej informacji poprawiasz niezwłocznie korektą (VAT-UEK) | art. 101 |
| Rejestracja jako podatnik VAT UE | art. 97 ust. 3; stan sprawdzasz teraz (Q-12, [README.md](../README.md)) |

- Brak pliku za miesiąc bez takich usług podaje źródło wtórne. Do weryfikacji przy pierwszym miesiącu bez sprzedaży `eu_b2b`.
- Projekt znoszący VAT-UE (druk 2453) nie jest prawem ([proces druku 2453](https://api.sejm.gov.pl/sejm/term10/processes/2453)). Obserwacja w [facts.md](../facts.md).

### Dane

- Założenie: każda faktura `eu_b2b` to usługa opodatkowana u nabywcy (np II). Usługa zwolniona albo z 0% u nabywcy trafia tylko do K_11, bez K_12 i VAT-UE.
- Taki wyjątek wymaga oznaczenia w panelu. Pola jeszcze nie ma; pierwszy przypadek to propozycja do [data-model.md](../data-model.md).
- Wiersze: przyjęte faktury `eu_b2b`, także KOR, z ważnym wynikiem VIES (`verification_id`) i `vat_period_start` w miesiącu. Dla faktury pierwotnej to miesiąc `tax_point_date` ([data-model.md](../data-model.md)).
- `eu_b2b_unidentified` nie wchodzi do VAT-UE. Art. 100 ust. 1 pkt 4 [ustawy o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) obejmuje tylko nabywców zidentyfikowanych na potrzeby VAT.
- Grupowanie po kodzie kraju i numerze VAT UE nabywcy. Wartość to suma `net_pln` po kursie VAT; KOR wchodzi ze znakiem różnicy.
- P_Uc ma typ `etd:TKwotaC`, czyli `xsd:integer`: pełne złote. Sprawdzone 8.10.2026 w XSD [VAT-UE(5)](http://crd.gov.pl/wzor/2021/01/12/10293/schemat.xsd) ([facts.md](../facts.md)).
- Twarda bramka: suma niezaokrąglonych `net_pln` pozycji równa K_12 za ten sam okres, do grosza. Dotyczy każdego złożenia `VAT_UE` i `VAT_UEK`; dla `VAT_UEK` liczy się stan całego miesiąca po korekcie.
- Porównanie idzie z K_12 najnowszego JPK_V7M za ten okres: pierwszego złożenia albo korekty, także w budowie.
- Tolerancja po zaokrągleniu P_Uc do pełnych złotych: najwyżej 0,50 zł × liczba kontrahentów.

**KOR do faktury `eu_b2b`**

| `VAT_UE` za okres VAT korekty (`vat_period_start`) | Złożenie |
| --- | --- |
| brak albo `draft`, `ready`, `approved`, `rejected` | KOR wchodzi do VAT-UE tego miesiąca, ze znakiem w sumie kontrahenta; plik się przebudowuje |
| `submitted`, `unknown` | VAT-UEK po przyjęciu `VAT_UE` |
| `accepted` | VAT-UEK za ten miesiąc, z `supersedes_id` do `VAT_UE` |

- KOR bez `correction_type` (pole opcjonalne, np. KOR z Subject1) blokuje budowę JPK_V7M i VAT-UE do Twojej decyzji o okresie.
- Regułę okresu (TypKorekty) ocenia przegląd doradcy (Q-29). Do tego czasu wybierasz typ przy zatwierdzeniu KOR ([ksef.md](ksef.md)).

### Kanał wysyłki: weryfikacja Q-32

Sprawdzone 8.10.2026 w źródłach MF. Źródła i ich ustalenia są w sekcji „Kanał VAT-UE: źródła” w [facts.md](../facts.md).

**Wniosek.** Ty jako podatnik będący osobą fizyczną składasz VAT-UE i VAT-UEK przez `sendUnsignDocument` z danymi autoryzującymi. Wymóg podpisu kwalifikowanego dotyczy pełnomocnika. Założenie domyślne D-27 się potwierdza; odpowiedź jest w [decisions.md](../decisions.md) (Q-32, D-27).

Do weryfikacji na bramce testowej w F5:

- czy VAT-UE i VAT-UEK z danymi autoryzującymi nie dostają kodu 418 („wymagany podpis kwalifikowany”); 418 daje `rejected` i nowy rekord D-xx zastępujący D-27;
- czy VAT-UEK z danymi autoryzującymi nie dostaje kodu 410 („dokument bez podpisu nie może być korektą”);
- czy bramka testowa z adresu w specyfikacji z 2019 r. działa.

**Wynik F5.6 (15.10.2026, D-68).** Bramka testowa działa. Fikcyjny VAT-UE(5) i VAT-UEK(5) z `DaneAutoryzujace` dopisanymi na końcu korzenia przeszły przez `sendUnsignDocument` (301, potem `requestUPO` 302 i 200) z UPO „e-Deklaracje - system testowy”, `Przyjeto` = true. Bez 418 i 410, więc D-27 zostaje. Bramka testowa nie sprawdza danych autoryzujących z rejestrem (fikcyjne przeszły), więc 410 za niezgodne dane sprawdza dopiero pierwsze złożenie PROD.

### Bramka e-Deklaracji (SOAP)

| `EDEKLARACJE_TARGET` | Adres usługi |
| --- | --- |
| `test` | `https://test-bramka.edeklaracje.gov.pl/uslugi/dokumenty` (WSDL z `?wsdl`); działa, sprawdzone w F5.6 (D-67, D-68) |
| `prod` | host `bramka.e-deklaracje.mf.gov.pl`; pełny adres z produkcyjnego WSDL w konfiguracji |

| Operacja | Wejście | Wyjście |
| --- | --- | --- |
| `sendUnsignDocument` | dokument XML w Base64; opcjonalnie `language` (`pl`, `en`, `de`) i `signatureType` (domyślnie `PIT` = dane autoryzujące) | numer referencyjny (`refId`), status, opis statusu |
| `requestUPO` | `refId`, opcjonalnie `language` | UPO, gdy wygenerowane; status, opis |

| Status `requestUPO` | Znaczenie | Działanie kokpitu |
| --- | --- | --- |
| 100, 101, 102 | błąd komunikatu albo prośba o ponowienie | wysyłki nie ponawia automatycznie; `requestUPO` ponawia odczyt |
| 200 | przetwarzanie poprawne, pobierz UPO; także duplikat z UPO oryginału | `accepted`, zapis UPO |
| 300 | brak dokumentu | `unknown`; `SubmissionReconciler` szuka własnego złożenia o tym samym sha256, bez wyniku wgrywasz UPO jako `manual` ([approval-gate.md](../approval-gate.md)) |
| 301, 302, 303, 306 | dokument w trakcie przetwarzania | dalsze odpytywanie |
| 401, 408, 409 | XSD, błędy przetwarzania, liczba elementów | `rejected` |
| 410 | dokument bez podpisu nie może być korektą | `rejected`; sprawdzić przy VAT-UEK |
| 411 | w systemie jest już dokument z tym identyfikatorem podatkowym; przy PIT-28 zeznanie za ten rok (D-102) | `unknown`; rekoncyliacja jak przy 300 |
| 412, 414 | dane autoryzujące niezgodne albo błędne | `rejected`; poprawa `auth_data` |
| 418 | wymagany podpis kwalifikowany | `rejected`; nowy rekord D-xx |
| 419 | brak celu korekty albo uzasadnienia | `rejected` |
| 422, 423 | dane autoryzujące tylko dla podatnika osoby fizycznej, nie pełnomocnika | `rejected` |
| pozostałe 4xx | m.in. 400, 402–407, 413, 415–417, 420, 421, 424, 425 | `rejected`, kod i opis w `filing.status_code` i `connector_call`; push |

- Dane autoryzujące są te same co w JPK; `Kwota` to przychód z zeznania za rok N−2.
- Hash `approval.payload_sha256` wiąże deklarację bez `DaneAutoryzujace`. Dane autoryzujące nie powstają przed zatwierdzeniem.
- Worker wstawia `DaneAutoryzujace` jako ostatni element `Deklaracja` dopiero przy wysyłce. To ten sam model co metadane JPK z danymi autoryzującymi ([approval-gate.md](../approval-gate.md)).
- `xml_sent` to deklaracja przed wstawieniem `DaneAutoryzujace`. Bajty z danymi istnieją tylko w pamięci workera.
- W `connector_call` zostają tylko `request_sha256` i rozmiar faktycznego żądania, bez treści (tabela „Bajty zapisane a bajty wysłane”).
- Krok 0 F5.5: XSD VAT-UE(5) i VAT-UEK(5) nie deklarują `DaneAutoryzujace` (korzeń kończy się na `Pouczenie`). Deklaracja bez elementu przechodzi XSD przy prośbie; po wstawieniu worker sprawdza w pamięci bajty `xml_sent` względem XSD deklaracji, element względem SIG-2008 i to, że jest jeden i ostatni w korzeniu (D-66). Wstawienie to wklejenie przed zamykającym `</Deklaracja>`, bez ponownej serializacji.
- Klient: `EDeklaracjeSoapClient` (podklasa `SoapClient` bez WSDL, dokument literalny, `trace` = false) wysyła kopertę przez `ConnectorHttpClient`. Przestrzeń nazw `https://bramka.e-deklaracje.mf.gov.pl/xsd`, `SOAPAction` `urn:<operacja>` i adresy usług są w `edeklaracje.soap` i `edeklaracje.endpoints`; to założenie D-66 do potwierdzenia nagraniami F5.6.
- Odpowiedź `sendUnsignDocument` z `refId` daje `submitted` (poza statusami 4xx spoza 300 i 411); bez `refId` ze statusem 4xx albo 100–102 → `rejected`; brak odpowiedzi → `unknown` (`send_no_response`). Ręczne ponowienie tylko bez `refId` albo po 300.
- Kokpit zapisuje `xml_sent` i UPO. `VAT_UEK` ma `supersedes_id` wskazujące `VAT_UE` ([data-model.md](../data-model.md)).
- Do go-live F5 składasz VAT-UE w e-Deklaracjach ([README.md](../README.md)).

## PIT-28

- Od D-96 złożenie `PIT_28` za rok przychodu idzie przez bramkę zatwierdzeń i konektor `edeklaracje`, jak VAT-UE: payload bez `DaneAutoryzujace`, element z `auth_data` wstawia worker przy wysyłce, UPO z `requestUPO`. Schemat PIT-28 dopuszcza dane autoryzujące ([struktury e-Deklaracji](https://podatki-arch.mf.gov.pl/e-deklaracje/dokumentacja-it/struktury-dokumentow-xml/)).
- Generator działa per wariant formularza: wariant dla roku przychodu to opublikowany XSD w `resources/xsd` z manifestem. Bez wariantu dla roku prośba odmawia; pól nie zgaduje się bez XSD.
- Zakres: przychód per stawka z ewidencji, składki społeczne zapłacone w roku, 50% zapłaconej zdrowotnej i wpłacone zaliczki (zestawienie w [tax-rules.md](../tax-rules.md)). Ulgi, załączniki i przychody spoza działalności: składasz ręcznie, złożenie `manual` z UPO ([data-model.md](../data-model.md)).
- Przed prośbą: bramki „przed budową JPK_EWP(4)” roku i przyjęty JPK_EWP za ten rok z tymi samymi sumami per stawka.
- Na PROD prośba wymaga wpisu `pit_28.review` (wariant, data, notatka) z przeglądu doradcy mapowania odliczeń i zaokrągleń (D-04). Najpierw przebieg na bramce testowej z fikcyjnymi danymi.
- Tylko pierwsze złożenie (`CelZlozenia` 1). Korektę składasz ręcznie i zapisujesz jako `manual`.
- Adres zamieszkania (część B) pochodzi z `setting` `taxpayer.residence_address` obowiązującego 31 grudnia roku przychodu; bez wpisu budowa odmawia.

### PIT-28(27): mapowanie pól

Wariant dla przychodu 2025: schemat `PIT-28(27)_v1-1E.xsd` (wzór `2025/10/10/13916`) w `resources/xsd`, `Pit28Builder::VARIANT_27`. Pozycje według formularza PIT-28(27) wersja 07; kolumny stawek działalności: 3%, 5,5%, 8,5%, 10%, 12%, 12,5%, 14%, 15% i 17%.

| Część | Pozycje | Źródło i wzór |
| --- | --- | --- |
| Naglowek | `KodFormularza` `PIT-28` (`kodSystemowy` `PIT-28 (27)`, `kodPodatku` `PPE`, `rodzajZobowiazania` `Z`, `wersjaSchemy` `1-1E`), `WariantFormularza` 27, `CelZlozenia` 1 (`poz` P_6), `Rok`, `KodUrzedu` | `company.identity.tax_office_code` |
| B | `OsobaFizyczna` (NIP, imię, nazwisko, `DataUrodzenia` z `poz` P_10), `AdresZamieszkania` `AdresPol` (`rodzajAdresu` RAD) | `company.identity`, `auth_data` (data urodzenia), `taxpayer.residence_address` |
| D.1 i D.5 | P_26–P_34 i P_52, P_53, P_55–P_57, P_59–P_62 per stawka; P_35 i P_63 razem | przychód roku per stawka z ewidencji (`TaxCalculator::pit28`) |
| D.7 | P_76, P_77, P_79–P_81, P_83–P_86 | udział stawki w P_63 w procentach, do dwóch miejsc, HALF_UP |
| E.1 i E.3 | P_98 i P_104; P_100 = P_63 − P_98 | składki społeczne zapłacone w roku (`zus_payment.social_pln` po `paid_on`), nie więcej niż P_63; `other_pln` poza odliczeniem |
| E.4 i E.5 | P_105 i P_107 | 50% zdrowotnej zapłaconej w roku |
| F.1 | P_109, P_110, P_112–P_114, P_116–P_119 | P_104 × udział z D.7, do grosza HALF_UP, nie więcej niż przychód stawki |
| F.2 | P_121–P_129 | P_107 × przychód stawki ÷ P_35, do grosza HALF_UP, nie więcej niż przychód stawki minus F.1 |
| G i I | P_132–P_142 (bez najmu), P_144; P_173–P_181, P_183 | przychód stawki minus F.1 i F.2; I równa G, bo części H nie ma |
| K | P_197, P_198, P_200–P_202, P_204–P_207 | I w pełnych złotych, HALF_UP (OP art. 63 § 1) |
| L | P_212, P_213, P_215–P_217, P_219–P_222; P_224, P_228, P_230 | stawka × K do grosza HALF_UP; suma |
| N | P_232 | P_230 w pełnych złotych, HALF_UP |
| P | P_236; P_237 albo P_238 | suma `obligation.amount_paid` zaliczek z okresów roku (w pełnych złotych, inaczej odmowa); do zapłaty albo nadpłata |
| Q | P_239–P_250; przy wpłatach co kwartał tylko P_241, P_244, P_247, P_250 | ryczałt okresu tym samym wzorem co zaliczka (`TaxCalculator::advance`) |
| X | P_267 = 1 | `ryczalt.advance_frequency` = `quarterly` na 1 stycznia roku |
| Pouczenia | 1 | stała |

- Puste zostają: C (zwolnienia z art. 21 ust. 1 pkt 152–154 ustawy o PIT), D.2–D.4 i D.6 (spółka, najem prywatny, produkty rolne, art. 17), E.2 (ulgi mieszkaniowe), H i J (zatory płatnicze z art. 11 ust. 4), M i O, R–W (w tym 1,5% dla OPP) i Y (rachunek do zwrotu) oraz załączniki. Zeznanie, które ich potrzebuje, składasz ręcznie.
- Odmowa budowy: stawka 2% albo inna spoza tabeli, ujemny przychód stawki, rok bez przychodu, zaliczki z groszami, brak `taxpayer.residence_address`.
- Udział z D.7 zaokrąglony do setnych procentu daje F.1 według instrukcji formularza („kwota z poz. 104 pomnożona przez udział”); zaliczki w ciągu roku liczą udział bez zaokrąglenia, więc Q nie musi się sumować do N.
- Złoty plik: `tests/Fixtures/Jpk/golden/pit-28-golden.xml` (fikcyjny rok 2025, `Pit28GoldenTest`).

## Korekty

| Plik | Sposób | Zapis w kokpicie |
| --- | --- | --- |
| JPK_V7M | cały plik z `CelZlozenia` = 2 i uzasadnieniem w P_ORDZU | `filing.purpose` = 2, kolejny `seq`, `supersedes_id`; okres `reopened` → `closed` |
| JPK_EWP, JPK_ST | cały rok z `CelZlozenia` = 2 | `filing.purpose` = 2, kolejny `seq`, `supersedes_id`; bez zmiany `period` |
| VAT-UE | VAT-UEK niezwłocznie (art. 101 ustawy o VAT), tylko za miesiąc z przyjętym `VAT_UE` (sekcja „Dane”) | `VAT_UEK` z `supersedes_id` do `VAT_UE` |

Wyzwalacze korekt (KOR w zamkniętym okresie, `DI` → `NrKSeF`, poprawka kursu) zbiera zadanie „korekta wymagana” w [compliance-calendar.md](../compliance-calendar.md).

## Uzgodnienia przed zatwierdzeniem

| Kontrola | Oczekiwanie |
| --- | --- |
| suma niezaokrąglonych `net_pln` każdego `VAT_UE` i `VAT_UEK` a K_12 najnowszego JPK_V7M za ten okres | równe do grosza; tolerancja po zaokrągleniu P_Uc według sekcji „Dane” |
| suma K_8 a suma `revenue_entry` roku | równe ([ewidencja.md](ewidencja.md)) |
| koszty okresu w stanie `imported` | brak |
| faktury sprzedaży z `NrKSeF` a przyjęte faktury okresu | ta sama liczba |

Zgodność sha256 `xml_sent` z `approval.payload_sha256` sprawdza worker przy wysyłce (krok 3 „Przepływu wysyłki”).

## Pliki XSD

Wszystkie schematy są w `resources/xsd`, z sumami kontrolnymi w `checksums.json`, walidowane z `LIBXML_NONET`. Zasady są w [.claude/rules/integrations.md](../../.claude/rules/integrations.md). Komplet JPK i e-Deklaracji jest w repo od 15.10.2026 (D-63).

| Schemat | Plik w `resources/xsd` | Źródło | Importy (pliki bazowe MF) |
| --- | --- | --- | --- |
| JPK_V7M(3) 1-0E | `Schemat_JPK_V7M(3)_v1-0E.xsd` | [crd.gov.pl, wzór 14090](http://crd.gov.pl/wzor/2025/12/19/14090/schemat.xsd); [struktury JPK](https://www.gov.pl/web/kas/struktury-jpk) | `KodyKrajow_v13-0E`, `KodyUrzedowSkarbowych_v8-0E`, `StrukturyDanych_v12-0E` |
| JPK_EWP(4) 1-0 | `Schemat_JPK_EWP(4)_v1-0.xsd` | [struktury JPK w podatkach dochodowych](https://www.gov.pl/web/kas/struktury-jpk-w-podatkach-dochodowych) | `StrukturyDanych_v10-0E` (z `ElementarneTypyDanych_v10-0E`, `KodyKrajow_v10-0E`), `KodyUrzedowSkarbowych_v8-0E` |
| JPK_ST(1) 1-2 | `Schemat_JPK_ST(1)_v1-2.xsd` | [gov.pl, załącznik](https://www.gov.pl/attachment/aa2ea22c-afdf-42bb-a607-8907afd321a6); [struktury JPK w podatkach dochodowych](https://www.gov.pl/web/kas/struktury-jpk-w-podatkach-dochodowych) | jak JPK_EWP(4) |
| VAT-UE(5) 2-0E | `VAT-UE(5)_v2-0E.xsd` | [crd.gov.pl, wzór 10293](http://crd.gov.pl/wzor/2021/01/12/10293/schemat.xsd) | `StrukturyDanych_v6-0E` (z `ElementarneTypyDanych_v6-0E`, `KodyUrzedowSkarbowych_v6-0E`, `KodyKrajow_v6-0E`), `KodyKrajowUE_v3-0E` |
| VAT-UEK(5) 2-1E | `VAT-UEK(5)_v2-1E.xsd` | [crd.gov.pl, wzór 10316](http://crd.gov.pl/wzor/2021/01/26/10316/schemat.xsd) | jak VAT-UE(5) |
| SIG-2008 2-0 | `SIG-2008_v2-0.xsd` | [XSD SIG-2008](https://podatki-arch.mf.gov.pl/media/10553/sig-2008_v2-0.xsd) | brak |
| metadane `InitUpload` 01.02.01.20160617 | `initupload.xsd` | [podatki.gov.pl](https://www.podatki.gov.pl/media/fmdjp0ri/initupload.xsd), z [plików do pobrania MF](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_vat-z-deklaracja/pliki-do-pobrania) | brak |

- Importy wskazują adresy `crd.gov.pl`. Loader walidacji mapuje pełny adres `schemaLocation` z pola `locations` manifestu na plik lokalny; każdy inny adres odpada. Pliki bazowe mają te same nazwy w kilku wersjach (`StrukturyDanych` v6, v10, v12; `KodyKrajow` v6, v10, v13; `ElementarneTypyDanych` v6, v10; `KodyUrzedowSkarbowych` v6, v8), więc o pliku decyduje cały adres, a nazwa pliku w repo zawiera wersję. Adres wskazany przez dwa pliki to błąd manifestu.
- Pliki zostają bajt w bajt jak u źródła. Test `XsdInventoryTest` (job `xsd`) sprawdza sumy, kompiluje każdy schemat główny offline i sprawdza, że każdy import trafia w jeden plik z właściwą przestrzenią nazw.
- `VAT-UE(5)_v2-0E.xsd` i `VAT-UEK(5)_v2-1E.xsd` nie deklarują `DaneAutoryzujace`; walidacja deklaracji i elementu idzie osobno, jak przy `AuthData` (D-66, „Bramka e-Deklaracji (SOAP)”).
- `initupload.xsd` nie deklaruje elementu `AuthData`, który specyfikacja 5.6.0 dodaje dla danych autoryzujących. Kokpit waliduje tym XSD metadane bez `AuthData`, a `AuthData` sprawdza osobno: jeden element, ostatni w `InitUpload`, sam tekst Base64 (D-63). Odpowiedź bramki testowej na `InitUploadSigned` potwierdza to w F5.3 (`jpk-te-fixtures`); kod 140 oznacza nowy rekord D-xx.
- Link `/media/qord0r0j` na podatki.gov.pl ma błędny opis i kokpit go nie używa; schemat metadanych pochodzi z `/media/fmdjp0ri` (D-63).

`filing.schema_version` zapisuje wersję struktury każdego pliku.

## Kryteria odbioru

- Dry run F2.4: część F2.4 w [phases/f2-ewidencja.md](../phases/f2-ewidencja.md); dowody w `docs/evidence/f2.md`.
- Wysyłki F5: [phases/f5-jpk.md](../phases/f5-jpk.md).
