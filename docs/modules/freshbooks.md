# Moduł FreshBooks

FreshBooks zostaje do obsługi klientów i płatności, a fakturę doręcza klientowi tylko kokpit, po nadaniu numeru KSeF. Stan na 8 października 2026.

## Rola i decyzje

| Faza | Co z tego pliku |
| --- | --- |
| F2.2/F2.3 | połączenie OAuth tylko do odczytu, import faktur i płatności 2026 jako źródło uzgodnienia ewidencji |
| F3 | znacznik gotowości, blokada P_2, blokady obejścia, zapis zwrotny (pierwszy raz na PROD), doręczenie klientowi, onboarding klienta |

- FreshBooks jest źródłem szkiców faktur sprzedaży (D-07, proponowana; Q-26, Q-27). Prawnie wiążąca jest faktura z numerem KSeF.
- Kolejność z D-30: F2.1 importuje ewidencję 2026 bez FreshBooks. FreshBooks wchodzi w F2.2/F2.3, po F0b i F1.
- Firmy testowej nie ma (D-35). F2.2/F2.3 i F3 działają na ręcznie pisanych fixtures i na prawdziwej firmie tylko do odczytu.
- „Doręcz klientowi” działa synchronicznie w żądaniu panelu, poza Messenger (D-31).
- Mapowanie FA(3), reguła P_1 i korekty są w [ksef.md](ksef.md). Kolumny `invoice` i `payment` są w [../data-model.md](../data-model.md).
- Kokpit odpytuje FreshBooks, bez webhooków, więc nic nie musi być wystawione do internetu.

## Połączenie OAuth

