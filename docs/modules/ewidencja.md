# Ewidencja przychodów

Ewidencja przychodów w `revenue_entry`: obowiązek, model wpisu, import 2026, mapowanie JPK_EWP(4), wpisy do 20. i uzgodnienia. Stan na 8 października 2026.

## Jak czytać

| Część | Sekcje |
| --- | --- |
| F2.1 | „Obowiązek i terminy”, „Model wpisu”, „Import 2026 (F2.1)”, „Format importu” |
| F2.4 | „Mapowanie JPK_EWP(4)” z „Dry runem”, „Wykaz środków trwałych i WNiP” |
| F2.2/F2.3 | „Źródła wpisów”, „Wpis do 20. dnia”, „Przełączenie i uzupełnienie 2026”, „Korekty”, „Uzgodnienia” |
| F5 | „Mapowanie JPK_EWP(4)”, „Korekty”, „Uzgodnienia” |

- Kolejność części pochodzi z D-30: F2.1 i F2.4 idą przed F0b i F1, a F2.2/F2.3 po F1.
- F2.1 i F2.4 nie używają `FxRateProvider`, `ryczalt.service_map`, bramki zatwierdzeń ani obowiązków. K_8 i K_9 przychodzą policzone w pliku importu.
- Kolumny i klucze `revenue_entry` są w [data-model.md](../data-model.md). Tu są reguły wpisu, importu i mapowanie.
- Kurs PIT, data przychodu, stawki i różnice kursowe definiuje [tax-rules.md](../tax-rules.md).
- Wysyłkę pliku (API JPK, dane autoryzujące, UPO) opisuje [jpk.md](jpk.md). Terminy są w [compliance-calendar.md](../compliance-calendar.md).
- Nazwy pochodzą z [naming.md](../naming.md).

## Obowiązek i terminy

