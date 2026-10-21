# F2 ewidencja 2026

Ewidencja 2026 w `revenue_entry`: import z narzędzia, dry run JPK_EWP(4) i JPK_ST(1) do 15.12.2026, potem źródła KSeF i FreshBooks, zaliczka i ZUS. Stan na 8 października 2026.

## Cel

Ewidencja w programie obowiązuje od 1.01.2026, z wpisem do 20. dnia następnego miesiąca ([ustawa o ryczałcie, art. 15 ust. 12](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf), [rozporządzenie, § 6](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf)). Po F2 kokpit prowadzi ją na bieżąco, ma rok 2026 i liczy zaliczkę oraz ZUS.

Kolejność pochodzi z D-30. F2.1 i F2.4 idą zaraz po F0a, przed F0b i F1. K_8 i K_9 przychodzą policzone w pliku importu, więc ta ścieżka nie potrzebuje kursów NBP ani bramki.

| Krok | Kiedy | Wynik |
| --- | --- | --- |
| F2.1, F2.4 | po F0a; F2.1 startuje najpóźniej 15.11.2026 ([../README.md](../README.md)) | import 2026, generator JPK, walidacja XSD |
| dry run | do 15.12.2026; to Twój cel, nie termin ustawowy (D-30) | JPK_EWP(4) i JPK_ST(1) z danych sty–lis 2026 ze źródła `import`, lokalnie |
| F2.2, F2.3 | po F0b i F1 | źródła KSeF Subject1 i FreshBooks, uzgodnienia, płatności, okres, zaliczka, ZUS |

Ustawowe są wpis do 20. dnia następnego miesiąca i JPK_EWP(4) za 2026 do 30.04.2027 ([broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf)).

## Wejścia

Brak wejścia oznaczonego „stop” zatrzymuje część i kończy się pytaniem. Pozostałe idą na założeniu domyślnym z [../decisions.md](../decisions.md).

| Wejście | Część | Źródło | Brak oznacza |
| --- | --- | --- | --- |
| F0a odebrana | F2.1, F2.4 | [f0a-szkielet.md](f0a-szkielet.md) | stop |
| F2.1 odebrana | F2.4 | „Kryteria odbioru” niżej | stop dla odbioru F2.4 i dry runu na danych PROD; kod na fikcyjnym zbiorze wcześniej (D-37) |
| F0b i F1 odebrane (D-30) | F2.2, F2.3 | [f0b-bramka-kalendarz.md](f0b-bramka-kalendarz.md), [f1-ksef-odczyt.md](f1-ksef-odczyt.md) | stop |
| XSD JPK_EWP(4) w `resources/xsd` z sumą kontrolną; JPK_ST(1) w F2.4 | F2.1 (import XML), F2.4 | warunki wstępne w [../README.md](../README.md), [../modules/jpk.md](../modules/jpk.md) | stop dla importu XML i dry runu; pobiera sesja części na starcie |
| eksport ewidencji 2026 z narzędzia z Q-11: CSV z K_1–K_10 albo XML JPK_EWP(4), z policzonymi K_8 i K_9 | F2.1 | Q-11, D-23, D-30, [../modules/ewidencja.md](../modules/ewidencja.md) | kod i testy na fikcyjnych fixtures; stop dla importu i dry runu na danych PROD |
| `company.identity` (część JPK) w panelu `kokpit-prod`; `auth_data` z datą urodzenia do `Podmiot1` w sejfie PROD | F2.4 | warunki wstępne w [../README.md](../README.md), [../security.md](../security.md), [../modules/ewidencja.md](../modules/ewidencja.md) | stop dla dry runu na danych PROD; odbioru F2.4 nie blokuje |
| środki trwałe i WNiP | F2.4 | Q-25 | założenie domyślne: brak; `jpk_st.enabled` = false; przed dry runem rozstrzygasz w [../facts.md](../facts.md) („Do ustalenia”) „plik bez wierszy czy brak pliku” i ustawiasz `jpk_st.enabled` |
| metoda kasowa, płatności, waluta wypłat Stripe | F2.4 (`pit.kasowy`), F2.2 | Q-24 | założenie domyślne: bez metody kasowej (`pit.kasowy` = false); płatności z FreshBooks w `payment` |
| usługi, kody PKWiU 2015 i stawki ryczałtu | F2.2 | Q-22 | kod i testy na fikcyjnym mapowaniu; stop dla odtworzenia z `freshbooks` i `ksef_subject1` na danych PROD, dopóki nie wpiszesz w `ryczalt.service_map` stawki stosowanej dziś z `note` „przed przeglądem doradcy” |
| wybór art. 31a ust. 2a | F2.2 | Q-23, D-21 | bez wyboru |
| FreshBooks jako źródło faktur | F2.2 | Q-26, D-07 | założenie domyślne: FreshBooks zostaje |
| FreshBooks: aplikacja OAuth z przekierowaniem HTTPS; prawdziwa firma tylko do odczytu, bez firmy testowej | F2.2 | D-35, warunki wstępne w [../README.md](../README.md), [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md) | kod, OAuth i testy na ręcznie pisanych fixtures oznaczonych jako fikcyjne; stop dla nagrań z prawdziwej firmy oraz odtworzenia i uzgodnień na danych PROD |
| F1 na PROD (backfill Subject1) i FreshBooks PROD połączony | go-live | wiersz „KSeF odczyt” w „Go-live” w [../operations.md](../operations.md), [../modules/freshbooks.md](../modules/freshbooks.md) | go-live ewidencji czeka; odbioru F2.2 nie blokuje |
| wariant składek ZUS, próg składki zdrowotnej | F2.3 | Q-16 | założenie domyślne: pełne składki, próg z przychodu bieżącego roku |
| zaliczka co miesiąc czy co kwartał | F2.3 | Q-17 | założenie domyślne: co miesiąc |
| ostatni JPK_V7M złożony poza kokpitem z UPO; zestawienie składek z eZUS | F2.3 | „Bilans otwarcia” w [../compliance-calendar.md](../compliance-calendar.md) | F2 nie czeka: klucze `opening.*` testujesz na danych fikcyjnych; wartości PROD przy go-live JPK_V7M(3) w F5 |
| rezerwacja doradcy podatkowego | F2.3 | Q-29, D-04 | go-live F3 czeka |
| VAT tylko miesięcznie | wszystkie | D-02 | – |

## Ładuj tylko

Ta lista jest autorytatywna. Kolumna „Części” mówi, której części dotyczy wiersz. [../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md) ładuje się sam, a [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md) sam przy edycji XML, XSD, konektorów i fixtures.