FreshBooks używa OAuth 2.0 z jednorazowym refresh tokenem ([FreshBooks, uwierzytelnianie](https://www.freshbooks.com/api/authentication)).

| Fakt | Skutek w kokpicie |
| --- | --- |
| refresh token żyje bez końca, ale działa jeden raz | nowy refresh token zapisany w `credential_state` (`freshbooks_oauth`) w tej samej transakcji, zanim kokpit użyje nowego bearera |
| na aplikację i użytkownika żyje jeden refresh token; każdy nowy unieważnia starsze | jedna aplikacja OAuth i jeden grant, tylko we wdrożeniu PROD; `kokpit-dev` i testy nie łączą się z FreshBooks, bo drugi grant unieważniłby grant PROD |
| bearer token żyje krótko; ważność jest w tokenie | odświeżanie pod Symfony Lock na parę `(environment, kind)` |
| redirect URI tylko HTTPS, bez parametrów zapytania | przekierowanie na nazwę panelu w VPN z TLS (D-18); dane przez `state` |
| utracony refresh token wymaga ręcznej autoryzacji | push i zadanie „połącz ponownie FreshBooks” w panelu |

- Client ID i secret aplikacji są statyczne: Symfony Secrets przez `SecretStore` ([../security.md](../security.md)).
- Dokumentacja API nie opisuje sandboxu ani konta testowego ([FreshBooks API, 8.10.2026](https://www.freshbooks.com/api/start)). Druga firma kosztuje (informacja właściciela), więc firmy testowej nie ma (D-35).
- `kokpit-dev` na laptopie i testy działają na ręcznie pisanych fixtures ([../testing.md](../testing.md)).
- Prawdziwą firmę łączy tylko wdrożenie PROD. Do pierwszego zapisu zwrotnego w F3 kokpit tylko z niej czyta (D-35).

**Środowisko wierszy**

| Wiersz `invoice` sprzedaży | `environment` | Przejście do `ready` |
| --- | --- | --- |
| z prawdziwej firmy (wdrożenie PROD) | `prod` | tylko przy `KSEF_TARGET=prod` |
| z fixtures (`kokpit-dev`, testy) | `KSEF_TARGET` w chwili utworzenia: `test` albo `demo` | tylko przy tym samym `KSEF_TARGET`; nigdy przy `prod` |

- Mapowanie FA(3) wymaga, by `invoice.environment` był równy bieżącemu `KSEF_TARGET`.
- Szkic z prawdziwej firmy nie trafi więc na api-test ani DEMO, także po wycofaniu F3 do `KSEF_TARGET=test`.
- F3 na api-test i DEMO działa tylko na fixtures.

## Polling i kursory

| Strumień (`sync_cursor.stream`) | Endpoint | Częstotliwość | Filtr | Kursor |
| --- | --- | --- | --- | --- |
| `invoices` | faktury | co 15 minut | `updated_min` = `last_value` minus 1 dzień | `last_value` = dzień startu udanego przebiegu; do go-live F3 nie później niż najwcześniejszy dzień ostatniej zmiany wśród faktur oczekujących (sekcja „Import 2026 do ewidencji”) |
| `payments` | płatności | co godzinę | jak wyżej | `last_value` = dzień startu udanego przebiegu, bez wstrzymania |
| backfill obu strumieni | faktury, płatności | raz, w F2.2/F2.3 | `backfill_from` = 2026-01-01; wszystkie strony po `per_page` ≤ 100 | `backfill_done_at` po ostatniej stronie; `last_value` = dzień startu backfillu |

- Filtr `updated_min` przyjmuje tylko datę (`YYYY-MM-DD`), więc okno zachodzi o jeden dzień ([FreshBooks, faktury](https://www.freshbooks.com/api/invoices)).
- `last_value` przesuwa się w tej samej transakcji co zapisane wiersze. Po awarii dłuższej niż doba przebieg nadrabia od ostatniego sukcesu.
- Lista zwraca najwyżej 100 wyników na stronę, niezależnie od `per_page`. Kokpit czyta każdą stronę do ostatniej ([FreshBooks, limity](https://www.freshbooks.com/api/limits)).
- Zmianę faktury z wierszem `invoice` wykrywa porównanie `source_hash`, czyli sha256 zmapowanych pól. Bez zmiany hasha nic się nie dzieje.
- Poza hashem są: znacznik w `po_number` (ustawiasz Ty), linia z numerem KSeF w `notes`, `create_date` i status (zapisuje kokpit).
- 409 dostaje tylko tworzenie faktur bez `invoice_number`; kokpit faktur nie tworzy ([FreshBooks, faktury](https://www.freshbooks.com/api/invoices)). Kolizję albo lukę P_2 z jednoczesnego tworzenia we FreshBooks wykrywa blokada P_2 i widok luk w numeracji.
- FreshBooks nie ma dziennego limitu zapytań, ale krótkie serie mogą być ograniczane ([FreshBooks, limity](https://www.freshbooks.com/api/limits)). 429 zapisuje `blocked_until` w liczniku `freshbooks.api` według `Retry-After`, jeśli przyjdzie; inaczej backoff (D-29, [../architecture.md](../architecture.md)).
- Kody błędów konektora są wyliczeniem, nie literałami.

## Znacznik gotowości i blokada P_2

Sam szkic niczego nie wyzwala. Nowy szkic to wiersz `invoice` w stanie `draft`; stan `ready` wymaga znacznika.

Znacznik działa od go-live F3, gdy `setting` `freshbooks.sales_enabled` jest włączony. Wcześniej wystawiasz według „Teraz – zgodność” w [../README.md](../README.md), a szkic zostaje w `draft`.

| Element | Reguła |
| --- | --- |
| znacznik (Q-26) | domyślnie wartość `freshbooks.ready_marker` w `po_number`; tag tylko, jeśli nagranie API potwierdzi pole; inaczej wybór w panelu |
| `ready_at` | chwila odczytu znacznika albo wyboru w panelu |
| po znaczniku | mapowanie FA(3), XSD i warunki wstępne; powstaje `approval` w stanie `pending` ([../approval-gate.md](../approval-gate.md)) |
| zdjęcie znacznika przed decyzją | faktura wraca do `draft`, zatwierdzenie → `superseded` |
| zdjęcie znacznika po zatwierdzeniu | bez skutku; wysyłkę wstrzymujesz anulowaniem w panelu (`cancelled_before_send`) |
| P_2 | `invoice_number` z FreshBooks; FreshBooks nadaje go przy utworzeniu, zwiększając poprzedni numer ([FreshBooks, faktury](https://www.freshbooks.com/api/invoices)) |
| blokada P_2 | przy zatwierdzeniu (`invoice_number_locked_at`) |
| zmiana numeru we FreshBooks przed wysyłką | nowe zatwierdzenie z nowym XML |
| zmiana numeru po przyjęciu w KSeF | alert; numer w KSeF zostaje, przywracasz go we FreshBooks |
| kolizja z P_2 już w KSeF | zatwierdzenie zablokowane ([ksef.md](ksef.md)) |
| usunięty szkic | `cancelled_before_send`; luka w numeracji widoczna w panelu |

- `freshbooks.ready_marker`: konfiguracja aplikacji, nie `setting`; domyślnie `KSEF-GOTOWA` w `po_number`.
- Implementacja F3.2 część a (D-52): `SalesReadiness` po każdym odczycie szkicu. Pozycje trafiają do `invoice_line` (`qty`, `unit_cost`, stawka z `ryczalt.service_map`). Szkic ze znacznikiem, który nie spełnia warunków stanu `ready` (onboarding klienta, data usługi, stawka pozycji, cel, warunki prośby), zostaje w `draft` z powodami w `invoice.ready_blockers`. Datę albo okres usługi wpisujesz w panelu przed `ready`.
- F3.2 część b (D-53): `FreshBooksBypass` po udanym przebiegu Subject1 daje `freshbooks_bypass` i zadanie w panelu („Zadania sprzedaży”, `invoice.panel_task`). Faktura z Subject1 o tym samym `kind` i P_2 anuluje szkic i żywe zatwierdzenie (`manual_invoice`); bez lokalnego wiersza od go-live powstaje zadanie `outside_cockpit`. Credit notes czyta strumień `credit_notes`; fakturę korygowaną wskazuje linia `Faktura: <numer>` w `notes`. Credit note daje szkic KOR (`CorrectionDrafts`), a edycja przyjętej faktury szkic KOR bez numeru do credit note. Zmiana samego numeru daje alert `freshbooks_number_changed`.
- Dokumentacja faktur FreshBooks nie opisuje tagów (sprawdzone 2026-10-08, [FreshBooks, faktury](https://www.freshbooks.com/api/invoices)).

## Blokady obejścia

FreshBooks potrafi dostarczyć klientowi dokument bez numeru KSeF i kodu QR, z inną datą. Klient nie widzi faktury w stanie Draft, ale Send, wysyłki cykliczne, auto-bill i `action_email` dostarczają ją od razu ([FreshBooks, faktury](https://www.freshbooks.com/api/invoices)).

**Checklista F3** `[manual]`

| Ustawienie albo akcja we FreshBooks | Reguła |
| --- | --- |
| Send i e-mail z FreshBooks | nigdy; po go-live F3 doręcza wyłącznie akcja „Doręcz klientowi” (PDF z KOD I) |
| profile cykliczne | bez automatycznej wysyłki; profil może tylko tworzyć szkice |
| auto-bill | wyłączony, bo obciąża kartę przed KSeF |
| oznaczenie jako wysłana | robi tylko kokpit, po przyjęciu w KSeF |
| credit note albo edycja po przyjęciu | szkic KOR w bramce, nigdy ponowna wysyłka (Q-27, [ksef.md](ksef.md)) |

Do go-live F3 obowiązuje reguła z „Teraz – zgodność” w [../README.md](../README.md): bez Send przed nadaniem numeru KSeF.

**Wykrywanie obejścia.** Działa od go-live F3 (`freshbooks.sales_enabled`).

- Każdy przebieg pollingu zapisuje ostatni widziany status FreshBooks w `invoice.freshbooks_status`.
- Kandydat to faktura FreshBooks ze statusem innym niż draft. Ma wiersz `invoice` utworzony od `valid_from` klucza `freshbooks.sales_enabled` albo nie ma wiersza i ma `create_date` od tego dnia.
- Faktura z wierszem `revenue_entry` ze źródłem `freshbooks` nie jest kandydatem.
- Kandydat czeka na najbliższy udany przebieg Subject1 ([ksef.md](ksef.md)).
- Gdy po nim nie ma przyjętej faktury (z `ksef_number`) z tym `freshbooks_id` albo numerem, powstaje `notification` kategorii `freshbooks_bypass` i zadanie w panelu.
- Faktura przyjęta, której zmapowane pola poza `invoice_number` zmieniły się we FreshBooks, daje szkic KOR; zmiana samego numeru daje tylko alert z wiersza tabeli wyżej.

## Po przyjęciu: zapis zwrotny i doręczenie

Kolejność zawsze ta sama: KSeF, potem klient.

**Zapis zwrotny** (`written_back_at`)

1. `PUT` faktury z `"action_mark_as_sent": true`, `create_date` = P_1 i linią dopisaną do istniejących `notes`, bez ich nadpisania ([FreshBooks, faktury](https://www.freshbooks.com/api/invoices)). Linia: „KSeF: {numer KSeF}. Faktura wystawiona w KSeF; ten widok nie jest fakturą. Wizualizacja z kodem QR: PDF w e-mailu / Invoice issued in KSeF; this view is not the invoice. See the PDF with QR code in the e-mail.”
2. Kokpit najpierw czyta fakturę; gdy numer już jest, pomija zapis. Ponowienie jest więc bezpieczne.
3. To zapis do FreshBooks, nie zapis urzędowy, więc idzie transportem `reads` z retry i backoffem, poza `official_writes` ([../operations.md](../operations.md)).
4. Oznaczenie jako wysłana otwiera klientowi portal i płatność Stripe.

**Pierwszy zapis zwrotny (D-35)**

- Firmy testowej nie ma, więc pierwszy zapis zwrotny działa na PROD: pierwsza faktura po przełączeniu F3, przez bramkę zatwierdzeń.
- Kryterium F3 jest `[manual]`. Dowód: zamaskowany zrzut faktury FreshBooks z numerem KSeF i wpis `audit_log`, w `docs/evidence/f3.md`.
- Wcześniej treść żądania `PUT` i pominięcie przy obecnym numerze sprawdzają testy na fixtures.

**Doręczenie klientowi (Q-26, D-07)**

- Klient dostaje PDF z kokpitu z KOD I i numerem KSeF, dwujęzyczny PL i EN ([ksef.md](ksef.md)).
- Nabywca zagraniczny otrzymuje fakturę w uzgodniony sposób. Faktura przekazana poza KSeF musi mieć kod QR (art. 106gb ust. 4 i 5, [ustawa o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)).
- Polski nabywca z NIP otrzymuje fakturę w KSeF w dniu nadania numeru (art. 106na ust. 3, [ustawa o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). PDF jest kopią i też ma kod QR.
- Akcja „Doręcz klientowi” w panelu wysyła e-mail konektorem `mail`: PDF z kokpitu i link do płatności FreshBooks. Bez kliknięcia nic nie wychodzi.
- Wysyłka idzie synchronicznie w żądaniu panelu, poza Messenger (D-31). Wynik albo błąd widzisz od razu w panelu.
- Adres e-mail i osobę kontaktową kokpit czyta z FreshBooks w chwili wysyłki.
- Doręczenie to osobna wiadomość, nie `notification`. Zasada treści powiadomień z D-19 jej nie dotyczy ([../security.md](../security.md)).
- Jedno kliknięcie to jedna próba, bez automatycznego ponowienia. Ponowienie to nowe kliknięcie; panel pokazuje wtedy datę poprzedniego doręczenia.
- Linia w `notes` z kroku 1 zapisu zwrotnego wskazuje klientowi PDF z kodem QR.
- Widok faktury w portalu FreshBooks to wizualizacja bez kodu QR. Ten punkt jest w zakresie przeglądu doradcy (Q-29).
- Każde doręczenie trafia do `audit_log` jako `invoice.delivered_to_client` ([../security.md](../security.md)).

## Stripe i płatności

**Link do płatności.** Klient płaci przez Stripe dopiero po oznaczeniu faktury jako wysłanej, czyli po przyjęciu w KSeF. Auto-bill jest wyłączony.

**Numer KSeF w przelewie.** Od 1.01.2027 czynny podatnik VAT płacący przelewem za fakturę z KSeF podaje jej numer KSeF (art. 108g, [ustawa o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Dotyczy to Twoich polskich klientów, więc numer KSeF stoi na PDF i w e-mailu. Płatność kartą przez Stripe nie ma tytułu przelewu; zakres obowiązku sprawdza [../facts.md](../facts.md).

**Import płatności (Q-24)** ([FreshBooks, płatności](https://www.freshbooks.com/api/payments))

| Pole FreshBooks | `payment` |
| --- | --- |
| `id` | `freshbooks_payment_id` |
| `invoiceid` | `freshbooks_invoice_id`; po dopasowaniu `invoice_id` albo `revenue_entry_id` i `matched_at` |
| `date` | `paid_on` |
| `amount.amount`, `amount.code` | `amount`, `currency` |
| `gateway` | `gateway`, np. Stripe |

- Płatność częściowa to kolejny wiersz `payment` dla tej samej faktury.
- Rekord płatności FreshBooks nie podaje opłaty bramki ani wypłaty. `fee_*` i `payout_*` uzupełniasz z raportu Stripe albo zostają puste (Q-24).
- Opłaty Stripe to osobne dokumenty kosztowe, np. raport opłat jako dokument wewnętrzny ([../tax-rules.md](../tax-rules.md)).
- Różnice kursowe, metoda kasowa PIT i wpis do ewidencji: [../tax-rules.md](../tax-rules.md), [ewidencja.md](ewidencja.md).

## Klient i kontrahent

Pierwszy szkic dla nowego klienta FreshBooks tworzy zadanie onboardingu. Bez ukończonego onboardingu faktura nie przejdzie do `ready`.

| Dane | Właściciel |
| --- | --- |
| nazwa, adres | FreshBooks; kokpit kopiuje do `counterparty` |
| e-mail, osoba kontaktowa | FreshBooks; kokpit czyta je przy doręczeniu, bez kopii |
| kraj, rodzaj i wartość identyfikatora podatkowego | kokpit, potwierdzasz Ty |
| weryfikacja w rejestrach | kokpit ([rejestry-kursy.md](rejestry-kursy.md)) |
| scenariusz FA(3) | kokpit, wyliczony z kraju i weryfikacji |
| dane nabywcy na fakturze | `buyer_snapshot` z dnia wystawienia |

**Onboarding**

1. Kokpit czyta klienta FreshBooks i tworzy `counterparty` z `freshbooks_client_id`.
2. Potwierdzasz kraj i identyfikator: numer VAT UE, zagraniczny identyfikator podatkowy albo NIP. Klient z UE bez ważnego VIES podaje krajowy identyfikator (`eu_b2b_unidentified`, Q-29).
3. Weryfikacja według polityki w [rejestry-kursy.md](rejestry-kursy.md).
4. Scenariusz według tabeli w [ksef.md](ksef.md).

- Onboarding jest ukończony, gdy `counterparty` ma kraj i identyfikator, a dla typu kontrahenta istnieje wymagany wiersz `verification`.
- Wymagany wiersz wynika z polityki w [rejestry-kursy.md](rejestry-kursy.md). Dla klienta spoza UE to `manual_evidence` z Twoim `checked_by`. Dla `eu_b2b_unidentified` to `manual_evidence` statusu przedsiębiorcy.
- Klient bez identyfikatora podatkowego blokuje zatwierdzenie: tylko B2B (D-05).
- Zmiana kraju albo identyfikatora we FreshBooks wymaga ponownej weryfikacji przed kolejną fakturą. Faktur już wystawionych nie zmienia.

## DTO faktury niezależne od źródła

- Adapter FreshBooks zamienia fakturę na DTO kokpitu: numer, nabywca, waluta, pozycje z `service_type`, daty usługi, uwagi.
- Mapper FA(3) zna tylko DTO, nie FreshBooks. Inne źródło, np. Stripe, to kolejny adapter bez zmian w FA(3) (D-25).
- Pozycja FreshBooks mapuje się na `service_type` przez `setting` `ryczalt.service_map`. Pozycja bez mapowania blokuje zatwierdzenie ([../tax-rules.md](../tax-rules.md)).

| Pole DTO | Źródło |
| --- | --- |
| data usługi | pole FreshBooks `fulfillment_date`, gdy nagranie API je potwierdzi |
| okres usługi albo data bez potwierdzonego pola | wpis w panelu przed `ready` |

- Brak daty albo okresu usługi blokuje `ready`. P_6, P_6A albo `OkresFa` wybiera mapper ([ksef.md](ksef.md)).
- Dokumentacja faktur nie opisuje daty usługi; `fulfillment_date` jest tylko w przykładach odpowiedzi (sprawdzone 2026-10-08, [FreshBooks, faktury](https://www.freshbooks.com/api/invoices)).
- Potwierdzone pole trafia do [../facts.md](../facts.md) ze źródłem.

## Import 2026 do ewidencji

Ewidencja 2026 powstaje najpierw w F2.1 z importu CSV albo JPK_EWP(4) z narzędzia z Q-11. W F2.2/F2.3 dochodzą dwa źródła uzgodnienia ([ewidencja.md](ewidencja.md), D-23, D-30).

| Faktura | Źródło w kokpicie | Faza | `revenue_entry.source` |
| --- | --- | --- | --- |
| każda faktura 2026 z narzędzia ewidencji | eksport CSV albo JPK_EWP(4) | F2.1 | `import` |
| wystawiona w KSeF (Aplikacja Podatnika albo inne narzędzie) | Subject1 z KSeF ([ksef.md](ksef.md)) | F2.2/F2.3 | `ksef_subject1` |
| wystawiona poza KSeF przed przejściem | FreshBooks | F2.2/F2.3 | `freshbooks` |

- Deduplikacja działa we wszystkich `source` ([ewidencja.md](ewidencja.md)). Faktura FreshBooks z wierszem `import` nie dostaje drugiego wiersza.
- FreshBooks i Subject1 to źródła uzgodnienia, nie warunek dry runu (D-30).

**Dzień przejścia.** `setting` `ksef.sales_transition_on` to dzień pierwszej Twojej faktury w KSeF. Domyślnie to P_1 najstarszej faktury z backfillu Subject1 (F1); potwierdzasz go w Q-10 ([ksef.md](ksef.md)). Data FreshBooks to `create_date`. Data przychodu faktury sprzed przejścia to `create_date`, a przy wcześniejszej zapłacie dzień zapłaty (art. 14 ust. 1c, D-45).

**Klasyfikacja faktury FreshBooks** (backfill i każdy przebieg strumienia `invoices`)

| Przypadek | F2.2/F2.3, do go-live F3 | Od go-live F3 (`freshbooks.sales_enabled`) |
| --- | --- | --- |
| `invoice_number` równy P_2 faktury sprzedaży z Subject1 o `kind` `VAT`, bez `freshbooks_id` | wpis `invoice.freshbooks_id`; bez drugiego wiersza | to samo |
| bez dopasowania, status inny niż draft, data przed dniem przejścia | wiersz `revenue_entry` ze źródłem `freshbooks` przez DTO, chyba że deduplikacja znajdzie wiersz `import` | to samo |
| bez dopasowania, szkic z datą przed dniem przejścia | pominięta | wiersz `invoice` w stanie `draft` |
| bez dopasowania, data od dnia przejścia | wiersz `invoice` w stanie `draft`, bez `ready`; bez ustalonego kraju klienta oczekuje bez wiersza (D-45) | wiersz `invoice` w stanie `draft`; wykrywanie obejścia z sekcji „Blokady obejścia” |

**Faktura oczekująca (do go-live F3)**

- Oczekująca to szkic `draft` bez odpowiednika z KSeF albo faktura od dnia przejścia bez ustalonego kraju klienta (D-45).
- Kursor `invoices` nie wyprzedza najwcześniejszego dnia ostatniej zmiany wśród faktur oczekujących. Każdy przebieg czyta je więc ponownie, niezależnie od `source_hash`.
- Pierwszy przebieg po imporcie odpowiednika z Subject1 dopasowuje oczekującą fakturę według pierwszego wiersza tabeli.
- Oczekująca faktura ze statusem innym niż draft trafia na listę „faktura bez KSeF” w panelu, gdy nie dopasuje jej udany przebieg Subject1. Liczy się przebieg rozpoczęty po jej ostatniej zmianie we FreshBooks.
- Panel liczy tę listę na żądanie. Czyta z FreshBooks faktury z filtrem `date_min` = dzień przejścia i pomija te z `invoice.freshbooks_id` ([FreshBooks, faktury](https://www.freshbooks.com/api/invoices)). Czas przebiegów Subject1 bierze z `sync_cursor`.
- Faktura bez KSeF nie dostaje wiersza w ewidencji.
- Porzucony szkic usuwasz we FreshBooks; usunięta faktura przestaje oczekiwać.

**Idempotencja i zmiany po imporcie**

- Ponowny odczyt tej samej faktury nie tworzy drugiego wiersza: deduplikacja według [ewidencja.md](ewidencja.md). Nie ma tabeli pośredniej ani zapisanego `source_hash`.
- Późniejszą zmianę faktury we FreshBooks pokazuje comiesięczne uzgodnienie FreshBooks ([ewidencja.md](ewidencja.md)). Korektę wpisujesz ręcznie.

Faktura z Subject1 i faktura FreshBooks o tym samym numerze to jeden wiersz `invoice`, z KSeF. Kolejność odczytu nie ma znaczenia.

| Kolejność | F2.2/F2.3, do go-live F3 | Od go-live F3 |
| --- | --- | --- |
| Subject1 przed FreshBooks | polling FreshBooks wpisuje `freshbooks_id` do faktury z KSeF | to samo; szkic nie powstaje |
| FreshBooks przed Subject1 | jak od go-live F3: import Subject1 w jednej transakcji przenosi szkic do `cancelled_before_send` i daje fakturze z KSeF jego `freshbooks_id` (D-45) | w jednej transakcji szkic oddaje `freshbooks_id` i przechodzi do `cancelled_before_send`, potem powstaje faktura z KSeF z tym `freshbooks_id` |

- Od go-live F3 taki szkic nie daje alertu o luce w numeracji, bo numer ma faktura z KSeF.

**Płatności 2026**

- Płatności 2026 importują się do `payment`, bo z nich liczą się różnice kursowe.
- Płatność dopasowuje się po `payment.freshbooks_invoice_id` do `invoice.freshbooks_id`.
- Inaczej kokpit czyta z FreshBooks numer faktury o tym id. Dopasowuje go do `revenue_entry.document_number` wiersza `regular` dowolnego `source`, także `import` z F2.1.
- Kolumna `payment.revenue_entry_id`: [../data-model.md](../data-model.md).
- Niedopasowane płatności sprawdza ponownie każdy przebieg pollingu.
- Uzgodnienie sum z FreshBooks i Aplikacją Podatnika KSeF jest kryterium F2.2/F2.3 na liście `docs/evidence/f2.md`.

## Testy

- Fixtures w `tests/Fixtures/FreshBooks` są pisane ręcznie według dokumentacji API ([FreshBooks, faktury](https://www.freshbooks.com/api/invoices), [FreshBooks, płatności](https://www.freshbooks.com/api/payments)). Firmy testowej nie ma (D-35).
- Nagranie z prawdziwej firmy: tylko odczyt, `app:fixtures:record freshbooks <scenariusz>` na `kokpit-prod`, uruchamiasz Ty. Zapis zawsze przez scrubber ([../testing.md](../testing.md), [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md)).
- Testów `@group live` dla FreshBooks nie ma. Żaden test nie łączy się z prawdziwą firmą ani do niej nie zapisuje.
- Odczyt prawdziwej firmy sprawdzają kryteria F2.2/F2.3 na PROD; pierwszy zapis zwrotny to kryterium `[manual]` F3 (D-35).
- Szkic kryteriów F3 jest w [../README.md](../README.md), m.in. alert przy fakturze, która opuszcza Draft bez KSeF.
