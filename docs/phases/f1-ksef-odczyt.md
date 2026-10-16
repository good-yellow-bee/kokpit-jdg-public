# F1 KSeF odczyt

Odczyt KSeF dla Subject1 i Subject2 od 1.02.2026, przegląd kosztów, koszty spoza KSeF, kursy NBP, VIES i biała lista. Stan na 8 października 2026.

## Cel

Odbiór faktur w KSeF działa od 1.02.2026 ([MF](https://ksef.podatki.gov.pl/ponizej-10-000-zl/)), a wystawianie jest obowiązkowe od 1.04.2026 ([Dz.U. 2025 poz. 1203](https://api.sejm.gov.pl/eli/acts/DU/2025/1203/text.pdf)). Po F1 kokpit zna wszystkie Twoje faktury 2026 z KSeF: kosztowe i własną sprzedaż wystawioną poza kokpitem.

F1 daje też kursy NBP i dowody weryfikacji kontrahentów, których potrzebuje F2.2 i F3. Kod kokpitu nic nie wysyła do KSeF; jedyny zapis to pomocnik testowy na TE w `tests/`.

Według D-30 F1 idzie po F0b, a przed F2.2 i F2.3. F2.1 i F2.4 są już odebrane, więc tabele `revenue_entry` i `fixed_asset` istnieją.

## Wejścia

Brak wejścia oznaczonego „stop” zatrzymuje fazę i kończy się pytaniem. Pozostałe idą na założeniu domyślnym z [../decisions.md](../decisions.md).

| Wejście | Źródło | Brak oznacza |
| --- | --- | --- |
| F0a i F0b odebrane | [f0a-szkielet.md](f0a-szkielet.md), [f0b-bramka-kalendarz.md](f0b-bramka-kalendarz.md) | stop |
| F2.1 i F2.4 odebrane (D-30); migracja F1 dodaje klucze obce do ich tabel | [f2-ewidencja.md](f2-ewidencja.md) | stop |
| dane testowe KSeF TE: testowa JDG, drugi sprzedawca testowy z innym losowym NIP, tokeny do odczytu i z `InvoiceWrite`, certyfikat typ 1 self-signed | Q-39, „KSeF TE” w [../testing.md](../testing.md) | bez bramki „stop”: wytwarza je pierwszy wiersz sesji 1 (`ksef-te-provision`), przegląd S7 |
| klient KSeF | D-70, D-71 | własny klient `HttpKsefGateway` zamiast biblioteki (D-14 zastąpiona) |
| zakres rejestrów | Q-20, D-20, D-29 | założenie domyślne: VIES, biała lista i NBP; KRS, CEIDG i GUS odłożone, wiersz w [../roadmap.md](../roadmap.md) |
| narzędzie i numeracja sprzedaży od 1.04.2026; miesiące ponad 10 tys. zł | Q-10 | założenie domyślne: Aplikacja Podatnika KSeF, numery z FreshBooks |
| traktowanie VAT i WHT dostawców zagranicznych | Q-21 | założenie domyślne: import usług; kwalifikacja WHT czeka na przegląd doradcy; przedpłata: ręczny `tax_point_date` z powodem; zagraniczna osoba fizyczna: WHT z art. 29 ustawy o PIT, do weryfikacji |
| tylko B2B | D-05 | – |
| wybór art. 31a ust. 2a | Q-23, D-21 | założenie domyślne: bez wyboru (Q-23) |
| dane własne sprzedawcy w `setting` `company.identity`, część FA(3); tabela `setting` z F2.1 | [../modules/ksef.md](../modules/ksef.md) | na `kokpit-dev` (laptop) wartości fikcyjne; na PROD wpisujesz przed go-live |
| rodzaje `credential_state` par JWT KSeF: `ksef_jwt_read`, `ksef_jwt_write` | [../naming.md](../naming.md), [../data-model.md](../data-model.md) | – |
| grupy `rate_counter.bucket`: `ksef.*`, `vies.check`, `white_list.search`, `white_list.check`, `nbp.rates` | [../naming.md](../naming.md) | – |
| token do odczytu PROD | wiersz „KSeF odczyt” w [../operations.md](../operations.md) | go-live F1 czeka |

Kod pomocnika leży tylko w `tests/`. Poświadczenia pomocnika są w sejfie testowym przez `SecretStore`, z nazwą według wzorca `<CONNECTOR>_<CEL>_<NAZWA>` i celem `TEST` ([../security.md](../security.md)). Mogą też powstawać w przebiegu: certyfikat self-signed i podmioty z `/testdata/subject`. Nigdy nie leżą jawnie w repo ani w sejfie `prod`.

## Ładuj tylko

Ta lista jest autorytatywna. [../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md) ładuje się sam, a [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md) sam przy edycji konektorów i fixtures.

| Plik | Sekcje |
| --- | --- |
| [../README.md](../README.md) | cały (start sesji), zwłaszcza „Teraz – zgodność” i „Odłożone” |
| [../naming.md](../naming.md) | cały, z „Nazwami wycofanymi” |
| [../decisions.md](../decisions.md) | wiersze z F1 w kolumnie „Potrzebne w”; Q-23, Q-39; D-05, D-14, D-20, D-21, D-29 |
| [../facts.md](../facts.md) | fakty oznaczone dla F1 |
| [../modules/ksef.md](../modules/ksef.md) | części F1 z jego „Zakresu i faz”: „Środowiska i hosty”, „Stan obecny i przejście”, „Uwierzytelnienie”, „Cykl życia tokenów i certyfikatów”, „Synchronizacja Subject1 i Subject2”, „Limity i 429” (tylko `blocked_until` i `Retry-After`, D-29); do tego „Biblioteka i `KsefGateway`”, „Dane własne sprzedawcy”, kolumna F1 w „Go-live” |
| [../modules/rejestry-kursy.md](../modules/rejestry-kursy.md) | cały; odłożone integracje są tylko linkiem do roadmap (D-29) |
| [../tax-rules.md](../tax-rules.md) | sekcje F1 z jego „Jak czytać”: „Kursy walut”, „Koszty: import usług i dokumenty spoza KSeF”; z „Podatku u źródła” pola dostawcy i osoba fizyczna |
| [../data-model.md](../data-model.md) | „Jak czytać”, „Konwencje”, „Kwoty i zaokrąglenia”, „Daty i strefa czasu”, „Środowiska”, „Mapa tabel”; tabele `invoice`, `invoice_line`, `counterparty`, `verification`, `fx_rate`, `sync_cursor`, `credential_state`, `rate_counter`, `stored_file`, `setting`, `obligation`, `connector_call`, `notification`, `audit_log`; z `revenue_entry` i `fixed_asset` tylko klucze obce; „Faktura sprzedaży”, „Faktura kosztowa” |
| [../security.md](../security.md) | „Sejf dwupoziomowy”, wiersze F1 w „Inwentarzu sekretów”, „Stany poświadczeń”, „Maskowanie: `Redactor`” (rachunki bankowe) |
| [../architecture.md](../architecture.md) | „Konektory”, „Środowiska i cele per konektor”, „Liczniki limitów” |
| [../compliance-calendar.md](../compliance-calendar.md) | „Silnik terminów” (`dedupe_key`), „`PolishCalendar`”; wiersze `vat_ue`, `wht_payment`, `wht_certificate` i `wl_notice` w „Tabeli obowiązków”; „Uwagi do wierszy”: `vat_ue` do F2; „Przypomnienia” (`wht_certificate`, `credential_expiry`); „Stany obowiązku” |
| [../operations.md](../operations.md) | „Katalog zadań” (zadania F1), wiersze KSeF w „Rotacjach poświadczeń”, wiersz „KSeF odczyt” w „Go-live” |
| [../testing.md](../testing.md) | „Polityka per konektor”, „Nagrania i odtwarzanie”, „`@group live`”, „KSeF TE”, „Złote przypadki”: „Podatki” (import usług, WHT) i „Czas, daty i kursy”, „CI”, „Dowód działania”, „Konwencje kryteriów odbioru”, „Kryteria przekrojowe” |

API komponentów F0a, F2.1, F2.4 i F0b czytasz z kodu oraz z `docs/evidence/f0a.md`, `docs/evidence/f2.md` i `docs/evidence/f0b.md`. Przed sesją 2 sprawdź fakty białej listy z datą 2026-10-13 w [../facts.md](../facts.md).

## Zakres

**KSeF.** Dom: [../modules/ksef.md](../modules/ksef.md).

| Element | Co powstaje |
| --- | --- |
| dane TE | pomocnik w `tests/` wytwarza testową JDG i drugiego sprzedawcę przez `/testdata/subject`, certyfikat self-signed i tokeny; losowe NIP trafiają na listę fikcyjnych identyfikatorów ([../testing.md](../testing.md)) |
| `KsefGateway` | interfejs nad własnym klientem `HttpKsefGateway` (D-70, D-71) przez `ConnectorHttpClient` i `HostAllowlist`; kody błędów jako wyliczenie |
| uwierzytelnienie | token `ksef_token_read`; zapas certyfikatem typ 1 (`ksef_auth_cert`, XAdES) sprawdzony na TE; para JWT (`ksef_jwt_read`) w `credential_state`, odświeżana pod lockiem |
| ważność poświadczeń | daty tokenów i certyfikatów w `credential_state`; przypomnienia z F0b; data przy konektorze w panelu |
| synchronizacja | eksport z `PermanentStorage` per rola (`subject1`, `subject2`), kursor w `sync_cursor`, deduplikacja po numerze KSeF; zadanie `ksef.sync` |
| backfill | od 1.02.2026, w przyległych oknach do 100 dni, w nocy; zapasowo zapytanie o metadane; błąd 21165 z backoffem |
| limity | `rate_counter` dla grup `ksef.*` tylko z `blocked_until`: 429 z `Retry-After` ustawia je, odkłada zadanie i wysyła push. Tempo eksportów wynika z harmonogramu w [../modules/ksef.md](../modules/ksef.md). Okna przesuwne dopiero po pierwszych odpowiedziach 429 (D-29) |
| klucze MF | zadanie `mf_keys.refresh` ([../operations.md](../operations.md)) |
| import Subject1 | `invoice` sprzedaży w stanie `accepted`, bez `approval`; `tax_scenario` z Podmiot2 według „Skutków zapisu” w [../modules/ksef.md](../modules/ksef.md); do ewidencji trafia w F2.2 |
| przejście | liczba i suma Twoich faktur 2026; raport serii P_2 z ostatnim numerem do porównania z FreshBooks (kroki 1–2 przejścia) |
| dane firmy | `setting` `company.identity` z danymi Podmiot1 (część FA(3)) |
| klucze obce | migracja dodaje klucze obce `revenue_entry.invoice_id`, `revenue_entry.fx_rate_id` i `fixed_asset.invoice_id` do tabel F1 ([f2-ewidencja.md](f2-ewidencja.md)) |

Zadanie „faktura spoza kokpitu” działa dopiero od go-live F3, jak w kroku 5 przejścia w [../modules/ksef.md](../modules/ksef.md). Przed F3 każda Twoja faktura powstaje poza kokpitem, więc import Subject1 go nie tworzy.

**Testowe dane na TE.** Faktury na TE wystawia pomocnik w `tests/`, poza `src/` i testem architektury zapisów, tylko w testsuite `live` przy `KSEF_TARGET=test`. Drugi sprzedawca testowy wystawia faktury testowej JDG (Subject2), a testowa JDG jemu (Subject1). Wszystkie dane są fikcyjne ([../testing.md](../testing.md)).

**Dowody.** Rejestr `tests/Acceptance/f1.yaml` ma wpis dla każdego wiersza „Kryteriów odbioru”: klucz, komenda, asercja, artefakty, wykonawca ([../testing.md](../testing.md)). Wiersze tabel i wycinki logu dla `cmd/<klucz>.txt` wskazuje wpis rejestru.

**Koszty.** Dom: [../tax-rules.md](../tax-rules.md), stany w [../data-model.md](../data-model.md).

| Element | Co powstaje |
| --- | --- |
| Subject2 | `invoice` kosztowy w stanie `imported`; XML jako `stored_file` rodzaju `cost_document`; `ksef_number_assigned_at` jako najwcześniejszy okres odliczenia |
| koszt spoza KSeF | wpis ręczny ze źródłem `email`, `paper`, `cash_register` albo `manual` i obowiązkowym plikiem; pola importu usług, dokumentu `WEW`, obowiązku podatkowego i odliczenia według [../data-model.md](../data-model.md) |
| przedpłata | ręczna zmiana `tax_point_date` kosztu w przeglądzie, przed `assigned`, z powodem w `invoice.tax_point_override_reason`; kurs VAT i proponowany `vat_period_start` liczone od nowej daty (art. 19a ust. 8, Q-21) |
| deduplikacja | wpis ręczny łączy się z późniejszą fakturą z KSeF po sprzedawcy i numerze; różnica wraca do `imported` |
| przegląd | `imported` → `reviewed` (`business`, `private`, `not_mine`, `disputed`) → `assigned`; `not_mine` tworzy zadanie: prośba o KOR albo zgłoszenie oszustwa |
| WHT dostawcy | pola WHT przy kontrahencie i certyfikat rezydencji jako `evidence`; obowiązek `wht_payment` per dostawca po wpisaniu `paid_on` |
| osoba fizyczna | `counterparty.is_natural_person`; przy true kokpit nie liczy WHT według ustawy o CIT, a panel pokazuje alert: WHT z art. 29 ustawy o PIT, do weryfikacji (Q-21) |

**Kontrahenci i rejestry.** Dom: [../modules/rejestry-kursy.md](../modules/rejestry-kursy.md).

| Element | Co powstaje |
| --- | --- |
| dopasowanie dostawcy | import kosztu dopasowuje sprzedawcę do `counterparty` po NIP albo numerze VAT UE; bez dopasowania nowy wiersz z `is_supplier`; powstaje w sesji 1 |
| konektory | `vies` i `white_list`; niezmienne wiersze `verification`; surowa odpowiedź jako `verification_raw` |
| VIES | numer pytającego z `company.identity`, `request_identifier`; błąd jako HTTP 200 daje `unverified_retry` z backoffem |
| biała lista | dwa liczniki `white_list.search` i `white_list.check` z progami z konfiguracji; `requestId` jako dowód; bez zapytań 22:00–0:00 (planowane przerwy nowego wykazu od 12.10.2026, [MF](https://www.gov.pl/web/kas/wykaz-podatnikow-vat)) |
| dowód ręczny | `manual_evidence` z plikiem `evidence` dla klientów spoza UE i z GB |
| polityka | wymagane sprawdzenia i świeżość per typ kontrahenta; zadanie `counterparty.refresh_due` |
| przed przelewem | akcja „Sprawdź przed przelewem”; przy wyniku `NIE` powyżej progu `white_list.pre_payment_threshold_pln` zadanie z trzema wyjściami; wybór zawiadomienia tworzy obowiązek `wl_notice` |
| samosprawdzenie | raz w miesiącu Twój numer VAT UE w VIES i status w białej liście |

**Kursy NBP.** Reguła w [../tax-rules.md](../tax-rules.md), mechanika w [../modules/rejestry-kursy.md](../modules/rejestry-kursy.md).

- Konektor `nbp` i `FxRateProvider::getRate(currency, date)` pobierają kurs na żądanie. Wiersz `fx_rate` jest niezmienny, a numer tabeli to dowód.
- Cache korzysta z `PolishCalendar::previousWorkingDay` z F0b.
- Zadanie `nbp.fetch_rates` z katalogu w [../operations.md](../operations.md) tylko rozgrzewa cache. Kurs przy dokumencie zawsze pochodzi z `getRate`.
- F1 zapisuje kursy VAT przy kosztach i imporcie Subject1. Kurs PIT dla ewidencji liczy F2.2.

**Obowiązki.** Dom: [../compliance-calendar.md](../compliance-calendar.md).

- `vat_ue`: import wykrywa miesiące ze sprzedażą `eu_b2b` (`tax_scenario` = `eu_b2b` dla pozycji `np II` z `KodUE` nabywcy); taki miesiąc ma obowiązek `open`. Miesiąc bez takiej sprzedaży obsługuje do F2.3 reguła „`vat_ue` do F2” z „Uwag do wierszy”.
- `eu_b2b_unidentified` (nabywca z UE bez ważnego VIES, `np I`) nie tworzy `vat_ue`. Scenariusz czeka na potwierdzenie doradcy (Q-29, D-05).
- `wht_payment`: obowiązek per dostawca z terminem od `paid_on`.
- `wht_certificate`: obowiązek per dostawca z `wht_cert_valid_until`, przypomnienia T−30 i T−7.
- `wl_notice`: termin zawiadomienia o zapłacie na rachunek spoza wykazu; reguła terminu w [../compliance-calendar.md](../compliance-calendar.md).

## Sesje

F1 dzieli się na trzy sesje. Sesja kończy się, gdy przechodzą wiersze jej tabeli w „Kryteriach odbioru”.

Kolejność wynika z zależności: import z KSeF potrzebuje kursów NBP, a koszty potrzebują rejestrów. Sesja 3 sprawdza też kryteria przekrojowe.

| Sesja | Zakres | Wiersz pierwszego promptu |
| --- | --- | --- |
| 1 KSeF i kursy | dane TE, `KsefGateway`, uwierzytelnienie, ważność poświadczeń, synchronizacja, backfill, 429, klucze MF, import Subject1 z kursem VAT, przejście, `company.identity`, klucze obce, `FxRateProvider` z NBP, dopasowanie dostawców w `counterparty` | `Zrealizuj sesję 1 F1 na api-test: najpierw dane TE pomocnikiem przez /testdata, potem KsefGateway z tokenem do odczytu i zapasem certyfikatem typ 1, synchronizację z backfillem od 1.02.2026, 429 z blocked_until, FxRateProvider z NBP, dopasowanie dostawców.` |
| 2 Rejestry | VIES, biała lista z dwoma licznikami, dowód ręczny, polityka świeżości, sprawdzenie przed przelewem z `wl_notice`, samosprawdzenie | `Zrealizuj sesję 2 F1: VIES, białą listę z dwoma licznikami, politykę świeżości, sprawdzenie przed przelewem z wl_notice i samosprawdzenie.` |
| 3 Koszty i obowiązki | przegląd kosztów, koszty spoza KSeF z importem usług i przedpłatą, pola WHT z osobą fizyczną, obowiązki `vat_ue`, `wht_payment` i `wht_certificate` | `Zrealizuj sesję 3 F1: przegląd kosztów, koszty spoza KSeF z importem usług i przedpłatą, pola WHT oraz obowiązki vat_ue, wht_payment i wht_certificate.` |

## Poza zakresem

- Wysyłka faktur, `ksef_token_write` na PROD, FA(3), reguła P_1, KOR, Latarnia, KOD I i PDF: zaślepka F3 w [../README.md](../README.md).
- Ewidencja, FreshBooks i kurs PIT dla `revenue_entry`: F2.2 w [f2-ewidencja.md](f2-ewidencja.md).
- `period` i blokada okresu: F2.3 w [f2-ewidencja.md](f2-ewidencja.md). Do tego czasu `assigned` działa bez blokady.
- Pola JPK_V7M(3) i wysyłka JPK: [f5-jpk.md](f5-jpk.md).
- KRS, CEIDG i GUS: odłożone (D-20, D-29), wiersz w [../roadmap.md](../roadmap.md).
- Okna przesuwne limitów KSeF: odłożone do pierwszych odpowiedzi 429 (D-29).
- Tryb offline i certyfikat typ 2: D-15, Q-28.

## Kryteria odbioru

Komendy uruchamiasz w kontenerze `app`, przy `KSEF_TARGET=test`. Wiersze niżej są tylko dla F1; każda sesja ma osobną tabelę.

Każdy wiersz zaczyna się kluczem z [../naming.md](../naming.md) (D-33); ten sam klucz jest w `tests/Acceptance/f1.yaml`. Wykonawca stoi w nawiasie po kluczu; wiersz bez nawiasu ma wykonawcę `ci`. Układ paczki i `manifest.json`: „Dowód działania” w [../testing.md](../testing.md).

| Zapis w „Dowodzie” | Znaczenie |
| --- | --- |
| `junit.xml`: `X` | przypadek testowy `X` z wynikiem pass; panel sprawdzają testy WebTestCase, bez zrzutów |
| `cmd/<klucz>.txt` | wynik komendy po `Redactor` z wierszami tabel, wycinkami logu i sha256 nagrań wskazanymi w wierszu; tokeny, JWT, certyfikaty i NIP zamaskowane |
| `ksef/<klucz>-upo.xml` | UPO XML z api-test z przebiegu `live`; NIP są losowe i testowe |

Wiersze `live` wymagają `CONNECTORS_ENABLED=1` i sejfu testowego. W CI uruchamia je ręcznie job `live` (`kokpit:evidence f1 --only=live`), nigdy na PROD.

### Sesja 1 KSeF i kursy

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `ksef-te-provision` (`live`) dane testowe na TE | `vendor/bin/phpunit --testsuite live --filter KsefTeProvision`: pomocnik w `tests/` | testowa JDG i drugi sprzedawca z losowymi NIP, uwierzytelnione samopodpisaną pieczęcią `VATPL-<NIP>` (D-72; `/testdata/subject` nie zakłada JDG); certyfikat self-signed; tokeny do odczytu i z `InvoiceWrite`; NIP na liście fikcyjnych identyfikatorów; zero sekretów w repo i w sejfie `prod` | `junit.xml`: `KsefTeProvision`; `cmd/ksef-te-provision.txt`: zamaskowane NIP, numery referencyjne tokenów bez wartości, notAfter certyfikatu |
| `credential-validity` (`live`) daty ważności poświadczeń | `vendor/bin/phpunit --testsuite live --filter KsefTeCredentialValidity`: `bin/console app:credential:register ksef_token_read --valid-until=2026-12-31`; `bin/console app:credential:register ksef_auth_cert --valid-until=<notAfter certyfikatu self-signed>` | `valid_until` 31.12.2026 dla tokenu ([../modules/ksef.md](../modules/ksef.md)) i notAfter dla `ksef_auth_cert`; obowiązki `credential_expiry` | `cmd/credential-validity.txt`: wyjście komend, wiersze `credential_state` bez jawnych wartości, wiersze `obligation` z `credential_expiry` |
| `ksef-token-auth` (`live`) uwierzytelnienie tokenem na TE | `vendor/bin/phpunit --testsuite live --filter KsefTeTokenAuth`: `bin/console app:credential:smoke ksef_token_read` | `ok`, nowy wiersz `connector_call`, para JWT w `credential_state` | `cmd/ksef-token-auth.txt`: wyjście komendy, wycinek logu z zamaskowanym tokenem i JWT, wiersz `connector_call` z hostem `api-test.ksef.mf.gov.pl` |
| `ksef-cert-fallback` (`live`) zapas certyfikatem typ 1 | `vendor/bin/phpunit --testsuite live --filter KsefTeCertFallback`: `bin/console app:credential:smoke ksef_auth_cert` z certyfikatem self-signed na TE | `ok` po uwierzytelnieniu XAdES; bez sekretu tokena `accessToken('write')` idzie tym samym podpisem (D-72) | `cmd/ksef-cert-fallback.txt`: wyjście komendy, wycinek logu z krokiem XAdES, bez bloku certyfikatu |
| `connector-validity-panel` data ważności w panelu | `vendor/bin/phpunit --filter ConnectorValidityPanel` | panel pokazuje `valid_until` przy konektorze `ksef` | `junit.xml`: `ConnectorValidityPanel` |
| `ksef-read-token-scope` (`live`) token do odczytu nie wysyła | `vendor/bin/phpunit --testsuite live --filter KsefTokenScope` | otwarcie sesji wysyłki tokenem do odczytu kończy się odmową KSeF; zero faktur | `junit.xml`: `KsefTokenScope`; `cmd/ksef-read-token-scope.txt`: wycinek logu z kodem odmowy KSeF |
| `ksef-sync-te` (`live`) synchronizacja na TE | `vendor/bin/phpunit --testsuite live --filter KsefTeSyncTest`: pomocnik `KsefTeIssuer` wystawia 3 faktury dla testowej JDG i 2 faktury testowej JDG | po `ksef.sync` 3 koszty `imported` i 2 sprzedaże `accepted` bez `approval` | `junit.xml`: `KsefSync`; `cmd/ksef-sync-te.txt`: 5 numerów KSeF z api-test i wiersze `invoice` z tymi numerami; `ksef/ksef-sync-te-upo.xml`: UPO sesji pomocnika |
| `ksef-sync-idempotent` idempotencja synchronizacji | `vendor/bin/phpunit --filter "KsefSyncReplay|KsefTeSyncRecording"`: ta sama paczka eksportu odtworzona dwa razy, w pamięci i z nagrania TE `te-sync-export` (D-71) | zero nowych wierszy, kursor bez zmian | `junit.xml`: `KsefSyncReplay`; `cmd/ksef-sync-idempotent.txt`: wiersz `sync_cursor` przed i po z tym samym sha256 |
| `ksef-backfill-windows` okna backfillu | `vendor/bin/phpunit --filter KsefBackfill` na atrapie bramki (TE nie daje historii od 1.02.2026, D-72), `MockClock` 8.10.2026 | trzy przyległe okna od 1.02.2026, każde do 100 dni; `IsTruncated` przenosi start na `LastPermanentStorageDate`; kolejne eksporty jednej roli co najmniej 15 minut po sobie, najwyżej 20 eksportów na godzinę ([../modules/ksef.md](../modules/ksef.md)) | `junit.xml`: `KsefBackfill`; `cmd/ksef-backfill-windows.txt`: wycinek logu z granicami trzech okien i czasami eksportów |
| `ksef-21165-retry` błąd 21165 | `vendor/bin/phpunit --filter Ksef21165Retry` na atrapie z błędem 21165 (TE nie daje go na żądanie, D-72) | ponowienie z backoffem; kursor bez zmian do sukcesu | `junit.xml`: `Ksef21165Retry`; `cmd/ksef-21165-retry.txt`: wycinek logu z odstępami ponowień |
| `ksef-429-blocked-until` stop przy 429 | `vendor/bin/phpunit --filter KsefRateLimit429` na odpowiedzi 429 z `Retry-After` (D-72) | `rate_counter.blocked_until` ustawione, zadanie odłożone, push; zero wywołań przed tą chwilą | `junit.xml`: `KsefRateLimit429`; `cmd/ksef-429-blocked-until.txt`: wiersz `rate_counter` z `blocked_until`, wiersze `connector_call` bez wywołań przed `blocked_until`, wiersz `notification` z pushem |
| `mf-keys-refresh` klucze MF | `vendor/bin/phpunit --filter MfKeysRefresh`: odpowiedź w kształcie nagrania `te-public-keys` z kluczem wygasłym, bieżącym i następcą | bez kopii w konfiguracji (D-72): na każde użycie klucz ważny w chwili procesu, przy kilku najnowszy; po wymianie następca; bez ważnego `mf_key_missing` | `junit.xml`: `MfKeysRefresh`; `cmd/mf-keys-refresh.txt`: sha256 nagrania i odpowiedzi, wybrane klucze |
| `nbp-rate` kurs NBP | `vendor/bin/phpunit --filter FxRateProvider` na nagraniu NBP | `getRate('EUR', 2025-12-29)` zwraca tabelę 248/A/NBP/2025 z 23.12.2025 ([zapytanie NBP](https://api.nbp.pl/api/exchangerates/rates/a/eur/2025-12-20/2025-12-31/)); drugie wywołanie bez żądania HTTP; pusty zakres daje błąd i alert | `junit.xml`: `FxRateProvider`; `cmd/nbp-rate.txt`: numer tabeli 248/A/NBP/2025, sha256 nagrania i wiersz `fx_rate` |
| `subject1-fx-vat` kurs VAT przy Subject1 | `vendor/bin/phpunit --filter KsefSubject1FxVat`: nagranie Subject1 z fakturą 1 000,00 EUR, P_1 = 2.11.2026, w wariantach P_6 30.10.2026 i 30.11.2026 (fikcyjne kwoty i kursy, przykład w [../tax-rules.md](../tax-rules.md)) | P_6 30.10.2026: `tax_point_date` 30.10.2026, `fx_vat_rate_id` z tabeli z 29.10.2026 (art. 31a ust. 1); P_6 30.11.2026: tabela z 30.10.2026 (art. 31a ust. 2); `net_pln` i `vat_pln` po kursie VAT; `fx_pit_rate_id` pusty do F2.2 | `junit.xml`: `KsefSubject1FxVat`, oba warianty; `cmd/subject1-fx-vat.txt`: wiersze `invoice` z `tax_point_date`, `fx_vat_rate_id`, `net_pln` i `vat_pln` |
| `ksef-transition-report` raport przejścia | `vendor/bin/phpunit --filter "KsefTransitionReport|KsefTeSyncRecording"`: fikcyjne faktury Subject1 z dwiema seriami P_2 i nagranie eksportu TE `te-sync-export` z serią przebiegu (D-71) | liczba i suma faktur 2026; ostatni numer każdej serii P_2 | `junit.xml`: `KsefTransitionReport`; `cmd/ksef-transition-report.txt`: raport |
| `supplier-match` dopasowanie dostawcy | `vendor/bin/phpunit --filter SupplierMatch`: nagranie Subject2 od istniejącego klienta i od nowego sprzedawcy | istniejący wiersz `counterparty` dostaje `is_supplier`; nowy sprzedawca daje nowy wiersz z `is_supplier` ([../modules/rejestry-kursy.md](../modules/rejestry-kursy.md)) | `junit.xml`: `SupplierMatch`; `cmd/supplier-match.txt`: wiersze `counterparty` |
| `ledger-foreign-keys` klucze obce do tabel F2 | `vendor/bin/phpunit --filter F1ForeignKeys`: wiersz `revenue_entry` z nieistniejącym `invoice_id` i `fx_rate_id`; wiersz `fixed_asset` z nieistniejącym `invoice_id` | błąd bazy w każdym przypadku | `junit.xml`: `F1ForeignKeys` |

### Sesja 2 Rejestry

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `vies-live` (`live`) odpowiedzi VIES | `vendor/bin/phpunit --testsuite live --filter Vies` na usłudze testowej | żądanie ma `requesterNumber` z `company.identity`; `valid`, `invalid` oraz błąd jako HTTP 200 z `actionSucceed:false` → `unverified_retry`; `request_identifier` z odpowiedzi, na usłudze testowej pusty (D-74) | `junit.xml`: `Vies`; `cmd/vies-live.txt`: `request_identifier` i sha256 `verification_raw` |
| `white-list-live` (`live`) biała lista na hoście testowym | `vendor/bin/phpunit --testsuite live --filter WhiteList` na `wykazpodatnikowvat-test.mf.gov.pl` | wiersz `verification` z `request_identifier` i plik `verification_raw` | `junit.xml`: `WhiteList`; `cmd/white-list-live.txt`: `requestId`, sha256 `verification_raw` i wiersz `connector_call` z hostem `wykazpodatnikowvat-test.mf.gov.pl` |
| `white-list-counters` dwa liczniki białej listy | `vendor/bin/phpunit --filter WhiteListCounters` | `white_list.search` i `white_list.check` liczą osobno; próg z konfiguracji odkłada zadanie do północy i wysyła push | `junit.xml`: `WhiteListCounters`; `cmd/white-list-counters.txt`: dwa wiersze `rate_counter` ze stanem po progu, wiersz `notification` z pushem |
| `pre-payment-above-threshold` przelew powyżej progu | `vendor/bin/phpunit --filter PrePaymentCheckAbove15k`: „Sprawdź przed przelewem” z nagraniem `NIE` i kwotą 20 000 zł (fikcyjną), potem wybór zawiadomienia z dniem zlecenia przelewu 4.11.2026 | ostrzeżenie i zadanie w panelu: zmiana rachunku, podzielona płatność albo zawiadomienie w 7 dni; zawiadomienie tworzy `wl_notice` z `due_date` 12.11.2026, bo 11.11.2026 to święto | `junit.xml`: `PrePaymentCheckAbove15k`; `cmd/pre-payment-above-threshold.txt`: wiersz `obligation` `wl_notice` z `due_date` |
| `pre-payment-below-threshold` przelew do progu | `vendor/bin/phpunit --filter PrePaymentCheckBelow15k`: „Sprawdź przed przelewem” z nagraniem `NIE` i kwotą 10 000 zł (fikcyjną) | ostrzeżenie bez zadania | `junit.xml`: `PrePaymentCheckBelow15k` |
| `manual-evidence-gb` dowód ręczny dla GB | `vendor/bin/phpunit --filter ManualEvidence`: klient z GB | `manual_evidence` z plikiem `evidence` i pustym `source` | `junit.xml`: `ManualEvidence`; `cmd/manual-evidence-gb.txt`: wiersz `verification` z `manual_evidence` i sha256 pliku |
| `refresh-due-eu-supplier` odświeżenie VIES po roku | `vendor/bin/phpunit --filter RefreshDueEuSupplier`: `counterparty.refresh_due` przy dostawcy z UE z wynikiem VIES starszym niż rok (`MockClock`, nagranie VIES) | nowy wiersz `verification` z numerem pytającego w żądaniu (nagranie usługi testowej ma pusty `requestIdentifier`, D-74); poprzedni bez zmian | `junit.xml`: `RefreshDueEuSupplier`; `cmd/refresh-due-eu-supplier.txt`: stary wiersz `verification` bez zmian i nowy z `request_identifier` |
| `refresh-due-pl-client` klient z Polski poza `counterparty.refresh_due` | `vendor/bin/phpunit --filter RefreshDuePlClient`: `counterparty.refresh_due` przy kliencie z Polski z wynikiem starszym niż 30 dni (`MockClock`) | zadanie go nie odświeża; zero wywołań białej listy | `junit.xml`: `RefreshDuePlClient`; `cmd/refresh-due-pl-client.txt`: wiersze `connector_call` bez `white_list` |
| `self-check` samosprawdzenie | `vendor/bin/phpunit --filter SelfCheck` na nagraniach VIES i białej listy | wiersze `verification` dla Twojego numeru; nagranie z rozbieżnością, np. statusem innym niż „Czynny”, daje alert w panelu | `junit.xml`: `SelfCheck`; `cmd/self-check.txt`: identyfikatory zapytań |

### Sesja 3 Koszty i obowiązki

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `manual-cost-requires-file` koszt tylko z plikiem | `vendor/bin/phpunit --filter ManualCostEntry`: wpis ręczny kosztu bez pliku | odmowa; z plikiem stan `imported` | `junit.xml`: `ManualCostEntry`; `cmd/manual-cost-requires-file.txt`: wiersz `invoice` z `source` i sha256 pliku `cost_document` |
| `manual-cost-dedup` deduplikacja z KSeF | `vendor/bin/phpunit --filter ManualCostDedup`: wpis ręczny, potem ta sama faktura z KSeF | jeden wiersz z `ksef_number`; różne kwoty dają powrót do `imported` | `junit.xml`: `ManualCostDedup`; `cmd/manual-cost-dedup.txt`: jeden wiersz `invoice` z `ksef_number` |
| `cost-not-mine` koszt `not_mine` | `vendor/bin/phpunit --filter CostReviewNotMine`: ocena `not_mine` | zadanie w panelu: prośba o KOR albo zgłoszenie oszustwa; koszt nie przejdzie do `assigned` i nie dostaje `vat_period_start` | `junit.xml`: `CostReviewNotMine`; `cmd/cost-not-mine.txt`: wiersz `invoice` bez `vat_period_start` |
| `import-services-eu` import usług z UE | `vendor/bin/phpunit --filter ImportServicesEu`: wpis ręczny, fikcyjny dostawca SaaS z UE z numerem VAT UE, 100,00 EUR, PDF, obowiązek podatkowy 30.10.2026 (fikcyjne kwoty i kursy), ocena `business` | `import_services` = `eu_28b`, `tax_point_date` 30.10.2026, `fx_vat_rate_id` z tabeli z 29.10.2026, `self_assessed_vat_pln` wyliczone; proponowany `vat_period_start` 2026-10-01 (art. 86 ust. 10b pkt 3, [../tax-rules.md](../tax-rules.md)) | `junit.xml`: `ImportServicesEu`; `cmd/import-services-eu.txt`: wiersz `invoice` z `import_services`, `fx_vat_rate_id`, `self_assessed_vat_pln` i `vat_period_start` |
| `import-services-non-eu` import usług spoza UE | `vendor/bin/phpunit --filter ImportServicesNonEu`: ten sam wpis dla fikcyjnego dostawcy spoza UE | `import_services` = `other` | `junit.xml`: `ImportServicesNonEu`; `cmd/import-services-non-eu.txt`: wiersz `invoice` z `import_services` = `other` |
| `cost-tax-point-override` przedpłata kosztu | `vendor/bin/phpunit --filter CostTaxPointOverride`: wpis ręczny, fikcyjny dostawca z UE, P_6 5.11.2026, przedpłata 15.10.2026; zmiana `tax_point_date` na 15.10.2026 bez powodu, potem z powodem | bez powodu odmowa; z powodem `tax_point_date` 15.10.2026, `fx_vat_rate_id` z tabeli z 14.10.2026, proponowany `vat_period_start` 2026-10-01, `tax_point_override_reason` zapisany (Q-21) | `junit.xml`: `CostTaxPointOverride`; `cmd/cost-tax-point-override.txt`: wiersz `invoice` przed i po zmianie |
| `cost-vat-period-warsaw` okres VAT w Europe/Warsaw | `vendor/bin/phpunit --filter CostVatPeriodWarsaw`: nagranie Subject2 z `ksef_number_assigned_at` 2026-10-31T23:30:00Z, ocena `business` | proponowany `vat_period_start` 2026-11-01, bo w Europe/Warsaw to 1.11.2026 00:30 (UTC dałoby 2026-10-01) | `junit.xml`: `CostVatPeriodWarsaw`; `cmd/cost-vat-period-warsaw.txt`: wiersz `invoice` z `ksef_number_assigned_at` i `vat_period_start` |
| `vat-ue-obligation` obowiązek `vat_ue` | `vendor/bin/phpunit --filter VatUeObligation`: `MockClock` 20.11.2026; `sync_cursor` `subject1` z `last_hwm` 2026-11-20T06:00:00+01:00; nagranie Subject1 z fakturą `np II`, `KodUE` nabywcy i obowiązkiem podatkowym 5.11.2026; 10.2026 tylko z fakturą `np I` dla nabywcy z UE bez `KodUE`; 12.2026 bez sprzedaży | `tax_scenario` = `eu_b2b` dla faktury z 11.2026; faktura z 10.2026 nie tworzy `vat_ue` (`eu_b2b_unidentified` według [../modules/ksef.md](../modules/ksef.md), Q-29); `vat_ue` za 10.2026 `not_applicable` z pustym `done_by`, bo kursor minął koniec 15.11.2026; za 11.2026 `open`; za 12.2026 `open`, bo kursor jest przed końcem 15.01.2027 | `junit.xml`: `VatUeObligation`; `cmd/vat-ue-obligation.txt`: wiersze `obligation` `vat_ue` za 10.2026–12.2026 i `tax_scenario` obu faktur |
| `wht-payment-obligation` obowiązek `wht_payment` | `vendor/bin/phpunit --filter WhtPaymentObligation`: koszt dostawcy z `wht_category` i `paid_on` 15.11.2026 | obowiązek `wht_payment` z `due_date` 7.12.2026 | `junit.xml`: `WhtPaymentObligation`; `cmd/wht-payment-obligation.txt`: wiersz `obligation` z `due_date` 2026-12-07 |
| `wht-certificate-obligation` obowiązek `wht_certificate` | `vendor/bin/phpunit --filter WhtCertificateObligation`: dostawca z `wht_category` i `wht_cert_valid_until` 31.12.2026, `MockClock` 1.12.2026 | obowiązek `wht_certificate` z przypomnieniem T−30 | `junit.xml`: `WhtCertificateObligation`; `cmd/wht-certificate-obligation.txt`: wiersz `notification` z przypomnieniem T−30 |
| `wht-natural-person` zagraniczna osoba fizyczna | `vendor/bin/phpunit --filter WhtNaturalPerson`: dostawca z `is_natural_person` = true, `wht_category` = `license` i kosztem z `paid_on` 15.11.2026 | brak obowiązku `wht_payment` według ustawy o CIT; alert w panelu: WHT z art. 29 ustawy o PIT, do weryfikacji (Q-21) | `junit.xml`: `WhtNaturalPerson`; `cmd/wht-natural-person.txt`: wiersze `obligation` dostawcy (zero `wht_payment`) |

Snapshoty rejestrów z dawnej Fazy 3 to wiersze VIES, białej listy i NBP wyżej. KRS, CEIDG i GUS są odłożone (D-20, D-29).

**Kryteria przekrojowe:** wiersze od F0a do F1 w [../testing.md](../testing.md) oraz `cc-25` i `cc-26`, bo F2.4 jest przed F1 (D-30).

## Dowód fazy

**Dowód fazy:** `docs/evidence/f1.md`. Lista powstaje przy odbiorze; status F1 w [../README.md](../README.md) linkuje do niej.

| Element | Treść |
| --- | --- |
| paczka | `bin/console kokpit:evidence f1 --only=ci` i `--only=live` → `var/evidence/f1/<YYYYMMDD-HHMM>/`; układ, `manifest.json` i wyniki `pass`, `fail`, `pending` według „Paczki dowodów” w [../testing.md](../testing.md); całość po `Redactor` |
| CI | job `evidence` uruchamia wiersze `ci`, ręczny job `live` wiersze `live`; oba wgrywają `var/evidence/**` jako artefakt przebiegu w `good-yellow-bee/kokpit-jdg`; `var/` jest w `.gitignore` |
| lista | `docs/evidence/f1.md`, pisana ręcznie (D-28): data, commit, linki do przebiegów `evidence` i `live`, kryterium → pass albo fail → artefakt |
| odbiór | wymagane joby zielone, udany przebieg `live`, lista z samymi pass (D-28, „Definicja ukończenia fazy” w [../testing.md](../testing.md)) |

## Go-live

Sekwencja i wycofanie: wiersz „KSeF odczyt” w [../operations.md](../operations.md) oraz kolumna F1 w „Go-live” w [../modules/ksef.md](../modules/ksef.md). Go-live startuje po odbiorze F1; jego lista nie należy do kryteriów odbioru ([../testing.md](../testing.md)).

| Pozycja listy go-live | Wynik | Zapis |
| --- | --- | --- |
| backfill PROD Subject1 i Subject2 od 1.02.2026 | liczba i suma Twoich faktur 2026 w kokpicie równe Aplikacji Podatnika KSeF | liczba, suma i sha256 eksportu listy z kokpitu przy Q-10; bez zrzutów PROD, NIP i nazw |
| seria P_2 | ostatni numer każdej serii z raportu przejścia porównany z kolejnym numerem FreshBooks; brak kolizji | wynik przy Q-10 |

- Z tokenów sejf PROD dostaje tylko `ksef_token_read`. Token z prawem wystawiania trafia tam dopiero w F3.
- `KSEF_TARGET=prod` ustawiasz Ty osobnym wdrożeniem; wycofanie to `KSEF_TARGET=test`.
- Certyfikat typ 1 na PROD nie jest warunkiem go-live F1. Termin ustalasz po przeglądzie §13 przed 1.12.2026 ([../README.md](../README.md), [../modules/ksef.md](../modules/ksef.md)); potem `app:credential:smoke ksef_auth_cert`.
- VIES, biała lista i NBP nie mają zmiennej `*_TARGET`. Host wynika z konfiguracji wdrożenia, a hosty PROD są tylko w `kokpit-prod` ([../architecture.md](../architecture.md)). Tam działają od razu, tylko do odczytu i przez liczniki.
- Backfill PROD Subject1 jest warunkiem go-live ewidencji po F2.3 ([f2-ewidencja.md](f2-ewidencja.md)).

## Pierwszy prompt

Prompt prowadzi całą fazę. Przy pracy w sesjach zamień zdanie „Zrealizuj F1…” na wiersz z sekcji „Sesje”, a kryteria odbioru zawęź do wierszy tej sesji.

```text
Przeczytaj docs/README.md i docs/phases/f1-ksef-odczyt.md, potem tylko pliki z sekcji „Ładuj tylko”.
Krok 0: sprawdź docs/naming.md, wiersze F1 w docs/decisions.md i fakty F1 w docs/facts.md.
Brak wejścia oznaczonego „stop” oznacza stop i pytanie.
Zrealizuj F1 na api-test. Zacznij od wiersza ksef-te-provision: dane TE wytwarza pomocnik w tests/ przez /testdata.
Potem KsefGateway z tokenem do odczytu i zapasem certyfikatem typ 1, synchronizację Subject1 i Subject2
z backfillem od 1.02.2026, 429 z blocked_until, dopasowanie dostawców, przegląd kosztów, koszty spoza KSeF
z importem usług i przedpłatą, pola WHT z osobą fizyczną, obowiązki vat_ue, wht_payment i wht_certificate,
FxRateProvider z NBP, VIES, białą listę z dwoma licznikami, politykę świeżości, sprawdzenie przed przelewem
z wl_notice i samosprawdzenie.
Zanim zaczniesz kodować, wypisz plan i pytania. Na koniec uruchom testy i bin/console kokpit:evidence f1 --only=ci,
pokaż wynik kryteriów odbioru F1 i kryteriów przekrojowych z docs/testing.md oraz otwarte wiersze [manual].
```
