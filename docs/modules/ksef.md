# Moduł KSeF

KSeF to rdzeń kokpitu: tu powstaje prawnie wiążąca faktura sprzedaży i tu wpadają faktury kosztowe od polskich dostawców. Stan na 8 października 2026.

## Zakres i fazy

| Faza | Co z tego pliku | Token |
| --- | --- | --- |
| F1 | środowiska, przejście, uwierzytelnienie, cykl życia poświadczeń, synchronizacja Subject1 i Subject2, limity, biblioteka i `KsefGateway`, dane własne sprzedawcy, kolumna F1 w „Go-live” | `ksef_token_read`; test certyfikatu typ 1 |
| F2.2/F2.3 | Subject1 jako źródło ewidencji i uzgodnienia z FreshBooks: „Skutki zapisu” dla `subject1`, dzień przejścia `ksef.sales_transition_on`; raport serii P_2 z F1 jako wejście | `ksef_token_read` z F1 |
| F3 | przepływ wysyłki, reguła P_1, mapowanie FA(3), „Wysyłka: sesje, błędy i rekoncyliacja”, korekty KOR, Latarnia i awarie, KOD I i PDF, kolumna F3 w „Go-live” | `ksef_token_write` |

- Kolejność faz pochodzi z D-30: F1 i F2.2/F2.3 idą po dry runie JPK_EWP. F2.1 i F2.4 nie używają KSeF.
- Pola, klucze i przejścia stanów faktury są w [../data-model.md](../data-model.md). Stany zatwierdzeń i rekoncyliacja ogólna są w [../approval-gate.md](../approval-gate.md).
- Reguły kursów i stawek są w [../tax-rules.md](../tax-rules.md). Nazwy pochodzą z [../naming.md](../naming.md).

## Środowiska i hosty

Cel wybiera `KSEF_TARGET`; host spoza celu odrzuca `HostAllowlist` ([../architecture.md](../architecture.md)). Adresy i wersja API są w konfiguracji, nie w kodzie.

| `KSEF_TARGET` | API | KOD I | Latarnia |
| --- | --- | --- | --- |
| `test` (TE) | `api-test.ksef.mf.gov.pl` | `qr-test.ksef.mf.gov.pl` | `api-latarnia-test.ksef.mf.gov.pl` |
| `demo` | `api-demo.ksef.mf.gov.pl` | `qr-demo.ksef.mf.gov.pl` | brak: Latarnia nie ma DEMO, więc kolejka nie jest wstrzymywana ([../architecture.md](../architecture.md)) |
| `prod` | `api.ksef.mf.gov.pl` | `qr.ksef.mf.gov.pl` | `api-latarnia.ksef.mf.gov.pl` |

