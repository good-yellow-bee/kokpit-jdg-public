---
paths:
  - "src/Connector/**"
  - "src/Xml/**"
  - "resources/xsd/**"
  - "tests/Fixtures/**"
---

# Integracje

Kontrakt konektora, walidacja XSD, zapis wysłanych bajtów i czyszczenie nagrań dla kodu integracji. Stan na 8 października 2026.

Reguła ładuje się przy edycji `src/Connector/**`, `src/Xml/**`, `resources/xsd/**` i `tests/Fixtures/**`. Zasady ogólne są w [zasady-twarde.md](zasady-twarde.md).

## Kontrakt konektora

Konektor to adapter jednego systemu w `src/Connector/<System>`, ze slugiem z kontraktu nazw w [docs/naming.md](../../docs/naming.md). Claude API też jest konektorem (licznik `claude.messages`, D-34). basal i e-Doręczenia są odłożone (D-29).

| Element | Reguła |
| --- | --- |
| Konfiguracja | adres bazowy i wersja API z konfiguracji celu (`*_TARGET`), a dla konektorów bez celu z konfiguracji wdrożenia; nigdy literał w kodzie |
| Sekrety | tylko `SecretStore::get(key, environment)`; tokeny rotujące przez `put` pod lockiem; zero odczytu zmiennych środowiskowych |
| Wyjście | jeden dekorator Symfony HttpClient z `HostAllowlist`; biblioteka KSeF dostaje go przez `Psr18Client`; SOAP przez podklasę `SoapClient` sprawdzającą adres. SDK Claude dostaje klienta PSR-18 przez `Psr18Client` albo Guzzle według [docs/modules/czat.md](../../docs/modules/czat.md). Guzzle tylko z middleware `HostAllowlist`, `CONNECTORS_ENABLED` i `connector_call`. `mail`: transport Symfony Mailer z hostem SMTP z mapy `HostAllowlist`; przy `CONNECTORS_ENABLED=0` transport `null`; każda wysyłka daje wiersz `connector_call` |
| Wyłączenie | przy `CONNECTORS_ENABLED=0` konektor nie wysyła żadnego żądania |
| Odczyt i zapis | osobne metody. Metoda zapisująca w urzędzie ma atrybut `#[OfficialWrite]` (propozycja do [docs/naming.md](../../docs/naming.md)). Reguła PHPStan (test architektury) dopuszcza jej wywołanie tylko z handlera `official_writes` w `src/Approval`. W runtime dekorator sprawdza kontekst wysyłki ustawiany przez handler i poza nim rzuca wyjątek. Atrapa `dummy` leży w `src/Connector/Dummy` (host `dummy.invalid`) i nigdy nie jest włączona przy `APP_ENV=prod` (D-32) |
| Błędy | kody błędów systemu jako wyliczenie konektora; HTTP 200 z błędem w treści to błąd, np. VIES z `actionSucceed: false` |
| Maskowanie | nagłówki i szczegóły przez `Redactor`. Treści żądań i odpowiedzi żadnego konektora nie trafiają do logów ani `connector_call`; zostają hash i rozmiar. Podklasa `SoapClient` nie zapisuje śladu (`trace`) w logach. Treść zostaje tylko tam, gdzie zapisuje ją reguła: `FileStore` (`xml_sent`, `upo`, `evidence`, `verification_raw`) i `chat_message` |
| e-Doręczenia | odłożone (D-29), konektor nie powstaje. Po powrocie F4: `list()` zwraca tylko metadane; `receive()` robi GET tylko po akcji „Odbierz” albo „Pobierz kopię” z panelu ([docs/modules/e-doreczenia.md](../../docs/modules/e-doreczenia.md), [docs/approval-gate.md](../../docs/approval-gate.md)); scheduler i czat wołają wyłącznie `list()` |

**Limity**

