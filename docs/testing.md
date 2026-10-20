# Testy

Piramida testów, polityka per konektor, nagrania, złote przypadki, CI, dowód działania i kryteria przekrojowe. Stan na 8 października 2026.

## Zasady

- Testy automatyczne nigdy nie wołają PROD urzędów. Działają na nagranych odpowiedziach albo na środowiskach testowych.
- Na środowiskach testowych wysyłasz wyłącznie dane fikcyjne ([../.claude/rules/zasady-twarde.md](../.claude/rules/zasady-twarde.md)). D-24 dotyczy danych w repo, fixtures i docs.
- Claude API jest konektorem: ma nagrania, licznik i log wywołań ([architecture.md](architecture.md)). Czas pochodzi z Symfony Clock, w testach `MockClock`.
- Plik fazy zawiera kryteria fazy. Ten plik zawiera kryteria przekrojowe, uruchamiane na końcu każdej fazy.
- Faza jest ukończona dopiero z listą `docs/evidence/<phase>.md` (sekcja „Dowód działania”, D-28). Komendy i definicja ukończenia kodu: [../.claude/rules/zasady-twarde.md](../.claude/rules/zasady-twarde.md).
- Moduły odłożone (D-29): e-Doręczenia API (F4) i basal (F7). Ich testy są oznaczone „odłożone” i nie działają do powrotu modułu ([roadmap.md](roadmap.md)).

## Piramida testów

| Warstwa | Co sprawdza | Narzędzia | Gdzie | Job CI |
| --- | --- | --- | --- | --- |
| jednostkowe | `src/Tax`, `src/Ledger`, `src/Xml`, `PolishCalendar`, `Redactor`, scrubber; złote przypadki | PHPUnit 11+, `MockClock` | `tests/Unit` | `unit_property` |
| własności | niezmienniki z „Testów własności” na losowych danych | PHPUnit z generatorem przypadków i zapisanym ziarnem; biblioteka do wyboru w F0a | `tests/Property` | `unit_property` |
| kontraktowe (`@group contract`) | mapowanie żądań i odpowiedzi, kody błędów, limity; OpenAPI KSeF | `MockHttpClient` z plikami i HAR, `Psr18Client`, podklasa `SoapClient` | `tests/Contract` | `contract` |
| XSD | złote pliki i fixtures wobec `resources/xsd`; sumy kontrolne schematów | libxml z `LIBXML_NONET` | `tests/Xsd` | `xsd` |
| integracyjne i panel | migracje, triggery, stany Workflow, Messenger, `chat_ro`; logowanie, CSRF; step-up od F0b | PHPUnit, PostgreSQL, Zenstruck Foundry z generatorem fikcyjnych identyfikatorów, Symfony WebTestCase; Panther albo Playwright tylko przy JavaScript | `tests/Integration` | `integration` |
| architektury | brak `float` w `src/Tax` i `src/Ledger`; zapisy tylko z workera bramki; konektory bez zmiennych środowiskowych | reguły PHPStan | konfiguracja PHPStan | `lint` |
| bezpieczeństwa | „Testy bezpieczeństwa” | PHPUnit, skaner fixtures i sekretów | `tests/Security` | `security` |
| mutacyjne | jakość testów logiki podatkowej, kalendarza i bramki; doradczo do powstania `src/Tax` (D-28) | Infection | `infection.json5` | `mutation` |
| live (`@group live`) | żywe środowiska testowe | PHPUnit | `tests/Live` | `live`, tylko ręcznie |
| akceptacyjne | kryteria fazy i przekrojowe | `kokpit:evidence <phase>` | `tests/Acceptance/<phase>.yaml` | `evidence`, `live` |

Odłożone testy F4 są w [modules/e-doreczenia.md](modules/e-doreczenia.md); nie wchodzą do aktywnych jobów CI ani dowodów faz. Warunek powrotu: [roadmap.md](roadmap.md).

**Podział na joby.** Jeden katalog to jeden testsuite PHPUnit i jeden job. Test leży w dokładnie jednym katalogu, więc działa w dokładnie jednym jobie.

| Testsuite | Katalog | Job |
| --- | --- | --- |
| `unit`, `property` | `tests/Unit`, `tests/Property` | `unit_property` |
| `contract` | `tests/Contract` | `contract` |
| `xsd` | `tests/Xsd` | `xsd` |
| `integration` | `tests/Integration` | `integration` |
| `security` | `tests/Security` | `security` |
| `live` | `tests/Live` | `live` |

- Job uruchamia `vendor/bin/phpunit --testsuite <nazwa>`. Test `CiWorkflow` sprawdza, że każdy job uruchamia swój testsuite.
- Domyślny przebieg `phpunit.xml` pomija testsuite `live` i działa przy `CONNECTORS_ENABLED=0`.
- Grup PHPUnit nie używasz do wyboru testów w jobach. `@group contract` i `@group live` zostają jako etykiety z [naming.md](naming.md).
- `contract` i `xsd` działają bez sieci: schematy leżą w repo z sumą kontrolną, a żądanie spoza nagrania kończy test błędem.

## Polityka per konektor