Źródła: [środowiska KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/srodowiska.md), [kody QR](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/kody-qr.md), [środowiska Latarni](https://raw.githubusercontent.com/CIRFMF/ksef-latarnia/main/srodowiska.md).

**Zasady środowisk testowych** ([środowiska KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/srodowiska.md))

- Na TE losowe NIP i certyfikaty self-signed. Kilku integratorów może więc działać jako ta sama firma, a dane TE są wspólne.
- TE i DEMO: bez faktur produkcyjnych i bez rzeczywistych danych podmiotów ([środowiska KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/srodowiska.md)). Próba generalna na DEMO idzie więc tylko na danych fikcyjnych.
- Sposób uwierzytelnienia na DEMO do sprawdzenia przed próbą generalną.
- Środowiska testowe mogą mieć przerwy techniczne w godzinach 16:00–18:00.
- Dane TE wytwarza sesja 1 F1 pomocnikiem w `tests/` przez `/testdata`: podmioty z fikcyjnym NIP, drugi sprzedawca testowy, certyfikat self-signed i tokeny. Bez bramki „stop” na starcie F1 (Q-39).
- Szczegóły danych testowych i nagrania odpowiedzi: [../testing.md](../testing.md).

## Stan obecny i przejście

Wystawianie w KSeF obowiązuje od 1.04.2026 (art. 145m, [Dz.U. 2025 poz. 1203](https://api.sejm.gov.pl/eli/acts/DU/2025/1203/text.pdf)), a odbiór faktur od 1.02.2026 ([MF, poniżej 10 tys. zł](https://ksef.podatki.gov.pl/ponizej-10-000-zl/)). Kokpit przejmie sprzedaż dopiero w F3, więc do tego czasu obowiązuje procedura z sekcji „Teraz – zgodność” w [../README.md](../README.md).

**Zwolnienie do 10 tys. zł** ([MF](https://ksef.podatki.gov.pl/ponizej-10-000-zl/))

- Do 31.12.2026 wolno wystawiać poza KSeF, dopóki miesięczna sprzedaż brutto na fakturach objętych KSeF nie przekroczy 10 tys. zł.
- Liczy się miesiąc daty wystawienia, nie data sprzedaży.
- Od faktury, która przekracza limit, wszystkie kolejne idą przez KSeF. Spadek poniżej limitu w kolejnym miesiącu nic nie zmienia: zwolnienie przepada trwale.
- Usługi B2B dla nabywców zagranicznych też są fakturami ustrukturyzowanymi; nabywca dostaje je w uzgodniony sposób (art. 106gb ust. 4, [ustawa o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Jedna faktura w EUR mogła więc przekroczyć limit (założenie Q-10).

**Stan do ustalenia (Q-10)**

| Pozycja | Gdzie zapisać |
| --- | --- |
| narzędzie, którym wystawiasz od 1.04.2026 (domyślnie Aplikacja Podatnika KSeF) | [../decisions.md](../decisions.md), Q-10 |
| seria numeracji (domyślnie numery z FreshBooks) | Q-10 |
| pierwsza faktura w KSeF, liczba i suma faktur od 1.04.2026 | wynik backfillu F1 |
| dzień przejścia, czyli dzień pierwszej Twojej faktury w KSeF | `setting` `ksef.sales_transition_on`; domyślnie P_1 najstarszej faktury z backfillu Subject1, potwierdzasz w Q-10; czyta go import FreshBooks w F2.2/F2.3 ([freshbooks.md](freshbooks.md)) |
| miesiące powyżej 10 tys. zł brutto przed przejściem na KSeF | Q-10; faktury spoza KSeF trafiają do ewidencji |

**Przejście krok po kroku**

| Krok | Faza | Co się dzieje | Kryterium |
| --- | --- | --- | --- |
| 1. Inwentaryzacja | F1 | backfill Subject1 i Subject2 od 1.02.2026, deduplikacja po `ksef_number` | liczba i suma Twoich faktur 2026 z importu = Aplikacja Podatnika KSeF |
| 2. Numeracja | F1 | raport serii P_2 z Subject1 porównany z kolejnym numerem FreshBooks (definicja serii niżej) | brak kolizji z kluczem duplikatu |
| 3. Okres równoległy | F3, przed `freshbooks.sales_enabled` | bez stanu `ready` i bez `approval`: komenda `app:ksef:parallel-compare <rok-miesiąc>` mapuje fakturę FreshBooks połączoną z fakturą Subject1 przez `freshbooks_id` na FA(3). P_1 i P_2 bierze z XML Subject1, tylko do porównania. Porównuje Podmiot1, Podmiot2, P_6 albo `OkresFa`, pozycje, P_12, P_13_x, P_14_x i P_14_xW, P_15, P_18, `KodWaluty` i adnotacje; hash i KOD I pomija. Prawdziwe dane nie trafiają na TE ani DEMO | raport rozbieżności w paczce dowodów F3; pełny miesiąc bez rozbieżności |
| 4. Przełączenie | go-live F3 | od pierwszego dnia miesiąca całą sprzedaż wystawia kokpit; okres zgodny z JPK_V7M; kolejność w „Go-live” niżej | jeden obowiązek, jedno źródło wysyłki |
| 5. Faktury spoza kokpitu po przełączeniu | od F3 | faktura wystawiona ręcznie (np. awaria kokpitu) wraca przez Subject1 | zadanie w panelu „faktura spoza kokpitu” |

**Kolizje P_2.** Klucz duplikatu KSeF to NIP sprzedawcy, `RodzajFaktury` i P_2, unikalny przez 10 pełnych lat od końca roku wystawienia. Duplikat daje błąd 440 ([weryfikacja faktury](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/weryfikacja-faktury.md)). Import Subject1 zapisuje Twoje faktury w `invoice`, więc ograniczenie unikalności z [../data-model.md](../data-model.md) łapie kolizję przed wysyłką. Numer, który już jest w KSeF, blokuje zatwierdzenie.

**Raport serii P_2** (krok 2)

- Seria to P_2 bez końcowego ciągu cyfr; numer to ten ciąg jako liczba, np. seria `FV/2026/` i numer 12 dla `FV/2026/0012`.
- Raport per `kind` i seria: liczba faktur, najwyższy numer i ostatnia faktura według `issue_date`.
- Kolejny numer FreshBooks porównujesz z najwyższym numerem jego serii.

**Sprzedaż sprzed przejścia.** Faktury wystawione poza KSeF (styczeń–marzec 2026 i miesiące w zwolnieniu) nie trafiają do `invoice`, tylko do `revenue_entry` ([ewidencja.md](ewidencja.md)). Korekta takiej faktury ma w FA(3) znacznik `NrKSeFN`.

## Uwierzytelnienie

Kokpit loguje się tokenem KSeF; biblioteka obsługuje cały cykl ([uwierzytelnianie KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/uwierzytelnianie.md)).

1. `POST /auth/challenge` zwraca challenge ważny 10 minut i znacznik czasu.
2. Kokpit szyfruje `{token}|{timestampMs}` kluczem publicznym MF (RSA-OAEP, SHA-256) i wysyła na `POST /auth/ksef-token` z identyfikatorem kontekstu (NIP).
3. `GET /auth/{referenceNumber}` odpytuje status. Zapasowa ścieżka certyfikatem typ 1 to podpis XAdES na `POST /auth/xades-signature`.
4. `POST /auth/token/redeem` zwraca accessToken i refreshToken; drugi redeem tym samym tokenem daje błąd 400.
5. accessToken żyje do swojego `exp`, refreshToken do 7 dni. `POST /auth/token/refresh` daje nowy accessToken; po wygaśnięciu refreshTokena pełne uwierzytelnienie od nowa.

**Dwa tokeny.** Generujesz w Aplikacji Podatnika KSeF dwa tokeny z uprawnieniami wpisanymi na stałe. Wyciek tokena do odczytu nie pozwala wystawić faktury.

| Token | Uprawnienie | Używa |
| --- | --- | --- |
| `ksef_token_read` | `InvoiceRead` | eksport i metadane faktur (sync Subject1 i Subject2); zapasowe dopasowanie w rekoncyliacji |
| `ksef_token_write` | `InvoiceWrite` | sesje wysyłki, lista sesji i faktur sesji, status faktury w sesji, lista błędnych, UPO, rekoncyliacja |

Lista sesji, faktury sesji, lista błędnych i UPO przyjmują `InvoiceWrite` ([KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json)). Przy zapisanej referencji sesji rekoncyliacja nie potrzebuje więc tokena do odczytu.

**Przechowywanie** ([../security.md](../security.md))

- Tokeny i klucze prywatne certyfikatów są statyczne: Symfony Secrets przez `SecretStore`. Daty ważności są w `credential_state`.
- Para JWT każdego tokena rotuje, więc leży zaszyfrowana w `credential_state` i odświeża się pod Symfony Lock. Dwa tokeny dają dwie pary JWT: rodzaje `ksef_jwt_read` i `ksef_jwt_write` ([../data-model.md](../data-model.md)).
- Jeden klient KSeF na kontekst NIP, poświadczenia zapisane per NIP. Wspólny cache PSR-16 trzyma tylko klucze publiczne MF.

## Cykl życia tokenów i certyfikatów

Tokeny są ścieżką podstawową. Certyfikat typ 1 to sprawdzony zapas z F1, nie bramka grudniowa.

**Stan prawny tokenów na 8.10.2026**

| Warstwa | Treść | Źródło |
| --- | --- | --- |
| rozporządzenie | §13 ust. 1 pkt 1: token do uwierzytelnienia tylko do 31.12.2026; zmiany nie opublikowano | [Dz.U. 2025 poz. 1815](https://api.sejm.gov.pl/eli/acts/DU/2025/1815), odpowiedź KIS z 23.07.2026 w [CIRFMF #834](https://github.com/CIRFMF/ksef-api/issues/834) |
| zapowiedź MF | tokeny zostają „bezterminowo”, nowelizacja w przygotowaniu (strona z 01.09.2026) | [MF, certyfikaty KSeF](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/) |
| konsultacje MF | ważność tokena od 1 do 365 dni i uprawnienie do automatycznego odnowienia | [MF, konsultacje](https://ksef.podatki.gov.pl/wyjasnienia/pierwsze-konsultacje-po-czesciowym-wdrozeniu-ksef-podsumowanie/) |
| plan techniczny | tokeny wydane do 31.12.2026 działają najpóźniej do 30.11.2027; nowe zasady wydawania od 1.01.2027; stary endpoint w trybie zgodności (10.09.2026) | [CIRFMF #834](https://github.com/CIRFMF/ksef-api/issues/834) |

Ponowne sprawdzenie §13 przed 1.12.2026 jest kamieniem milowym w [../README.md](../README.md); wynik trafia do [../facts.md](../facts.md).

**Poświadczenia**

| Rodzaj | Do czego | Ważność | Jak uzyskać |
| --- | --- | --- | --- |
| `ksef_token_read`, `ksef_token_write` | logowanie API | prawnie do 31.12.2026; technicznie najpóźniej 30.11.2027 dla tokenów wydanych w 2026 | Aplikacja Podatnika KSeF po zalogowaniu profilem zaufanym albo QES |
| `ksef_auth_cert` (typ 1) | logowanie API podpisem XAdES | najwyżej 2 lata od wytworzenia | wniosek po uwierzytelnieniu PZ, QES albo pieczęcią kwalifikowaną ([MF](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/)) |
| `ksef_offline_cert` (typ 2) | KOD II faktur offline | najwyżej 2 lata od wytworzenia | jak typ 1; potrzebny tylko przy trybie offline (Q-28) |

- Typ 1 i typ 2 generujesz osobno; certyfikat typu `Authentication` nie podpisze KOD II ([kody QR](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/kody-qr.md)).
- Sesja zalogowana tokenem nie złoży wniosku o certyfikat. MF wymienia tu tylko PZ, QES i pieczęć kwalifikowaną.
- `credential_state.valid_until` tokenów bierze się z `setting` `ksef.token_legal_valid_until`: dziś 31.12.2026. Po publikacji nowelizacji nowy wiersz z `valid_from` podaje datę z nowych przepisów. Panel pokazuje datę przy konektorze.
- Wygasanie tworzy obowiązek `credential_expiry` z przypomnieniami 30, 7 i 1 dzień wcześniej ([../security.md](../security.md)). Procedura odnowienia przez XAdES: [../operations.md](../operations.md).
- Kryterium F1: logowanie tokenem i certyfikatem typ 1 (`.p12`) na api-test. TE przyjmuje certyfikat self-signed; certyfikat i tokeny TE wytwarza sesja F1 (Q-39).

## Synchronizacja Subject1 i Subject2

Kokpit pobiera faktury przyrostowo, osobno dla każdej roli. Metoda zalecana przez MF to eksport po dacie trwałego zapisu ([przyrostowe pobieranie](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/pobieranie-faktur/przyrostowe-pobieranie-faktur.md)).

| `sync_cursor.stream` | Rola | Cel |
| --- | --- | --- |
| `subject2` | jesteś nabywcą | faktury kosztowe |
| `subject1` | jesteś sprzedawcą | Twoje faktury z dowolnego narzędzia: import, uzgodnienie z kokpitem, ewidencja |

Podmiot 3 i podmiot upoważniony są poza zakresem.

**Algorytm**

1. `POST /invoices/exports` z `dateType` = `PermanentStorage`, `restrictToPermanentStorageHwmDate` = `true`, `from` = `sync_cursor.last_hwm`. Przyrost idzie bez `to`, więc KSeF przyjmuje bieżącą chwilę w UTC ([KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json)). Backfill podaje `to` jawnie (niżej).
2. Odpytywanie `GET /invoices/exports/{referenceNumber}` do końca budowy paczki.
3. Pobranie części, odszyfrowanie AES-256, złączenie i rozpakowanie. Paczka zawiera XML faktur i `_metadata.json`.
4. Deduplikacja po `KsefNumber` z `_metadata.json`, bez rozróżniania wielkości liter; zostaje pierwsze wystąpienie.
5. Zapis faktur i nowego kursora w jednej transakcji. Gdy `IsTruncated` = `true`, kolejne okno startuje od `LastPermanentStorageDate`; w przeciwnym razie od `PermanentStorageHwmDate`.

Okna są przyległe, bez nakładania. Inne typy dat (`Issue`, `Invoicing`) gubią faktury, np. przy późnej wysyłce offline.

**Ograniczenia API** ([KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json), [przyrostowe pobieranie](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/pobieranie-faktur/przyrostowe-pobieranie-faktur.md))

| Ograniczenie | Wartość |
| --- | --- |
| zakres dat w filtrze | najwyżej 100 dni w UTC |
| paczka eksportu | do 10 000 faktur albo 1 GB po kompresji; potem `IsTruncated` |
| eksporty | najwyżej 20 na godzinę łącznie; co najmniej 15 minut przerwy na rolę; do 10 równoczesnych na kontekst |
| uprawnienie | `InvoiceRead` |

**Harmonogram i backfill**

- Przyrost: co godzinę dla każdej roli, role przesunięte o 30 minut. Daje to 2 eksporty na godzinę.
- Backfill: zadanie nocne `ksef.backfill` z katalogu w [../operations.md](../operations.md). Start: 1.02.2026 00:00 Europe/Warsaw, czyli `from` = 2026-01-31T23:00Z (`sync_cursor.backfill_from`).
- Okna backfillu mają `to` = min(`from` + 100 dni, teraz) i `restrictToPermanentStorageHwmDate` = `true`; do października co najmniej 3 okna. Kolejne okno startuje według kroku 5 algorytmu. Odstęp między eksportami tej roli to co najmniej 15 minut (tabela ograniczeń).
- Backfill i przyrost dzielą jeden wiersz `sync_cursor` na rolę. `ksef.sync` tej roli nic nie pobiera, dopóki backfill nie ustawi `backfill_done_at`.
- MF zapowiada wyższe limity pobierania w godzinach 20:00–06:00, wartości jeszcze nieustalone ([limity](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/limity/limity-api.md)).
- Zapasowo `POST /invoices/query/metadata` (`PermanentStorage`, kolejność `Asc`, `pageSize` 10–250). Przy `isTruncated` zawęża się zakres od daty ostatniego rekordu i zeruje `pageOffset`. Pojedyncze XML pobiera `GET /invoices/ksef/{ksefNumber}`.
- Błąd 21165 przy pobraniu oznacza, że faktura nie jest jeszcze dostępna; ponowienie później z backoffem ([changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md)).
- Odczyty idą transportem `reads` z retry i backoffem. 429 obsługuje sekcja „Limity i 429”.

**Skutki zapisu**

| Rola | Przypadek | Zapis |
| --- | --- | --- |
| obie | każda faktura | `ksef_number_assigned_at` = `acquisitionDate` z `_metadata.json`, czyli „Data nadania numeru KSeF” ([KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json)); `permanentStorageDate` służy tylko kursorowi |
| `subject2` | każda faktura | `invoice` z `direction` = `cost`, `source` = `ksef`, `cost_status` = `imported`; XML jako `stored_file` rodzaju `cost_document`; `ksef_number_assigned_at` to data otrzymania i najwcześniejszy okres odliczenia (art. 106na ust. 3, [../data-model.md](../data-model.md)) |
| `subject2` | wpis ręczny o tym samym sprzedawcy i numerze | połączenie z fakturą z KSeF; różnica wraca do `imported` |
| `subject1` | numer KSeF znany kokpitowi | tylko uzgodnienie |
| `subject1` | numer nieznany; lokalna sprzedaż o tym samym `kind` i P_2 z zatwierdzeniem w `dispatching`, `submitted` albo `unknown` | `SubmissionReconciler` porównuje `invoiceHash` z sha256 każdego wysłanego `xml_sent` tej faktury. Zgodny → `accepted` z tym numerem. Różny → kolizja P_2: lokalna faktura `rejected`, alert, wiersz z KSeF jak przy braku lokalnego wiersza |
| `subject1` | numer nieznany; lokalny szkic (`draft`, `ready`, `approved` bez wysyłki) o tym samym `kind` i P_2 | reguła kolejności z [freshbooks.md](freshbooks.md): szkic → `cancelled_before_send`, żywe zatwierdzenie → `cancelled` z `cancel_reason` = `manual_invoice` ([../naming.md](../naming.md)); faktura z KSeF przejmuje `freshbooks_id` |
| `subject1` | numer nieznany; brak lokalnego wiersza | `invoice` z `direction` = `sale` i stanem `accepted`, bez `approval`; od go-live F3 (`setting` `freshbooks.sales_enabled`) zadanie „faktura spoza kokpitu” w panelu |
| `subject1` | nowy wiersz z XML z KSeF: kolizja P_2, szkic albo brak lokalnego wiersza | XML jako `stored_file` rodzaju `xml_sent`: dokładne bajty przyjęte przez KSeF, źródło KOD I i danych KOR. `schema_version`, `invoice_line` i `buyer_snapshot` z XML. `tax_scenario` z Podmiot2: `KodUE` i `NrVatUE` → `eu_b2b`, ale `XI` → `non_eu_b2b`; `KodKraju` państwa UE i `NrID` → `eu_b2b_unidentified`; inny `KodKraju` i `NrID` → `non_eu_b2b`; `NIP` → `pl_b2b`; inny identyfikator → alert. P_12 niezgodne ze scenariuszem, np. `np II` przy `NrID`, → alert. Stawka ryczałtu pozycji przez `ryczalt.service_map`; brak → alert z [ewidencja.md](ewidencja.md) |
| `subject1` | faktura `accepted` | ewidencja, JPK_V7M i VAT-UE jak każda przyjęta sprzedaż ([ewidencja.md](ewidencja.md), [jpk.md](jpk.md)). Wiersz `revenue_entry` ze źródłem `ksef_subject1` powstaje od F2.2/F2.3; deduplikacja z wierszami importu F2.1 według [ewidencja.md](ewidencja.md) |

Faktury zagranicznych dostawców (SaaS, hosting) do KSeF nie trafiają; wpisujesz je ręcznie albo z maila. Przegląd kosztów, import usług i koszty spoza KSeF: [../tax-rules.md](../tax-rules.md).

KSeF nie daje nabywcy odrzucenia faktury; zła faktura wymaga KOR od wystawcy.

## Sprzedaż: przepływ wysyłki

Faktura ustrukturyzowana jest wystawiona w dniu P_1 i dopiero po nadaniu numeru KSeF może trafić do klienta. Numer z FreshBooks jest jedyną numeracją (P_2).

1. Szkic FreshBooks ze znacznikiem gotowości staje się DTO faktury niezależnym od źródła ([freshbooks.md](freshbooks.md)).
2. Mapper buduje FA(3) według tabeli scenariuszy i reguły P_1. XML przechodzi walidację XSD z `resources/xsd` ([../../.claude/rules/integrations.md](../../.claude/rules/integrations.md)) i kontrole przed wysyłką.
3. Powstaje `approval` z sha256 dokładnych bajtów ([../approval-gate.md](../approval-gate.md)). P_2 blokuje się przy zatwierdzeniu.
4. Po zatwierdzeniu worker `official_writes` sprawdza Latarnię i dzień P_1, liczy ponownie sha256 i otwiera sesję interaktywną.
5. Wysyłka zaszyfrowanej faktury: AES-256-CBC z kluczem zaszyfrowanym RSA-OAEP; robi to biblioteka. Numery referencyjne zapisują się po każdym kroku.
6. `GET /sessions/{referenceNumber}/invoices/{invoiceReferenceNumber}` aż do numeru KSeF albo błędu; potem zamknięcie sesji i UPO.
7. Przy stanie `accepted` powstaje PDF z KOD I i zapis zwrotny do FreshBooks. Klient dostaje PDF dopiero po akcji „Doręcz klientowi” w panelu, wysyłanej synchronicznie, poza Messenger (D-31, [freshbooks.md](freshbooks.md)).

**Kontrole przed wysyłką** ([weryfikacja faktury](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/weryfikacja-faktury.md))

| Kontrola | Skutek |
| --- | --- |
| UTF-8 bez BOM, bez instrukcji przetwarzania i znaków niezalecanych | inaczej KSeF odrzuca plik |
| rozmiar do 1 MB; z załącznikami do 3 MB | załączniki tylko w sesji wsadowej, więc kokpit ich nie wysyła |
| P_1 nie późniejsza niż dzień przyjęcia | reguła P_1 niżej |
| suma kontrolna NIP | KSeF sprawdza tylko na PROD; kokpit sprawdza zawsze |
| schemat zgodny z `formCode` sesji, np. FA (3) | jedna sesja na jeden schemat |
| identyfikator podatkowy nabywcy | brak blokuje zatwierdzenie (D-05, [rejestry-kursy.md](rejestry-kursy.md)) |
| stawka ryczałtu przy każdej pozycji | brak blokuje zatwierdzenie ([../tax-rules.md](../tax-rules.md)) |
| scenariusz FA(3) albo KOR zatwierdzony w przeglądzie doradcy (`ksef.fa3_review`) | brak blokuje zatwierdzenie przy `KSEF_TARGET=prod` (D-04) |
| `eu_b2b_unidentified` objęty `ksef.fa3_review` | brak blokuje zatwierdzenie przy każdym `KSEF_TARGET` (D-05, Q-29) |

## Reguła P_1

Ten plik jest domem reguły P_1 (D-16). [../approval-gate.md](../approval-gate.md) wiąże ją z hashem payloadu.

**Fakty**

- KSeF traktuje P_1 jako jedyną datę wystawienia ([tryby offline](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/tryby-offline.md)).
- P_1 późniejsza niż dzień przyjęcia przez KSeF oznacza odrzucenie ([weryfikacja faktury](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/weryfikacja-faktury.md)).
- Faktura przyjęta po dniu P_1 może dostać tryb offline: KSeF porównuje P_1 z datą przyjęcia ([tryby offline](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/tryby-offline.md)).
- Faktura offline24 musi trafić do KSeF najpóźniej następnego dnia roboczego po dniu wystawienia ([MF, offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/)).
- Szkic z piątkową datą z FreshBooks, wysłany we wtorek, byłby więc spóźnioną fakturą offline24. Po wejściu sankcji to kara (kamienie milowe w [../README.md](../README.md)).

**Reguła**

| Element | Reguła |
| --- | --- |
| P_1 | dzień kalendarzowy w Europe/Warsaw, w którym worker wysyła fakturę |
| ustawienie | przy budowie XML do zatwierdzenia; P_1 = dzisiaj |
| źródło | nigdy z FreshBooks ani z daty utworzenia szkicu |
| P_1 w przyszłości | blokuje zatwierdzenie |
| ważność zatwierdzenia | najpóźniej do końca dnia P_1; dłuższe `expires_at` skraca się do tej granicy |
| zmiana dnia przed wysyłką | worker odmawia; zatwierdzenie `expired`; nowa prośba z nową P_1 i nowym sha256 według reguły odnowienia w [../approval-gate.md](../approval-gate.md) |
| wysyłka tuż przed północą | przyjęcie po północy może dać tryb offline24 z poprzednią P_1; faktura jest poprawna, bo dotarła w terminie; panel pokazuje tryb ze statusu |
| termin wystawienia | najpóźniej 15. dnia miesiąca po wykonaniu usługi (art. 106i ust. 1, [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)); obowiązek `invoice_issue` w [../compliance-calendar.md](../compliance-calendar.md) |

**Data usługi.** P_6 wpisujesz, gdy data wykonania jest wspólna dla pozycji i różni się od P_1. Przy różnych datach pozycje mają P_6A, a usługa okresowa ma `OkresFa` ([FA(3)](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/schemy/FA/schemat_FA%283%29_v1-0E.xsd)). Kokpit przechowuje `issue_date`, `service_date`, `service_period_*`, `sent_at` i `ksef_number_assigned_at`.

**Testy na api-test**

- Szkic FreshBooks z datą z przeszłości → XML z dzisiejszą P_1.
- Zatwierdzenie z wczoraj (`MockClock`) → worker nic nie wysyła, zatwierdzenie `expired`, faktura wraca do `ready`. Nowa prośba z dzisiejszą P_1 powstaje według [../approval-gate.md](../approval-gate.md).
- P_1 w przyszłości → zatwierdzenie zablokowane.

## Mapowanie FA(3): scenariusze B2B

Większość wartości ustalają XSD FA(3) 1-0E i broszura MF ([schemat](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/schemy/FA/schemat_FA%283%29_v1-0E.xsd), [broszura FA(3)](https://ksef.podatki.gov.pl/media/nuclnu0w/broszura-informacyjna-dotyczaca-struktury-logicznej-fa-3.pdf)). Otwarte punkty rozstrzyga jednorazowy przegląd doradcy przed go-live F3 (D-04).

**Pola wspólne**

| Pole | Wartość |
| --- | --- |
| `KodWaluty` | zawsze, także dla PLN |
| `JST`, `GV` | 2 (faktura nie dotyczy jednostki JST ani członka grupy VAT) |
| `P_16`, `P_17`, `P_18A` | 2 (bez metody kasowej VAT, bez samofakturowania, bez podzielonej płatności); zmianę wskazuje przegląd doradcy |
| `P_14_xW` i kurs w pozycjach | tylko gdy faktura w walucie obcej ma polski VAT; kurs według [../tax-rules.md](../tax-rules.md) |
| `KursWalutyZ` | tylko faktura zaliczkowa; zaliczki poza zakresem |
| Podmiot1 | z `setting` `company.identity` (sekcja „Dane własne sprzedawcy”) |
| Podmiot2 | ze `buyer_snapshot` z dnia wystawienia |
| `BrakID` | nigdy: tylko B2B, identyfikator nabywcy obowiązkowy (D-05) |

**Scenariusze** (`invoice.tax_scenario`)

| Element | `eu_b2b` (ważny VIES) | `eu_b2b_unidentified` (do potwierdzenia, Q-29) | `non_eu_b2b` | `pl_b2b` |
| --- | --- | --- | --- | --- |
| P_12 | `np II` | `np I` | `np I` | stawka krajowa, zwykle `23` |
| suma | P_13_9 | P_13_8 | P_13_8 | P_13_1 i P_14_1 |
| P_18 „odwrotne obciążenie” | 1, bo VAT rozlicza nabywca | rozstrzyga przegląd doradcy | rozstrzyga przegląd doradcy: zależy od tego, czy nabywca płaci VAT albo podatek o podobnym charakterze | 2 |
| Podmiot2 | `KodUE` i `NrVatUE` bez prefiksu kraju | `KodKraju` i `NrID` (krajowy identyfikator podatkowy) | `KodKraju` i `NrID` | `NIP` |
| adnotacja w PDF (PL i EN) | odwrotne obciążenie; usługa z art. 100 ust. 1 pkt 4 | usługa poza terytorium kraju; adnotacja według P_18 | usługa poza terytorium kraju; adnotacja według P_18 | brak |
| VAT-UE | tak, w miesiącu obowiązku podatkowego | nie: VAT-UE obejmuje tylko nabywców zidentyfikowanych (art. 100 ust. 1 pkt 4) | nie | nie |
| JPK_V7M | K_11 i K_12 | K_11 | K_11 | K_19 i K_20 |
| weryfikacja przy zatwierdzeniu | VIES `valid` albo `manual_evidence` z formularza VIES z numerem konsultacji ([rejestry-kursy.md](rejestry-kursy.md)) | VIES `invalid` i `manual_evidence` statusu przedsiębiorcy ([rejestry-kursy.md](rejestry-kursy.md)) | dowód identyfikatora (`manual_evidence`) | biała lista `search` albo `manual_evidence` ([rejestry-kursy.md](rejestry-kursy.md)) |
| zatwierdzone przez doradcę | [data] | [data] | [data] | [data] |

- `eu_b2b_unidentified` to nabywca z UE bez ważnego numeru w VIES, ale prowadzący działalność (art. 28a pkt 1 lit. a [ustawy o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Reguła i źródła: [../tax-rules.md](../tax-rules.md), D-05.
- Ten scenariusz czeka na potwierdzenie doradcy (Q-29). Do tego czasu `ApprovalGate` blokuje jego zatwierdzenie na każdym celu. Złoty plik XML powstaje po przeglądzie.
- Nieważny VIES (`invalid`) bez `manual_evidence` statusu przedsiębiorcy blokuje zatwierdzenie. Brak odpowiedzi (`unverified_retry`) blokuje do czasu `manual_evidence` według [rejestry-kursy.md](rejestry-kursy.md).
- Klient z GB i z Irlandii Północnej (`XI`) przy usługach to `non_eu_b2b`; do potwierdzenia w Q-29 ([rejestry-kursy.md](rejestry-kursy.md)).
- To reguły, nie osobne scenariusze: B2C i OSS są poza zakresem (D-05).
- K_12 zawiera się w K_11. Pełne mapowanie JPK jest w [jpk.md](jpk.md), sumy VAT-UE też.
- Każdy scenariusz ma złoty plik XML na api-test. Złote pliki są fixtures od początku F3, poza `eu_b2b_unidentified`; przegląd doradcy je zatwierdza ([../testing.md](../testing.md)).
- Pole „zatwierdzone przez doradcę” wypełniasz datą z [../decisions.md](../decisions.md) (Q-29).
- Wynik przeglądu zapisujesz też w `setting` `ksef.fa3_review` z `valid_from`: data, lista zatwierdzonych `tax_scenario` i KOR, sha256 złotych plików.
- Przy `KSEF_TARGET=prod` `ApprovalGate` blokuje fakturę, której `tax_scenario` nie ma na tej liście, oraz KOR, gdy lista jej nie obejmuje.

## Wysyłka: sesje, błędy i rekoncyliacja

Stany zatwierdzenia i ogólny przebieg rekoncyliacji są w [../approval-gate.md](../approval-gate.md). Tu są reguły specyficzne dla KSeF.

| Sytuacja | Reguła | Źródło |
| --- | --- | --- |
| sesja interaktywna | żyje do 12 h od otwarcia (`validUntil`); kokpit otwiera sesję na jedną wysyłkę i zamyka ją po statusie | [sesja interaktywna](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/sesja-interaktywna.md) |
| weryfikacja | asynchroniczna; status faktury w sesji zwraca numer KSeF, `invoiceHash` i tryb (`online`, `offline`) | [KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json) |
| błąd 21184 „Sesja tymczasowo niedostępna” | zamknięcie sesji i nowa sesja; ten sam payload | [changelog API 2.8.0](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) |
| status faktury 440 (duplikat) | `extensions` niesie `originalKsefNumber` i `originalSessionReferenceNumber` | [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) |
| timeout albo brak odpowiedzi | stan `unknown`; ponowienie tylko ręcznie | [../approval-gate.md](../approval-gate.md) |

**Rekoncyliacja w KSeF** (`SubmissionReconciler`, token `ksef_token_write`, zapasowo `ksef_token_read`)

Rekoncyliacja używa tylko zapytań odczytu i nigdy nie wysyła ponownie ([../approval-gate.md](../approval-gate.md)).

1. Referencja faktury → status faktury w sesji i UPO.
2. Tylko referencja sesji → lista faktur sesji i lista błędnych (`GET /sessions/{referenceNumber}/invoices/failed`).
3. Brak referencji → `GET /sessions` (`InvoiceWrite`) z dnia wysyłki i `GET /sessions/{referenceNumber}/invoices` ([KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json)).
4. Zapasowo dopasowanie z syncu Subject1 po `kind` i P_2, tokenem `ksef_token_read` (`InvoiceRead`, [KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json)). Reguła jak w „Skutkach zapisu” wyżej.
5. Jest numer KSeF i `invoiceHash` zgodny z wysłanym `xml_sent` → `accepted`. Faktura na liście błędnych → `rejected`.
6. Brak wyniku → stan zostaje `unknown`; panel proponuje ręczne ponowienie.

**Ręczne ponowienie po `unknown`** (nowe zatwierdzenie, [../approval-gate.md](../approval-gate.md))

1. Ponowienie w innym dniu daje nowy XML z nową P_1, więc i nowy hash (reguła P_1 wyżej).
2. Faktura już przyjęta w KSeF daje status 440. Kokpit pobiera status faktury z sesji oryginału (`originalSessionReferenceNumber`).
3. Porównuje `invoiceHash` (SHA-256 w Base64, po zdekodowaniu) z sha256 każdego wcześniej wysłanego payloadu tej faktury. Payloady to `stored_file` `xml_sent`, także z zatwierdzeń `superseded` i `expired`.
4. Hash zgodny z którymkolwiek oznacza wcześniejszy sukces: `accepted` z numerem oryginału, bez drugiej faktury.
5. Hash różny od wszystkich oznacza kolizję P_2 z inną fakturą: `rejected` i alert.

Faktura odrzucona przez KSeF nie została wystawiona. Poprawiasz ją i wysyłasz ponownie przez nowe zatwierdzenie, bez KOR.

## Korekty KOR

Faktury przyjętej przez KSeF nie da się edytować ani anulować. Jedyną drogą jest faktura korygująca od sprzedawcy; noty korygujące nabywcy już nie działają ([podręcznik KSeF 2.0, cz. II](https://ksef.podatki.gov.pl/media/rronoxyt/podrecznik-ksef-20-cz-ii-wystawianie-i-otrzymywanie-faktur-w-ksef-06082026.pdf)).

**Pola FA(3)** ([schemat](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/schemy/FA/schemat_FA%283%29_v1-0E.xsd))

| Pole | Reguła |
| --- | --- |
| `RodzajFaktury` | `KOR`; `KOR_ZAL` i `KOR_ROZ` poza zakresem |
| `DaneFaKorygowanej` | `DataWystFaKorygowanej`, `NrFaKorygowanej` oraz `NrKSeF` z `NrKSeFFaKorygowanej` albo `NrKSeFN` dla oryginału spoza KSeF |
| `PrzyczynaKorekty` | opcjonalne; powód ze szkicu KOR |
| `TypKorekty` | opcjonalne; 1 = skutek w dacie ujęcia faktury pierwotnej, 2 = w dacie wystawienia korekty, 3 = w innej dacie |
| kurs waluty | kurs faktury pierwotnej (art. 31b, [../tax-rules.md](../tax-rules.md)) |

Przyczynę i `TypKorekty` (albo ich brak) ustawiasz w szkicu KOR przed prośbą o zatwierdzenie. Zmiana po prośbie to nowy XML, nowy sha256 i nowe zatwierdzenie ([../approval-gate.md](../approval-gate.md)). Oba pola są opcjonalne; pusty wybór pomija element w XML. Domyślny `TypKorekty` ustala przegląd doradcy (Q-29).

**Przepływ**

1. Credit note we FreshBooks, edycja po przyjęciu albo akcja w panelu tworzy szkic KOR: dane oryginału i różnica ([freshbooks.md](freshbooks.md), Q-27).
2. KOR to osobny wiersz `invoice` z `kind` = `KOR`, zatwierdzany jako `correction_invoice`.
3. P_2 korekty to numer credit note z FreshBooks, więc FreshBooks zostaje jedyną numeracją. Szkic KOR z edycji po przyjęciu albo z panelu przechodzi do `ready` dopiero z numerem credit note. Klucz duplikatu obejmuje `RodzajFaktury`, więc seria KOR nie koliduje z seriami VAT (Q-27).
4. KOR wysyłasz dopiero, gdy oryginał ma numer KSeF. Oryginał offline24 bez numeru najpierw trafia do KSeF ([MF, offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/)).
5. Przyjęta KOR tworzy zadania w panelu: korekta JPK_V7M przy zamkniętym okresie, VAT-UEK przy nabywcy z UE, wiersz `correction` w ewidencji.

| Przypadek | Ścieżka | Stan oryginału |
| --- | --- | --- |
| zmiana kwoty, pozycji albo opisu | KOR z różnicą | `corrected` |
| błędny NIP albo inny nabywca | KOR do zera i nowa faktura z `replaces_invoice_id` | `superseded` |
| oryginał sprzed przejścia, spoza KSeF | KOR z `NrKSeFN`; `corrects_invoice_id` pusty | – |
| faktura odrzucona przez KSeF | poprawka i nowe zatwierdzenie, bez KOR | `rejected` → `ready` |
| szkic jeszcze niewysłany | anulowanie w panelu albo usunięcie we FreshBooks | `cancelled_before_send` |

Jedna KOR koryguje jedną fakturę. Okres ujęcia korekty w JPK i ewidencji: [jpk.md](jpk.md), [ewidencja.md](ewidencja.md), [../compliance-calendar.md](../compliance-calendar.md). Każdy przypadek ma fixture.

## Awarie KSeF i Latarnia

Latarnia jest osobnym konektorem `latarnia`, niezależnie od wyboru polityki awarii (D-15).

**Latarnia** ([scenariusze](https://raw.githubusercontent.com/CIRFMF/ksef-latarnia/main/scenariusze.md), [README Latarni](https://raw.githubusercontent.com/CIRFMF/ksef-latarnia/main/README.md))

- API publiczne, bez uwierzytelnienia. Endpointy `/status` i `/messages`; komunikaty widać jeszcze 30 dni po zdarzeniu.
- Statusy: `AVAILABLE`, `MAINTENANCE`, `FAILURE`, `TOTAL_FAILURE`.
- Kokpit pyta `/status` co 5 minut i przed każdą wysyłką; zadanie jest w katalogu w [../operations.md](../operations.md).
- Status inny niż `AVAILABLE` wstrzymuje wysyłki KSeF. Panel pokazuje status i komunikat.

**Polityka domyślna (D-15, Q-28): czekasz.** Kolejka stoi, zatwierdzenia wygasają z końcem dnia P_1, a po awarii zatwierdzasz faktury z nową P_1. Bez trybu offline i bez certyfikatu typ 2. Obowiązek `invoice_issue` pilnuje terminu z art. 106i.

**Tryby offline, gdyby Q-28 zmieniło decyzję** ([tryby offline](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/tryby-offline.md))

| Tryb | `invoice.ksef_issue_mode` | Wyzwalacz | Termin wysyłki do KSeF |
| --- | --- | --- | --- |
| offline24 | `offline24` | decyzja wystawcy | następny dzień roboczy po dniu wystawienia |
| offline (`MAINTENANCE`) | `maintenance` | zapowiedziana niedostępność | następny dzień roboczy po jej końcu |
| awaryjny (`FAILURE`) | `failure` | ogłoszona awaria | 7 dni roboczych od końca awarii; nowa awaria liczy od nowa |
| awaria całkowita (`TOTAL_FAILURE`) | `total_failure` | komunikat w mediach | brak obowiązku; faktura bez FA(3) i bez QR |

Pozostałe wartości `ksef_issue_mode` (`online`, `outside_ksef`) są w [../naming.md](../naming.md); znaczniki JPK z trybu: [jpk.md](jpk.md).

- Awaria ogłoszona w oknie offline24 albo offline przesuwa termin: liczy się od końca ostatniej awarii, najwyżej 7 dni roboczych.
- Faktura offline idzie z `offlineMode` = `true`.
- KOD II jest potrzebny tylko, gdy nabywca dostaje fakturę przed numerem KSeF ([MF, offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/)).
- Włączenie trybu offline oznacza certyfikat typ 2, stan `offline_issued` i terminy w `obligation` liczone przez `PolishCalendar`.

## KOD I, KOD II i PDF

**KOD I** ([kody QR](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/kody-qr.md))

- Adres: `https://{host KOD I}/invoice/{NIP sprzedawcy}/{P_1 jako DD-MM-RRRR}/{SHA-256 pliku w Base64URL}`. Host z tabeli środowisk, z konfiguracji celu.
- Hash liczy się z dokładnie wysłanych bajtów (`stored_file` rodzaju `xml_sent`). Ponowna serializacja XML psuje kod.
- Pod kodem stoi numer KSeF; przed nadaniem numeru napis OFFLINE. Kod spełnia ISO/IEC 18004:2024.
- Faktura przekazana nabywcy poza KSeF musi mieć kod (art. 106gb ust. 5, [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)).

**KOD II** dotyczy tylko trybu offline: podpis kluczem certyfikatu typu Offline, etykieta CERTYFIKAT ([kody QR](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/kody-qr.md)).

**PDF (D-17, Q-26)**

| Wybór | Stan |
| --- | --- |
| własny dwujęzyczny szablon Twig→PDF (PL i EN) z KOD I przez `endroid/qr-code` ^5.1, wymóg linii 1.10.x; ≥6.1 dopiero po migracji na 2.x (D-14, [../architecture.md](../architecture.md)) | domyślnie |
| silnik HTML→PDF | wybiera sesja F3 spośród licencji bez GPL, np. dompdf (LGPL-2.1) |
| kontener Node z generatorem przypiętym do commita | tylko zapas |
| mPDF | odpada: GPL-2.0-only |

- Biblioteka KSeF renderuje PDF przez fork generatora w Node wołany przez `proc_open`. Oficjalny generator MF (TypeScript, Node 22) nie ma CLI, a jego plik angielski ma same placeholdery ([ksef-pdf-generator](https://github.com/CIRFMF/ksef-pdf-generator), [README biblioteki](https://raw.githubusercontent.com/N1ebieski/ksef-php-client/main/README.md)).
- Szablon czyta dane z `xml_sent`, nie z FreshBooks, więc PDF zgadza się z XML.
- PDF powstaje tylko w stanie `accepted`. Kryterium F3: QR otwiera działający adres qr-test.

## Limity i 429

MF liczy limity na parę kontekst (NIP) i adres IP, w oknach przesuwnych sekundy, minuty i godziny ([limity API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/limity/limity-api.md)). Kokpit tych okien nie liczy (D-29). Obsługuje tylko 429: `Retry-After` i `rate_counter.blocked_until` ([../architecture.md](../architecture.md)).

| Grupa (`rate_counter.bucket`) | Endpoint | Limit MF na s | na min | na h |
| --- | --- | --- | --- | --- |
| `ksef.metadata` | `POST /invoices/query/metadata` | 8 | 16 | 20 |
| `ksef.export` | `POST /invoices/exports` | 8 | 16 | 20 |
| `ksef.export_status` | `GET /invoices/exports/{referenceNumber}` | 10 | 60 | 600 |
| `ksef.download` | `GET /invoices/ksef/{ksefNumber}` | 8 | 16 | 64 |
| `ksef.session_open` | `POST /sessions/online` | 10 | 30 | 120 |
| `ksef.invoice_send` | `POST /sessions/online/{referenceNumber}/invoices` | 10 | 30 | 180 |
| `ksef.session_close` | `POST /sessions/online/{referenceNumber}/close` | 20 | 60 | 240 |
| `ksef.invoice_status` | `GET /sessions/{referenceNumber}/invoices/{invoiceReferenceNumber}` | 30 | 120 | 1200 |
| `ksef.sessions_list` | `GET /sessions` | 5 | 10 | 60 |
| `ksef.session_invoices` | `GET /sessions/{referenceNumber}/invoices` | 10 | 20 | 200 |
| `ksef.session_failed` | `GET /sessions/{referenceNumber}/invoices/failed`; wartości jak `sessionInvoiceList` według `x-rate-limits` | 10 | 20 | 200 |
| `ksef.session_other` | pozostałe `GET /sessions/*` | 10 | 120 | 1200 |
| `ksef.default` | pozostałe chronione zasoby | 10 | 30 | 120 |
| `ksef.challenge` | `POST /auth/challenge` (na IP) | 60 | – | – |
| `ksef.global` | wszystkie wywołania, na IP; mechanizm zapowiedziany w API 2.8.0, na razie wyłączony | – | – | – |

Źródła: [limity API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/limity/limity-api.md), `x-rate-limits` w [KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json), [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md). Limity sesji wsadowych i identyfikatora zbiorczego kokpitu nie dotyczą.

- Wartości w tabeli to odniesienie, nie konfiguracja. Grupa służy tylko do zapisu `blocked_until` po 429.
- Od API 2.5.0 (TEST od 06.05.2026) domyślne limity TE są równe PROD ([changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md); uwaga o nieaktualnej linii w [../facts.md](../facts.md)). DEMO ma limity PROD ([limity API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/limity/limity-api.md)).
- Limity domyślne liczą się osobno dla każdego endpointu ([limity API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/limity/limity-api.md)). `ksef.default` zbiera pozostałe chronione zasoby.
- Harmonogram trzyma wywołania daleko poniżej limitów: przyrost to 2 eksporty na godzinę wobec 20, wysyłka to jedna faktura na sesję.
- 429 niesie `Retry-After` w sekundach. Kokpit zapisuje `blocked_until` w wierszu grupy i odkłada zadanie. Push według reguły „Push” w „Licznikach limitów” ([../architecture.md](../architecture.md)).
- Blokady rosną przy powtórnych przekroczeniach. Równoległe użycie kilku IP w jednym kontekście MF traktuje jak obchodzenie limitów ([limity API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/limity/limity-api.md)).
- Wszystkie wywołania KSeF wychodzą z jednego IP serwera. `rate_counter` w PostgreSQL jest wspólny dla `worker_reads` i `worker_official_writes` ([../architecture.md](../architecture.md)).
- Okna przesuwne wracają dopiero po pierwszych odpowiedziach 429 na PROD; wtedy bieżące limity daje `GET /rate-limits` (D-29, [../roadmap.md](../roadmap.md)).

## Biblioteka i `KsefGateway`

Klient PHP `n1ebieski/ksef-php-client` ma dwie linie, obie dla KSeF API 2.0 ([Packagist](https://packagist.org/packages/n1ebieski/ksef-php-client), [UPGRADE.md](https://github.com/N1ebieski/ksef-php-client/blob/main/UPGRADE.md)).

| Linia | Stan | Uwagi |
| --- | --- | --- |
| 1.10.x | stabilna, od PHP 8.1, tylko poprawki i krytyczne zmiany KSeF | token, `.p12`, certyfikaty KSeF, FA(3), QR, offline, Latarnia; ostatnie podbicie API 2.7.1 w v1.9.0 |
| 2.0.0-rc.1 (20.09.2026) | release candidate, PHP ^8.4 | phpseclib4, interfejs generatora QR, `endroid` opcjonalny (≥6.1), usunięte fabryki |

API KSeF jest w wersji 2.8.1 (PROD od 23.09.2026); 2.8.0 dodało błąd 21184 i osobne limity zamknięcia sesji ([changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md)).

**Decyzja domyślna (D-14, Q-19):** 1.10.x przypięta do dokładnej wersji, migracja po wydaniu stabilnej 2.0.

- Kod domeny zna tylko interfejs `KsefGateway`: uwierzytelnienie, sesja, wysyłka, status, UPO, eksport, pobranie, 429 z `Retry-After`.
- Zmianę API nowszą niż biblioteka obsługuje `KsefGateway`. Wzorcem są oficjalne klienty MF w C# i Javie ([CIRFMF](https://github.com/CIRFMF/ksef-api)).
- Kod QR i certyfikatów piszesz pod wybraną linię.
- Biblioteka dostaje wstrzyknięty klient PSR-18, więc testy odtwarzają nagrane odpowiedzi ([../testing.md](../testing.md)).
- Kody błędów KSeF (21165, 21184, 440 i inne) są wyliczeniem w konektorze, nie literałami.

## Dane własne sprzedawcy

`setting` `company.identity` przechowuje dane Podmiot1: NIP, numer VAT UE (PL i NIP), nazwę, adres i kraj. Ma też rachunki firmowe do samosprawdzenia w białej liście. Nie są stałymi w kodzie, więc kolejna firma nie zmienia kodu (D-25). Ten sam rekord daje numer pytającego w VIES i dane do samosprawdzenia w [rejestry-kursy.md](rejestry-kursy.md).

- Pola JPK w tym samym rekordzie (`KodUrzedu`, imię, nazwisko, e-mail, telefon) definiuje [ewidencja.md](ewidencja.md). `DataUrodzenia` pochodzi z `auth_data` w `SecretStore`, nie z `setting` ([../data-model.md](../data-model.md)).
- Na `kokpit-dev` wszystkie wartości są fikcyjne; na PROD wpisujesz je przed go-live fazy, która ich używa.

## Go-live

Ogólna lista go-live jest w [../operations.md](../operations.md). Dla KSeF kroki idą w kolejności tabeli. F3 nie ma zapisu PROD przed przełączeniem. Kolejność F3: okres równoległy bez wysyłki przez pełny miesiąc → przegląd doradcy (D-04) → przełączenie 1. dnia miesiąca → stare narzędzie wyłączone tego dnia.

| Krok | F1 (odczyt) | F3 (sprzedaż) |
| --- | --- | --- |
| 1. api-test | kryteria F1, w tym certyfikat typ 1 | kryteria F3 i złote pliki XML |
| 2. DEMO | opcjonalnie | próba generalna w `kokpit-dev` na laptopie, na danych fikcyjnych, jeśli DEMO to umożliwia; inaczej pominięta |
| 3. poświadczenia PROD | `ksef_token_read` | `ksef_token_write` |
| 4. okres równoległy | backfill zgodny z Aplikacją Podatnika | pełny miesiąc bez wysyłki: raport z kroku 3 przejścia |
| 5. przegląd doradcy | – | zapisany w [../decisions.md](../decisions.md) i `ksef.fa3_review` (D-04) |
| 6. przełączenie | – | 1. dnia miesiąca `freshbooks.sales_enabled` włączony; pierwsza faktura miesiąca przez bramkę to pierwszy zapis PROD i pierwszy zapis zwrotny do FreshBooks (D-35); numer i UPO sprawdzone w Aplikacji Podatnika |
| 7. wyłączenie starego narzędzia | – | tego samego dnia |