| Plik | Sekcje | Części |
| --- | --- | --- |
| [../README.md](../README.md) | cały (start sesji), zwłaszcza „Kamienie milowe”, „Teraz – zgodność” i „Odłożone” | wszystkie |
| [../naming.md](../naming.md) | cały, z „Nazwami wycofanymi” | wszystkie |
| [../decisions.md](../decisions.md) | wiersze z F2 i jej częściami w kolumnie „Potrzebne w”; D-02, D-23, D-30 | wszystkie |
| [../decisions.md](../decisions.md) | D-04, D-07, D-21, D-35 | F2.2, F2.3 |
| [../facts.md](../facts.md) | fakty oznaczone dla F2 | wszystkie |
| [../modules/ewidencja.md](../modules/ewidencja.md) | „Model wpisu”, „Odtworzenie i import 2026” z „Formatem importu”, „Mapowanie JPK_EWP(4)” | F2.1 |
| [../modules/ewidencja.md](../modules/ewidencja.md) | „Mapowanie JPK_EWP(4)” z „Dry runem”, „Wykaz środków trwałych i WNiP”, wiersz „kontrola pliku” w „Uzgodnieniach” | F2.4 |
| [../modules/ewidencja.md](../modules/ewidencja.md) | cały | F2.2, F2.3 |
| [../modules/jpk.md](../modules/jpk.md) | „Pliki XSD” | F2.1 |
| [../modules/jpk.md](../modules/jpk.md) | sekcje dry runu z jego „Jak czytać”: „Zakres i kolejność”, „JPK_EWP(4) i JPK_ST(1)”, „Pliki XSD” | F2.4 |
| [../data-model.md](../data-model.md) | „Jak czytać”, „Konwencje”, „Kwoty i zaokrąglenia”, „Daty i strefa czasu”, „Środowiska”, „Mapa tabel” | wszystkie |
| [../data-model.md](../data-model.md) | tabele `revenue_entry`, `setting`, `stored_file` | F2.1 |
| [../data-model.md](../data-model.md) | tabele `fixed_asset`, `filing`, `setting`; „Stany encji”: „Złożenie” | F2.4 |
| [../data-model.md](../data-model.md) | tabele `invoice` (sekcje „Pola wspólne”, „Daty”, „Kwoty i kursy”, „Sprzedaż”, „Korekty”, „Klucze i ograniczenia”), `invoice_line`, `payment`, `sync_cursor`, `credential_state`, `rate_counter`; „Stany encji”: „Faktura sprzedaży” | F2.2 |
| [../data-model.md](../data-model.md) | tabele `zus_payment`, `period`, `filing`, `obligation`, `setting` (klucze `opening.*`); „Stany encji”: „Złożenie” | F2.3 |
| [../tax-rules.md](../tax-rules.md) | „Stawki ryczałtu”, „Płatności, różnice kursowe i metoda kasowa”, „Korekty przychodu”; z „Kursów walut” wiersze PIT i różnicy kursowej oraz tabela „Daty, od których liczy się kurs” | F2.2 |
| [../tax-rules.md](../tax-rules.md) | „Zaliczka na ryczałt”, „Składki ZUS”, „Parametry”, „Kryteria odbioru (szkic)”; z „Kursów walut” wiersz „progi w euro” | F2.3 |
| [../compliance-calendar.md](../compliance-calendar.md) | sekcje F2 z jego „Jak czytać”: „Zamknięcie miesiąca”, „Blokada okresu”, „Bilans otwarcia”, kwoty w „Tabeli obowiązków”, „Stany obowiązku”; „Uwagi do wierszy”: `vat_ue` od F2 | F2.3 |
| [../modules/freshbooks.md](../modules/freshbooks.md) | części F2: „Połączenie OAuth”, „Polling i kursory”, „Stripe i płatności”, „DTO faktury niezależne od źródła”, „Import 2026 do ewidencji (F2)”; ze „Znacznika gotowości i blokady P_2” dwa zdania wstępu (szkic w `draft` przed F3) | F2.2 |
| [../modules/ksef.md](../modules/ksef.md) | „Synchronizacja Subject1 i Subject2”: tabela „Skutki zapisu”, wiersze `subject1`; „Korekty KOR”: wiersz „oryginał sprzed przejścia, spoza KSeF” | F2.2 |
| [../approval-gate.md](../approval-gate.md) | „Przedmioty zatwierdzeń”: złożenie poza kokpitem i dry run bez zatwierdzenia | F2.3 |
| [../architecture.md](../architecture.md) | „Konektory”, „Środowiska i cele per konektor”, „Liczniki limitów” (wiersze `freshbooks`) | F2.2 |
| [../security.md](../security.md) | „Inwentarz sekretów” (wiersze F2, w tym `auth_data`), „`audit_log` i `connector_call`” (lista akcji) | wszystkie |
| [../security.md](../security.md) | „Panel” (step-up) | F2.2, F2.3 |
| [../operations.md](../operations.md) | „Katalog zadań” (zadania F2), wiersze „ewidencja 2026” i „JPK_V7M(3)” w „Go-live” | wszystkie |
| [../operations.md](../operations.md) | wiersz `freshbooks_oauth` w „Rotacjach poświadczeń”, wiersz „KSeF odczyt” w „Go-live” | F2.2 |
| [../testing.md](../testing.md) | „Fikcyjny zbiór JPK”, „Złote przypadki” (podsekcje „Ewidencja i JPK_EWP(4)”, „Czas, daty i kursy”), „Testy własności”, „Dowód działania”, „Konwencje kryteriów odbioru”, „Kryteria przekrojowe” | wszystkie |
| [../testing.md](../testing.md) | „Polityka per konektor”, „Nagrania i odtwarzanie”, „Złote przypadki”: „Podatki” | F2.2, F2.3 |

API komponentów F0a czytasz z kodu i z `docs/evidence/f0a.md`. W F2.2 i F2.3 także F0b i F1 z ich list dowodów.

## Zakres

### Zależności F2.1 i F2.4

F2.1 i F2.4 nie używają tabel ani usług F0b i F1 (D-30). Tabela mówi, skąd biorą to, czego potrzebują.

| Potrzeba | Skąd |
| --- | --- |
| `setting` | F2.1 tworzy minimalną tabelę według [../data-model.md](../data-model.md): odczyt wiersza obowiązującego w dniu biznesowym, formularz w panelu z wpisem `setting.changed`. Klucze F2: `company.identity` (część JPK), `jpk_st.enabled`, `pit.kasowy`. F0b dokłada pozostałe klucze i przeliczenia |
| `stored_file`, `FileStore`, `audit_log`, `SecretStore`, `Redactor`, panel | F0a |
| `auth_data` (`DataUrodzenia`) | `SecretStore` z F0a; wartość PROD w „Wejściach” |
| kurs PIT | K_8 w pliku importu; `FxRateProvider` dopiero w F2.2 |
| stawka ryczałtu | K_9 w pliku importu jako kod słownika XSD; `ryczalt.service_map` dopiero w F2.2 |
| `filing` | F2.4 tworzy tabelę według [../data-model.md](../data-model.md) |
| klucze obce do `invoice`, `fx_rate`, `payment` | `revenue_entry.invoice_id`, `fx_rate_id`, `payment_id` i `fixed_asset.invoice_id` powstają bez klucza obcego. Klucz dodaje migracja F1 (`invoice`, `fx_rate`) i F2.2 (`payment`) |
| `approval`, `obligation`, `PolishCalendar` | nieużywane; dry run nie potrzebuje bramki |