| Fakt | Źródło |
| --- | --- |
| Podatnik składający JPK_V7M prowadzi ewidencję w programie komputerowym od 1.01.2026 i przesyła ją po roku jako JPK_EWP | [ustawa o ryczałcie, art. 15 ust. 12](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf), [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf) |
| Wpisy na podstawie faktur i innych dowodów najpóźniej do 20. dnia każdego miesiąca za miesiąc poprzedni | [rozporządzenie, § 6, Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf) |
| Plik JPK_EWP przekazujesz do upływu terminu zeznania, czyli do 30 kwietnia | [ustawa o ryczałcie, art. 21 ust. 2 pkt 2](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) |
| Ewidencja jest rzetelna mimo braków, gdy uzupełnisz ją przed kontrolą albo w okresie prawa do korekty; także przy błędach do 0,5% przychodu | [rozporządzenie, § 3 ust. 4 pkt 1 i 3](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf) |
| Przedłużenie terminów JPK na 2026 dotyczy tylko ksiąg rachunkowych, nie JPK_EWP | [Dz.U. 2026 poz. 779](https://api.sejm.gov.pl/eli/acts/DU/2026/779/text.pdf) |
| Projekt zwolnienia z ewidencji elektronicznej na 2027–2028 obejmuje tylko podatników niebędących czynnymi podatnikami VAT; JPK_EWP zostaje obowiązkowy | [projekt](https://legislacja.gov.pl/projekt/12413609) |

**Teraz, przed kokpitem**

- Narzędzie musi eksportować plik w kształcie z „Formatu importu”. Bez tego F2.1 nie ma wejścia.
- Do przełączenia w F2.2/F2.3 wpis do 20. dnia robisz w tym narzędziu. MF nie daje darmowego narzędzia do ewidencji ([facts.md](../facts.md)).

**Odpowiedź na komentarz w dokumencie claude.ai (D-02)**

- Tak: przy VAT rozliczanym miesięcznie pierwszy JPK_EWP jest za 2026, z terminem 30.04.2027 (piątek, bez przesunięcia).
- Inny okres rozliczenia VAT albo zwolnienie z VAT dałyby pierwszy plik za 2027, z terminem w 2028 ([broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf)). Ten wariant Cię nie dotyczy.

## Model wpisu

| Reguła | Szczegół |
| --- | --- |
| jeden wiersz na fakturę i stawkę | `kind = 'regular'`; stawka z `invoice_line.ryczalt_rate_pct` |
| tylko dopisywanie | błąd poprawia wiersz `correction` (sekcja „Korekty”), nigdy `UPDATE` |
| `lp` (K_1) | ciągła numeracja w roku, nadawana pod blokadą w kolejności zapisu, więc zgodna z K_2; wiersz `import` bierze K_1 z pliku |
| `entry_date` (K_2) | faktyczny dzień zapisu w Europe/Warsaw; wiersz `import` bierze K_2 z pliku; kokpit nigdy nie antydatuje (D-23) |
| `revenue_date` (K_3) | data uzyskania przychodu według [tax-rules.md](../tax-rules.md) |
| `amount_pln` (K_8) | kwota netto w stawce × kurs PIT; ujemna przy korekcie zmniejszającej i ujemnej różnicy kursowej; wiersz `import`: K_8 z pliku |
| `rate_pct` (K_9) | stawka wiersza; pozycja bez stawki blokuje wpis; wiersz `import`: K_9 z pliku |
| `fx_difference` | wiersz z `payment_id` dla różnicy kursowej ([tax-rules.md](../tax-rules.md)); pola w tabeli „Wiersz `fx_difference`” niżej |
| `correction` | wiersz z `corrects_entry_id` albo `invoice_id` faktury KOR; pola w tabelach „Wiersz `correction` z KOR” i „Błąd wpisu” w sekcji „Korekty” |
| `import` | wiersz z pliku narzędzia; pola w tabeli „Wiersz `import`” w „Imporcie 2026 (F2.1)” |
| `year` | rok `revenue_date` (K_3) wiersza, w każdym rodzaju; plik roku N zawiera wiersze `year = N` w danym `environment` |

- Wpis ze stycznia N+1 za grudzień N dostaje `year = N` i kolejne `lp` roku N.
- Wpis z faktury powstaje z DTO niezależnego od źródła. FreshBooks, KSeF Subject1 i wpis ręczny dają ten sam kształt (D-25).
- Wiersz `import` nie powstaje z faktury. Czyta K_1–K_10 z pliku.
- Deduplikacja działa w każdym `source`, także wobec wierszy `import`. Klucz: najpierw `ksef_number`, potem (`document_number`, `counterparty_tax_id`, `year`).
- Kopia z inną `revenue_date` nie daje drugiego wiersza. Różnica dat trafia do raportu różnic (sekcja „Uzgodnienia”).
- Wpis z faktury nie wymaga bramki zatwierdzeń: to zapis w kokpicie, nie w urzędzie.

**Wiersz `fx_difference`**

| Płatność wskazuje | Wiersze źródłowe |
| --- | --- |
| `payment.invoice_id` | wiersze `regular` tej faktury |
| `payment.revenue_entry_id` | wiersze `regular` z tym samym `document_number`, `environment` i `year` co wskazany wiersz, z dowolnego `source` |

Jeden wiersz `fx_difference` powstaje na płatność i wiersz źródłowy.

| Pole | Wartość |
| --- | --- |
| K_3 `revenue_date` | dzień wpływu: `payment.paid_on` ([tax-rules.md](../tax-rules.md)) |
| K_4 `document_number` | dowód wpływu: `payment.freshbooks_payment_id` |
| K_5 `ksef_number` | pusty |
| K_6, K_7 | z wiersza źródłowego |
| K_8 `amount_pln` | różnica kursowa ze znakiem według [tax-rules.md](../tax-rules.md), w części wiersza źródłowego |
| K_9 `rate_pct` | stawka wiersza źródłowego; reguła domyślna w [tax-rules.md](../tax-rules.md) |
| K_10 `remarks` | numer faktury: `document_number` wiersza źródłowego |

- K_1 i K_2 nadawane jak w każdym wierszu.
- Kurs dnia wpływu: `payment.actual_rate` tylko przy przewalutowaniu w dniu `paid_on`; inaczej `payment.fx_rate_id` ([tax-rules.md](../tax-rules.md)).
- Przy kilku wierszach źródłowych kokpit dzieli różnicę proporcjonalnie do ich K_8.
- Faktura z grudnia 2026 zapłacona w styczniu 2027 daje wiersz `fx_difference` z `year` = 2027.
- Dzień wpływu przy płatności przez bramkę jest w zakresie przeglądu doradcy ([tax-rules.md](../tax-rules.md)). K_4 i K_10 tego wiersza też są punktem przeglądu (Q-29, D-04).

## Import 2026 (F2.1)

Ewidencja 2026 to plik z narzędzia z Q-11: wpisy od 09.2026 i miesiące odtworzone (D-23). F2.1 importuje go do `revenue_entry`, a dry run bierze tylko te wiersze (D-30).

| Krok | Czynność | Wynik |
| --- | --- | --- |
| 1 | narzędzie z eksportem z „Formatu importu”; narzędzie i format zapisujesz przy Q-11 | wpisy do 20. dnia w narzędziu, aż do przełączenia |
| 2 | miesiące bez wpisu (sty–sie 2026) wpisujesz w narzędziu albo dopisujesz w pliku importu, z policzonymi K_8 i K_9 (D-23) | plik z pełną ewidencją roku |
| 3 | `app:ledger:import import 2026` z plikiem eksportu | wiersze `import` z K_1 i K_2 z pliku |
| 4 | przed dry runem: świeży eksport i ponowny import | dopisane tylko nowe wiersze |
| 5 | dry run do 15.12.2026 na danych sty–lis 2026 („Dry run” niżej) | pliki lokalnie, bez wysyłki |

**Wiersz `import`**

| Pole | Wartość |
| --- | --- |
| `lp`, K_2–K_10 | z pliku; puste K_2 dostaje dzień importu w Europe/Warsaw |
| `source`, `kind` | `import`, `regular`; także przy ujemnym K_8, bo plik nie niesie rodzaju wiersza |
| `year` | rok z argumentu komendy; K_3 każdego wiersza leży w tym roku |
| `fx_rate_id`, `invoice_id`, `payment_id`, `corrects_entry_id`, `original_amount`, `original_currency`, `recorded_by` | puste |

- Import nie liczy kursów ani stawek. K_8 i K_9 bierze z pliku, bez `FxRateProvider` i `ryczalt.service_map`.
- Każdy import czyta pełną ewidencję roku. Wiersze z K_1 już w bazie muszą być identyczne; nowe K_1 komenda dopisuje.
- Puste K_2 w pliku pasuje do dowolnego K_2 w bazie: porównanie pomija wtedy K_2.
- Odmowa bez zapisu, z raportem różnic: luka albo duplikat K_1, zmieniony albo brakujący znany wiersz, K_3 spoza roku, wiersze innego `source` w roku.
- Błąd we wpisie narzędzia poprawiasz nowym wpisem korygującym. Edycja starego wpisu zatrzyma kolejny import.
- Odtworzone miesiące najprościej wpisujesz w narzędziu. Wtedy eksport jest pełną ewidencją, a K_1 numeruje narzędzie.
- Miesiące dopisane w CSV dostają K_1 po wpisach narzędzia. Nowe wpisy narzędzia numerujesz potem w pliku po nich, żeby K_1 było ciągłe.
- Komenda zapisuje plik w `FileStore` jako `stored_file` rodzaju `evidence` przy dopisanych wierszach i wypisuje jego sha256. To dowód pochodzenia K_2.
- Wpisy odtworzone mają K_2 równe faktycznej dacie wpisu.
- § 3 ust. 4 pkt 3 rozporządzenia uznaje ewidencję uzupełnioną przed kontrolą za rzetelną. Skutek późnych K_2 to punkt przeglądu doradcy (D-23, Q-29).
- Dry run na prawdziwych danych zostaje lokalnie. Środowisko testowe MF przyjmuje tylko dane fikcyjne ([jpk.md](jpk.md)).

## Format importu

Narzędzie wybierasz tak, by eksportowało jeden z formatów niżej. Sprawdzasz to próbnym eksportem wpisów za 09.2026 (przegląd M5).

| Format | Kiedy | Reguła |
| --- | --- | --- |
| JPK_EWP(4) XML z narzędzia | cały rok jest w narzędziu | walidacja XSD z `resources/xsd` przed importem; K_1–K_10 wprost |
| CSV | miesiące odtworzone dopisujesz w pliku albo narzędzie nie eksportuje XML | kolumny w tabeli „CSV” niżej |

**CSV**

| Element | Reguła |
| --- | --- |
| plik | UTF-8, separator `;`, pierwszy wiersz to nagłówek `K_1;K_2;K_3;K_4;K_5;K_6;K_7;K_8;K_9;K_10` |
| K_1 | liczba całkowita od 1, bez luk |
| K_2, K_3 | data `RRRR-MM-DD`; K_2 puste tylko w miesiącach odtworzonych w pliku |
| K_4 | wymagane, do 256 znaków |
| K_5, K_6, K_7, K_10 | opcjonalne; K_6 to kod kraju ze słownika XSD, K_7 do 50 znaków |
| K_8 | kwota w PLN z dwoma miejscami po przecinku; separator dziesiętny `,` albo `.`, bez separatora tysięcy; minus przy kwocie ujemnej |
| K_9 | wartość ze słownika K_9 w XSD JPK_EWP(4); import mapuje ją na `rate_pct` |

- Import sprawdza typy i słowniki XSD przed zapisem. Błąd w jednym wierszu odrzuca cały plik.
- Plik próbny z prawdziwymi danymi zostaje u Ciebie. Fixture F2.1 to fikcyjny plik w tym samym kształcie ([testing.md](../testing.md)).

## Mapowanie JPK_EWP(4)

Struktura według [broszury JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf). Wartości słownikowe (K_6, K_9, KodUrzedu) pochodzą z XSD w `resources/xsd`.

**Wejścia F2.4.** Generator działa przed F0b (D-30) i potrzebuje tylko:

- `setting` `company.identity` (część JPK), `pit.kasowy` i `jpk_st.enabled`;
- `auth_data` przez `SecretStore`, tylko dla `DataUrodzenia`;
- XSD JPK_EWP(4) i JPK_ST(1) w `resources/xsd` z sumami kontrolnymi.

Skąd F2.4 bierze `setting` przed F0b, wskazuje [../phases/f2-ewidencja.md](../phases/f2-ewidencja.md).

**Nagłówek (`Naglowek`)**

| Pole | Wartość |
| --- | --- |
| `KodFormularza` | atrybuty `kodSystemowy` = „JPK_EWP (4)”, `wersjaSchemy` = „1-0” |
| `WariantFormularza` | 4 |
| `CelZlozenia` | 1 przy pierwszym złożeniu, 2 przy korekcie; `filing.purpose`. Wartość 0 (na żądanie) jest poza zakresem |
| `DataWytworzeniaJPK` | chwila budowy pliku |
| `DataOd`, `DataDo` | 1 stycznia i 31 grudnia roku, także w dry runie |
| `KodUrzedu` | kod urzędu skarbowego z `company.identity` |

**Podmiot (`Podmiot1`)**

| Pole | Wartość |
| --- | --- |
| `OsobaFizyczna`: `NIP`, `ImiePierwsze`, `Nazwisko`, `Email`, `Telefon` | `company.identity`; email i telefon opcjonalne |
| `OsobaFizyczna`: `DataUrodzenia` | z `auth_data` przez `SecretStore` przy budowie pliku ([security.md](../security.md)); nie z `company.identity` |
| `rola` | stała „Podatnik” |
| `Kasowy_PIT` | „1” przy `pit.kasowy` = true; inaczej pole pominięte |

- `company.identity` ma część FA(3) i część JPK ([data-model.md](../data-model.md)). Część FA(3) (NIP, numer VAT UE, nazwa, adres, kraj) opisuje [ksef.md](ksef.md).
- Część JPK to `KodUrzedu` ze słownika XSD, pierwsze imię, nazwisko oraz opcjonalnie e-mail i telefon.

**Wiersze (`EWPWiersz`)**

| Pole | Znaczenie | Kolumna `revenue_entry` |
| --- | --- | --- |
| K_1 | liczba porządkowa | `lp` |
| K_2 | data wpisu | `entry_date` |
| K_3 | data uzyskania przychodu | `revenue_date` |
| K_4 | numer dowodu, do 256 znaków | `document_number` |
| K_5 | numer KSeF, opcjonalny | `ksef_number` |
| K_6 | kod kraju nadania identyfikatora kontrahenta, opcjonalny | `counterparty_country` |
| K_7 | identyfikator podatkowy kontrahenta, do 50 znaków, opcjonalny | `counterparty_tax_id` |
| K_8 | kwota przychodu w PLN | `amount_pln` |
| K_9 | stawka ze słownika XSD | `rate_pct` → kod słownika |
| K_10 | uwagi, opcjonalne | `remarks` |

**Sumy kontrolne (`EWPCtrl`)**

| Pole | Wartość |
| --- | --- |
| `LiczbaWierszy` | liczba wierszy pliku |
| `SumaPrzychodow` | suma K_8 |

- K_6 i K_7 wypełniasz z `buyer_snapshot` faktury. Dla wierszy bez kontrahenta zostają puste. Wiersz `import` ma je z pliku.
- K_10 jest domyślnie pusty. Przy wpłacie zaliczki co kwartał może zawierać datę otrzymania przychodu (broszura).
- Oznaczenie wpisów odtworzonych w K_10 to punkt przeglądu doradcy (Q-29).

**Dry run (`app:jpk:dry-run JPK_EWP <rok> --until=<data>`)**

- Dry run do 15.12.2026 to Twój cel, nie termin ustawowy (D-30). Działa bez bramki i bez konektora `jpk`.
- Generator nie rozróżnia `source`. Na danych PROD ewidencja ma wtedy tylko wiersze `import` sty–lis 2026.
- Nagłówek ma `DataOd` 1 stycznia i `DataDo` 31 grudnia roku. `filing.period_start` i `filing.period_end` obejmują cały rok.
- `--until` filtruje tylko wiersze: `revenue_date` (K_3) ≤ data. K_2 nie filtruje, bo wpis za listopad może mieć K_2 z grudnia.
- Po filtrze K_1 może mieć luki: wpis za grudzień zapisany przed późnym wpisem za listopad wypada. Ciągłość K_1 obowiązuje tylko plik całego roku, bez `--until`.
- F5 przebudowuje wiersz `filing` z dry runu w miejscu, bo klucz jest ten sam ([data-model.md](../data-model.md)).

## Źródła wpisów

| Źródło | `source` | Zakres | Część |
| --- | --- | --- | --- |
| Twoje narzędzie z Q-11 | `import` | cały 2026 do przełączenia: wpisy narzędzia i miesiące odtworzone (D-23) | F2.1 |
| FreshBooks | `freshbooks` | sprzedaż od 1.01.2026 wystawiona poza KSeF; od F3 sprzedaż wysłana z kokpitu | F2.2/F2.3 (uzupełnienie), F3 |
| KSeF Subject1 | `ksef_subject1` | Twoje faktury w KSeF wystawione poza kokpitem; backfill od 1.02.2026 ([ksef.md](ksef.md)) | F1 (dane), F2.2/F2.3 (wpisy) |
| panel | `manual` | przychód udokumentowany innym dowodem | F2.2/F2.3 |

Sprzedaż sprzed przejścia wystawiona poza KSeF nie trafia do `invoice`. Jest tylko w `revenue_entry` ze źródłem `freshbooks` albo `import` ([data-model.md](../data-model.md)).

## Wpis do 20. dnia

Od przełączenia (F2.2/F2.3) wpis robi kokpit. Wcześniej robisz go w narzędziu z Q-11 („Obowiązek i terminy”).

1. Po włączeniu `ledger.auto_entries_enabled` (sekcja „Przełączenie i uzupełnienie 2026”) wpis powstaje od razu. Tworzą go: przyjęta faktura `VAT`, import Subject1 i rozliczona płatność w walucie.
2. K_2 to dzień zapisu. KOR tworzy zadanie w panelu, a wiersz powstaje po jego zamknięciu (sekcja „Korekty”).
3. Przed włączeniem import Subject1 z F1 wypełnia tylko pola faktury: `revenue_date`, `fx_pit_rate_id`, `ryczalt_rate_pct`. Płatność czeka z pustym `fx_posted_at`.
4. Zadanie `ledger.entry_check` 15. i 20. dnia miesiąca szuka dokumentów przychodu z poprzedniego miesiąca bez wpisu ([operations.md](../operations.md)).
5. Gdy miesiąc M ma komplet, `period.ledger_closed_at` ustawia `ledger.entry_check` albo zapis, który uzupełnia ostatni brak. To zamyka obowiązek `ledger_entry` za M.
6. Miesiąc bez dokumentów zamyka pierwszy przebieg `ledger.entry_check` po jego końcu.
7. Faktura z pozycją bez stawki ryczałtu nie dostaje wpisu. Alert wskazuje brakujący wpis w `ryczalt.service_map`.

**Dokumenty przychodu miesiąca M**

| Dokument | Należy do M, gdy | Wymagane wiersze |
| --- | --- | --- |
| przyjęta faktura sprzedaży `VAT` | `revenue_date` w M | `regular` na każdą stawkę |
| przyjęta faktura KOR | `issue_date` (P_1) w M | `correction` na każdą stawkę |
| faktura FreshBooks sprzed przejścia, poza KSeF | data przychodu z DTO w M | `regular` na każdą stawkę |
| rozliczona płatność w walucie, od przełączenia | `paid_on` w M | `fx_difference` na każdy wiersz źródłowy |

- Komplet oznacza, że każdy dokument M ma swoje wiersze. Kokpit szuka ich kluczem deduplikacji, w każdym `source`.
- Pozycja bez stawki oznacza brak kompletu.

**Alerty**

- Alerty ewidencji to widoki panelu liczone zapytaniem: brak wpisu, brak stawki, duplikat między źródłami. Nie mają tabeli ani nowej wartości `notification.category`.
- Push idzie tylko przez przypomnienia obowiązku `ledger_entry` (T−5, T−1, [compliance-calendar.md](../compliance-calendar.md)).
- Osobny push dla alertu wymaga najpierw nowej wartości `notification.category` w [naming.md](../naming.md).

## Przełączenie i uzupełnienie 2026

Przełączenie to dzień, od którego ewidencję prowadzi tylko kokpit. Następuje w F2.2/F2.3, po F0b i F1 (D-30).

| Krok | Czynność | Wynik |
| --- | --- | --- |
| 1 | ostatni import z narzędzia: `app:ledger:import import 2026` z pełnym eksportem | wszystkie wpisy narzędzia w `revenue_entry` |
| 2 | zatrzymujesz wpisy w narzędziu i włączasz `ledger.auto_entries_enabled` z `valid_from` = dzień włączenia | dalej wpisy robi tylko kokpit |
| 3 | uzupełnienie: `app:ledger:import freshbooks 2026` i `app:ledger:import ksef_subject1 2026` w dowolnej kolejności | wiersze tylko dla dokumentów bez wpisu; K_2 z dnia uzupełnienia |
| 4 | różnice kursowe płatności sprzed przełączenia według reguły niżej | brak płatności z pustym `fx_posted_at` |
| 5 | uzgodnienia z sekcji „Uzgodnienia” | raport różnic do wyjaśnienia |
| 6 | porównanie przychodu narastająco z kluczem `setting` `opening.revenue_ytd` ([compliance-calendar.md](../compliance-calendar.md)) | różnica to alert |
| 7 | go-live: wiersz „ewidencja 2026” w „Go-live” w [operations.md](../operations.md) | ewidencję prowadzi tylko kokpit |

- Kroki 1–3 robisz bez przerwy, najlepiej jednego dnia. Termin 20. biegnie dalej.
- Po kroku 3 rok ma wiersze innego `source`, więc kolejny import tego roku odmówi.
- Przełączenie w 2027 oznacza kroki 1 i 3 także za 2027, jeśli narzędzie ma już wpisy 2027.
- Uzupełnienie szuka dokumentów kluczem deduplikacji z „Modelu wpisu”. Późne K_2 są jawne (D-23).
- Przed przełączeniem uzgodnienia działają tylko jako raport, bez zapisu wierszy.
- Różnice kursowe sprzed przełączenia: gdy narzędzie je ujmowało (Q-11), płatność dostaje `fx_posted_at` bez nowego wiersza.
- Gdy narzędzie ich nie ujmowało, kokpit dopisuje `fx_difference` z K_2 z dnia uzupełnienia.

## Korekty

| Zdarzenie | Zapis |
| --- | --- |
| przyjęta faktura KOR | zadanie w panelu ([ksef.md](ksef.md)); po jego zamknięciu wiersz `correction` na każdą stawkę, pola w tabeli „Wiersz `correction` z KOR” niżej |
| korekta do zera i faktura zastępcza | wiersz `correction` do zera i nowy wiersz `regular` dla faktury zastępczej, z jej `invoice_id` |
| błąd wpisu | formularz „Błąd wpisu” w panelu `/ledger`; wiersze `correction` z tabeli „Błąd wpisu” niżej; nigdy drugi wiersz `regular` tej samej faktury i stawki |
| różnica kursowa | wiersz `fx_difference`, nie korekta |
| wpis z `revenue_date` w zamkniętym miesiącu | `period.correction_required_at` i zadanie w panelu ([compliance-calendar.md](../compliance-calendar.md)) |
| zmiana po wysłaniu JPK_EWP | korekta całego pliku z `CelZlozenia` = 2 ([jpk.md](jpk.md)) |

**Wiersz `correction` z KOR**

| Pole | Wartość |
| --- | --- |
| K_3 `revenue_date` | `issue_date` (P_1) KOR; przy błędzie rachunkowym albo oczywistej omyłce oznaczonej w zadaniu: K_3 wiersza korygowanego ([tax-rules.md](../tax-rules.md)) |
| K_4 `document_number` | `invoice_number` KOR (P_2) |
| K_5 `ksef_number` | `ksef_number` KOR |
| K_6, K_7 | z wiersza korygowanego; bez niego z `buyer_snapshot` KOR |
| K_8 `amount_pln` | różnica netto KOR w stawce, ze znakiem, × kurs PIT wiersza korygowanego ([tax-rules.md](../tax-rules.md)); w PLN bez kursu |
| K_9 `rate_pct` | stawka pozycji KOR |
| K_10 `remarks` | pusty |
| `source` | jak przy fakturze `VAT` z tego samego źródła |
| `invoice_id` | KOR |
| `corrects_entry_id` | wiersz `regular` faktury korygowanej w tej samej stawce |
| `year` | rok K_3 |

- Przyczynę wybierasz przy zamknięciu zadania; domyślna to „inna niż błąd” ([tax-rules.md](../tax-rules.md)).
- Faktura korygowana w bazie (`corrects_invoice_id`): wiersz `regular` z jej `invoice_id`.
- KOR z `NrKSeFN` (oryginał sprzed przejścia, bez wiersza `invoice`): wiersz `regular` z `document_number` = `corrected_invoice_number`, w tym samym `environment`, z dowolnego `source`, także `import`.
- Kilka trafień: wiersz wskazujesz w zadaniu.
- Brak wiersza korygowanego, np. faktura z 2025: `corrects_entry_id` pusty, `CHECK` spełnia `invoice_id` KOR. Kurs PIT wybierasz w zadaniu.
- Wiersz `import` bez kursu w bazie: kurs PIT korekty też wybierasz w zadaniu.

**Błąd wpisu**

| Błąd | Wiersze `correction` |
| --- | --- |
| tylko kwota K_8 | jeden wiersz z różnicą ze znakiem; pozostałe pola jak w błędnym wierszu |
| inne pole (K_3–K_7, K_9) | wiersz odwracający: pola błędnego wiersza i −K_8; drugi wiersz z poprawnymi polami i kwotą |

- Każdy wiersz ma `corrects_entry_id` = błędny wiersz, `invoice_id` błędnego wiersza i `source` = `manual`.
- `year` to rok K_3 każdego wiersza.
- W formularzu wskazujesz wiersz rokiem i K_1 i wypełniasz tylko pola do poprawy. Puste pole zostaje jak w błędnym wierszu.
- Wiersz z K_3 w zamkniętym miesiącu daje „korekta wymagana” jak każdy zapis ([compliance-calendar.md](../compliance-calendar.md)).

## Uzgodnienia

| Uzgodnienie | Strona A | Strona B | Oczekiwanie | Kiedy |
| --- | --- | --- | --- | --- |
| import | liczba i suma K_8 wierszy `import` roku | liczba wierszy i suma K_8 pliku importu | równe | każdy import, F2.1 |
| kontrola pliku | liczba wierszy i suma K_8 | `LiczbaWierszy`, `SumaPrzychodow` | równe | budowa pliku, F2.4 |
| FreshBooks | każda faktura FreshBooks 2026 przeliczona kursem PIT | wiersze `regular` po `ksef_number` albo `document_number`, z dowolnego `source` | dokładnie jeden zestaw wierszy na fakturę; K_8 i `revenue_date` zgodne, różnica w raporcie różnic | F2.2/F2.3, potem co miesiąc |
| KSeF Subject1 | każda Twoja faktura z Subject1; liczba i suma zgodne z Aplikacją Podatnika KSeF | wiersze `regular` po `ksef_number`, z dowolnego `source` | dokładnie jeden zestaw wierszy na fakturę | F1 (liczba i suma), F2.2/F2.3 |
| duplikaty między źródłami | wiersze `regular` jednego `source` | wiersze `regular` innego `source` | żaden `ksef_number` ani `document_number` w dwóch źródłach; trafienie to alert | F2.2/F2.3, potem co miesiąc |
| JPK_V7M | sprzedaż w ewidencji | sprzedaż w JPK_V7M | różnice tylko z kursów PIT i VAT, z wierszy `fx_difference`, z różnych miesięcy `revenue_date` i `tax_point_date` oraz z różnych okresów korekty w PIT (art. 14 ust. 1m) i VAT (TypKorekty) ([tax-rules.md](../tax-rules.md)); każda wyjaśniona per faktura | co miesiąc od F2.2/F2.3 |
| zaliczki | zaliczka wyliczona z ewidencji | zaliczka zapłacona | różnica to zadanie | co miesiąc od F2.2/F2.3 |

## Raport rok do roku

- Strona `/reports` panelu zestawia per miesiąc rok wybrany wobec poprzedniego: przychód (suma `revenue_entry.amount_pln` według miesiąca `revenue_date`), VAT należny, naliczony i P_51 z najnowszego przyjętego JPK_V7M okresu, koszty netto w zł z faktur kosztowych `business` i średni kurs NBP z tabel w bazie (D-87).
- Wiersze „cały rok” i, dla roku bieżącego, „styczeń do bieżącego miesiąca” obu lat. Brak danych to „–”, nie zero. Eksport `?format=csv`.
- Raport tylko czyta; nie zastępuje uzgodnień wyżej. Czat nie ma do niego narzędzia (`tool_set` bez zmian).

## Wykaz środków trwałych i WNiP

- Wykaz prowadzisz w programie i wysyłasz jako JPK_ST(1) razem z terminem JPK_EWP ([ustawa o ryczałcie, art. 15 ust. 12](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)).
- Zakres danych wykazu określa § 8 rozporządzenia. Wpis najpóźniej w miesiącu przyjęcia do używania (§ 8 ust. 3, [Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf)).
- Tabela `fixed_asset` jest w [data-model.md](../data-model.md). Mapowanie JPK_ST(1), flaga `jpk_st.enabled` i projekt zwolnienia są w [jpk.md](jpk.md).
- Domyślnie nie masz środków trwałych (Q-25).

## Kryteria odbioru

- Kryteria F2 są w [../phases/f2-ewidencja.md](../phases/f2-ewidencja.md) (sekcja „Kryteria odbioru”). F2 ma jedną listę dowodów `docs/evidence/f2.md`.
- Złote przypadki są w [../testing.md](../testing.md) (sekcja „Ewidencja i JPK_EWP(4)”).