| `connector` | Środowisko testowe | CI | `@group live` | Dane |
| --- | --- | --- | --- | --- |
| `ksef` | TE `api-test.ksef.mf.gov.pl` ([środowiska](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/srodowiska.md)) | nagrania | TE | losowe NIP z poprawną sumą kontrolną |
| `latarnia` | `api-latarnia-test.ksef.mf.gov.pl` | nagrania stanów AVAILABLE, MAINTENANCE, FAILURE, TOTAL_FAILURE; TE zwraca stałe dane (`te-status.json`, D-70) | host testowy | – |
| `jpk` | `test-e-dokumenty.mf.gov.pl` | nagrania | bramka testowa | fikcyjny zbiór JPK, fikcyjne dane autoryzujące |
| `edeklaracje` | bramka testowa, działanie do sprawdzenia | nagrania przez podklasę `SoapClient` | jeśli bramka działa | fikcyjna deklaracja VAT-UE |
| `vies` | usługa testowa; błędy wracają jako HTTP 200 z `actionSucceed:false` ([VIES test](https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service)) | nagrania, także MS_UNAVAILABLE i TIMEOUT | usługa testowa | numery testowe usługi |
| `white_list` | `wykazpodatnikowvat-test.mf.gov.pl`, dawny `wl-test.mf.gov.pl` ([API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat)) | nagrania; liczniki `white_list.search` i `white_list.check` osobno | host testowy | – |
| `nbp` | brak; PROD tylko do odczytu | nagrania | PROD przez licznik, ręcznie | kursy publiczne |
| `eli` | brak; jeden publiczny host, tylko odczyt | nagrania `tests/Fixtures/Eli` i ręcznie zmienione warianty fikcyjne | `app:legal-watch check` ręcznie, nigdy z testów | dane publiczne, bez danych osobowych (D-75) |
| `mail_in` | brak; dedykowana skrzynka właściciela tylko na PROD | fikcyjne sesje IMAP i wiadomości `tests/Fixtures/MailIn` odtwarzane przez `ScriptedImapStream`; test listy poleceń tylko do odczytu | `app:mail-in:fetch` ręcznie na `kokpit-prod`, nigdy z testów | wyłącznie fikcyjne wiadomości i załączniki (D-92) |
| `freshbooks` | brak sandboxu i firmy testowej (D-35, [FreshBooks API, 8.10.2026](https://www.freshbooks.com/api/start)) | ręcznie pisane fixtures i nagrania oczyszczone | brak; prawdziwa firma tylko odczytem, ręcznie przez `app:fixtures:record` | fikcyjni klienci i faktury |
| `claude` | brak | nagrane odpowiedzi Messages API | tak, z budżetem przebiegu | tylko dane fikcyjne |
| `push`, `mail`, `deadman` | brak | transport i Symfony Mailer w pamięci; ping przez `MockHttpClient` | `[manual]` push na telefon, mail, alert dead-man | – |
| `dummy` | brak; host `dummy.invalid` tylko przez `MockHttpClient` | atrapa konektora zapisującego dla bramki przed F3 | nie | nigdy przy `APP_ENV=prod` (D-32) |

- `edoreczenia` (F4 API) i `basal` (F7) są odłożone (D-29). Do powrotu modułu nie mają nagrań ani testów ([modules/e-doreczenia.md](modules/e-doreczenia.md), [modules/basal.md](modules/basal.md), [roadmap.md](roadmap.md)).
- Konektory `krs`, `ceidg` i `gus` nie powstają (D-20, D-29). Wiersz w [roadmap.md](roadmap.md).
- Zapis zwrotny FreshBooks sprawdzasz pierwszy raz na PROD: jedna faktura przez bramkę, kryterium `[manual]` F3 (D-35).

## Nagrania i odtwarzanie

Nagrania leżą w `tests/Fixtures/<Connector>`. Reguły kodu dla konektorów i fixtures są w [../.claude/rules/integrations.md](../.claude/rules/integrations.md).

| Element | Reguła |
| --- | --- |
| HTTP | `MockHttpClient` Symfony z odpowiedziami z plików albo HAR; ten sam dekorator `HostAllowlist` co w produkcji |
| biblioteka KSeF | dostaje klienta PSR-18 przez `Psr18Client`, więc korzysta z tych samych nagrań |
| SOAP (e-Deklaracje) | podklasa `SoapClient` nadpisuje `__doRequest` i czyta nagranie |
| dopasowanie | metoda, ścieżka i wybrane parametry zapytania; nigdy treść, bo losowe klucze AES i znaczniki czasu zmieniają ją przy każdym wywołaniu |
| nagrywanie | `app:fixtures:record <connector> <scenariusz>` tylko przy `CONNECTORS_ENABLED=1`; konektor z `*_TARGET` tylko na celu innym niż `prod`; konektory bez środowiska testowego (`nbp`, `freshbooks`, `claude`) ręcznie, wyłącznie odczytem, przez `rate_counter`, nigdy z testów automatycznych ani CI; zapis zawsze przez scrubber |
| FreshBooks | fixtures piszesz ręcznie z dokumentacji API; nagranie z prawdziwej firmy tylko odczytem i po scrubberze (D-35) |
| Claude API | nagrane żądanie i odpowiedź; test sprawdza warstwę narzędzi, nie treść odpowiedzi modelu |

**Scrubber**

- Zastępuje NIP, PESEL, IBAN i NRB, adresy ADE, numery KSeF, nazwy, nazwiska, adresy, maile, telefony, tokeny, JWT, nagłówki `Authorization` i bloki certyfikatów.
- Ta sama wartość prawdziwa dostaje w całym nagraniu tę samą wartość fikcyjną, więc relacje zostają.
- Wartości fikcyjne pochodzą z generatora z poprawną sumą kontrolną i z jednej listy fikcyjnych identyfikatorów. Losowe NIP z KSeF TE trafiają na tę listę przed pierwszym nagraniem.
- Skaner fixtures pada na PESEL lub NIP z poprawną sumą spoza listy, IBAN z prefiksem PL, JWT, `Bearer` i klucz PEM. Obejmuje złote pliki XML, `docs/` i paczki dowodów.

## `@group live`

- Uruchamiasz jawnie: `vendor/bin/phpunit --testsuite live` (jeden test: z `--filter`) albo job `live` w CI.
- Każdy test w `tests/Live` ma też `@group live`. To druga zapora obok osobnego testsuite.
- Bootstrap odmawia startu testsuite `live` albo grupy `live`, gdy którykolwiek `*_TARGET` ma wartość `prod` albo `CONNECTORS_ENABLED` nie jest `1`.
- NBP ma tylko PROD: test przez `rate_counter`, tylko ręcznie, nigdy z CI. FreshBooks nie ma testów live (D-35).
- VIES i biała lista wołają wyłącznie usługi testowe z tabeli polityki.
- `workflow_dispatch` działa dopiero, gdy plik workflow jest na gałęzi domyślnej ([GitHub, sprawdzone 8.10.2026](https://docs.github.com/en/actions/writing-workflows/choosing-when-your-workflow-runs/events-that-trigger-workflows)). Job `live` startuje więc dopiero po pierwszym merge F0a do `main`.
- Test live Claude API używa `chat.model` (`claude-haiku-5-5`) i zatrzymuje się po wyczerpaniu budżetu przebiegu w USD.

## KSeF TE

| Krok | Szczegół | Źródło |
| --- | --- | --- |
| 1 | losowe NIP dla testowej JDG i kontrahentów; dane TE są współdzielone między integratorami, bez danych prawdziwych | [środowiska KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/srodowiska.md) |
| 2 | podmioty testowe przez `/testdata/person` albo `/testdata/subject` | [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) |
| 3 | tokeny do odczytu i do wystawiania wygenerowane na TE | [uwierzytelnianie](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/uwierzytelnianie.md) |
| 4 | certyfikat self-signed działa na TE, więc ścieżkę XAdES zapasu (typ 1) testujesz bez wniosku do MF i bez podpisu PZ albo QES | [środowiska KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/srodowiska.md) |
| 5 | drugi sprzedawca testowy z innym losowym NIP wystawia Ci faktury do testów Subject2 | Q-39 w [decisions.md](decisions.md) |
| 6 | sumy kontrolne NIP sprawdza tylko PROD; na TE kokpit wysyła nagłówek `X-KSeF-Feature: subject-identifier-validation` od API 2.8.0 | [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) |
| 7 | limity TE są równe PROD od API 2.5.0 (TEST 06.05.2026) | [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) |
| 8 | nagranie 429 dla testu F1 `KsefRateLimit429`: `app:fixtures:record` po obniżeniu limitów kontekstu przez `POST /testdata/rate-limits`; potem `DELETE /testdata/rate-limits` przywraca domyślne; oba tylko na środowiskach testowych | [open-api](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json), sprawdzone 8.10.2026 |
| 9 | nagranie 21165 dla testu F1 `Ksef21165Retry`: błędu nie wywołasz na żądanie, więc nagranie piszesz ręcznie, wyłącznie z danymi fikcyjnymi | [../.claude/rules/integrations.md](../.claude/rules/integrations.md) |

- Kroki 1–5 wykonuje sesja 1 F1 pomocnikiem w `tests/` przez `/testdata`, tylko na TE i z fikcyjnymi NIP. Bez bramki „stop” na starcie F1: pierwszy wiersz `live` F1 najpierw wytwarza te dane (Q-39, przegląd S7).
- Faktury z TE mają `environment = test` i nigdy nie trafiają do ewidencji ani JPK celu `prod` ([data-model.md](data-model.md)).

## Fikcyjny zbiór JPK

Zbiór w `tests/Fixtures/Jpk` przechodzi tą samą ścieżką kodu co PROD. Różnią się tylko cel i dane autoryzujące ([specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf)).

| Zawartość | Po co |
| --- | --- |
| fikcyjna firma, NIP z generatora, 12 miesięcy | pełny rok JPK_EWP(4); JPK_V7M(3) za okresy od 02.2026 ([modules/jpk.md](modules/jpk.md)) |
| sprzedaż UE B2B (np II), spoza UE (np I), PL B2B z VAT | K_11, K_12 i VAT należny |
| faktury w EUR i USD, KOR w okresie i po okresie | kursy, korekty (`corrects_invoice_id`, `corrects_entry_id`), korekta złożenia po zamkniętym okresie (`filing.supersedes_id`) |
| koszty z KSeF, import usług z UE i spoza UE, dokument WEW, rekordy BFK i DI | K_27–K_30, znaczniki rekordów |
| środek trwały | JPK_ST(1) za flagą |
| ewidencja w dwóch stawkach, korekta i różnica kursowa | K_8, K_9 i `revenue_entry.kind` |
| eksport ewidencji sty–lis 2026 jako CSV z K_1–K_10 i jako XML JPK_EWP(4), z policzonymi K_8 i K_9 | import F2.1 i dry run bez `FxRateProvider` i bez tabel F0b (D-30) |
| VAT-UE z dwoma nabywcami | suma równa K_12 |

Fikcyjne dane autoryzujące są zgodne z nagłówkiem pliku; akceptację na bramce testowej sprawdza F5. Klucz publiczny MF jest osobny dla celu testowego i PROD ([modules/jpk.md](modules/jpk.md)).

## Złote przypadki

Kod musi przejść każdy wiersz. Kwoty i kursy są fikcyjne. Reguły mają dom w pliku z wstępu podsekcji; tu są tylko przypadki.

### Podatki

Reguły: [tax-rules.md](tax-rules.md). Kwota WHT w złotych wchodzi do testów po decyzji Q-29. WHT zagranicznej osoby fizycznej (`counterparty.is_natural_person`, art. 29 ustawy o PIT) nie ma przypadku, bo reguła jest do weryfikacji (Q-21).

| Przypadek | Wejście | Oczekiwany wynik | Źródło |
| --- | --- | --- | --- |
| zaliczka, dwie stawki | przychód 20 000,00 zł (12%) i 5 000,00 zł (8,5%); społeczne 1 600,00; zdrowotna 830,58; udziały 0,8 i 0,2 | odliczenia 1 612,23 i 403,06; podstawy 18 388 i 4 597; zaliczka 2 597 zł | [ustawa o ryczałcie, art. 11 i 12](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf), [OP, art. 63 § 1](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf) |
| zaliczka, odliczenia ponad przychód | przychód 1 000,00 zł (12%); odliczenia 2 015,29 zł | zaliczka 0 zł; 1 015,29 zł przechodzi na kolejny miesiąc | [ustawa o ryczałcie, art. 11](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) |
| zdrowotna, progi | przychód narastająco po społecznych: 59 999,99; 60 000,01; 299 999,99; 300 000,01 zł | 498,35; 830,58; 830,58; 1 495,04 zł | [ustawa o świadczeniach, art. 81 ust. 2g](https://eli.gov.pl/api/acts/DU/2025/1461/text/T/D20251461L.pdf), [ZUS 2026](https://www.zus.pl/en/-/informacja-w-sprawie-podstawy-wymiaru-sk%C5%82adki-oraz-kwoty-sk%C5%82adki-na-ubezpieczenie-zdrowotne-w-2026-r.) |
| pozycja bez stawki | brak wpisu w `ryczalt.service_map` | zatwierdzenie zablokowane; brak wiersza `revenue_entry` | [ustawa o ryczałcie, art. 12 ust. 1](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) |
| import usług z UE; spoza UE | 100,00 EUR, kurs 4,2500; `eu_28b`; `other` | K_29 425,00 i K_30 97,75; K_27 i K_28; w obu K_42 425,00 i K_43 97,75, znacznik BFK | [ustawa o VAT, art. 17 ust. 1 pkt 4, art. 28b, art. 86 ust. 10b pkt 3](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), [broszura JPK_V7M(3)](https://www.podatki.gov.pl/media/wgbkrejs/broszura-jpk_vat-z-deklaracj%C4%85-od-1-lutego-2026-r.pdf) |
| import usług bez faktury; zwolniony | raport opłat bramki płatniczej; `vat_exempt` | dowód `WEW` ze znacznikiem DI; podstawa i podatek 0,00 bez odliczenia | jak wyżej |
| WHT bez certyfikatu albo z wygasłym | wypłata za licencję 15.10.2026; `wht_cert_valid_until` pusty albo przed `paid_on` | 20% kwoty wypłaty; `wht_payment` 7.11.2026 → 9.11.2026; `wht_certificate` przy ustawionym `wht_treaty_country`; `ift_2r` i `cit_10z` za 2026 | [ustawa o CIT, art. 21 ust. 1 pkt 1, art. 26, art. 26a](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) |
| WHT z certyfikatem | certyfikat ważny w dniu `paid_on`; decyzja w `wht_decision_source` | stawka z umowy; `ift_2r` nadal powstaje | [ustawa o CIT, art. 26 ust. 1 i 3d](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) |
| przedpłata kosztu (kredyty API), do potwierdzenia przez doradcę (Q-21, Q-29) | ręczna zmiana `tax_point_date` na dzień zapłaty; raz bez `tax_point_override_reason`, raz z nim | bez powodu odmowa; z powodem okres VAT kosztu z nowej daty | [ustawa o VAT, art. 19a ust. 8](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), [tax-rules.md](tax-rules.md) |

### Czas, daty i kursy

- `MockClock` ustawia chwilę w UTC. Testy sprawdzają dzień w Europe/Warsaw o północy, w dni zmiany czasu i dla P_1 między zatwierdzeniem a wysyłką ([modules/ksef.md](modules/ksef.md), D-16).
- Terminy: [compliance-calendar.md](compliance-calendar.md). `op12`: [OP, art. 12 § 5](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf); `zus`: art. 31 [ustawy o systemie ubezpieczeń społecznych](https://api.sejm.gov.pl/eli/acts/DU/2026/199/text.pdf); `none`: bez przesunięcia.
- Złote przypadki kalendarza mają dom tutaj. Przykłady w sekcji „`PolishCalendar`” w [compliance-calendar.md](compliance-calendar.md) są ich podzbiorem z tymi samymi wartościami.
- Dni wolne obejmują 24 grudnia od 2025 r. ([Dz.U. 2025 poz. 296](https://api.sejm.gov.pl/eli/acts/DU/2025/296/text.pdf)). Wielkanoc: 5.04.2026, 28.03.2027, 16.04.2028. Kursy: [tax-rules.md](tax-rules.md), [modules/rejestry-kursy.md](modules/rejestry-kursy.md).

| `PolishCalendar` i strefa czasu | Oczekiwany wynik |
| --- | --- |
| `op12`: 6.04.2026 i 29.03.2027 (Poniedziałek Wielkanocny); 4.06.2026 i 27.05.2027 (Boże Ciało) | 7.04.2026, 30.03.2027; 5.06.2026, 28.05.2027 |
| `op12`: 15.04.2028 (sobota przed Wielkanocą); 15.06.2028 (Boże Ciało); 24.12.2026 (Wigilia) | 18.04.2028; 16.06.2028; 28.12.2026 |
| `previousWorkingDay`: 7.04.2026; 5.06.2026; 30.03.2027; 29.12.2025; 4.01.2027 | 3.04.2026; 3.06.2026; 26.03.2027; 23.12.2025; 31.12.2026 |
| `MockClock` 2026-10-24T22:30:00Z; 2026-10-25T22:30:00Z | dzień 25.10.2026 w obu przypadkach (UTC+2, potem UTC+1) |
| 2026-03-29T21:59:59Z; 2026-03-29T22:00:00Z; 2027-03-28T22:30:00Z (Wielkanoc) | 29.03.2026; 30.03.2026; 29.03.2027 |
| przypomnienie 8:00 w dni zmiany czasu 29.03.2026 i 25.10.2026 | wysyłka o 06:00Z i 07:00Z; jedna na obowiązek |
| zatwierdzenie faktury 2026-11-02T22:59:59Z, worker 2026-11-02T23:00:01Z | brak wysyłki; `expired`; faktura `ready` bez nowej prośby; jeden push `connector_failure`. „Odnów” 3.11.2026 → prośba z P_1 = 3.11.2026 i nowym sha256, bez push (przegląd S2) |

| Obowiązek (termin nominalny, dzień) | Polityka | `due_date` |
| --- | --- | --- |
| `zus` za 05.2027 (20.06.2027, nd); `health_reconciliation` za 2027 (20.05.2028, so) | `zus` | 21.06.2027; 22.05.2028 |
| `ryczalt_advance` za 11.2026 (20.12.2026, nd); `wht_payment` za wypłatę z 10.2026 (7.11.2026, so) | `op12` | 21.12.2026; 9.11.2026 |
| `vat_jpk_v7m` za 09.2026 (25.10.2026, nd); za 11.2026 (25.12.2026, święto); za 11.2028 (25.12.2028, święto) | `op12` | 26.10.2026; 28.12.2026; 27.12.2028 |
| `vat_ue` za 03.2027 (25.04.2027, nd); `cit_10z` za 2026 (31.01.2027, nd) | `op12` | 26.04.2027; 1.02.2027 |
| `ift_2r` za 2028 (31.03.2029, sobota przed Wielkanocą) | `op12` | 3.04.2029 |
| `jpk_ewp` i `pit_28` za 2026 (30.04.2027, pt); za 2027 (30.04.2028, nd, potem święto 1.05) | `op12` | 30.04.2027; 2.05.2028 |
| `jpk_st` za 2026 przy `jpk_st.enabled` = false | – | `not_applicable` |
| `invoice_issue` za 03.2028 (15.04.2028, so); za 05.2028 (15.06.2028, Boże Ciało); `ledger_entry` za 05.2026 (20.06.2026, so) | `none` | bez zmian |
| `tax_form_choice` (20.02.2027, so); `wht_certificate` (15.08.2027, święto); `edelivery_reply_due` (15.08.2026, święto); `credential_expiry` (25.12.2026, święto) | `none` | bez zmian |
| `manual_channel_check` kanału `ezus` (6.04.2026, Poniedziałek Wielkanocny) | `none` | 6.04.2026; `dedupe_key` `manual_channel_check:2026-04-06:ezus` |

`edelivery_deemed` wymaga API e-Doręczeń, więc jego przypadek wraca z F4 (D-29). `edelivery_reply_due` wpisujesz ręcznie po odbiorze pisma w aplikacji ([compliance-calendar.md](compliance-calendar.md)).

| Kurs NBP: wejście | Oczekiwany wynik | Źródło |
| --- | --- | --- |
| `getRate(EUR, 2.11.2026)`; `getRate(EUR, 29.12.2025)` | tabela z 30.10.2026; tabela 248/A/NBP/2025 z 23.12.2025 | [API NBP, sprawdzone 8.10.2026](https://api.nbp.pl/api/exchangerates/rates/a/eur/2025-12-20/2025-12-31/) |
| data z opublikowaną tabelą; dzień przed datą to dziś bez tabeli; pusty zakres (404) | tabela z poprzedniego dnia roboczego; zadanie czeka; błąd i alert, nigdy starszy kurs po cichu | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |
| usługa 30.10.2026, faktura wysłana 2.11.2026 | kurs PIT i VAT z 29.10.2026 | [ustawa o PIT, art. 11a ust. 1](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf), [ustawa o VAT, art. 31a ust. 1](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| usługa rozliczana okresowo 1–31.10.2026, faktura wysłana 30.10.2026 | VAT z 29.10.2026 (faktura przed obowiązkiem); PIT: przychód 31.10.2026, kurs z 30.10.2026 | [ustawa o VAT, art. 19a ust. 3, art. 31a ust. 2](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), [ustawa o PIT, art. 14 ust. 1e](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) |
| import usług wykonany 15.10.2026 przy `fx.art31a_2a_elected` = true; KOR w EUR | kurs VAT z 14.10.2026; kurs faktury korygowanej | [ustawa o VAT, art. 31a ust. 1 i 2d, art. 31b ust. 1](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| 1 000,00 EUR po 4,2500, wpływ przewalutowany po 4,2800 | `fx_difference` +30,00 zł z `payment_id` | [ustawa o PIT, art. 24c](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) |
| progi w euro na 2027; na 2029 | kurs z 1.10.2026; z 2.10.2028 (1.10.2028 to niedziela) | [ustawa o ryczałcie, art. 4 ust. 2](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) |

### Ewidencja i JPK_EWP(4)

Reguły: [modules/ewidencja.md](modules/ewidencja.md). Struktura: [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf).

| Wejście | Oczekiwany wynik | Źródło |
| --- | --- | --- |
| faktura 1 000,00 EUR w stawce 12%, kurs PIT 4,2500; faktura z pozycjami 12% i 8,5% | wiersz `regular` z K_8 4 250,00 i K_9 12; dwa wiersze, po jednym na stawkę | [ustawa o ryczałcie, art. 15 ust. 12](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) |
| wpis 15.01.2027 za usługę z 20.12.2026; odtworzenie 20.11.2026 faktury ze stycznia | `year` 2026, K_2 15.01.2027, K_3 20.12.2026; K_2 20.11.2026, K_3 ze stycznia, bez antydatowania (D-23) | [rozporządzenie, § 6](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf) |
| płatność EUR 10.01.2027 za fakturę z 20.12.2026, inny kurs | `fx_difference`: `year` 2027, K_3 10.01.2027, K_4 = `payment.freshbooks_payment_id`, K_5 pusty, K_9 stawki źródłowej, K_10 = numer faktury | [ustawa o ryczałcie, art. 6 ust. 1c](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf), [modules/ewidencja.md](modules/ewidencja.md) |
| faktura FreshBooks sprzed przejścia z pozycjami 12% i 8,5%, zapłacona w EUR po innym kursie | dwa wiersze `fx_difference`, po jednym na wiersz źródłowy; różnica dzielona proporcjonalnie do K_8 | [modules/ewidencja.md](modules/ewidencja.md) |
| przyjęta KOR zmniejszająca; `UPDATE` wiersza | wiersz `correction` z ujemnym K_8 i `corrects_entry_id`; błąd bazy | [ustawa o PIT, art. 14 ust. 1m](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) |
| `app:ledger:import import 2026` z CSV z policzonymi K_8 i K_9 | wiersze `source` = `import` z oryginalnymi K_2; zero wywołań `nbp` i `FxRateProvider` (D-30) | [modules/ewidencja.md](modules/ewidencja.md) |
| `app:ledger:import import` dwa razy; `app:ledger:import import` do roku z wierszami innego źródła; `freshbooks` i `ksef_subject1` po imporcie | ta sama liczba wierszy; odmowa; bez odmowy, z deduplikacją | [modules/ewidencja.md](modules/ewidencja.md) |
| JPK_EWP(4) za sty–lis 2026 z fikcyjnego zbioru (`--until`), `pit.kasowy` = false | zgodny z XSD; `LiczbaWierszy` i `SumaPrzychodow` zgodne z wierszami; K_1 rosnące, luki dopuszczalne po filtrze `--until`; brak `Kasowy_PIT` | [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf), [modules/ewidencja.md](modules/ewidencja.md) |
| JPK_EWP(4) za pełny 2026, bez `--until` | K_1 ciągłe od 1, bez luk | [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf) |

### FA(3) i złote pliki

Mapowanie: [modules/ksef.md](modules/ksef.md). Schemat: [FA(3) 1-0E](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/schemy/FA/schemat_FA%283%29_v1-0E.xsd), [broszura FA(3)](https://ksef.podatki.gov.pl/media/nuclnu0w/broszura-informacyjna-dotyczaca-struktury-logicznej-fa-3.pdf). `BrakID` nie występuje w żadnym scenariuszu (D-05).

| Scenariusz | Wejście | Oczekiwane pola | Źródło |
| --- | --- | --- | --- |
| `eu_b2b` | 1 000,00 EUR, VIES `valid` | P_12 `np II`; P_13_9; P_18 = 1; `KodWaluty` EUR; Podmiot2 `KodUE` i `NrVatUE` bez prefiksu; brak P_14_xW; JPK K_11 i K_12; obowiązek `vat_ue` | [ustawa o VAT, art. 28b, art. 100 ust. 1 pkt 4](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| `eu_b2b` z VIES `invalid` albo `unverified_retry` | jak wyżej | prośba i decyzja zablokowane | D-05 |
| `eu_b2b_unidentified`, do potwierdzenia przez doradcę (Q-29) | 1 000,00 EUR, VIES `invalid`, dowód `manual_evidence` statusu przedsiębiorcy | przed potwierdzeniem w `ksef.fa3_review`: prośba i decyzja zablokowane na każdym celu. Po potwierdzeniu: P_12 `np I`; JPK K_11 bez K_12; faktura poza VAT-UE; Podmiot2 według [modules/ksef.md](modules/ksef.md) | D-05; [ustawa o VAT, art. 28a pkt 1 lit. a, art. 100 ust. 1 pkt 4](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| `non_eu_b2b` | 1 000,00 USD, dowód `manual_evidence` | P_12 `np I`; P_13_8; Podmiot2 `KodKraju` i `NrID`; JPK K_11; P_18 według przeglądu doradcy | [ustawa o VAT, art. 28b](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| `pl_b2b` | dwie pozycje po 333,33 zł netto, 23% | P_13_1 666,66; P_14_1 153,33 (z sumy netto, nie 153,34 z pozycji); P_15 819,99; P_18 = 2; Podmiot2 `NIP`; JPK K_19 i K_20 | [ustawa o VAT, art. 106e ust. 11](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), [data-model.md](data-model.md) |
| `pl_b2b` w EUR | 1 000,00 EUR netto, 23%, kurs VAT 4,2500 | P_14_1 230,00; P_14_1W 977,50 | [data-model.md](data-model.md) |
| KOR kwotowa | różnica −200,00 EUR do przyjętej faktury | `RodzajFaktury` KOR; `DaneFaKorygowanej` z `NrKSeF` i `NrKSeFFaKorygowanej`; kurs faktury pierwotnej; zgodny z XSD bez `PrzyczynaKorekty` i `TypKorekty`; oryginał `corrected` | [ustawa o VAT, art. 31b](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| KOR do zera; KOR oryginału spoza KSeF | błędny NIP nabywcy; faktura sprzed przejścia | nowa faktura z `replaces_invoice_id`, oryginał `superseded`; `NrKSeFN`, `corrects_invoice_id` pusty | [modules/ksef.md](modules/ksef.md) |
| okres równoległy F3 | `app:ksef:parallel-compare <rok-miesiąc>` na miesiącu fixtures: faktury FreshBooks połączone z fakturami Subject1, jedna z inną kwotą P_13_1 | raport: zgodne faktury i jedna różnica P_13_1; zero wierszy `approval`, zero wywołań zapisujących | [modules/ksef.md](modules/ksef.md) |

- Jeden złoty plik na wiersz tabeli scenariuszy z [modules/ksef.md](modules/ksef.md), także KOR, w `tests/Fixtures/Ksef/golden`. Zatwierdza je doradca podatkowy przed go-live F3 (D-04).
- Test porównuje bajt po bajcie wynik generatora z plikiem złotym. Działa z `MockClock`, stałym P_2 i NIP z listy fikcyjnych identyfikatorów.
- Wysyłka na TE odbywa się raz na nowy albo zmieniony plik złoty. Idzie testem z `tests/Live` na celu `test`, z P_1 = dziś i nowym P_2 ([modules/ksef.md](modules/ksef.md)).
- Numer KSeF i UPO z tej wysyłki leżą obok pliku złotego, po skanerze fixtures. Lista trafia do dowodu F3.
- Zmiana techniczna pliku złotego (serializacja, biblioteka, wersja schematu): Twoja notatka z datą i powodem przy D-04 w [decisions.md](decisions.md).
- Zmiana wartości mapowanej (P_12, P_18, sumy, pola Podmiot2) przed go-live F3 wchodzi do jednorazowego przeglądu doradcy. Po go-live decydujesz Ty; notatka przy D-04 podaje powód i źródło prawne.

### Bramka zatwierdzeń

Stany i przejścia: [approval-gate.md](approval-gate.md). Testy działają na atrapie `dummy` (F0b, D-32), potem na nagraniach KSeF i JPK. Step-up powstaje w F0b razem z bramką (D-28).

| Stan i zdarzenie | Oczekiwany wynik |
| --- | --- |
| `pending`, „Zatwierdź” ze świeżym step-up; bez niego; dwa kliknięcia równolegle | `approved`, `expires_at`, `approval.approved`, przy fakturze `invoice_number_locked_at`; odmowa; jedno przejście |
| `pending` albo `approved`, zmiana źródła | `superseded` z `superseded_by_id`; nowe `pending`; push `approval_pending` |
| `approved`, payload zmieniony na dysku | `cancelled` z `hash_mismatch`; zero wywołań zapisujących; push |
| `pending` faktury, `MockClock` po północy | `expired`, faktura `ready` bez żywego zatwierdzenia; zero nowych prośb, plików `xml_sent` i push; „Odnów” → nowe `pending` z dzisiejszym P_1, bez push (przegląd S2) |
| `approved`, `MockClock` po `expires_at`; Latarnia w MAINTENANCE | `expired`, faktura wraca do `ready` bez nowej prośby, push `connector_failure`; zostaje `approved` z `approval.dispatch_waiting`; w obu zero wywołań |
| `pending` albo `approved` faktury; sync Subject1 przynosi fakturę o tym samym `kind` i P_2 | `cancelled` z `manual_invoice`; szkic `cancelled_before_send`; zero wywołań zapisujących |
| odtworzenie z backupu: `approved` w odtworzonej bazie | `unknown` z wpisem `approval.marked_unknown`; zero wywołań zapisujących; rozstrzyga tylko `SubmissionReconciler` |
| „Doręcz klientowi” (F3) | jeden e-mail konektorem `mail` w żądaniu panelu; zero wierszy `approval` i zero wiadomości Messenger (D-31) |
| `approved`, dwa workery albo duplikat wiadomości | jedno wywołanie zapisujące |
| `dispatching`, timeout; potem status KSeF z numerem i zgodnym `invoiceHash` | `unknown` bez ponowień, rekoncyliacja tylko odczytami; `accepted` |
| ręczne ponowienie, KSeF 440 ze zgodnym `invoiceHash`; z innym | `accepted` z numerem oryginału; `rejected` i alert kolizji P_2 |
| JPK 170 przy wysyłce; 407 po `submitted`; 411; po 411 wgranie UPO ze step-up | `accepted` z UPO oryginału; `accepted`; `unknown`; `cancelled` z `manual_filing` i złożenie `manual` |
| `draft` `approved`, „Skopiowano” | `accepted` bez konektora; szkic `executed`; wpis `draft.executed` |
| `messenger:failed:retry` na `official_writes` | zero wywołań zapisujących |

### e-Doręczenia: `list()` nigdy nie odbiera (odłożone, D-29)

Przypadki i dawne `cc-27` są w [modules/e-doreczenia.md](modules/e-doreczenia.md). Nie są aktywnymi kryteriami przekrojowymi, nie działają w CI ani w `kokpit:evidence` i nie blokują odbioru obecnych faz. Powrót: [roadmap.md](roadmap.md).

## Testy własności

Każdy test zapisuje ziarno. Ziarno z porażki staje się nowym przypadkiem w `tests/Unit`.

| Właściwość | Generator | Oczekiwanie |
| --- | --- | --- |
| przesunięcie `op12`, `zus`, `none` | daty 2026–2030 | dzień roboczy, nie wcześniej niż data, najwyżej 5 dni później (najdłuższa przerwa 22–26.12.2029); ponowne przesunięcie bez zmian; `none` zwraca datę |
| `previousWorkingDay`, dzień biznesowy | daty i chwile UTC 2026–2030 | dzień roboczy wcześniejszy, bez dnia roboczego pomiędzy; dzień = data w Europe/Warsaw, niemalejący z chwilą |
| kwoty FA(3) i podział proporcjonalny | pozycje `BigDecimal` w stawkach 23, 8, 5, 0, np; różnice kursowe i odliczenia na kilka stawek | P_13_x = suma netto stawki; P_15 = suma P_13_x i P_14_x; suma części równa całości co do grosza |
| zaliczka | przychody i odliczenia | podstawy i zaliczka ≥ 0; odliczenia użyte plus przeniesione = odliczenia |
| JPK_EWP(4) | losowe wiersze ewidencji | `LiczbaWierszy` i `SumaPrzychodow` zgodne; K_2 niemalejące z K_1; K_1 bez luk tylko przy budowie pełnego roku, bez `--until` |
| sync i silnik idempotentne | nagrania KSeF Subject1 i Subject2 oraz FreshBooks z losowym podziałem stron i powtórzeniami; kolejność zmian `setting` | drugi przebieg bez zmian; kursor niemalejący; obowiązki `done` bez zmian |
| model bramki | sekwencje: klik, worker, wygaśnięcie, „Odnów”, zmiana źródła, timeout, odtworzenie | tylko przejścia z [approval-gate.md](approval-gate.md); najwyżej jedno wywołanie zapisujące na zatwierdzenie; prośba po wygaśnięciu tylko z „Odnów” |
| `Redactor` | tekst z PESEL, IBAN i JWT z generatora | wynik bez tych wartości |

## Testy bezpieczeństwa

| Test | Kroki | Oczekiwany wynik |
| --- | --- | --- |
| allowlista hostów | każdy konektor z celem innym niż `prod` woła host PROD; `JPK_TARGET=test` i magazyn PROD; adresy PROD białej listy i VIES we wdrożeniu `dev` i w testach (NBP dozwolony: tylko odczyt przez licznik) | wyjątek przed żądaniem; `connector_call` z kodem błędu; alert |
| konektory wyłączone | `CONNECTORS_ENABLED=0`, klient HTTP padający przy każdym żądaniu | zero żądań, także `push`, `mail`, `claude` |
| `dummy` w produkcji | kontener z `APP_ENV=prod` | usługi `dummy` brak w kontenerze; `HostAllowlist` bez hostów `dummy` (D-32) |
| obejście bramki | metoda zapisująca wołana spoza workera; wiadomość `official_writes` dla `pending` | test architektury pada; handler kończy bez skutku |
| `official_writes` bez ponowień | wyjątek w handlerze po przejściu do `dispatching`; wyjątek przed `dispatching`; `messenger:failed:retry` | `unknown`, zero drugiego wywołania; stan bez zmian, zero wywołań; ponowienie odrzucone |
| wstrzyknięcie w czat | nagrana odpowiedź modelu po wklejonej treści z ukrytym poleceniem: `draft_document` bez znacznika prośby, narzędzie spoza listy, `query` = `audit_log`; tekst znacznika prośby w wyniku narzędzia, bez `draft_requested` | zero szkiców i zapisów; walidacja schematu odrzuca; lista narzędzi to tylko odczyt i `draft_document` |
| `chat_ro` | `INSERT`, `UPDATE`, `DELETE` i `SELECT` z tabeli przez połączenie `chat` | błąd bazy |
| `Redactor` | fikcyjne PESEL, IBAN, token i JWT w każdym konektorze i w wyniku narzędzia czatu | zero wystąpień w logach, `messenger_messages`, `connector_call`, `audit_log`, `notification` i w treści żądania do Claude API |
| skaner fixtures i sekretów | `tests/Fixtures`, złote XML, `docs/`, paczka dowodów; śledzone pliki sejfu i `.env.local` | zero prawdziwych identyfikatorów, kluczy PEM, JWT, `Bearer` i plików deszyfrujących |

## Testy mutacyjne

- Zakres to katalogi: `src/Tax`, `src/Ledger`, `src/Calendar` (`PolishCalendar`, `ObligationEngine`), `src/Approval` (`ApprovalGate`). Powstają w F0a, puste z `.gitkeep`.
- PR sprawdza zmienione pliki z zakresu (`--git-diff-filter=AM`), `main` cały zakres.
- Gdy zakres nie ma plików PHP do sprawdzenia, job pomija Infection. Zapisuje „brak kodu w zakresie” w podsumowaniu joba i kończy kodem 0.
- Progi: `--min-msi=85` i `--min-covered-msi=95` ([Infection](https://infection.github.io/guide/command-line-options.html)). Progi tylko rosną; obniżenie wymaga wpisu D-xx.
- Doradczo do powstania `src/Tax` w F2 (D-28). Bez pliku PHP w `src/Tax` job uruchamia Infection bez progów, zapisuje MSI i kończy kodem 0. Od pierwszego pliku w `src/Tax` progi blokują merge.
- Stan 2026-10-20 (D-103): obraz `development` nie ma sterownika pokrycia, więc Infection w CI kończy się błędem i job jest czerwony; zostaje doradczy, a progi nie blokują merge do zmiany z pomiarem.
- Ocalały mutant to nowy test albo wpis ignorowania z uzasadnieniem w `infection.json5`. Raport JSON trafia do paczki dowodów.

## CI

GitHub Actions w prywatnym repo `good-yellow-bee/kokpit-jdg`. Żaden job nie ma sekretów celu `prod` ani klucza sejfu PROD.

| Job | Zakres | Blokuje merge |
| --- | --- | --- |
| `lint` | `php-cs-fixer --dry-run`, `phpstan` z regułami architektury, lista dozwolonych licencji Composer | tak |
| `unit_property` | testsuite `unit` i `property` (`tests/Unit`, `tests/Property`) | tak |
| `contract` | testsuite `contract` (`tests/Contract`): nagrania i OpenAPI KSeF; bez sieci | tak |
| `xsd` | testsuite `xsd` (`tests/Xsd`) z `LIBXML_NONET`; sumy kontrolne `resources/xsd` | tak |
| `integration` | testsuite `integration` (`tests/Integration`) z PostgreSQL jako kontener usługi | tak |
| `security` | testsuite `security` (`tests/Security`), skaner fixtures i sekretów | tak |
| `mutation` | Infection według „Testów mutacyjnych”; pusty zakres pomija Infection; bez `pull_request`, póki doradczy (D-43) | nie do powstania `src/Tax` (D-28), potem tak |
| `evidence` | po pozostałych: `kokpit:evidence <phase> --only=ci`, upload `var/evidence/**` jako artefakt przebiegu; pomijany przy push do `main` i na gałęziach bez prefiksu fazy (D-43) | tak |
| `live` | tylko `workflow_dispatch` z wymaganym wejściem `phase`; testsuite `live` na celach testowych, potem `kokpit:evidence <phase> --only=live` i upload paczki; przed testami certyfikat testowy MF ze zmiennej repozytorium `MF_TEST_CERT_PEM` w `var/jpk/mf-test.crt` (D-97); paczka także po czerwonym teście (D-102); klucz sejfu testowego jako sekret środowiska GitHub; nigdy PROD | nie; faza z kryteriami live wymaga udanego przebiegu |

- Wyzwalacze (D-43): `push` tylko na `main`, gałąź PR przez `pull_request`; nowy push do PR anuluje starszy przebieg.
- Id joba to `unit_property` z `name: unit+property` (D-34). GitHub dopuszcza w id litery, cyfry, `-` i `_` ([GitHub](https://docs.github.com/en/actions/writing-workflows/workflow-syntax-for-github-actions)).
- Faza przy `push` pochodzi z prefiksu gałęzi przed pierwszym `-` (`f0a-szkielet` → `f0a`). Przy `pull_request` z gałęzi źródłowej PR, przy `workflow_dispatch` z wejścia `phase`.
- Push do `main` pomija `evidence`. Brak fazy na innej gałęzi przerywa job.
- Job `evidence` nie czyta `docs/evidence/<phase>.md`. Listę piszesz ręcznie i sprawdzasz Ty (D-28).
- Wymuszenie zielonego CI przed merge zależy od planu GitHub (Q-43). Domyślnie bez rulesetu: merge tylko przy zielonym CI na PR, sprawdzany ręcznie ([GitHub](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/about-rulesets)).

**Kolejność odbioru fazy**

1. Push gałęzi fazy: wymagane joby zielone; artefakt joba `evidence` z paczką `ci`.
2. Przy kryteriach live: przebieg `live` przez `workflow_dispatch` na gałęzi fazy; działa po pierwszym merge F0a („`@group live`”).
3. Wiersze `[manual]`: artefakt kładziesz w `var/evidence/<phase>/inbox/` i uruchamiasz lokalnie `kokpit:evidence <phase> --only=ci`. Paczka lokalna dołącza pliki z `inbox/` z sha256.
4. Piszesz `docs/evidence/<phase>.md` („Lista dowodów w repo”); commit tylko `docs/`, na Twoje polecenie.
5. PR do `main` i merge przy zielonym CI.

## Dowód działania

Dowód to raport JUnit i artefakty z CI oraz ręcznie pisana lista `docs/evidence/<phase>.md` (D-28). Każdy wiersz kryteriów ma kolumnę „Dowód”.

### Paczka dowodów

`bin/console kokpit:evidence <phase>` powstaje w F0a. Uruchamia w kontenerze `app` komendy wierszy `ci` albo `live` fazy i kryteriów przekrojowych tej fazy. Wiersze `[manual]` wykonujesz Ty; ich pliki przychodzą przez `inbox/`.

| Element | Reguła |
| --- | --- |
| rejestr | `tests/Acceptance/<phase>.yaml`: klucz, komenda, asercja, artefakty, wykonawca; bez testu zgodności z tabelą pliku fazy (D-28) |
| klucz | slug z kolumny „Kryterium” pliku fazy (np. `calendar-golden`); kryterium przekrojowe: `cc-NN` (D-33); ten sam klucz w `manifest.json` i w liście dowodów |
| wykonawca (`runner`) | `ci`: komenda w kontenerze `app`, w CI job `evidence`; `live`: testsuite `live` w jobie `live`; `manual`: krok człowieka, urządzenia albo hosta PROD z plikiem w `inbox/` |
| `inbox/` | `var/evidence/<phase>/inbox/` na laptopie; nazwa pliku zaczyna się kluczem wiersza; każdy przebieg komendy kopiuje pliki do `manual/` z sha256 |
| katalog | `var/evidence/<phase>/<YYYYMMDD-HHMM>/` (czas Europe/Warsaw); `var/` jest w `.gitignore`; w CI artefakt przebiegu jobów `evidence` i `live` |
| zawartość | `manifest.json`, `junit.xml`, `mutation.json` (raport Infection) i podkatalogi z tabeli niżej; artefakt może należeć do kilku wierszy, a manifest wymienia go przy każdym |
| skrót w kolumnie „Dowód” | ścieżka bez katalogu, poza `junit.xml`, `manifest.json` i `mutation.json`, oznacza `cmd/`; dopisek `(inbox)` oznacza `manual/` |
| `manifest.json` | `phase`, `created_at`; `commit` (brudne drzewo unieważnia paczkę); `ci_run`; `targets`: `*_TARGET`, `CONNECTORS_ENABLED`, `APP_ENV`, wdrożenie; `tools`: wersje PHP, Symfony, PHPUnit, PHPStan, Infection, PostgreSQL, libxml, biblioteki KSeF, sumy XSD i digest obrazu `app`; `criteria[]`: `key`, `runner`, `result`, `artefacts[]` ze ścieżką i sha256 |
| wynik | `pass`, `fail` albo `pending`; `pending` to wiersz `manual` bez pliku w `inbox/`; kod wyjścia różny od 0 tylko przy `fail` |
| maskowanie | artefakty tekstowe przechodzą przez `Redactor`, potem skaner fixtures; trafienie przerywa komendę bez paczki; zrzuty tylko z celów testowych albo zamaskowane |
| opcje | `--only=ci` albo `--only=live` ogranicza uruchamiane komendy do wierszy wykonawcy; bez `--only` wiersze `ci` i `live`; pliki `manual` dołącza każdy przebieg; paczki z CI pobierasz przez `gh run download` |
| przebiegi | `ci`: job `evidence`; `live`: job `live`; lokalnie `--only=ci` z plikami w `inbox/` daje paczkę z artefaktami `manual` |

| Podkatalog paczki | Zawartość |
| --- | --- |
| `cmd/` | wyjście komendy po `Redactor` |
| `db/` | zrzut wierszy utworzonych przez kryterium, CSV |
| `logs/` | wycinek logu po `Redactor` |
| `snapshots/` | identyfikator zapytania rejestru i sha256 surowej odpowiedzi |
| `golden/` | różnica wobec złotego przypadku; pusty plik oznacza zgodność |
| `screens/` | zrzut panelu z testu panelu |
| `ksef/` | numery KSeF i UPO z api-test, sha256 wysłanych bajtów |
| `jpk/` | XML JPK z fikcyjnego zbioru, wynik XSD, UPO z test-e-dokumenty, sha256 wysłanych bajtów |
| `manual/` | pliki z `inbox/`: kroki `[manual]` |

### Lista dowodów w repo

`docs/evidence/<phase>.md` piszesz ręcznie na koniec fazy (D-28). Generatora listy nie ma. Commit tylko na Twoje polecenie.

- Jeden wiersz na kryterium fazy i na kryterium przekrojowe tej fazy: klucz → pass albo fail → artefakt.
- Artefakt to przypadek z `junit.xml`, plik z artefaktu przebiegu CI albo plik `manual/` z sha256.
- Wiersz `[manual]` odhaczasz Ty, z datą. Dowód bez pliku to odhaczony wpis listy kontrolnej, np. karta awaryjna.
- F2 ma jedną listę `docs/evidence/f2.md`. Wiersze F2.1 i F2.4 mają pass przed dry runem; lista zamyka się po F2.2/F2.3 (D-30).

```markdown
# Dowód działania: f0a

| Pole | Wartość |
| --- | --- |
| Data, commit | 2026-11-20, <sha> |
| Przebieg CI; live | <adres przebiegu push z artefaktem evidence>; <adres przebiegu live albo „brak kryteriów live”> |
| Paczka lokalna z `inbox/` | f0a/20261120-2215, sha256 `manifest.json` <…> |

| Kryterium | Wynik | Artefakt |
| --- | --- | --- |
| `cc-01`: konektory domyślnie wyłączone | pass | junit.xml: ConnectorsDisabledTest |
| `push-test`: push na telefon [manual] | [x] pass, 2026-11-20, Ty | manual/push-test.png, sha256 <…> |

Otwarte [manual]: brak.
```

### Definicja ukończenia fazy

1. Wymagane joby CI są zielone na commicie z listy. Artefakt joba `evidence` obejmuje każdy wiersz `ci` fazy i przekrojowy tej fazy, bez `fail`.
2. Faza z kryteriami live ma udany przebieg `live` z artefaktami z celów testowych.
3. Każdy wiersz `[manual]` ma artefakt albo odhaczony wpis listy kontrolnej oraz Twoje odhaczenie.
4. `docs/evidence/<phase>.md` jest w repo, każdy wiersz ma pass, a status fazy w [README.md](README.md) go linkuje.
5. Spełniona jest definicja ukończenia z [../.claude/rules/zasady-twarde.md](../.claude/rules/zasady-twarde.md).

Bez listy faza nie jest ukończona, także przy zielonym CI.

## Konwencje kryteriów odbioru

| Zasada | Przykład |
| --- | --- |
| kolumny: Kryterium, Komenda / kroki, Oczekiwany wynik, Dowód | `cc-02` → `phpunit --filter HostAllowlist` → wyjątek dla hostów PROD → `junit.xml` |
| klucz wiersza fazy to identyfikator z kolumny „Kryterium” pliku fazy: slug kebab-case w backtickach na początku komórki, stały i unikalny w fazie; `cc-NN` tylko dla kryteriów przekrojowych (D-33) | `calendar-golden`: złote przypadki; `cc-02` |
| „Dowód” nazywa artefakt ścieżką w paczce | `cmd/cc-07.txt`, `manual/push-test.png`; przypadek `junit.xml`, wynik komendy, numer KSeF i UPO z api-test, UPO JPK z test-e-dokumenty, zrzut panelu, wycinek logu z zamaskowanymi danymi, sha256 wysłanych bajtów |
| krok człowieka albo strony zewnętrznej ma `[manual]` i też dowód | zrzut albo odhaczony wpis listy kontrolnej w liście dowodów |
| kryterium nie zależy od zachowania modelu | wstrzyknięte polecenie sprawdza walidacja schematu narzędzi |
| kryterium da się spełnić na końcu fazy | pierwszy miesiąc na PROD należy do checklisty go-live, nie do kryteriów |
| kryterium działa na celu testowym | go-live PROD ma osobną checklistę w [operations.md](operations.md); wyjątek z decyzji: zapis zwrotny FreshBooks w F3 jako `[manual]` na PROD (D-35) |

Klucze F0a, F0b i F1 przechodzą na slugi jedną zmianą mechaniczną (D-33). Klucze `fX-NN`, wynik `manual_pending`, opcje `--attach` i `--summary`, wykonawca `host` i komenda `kokpit:demo` są wycofane ([naming.md](naming.md)).

## Kryteria przekrojowe

Nawias przy kluczu podaje fazę albo część F2, od której kryterium obowiązuje. „Od” liczy się w kolejności realizacji z D-30, nie według numeru fazy. Niespełnione kryterium blokuje odbiór.

| Odbiór | Kryteria z nawiasem |
| --- | --- |
| F0a | F0a |
| F2.1 i F2.4, przed dry runem | F0a, F2.1, F2.4 |
| F0b | F0a, F2.1, F2.4, F0b |
| F1 | jak F0b oraz F1 |
| F2, po F2.2/F2.3 | jak F1 |
| F3, F5, F6 | wszystkie z faz wcześniejszych i własne |

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `cc-01` (F0a) konektory domyślnie wyłączone | test z pustym środowiskiem i klientem HTTP padającym przy każdym żądaniu | zero żądań | `junit.xml` |
| `cc-02` (F0a) allowlista hostów | `phpunit --filter HostAllowlist` | każdy host PROD przy celu testowym rzuca wyjątek | `junit.xml` |
| `cc-03` (F0a) brak publicznych portów | `[manual]` `docker compose config` na VPS | porty opublikowane tylko na adresie VPN | `manual/cc-03.txt` |
| `cc-04` (F0a) `/health` | `[manual]` żądanie przez VPN | 200 i JSON bez danych osobowych | `manual/cc-04.txt` |
| `cc-05` (F0a) dead-man | `[manual]` zatrzymanie usługi `scheduler` | alert z zewnętrznego serwisu w czasie zapasu | `manual/cc-05.png`: zrzut alertu |
| `cc-06` (F0a) passkey i CSRF | WebTestCase: żądanie bez sesji; POST bez tokenu CSRF | logowanie; odrzucenie | `junit.xml` |
| `cc-07` (F0a) maskowanie | test z fikcyjnym sekretem i PESEL | zero wystąpień w logach, `messenger_messages`, `connector_call`, `audit_log`, `notification` | `junit.xml`, `cmd/cc-07.txt` z liczbą trafień |
| `cc-08` (F0a) log audytu | `UPDATE` na `audit_log` rolą aplikacji; `app:audit:verify` | błąd bazy; łańcuch poprawny | `junit.xml`, `cmd/cc-08.txt` |
| `cc-09` (F0a) `official_writes` | test konfiguracji Messenger | `max_retries: 0` i jeden konsument | `junit.xml` |
| `cc-10` (F0a) strefa czasu | testy strefy czasu z „Czas, daty i kursy” | dzień w Europe/Warsaw | `junit.xml` |
| `cc-11` (F0a) fixtures czyste | skaner fixtures i sekretów | zero trafień | `cmd/cc-11.txt` |
| `cc-12` (F0a) licencje | sprawdzenie licencji Composer z listą dozwolonych | brak GPL | `cmd/cc-12.txt` |
| `cc-13` (F0a) analiza statyczna i testy | `phpstan` z regułami architektury, `php-cs-fixer --dry-run`, `phpunit`; wymagane joby CI | zielone | `junit.xml`, `cmd/cc-13.txt`, adres przebiegu CI |
| `cc-14` (F0a, potem raz na kwartał) backup | `[manual]` odtworzenie w jednorazowym projekcie lokalnym (`KOKPIT_DEPLOYMENT=restore`, D-28) poleceniem `scripts/restore-test.sh` (D-89); `app:files:verify`, `app:audit:verify` z kotwicami i liczby z PROD na kopii | hashe plików, łańcuch `audit_log`, obie kotwice i liczby zgodne (`passed` w raporcie) | `manual/cc-14.txt` z raportem `var/evidence/restore/kokpit-restore-*.json`, wpis `ops.restored`, odhaczony wpis |
| `cc-15` (F0a) paczka dowodów | `bin/console kokpit:evidence <phase> --only=ci` w jobie `evidence` | `manifest.json` z każdym wierszem `ci` i `manual` fazy; `pending` przy `manual` bez pliku; skaner bez trafień | `manifest.json` i jego sha256 |
| `cc-16` (F0b) step-up | zatwierdzenie bez świeżej asercji passkeya | odmowa | `junit.xml` |
| `cc-17` (F0b) hash payloadu | payload zmieniony po zatwierdzeniu | `cancelled` z `hash_mismatch`; zero wywołań urzędu | `junit.xml` |
| `cc-18` (F0b) podwójna wysyłka | dwa kliknięcia i dwa workery na jednym zatwierdzeniu | jedno wywołanie zapisujące | `junit.xml` |
| `cc-19` (F0b) wygaśnięcie | `MockClock` po `expires_at` | brak wysyłki, stan `expired`; nowa prośba tylko po „Odnów” (przegląd S2) | `junit.xml` |
| `cc-20` (F0b) zapisy tylko z bramki | reguły architektury PHPStan | metody zapisujące wołane wyłącznie z workera bramki | `cmd/cc-13.txt`, wspólny z `cc-13` |
| `cc-21` (F0b) rekoncyliacja | konektor zapisujący (`dummy`, od F3 KSeF, od F5 JPK) zwraca timeout | `unknown`; `SubmissionReconciler` tylko odczytem; zero ponownych zapisów; `messenger:failed:retry` nie ponawia `official_writes` | `junit.xml` |
| `cc-22` (F0b) kalendarz | testy `PolishCalendar` i testy własności | przesunięcia z „Czas, daty i kursy” | `junit.xml` z ziarnem |
| `cc-23` (F0b) mutacje | Infection na zakresie z „Testów mutacyjnych” | raport MSI; od pierwszego pliku w `src/Tax` MSI i covered MSI co najmniej na progach (D-28) | `mutation.json`: raport JSON Infection |
| `cc-24` (F1) kurs NBP | testy na nagraniach NBP | 2.11.2026 → tabela z 30.10.2026; data z opublikowaną tabelą → tabela z poprzedniego dnia roboczego | `junit.xml` |
| `cc-25` (F2.1) XSD offline | walidacja z `LIBXML_NONET` bez sieci | dokumenty z fixtures zgodne | `junit.xml` z joba `xsd` |
| `cc-26` (F2.4) rozdział środowisk | plik do urzędu dla celu testowego | zero wierszy z `environment = prod` | `junit.xml` |
| `cc-28` (F6) czat tylko do odczytu | `UPDATE` przez połączenie `chat` | błąd bazy | `junit.xml` |
| `cc-29` (F6) wstrzyknięcie we wklejonej treści | nagrana odpowiedź modelu z `draft_document` bez znacznika prośby i z narzędziem spoza listy | zero szkiców i zapisów; walidacja odrzuca | `junit.xml` |
| `cc-30` (F6) kontekst modelu | treść żądania do Claude API w `MockHttpClient` | brak PESEL, IBAN i tokenów | `junit.xml` |
| `cc-31` (F0a) kwoty bez `float` | reguła architektury PHPStan | brak `float` w `src/Tax` i `src/Ledger` | `cmd/cc-13.txt`, wspólny z `cc-13` |

## Dawne kryteria odbioru

Kryteria z [archive/SPEC-2026-10-05.md](archive/SPEC-2026-10-05.md) mają nowe miejsca.

| Dawne kryterium | Teraz |
| --- | --- |
| Faza 0: compose, push, sekrety z sejfu, wpis w `audit_log` po każdym zadaniu | [phases/f0a-szkielet.md](phases/f0a-szkielet.md) i kryteria przekrojowe; wywołanie zewnętrzne zostawia `connector_call`, akcja z listy zostawia `audit_log` ([security.md](security.md)) |
| Faza 1: token na api-test, faktury kosztowe, wysyłka ze szkicu FreshBooks, numer KSeF, UPO, PDF z QR | odczyt w [phases/f1-ksef-odczyt.md](phases/f1-ksef-odczyt.md); wysyłka, UPO i PDF w zaślepce F3 w [README.md](README.md) |
| Faza 2: rejestracja na INT, lista bez odbioru, push z datą fikcji, odbiór i archiwum | odłożone (D-29): [modules/e-doreczenia.md](modules/e-doreczenia.md) i [roadmap.md](roadmap.md); teraz bez wniosku INT |
| Faza 3: snapshoty rejestrów i NBP, licznik białej listy | [phases/f1-ksef-odczyt.md](phases/f1-ksef-odczyt.md); dwa liczniki białej listy w [modules/rejestry-kursy.md](modules/rejestry-kursy.md); KRS, CEIDG i GUS w [roadmap.md](roadmap.md) |
| Faza 4: JPK_V7M z UPO na test-e-dokumenty, JPK_EWP zgodny z XSD | dry run XSD po F2.4 w [phases/f2-ewidencja.md](phases/f2-ewidencja.md); wysyłki w [phases/f5-jpk.md](phases/f5-jpk.md) |
| Czat: tylko odczyt, wykres z bazy, wklejona treść z ukrytym poleceniem bez akcji | [phases/f6-czat.md](phases/f6-czat.md); `cc-28`–`cc-30`, test na warstwie narzędzi |
| basal: CPU, decyzje poniżej progu do panelu, korekty w `classification`, porównanie wersji | odłożone (D-29): [modules/basal.md](modules/basal.md) i [roadmap.md](roadmap.md) |

Biblioteka testów własności F0a: `giorgiosironi/eris` 1.2.0 (wersja z `composer.lock`); ziarno CI `ERIS_SEED=20261009`, odtwarzalne lokalnie.