### F2.1 Ewidencja i import 2026

Dom: [../modules/ewidencja.md](../modules/ewidencja.md).

| Element | Co powstaje |
| --- | --- |
| `revenue_entry` | tabela według [../data-model.md](../data-model.md); tylko dopisywanie, trigger odrzuca `UPDATE`; rodzaje `regular`, `correction`, `fx_difference`; `lp` pod blokadą na rok; `year` z K_3 |
| import | `app:ledger:import import 2026`: XML JPK_EWP(4) z walidacją XSD przed importem albo CSV z K_1–K_10 („Format importu” w [../modules/ewidencja.md](../modules/ewidencja.md)) |
| pola z pliku | K_2 bez zmian (D-23); pusty K_2 w wierszu CSV to dzień importu w Europe/Warsaw, nigdy wcześniejszy; K_8 i K_9 bez przeliczeń w kokpicie (D-30) |
| walidacja | pusty K_8, K_9 spoza słownika XSD, K_3 poza rokiem albo XML niezgodny z XSD: odmowa całego pliku, zero wierszy |
| idempotencja | ponowny import tego samego albo nowszego pliku dopisuje tylko nowe wiersze; klucz deduplikacji z „Modelu wpisu” |
| kolejność źródeł | `import` odmawia w roku z wierszami innego źródła |
| sumy | komenda wypisuje liczbę wierszy i sumę K_8 per miesiąc K_3 i stawkę; porównujesz je z narzędziem (uzgodnienie „import”) |
| `setting` | minimalna tabela z „Zależności F2.1 i F2.4” |

### F2.4 Dry run JPK

Dom: [../modules/jpk.md](../modules/jpk.md), mapowanie w [../modules/ewidencja.md](../modules/ewidencja.md).

| Element | Co powstaje |
| --- | --- |
| XSD | JPK_EWP(4) z F2.1 i JPK_ST(1) w `resources/xsd` z sumami kontrolnymi; walidacja z `LIBXML_NONET` |
| generatory | `src/Xml`: JPK_EWP(4) z `revenue_entry`, JPK_ST(1) z `fixed_asset`; `filing.schema_version` |
| `Podmiot1` | NIP, imię, nazwisko i `KodUrzedu` z `company.identity`; `DataUrodzenia` z `auth_data` przez `SecretStore`; `Kasowy_PIT` z `pit.kasowy` |
| kontrola pliku | `LiczbaWierszy` i `SumaPrzychodow` równe wierszom pliku |
| `filing` | dry run zapisuje `filing` w stanie `draft` z wierszy swojego `environment` |
| dry run | `app:jpk:dry-run`: bez bramki, bez konektora `jpk` i bez wiadomości w `official_writes` |
| zakres `--until` | `DataOd` 2026-01-01, `DataDo` 2026-12-31; wiersze z K_3 ≤ `--until`; `filing.period_start` 2026-01-01, `period_end` 2026-12-31; data `--until` w `filing.totals` |
| fikcyjny zbiór | części JPK_EWP(4) i JPK_ST(1) zbioru z [../testing.md](../testing.md): dwie stawki, korekta, różnica kursowa, środek trwały; wiersze ewidencji wchodzą z fixtures, nie z kodu F2.2 |
| `fixed_asset` | tabela według [../data-model.md](../data-model.md); mapowanie F_1–F_16 w [../modules/jpk.md](../modules/jpk.md); JPK_ST za flagą `jpk_st.enabled` |

Dry run na prawdziwych danych zostaje lokalnie. Bramka testowa MF przyjmuje tylko dane fikcyjne ([specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf)), a wysyłka to F5.

### F2.2 Źródła KSeF Subject1 i FreshBooks, uzgodnienia

Dom: [../modules/ewidencja.md](../modules/ewidencja.md), [../modules/freshbooks.md](../modules/freshbooks.md), [../modules/ksef.md](../modules/ksef.md), reguły w [../tax-rules.md](../tax-rules.md).

| Element | Co powstaje |
| --- | --- |
| stawki | `ryczalt.service_map` (usługa → PKWiU 2015 → stawka) z `valid_from`; pozycja bez mapowania daje brak wpisu i alert |
| kurs PIT | `fx_rate_id` przy wierszu ewidencji z `FxRateProvider` z F1, według reguły z „Kursów walut” |
| Subject1 | dla sprzedaży z Subject1: `invoice.revenue_date`, `invoice.fx_pit_rate_id`, `invoice_line.ryczalt_rate_pct`; te same wartości w `revenue_entry`; sprzedaż wchodzi jako `accepted`, bez `approval` |
| wpisy bieżące | dopiero po `ledger.auto_entries_enabled`: z przyjętej sprzedaży, z importu Subject1 i z rozliczonej płatności w walucie; wcześniej import Subject1 wypełnia tylko pola faktury |
| odtworzenie | `app:ledger:import freshbooks 2026` i `app:ledger:import ksef_subject1 2026` po imporcie, w dowolnej kolejności, z deduplikacją; K_2 to dzień odtworzenia (D-23) |
| wpis ręczny | formularz w panelu, `source` = `manual`, `recorded_by`, plik `evidence`, wpis `ledger.manual_entry` w `audit_log` |
| korekty | wiersz `correction` dla przyjętej KOR z Subject1 po zamknięciu zadania; KOR do faktury sprzed przejścia ma `NrKSeFN` i pusty `corrects_invoice_id` ([../modules/ksef.md](../modules/ksef.md)); błąd wpisu według tabeli „Błąd wpisu” |
| FreshBooks OAuth | aplikacja z przekierowaniem HTTPS; `freshbooks_oauth` w `credential_state`, nowy refresh token w tej samej transakcji; połączenie w panelu ze step-up z F0b |
| polling | strumienie `invoices` (`freshbooks.poll_invoices`) i `payments` (`freshbooks.poll_payments`); backfill od `sync_cursor.backfill_from` = 2026-01-01; `source_hash`; licznik `freshbooks.api`; 429 z backoffem, `Retry-After` i `rate_counter.blocked_until` |
| faktury FreshBooks 2026 | faktury sprzed przejścia, wystawione poza KSeF, trafiają tylko do `revenue_entry`; faktura z numerem z Subject1 dostaje `freshbooks_id` bez drugiego wiersza |
| szkice | nowy szkic to `invoice` w stanie `draft`, bez znacznika, bo `freshbooks.sales_enabled` jest wyłączony do F3; szkic przed Subject1 oddaje `freshbooks_id` i przechodzi do `cancelled_before_send` |
| płatności | migracja tworzy `payment` i klucz obcy `revenue_entry.payment_id`; płatności z FreshBooks; różnica kursowa jako wiersz `fx_difference` |
| DTO | adapter FreshBooks zamienia fakturę na DTO niezależne od źródła |
| uzgodnienia | FreshBooks, KSeF Subject1 per faktura z dowolnego `source`, duplikaty między źródłami i import według „Uzgodnień” w [../modules/ewidencja.md](../modules/ewidencja.md); widok „Uzgodnienia” w panelu z eksportem CSV |

