# Rejestry i kursy walut

Konektory rejestrów sprawdzają kontrahentów i rachunki, a konektor NBP daje kursy do przeliczeń; każdy wynik zostaje z datą jako dowód. Stan na 8 października 2026.

## Zakres

Dawny jeden konektor „kontrahent” to teraz osobne konektory z [../naming.md](../naming.md) i wspólna polityka weryfikacji (sekcja „Polityka weryfikacji” niżej). Powstają tylko VIES, biała lista i NBP (D-20).

| Źródło (`connector`) | Endpoint | Klucz | Uwagi | Użycie | Faza |
| --- | --- | --- | --- | --- | --- |
| [VIES](https://ec.europa.eu/assets/taxud/vow-information/swagger_publicVAT.yaml) (`vies`) | `POST ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number` | brak | bywa niedostępny po stronie kraju | klienci i dostawcy z UE, własny numer VAT UE | F1 |
| [Biała lista VAT](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat) (`white_list`) | `wykazpodatnikowvat.mf.gov.pl/api` | brak | dwa limity; blokada do północy po wyczerpaniu | polscy kontrahenci, rachunki przed przelewem | F1 |
| [NBP](https://api.nbp.pl/en.html) (`nbp`) | `api.nbp.pl/api/exchangerates/rates/a/{waluta}/…` | brak | tabela A, kursy średnie | przeliczenia PIT i VAT | F1 |

- KRS, CEIDG i GUS są odłożone (D-20, D-29): warunek powrotu w [../roadmap.md](../roadmap.md), fakty o API w [../facts.md](../facts.md).
- Do ich powrotu polski kontrahent bez statusu w wykazie dostaje `manual_evidence` (tabela polityki niżej).
- Reguła kursu (który dzień tabeli, PIT czy VAT) jest w [../tax-rules.md](../tax-rules.md). Kolumny `counterparty`, `verification` i `fx_rate` są w [../data-model.md](../data-model.md).

## Zasady wywołań

- Rejestry i NBP nie mają zmiennej `*_TARGET` (D-13). Adresy i wersje API są w konfiguracji, nie w kodzie.
- Hosty rejestrów pochodzą z konfiguracji wdrożenia, którą sprawdza `HostAllowlist` ([../architecture.md](../architecture.md)).
- Hosty PROD białej listy i VIES są tylko w `kokpit-prod` na VPS. Jednorazowy lokalny projekt `kokpit-restore` do testu odtworzenia nie woła nikogo, bo ma `CONNECTORS_ENABLED=0` (D-28).
- `kokpit-dev` na laptopie i testy mają tylko test białej listy (`wykazpodatnikowvat-test.mf.gov.pl`), ścieżkę VIES `check-vat-test-service` albo nagrane odpowiedzi ([../testing.md](../testing.md)).
- VIES test i PROD mają wspólny host `ec.europa.eu`, więc dla `vies` allowlista sprawdza też ścieżkę.
- NBP ma tylko host PROD `api.nbp.pl`. `kokpit-dev` dopuszcza go tylko dla `nbp`, tylko `GET` i przez licznik `nbp.rates`.
- Produkcyjne rejestry i NBP tylko do odczytu, przez licznik, nigdy z testów automatycznych.
- Wiersz `verification` dostaje `environment` kontrahenta; samosprawdzenie z pustym `counterparty_id`: środowisko wdrożenia ([../data-model.md](../data-model.md)).
- Każde wywołanie trafia do `connector_call` (hash i rozmiar). Surowa odpowiedź to `stored_file` rodzaju `verification_raw`.
- `Redactor` maskuje numery rachunków w logach ([../security.md](../security.md)). Kody błędów źródeł są wyliczeniem w konektorze.

## VIES

**Żądanie** ([VIES API](https://ec.europa.eu/assets/taxud/vow-information/swagger_publicVAT.yaml))

- `countryCode` i `vatNumber` sprawdzanego podmiotu.
- `requesterMemberStateCode` = `PL` i `requesterNumber` = Twój NIP z `setting` `company.identity`. Tylko wtedy VIES zwraca `requestIdentifier`, czyli numer konsultacji.

**Odpowiedź → `verification`**

| VIES | `verification` |
| --- | --- |
| `valid` = `true` | `result` = `valid` |
| `valid` = `false` | `result` = `invalid` |
| `actionSucceed` = `false` z `errorWrappers` | według kodu błędu w tabeli „Awarie” niżej |
| `requestIdentifier` | `request_identifier` |
| `requestDate` | `source_datetime` |
| nazwa i adres | do porównania z `counterparty`; surowa odpowiedź w pliku |

**Awarie** (kody z [checkVatService.wsdl](https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl), sprawdzone 8.10.2026)

| Kod w `errorWrappers` | `result` | Dalej |
| --- | --- | --- |
| `MS_UNAVAILABLE`, `TIMEOUT`, `SERVICE_UNAVAILABLE`, `MS_MAX_CONCURRENT_REQ`, `GLOBAL_MAX_CONCURRENT_REQ` | `unverified_retry` | ponowienie z backoffem |
| `INVALID_INPUT`: zły kod kraju albo pusty numer | `invalid` | bez ponowienia; panel prosi o poprawienie numeru |
| inny kod | `unverified_retry` | bez ponowienia; alert `connector_failure` |

- Błąd może przyjść jako HTTP 200 z `actionSucceed` = `false`. Konektor sprawdza to pole przy każdym statusie ([test VIES](https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service)).
- Ponowienie to nowa wiadomość Messenger z opóźnieniem, nie retry transportu `reads` (`max_retries: 3`, ok. 30 min, [../operations.md](../operations.md)).
- Odstępy backoffu są w konfiguracji. Po 24 h bez wyniku ponowienia stają i panel daje alert `connector_failure`.
- Potem nowe sprawdzenie uruchamia prośba o zatwierdzenie, decyzja albo akcja w panelu. `GET /check-status` pokazuje dostępność per kraj.
- VIES obejmuje państwa UE i Irlandię Północną (`XI`); Wielkiej Brytanii nie. Klient z GB dostaje dowód ręczny.
- Numer `XI` dotyczy tylko towarów; przy usługach Irlandia Północna to terytorium UK ([HMRC VATNIEU2100](https://www.gov.uk/hmrc-internal-manuals/vat-northern-ireland-and-the-eu/vatnieu2100), aktualizacja 16.06.2026).
- Klient z Irlandii Północnej kupujący usługi jest więc jak klient z GB: `non_eu_b2b` z `manual_evidence`, wynik VIES dla `XI` najwyżej jako załącznik. Scenariusz w [../tax-rules.md](../tax-rules.md), do przeglądu doradcy (Q-29).
- Wynik `invalid` dla klienta z UE nie kończy onboardingu, gdy klient prowadzi działalność. Ścieżka `eu_b2b_unidentified` jest w „Polityce weryfikacji” niżej.
- Testy idą na `check-vat-test-service`, który ma ten sam schemat.

## Biała lista

**Hosty:** od 15.10.2026 produkcja `wykazpodatnikowvat.mf.gov.pl/api`, test `wykazpodatnikowvat-test.mf.gov.pl/api` ([API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat), D-42). Adresy są w `registry.white_list_endpoints`. Dawne hosty `wl-api.mf.gov.pl` (przekierowany, działa do 1.12.2026) i `wl-test.mf.gov.pl` zostają na liście `HostAllowlist` do tej daty. Imperva przed wykazem może odesłać klientowi HTTP stronę HTML z wyzwaniem JS zamiast JSON; konektor zgłasza wtedy `waf_challenge` (D-74).

**Metody** ([specyfikacja API 1.6.1](https://www.gov.pl/attachment/cb5cf50e-9c50-4c5e-b316-cf33de2e8c06))

| Metoda | Zwraca | Użycie |
| --- | --- | --- |
| `/search/...` | pełne dane podmiotu: status VAT, nazwa, rachunki; do 30 podmiotów w zapytaniu | onboarding polskiego kontrahenta, samosprawdzenie |
| `/check/...` | `TAK` albo `NIE`: czy rachunek należy do czynnego podatnika VAT | sprawdzenie przed przelewem |

Zapytanie może dotyczyć dnia bieżącego albo przeszłego, do 5 lat wstecz.

**Limity** ([API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat))

| Licznik (`rate_counter.bucket`) | Limit oficjalny | Próg kokpitu |
| --- | --- | --- |
| `white_list.search` | 100 zapytań dziennie, każde do 30 podmiotów | 80 dziennie (jak w SPEC z 5.10) |
| `white_list.check` | do 5000 podmiotów; strona nie podaje okresu | 4000 dziennie; okno dobowe to założenie kokpitu (blokada trwa do 0:00) |

- To dwa osobne liczniki, każdy z własnym limitem.
- Po wyczerpaniu jednego z limitów dostęp może być zablokowany do 0:00. Blokada obejmuje `search`, `check` i wyszukiwarkę na stronie.
- Czy blokada dotyczy adresu IP, strona nie mówi; to niepotwierdzone.
- Okno licznika zaczyna się o północy w Europe/Warsaw. Próg kokpitu odkłada zadanie na następny dzień i wysyła push według reguły „Push” w „Licznikach limitów” ([../architecture.md](../architecture.md)).

**Odpowiedź → `verification`** ([specyfikacja API 1.6.0](https://www.gov.pl/attachment/64f1f32b-b979-4b4d-8ace-57e54c6f1082))

| Biała lista | `verification` |
| --- | --- |
| `search`: `statusVat` = `Czynny` | `result` = `valid` |
| `search`: `statusVat` = `Zwolniony` | `result` = `valid`, bo NIP jest potwierdzony; panel pokazuje status |
| `search`: `statusVat` = `Niezarejestrowany` albo brak `subject` | `result` = `invalid`; potem dozwolony `manual_evidence` |
| `check`: `TAK` | `result` = `valid` |
| `check`: `NIE` | `result` = `invalid` |
| `requestId` | `request_identifier` |
| `requestDateTime` | `source_datetime` |
| dzień, na który pytano | `checked_for_date` |

- Jedno zapytanie `check` to jeden NIP i jeden rachunek.
- `requestId` jednoznacznie identyfikuje zapytanie i może potwierdzić je przed organami podatkowymi. `requestDateTime` to chwila odpowiedzi serwera.

**Plik płaski.** MF publikuje codziennie o północy plik par NIP–rachunek zapisanych jednokierunkową funkcją skrótu ([API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat)). Pozwala potwierdzić znaną parę, nie odczytać rachunków. To zapas, gdy limity nie wystarczą. Pobieranie pliku płaskiego jest poza zakresem F1; decyzja przy R-06 ([../facts.md](../facts.md)).

**Nowy wykaz od 12.10.2026.** MF planuje zmodernizowany wykaz pod `wykazpodatnikowvat.mf.gov.pl`, z możliwymi przerwami codziennie 22:00–0:00 ([MF](https://www.gov.pl/web/kas/wykaz-podatnikow-vat)). Strona API nie zapowiada zmian hosta ani limitów.

- Host i limity są w konfiguracji. Po 12.10.2026 sprawdzasz je ponownie; wpis w [../facts.md](../facts.md).
- Konektor odmawia zapytania między 22:00 a 0:00 tak jak przy progu licznika: zadanie czeka do północy (`WhiteListConnector::QUIET_FROM_HOUR`).
- Zadania kokpitu nie pytają wykazu między 22:00 a 0:00.

## Polityka weryfikacji

Każde sprawdzenie dodaje nowy, niezmienny wiersz `verification`. Zatwierdzenie faktury wskazuje użyty wynik w `invoice.verification_id`.

| Typ | Kiedy | Źródło | Świeżość | Gdy źródło nie działa |
| --- | --- | --- | --- | --- |
| klient z UE | onboarding; każda prośba o zatwierdzenie faktury i decyzja | VIES | `valid` z dnia prośby i z dnia decyzji (Europe/Warsaw); decyzja innego dnia wymaga nowego sprawdzenia | `unverified_retry` blokuje zatwierdzenie; ponowienia według „Awarii” w sekcji VIES |
| klient z UE bez ważnego numeru w VIES, prowadzący działalność (`eu_b2b_unidentified`, do potwierdzenia, Q-29) | onboarding po wyniku `invalid` z `valid` = `false`; każda prośba o zatwierdzenie i decyzja | `manual_evidence` statusu przedsiębiorcy, np. wyciąg z rejestru kraju; wynik VIES `invalid` jako załącznik; VIES przy każdej prośbie | dowód do zmiany danych; VIES jak wiersz wyżej: `valid` zmienia scenariusz na `eu_b2b` i daje nowy XML | `unverified_retry` blokuje zatwierdzenie |
| klient spoza UE, także GB i Irlandia Północna (`XI`) przy usługach | onboarding; zmiana danych | `manual_evidence`: dokument z identyfikatorem podatkowym, np. wyciąg z rejestru kraju | do zmiany danych | – |
| klient z Polski | onboarding; prośba o zatwierdzenie, gdy wynik ma ponad 30 dni | biała lista `search` po NIP | 30 dni | NIP bez statusu w wykazie (np. zwolniony bez rejestracji VAT): `manual_evidence`, np. wydruk z CEIDG |
| dostawca z Polski | pierwszy koszt; przed każdym przelewem | biała lista `check`: NIP i rachunek | dzień zlecenia przelewu | brak wyniku: nie przelewasz albo działasz według sekcji niżej |
| dostawca z UE | pierwszy koszt; potem raz w roku | VIES | rok | `unverified_retry` i ponowienie; klasyfikacja importu usług w [../tax-rules.md](../tax-rules.md) |
| dostawca spoza UE | – | brak rejestru; certyfikat rezydencji dla WHT w [../tax-rules.md](../tax-rules.md) | – | – |
| Twoja firma | raz w miesiącu | VIES i biała lista | miesiąc | alert |

- Zatwierdzenie faktury sprzedaży wymaga identyfikatora podatkowego nabywcy: ważny VIES dla UE, dowód identyfikatora spoza UE, NIP dla Polski (D-05). B2C i OSS są poza zakresem.
- Wyjątek do potwierdzenia przez doradcę (Q-29, D-05): `eu_b2b_unidentified` z `manual_evidence` statusu przedsiębiorcy. Do potwierdzenia zatwierdzenie jest zablokowane ([ksef.md](ksef.md), [../tax-rules.md](../tax-rules.md)).
- Standard dowodu statusu przedsiębiorcy (art. 18 rozporządzenia wykonawczego Rady (UE) nr 282/2011) jest do weryfikacji w Q-29.
- `manual_evidence` ma pusty `source`, Twój `checked_by` i plik `stored_file` rodzaju `evidence`.
- `manual_evidence` statusu przedsiębiorcy nie zastępuje VIES `valid` dla `eu_b2b`. Daje tylko scenariusz `eu_b2b_unidentified` ([ksef.md](ksef.md)).
- Panel pokazuje wiek ostatniego wyniku. Wynik starszy niż próg wymaga nowego sprawdzenia przed zatwierdzeniem.
- Sprawdzenia okresowe (Twoja firma, dostawca z UE) uruchamia zadanie `counterparty.refresh_due` ([../operations.md](../operations.md)). Wynik klienta z Polski starszy niż 30 dni odświeża prośba o zatwierdzenie.

## Onboarding kontrahenta

| Rola | Przebieg |
| --- | --- |
| klient | zadanie onboardingu z pierwszego szkicu FreshBooks ([freshbooks.md](freshbooks.md)) |
| dostawca | import kosztu z KSeF (Subject2) albo spoza KSeF dopasowuje sprzedawcę do `counterparty` po NIP albo numerze VAT UE |

- Bez dopasowania import tworzy nowy wiersz z `is_supplier`. Istniejący klient dostaje dodatkowo `is_supplier`.
- Dostawca spoza UE nie ma NIP ani numeru VAT UE, więc dopasowanie potwierdzasz w przeglądzie kosztu. Import usług i WHT: [../tax-rules.md](../tax-rules.md).
- Rachunek z faktury trafia do `bank_accounts`: numer, źródło i pierwsze użycie.
- Pierwsze sprawdzenie dostawcy idzie według tabeli polityki wyżej.

## Sprawdzenie przed przelewem

Płacisz ręcznie, więc wyzwalaczem jest akcja „Sprawdź przed przelewem” przy fakturze kosztowej polskiego dostawcy.

1. Kokpit wysyła `check` z NIP dostawcy i rachunkiem z faktury na dzień zlecenia przelewu.
2. Wynik trafia do `verification` z `bank_account` i `checked_for_date`.
3. `TAK`: panel pokazuje dane do przelewu, a od 1.01.2027 także numer KSeF faktury do tytułu przelewu.
4. `NIE`: panel pokazuje ostrzeżenie i skutki z tabeli niżej.

| Reguła | Źródło |
| --- | --- |
| od 1.01.2027 czynny podatnik VAT płacący przelewem za fakturę z KSeF podaje jej numer KSeF albo identyfikator zbiorczy; status odbiorcy według wykazu na dzień płatności | art. 108g ust. 1 i 3, [ustawa o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| zapłata przelewem na rachunek spoza wykazu w dniu zlecenia oznacza solidarną odpowiedzialność za VAT dostawcy | art. 117ba § 1, [Ordynacja podatkowa, Dz.U. 2026 poz. 622](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf) |
| odpowiedzialność nie dotyczy transakcji do 15 000 zł, zawiadomienia urzędu skarbowego w 7 dni od zlecenia przelewu, podzielonej płatności i importu usług | art. 117ba § 3 tamże; próg z art. 19 [Prawa przedsiębiorców, Dz.U. 2025 poz. 1480](https://api.sejm.gov.pl/eli/acts/DU/2025/1480/text.pdf) |

| Wartość transakcji | Reguła |
| --- | --- |
| podstawa porównania | brutto faktury, bez względu na liczbę płatności z niej; nie kwota pojedynczego przelewu (art. 19 pkt 2 Prawa przedsiębiorców) |
| waluta obca | przeliczenie przez `FxRateProvider::getRate(currency, dzień transakcji)`, czyli kurs z ostatniego dnia roboczego przed transakcją |
| próg | `setting` `white_list.pre_payment_threshold_pln`, nie literał w kodzie ([../data-model.md](../data-model.md)) |

Przy wyniku `NIE` dla transakcji powyżej progu panel pokazuje trzy wyjścia: zmień rachunek, zapłać w podzielonej płatności albo złóż zawiadomienie (ZAW-NR) w 7 dni.

- Gdy wybierasz zawiadomienie i podajesz dzień zlecenia przelewu, powstaje `obligation` typu `wl_notice`.
- `due_date` = dzień zlecenia + 7 dni, przesunięty przez `PolishCalendar` na dzień roboczy (art. 12 § 5 Ordynacji podatkowej).
- `obligation.type` = `wl_notice` jest w [../naming.md](../naming.md). Domem reguły terminu jest [../compliance-calendar.md](../compliance-calendar.md).

## Dane własne sprzedawcy

Dane Podmiot1 są w `setting` `company.identity`; domem rekordu jest [ksef.md](ksef.md). Rejestry używają go dwa razy.

- Numer pytającego w VIES, więc każde sprawdzenie ma numer konsultacji.
- Samosprawdzenie raz w miesiącu: Twój numer VAT UE w VIES jest ważny, co potwierdza rejestrację do VAT-UE (art. 97 ust. 3, Q-12). W białej liście masz status „Czynny”, bo klienci sprawdzają Cię przed przelewem.
- Samosprawdzenie porównuje `accountNumbers` z `search` z rachunkami firmowymi w `company.identity` ([ksef.md](ksef.md)). Brak któregoś rachunku w wykazie daje alert.
- Samosprawdzenie zapisuje wiersz `verification` z pustym `counterparty_id` i `subject` = Twój NIP albo numer VAT UE z `company.identity`.
- Rozbieżność daje alert w panelu.

## Kursy NBP: użycie API

Ten plik opisuje tylko mechanikę pobrania. Który dzień tabeli podać dla PIT, VAT, korekty i różnicy kursowej, mówi [../tax-rules.md](../tax-rules.md).

`FxRateProvider` powstaje w F1. F2.1 i F2.4 go nie używają: K_8 i K_9 przychodzą policzone w imporcie (D-30).

**API** ([API NBP](https://api.nbp.pl/en.html))

| Fakt | Wartość |
| --- | --- |
| zakres dat | `/api/exchangerates/rates/a/{code}/{startDate}/{endDate}/`, najwyżej 93 dni |
| jeden dzień | `/api/exchangerates/rates/a/{code}/{date}/`; dzień bez tabeli daje 404 |
| dzień bez tabeli | weekendy i święta; dzisiejsza tabela przed publikacją też daje 404 |
| za duże zapytanie | 400 „Limit exceeded” |
| format | JSON (`?format=json`), daty `YYYY-MM-DD` |
| pola odpowiedzi | numer tabeli w polu `no`, dzień publikacji w `effectiveDate`, kurs w `mid` (JSON); zapis do `fx_rate.table_no`, `effective_date`, `mid` |
| transport | tylko HTTPS od 1.08.2025 |
| historia | kursy od 2.01.2002 |

**`FxRateProvider::getRate(currency, date)`** zwraca kurs średni z ostatniej tabeli A opublikowanej przed `date`.

1. `date` późniejsza niż jutro to błąd.
2. Cache: wiersz `fx_rate` z `effective_date` równym poprzedniemu dniowi roboczemu według `PolishCalendar` → wynik bez wywołania API.
3. W przeciwnym razie zapytanie o zakres od `date` − 10 dni do `date` − 1 dzień. Kokpit zapisuje wszystkie zwrócone wiersze i bierze najnowszy.
4. Gdy `date` − 1 to dzisiejszy dzień roboczy, a tabeli z dziś jeszcze nie ma, zadanie czeka na publikację. Starszego kursu wtedy nie bierze.
5. Pusty zakres (404) to błąd i alert. Kokpit nigdy po cichu nie bierze starszego kursu.

- Dziesięć dni pokrywa najdłuższe przerwy. Przykład: po tabeli 248/A/NBP/2025 z 23.12.2025 następna jest z 29.12.2025 ([zapytanie API NBP, sprawdzone 8.10.2026](https://api.nbp.pl/api/exchangerates/rates/a/eur/2025-12-20/2025-12-31/)).
- Wiersz `fx_rate` jest niezmienny; klucz `UNIQUE (source, currency, effective_date)`.
- Kurs pobiera się na żądanie dla konkretnej daty. Dawna reguła „raz dziennie przy fakturze” odpada.
- Kurs z tabeli dnia D (np. progi w euro z [../tax-rules.md](../tax-rules.md)) = `getRate(currency, D + 1 dzień)`. Gdy `effective_date` wyniku nie równa się D, to błąd i alert.
- Zadanie `nbp.fetch_rates` ([../operations.md](../operations.md)) tylko rozgrzewa cache bieżącą tabelą; źródłem prawdy jest `getRate` na żądanie.
- Użycie kursu zapisuje `fx_vat_rate_id` i `fx_pit_rate_id` przy fakturze, `fx_rate_id` przy wierszu ewidencji i płatności; numer tabeli to dowód.
- NBP nie ma środowiska testowego: testy na nagranych odpowiedziach, licznik `nbp.rates` na produkcji.
- Kurs EBC (`fx_rate.source` = `ecb`) wchodzi tylko, jeśli [../tax-rules.md](../tax-rules.md) go wybierze; konektor wtedy powstaje osobno.

## Liczniki

| `rate_counter.bucket` | Limit źródła | Zachowanie kokpitu |
| --- | --- | --- |
| `vies.check` | brak liczby; błędy przeciążenia kraju i globalne | backoff przy przeciążeniu według „Awarii”; 429 → `blocked_until` |
| `white_list.search` | 100 dziennie, do 30 podmiotów w zapytaniu | próg 80, potem odłożenie do północy |
| `white_list.check` | 5000 podmiotów | próg 4000, potem odłożenie do północy |
| `nbp.rates` | brak liczby; 400 przy za dużym zapytaniu | wywołanie tylko przy braku kursu w `fx_rate`; 429 → `blocked_until` |

- Po osiągnięciu progu zadanie jest odkładane, nie kończy się błędem ([../data-model.md](../data-model.md)). Progi są w konfiguracji, nie w kodzie.
- 429 z `Retry-After` zapisuje `blocked_until`; okien przesuwnych nie ma (D-29, [../architecture.md](../architecture.md)).
- Nazwy liczników są w [../naming.md](../naming.md).