- Jedynym mechanizmem limitów jest tabela `rate_counter` z `blocked_until` i `Retry-After` (D-29). `bucket` ma wzorzec `<connector>.<grupa>` z [docs/naming.md](../../docs/naming.md).
- Licznik zwiększasz atomowo w PostgreSQL przed wywołaniem. Tabela jest wspólna dla wszystkich kontenerów; bez liczników w pamięci procesu i w cache plikowym.
- Okna: dobowe od północy Europe/Warsaw i inne limity kalendarzowe, np. dwa liczniki białej listy. Wartości pochodzą z konfiguracji.
- Przy progu zadanie czeka do następnego okna, bez błędu.
- Odpowiedź 429: `Retry-After` trafia do `connector_call.retry_after_s` i `rate_counter.blocked_until`. Do tej chwili konektor nie woła systemu.
- `blocked_until` zapisujesz w wierszu bieżącego okna dobowego (`window_start` = północ Europe/Warsaw). Sprawdzenie blokady czyta `max(blocked_until)` per `(environment, connector, bucket)`.
- Limity KSeF na sekundę, minutę i godzinę nie mają okien przesuwnych. Harmonogram trzyma wolumen pod limitem ([docs/modules/ksef.md](../../docs/modules/ksef.md)), a 429 daje `blocked_until`.
- Odłożone rozszerzenia limitów i warunek powrotu: [docs/roadmap.md](../../docs/roadmap.md).
- Semantyka liczników i push przy limicie: [docs/architecture.md](../../docs/architecture.md). Wartości limitów: moduł konektora.

**Log wywołań**

- Każde wywołanie zostawia jeden wiersz `connector_call`, zapisany raz, po zakończeniu. Dotyczy to też timeoutów i żądań odrzuconych przez `HostAllowlist`.
- Wiersz ma metodę, host, ścieżkę bez parametrów, status, `error_code`, `retry_after_s` oraz sha256 i rozmiar żądania i odpowiedzi.
- `request_sha256` to hash faktycznego żądania, także z danymi autoryzującymi i po szyfrowaniu. Treści takiego żądania kokpit nie zapisuje.
- Zapis w urzędzie niesie `approval_id` i `correlation_id`. Akcje prawne idą osobno do `audit_log` ([docs/security.md](../../docs/security.md)).

**Ponowienia**

| Rodzaj | Messenger | HttpClient |
| --- | --- | --- |
| odczyt, synchronizacja, status | transport `reads`, retry z backoffem | ponowienie dopuszczalne |
| zapis w urzędzie | transport `official_writes`, `max_retries: 0`, jeden konsument | bez `RetryableHttpClient` i bez własnej pętli ponowień |

- Timeout albo odpowiedź niejednoznaczna przy zapisie daje `unknown`. Rozstrzyga `SubmissionReconciler`, tylko odczytem.
- Po każdym kroku API krótka transakcja zapisuje `approval.external_refs`.
- Handler jest idempotentny: sprawdza stan w bazie i przy duplikacie kończy się bez skutku.

## XSD

**Schematy**