- W F2 polling tworzy szkice tylko w stanie `draft`. Znacznik gotowości, przejście do `ready` i blokady obejścia dochodzą w F3.
- Do pierwszego zapisu zwrotnego w F3 kokpit tylko czyta z FreshBooks (D-35).

### F2.3 Okres, podatki i zamknięcie

Dom: [../compliance-calendar.md](../compliance-calendar.md), reguły w [../tax-rules.md](../tax-rules.md).

| Element | Co powstaje |
| --- | --- |
| `period` | tabela według [../data-model.md](../data-model.md): stany `open`, `closed`, `reopened`, blokada, `correction_required_at` i zadanie „korekta wymagana” |
| wpis do 20. dnia | zadanie `ledger.entry_check` 15. i 20. dnia; `period.ledger_closed_at` zamyka obowiązek `ledger_entry` |
| zaliczka | wzór z odliczeniami z `zus_payment`, proporcją stawek i zaokrągleniem do pełnych złotych; kwota w obowiązku `ryczalt_advance`; uzgodnienie zaliczek |
| ZUS | `zus_payment` wpisywane ręcznie; próg zdrowotnej z przychodu narastająco; kwoty w `zus` i `health_reconciliation` |
| limit ryczałtu | metryka przychodu 2026 na panelu wobec 250 tys. EUR z projektu UD458, nieuchwalonego na 08.10.2026 ([proces druku 3147](https://api.sejm.gov.pl/sejm/term10/processes/3147)); Q-38, `ryczalt.eligibility` |
| PIT-28 | zestawienie roczne; wysyłka ręczna |
| zamknięcie miesiąca | `period.checklist` zapisany przy rejestracji złożenia `manual` JPK_V7M za M, przed stanem `accepted`; bramki kroku 4 możliwe przed F5: faktury sprzedaży z `vat_period_start` = M w stanach końcowych, koszty bez `imported`, kursy VAT i PIT, VIES dla `eu_b2b`; lista DI i bilans otwarcia w F5; przed F5 niespełniona bramka daje alert, bo kokpit tylko liczy i porównuje |
| `vat_ue` od F2 | reguła `not_applicable` dla `vat_ue` po bramkach kroku 4, czyli po zapisie `period.checklist` za M („Uwagi do wierszy” w [../compliance-calendar.md](../compliance-calendar.md)) |
| złożenie poza kokpitem | JPK_V7M albo VAT-UE z narzędzia MF: panel tworzy `filing` od razu w stanie `accepted` z `submitted_via` = `manual`, wgranym UPO i step-up; bez `approval`; wpis `filing.manual_recorded`; przyjęty JPK_V7M zamyka okres |
| bilans otwarcia | klucze `setting` `opening.vat_carry_forward_pln`, `opening.revenue_ytd`, `opening.zus_paid_ytd`, `opening.last_invoice_numbers` z `valid_from` = data przejścia; każdy z plikiem `stored_file` rodzaju `evidence`; w F2 tylko na danych fikcyjnych (D-29) |

Bez rejestracji złożenia poza kokpitem blokada okresu nie zadziała przed F5. Dlatego F2.3 ją buduje, a [../approval-gate.md](../approval-gate.md) opisuje ją jako ścieżkę bez zatwierdzenia. Przejście jest w „Złożeniu” w [../data-model.md](../data-model.md).

Wartości `opening.*` w PROD wpisujesz przy go-live JPK_V7M(3) w F5, bo data przejścia to pierwszy miesiąc VAT liczony w kokpicie ([../operations.md](../operations.md)). W F2 przychód narastająco pochodzi z `revenue_entry`, a składki 2026 wpisujesz per miesiąc w `zus_payment`. Zaliczka i próg zdrowotnej nie zależą więc od kluczy `opening.*`.

## Poza zakresem

- Wysyłka JPK_EWP(4) i JPK_ST(1) na bramkę testową i PROD, dane autoryzujące, UPO z bramki: [f5-jpk.md](f5-jpk.md).
- JPK_V7M(3) i VAT-UE z kokpitu: [f5-jpk.md](f5-jpk.md). Do tego czasu składasz w narzędziach MF i rejestrujesz UPO.
- Wartości `opening.*` w PROD: go-live JPK_V7M(3) w F5.
- Znacznik gotowości FreshBooks, blokady obejścia, zapis zwrotny (pierwszy raz na PROD, D-35), FA(3) i KOR wystawiane z kokpitu: zaślepka F3.
- Kwoty WHT, IFT-2R i CIT-10Z: przypomnienia z F0b; żadna faza nie planuje wysyłki.
- Wysyłka PIT-28: poza planem faz.

## Kolejność części

Jedna część na sesję, w kolejności z D-30. Każda część ma osobną tabelę w „Kryteriach odbioru”.

| Część | Kiedy | Zakres | Warunek startu |
| --- | --- | --- | --- |
| F2.1 | zaraz po F0a, najpóźniej 15.11.2026 | `revenue_entry`, import 2026, minimalny `setting` | F0a odebrana |
| F2.4 | po F2.1 | XSD, generatory, `fixed_asset`, `filing`, dry run na fikcyjnym zbiorze | F2.1 odebrana |
| dry run | do 15.12.2026, cel własny | „Kamień 15.12.2026” niżej | wiersze F2.1 i F2.4 z pass |
| F2.2 | po F0b i F1 | stawki, kurs PIT, Subject1, FreshBooks, płatności, wpis ręczny, korekty, uzgodnienia | F0b i F1 odebrane |
| F2.3 | po F2.2 | okres, wpis do 20. dnia, zaliczka, ZUS, PIT-28, metryka, złożenie poza kokpitem, bilans otwarcia | F2.2 odebrana |

F2.4 idzie przed F2.2, bo dry run nie potrzebuje źródeł KSeF i FreshBooks. To źródła uzgodnienia, nie warunek pliku (D-30).

## Kryteria odbioru

Komendy uruchamiasz w kontenerze `app`. Wiersze działają na danych fikcyjnych. XSD offline (`cc-25`) i rozdział środowisk (`cc-26`) są w kryteriach przekrojowych. Kroki na danych PROD są w „Kamieniu 15.12.2026” i w „Go-live”.

Kolumna „Kryterium” zaczyna się kluczem z [../naming.md](../naming.md) (D-33). Wiersz bez dopisku ma wykonawcę `ci`, wiersz `[manual]` wykonawcę `manual`. Rejestr `tests/Acceptance/f2.yaml` dostaje wiersze części w jej sesji; przed dry runem ma tylko F2.1 i F2.4.

| Zapis w „Dowodzie” | Znaczenie |
| --- | --- |
| `junit.xml`: `Nazwa*` | przypadki testowe z raportu JUnit paczki o nazwie zaczynającej się od `Nazwa` |
| `cmd/<klucz>.txt` | wyjście komendy po `Redactor` |
| `cmd/<klucz>.csv` | eksport CSV z panelu na danych fikcyjnych, po `Redactor` |
| `db/<klucz>-<tabela>.csv` | wiersze tabeli utworzone przez kryterium, po `Redactor` |
| `golden/<klucz>.diff` | różnica wyniku wobec złotego przypadku; pusty plik = zgodność |
| `jpk/<klucz>.xml` | XML z fikcyjnego zbioru; sha256 w `manifest.json` |
| `screens/<klucz>.png` | zrzut panelu z testu panelu; dane fikcyjne |
| `manual/<klucz>.*` | plik z kroku `[manual]`, położony w `var/evidence/f2/inbox/` z nazwą od klucza; bez pliku wynik `pending` |

Złote przypadki i testy własności: „Złote przypadki” i „Testy własności” w [../testing.md](../testing.md).

### F2.1

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `ledger-import-idempotent`: import idempotentny | `bin/console app:ledger:import import 2026` dwa razy na fixture CSV, potem raz na fixture dłuższym o jeden miesiąc | drugi przebieg bez nowych wierszy; trzeci dopisuje tylko wiersze nowego miesiąca | `cmd/ledger-import-idempotent.txt`: wyjście trzech przebiegów z liczbą wierszy i sha256 zrzutu `revenue_entry` |
| `ledger-import-xml`: import JPK_EWP(4) | `bin/console app:ledger:import import 2026` z fixture XML JPK_EWP(4) z datami wpisu | walidacja XSD przed importem; K_1–K_10 równe plikowi, w tym oryginalne K_2 | `cmd/ledger-import-xml.txt` z wynikiem XSD; `golden/ledger-import-xml.diff` pusty |
| `ledger-import-invalid`: odmowa błędnego pliku | `vendor/bin/phpunit --filter LedgerImportInvalid`: CSV z pustym K_8; CSV z K_9 spoza słownika XSD; XML niezgodny z XSD | odmowa całego pliku w każdym wariancie; zero wierszy `revenue_entry` | `junit.xml`: `LedgerImportInvalid*` |
| `ledger-import-late-k2`: późne K_2 z importu | `vendor/bin/phpunit --filter LedgerImportLateK2`: `MockClock` 20.11.2026, CSV z wierszem za styczeń bez K_2 | K_2 = 20.11.2026, K_3 ze stycznia; bez antydatowania (D-23) | `junit.xml`: `LedgerImportLateK2*`; `db/ledger-import-late-k2-revenue_entry.csv` z K_2 i K_3 |
| `ledger-import-order`: import po innym źródle | `vendor/bin/phpunit --filter LedgerImportOrder`: rok z wierszem `ksef_subject1` z fixture, potem `app:ledger:import import 2026` | odmowa; zero nowych wierszy | `junit.xml`: `LedgerImportOrder*` |
| `ledger-year-boundary`: granica roku | `vendor/bin/phpunit --filter LedgerYearBoundary`: wiersz importu z K_2 15.01.2027 i K_3 20.12.2026 | `year` 2026, K_2 15.01.2027, K_3 20.12.2026, kolejne `lp` roku 2026 | `junit.xml`: `LedgerYearBoundary*`; `db/ledger-year-boundary-revenue_entry.csv` |
| `ledger-import-sums`: sumy importu | `bin/console app:ledger:import import 2026` na fikcyjnym zbiorze z dwiema stawkami | liczba wierszy i suma K_8 per miesiąc K_3 i stawkę równe złotym wartościom zbioru | `cmd/ledger-import-sums.txt`; `golden/ledger-import-sums.diff` pusty |
| `revenue-entry-append-only`: tylko dopisywanie | `vendor/bin/phpunit --filter RevenueEntryAppendOnly`: `UPDATE` wiersza `revenue_entry` | błąd bazy | `junit.xml`: `RevenueEntryAppendOnly*` z kodem błędu bazy |
| `setting-minimal`: ustawienia z datą obowiązywania | `vendor/bin/phpunit --filter SettingMinimal`: dwa wiersze `jpk_st.enabled` z różnym `valid_from`; zmiana `company.identity` w panelu | odczyt daje wiersz z największym `valid_from` nie późniejszym niż data biznesowa; zmiana zostawia wpis `setting.changed` | `junit.xml`: `SettingMinimal*`; `db/setting-minimal-audit_log.csv` z `setting.changed` |

### F2.4

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `jpk-ewp-build`: budowa JPK_EWP(4) | `vendor/bin/phpunit --filter JpkEwpBuild` na fikcyjnym zbiorze | XML zgodny z XSD; `LiczbaWierszy` i `SumaPrzychodow` zgodne z wierszami; K_1 ciągłe od 1; dwie stawki K_9 | `junit.xml`: `JpkEwpBuild*`; `jpk/jpk-ewp-build.xml`; `cmd/jpk-ewp-build.txt`: wynik `LIBXML_NONET` i sha256 użytych XSD, równe sumom z `resources/xsd` |
| `jpk-ewp-podmiot1`: dane podatnika | `vendor/bin/phpunit --filter JpkEwpPodmiot1`: fikcyjne `company.identity`, `auth_data` z sejfu testowego, `pit.kasowy` = false | NIP, imię, nazwisko i `KodUrzedu` z `company.identity`; `DataUrodzenia` z `SecretStore`; brak `Kasowy_PIT` | `junit.xml`: `JpkEwpPodmiot1*` |
| `jpk-st-build`: budowa JPK_ST(1) | `vendor/bin/phpunit --filter JpkStBuild` z fikcyjnym środkiem trwałym | XML zgodny z XSD, niezależnie od `jpk_st.enabled` | `junit.xml`: `JpkStBuild*`; `jpk/jpk-st-build.xml`; `cmd/jpk-st-build.txt` z wynikiem XSD |
| `jpk-ewp-dry-run`: dry run JPK_EWP(4) | `bin/console app:jpk:dry-run JPK_EWP 2026 --until=2026-11-30` na fikcyjnym zbiorze z [../testing.md](../testing.md) | XML zgodny z XSD; `DataOd` 2026-01-01, `DataDo` 2026-12-31; wiersze z K_3 ≤ 30.11.2026; suma K_8 = suma ewidencji sty–lis; `filing` `draft` z `period_end` 2026-12-31 i datą `--until` w `totals`; zero wierszy `connector_call` konektora `jpk` i zero wiadomości w `official_writes` | `cmd/jpk-ewp-dry-run.txt` z wynikiem XSD, sumą K_8 i sumą ewidencji; `golden/jpk-ewp-dry-run.diff` pusty wobec złotych wierszy zbioru; `jpk/jpk-ewp-dry-run.xml`; `db/jpk-ewp-dry-run-filing.csv`; `db/jpk-ewp-dry-run-connector_call.csv` bez wierszy `jpk` |
| `jpk-st-dry-run`: dry run JPK_ST(1) | `bin/console app:jpk:dry-run JPK_ST 2026 --until=2026-11-30` przy `jpk_st.enabled` = false, potem true | przy false komunikat o wyłączeniu (Q-25); przy true XML zgodny z XSD | `cmd/jpk-st-dry-run-off.txt` z komunikatem; `cmd/jpk-st-dry-run-on.txt` z wynikiem XSD; `jpk/jpk-st-dry-run.xml` |

### F2.2

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `ryczalt-rate-missing`: brak stawki | `vendor/bin/phpunit --filter RyczaltServiceMapMissing`: pozycja bez wpisu w `ryczalt.service_map` | brak wpisu, alert w panelu | `junit.xml`: `RyczaltServiceMapMissing*`; `screens/ryczalt-rate-missing.png` |
| `ledger-two-rates`: dwie stawki | `vendor/bin/phpunit --filter LedgerTwoRates`: faktura z pozycjami 12% i 8,5% | dwa wiersze `regular`, po jednym na stawkę; K_1 ciągłe, bez luk | `junit.xml`: `LedgerTwoRates*`; `golden/ledger-two-rates.diff` pusty |
| `ledger-subject1-fx`: kurs PIT przy Subject1 | `vendor/bin/phpunit --filter LedgerSubject1Fx`: import faktury Subject1 w EUR przy włączonym `ledger.auto_entries_enabled` | `invoice.fx_pit_rate_id` ustawione i równe `revenue_entry.fx_rate_id`; `revenue_date` i `ryczalt_rate_pct` wypełnione | `junit.xml`: `LedgerSubject1Fx*`; `db/ledger-subject1-fx-invoice.csv` i `db/ledger-subject1-fx-revenue_entry.csv` z tym samym identyfikatorem kursu |
| `ledger-auto-entries-off`: wpisy przed włączeniem | `vendor/bin/phpunit --filter LedgerAutoEntriesOff`: import Subject1 przy `ledger.auto_entries_enabled` z `valid_from` w przyszłości | pola faktury wypełnione; zero nowych wierszy `revenue_entry` | `junit.xml`: `LedgerAutoEntriesOff*` |
| `ledger-reconstruct-late-k2`: późne K_2 przy odtworzeniu | `vendor/bin/phpunit --filter LedgerReconstruct`: odtworzenie z `MockClock` 20.11.2026 faktury ze stycznia | K_2 = 20.11.2026, K_3 = data przychodu ze stycznia | `junit.xml`: `LedgerReconstruct*`; `db/ledger-reconstruct-late-k2-revenue_entry.csv` z K_2 i K_3 |
| `ledger-manual-entry`: wpis ręczny | `vendor/bin/phpunit --filter LedgerManualEntry`: wpis ręczny w panelu z dowodem | wiersz `manual` z K_2 = dzień zapisu i plikiem `evidence`; wpis `ledger.manual_entry` | `junit.xml`: `LedgerManualEntry*`; `db/ledger-manual-entry-audit_log.csv` z `ledger.manual_entry`; `screens/ledger-manual-entry.png` |
| `ledger-entry-error`: błąd wpisu | `vendor/bin/phpunit --filter LedgerEntryError`: błąd tylko w K_8; błąd w K_3; formularz „Błąd wpisu” w panelu `/ledger` | jeden wiersz `correction` z różnicą; dla K_3 wiersz odwracający i wiersz z poprawnymi polami; oba z `corrects_entry_id` | `junit.xml`: `LedgerEntryError*`; `golden/ledger-entry-error.diff` pusty |
| `ledger-correction`: korekta z KOR | `vendor/bin/phpunit --filter LedgerCorrection`: przyjęta KOR zmniejszająca z Subject1, zadanie zamknięte | wiersz `correction` z ujemnym K_8 i `corrects_entry_id` | `junit.xml`: `LedgerCorrection*`; `golden/ledger-correction.diff` pusty |
| `freshbooks-oauth`: połączenie FreshBooks | `vendor/bin/phpunit --filter FreshBooksOAuth`: połączenie FreshBooks w panelu ze step-up na fikcyjnych fixtures | `freshbooks_oauth` w stanie `valid`; odświeżenie zapisuje nowy refresh token w tej samej transakcji | `junit.xml`: `FreshBooksOAuth*`; `db/freshbooks-oauth-credential_state.csv` bez jawnych wartości; `db/freshbooks-oauth-audit_log.csv` z `auth.step_up`; `cmd/freshbooks-oauth.txt`: wycinek logu z zamaskowanym tokenem |
| `freshbooks-rate-limit-429`: 429 FreshBooks | `vendor/bin/phpunit --filter FreshBooksRateLimit429`: fixture z odpowiedzią 429 i `Retry-After` | `blocked_until` w `rate_counter` (`freshbooks.api`); backoff; zero wywołań przed tą chwilą | `junit.xml`: `FreshBooksRateLimit429*`; `db/freshbooks-rate-limit-429-rate_counter.csv` |
| `freshbooks-ksef-dedup`: bez duplikatu z FreshBooks | `vendor/bin/phpunit --filter FreshBooksKsefDedup`: faktura FreshBooks z numerem już zaimportowanym z Subject1 | brak drugiego wiersza; `freshbooks_id` przy fakturze z KSeF | `junit.xml`: `FreshBooksKsefDedup*`; `db/freshbooks-ksef-dedup-revenue_entry.csv` z jednym wierszem; `db/freshbooks-ksef-dedup-invoice.csv` z `freshbooks_id` |
| `freshbooks-draft-before-subject1`: szkic przed Subject1 | `vendor/bin/phpunit --filter FreshBooksDraftBeforeSubject1`: polling `invoices` tworzy szkic, potem Subject1 przynosi fakturę o tym samym numerze | szkic w `draft` bez `ready` przy wyłączonym `freshbooks.sales_enabled`; potem w jednej transakcji szkic oddaje `freshbooks_id` i przechodzi do `cancelled_before_send`; faktura z KSeF ma ten `freshbooks_id`; brak alertu o luce w numeracji | `junit.xml`: `FreshBooksDraftBeforeSubject1*`; `db/freshbooks-draft-before-subject1-invoice.csv` z oboma wierszami |
| `freshbooks-poll-payments`: płatności idempotentne | `vendor/bin/phpunit --filter FreshBooksPollPayments`: dwa przebiegi `freshbooks.poll_payments` na fixture | ta sama liczba wierszy `payment`; płatność przypisana do faktury (`matched_at`) | `junit.xml`: `FreshBooksPollPayments*`; `db/freshbooks-poll-payments-payment.csv` z `matched_at` |
| `fx-difference`: różnica kursowa | `vendor/bin/phpunit --filter FxDifference`: płatność 1 000,00 EUR, przychód po 4,2500, wypłata po 4,2800 (kursy fikcyjne) | wiersz `fx_difference` +30,00 zł z `payment_id` | `junit.xml`: `FxDifference*`; `golden/fx-difference.diff` pusty wobec przykładu z [../tax-rules.md](../tax-rules.md) |
| `fx-difference-year-boundary`: różnica kursowa na granicy roku | `vendor/bin/phpunit --filter FxDifferenceYearBoundary`: płatność EUR 10.01.2027 za fakturę z 20.12.2026, inny kurs | `fx_difference`: `year` 2027, K_3 10.01.2027, K_4 z wiersza źródłowego, K_5 pusty, K_9 stawki źródłowej | `junit.xml`: `FxDifferenceYearBoundary*`; `db/fx-difference-year-boundary-revenue_entry.csv` |
| `ledger-reconciliation-report`: raport uzgodnień | `vendor/bin/phpunit --filter LedgerReconciliationReport`: eksport CSV z widoku „Uzgodnienia” na fikcyjnym zbiorze | wiersze uzgodnień FreshBooks, KSeF Subject1, duplikatów i importu według „Uzgodnień” w [../modules/ewidencja.md](../modules/ewidencja.md); każda faktura z Subject1 ma dokładnie jeden zestaw wierszy `regular` po `ksef_number`, z dowolnego `source` | `junit.xml`: `LedgerReconciliationReport*`; `cmd/ledger-reconciliation-report.csv` |

### F2.3

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `ledger-entry-check`: wpis do 20. dnia | `vendor/bin/phpunit --filter LedgerEntryCheck`: `MockClock` na 20. dnia bez wpisu za poprzedni miesiąc | alert z `ledger.entry_check`; po uzupełnieniu `ledger_closed_at` ustawione i `ledger_entry` w stanie `done` | `junit.xml`: `LedgerEntryCheck*`; `db/ledger-entry-check-period.csv` z `ledger_closed_at`; `db/ledger-entry-check-obligation.csv` z `ledger_entry` `done` |
| `ryczalt-advance`: zaliczka | `vendor/bin/phpunit --filter RyczaltAdvance` z przykładem z [../tax-rules.md](../tax-rules.md) | 2 597 zł | `junit.xml`: `RyczaltAdvance*`; `golden/ryczalt-advance.diff` pusty: przychód per stawka, odliczenia, ryczałt i zaokrąglenie jak w przykładzie |
| `ryczalt-advance-carryover`: odliczenia ponad przychód | `vendor/bin/phpunit --filter RyczaltAdvanceCarryover`: przychód 1 000,00 zł (12%), odliczenia 2 015,29 zł | zaliczka 0 zł; 1 015,29 zł przechodzi na kolejny miesiąc | `junit.xml`: `RyczaltAdvanceCarryover*`; `golden/ryczalt-advance-carryover.diff` pusty |
| `health-threshold`: progi składki zdrowotnej | `vendor/bin/phpunit --filter HealthThreshold`: przychód 59 999,99; 60 000,01; 299 999,99; 300 000,01 zł po odliczeniu składek społecznych | składka zdrowotna 498,35; 830,58; 830,58; 1 495,04 zł ([../tax-rules.md](../tax-rules.md), [ZUS 2026](https://www.zus.pl/en/-/informacja-w-sprawie-podstawy-wymiaru-sk%C5%82adki-oraz-kwoty-sk%C5%82adki-na-ubezpieczenie-zdrowotne-w-2026-r.)) | `junit.xml`: `HealthThreshold*`; `golden/health-threshold.diff` pusty |
| `ryczalt-advance-obligation`: obowiązek zaliczki | `vendor/bin/phpunit --filter RyczaltAdvanceObligation`: obowiązek `ryczalt_advance` za 11.2026 | `amount_due` z ewidencji i `zus_payment`; tytuł z symbolem PPE | `junit.xml`: `RyczaltAdvanceObligation*`; `db/ryczalt-advance-obligation-obligation.csv` z `amount_due` i tytułem |
| `health-reconciliation`: roczne rozliczenie zdrowotnej | `vendor/bin/phpunit --filter HealthReconciliation`: obowiązek `health_reconciliation` za 2026 | `amount_due` z `zus_payment` i przychodu rocznego | `junit.xml`: `HealthReconciliation*`; `db/health-reconciliation-obligation.csv` z `amount_due` |
| `pit28-summary`: zestawienie PIT-28 | `vendor/bin/phpunit --filter Pit28Summary`: zestawienie PIT-28 za 2026 na fikcyjnym zbiorze | przychód per stawka równy sumie K_8 per K_9 | `junit.xml`: `Pit28Summary*`; `golden/pit28-summary.diff` pusty |
| `ryczalt-eligibility-metric`: metryka limitu | `vendor/bin/phpunit --filter RyczaltEligibilityMetric`: metryka z fikcyjnym kursem EUR według wiersza „progi w euro” w [../tax-rules.md](../tax-rules.md) | wartość na panelu: przychód 2026 wobec 250 000 EUR po tym kursie | `junit.xml`: `RyczaltEligibilityMetric*`; `screens/ryczalt-eligibility-metric.png` |
| `opening-balance-keys`: bilans otwarcia w `setting` | `vendor/bin/phpunit --filter OpeningBalanceKeys`: klucze `opening.*` na danych fikcyjnych bez pliku, potem z plikiem `evidence`, potem zmiana wartości | bez pliku odmowa; z plikiem wiersze `setting` z `valid_from` = data przejścia i wpis `setting.changed`; zmiana to nowy wiersz, poprzedni bez zmian | `junit.xml`: `OpeningBalanceKeys*`; `db/opening-balance-keys-setting.csv`; `db/opening-balance-keys-audit_log.csv` z `setting.changed` |
| `manual-filing`: złożenie poza kokpitem | `vendor/bin/phpunit --filter ManualFiling`: JPK_V7M za 10.2026 z fikcyjnym UPO | `period.checklist` z bramkami z „Zamknięcia miesiąca” w „Zakresie” F2.3; `filing` `accepted` z `submitted_via` = `manual`, bez `approval`; okres `closed`; wpis `filing.manual_recorded` | `junit.xml`: `ManualFiling*`; `db/manual-filing-filing.csv` z sha256 UPO; `db/manual-filing-period.csv` z `checklist` i `closed`; `db/manual-filing-audit_log.csv` z `filing.manual_recorded`; `db/manual-filing-approval.csv` pusty |
| `vat-ue-after-checklist`: `vat_ue` od F2 | `vendor/bin/phpunit --filter VatUeAfterChecklist`: miesiąc bez sprzedaży `eu_b2b`, `MockClock` po 15. dniu M+1, potem rejestracja złożenia `manual` JPK_V7M za M | przed zapisem `period.checklist` obowiązek `vat_ue` za M zostaje `open`; po zapisie `not_applicable` | `junit.xml`: `VatUeAfterChecklist*`; `db/vat-ue-after-checklist-obligation.csv` |
| `closed-period-cost`: koszt w zamkniętym okresie | `vendor/bin/phpunit --filter ClosedPeriodCost`: zapis kosztu do zamkniętego okresu | odmowa i propozycja najwcześniejszego otwartego okresu | `junit.xml`: `ClosedPeriodCost*` z proponowanym okresem |
| `closed-period-revenue`: przychód w zamkniętym miesiącu | `vendor/bin/phpunit --filter ClosedPeriodRevenue`: nowy wiersz `revenue_entry` z `revenue_date` w zamkniętym miesiącu | `correction_required_at` ustawione; zadanie w panelu | `junit.xml`: `ClosedPeriodRevenue*`; `db/closed-period-revenue-period.csv` z `correction_required_at` |
| `closed-period-kor`: KOR w zamkniętym miesiącu | `vendor/bin/phpunit --filter ClosedPeriodKor`: przyjęta KOR z Subject1 z okresem w zamkniętym M | `correction_required_at` ustawione; zadanie „korekta wymagana”; wiersz `correction` w ewidencji | `junit.xml`: `ClosedPeriodKor*`; `db/closed-period-kor-period.csv` z `correction_required_at` |
| `advisor-booked`: doradca zarezerwowany | `[manual]` doradca podatkowy zarezerwowany | data przy Q-29 w [../decisions.md](../decisions.md) | wpis przy Q-29; odhaczony wiersz w `docs/evidence/f2.md` |

**Kryteria przekrojowe:** przy odbiorze F2, po F2.3, wiersze od F0a do F2 w [../testing.md](../testing.md). Przed dry runem wystarczy zielone CI z `cc-25` i `cc-26`.

## Kamień 15.12.2026

Dry run na danych PROD w `kokpit-prod` robisz Ty, po odbiorze F2.1 i F2.4, do 15.12.2026. To cel własny (D-30), a kroki nie są kryteriami odbioru F2. Pusty wiersz po 15.12.2026 oznacza, że F5 zaczyna od napraw ewidencji ([../README.md](../README.md)).

| Krok | Co robisz |
| --- | --- |
| ustawienia | `company.identity` (część JPK) i `jpk_st.enabled` w panelu `kokpit-prod`; `auth_data` w sejfie PROD ([../security.md](../security.md)) |
| import | `app:ledger:import import 2026` z eksportu narzędzia z Q-11 |
| uzgodnienie importu | liczba wierszy i suma K_8 z komendy wobec sum w narzędziu |
| dry run | `bin/console app:jpk:dry-run JPK_EWP 2026 --until=2026-11-30` i to samo dla `JPK_ST`; przy `jpk_st.enabled` = false komunikat i powód w kolumnie Uwagi |

- Pliki z PROD zostają w `kokpit-prod`. Tu trafiają tylko liczby, sumy i sha256, bez NIP, nazw i zrzutów ekranu.
- Do go-live ewidencji wpisy bieżące robisz dalej w narzędziu z Q-11. Ponowny `app:ledger:import import 2026` z nowszym eksportem dopisuje tylko nowe wiersze.

| Wiersz | Data | Wynik (XSD albo uzgodnienie) | Suma K_8, liczba pozycji albo liczba różnic | sha256 pliku | Uwagi |
| --- | --- | --- | --- | --- | --- |
| uzgodnienie importu, sty–lis 2026 | | | | | |
| JPK_EWP(4), sty–lis 2026 | | | | | |
| JPK_ST(1), sty–lis 2026 | | | | | |

## Dowód fazy

**Dowód fazy:** `docs/evidence/f2.md`, jedna lista dla wszystkich części. Wiersze F2.1 i F2.4 mają pass przed dry runem; F2 jest odebrana po F2.3. Status F2 w [../README.md](../README.md) linkuje listę po odbiorze.

| Element | Treść |
| --- | --- |
| paczka | `bin/console kokpit:evidence f2 --only=ci` → `var/evidence/f2/<YYYYMMDD-HHMM>/`; `manifest.json`: sha commita, cele konektorów, per klucz kryterium wynik `pass`, `fail` albo `pending` i pliki z sha256, wersje narzędzi; pliki `[manual]` z `var/evidence/f2/inbox/`; całość po `Redactor` |
| CI | job `evidence` uruchamia wiersze `ci` i wgrywa `var/evidence/**` jako artefakt przebiegu w `good-yellow-bee/kokpit-jdg`; `var/` jest w `.gitignore`; F2 nie ma wierszy `live` |
| lista | `docs/evidence/f2.md`, pisana ręcznie (D-28): data, commit, link do przebiegu CI, kryterium → pass albo fail → artefakt, odhaczone `[manual]`; najpierw części F2.1 i F2.4, potem F2.2 i F2.3 |
| odbiór | F2 jest odebrana, gdy lista istnieje, wszystkie wiersze mają pass, a wiersze `[manual]` odhaczyłeś Ty; „Kamień 15.12.2026” jest osobno |

## Go-live

Sekwencja i wycofanie: wiersz „ewidencja 2026” w „Go-live” w [../operations.md](../operations.md). Kroki pochodzą z „Przełączenia i uzupełnienia 2026” w [../modules/ewidencja.md](../modules/ewidencja.md).

| Warunek wejścia | Źródło |
| --- | --- |
| wypełniony „Kamień 15.12.2026” | ta sekcja wyżej |
| F2.2 i F2.3 odebrane | „Kryteria odbioru” |
| F1 na PROD z backfillem Subject1; FreshBooks PROD połączony osobną aplikacją OAuth, ze step-up | wiersz „KSeF odczyt” w [../operations.md](../operations.md), [../modules/freshbooks.md](../modules/freshbooks.md) |

1. Kroki 2–4 robisz bez przerwy, najlepiej jednego dnia: zatrzymujesz wpisy w narzędziu i eksportujesz ostatni plik; `app:ledger:import import 2026`; włączasz `ledger.auto_entries_enabled` z `valid_from` = dzień włączenia.
2. Odtworzenie: `app:ledger:import freshbooks 2026` i `app:ledger:import ksef_subject1 2026`, z deduplikacją.
3. Uzgodnienie Subject1 per faktura: każda Twoja faktura z Subject1 ma dokładnie jeden zestaw wierszy `regular`, z dowolnego `source`. Liczba i suma są zgodne z Aplikacją Podatnika KSeF.
4. Krok 10: od go-live ewidencję prowadzi tylko kokpit; stare narzędzie wyłączasz dla tego obowiązku („Teraz – zgodność” w [../README.md](../README.md)).

- Pierwsza operacja PROD po go-live to wpisy bieżącego miesiąca do 20. dnia. Wycofanie to eksport ewidencji.
- Wartości `opening.*` w PROD wpisujesz przy go-live JPK_V7M(3) w F5, nie w F2.

## Pierwszy prompt

```text
Przeczytaj docs/README.md i docs/phases/f2-ewidencja.md, potem tylko pliki z sekcji „Ładuj tylko” dla tej części.
Krok 0: sprawdź docs/naming.md, wiersze F2 w docs/decisions.md i fakty F2 w docs/facts.md.
Brak wejścia oznaczonego „stop” oznacza stop i pytanie, w tym XSD.
Zrealizuj jedną część z sekcji „Kolejność części”: pierwszą, której kryteria nie mają pass, a warunek startu jest spełniony.
F2.1 i F2.4 nie używają tabel ani usług F0b i F1. Bez wysyłki do urzędu; dry run JPK zostaje lokalnie.
Zanim zaczniesz kodować, wypisz plan i pytania. Na koniec uruchom testy i bin/console kokpit:evidence f2 --only=ci,
pokaż wynik kryteriów tej części i otwarte wiersze [manual]; po F2.3 także kryteria przekrojowe z docs/testing.md.
```