- Wszystkie schematy leżą w `resources/xsd`: FA(3) 1-0E z importami, JPK_V7M(3), JPK_EWP(4), JPK_ST(1), VAT-UE(5)_v2-0E, VAT-UEK(5)_v2-1E, PIT-28(27)_v1-1E z importami (D-96), SIG-2008_v2-0 i metadane `InitUpload`.
- Źródła schematów: [docs/modules/ksef.md](../../docs/modules/ksef.md) i [docs/modules/jpk.md](../../docs/modules/jpk.md).
- Manifest w `resources/xsd` podaje dla każdego pliku adres źródła, datę pobrania i sha256. Test sprawdza sumy.
- Manifest to `resources/xsd/checksums.json`: lista wpisów `file`, `source`, `fetched_on`, `sha256` i opcjonalnie `locations`, czyli adresy `schemaLocation`, pod którymi inne schematy importują plik.
- FA(3) 1-0E importuje `StrukturyDanych_v10-0E.xsd` z hosta `crd.gov.pl` ([schemat FA(3)](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/schemy/FA/schemat_FA%283%29_v1-0E.xsd)). Ten schemat dołącza kolejne schematy bazowe.
- `DOMDocument::schemaValidate()` przyjmuje tylko flagę `LIBXML_SCHEMA_CREATE` ([PHP](https://www.php.net/manual/en/domdocument.schemavalidate.php)). Sama flaga `LIBXML_NONET` nie zatrzymuje importu schematu z sieci.

**Walidacja offline**

1. Dokument wczytujesz z `LIBXML_NONET`.
2. Loader z `libxml_set_external_entity_loader` mapuje znane adresy `schemaLocation` na pliki w `resources/xsd`. Dla innego adresu zwraca `null` ([PHP](https://www.php.net/manual/en/function.libxml-set-external-entity-loader.php)). Loader działa w całym procesie, więc ustawiasz go tylko na czas walidacji. W `finally` przywracasz domyślny: `libxml_set_external_entity_loader(null)`. Test sprawdza, że po walidacji `SoapClient` ładuje lokalny plik WSDL.
3. Pliki XSD zostają bajt w bajt jak u źródła, więc sumy zgadzają się z publikacją.
4. Pierwsza sesja z XSD sprawdza testem, że loader obsługuje `xsd:import`. Jeśli nie, przepisujesz `schemaLocation` na ścieżki lokalne i zapisujesz w manifeście obie sumy.
5. Test offline uruchomiony bez sieci waliduje dokumenty z fixtures.

W innych plikach „walidacja z `LIBXML_NONET`” oznacza cały ten mechanizm.

**Wersje**

- Wersja schematu jest zapisana przy dokumencie: `invoice.schema_version` i `filing.schema_version` ([docs/data-model.md](../../docs/data-model.md)). `ksef_session.form_code` służy tylko do kontroli zgodności przy otwarciu sesji (pod tabelą niżej).
- Nowa wersja XSD to nowy plik, nowa suma i przegląd złotych plików.
- Zmiana złotego pliku FA(3) wymaga nowego wpisu przy D-04: data, powód, Twoja akceptacja ([docs/testing.md](../../docs/testing.md)). Przegląd doradcy jest jednorazowy (D-04).

**Kontrole przed wysyłką**

Zgodność z XSD nie oznacza przyjęcia przez system ([weryfikacja faktury KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/weryfikacja-faktury.md)). Kontrole niżej działają przy prośbie o zatwierdzenie, obok XSD.

| Kontrola | Dotyczy |
| --- | --- |
| UTF-8 bez BOM; generator nigdy nie dodaje BOM | każdy dokument |
| bez instrukcji przetwarzania XML | każdy dokument |
| bez znaków niezalecanych | faktury KSeF, według [docs/modules/ksef.md](../../docs/modules/ksef.md) |
| rozmiar w limicie systemu: KSeF 1 MB bez załączników, JPK według [docs/modules/jpk.md](../../docs/modules/jpk.md) | każdy dokument |
| schemat zgodny z formularzem: `invoice.schema_version` wobec `formCode` z konfiguracji KSeF, `filing.schema_version` wobec kodu formularza JPK | każdy dokument |
| suma kontrolna NIP, także na celu testowym | każdy dokument z NIP |
| reguły dziedzinowe: P_1, P_2, scenariusz FA(3), identyfikator nabywcy | faktury, według [docs/modules/ksef.md](../../docs/modules/ksef.md) |

Przy otwarciu sesji KSeF worker porównuje `ksef_session.form_code` z `invoice.schema_version`. Przy niezgodności nie wysyła.

## Wysłane bajty

- Payload powstaje raz: generator, walidacja, zapis przez `FileStore` jako `stored_file` rodzaju `xml_sent`, sha256 do `approval.payload_sha256`.
- `xml_sent` to bajty dokumentu przed danymi autoryzującymi. VAT-UE dostaje `DaneAutoryzujace` dopiero przy wysyłce, JPK `AuthData` w metadanych `InitUpload` ([docs/modules/jpk.md](../../docs/modules/jpk.md)).
- Hash faktycznego żądania trafia do `connector_call.request_sha256` (wyżej). Żądanie z danymi autoryzującymi istnieje tylko w pamięci workera.
- Hash to sha256 dokładnych bajtów, hex małymi literami.
- Worker czyta bajty z `FileStore`, liczy sha256 ponownie i wysyła tylko przy zgodności.
- Ponowna serializacja jest zabroniona. KOD I faktury liczy się z zapisanych bajtów.
- KSeF i JPK: hash wiąże jawny XML przed szyfrowaniem, w JPK także przed kompresją. Szyfrogram z losowym kluczem AES różni się przy każdej wysyłce ([docs/approval-gate.md](../../docs/approval-gate.md)).
- Szyfruje biblioteka KSeF albo konektor JPK. Szyfrogramu nigdy nie zapisujesz w miejsce `xml_sent`.
- Odpowiedzi ze skutkiem prawnym też trafiają do `FileStore`: UPO jako `upo`, surowe odpowiedzi rejestrów jako `verification_raw`. Dowody e-Doręczeń jako `evidence` dopiero po powrocie F4.
- `FileStore` zapisuje raz i nigdy nie nadpisuje. Układ katalogów i zasady zapisu: [docs/data-model.md](../../docs/data-model.md).

## Nagrania

Polityka testów per konektor jest w [docs/testing.md](../../docs/testing.md). Tu są reguły kodu dla `tests/Fixtures`.

- Nagrania leżą w `tests/Fixtures/<Connector>/<scenariusz>`. Złote pliki FA(3) w `tests/Fixtures/Ksef/golden`.
- Nagrywasz komendą `app:fixtures:record <connector> <scenariusz>` przy `CONNECTORS_ENABLED=1` albo ręcznie z danymi wyłącznie fikcyjnymi; oba przez skaner.
- Konektor z `*_TARGET` nagrywasz tylko na celu testowym (`test`).
- Konektory bez środowiska testowego nagrywasz wyłącznie odczytem, przez `rate_counter`. Nigdy z testów automatycznych ani CI.

| Konektor | Gdzie nagrywasz |
| --- | --- |
| `nbp`; `claude` tylko z danymi fikcyjnymi | `kokpit-dev` na laptopie |
| `freshbooks` prawdziwej firmy, tylko odczyt | tylko `kokpit-prod`; komendę uruchamiasz Ty, nie sesja Claude Code |
| `freshbooks` w dev i testach | ręcznie pisane fixtures; firmy testowej nie ma (D-35) |

- Nagranie z `kokpit-prod` przechodzi przez scrubber i skaner przed dodaniem do repo.
- Każdy zapis nagrania przechodzi przez scrubber. Nagrania pisanego ręcznie nie zasilasz prawdziwymi danymi.
- Scrubber zastępuje identyfikatory, dane osobowe i sekrety. Ta sama wartość prawdziwa dostaje w nagraniu tę samą wartość fikcyjną.
- Wartości fikcyjne pochodzą z generatora z poprawną sumą kontrolną i z jednej listy fikcyjnych identyfikatorów. Typy danych do zastąpienia: [docs/testing.md](../../docs/testing.md).
- Skaner fixtures działa w CI, obejmuje złote pliki XML i pada na każdym prawdziwym identyfikatorze albo sekrecie.
- Odtwarzanie HTTP: `MockHttpClient` za tym samym dekoratorem `HostAllowlist` co w produkcji.
- Dopasowanie po metodzie, ścieżce i wybranych parametrach zapytania, nigdy po treści. Losowe klucze AES i znaczniki czasu zmieniają treść przy każdym wywołaniu.
- SOAP (e-Deklaracje): podklasa `SoapClient` z nadpisanym `__doRequest`, czytająca nagranie.
- Claude API: nagrane żądanie i odpowiedź. Test sprawdza warstwę narzędzi, nie tekst modelu.
