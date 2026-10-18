# Model danych

Tabele, pola, klucze, stany encji, typy kwot i dat oraz układ plików kokpitu. Stan na 8 października 2026.

## Jak czytać

- Sesja fazy czyta sekcje „Konwencje”, „Kwoty i zaokrąglenia”, „Daty i strefa czasu”, „Środowiska” oraz tabele ze swoją fazą w „Mapie tabel”.
- Nazwy tabel, stanów i wyliczeń pochodzą z kontraktu nazw w [naming.md](naming.md).
- Ten plik definiuje kolumny, klucze i przejścia stanów: faktury, złożenia i encji bez kolumny stanu. Pozostałe stany mają tu tylko skrót i link do domu.
- Reguły podatkowe (kursy, stawki, okresy) są w [tax-rules.md](tax-rules.md). Tu są tylko kolumny, które je przechowują.
- Tabele i kolumny odłożone (D-29) są w sekcji „Odłożone”. Nie powstają w migracjach, dopóki nowy rekord D-xx nie przywróci modułu.

## Konwencje

| Element | Reguła |
| --- | --- |
| Klucz główny | `id uuid` (UUIDv7 z Symfony Uid), nadawany w aplikacji przed zapisem |
| Logi tylko dopisywane | `connector_call`, `audit_log`, `chat_message`: `id bigint generated always as identity` |
| Tekst i kody | `text`; kraj `char(2)` (ISO 3166-1), waluta `char(3)` (ISO 4217) |
| Wyliczenia | `text` z ograniczeniem `CHECK`; bez typów `enum` PostgreSQL, bo utrudniają migracje |
| Hash | `char(64)`: sha256 dokładnych bajtów, hex małymi literami |
| Struktury i dane surowe | `jsonb` |
| Znaczniki czasu | `created_at`, `updated_at` (`timestamptz`) na tabelach zmiennych |
| Wersja encji | `version int` (optimistic lock Doctrine) na `invoice`, `filing`, `draft`; `approval.source_version` wiąże zatwierdzenie z wersją |
| Stany | kolumna stanu obsługiwana przez Symfony Workflow typu `state_machine`; każde przejście ma nazwę, wyzwalacz i aktora |
| Akcje użytkownika | przejścia oznaczone „Ty” wymagają zalogowania passkeyem; akcje z tabeli step-up w [security.md](security.md) wymagają świeżej asercji (step-up od F0b, D-28) |
| Wielodostępność | brak `tenant_id`: jedna baza i jeden katalog plików na wdrożenie |
| Migracje | Doctrine Migrations; zmiana `CHECK` to osobna migracja |
| Tabele techniczne | `messenger_messages`, `doctrine_migration_versions` i magazyn locków są poza kontraktem; wiadomości Messenger niosą tylko identyfikatory |
| Dane osobowe w przykładach | wyłącznie fikcyjne, oznaczone jako fikcyjne |

## Kwoty i zaokrąglenia

| Wielkość | Typ PostgreSQL | Typ PHP |
| --- | --- | --- |
| kwota | `NUMERIC(18,2)` | `Brick\Math\BigDecimal` przez własny typ DBAL |
| kurs waluty | `NUMERIC(18,6)` | `BigDecimal` |
| cena jednostkowa | `NUMERIC(22,8)` | `BigDecimal` |
| ilość | `NUMERIC(18,6)` | `BigDecimal` |
| stawka w procentach | `NUMERIC(5,2)`, np. `8.50`, `23.00` | `BigDecimal` |
| koszt API w USD | `NUMERIC(12,6)` | `BigDecimal` |
| pewność, próg basal (odłożone, D-29) | `NUMERIC(6,5)` | `BigDecimal` |

**Zasady**

- Nigdy `float`, ani w kolumnie, ani w PHP. Test architektury odrzuca `float` w encjach i usługach z `src/Tax` i `src/Ledger`.
- Kwota w walucie ma obok kolumnę `currency`. Kolumny z przyrostkiem `_pln` są zawsze w PLN.
- Obliczenia pośrednie (podział proporcjonalny, przeliczenie kursem) mają skalę 10. Zaokrąglenie następuje raz, przy zapisie wyniku.
- Tryb zaokrąglenia: `RoundingMode::HALF_UP`.
- Korekty przechowują różnicę ze znakiem; kwota może być ujemna.

| Dokument | Poziom zaokrąglenia | Reguła | Podstawa |
| --- | --- | --- | --- |
| FA(3) | VAT per stawka z sumy netto pozycji tej stawki (P_13_x, P_14_x) | do grosza | art. 106e ust. 11 [ustawy o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf); poziom per stawka to konwencja kokpitu, bez sumowania VAT z pozycji z ust. 10 |
| faktura kosztowa | kwoty z dokumentu dostawcy | bez przeliczania | kokpit zapisuje kwoty wystawcy |
| FA(3) w walucie z polskim VAT | P_14_xW per stawka: P_14_x × kurs VAT | do grosza | konwencja kokpitu; wybór kursu w [tax-rules.md](tax-rules.md) |
| `invoice.net_pln`, `vat_pln` | suma faktury × kurs VAT | do grosza | konwencja kokpitu |
| `revenue_entry.amount_pln` (K_8) | netto faktury w jednej stawce ryczałtu × kurs PIT; przy `source` = `import` wartość ze źródła, bez przeliczenia (D-30) | do grosza | konwencja kokpitu; wybór kursu w [tax-rules.md](tax-rules.md) |
| JPK_V7M(3), część deklaracyjna | podstawy i kwoty podatku | pełne złote | art. 63 § 1 [Ordynacji podatkowej](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf); pola w [modules/jpk.md](modules/jpk.md) |
| zaliczka na ryczałt | kwota do zapłaty | pełne złote | art. 63 § 1 [Ordynacji podatkowej](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf) |
| składki ZUS | kwoty z `setting` `zus.parameters` | grosze | bez przeliczeń w kokpicie |

## Daty i strefa czasu

- Daty prawne (`issue_date`, terminy, `entry_date`, `revenue_date`) mają typ `date` i są liczone w strefie Europe/Warsaw.
- Chwile (`sent_at`, `decided_at`, `received_at`) mają typ `timestamptz`. Połączenie z bazą pracuje w UTC.
- Data biznesowa z chwili powstaje przez konwersję do Europe/Warsaw i obcięcie do dnia. Robi to jedna funkcja z testami na północ i zmianę czasu.
- Bieżący czas pochodzi wyłącznie z Symfony Clock; w testach `MockClock` ([testing.md](testing.md)).
- Okres rozliczeniowy to miesiąc. Kluczem jest `date` pierwszego dnia miesiąca (`period_start`, `vat_period_start`).
- Obowiązek ma datę nominalną i datę po przesunięciu (`nominal_due_date`, `due_date`). Przypomnienia liczą się od `due_date`; reguły przesunięć w [compliance-calendar.md](compliance-calendar.md).
- Regułę P_1 i porównanie z dniem przyjęcia przez KSeF opisuje [modules/ksef.md](modules/ksef.md) (D-16); daty liczone w Europe/Warsaw.

## Środowiska

Każde wdrożenie ma osobną bazę i osobny projekt compose: `kokpit-prod` na VPS, `kokpit-dev` na laptopie, `kokpit-restore` jako jednorazowy projekt lokalny do testu odtworzenia (D-28, [operations.md](operations.md)). Kolumna `environment` to druga bariera wewnątrz jednej bazy, bo cele konektorów przechodzą na produkcję w różnych fazach.

| Element | Reguła |
| --- | --- |
| Kolumna | `environment text NOT NULL CHECK (environment IN ('test','demo','prod'))`; `int` wraca z F4 osobną migracją (sekcja „Odłożone”) |
| Tabele z kolumną | `invoice`, `ksef_session`, `counterparty`, `verification`, `payment`, `revenue_entry`, `fixed_asset`, `zus_payment`, `period`, `filing`, `obligation`, `approval`, `draft`, `stored_file`, `connector_call`, `rate_counter`, `sync_cursor`, `credential_state`, `mail_in_message`; w `audit_log` kolumna wskazuje cel akcji i może być pusta |
| Tabele bez kolumny | `invoice_line` (dziedziczy po `invoice`), `fx_rate`, `setting`, `notification`, `app_user`, `webauthn_credential`, `chat_session`, `chat_message`, `legal_watch`, `legal_watch_hit` (stan prawa jest ten sam dla każdego środowiska) |
| Środowisko wdrożenia | `prod` przy `APP_ENV=prod` (`kokpit-prod`, `kokpit-restore`), w innym wypadku `test` (`kokpit-dev`, testy); ten sam przełącznik wybiera hosty rejestrów ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)) |
| Dane prawdziwe | tylko w `kokpit-prod` i w jego kopii w `kokpit-restore`; `kokpit-dev` i testy działają wyłącznie na danych fikcyjnych |
| Wiersz z konektora z celem | cel konektora w chwili zapisu (`KSEF_TARGET`, `JPK_TARGET`, `EDEKLARACJE_TARGET`) |
| Wiersz z FreshBooks: dane (`invoice`, `payment`, `counterparty`, `revenue_entry`) i techniczne (`connector_call`, `rate_counter`, `sync_cursor`, `credential_state`) | na `kokpit-prod`: `prod`, z prawdziwej firmy, tylko odczyt do pierwszego zapisu zwrotnego w F3; w `kokpit-dev` i testach: tylko fixtures, `KSEF_TARGET` w chwili zapisu (`test` albo `demo`); firmy testowej nie ma (D-35, [modules/freshbooks.md](modules/freshbooks.md)) |
| Konektor bez celu: `vies`, `white_list`, `nbp`, `eli`, `mail_in`, `claude`, `push`, `mail`, `deadman`, `dummy` | środowisko wdrożenia; dotyczy `connector_call`, `rate_counter` i `credential_state`, np. `dummy_token` |
| Wiersz `verification` z rejestru (VIES, biała lista) | `environment` kontrahenta, którego dotyczy; samosprawdzenie z pustym `counterparty_id`: środowisko wdrożenia |
| Wiersz `obligation` | obowiązek z encją (`credential_state`, `counterparty`, `invoice`) dziedziczy jej `environment`; obowiązek bez encji (`zus`, `vat_jpk_v7m`, `manual_channel_check`, wpisany ręcznie `edelivery_reply_due` i inne): środowisko wdrożenia |
| `draft` | `environment` odbiorcy (`counterparty`) |
| `stored_file` | `environment` właściciela; właściciel bez kolumny `environment` (`setting`) albo zapis bez właściciela (testy): środowisko wdrożenia |
| Złożenie bez konektora (dry run, `manual`) | `environment` danych źródłowych, niezależnie od `JPK_TARGET`. Dry run na danych 2026 tylko w `kokpit-prod` (`prod`); w `kokpit-dev` i testach tylko na danych fikcyjnych (`test`). Wysyłka przez konektor wymaga `filing.environment` = `JPK_TARGET` albo `EDEKLARACJE_TARGET` |
| Wiersz ręczny albo z importu pliku (`source` = `import`) | środowisko wdrożenia; formularz ani komenda nie wybierają go dla wiersza |
| Klucze `setting` `opening.*` | opisują dane środowiska wdrożenia; plik do urzędu w innym `environment` ich nie używa |
| Reguła twarda | plik do urzędu w środowisku X agreguje wyłącznie wiersze z `environment = X` |
| Unikalność | każdy klucz biznesowy (numer KSeF, numer referencyjny, numer faktury) jest unikalny w parze z `environment` |
| Raporty | panel, raporty i widoki `chat_*` filtrują po `environment` |

## Mapa tabel

| Tabela | Cel | Faza | Dom semantyki |
| --- | --- | --- | --- |
| `stored_file` | rejestr plików adresowanych sha256 | F0a | ten plik |
| `connector_call` | każde wywołanie zewnętrzne; indeks `(environment, started_at)` pod panel zdrowia (D-85) | F0a | [security.md](security.md) |
| `audit_log` | akcje użytkownika i zapisy w urzędzie, łańcuch hashy | F0a | [security.md](security.md) |
| `rate_counter` | liczniki limitów per konektor | F0a | [architecture.md](architecture.md) |
| `credential_state` | tokeny rotujące i daty ważności poświadczeń | F0a | [security.md](security.md) |
| `app_user`, `webauthn_credential` | użytkownik panelu i passkeye | F0a | [security.md](security.md) |
| `revenue_entry` | ewidencja przychodów (JPK_EWP) | F2.1 | [modules/ewidencja.md](modules/ewidencja.md) |
| `setting` | parametry z datą obowiązywania | F2.1 (klucze ewidencji i JPK), F0b (klucze kalendarza) | ten plik |
| `fixed_asset` | wykaz ŚT i WNiP (JPK_ST) | F2.4 | [modules/jpk.md](modules/jpk.md) |
| `filing` | złożenia JPK, VAT-UE i PIT-28 | F2.4 (dry run), F2.2/F2.3 (złożenie `manual`), F5 | ten plik; pliki w [modules/jpk.md](modules/jpk.md) |
| `approval` | bramka zatwierdzeń | F0b | [approval-gate.md](approval-gate.md) |
| `obligation` | terminy z kwotą lub czynnością | F0b | [compliance-calendar.md](compliance-calendar.md) |
| `notification` | przypomnienia i alerty bez duplikatów | F0b | [compliance-calendar.md](compliance-calendar.md), [security.md](security.md) |
| `invoice`, `invoice_line` | faktury sprzedaży i kosztowe z pozycjami | F1 (koszty, import Subject1), F3 (sprzedaż) | ten plik; reguły FA(3) w [modules/ksef.md](modules/ksef.md) |
| `counterparty`, `verification` | kontrahenci i snapshoty weryfikacji | F1 | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |
| `fx_rate` | kursy NBP, niezmienne | F1 | reguła: [tax-rules.md](tax-rules.md); API: [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |
| `sync_cursor` | kursory synchronizacji | F1 (KSeF), F2.2/F2.3 (FreshBooks) | [modules/ksef.md](modules/ksef.md) |
| `payment` | płatności klientów z FreshBooks | F2.2/F2.3 | [tax-rules.md](tax-rules.md) |
| `zus_payment` | zapłacone składki ZUS | F2.2/F2.3 | [tax-rules.md](tax-rules.md) |
| `period` | miesiąc, zamknięcie i blokada | F2.2/F2.3 | [compliance-calendar.md](compliance-calendar.md) |
| `ksef_session` | sesje wysyłki do KSeF | F3 | [modules/ksef.md](modules/ksef.md) |
| `chat_session`, `chat_message`, `draft` | historia czatu i szkice | F6 | [modules/czat.md](modules/czat.md) |
| `legal_watch`, `legal_watch_hit` | obserwowane akty i procesy legislacyjne, wykryte zmiany | po F6 (D-75) | [modules/obserwacja-prawa.md](modules/obserwacja-prawa.md) |
| `mail_in_message` | wiadomości z dedykowanej skrzynki na dokumenty kosztowe | po F1 (D-92) | [decisions.md](decisions.md), D-92 |

- Kolejność wierszy idzie za kolejnością realizacji z D-30: F0a → F2.1 → F2.4 → F0b → F1 → F2.2/F2.3 → F3 → F5 → F6. Dokładny podział F2 jest w [phases/f2-ewidencja.md](phases/f2-ewidencja.md).
- F2.1 i F2.4 działają przed F0b i F1. `revenue_entry.invoice_id`, `payment_id`, `fx_rate_id` oraz `fixed_asset.invoice_id` powstają bez kluczy obcych. Klucz obcy dodaje migracja fazy, która tworzy tabelę docelową: F1 (`invoice`, `fx_rate`), F2.2/F2.3 (`payment`).
- `setting` powstaje w F2.1 z kluczami potrzebnymi F2.1 i F2.4: `company.identity`, `pit.kasowy`, `jpk_st.enabled`. Dry run tworzy `filing` w stanie `draft`, bez `approval`.
- Tabele odłożone (D-29): `edelivery_message`, `edelivery_case`, `classification`. Definicje są w sekcji „Odłożone”. Tabela `opening_balance` nie powstaje; bilans otwarcia to klucze `setting` `opening.*`.

## Tabele

Kolumny `id`, `environment`, `created_at` i `updated_at` pomijam w opisach, jeśli działają według konwencji.

### Faktury

#### `invoice`

Jedna tabela na faktury sprzedaży i kosztowe. Kierunek rozróżnia `direction`; stan sprzedaży jest w `status`, stan kosztu w `cost_status`.

**Pola wspólne**

| Pole | Typ | Opis |
| --- | --- | --- |
| `direction` | text | `sale` albo `cost` |
| `kind` | text | `RodzajFaktury` z [FA(3)](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/schemy/FA/schemat_FA%283%29_v1-0E.xsd): `VAT`, `KOR`, `ZAL`, `ROZ`, `UPR`, `KOR_ZAL`, `KOR_ROZ`; dokument spoza KSeF dostaje najbliższy odpowiednik, zwykle `VAT` |
| `invoice_number` | text null | P_2, numer nadany przez wystawcę; pusty tylko w szkicu KOR przed numerem credit note (`CHECK` `invoice_number_kor_draft`, D-53) |
| `counterparty_id` | uuid FK | nabywca przy sprzedaży, sprzedawca przy koszcie |
| `currency` | char(3) | KodWaluty |
| `ksef_number` | text null | numer KSeF |
| `schema_version` | text null | wersja schematu XML, np. `FA(3) 1-0E`; sprzedaż: przy budowie `xml_sent`; sprzedaż z Subject1 i koszt: przy imporcie XML z KSeF; pusty przy dokumencie spoza KSeF |
| `ksef_issue_mode` | text null | `online`, `offline24`, `maintenance`, `failure`, `total_failure`, `outside_ksef`; pusty tylko przy fakturze z numerem z importu KSeF; wartości per rodzaj faktury i znacznik JPK w [modules/jpk.md](modules/jpk.md), tryby w [modules/ksef.md](modules/ksef.md) |
| `version` | int | optimistic lock |

**Daty**

| Pole | Typ | Opis |
| --- | --- | --- |
| `issue_date` | date | P_1; przy sprzedaży ustalana według reguły P_1 z [modules/ksef.md](modules/ksef.md) (D-16), nigdy kopiowana z FreshBooks |
| `service_date` | date null | P_6 |
| `service_period_start`, `service_period_end` | date null | OkresFa (`service_period`) |
| `sent_at` | timestamptz null | sprzedaż: chwila wysyłki do KSeF |
| `ksef_number_assigned_at` | timestamptz null | nadanie numeru KSeF; przy koszcie to data otrzymania faktury i najwcześniejszy okres odliczenia (art. 106na ust. 3 [ustawy o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)) |
| `received_on` | date null | koszt spoza KSeF: data otrzymania, wpisujesz Ty |
| `revenue_date` | date null | sprzedaż: data uzyskania przychodu (PIT); reguła w [tax-rules.md](tax-rules.md) |
| `tax_point_date` | date null | obowiązek podatkowy VAT: sprzedaż; przy koszcie data obowiązku u sprzedawcy albo z importu usług; reguła w [tax-rules.md](tax-rules.md) |
| `vat_period_start` | date null | miesiąc w JPK_V7M: sprzedaż według `tax_point_date`, koszt według przypisanego okresu odliczenia |

**Kwoty i kursy**

| Pole | Typ | Opis |
| --- | --- | --- |
| `net_total`, `vat_total`, `gross_total` | NUMERIC(18,2) | w walucie faktury; przy KOR różnica ze znakiem |
| `fx_vat_rate_id` | uuid FK null | kurs do VAT z `fx_rate`; pusty dla PLN |
| `fx_pit_rate_id` | uuid FK null | kurs do PIT z `fx_rate`; tylko sprzedaż w walucie |
| `net_pln`, `vat_pln` | NUMERIC(18,2) | po kursie VAT; dane do JPK_V7M |

Kwota przychodu po kursie PIT jest w `revenue_entry.amount_pln`, nie w `invoice`.

**Sprzedaż**

| Pole | Typ | Opis |
| --- | --- | --- |
| `status` | text null | stan faktury sprzedaży; pusty przy koszcie |
| `freshbooks_id` | text null | id faktury FreshBooks |
| `freshbooks_status` | text null | ostatni widziany status FreshBooks; wykrywanie obejścia ([modules/freshbooks.md](modules/freshbooks.md)) |
| `ready_at` | timestamptz null | odczytany znacznik gotowości albo wybór w panelu ([modules/freshbooks.md](modules/freshbooks.md)) |
| `invoice_number_locked_at` | timestamptz null | blokada P_2 przy zatwierdzeniu |
| `panel_task` | text null | zadanie sprzedaży w panelu: `freshbooks_bypass`, `outside_cockpit`, `freshbooks_number_changed` (D-53), `p2_collision` (kolizja P_2 przy 440, D-54); `panel_task_at` to chwila otwarcia; zapisywalne też po przyjęciu |
| `freshbooks_credit_id` | text null | credit note FreshBooks szkicu KOR (D-53); unikalny w `environment` |
| `ready_blockers` | jsonb null | lista powodów, dla których szkic ze znacznikiem gotowości zostaje w `draft` (panel); pusta poza tym stanem (D-52) |
| `source_hash` | char(64) null | sha256 zmapowanych pól źródła; zmiana po przyjęciu prowadzi do korekty, nigdy do ponownej wysyłki |
| `tax_scenario` | text null | `eu_b2b`, `eu_b2b_unidentified`, `non_eu_b2b`, `pl_b2b`; `eu_b2b_unidentified` czeka na potwierdzenie doradcy (Q-29, D-05) i do tego czasu blokuje zatwierdzenie; mapowanie FA(3) w [modules/ksef.md](modules/ksef.md) |
| `buyer_snapshot` | jsonb null | dane nabywcy z dnia wystawienia: nazwa, adres, kraj, identyfikator |
| `verification_id` | uuid FK null | wynik VIES albo dowód identyfikatora zagranicznego użyty przy zatwierdzeniu (tylko B2B); przy `eu_b2b_unidentified` dowód `manual_evidence` statusu przedsiębiorcy |
| `ksef_session_id` | uuid FK null | sesja, w której poszła faktura |
| `written_back_at` | timestamptz null | zapis zwrotny numeru KSeF do FreshBooks |

**Koszty**

| Pole | Typ | Opis |
| --- | --- | --- |
| `cost_status` | text null | `imported`, `reviewed`, `assigned`; pusty przy sprzedaży |
| `cost_review` | text null | `business`, `private`, `not_mine`, `disputed` |
| `source` | text null | `ksef`, `email`, `paper`, `cash_register`, `manual` |
| `import_services` | text null | `eu_28b` (K_29, K_30) albo `other` (K_27, K_28); klasyfikacja w [tax-rules.md](tax-rules.md) |
| `vat_exempt` | boolean null | usługa zwolniona albo bez podatku do naliczenia; pusty przy sprzedaży |
| `self_assessed_vat_pln` | NUMERIC(18,2) null | VAT należny z importu usług |
| `deductible` | boolean null | prawo do odliczenia; pusty przy sprzedaży |
| `jpk_doc_type` | text null | `WEW` dla dokumentu wewnętrznego, np. raportu opłat Stripe |
| `is_fixed_asset` | boolean null | zakup środka trwałego; wiersz w `fixed_asset`; pusty przy sprzedaży |
| `tax_point_override_reason` | text null | powód ręcznej zmiany `tax_point_date`, np. przedpłata (art. 19a ust. 8 [ustawy o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)); Q-21 |
| `paid_on` | date null | zapłata dostawcy, czyli dzień zlecenia przelewu; podstawa terminów `wht_payment` ([tax-rules.md](tax-rules.md)) i `wl_notice` ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)) |
| `reviewed_at`, `reviewed_by` | timestamptz, uuid null | Twoja ocena |

Znacznik JPK rekordu (NrKSeF, OFF, BFK, DI) wylicza mapper z tych pól ([modules/jpk.md](modules/jpk.md)).

**Korekty**

| Pole | Typ | Opis |
| --- | --- | --- |
| `corrects_invoice_id` | uuid FK null | faktura korygowana, jeśli jest w bazie |
| `corrected_invoice_number`, `corrected_issue_date`, `corrected_ksef_number` | text, date, text null | DaneFaKorygowanej; pusty numer KSeF oryginału oznacza NrKSeFN |
| `correction_reason` | text null | PrzyczynaKorekty; pole opcjonalne w FA(3) |
| `correction_type` | smallint null | TypKorekty 1, 2 albo 3; pole opcjonalne, wpływa na okres w ewidencji VAT ([modules/ksef.md](modules/ksef.md)) |
| `replaces_invoice_id` | uuid FK null | nowa faktura po korekcie do zera, np. przy błędnym NIP nabywcy |

Jedna KOR koryguje jedną fakturę. Korekta zbiorcza jest poza zakresem.

**Klucze i ograniczenia**

- `UNIQUE (environment, ksef_number) WHERE ksef_number IS NOT NULL`.
- `UNIQUE (environment, kind, invoice_number) WHERE direction = 'sale' AND status NOT IN ('cancelled_before_send', 'rejected')`. Odpowiada kluczowi duplikatu KSeF: NIP sprzedawcy, RodzajFaktury i P_2, unikalnemu przez 10 lat ([KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/weryfikacja-faktury.md)).
- `UNIQUE (environment, freshbooks_id) WHERE freshbooks_id IS NOT NULL`.
- `CHECK`: `sale` ma `status` i pusty `cost_status`; `cost` ma `cost_status` i pusty `status`. `vat_exempt`, `deductible` i `is_fixed_asset` są niepuste przy `cost` i puste przy `sale`. `tax_point_override_reason` jest pusty przy `sale`.
- Indeksy: `(environment, direction, vat_period_start)`, `(counterparty_id, invoice_number)` do deduplikacji kosztów.
- Koszt ze źródłem innym niż `ksef` nie powstaje bez `stored_file` rodzaju `cost_document`: zapis w stanie `imported` wymaga pliku. Strażnik przejścia do `reviewed` zostaje jako druga bariera.
- Sprzedaż w stanach `accepted`, `corrected` i `superseded` jest niezmienna poza kolumnami z tabeli niżej. Listener Doctrine i trigger odrzucają każdą inną zmianę; treść zmienia tylko KOR.

| Kolumny zapisywalne po przyjęciu | Dozwolona zmiana |
| --- | --- |
| `status`, `version`, `updated_at` | przejścia z sekcji „Faktura sprzedaży”; optimistic lock |
| `revenue_date`, `fx_pit_rate_id` | raz, z pustej na wartość; F2 ([tax-rules.md](tax-rules.md)) |
| `freshbooks_id`, `source_hash` | raz, z pustej na wartość, przy powiązaniu z FreshBooks ([modules/freshbooks.md](modules/freshbooks.md)) |
| `freshbooks_status` | każda zmiana; zapis pollingu FreshBooks ([modules/freshbooks.md](modules/freshbooks.md)) |
| `written_back_at` | raz, z pustej na wartość, po zapisie zwrotnym |
| `panel_task`, `panel_task_at` | każda zmiana; zadanie sprzedaży w panelu (D-53) |
| `invoice_line.service_type`, `pkwiu`, `ryczalt_rate_pct` | raz, z pustej na wartość; F2 |

- Chronione są m.in. `kind`, `invoice_number`, `issue_date`, daty usługi, `currency`, `counterparty_id`, `buyer_snapshot`, `ksef_number`, `ksef_issue_mode`, `tax_scenario`, kwoty i kursy VAT (`fx_vat_rate_id`, `net_pln`, `vat_pln`).
- W `invoice_line` chronione są pozostałe pola, w tym P_7–P_12. Pozycji nie dodaje się ani nie usuwa.
- Przed wysyłką pozycje szkicu sprzedaży zmieniają się razem z FreshBooks; usunięcie pozycji poza szkicem odrzuca trigger `invoice_line_delete_draft_only` (D-52).

Pliki faktury są w `stored_file` z właścicielem `invoice`: `xml_sent`, `upo`, `pdf`, `cost_document`. Przyjęty XML to payload przyjętego `approval`. Sprzedaż z Subject1 nie ma `approval`; jej `xml_sent` to bajty pobrane z eksportu KSeF.

Sprzedaż sprzed przejścia wystawiona poza KSeF nie trafia do `invoice`. Jest tylko w `revenue_entry` ze źródłem `freshbooks` albo `import`.

#### `invoice_line`

| Pole | Typ | Opis |
| --- | --- | --- |
| `invoice_id` | uuid FK | faktura |
| `line_no` | int | NrWierszaFa |
| `state_before` | boolean | StanPrzed w KOR: wiersz sprzed korekty |
| `description` | text | P_7 |
| `service_type` | text null | klucz mapowania usług z `setting` `ryczalt.service_map` |
| `pkwiu` | text null | PKWiU 2015 |
| `quantity` | NUMERIC(18,6) | P_8B |
| `unit` | text | P_8A |
| `unit_net_price` | NUMERIC(22,8) | P_9A |
| `net_amount` | NUMERIC(18,2) | P_11 |
| `vat_code` | text | P_12 z FA(3), np. `23`, `zw`, `oo`, `np I`, `np II` |
| `vat_amount` | NUMERIC(18,2) null | VAT pozycji, jeśli dokument go podaje |
| `ryczalt_rate_pct` | NUMERIC(5,2) null | stawka ryczałtu pozycji sprzedaży |
| `gtu` | text null | kod GTU (Q-31) |

`UNIQUE (invoice_id, line_no, state_before)`. Pozycja sprzedaży bez stawki ryczałtu blokuje zatwierdzenie ([tax-rules.md](tax-rules.md)).

#### `ksef_session`

| Pole | Typ | Opis |
| --- | --- | --- |
| `reference_number` | text | numer referencyjny sesji |
| `form_code` | text | kod formularza sesji, np. FA (3) |
| `opened_at` | timestamptz | otwarcie |
| `expires_at` | timestamptz | koniec życia sesji: 12 h od utworzenia ([KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json)) |
| `closed_at` | timestamptz null | zamknięcie |
| `status_code` | int null | ostatni kod statusu sesji z API |

`UNIQUE (environment, reference_number)`. UPO sesji leży w `stored_file` z właścicielem `ksef_session`.

### Kontrahenci

#### `counterparty`

| Pole | Typ | Opis |
| --- | --- | --- |
| `name` | text | nazwa |
| `country` | char(2) | kraj siedziby |
| `nip` | text null | NIP polskiego podmiotu |
| `eu_vat_prefix`, `eu_vat_number` | text null | KodUE i NrVatUE bez prefiksu |
| `foreign_tax_id` | text null | NrID spoza UE |
| `krs`, `regon` | text null | numery z rejestrów polskich, z białej listy albo wpisane ręcznie; konektory KRS i GUS odłożone (D-20, D-29) |
| `address` | jsonb | ulica, numer, miejscowość, kod, kraj |
| `freshbooks_client_id` | text null | klient FreshBooks |
| `is_client`, `is_supplier` | boolean | rola |
| `is_natural_person` | boolean | osoba fizyczna; przy dostawcy zagranicznym WHT z art. 29 [ustawy o PIT](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf), nie z ustawy o CIT; do weryfikacji (Q-21) |
| `bank_accounts` | jsonb | lista rachunków: numer, źródło, pierwsze użycie |
| `wht_category` | text null | klasyfikacja płatności dla WHT ([tax-rules.md](tax-rules.md)) |
| `wht_treaty_country` | char(2) null | kraj umowy o unikaniu podwójnego opodatkowania |
| `wht_cert_valid_until` | date null | ważność certyfikatu rezydencji; plik w `stored_file` rodzaju `evidence` |
| `wht_decision_source` | text null | skąd klasyfikacja, np. przegląd doradcy |

- `UNIQUE (environment, nip)`, `UNIQUE (environment, eu_vat_prefix, eu_vat_number)`, `UNIQUE (environment, freshbooks_client_id)`, każdy z `WHERE … IS NOT NULL`.
- Dane własne sprzedawcy (Podmiot1) są w `setting` `company.identity`, nie w tej tabeli.
- Dane kontrahenta są zmienne. Faktura zachowuje stan z dnia wystawienia w `buyer_snapshot`.

#### `verification`

| Pole | Typ | Opis |
| --- | --- | --- |
| `counterparty_id` | uuid FK null | kontrahent; pusty przy samosprawdzeniu (`subject` z `company.identity`) |
| `source` | text null | slug konektora: `vies`, `white_list`; pusty przy ręcznym dowodzie |
| `subject` | text | sprawdzany identyfikator: NIP albo numer VAT UE |
| `bank_account` | text null | biała lista: sprawdzany rachunek |
| `checked_for_date` | date | dzień, na który pytano |
| `checked_at` | timestamptz | chwila sprawdzenia |
| `result` | text | `valid`, `invalid`, `unverified_retry`, `manual_evidence` |
| `request_identifier` | text null | VIES `requestIdentifier`; biała lista `requestId` |
| `source_datetime` | timestamptz null | `requestDate` albo `requestDateTime` ze źródła |
| `connector_call_id` | bigint null | wywołanie, które dało wynik; bez klucza obcego, bo `connector_call` ma retencję |
| `checked_by` | uuid null | Ty, przy ręcznym dowodzie |

- Wiersz jest niezmiennym snapshotem. Ponowne sprawdzenie dodaje nowy wiersz.
- `CHECK (counterparty_id IS NOT NULL OR source IN ('vies', 'white_list'))`. Aplikacja zapisuje pusty `counterparty_id` tylko, gdy `subject` to Twój NIP albo numer VAT UE z `company.identity` ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)).
- VIES zwraca `requestIdentifier` tylko, gdy żądanie zawiera dane pytającego ([VIES](https://ec.europa.eu/assets/taxud/vow-information/swagger_publicVAT.yaml)). Biała lista daje `requestId` i `requestDateTime` jako dowód ([API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat)).
- Surowa odpowiedź to `stored_file` rodzaju `verification_raw`; ręczny dowód to rodzaj `evidence`.
- Indeksy `(counterparty_id, source, checked_at DESC)` oraz dla samosprawdzenia `(subject, source, checked_at DESC) WHERE counterparty_id IS NULL`. Świeżość i polityka awarii: [modules/rejestry-kursy.md](modules/rejestry-kursy.md).

### Kursy i płatności

#### `fx_rate`

| Pole | Typ | Opis |
| --- | --- | --- |
| `source` | text | `nbp`; `ecb` tylko przy wyborze kursu EBC ([tax-rules.md](tax-rules.md)) |
| `table_no` | text | numer tabeli (NBP: tabela A), dowód kursu |
| `currency` | char(3) | waluta |
| `effective_date` | date | dzień publikacji tabeli |
| `mid` | NUMERIC(18,6) | kurs średni |
| `fetched_at` | timestamptz | pobranie |
| `connector_call_id` | bigint null | wywołanie źródła; bez klucza obcego, bo `connector_call` ma retencję |

- `UNIQUE (source, currency, effective_date)`. Wiersz jest niezmienny.
- W dni bez tabeli nie ma wiersza; NBP nie publikuje tabeli w weekendy i święta ([API NBP](https://api.nbp.pl/en.html)).
- Który dzień tabeli wybrać, mówi [tax-rules.md](tax-rules.md). Jak pobrać, mówi [modules/rejestry-kursy.md](modules/rejestry-kursy.md).

#### `payment`

Płatności klientów z FreshBooks (Q-24). Opłaty bramki płatniczej to osobne faktury kosztowe.

| Pole | Typ | Opis |
| --- | --- | --- |
| `invoice_id` | uuid FK null | faktura sprzedaży; pusty, dopóki płatność nie jest przypisana |
| `revenue_entry_id` | uuid FK null | wiersz ewidencji sprzedaży, która nie ma wiersza `invoice` (sprzed przejścia, poza KSeF) |
| `freshbooks_payment_id` | text | id płatności FreshBooks |
| `freshbooks_invoice_id` | text | surowe `invoiceid` z FreshBooks; ponowne dopasowanie do `invoice.freshbooks_id` bez wywołania API ([modules/freshbooks.md](modules/freshbooks.md)) |
| `paid_on` | date | dzień zapłaty |
| `amount`, `currency` | NUMERIC(18,2), char(3) | kwota zapłaty |
| `gateway` | text | wartość z FreshBooks, np. Stripe |
| `fee_amount`, `fee_currency` | NUMERIC(18,2), char(3) null | opłata bramki |
| `payout_amount`, `payout_currency`, `payout_on` | NUMERIC(18,2), char(3), date null | wypłata na rachunek |
| `fx_rate_id` | uuid FK null | kurs dla różnicy kursowej według [tax-rules.md](tax-rules.md) |
| `actual_rate` | NUMERIC(18,6) null | kurs faktycznie zastosowany, jeśli był |
| `matched_at` | timestamptz null | przypisanie do faktury |
| `fx_posted_at` | timestamptz null | zapis różnicy kursowej w `revenue_entry` |

- `UNIQUE (environment, freshbooks_payment_id)`.
- `CHECK (invoice_id IS NULL OR revenue_entry_id IS NULL)`: płatność wskazuje fakturę albo wiersz ewidencji, nigdy oba.
- `CHECK`: `matched_at` jest ustawione dokładnie przy `invoice_id` albo `revenue_entry_id`; `fx_posted_at` tylko po przypisaniu; opłata i wypłata mają kwotę razem z walutą.
- Indeks unikalny `revenue_entry (payment_id, remarks, rate_pct)` dla `fx_difference`: jeden wiersz na płatność i wiersz źródłowy (D-45).
- Przy `revenue_entry_id` różnica kursowa bierze kurs PIT z `fx_rate_id` tego wiersza.

### Ewidencja i podatki

#### `revenue_entry`

Ewidencja przychodów, obowiązkowa w programie od 1.01.2026 (art. 15 ust. 12 [ustawy o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)). Wpis do 20. dnia następnego miesiąca ([rozporządzenie, Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf)). Mapowanie pól na JPK_EWP(4) według [broszury MF](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf); szczegóły w [modules/ewidencja.md](modules/ewidencja.md).

| Pole | Typ | Opis |
| --- | --- | --- |
| `year` | smallint | rok ewidencji |
| `lp` | int | liczba porządkowa w roku, bez luk |
| `entry_date` | date | K_2, data wpisu |
| `revenue_date` | date | K_3, data uzyskania przychodu |
| `document_number` | text | K_4, numer dowodu |
| `ksef_number` | text null | K_5 |
| `counterparty_country` | char(2) null | K_6 |
| `counterparty_tax_id` | text null | K_7 |
| `amount_pln` | NUMERIC(18,2) | K_8; ujemna przy korekcie |
| `rate_pct` | NUMERIC(5,2) | K_9, stawka ryczałtu wiersza |
| `remarks` | text null | K_10 |
| `source` | text | `freshbooks`, `ksef_subject1`, `import`, `manual` |
| `kind` | text | `regular`, `correction`, `fx_difference` |
| `invoice_id` | uuid FK null | faktura źródłowa |
| `payment_id` | uuid FK null | płatność; wymagana przy `fx_difference` |
| `corrects_entry_id` | uuid FK null | korygowany wiersz |
| `original_amount`, `original_currency` | NUMERIC(18,2), char(3) null | kwota przed przeliczeniem |
| `fx_rate_id` | uuid FK null | kurs PIT; numer i dzień tabeli to dowód |
| `recorded_at` | timestamptz | chwila zapisu w kokpicie |
| `recorded_by` | uuid null | Ty przy wpisie ręcznym; pusty przy imporcie |

- `UNIQUE (environment, year, lp)`. Numer `lp` nadawany pod blokadą na rok.
- `UNIQUE (invoice_id, rate_pct) WHERE kind = 'regular'`: jeden wiersz na fakturę i stawkę.
- `CHECK`: `fx_difference` ma `payment_id`; `correction` ma `corrects_entry_id` albo `invoice_id` faktury KOR; `manual` ma `recorded_by`.
- Wiersz `manual` wymaga dowodu: `stored_file` rodzaju `evidence` z właścicielem `revenue_entry`, zapisanego w tej samej transakcji.
- Wiersz jest niezmienny od zapisu. Błąd poprawia nowy wiersz `correction`.
- Import zachowuje oryginalne K_2 ze źródła. Rekonstrukcja wpisuje faktyczną datę wpisu; późne K_2 są jawne (D-23).
- Wiersz `import` z F2.1 ma K_8 i K_9 policzone w narzędziu źródłowym, z pustym `fx_rate_id`; kokpit ich nie przelicza (D-30).
- Flaga `Kasowy_PIT` należy do węzła `Podmiot1` pliku JPK_EWP(4) ([broszura MF](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf)) i pochodzi z `setting` `pit.kasowy`.

#### `fixed_asset`

| Pole | Typ | Opis |
| --- | --- | --- |
| `invoice_id` | uuid FK null | faktura zakupu |
| `ksef_number` | text null | numer KSeF zakupu |
| `name` | text | nazwa |
| `kst_code` | text null | F_8, symbol KŚT, opcjonalny |
| `intangible` | boolean | WNiP |
| `acquisition_date` | date | nabycie |
| `commissioning_date` | date | przyjęcie do używania |
| `acquisition_document_number` | text null | F_5, numer dokumentu przyjęcia |
| `acquisition_document_type` | text | F_6, rodzaj dokumentu nabycia: `S`, `D`, `N`, `W`, `F`, `I` |
| `depreciation_rate_pct` | NUMERIC(5,2) | F_9, stawka amortyzacyjna podatkowa |
| `initial_value_pln` | NUMERIC(18,2) | wartość początkowa |
| `updated_value_pln` | NUMERIC(18,2) null | F_11, zaktualizowana wartość początkowa |
| `disposal_date`, `disposal_reason` | date, text null | likwidacja albo sprzedaż; `disposal_reason` to kod F_13: `S`, `X`, `K`, `D`, `N`, `I` |
| `disposal_document_number` | text null | F_14, numer dokumentu wykreślenia |
| `disposal_ksef_numbers` | jsonb null | F_15, numery KSeF dokumentów zbycia |
| `reclassified` | boolean | F_16, reklasyfikacja |

- `CHECK`: `acquisition_document_type` w kodach F_6; `disposal_reason` pusty albo w kodach F_13.
- Znaczenie pól F_1–F_16 i mapowanie na JPK_ST(1): [modules/jpk.md](modules/jpk.md).
- JPK_ST(1) działa za flagą `setting` `jpk_st.enabled`, zależną od projektu zwolnienia (Q-25, [modules/jpk.md](modules/jpk.md)).

#### `zus_payment`

Płacisz ręcznie, więc zapłacone składki wpisujesz w panelu. Służą do odliczeń od zaliczki ([tax-rules.md](tax-rules.md)).

| Pole | Typ | Opis |
| --- | --- | --- |
| `month` | date | miesiąc składkowy, pierwszy dzień |
| `social_pln` | NUMERIC(18,2) | składki społeczne podlegające odliczeniu |
| `health_pln` | NUMERIC(18,2) | składka zdrowotna |
| `other_pln` | NUMERIC(18,2) | części bez odliczenia; podział w [tax-rules.md](tax-rules.md) |
| `paid_on` | date | dzień zapłaty |
| `obligation_id` | uuid FK null | obowiązek `zus` |
| `entered_by` | uuid | Ty |

`UNIQUE (environment, month)`. Wiersz zmieniasz, dopóki miesiąc nie jest zamknięty.

#### `period`

| Pole | Typ | Opis |
| --- | --- | --- |
| `period_start` | date | pierwszy dzień miesiąca |
| `status` | text | `open`, `closed`, `reopened` |
| `ledger_closed_at` | timestamptz null | ewidencja miesiąca kompletna |
| `checklist` | jsonb null | wynik bramek kompletności przy budowie JPK |
| `closed_at` | timestamptz null | zamknięcie |
| `closed_by_filing_id` | uuid FK null | przyjęty JPK_V7M, który zamknął okres |
| `reopened_at`, `reopen_reason` | timestamptz, text null | ponowne otwarcie |
| `correction_required_at` | timestamptz null | zapis trafił do zamkniętego okresu; panel pokazuje zadanie „korekta wymagana” |

`UNIQUE (environment, period_start)`. Sekwencja zamknięcia i blokada: [compliance-calendar.md](compliance-calendar.md).

Bilans otwarcia nie ma tabeli (D-29). Wartości są w kluczach `setting` `opening.*`, a dowody w `stored_file` rodzaju `evidence` z właścicielem `setting` (sekcja `setting`).

### Urząd

#### `filing`

| Pole | Typ | Opis |
| --- | --- | --- |
| `type` | text | `JPK_V7M`, `JPK_EWP`, `JPK_ST`, `VAT_UE`, `VAT_UEK`, `PIT_28` |
| `period_start`, `period_end` | date | miesiąc albo rok |
| `purpose` | smallint | cel złożenia: 1 złożenie, 2 korekta (`CelZlozenia`, [broszura JPK_V7M](https://www.podatki.gov.pl/media/wgbkrejs/broszura-jpk_vat-z-deklaracj%C4%85-od-1-lutego-2026-r.pdf)) |
| `seq` | smallint | 0 dla pierwszego złożenia, potem kolejne korekty |
| `status` | text | stan złożenia |
| `supersedes_id` | uuid FK null | złożenie zastępowane korektą; `VAT_UEK` wskazuje `VAT_UE` |
| `schema_version` | text | wersja struktury, np. JPK_V7M(3) |
| `totals` | jsonb | sumy pokazane w bramce: sprzedaż netto, VAT należny i naliczony, liczba dokumentów |
| `reference_number` | text null | numer referencyjny bramki |
| `status_code` | int null | ostatni kod statusu bramki |
| `submitted_via` | text | `jpk`, `edeklaracje` albo `manual` (zapas: narzędzie MF) |
| `accepted_at` | timestamptz null | przyjęcie z UPO |
| `version` | int | optimistic lock |

- `UNIQUE (environment, type, period_start, purpose, seq)`.
- Pliki: `xml_sent` i `upo` w `stored_file` z właścicielem `filing`. Wysłany XML to payload przyjętego `approval`.
- Złożenie `manual` nie ma `approval`. Wymaga `upo`, a `xml_sent` jest opcjonalny.
- `xml_sent` to dokument przed kompresją i szyfrowaniem; jego SHA-256 trafia do metadanych ([specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf)). Zaszyfrowanych części kokpit nie przechowuje.
- `PIT_28`: złożenie za rok przez bramkę i konektor `edeklaracje` (D-96, [modules/jpk.md](modules/jpk.md)) albo `manual` z wgranym UPO; korekta tylko `manual`.

#### `obligation`

| Pole | Typ | Opis |
| --- | --- | --- |
| `type` | text | wartość z `obligation.type` w [naming.md](naming.md), m.in. `wl_notice` i wpisywany ręcznie `edelivery_reply_due` |
| `rule_key` | text | reguła `ObligationEngine` albo formularz wpisu ręcznego, który utworzył wiersz ([compliance-calendar.md](compliance-calendar.md)) |
| `period_start` | date null | okres, którego dotyczy |
| `nominal_due_date` | date | termin z przepisu |
| `due_date` | date | termin po przesunięciu |
| `amount_due` | NUMERIC(18,2) null | kwota z `setting` albo wyliczona |
| `status` | text | `open`, `done`, `not_applicable` |
| `done_at`, `done_by` | timestamptz, uuid null | zamknięcie; pusty `done_by` oznacza system |
| `amount_paid`, `paid_on` | NUMERIC(18,2), date null | zapłata ręczna, wpisujesz Ty |
| `payment_title` | text null | tytuł przelewu z szablonu ([compliance-calendar.md](compliance-calendar.md)) |
| `filing_id` | uuid FK null | złożenie, które zamyka obowiązek |
| `credential_state_id` | uuid FK null | wygasające poświadczenie |
| `invoice_id` | uuid FK null | szkic sprzedaży z terminem wystawienia; faktura kosztowa przy `wl_notice` |
| `counterparty_id` | uuid FK null | dostawca przy WHT, IFT-2R i `wl_notice` |
| `note` | text null | aktywna od F0b: opis obowiązku wpisanego ręcznie, np. nadawca i sygnatura pisma przy `edelivery_reply_due`; bez treści pisma |
| `dedupe_key` | text | typ, okres i podmiot obowiązku; wzorce w [compliance-calendar.md](compliance-calendar.md) |

- `UNIQUE (environment, dedupe_key)`: silnik może przeliczać bez duplikatów.
- Źródło prawne terminu pochodzi z reguły po `rule_key` (rejestr reguł `ObligationEngine`); źródło kwoty z `setting.legal_source`. Bez osobnej kolumny.
- Zaległość nie jest zapisywana. Liczy się ją jako `status = 'open' AND due_date < dzisiaj` w Europe/Warsaw.
- `wl_notice`: dzień zlecenia przelewu to `invoice.paid_on` faktury z `invoice_id`; wynik białej listy na ten dzień jest w `verification.checked_for_date` ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)).
- `edelivery_reply_due` do powrotu F4 wpisujesz ręcznie po odbiorze pisma w aplikacji e-Doręczeń: termin w `nominal_due_date`, opis w `note`, bez encji źródłowej (D-29).

#### `approval`

Kolumny bramki zatwierdzeń. Semantyka, warunki wstępne, step-up i rekoncyliacja są w [approval-gate.md](approval-gate.md).

| Pole | Typ | Opis |
| --- | --- | --- |
| `subject_type` | text | `sales_invoice`, `correction_invoice`, `filing`, `draft` |
| `subject_id` | uuid | encja zatwierdzana |
| `connector` | text null | slug konektora wysyłki: `ksef`, `jpk`, `edeklaracje`, `dummy`; pusty przy szkicu tylko do kopiowania (Q-35) |
| `payload_file_id` | uuid FK | `stored_file` z dokładnymi bajtami do wysłania |
| `payload_sha256` | char(64) | hash payloadu; worker liczy go ponownie przed wysyłką |
| `source_version` | int | `version` encji w chwili prośby |
| `summary` | jsonb | sumy i pola pokazane przy zatwierdzeniu |
| `preconditions` | jsonb | wynik warunków wstępnych przy prośbie |
| `requested_at`, `expires_at` | timestamptz | prośba i wygaśnięcie |
| `decided_at`, `decided_by` | timestamptz, uuid null | decyzja i `app_user` |
| `step_up_credential_id` | uuid FK null | `webauthn_credential` użyty przy decyzji |
| `status` | text | stan zatwierdzenia |
| `dispatching_at`, `submitted_at`, `finished_at` | timestamptz null | kroki wysyłki |
| `external_refs` | jsonb | numery referencyjne zapisywane po każdym kroku API |
| `result_code` | text null | kod wyniku systemu |
| `superseded_by_id` | uuid FK null | nowsze zatwierdzenie tej samej encji; przy fakturze `superseded` bywa bez następcy po zdjęciu znacznika gotowości (CHECK `approval_superseded_successor`, D-52) |
| `cancel_reason` | text null | `user`, `hash_mismatch`, `manual_filing`, `manual_invoice` |

- `UNIQUE (subject_type, subject_id) WHERE status IN ('pending', 'approved', 'dispatching', 'submitted', 'unknown')`: jedno żywe zatwierdzenie na encję.
- `CHECK (connector IS NOT NULL OR subject_type = 'draft')`: tylko szkic może nie mieć konektora.
- `CHECK (connector IN ('ksef', 'jpk', 'edeklaracje', 'dummy'))`. Nowy konektor zapisu to osobna migracja; `edoreczenia` wraca z F4 (sekcja „Odłożone”).
- `dummy` spełnia `CHECK`, żeby testy bramki działały przed F3. Przy `APP_ENV=prod` nie jest włączony; pilnuje tego konfiguracja i `HostAllowlist`, nie baza (D-32, [naming.md](naming.md)).
- Pola payloadu (`payload_file_id`, `payload_sha256`, `source_version`, `summary`) są niezmienne od zapisu.

#### `draft`

| Pole | Typ | Opis |
| --- | --- | --- |
| `chat_session_id` | uuid FK null | rozmowa, która utworzyła szkic; `ON DELETE SET NULL`, bo retencja usuwa rozmowy ([security.md](security.md)) |
| `model` | text null | model czatu, który napisał szkic; kopia `chat_message.model` wywołania `draft_document`; pusty przy szkicu z panelu |
| `kind` | text | `email` |
| `recipient_counterparty_id` | uuid FK | odbiorca z bazy |
| `subject`, `body` | text | treść |
| `provenance` | jsonb | źródła nieufne użyte w szkicu: pisma, pliki, wiadomości |
| `flagged_values` | jsonb | IBAN, NRB, kwota albo NIP niezgodne z bazą |
| `status` | text | `pending`, `approved`, `discarded`, `executed` |
| `executed_at` | timestamptz null | wysłanie albo skopiowanie (Q-35) |
| `version` | int | optimistic lock |

Odbiorcę wybiera się tylko z rekordów bazy ([modules/czat.md](modules/czat.md)). Szkic odpowiedzi na pismo e-Doręczeń wraca z F4 (sekcja „Odłożone”).

### Pliki

#### `stored_file`

| Pole | Typ | Opis |
| --- | --- | --- |
| `kind` | text | `xml_sent`, `upo`, `pdf`, `evidence`, `attachment`, `verification_raw`, `cost_document`, `outbound_payload` |
| `sha256` | char(64) | hash bajtów |
| `path` | text | ścieżka względem `var/data` |
| `size_bytes` | bigint | rozmiar |
| `media_type` | text | typ MIME |
| `original_name` | text null | nazwa ze źródła |
| `owner_table`, `owner_id` | text, uuid | właściciel pliku |
| `last_verified_at` | timestamptz null | ostatnie zgodne przeliczenie hasha |
| `verify_failed_at` | timestamptz null | niezgodny hash; alert |
| `retention_until` | date null | koniec retencji według [security.md](security.md) |
| `legal_hold` | boolean | blokada usunięcia |
| `deleted_at` | timestamptz null | usunięcie pliku (retencja albo RODO); wiersz z hashem zostaje |

- `UNIQUE (owner_table, owner_id, kind, sha256)`; indeks `(sha256)`.
- Właściciela wskazują `owner_table` i `owner_id`. Dokładny wskaźnik ma tylko `approval.payload_file_id`.
- Układ katalogów i zasady zapisu: sekcja „Magazyn plików”.

| Rodzaj | Zawartość | Właściciel |
| --- | --- | --- |
| `xml_sent` | dokładne bajty FA(3), JPK i VAT-UE do wysłania i wysłane; także XML faktury Subject1 pobrany z paczki eksportu KSeF (bajty z KSeF) | `invoice`, `filing` |
| `upo` | UPO KSeF, bramki JPK i e-Deklaracji | `invoice`, `ksef_session`, `filing` |
| `pdf` | wizualizacja faktury z KOD I | `invoice` |
| `evidence` | ręczne dowody weryfikacji, certyfikaty rezydencji, dokumenty bilansu otwarcia (klucze `opening.*`), dowody wpisu ręcznego w ewidencji | `verification`, `counterparty`, `setting`, `revenue_entry` |
| `attachment` | treść i załączniki pism; odłożone z F4 (D-29), bo jedyny właściciel to `edelivery_message` | – |
| `verification_raw` | surowa odpowiedź rejestru | `verification` |
| `cost_document` | dokument kosztowy: XML z KSeF, PDF z maila, skan | `invoice` |
| `outbound_payload` | kanoniczny JSON szkicu (odbiorca, temat, treść) wiązany hashem zatwierdzenia | `draft` |

### Operacje

#### `connector_call`

Każde wywołanie zewnętrzne, także odczyty i polling. Akcje prawne idą do `audit_log` ([security.md](security.md)).

| Pole | Typ | Opis |
| --- | --- | --- |
| `id` | bigint identity | kolejność |
| `connector` | text | slug z kontraktu nazw |
| `operation` | text | metoda konektora, np. otwarcie sesji |
| `host` | text | cel |
| `http_method`, `path` | text null | metoda i ścieżka bez parametrów zapytania; puste przy `mail` (SMTP) |
| `started_at` | timestamptz | start |
| `duration_ms` | int | czas trwania |
| `http_status` | int null | pusty przy timeoucie albo błędzie sieci |
| `error_code` | text null | kod błędu systemu (wyliczenie konektora) |
| `retry_after_s` | int null | z nagłówka `Retry-After` |
| `request_sha256`, `request_bytes` | char(64), int null | hash i rozmiar żądania |
| `response_sha256`, `response_bytes` | char(64), int null | hash i rozmiar odpowiedzi |
| `approval_id` | uuid null | zatwierdzenie przy zapisie w urzędzie |
| `correlation_id` | uuid | łączy kroki jednego przepływu |

- Wiersz zapisywany raz, po zakończeniu wywołania; bez `UPDATE`.
- Treści żądań i odpowiedzi KSeF, e-Doręczeń i JPK nie trafiają do bazy ani logów; zostają hash i rozmiar.
- Usuwa je tylko zadanie retencji ([security.md](security.md)).

#### `audit_log`

| Pole | Typ | Opis |
| --- | --- | --- |
| `id` | bigint identity | kolejność |
| `occurred_at` | timestamptz | chwila |
| `actor_user_id` | uuid null | `app_user`; pusty oznacza system |
| `action` | text | `obszar.czynność`; lista akcji w [security.md](security.md) |
| `target_type`, `target_id` | text, uuid null | tabela i wiersz |
| `environment`, `connector` | text null | cel akcji |
| `approval_id` | uuid null | zatwierdzenie |
| `payload_sha256` | char(64) null | hash wysłanego payloadu |
| `details` | jsonb | szczegóły po `Redactor` |
| `prev_hash`, `hash` | char(64) | łańcuch: sha256 z `prev_hash` i kanonicznego JSON wiersza |

- Tylko dopisywanie. Rola aplikacji nie ma `UPDATE` ani `DELETE`, a trigger odrzuca obie operacje.
- Wpis powstaje pod blokadą doradczą PostgreSQL, więc łańcuch jest liniowy.

#### `rate_counter`

| Pole | Typ | Opis |
| --- | --- | --- |
| `connector` | text | slug konektora |
| `bucket` | text | grupa limitu, np. `white_list.search`, `claude.messages`; wartości w [naming.md](naming.md) |
| `window_start` | timestamptz | początek okna |
| `count` | int | liczba wywołań w oknie |
| `blocked_until` | timestamptz null | blokada z `Retry-After` |

- Klucz główny `(environment, connector, bucket, window_start)`.
- Zwiększanie atomowym `INSERT … ON CONFLICT … DO UPDATE SET count = count + 1 RETURNING count`.
- Jedyny mechanizm limitów: licznik w oknie stałym, `blocked_until` i `Retry-After`. Okna przesuwne dopiero po pierwszych odpowiedziach 429 (D-29).
- Zadanie w tle po osiągnięciu limitu jest odkładane, nie kończy się błędem. Żądanie synchroniczne panelu (czat, „Doręcz klientowi”) dostaje odmowę. Semantyka: [architecture.md](architecture.md).
- Okno dobowe zaczyna się o północy w Europe/Warsaw.
- Biała lista ma dwa liczniki: `white_list.search` i `white_list.check`; limity w [modules/rejestry-kursy.md](modules/rejestry-kursy.md). Grupy KSeF: [modules/ksef.md](modules/ksef.md).

#### `sync_cursor`

| Pole | Typ | Opis |
| --- | --- | --- |
| `connector` | text | slug konektora |
| `stream` | text | KSeF: `subject1`, `subject2`; FreshBooks: `invoices`, `payments`, `credit_notes` (D-53) |
| `last_hwm` | timestamptz null | KSeF: `PermanentStorageHwmDate` ([przyrostowe pobieranie](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/pobieranie-faktur/przyrostowe-pobieranie-faktur.md)) |
| `last_value` | text null | inny kursor, np. `updated_min` FreshBooks |
| `backfill_from` | date null | początek jednorazowego backfillu |
| `backfill_done_at` | timestamptz null | koniec backfillu |
| `last_run_at`, `last_success_at` | timestamptz null | ostatni przebieg i ostatni sukces |

- `UNIQUE (environment, connector, stream)`.
- Kursor przesuwa się w tej samej transakcji co zapisane wiersze. Ponowny przebieg jest więc idempotentny.
- Algorytm synchronizacji KSeF: [modules/ksef.md](modules/ksef.md).

#### `credential_state`

Drugi poziom sejfu: tokeny rotujące w runtime oraz daty ważności wszystkich poświadczeń. Inwentarz i rotacje: [security.md](security.md).

| Pole | Typ | Opis |
| --- | --- | --- |
| `kind` | text | rodzaj poświadczenia |
| `secret_name` | text null | nazwa sekretu statycznego w Symfony Secrets |
| `ciphertext`, `nonce` | bytea null | token rotujący zaszyfrowany sodium secretbox |
| `key_id` | text null | klucz szyfrujący z sejfu |
| `valid_from`, `valid_until` | timestamptz null | ważność |
| `refresh_valid_until` | timestamptz null | ważność tokena odświeżania |
| `status` | text | `valid`, `expiring`, `expired`, `revoked` |
| `rotated_at` | timestamptz null | ostatnia rotacja |
| `last_smoke_ok_at` | timestamptz null | udane wywołanie testowe po rotacji |
| `revoked_at` | timestamptz null | unieważnienie |

- `UNIQUE (environment, kind)`. Odświeżanie pod Symfony Lock na parę `(environment, kind)`.
- Rodzaje statyczne, tylko z datą ważności: `ksef_token_read`, `ksef_token_write`, `ksef_auth_cert`, `ksef_offline_cert`, `auth_data`.
- Rodzaje rotujące, z wartością: `ksef_jwt_read`, `ksef_jwt_write`, `freshbooks_oauth`. Każdy token KSeF daje własną parę JWT, stąd dwa rodzaje.
- Rodzaj testowy `dummy_token`: tylko z konektorem `dummy`, nigdy przy `APP_ENV=prod` (D-32).
- Rodzaje e-Doręczeń są odłożone (sekcja „Odłożone”); `ceidg_jwt` nie powstaje (D-20, D-29).
- Wartość sekretu statycznego nigdy nie trafia do tej tabeli.
- Powód tabeli: refresh token FreshBooks jest jednorazowy ([FreshBooks](https://www.freshbooks.com/api/authentication)), a refresh token KSeF żyje do 7 dni ([KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/uwierzytelnianie.md)). Certyfikaty KSeF typ 1 i typ 2 są ważne najwyżej 2 lata ([MF](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/)).

#### `notification`

| Pole | Typ | Opis |
| --- | --- | --- |
| `channel` | text | `push` albo `mail` |
| `category` | text | `obligation.type` przy przypomnieniu; zdarzenia: `approval_pending`, `connector_failure`, `budget_alert`, `freshbooks_bypass`, `freshbooks_number_changed` (D-53), `p2_collision` (D-54), `legal_change` (D-75), `weekly_digest` (D-82) |
| `obligation_id`, `approval_id` | uuid FK null | przyczyna |
| `dedupe_key` | text | przyczyna, przesunięcie przypomnienia i kanał |
| `body` | text | kategoria, liczba i link; bez nadawcy, kwot i danych osobowych ([security.md](security.md)) |
| `deep_link` | text | adres widoku w panelu |
| `scheduled_for` | timestamptz | planowana wysyłka |
| `sent_at` | timestamptz null | wysłanie |
| `attempts` | int | liczba prób |
| `last_error` | text null | ostatni błąd po `Redactor` |

`UNIQUE (dedupe_key)`. Zadanie wybiera wiersze z terminem i bez `sent_at`, więc przebieg nadrabiający po awarii niczego nie dubluje.

#### `setting`

| Pole | Typ | Opis |
| --- | --- | --- |
| `key` | text | klucz parametru |
| `value` | jsonb | wartość |
| `valid_from` | date | pierwszy dzień obowiązywania |
| `legal_source` | text null | link do źródła wartości |
| `set_by`, `set_at` | uuid, timestamptz | kto i kiedy |
| `note` | text null | uwaga |

- `UNIQUE (key, valid_from)`. Obowiązuje wiersz z największym `valid_from` nie późniejszym niż data biznesowa.
- Wiersz użyty przez zamknięty okres jest niezmienny. Zmiana to nowy wiersz albo ponowne otwarcie okresu ([compliance-calendar.md](compliance-calendar.md)).
- Sekrety nigdy tu nie trafiają; idą przez `SecretStore`.
- Stawki, progi, kwoty ZUS i limity nie są literałami w kodzie.

| Klucz | Zawartość | Decyzja lub dom |
| --- | --- | --- |
| `company.identity` | FA(3) Podmiot1: NIP, numer VAT UE, nazwa, adres, kraj; JPK: `KodUrzedu`, imię, nazwisko, e-mail, telefon | [modules/ksef.md](modules/ksef.md) (FA(3)), [modules/ewidencja.md](modules/ewidencja.md) (JPK) |
| `company.tax_micro_account` | mikrorachunek podatkowy (`tax_micro_account`) | [compliance-calendar.md](compliance-calendar.md) |
| `company.zus_account` | rachunek składkowy ZUS | [compliance-calendar.md](compliance-calendar.md) |
| `ryczalt.service_map` | usługa → PKWiU 2015 → stawka; mapowanie pozycji FreshBooks | Q-22, [tax-rules.md](tax-rules.md) |
| `ryczalt.advance_frequency` | zaliczka co miesiąc albo co kwartał | Q-17 |
| `taxpayer.residence_address` | adres zamieszkania podatnika do PIT-28 część B: `province`, `county`, `municipality`, `street` (opcjonalnie), `building`, `unit` (opcjonalnie), `city`, `postal_code`; obowiązuje wiersz z 31 grudnia roku przychodu | D-96, [modules/jpk.md](modules/jpk.md) |
| `ryczalt.eligibility` | limit prawa do ryczałtu, próg z projektu (obserwacja) i limit wpłaty co kwartał; wiersz domyślny z migracji (D-76) | Q-38, D-76, [tax-rules.md](tax-rules.md) |
| `zus.parameters` | podstawy i kwoty składek na rok | [tax-rules.md](tax-rules.md) |
| `zus.variant` | wariant składek społecznych | Q-16 |
| `fx.art31a_2a_elected` | wybór art. 31a ust. 2a | Q-23, D-21 |
| `pit.kasowy` | metoda kasowa PIT | Q-24 |
| `vat.excess_policy` | zwrot albo przeniesienie nadwyżki VAT; od F5.4 tylko `carry_forward`, brak wiersza = `carry_forward` (D-65) | Q-31, [modules/jpk.md](modules/jpk.md) |
| `wht.rates` | stawki WHT: `standard` per kategoria (`license`, `services_21_2a`) i `treaty` per kod kraju ISO, wartości `"NN.NN"` w procentach (D-65) | Q-21, Q-29, [tax-rules.md](tax-rules.md) |
| `jpk_st.enabled` | JPK_ST włączony | Q-25 |
| `chat.law_snapshot` | wersjonowany zestaw stawek i progów dla czatu | [modules/czat.md](modules/czat.md) |
| `freshbooks.sales_enabled` | brama F3 dla znacznika gotowości i wykrywania obejścia; `valid_from` = dzień go-live F3, wycofanie to nowy wiersz | [modules/freshbooks.md](modules/freshbooks.md) |
| `white_list.pre_payment_threshold_pln` | próg 15 000 zł: powyżej niego wynik `NIE` przed przelewem tworzy zadanie; `legal_source`: art. 117ba § 3 [Ordynacji podatkowej](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf) | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |
| `ksef.token_legal_valid_until` | prawna data końca tokenów KSeF, domyślne `valid_until` przy ich rejestracji; dziś 31.12.2026 według § 13 ust. 1 pkt 1 [Dz.U. 2025 poz. 1815](https://api.sejm.gov.pl/eli/acts/DU/2025/1815) | [modules/ksef.md](modules/ksef.md) |
| `ksef.fa3_review` | wynik przeglądu doradcy: data, zatwierdzone `tax_scenario` i KOR, sha256 złotych plików; brak blokuje zatwierdzenie przy `KSEF_TARGET=prod` | D-04, Q-29, [modules/ksef.md](modules/ksef.md) |
| `ksef.sales_transition_on` | dzień pierwszej Twojej faktury w KSeF; dzień przejścia dla importu FreshBooks 2026 | Q-10, [modules/ksef.md](modules/ksef.md) |
| `ledger.auto_entries_enabled` | wpisy automatyczne w ewidencji; `valid_from` = dzień włączenia, dopiero po imporcie 2026 | [modules/ewidencja.md](modules/ewidencja.md) |
| `opening.vat_carry_forward_pln` | nadwyżka VAT do przeniesienia (P_62) z ostatniego JPK_V7M złożonego poza kokpitem | [compliance-calendar.md](compliance-calendar.md) |
| `opening.revenue_ytd` | przychód narastająco per stawka ryczałtu na dzień przejścia | [compliance-calendar.md](compliance-calendar.md) |
| `opening.zus_paid_ytd` | składki zapłacone w roku: społeczne i zdrowotna, w PLN | [compliance-calendar.md](compliance-calendar.md) |
| `opening.last_invoice_numbers` | ostatni numer faktury per seria; chroni przed kolizją P_2 ([modules/ksef.md](modules/ksef.md)) | [compliance-calendar.md](compliance-calendar.md) |
| `vat.standard_rate` | stawka podstawowa VAT, np. do VAT należnego z importu usług; `legal_source`: [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | [tax-rules.md](tax-rules.md) |

- `DataUrodzenia` do JPK pochodzi z `SecretStore` (`auth_data`), nie z `setting` ([security.md](security.md)).
- VAT rozliczasz tylko miesięcznie (D-02), więc nie ma ustawienia okresu VAT.
- `chat.model` i `chat.effort` to konfiguracja aplikacji, nie `setting`. Klucz `basal.thresholds` jest odłożony (sekcja „Odłożone”).
- Klucze `opening.*` zastępują tabelę `opening_balance` (D-29). Mają `valid_from` = data przejścia, czyli pierwszy dzień miesiąca, od którego kokpit liczy VAT.
- Każdy wiersz `opening.*` ma dowód: `stored_file` rodzaju `evidence` z właścicielem `setting`, zapisany w tej samej transakcji. Dowody to m.in. ostatnie UPO i zestawienie z eZUS.
- Wiersz `opening.*` użyty przez zamknięty okres jest niezmienny, jak każdy `setting`. Kroki formularza „Otwarcie” i potwierdzenie: [compliance-calendar.md](compliance-calendar.md).
- Progi dławienia konektorów (`white_list.search` 80, `white_list.check` 4000, `vies.check`, `nbp.rates`) to konfiguracja konektora, nie `setting` ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)).

### Panel

#### `app_user`

| Pole | Typ | Opis |
| --- | --- | --- |
| `username` | text | login |
| `display_name` | text | nazwa w panelu |
| `disabled_at` | timestamptz null | wyłączenie |
| `calendar_feed_token_hash` | char(64) null, unikalne | sha256 tokena adresu kanału ICS obowiązków; pusty to kanał wyłączony (D-77) |
| `enrollment_kind` | text null `CHECK` | rodzaj uwierzytelniacza aktywnego linku rejestracji: `passkey` albo `security_key`; czyszczony po użyciu (D-90) |

Jeden użytkownik. Tabela istnieje, bo `approval`, `audit_log` i `draft` zapisują aktora.

#### `webauthn_credential`

| Pole | Typ | Opis |
| --- | --- | --- |
| `app_user_id` | uuid FK | właściciel |
| `credential_id` | bytea | identyfikator passkeya |
| `public_key` | bytea | klucz publiczny |
| `sign_count` | bigint | licznik podpisów |
| `transports`, `aaguid` | jsonb, uuid | dane uwierzytelniacza |
| `label` | text | nazwa urządzenia |
| `kind` | text `CHECK`, domyślnie `passkey` | `passkey` albo `security_key` (klucz FIDO2, `cross-platform`), z linku rejestracji (D-90) |
| `last_used_at`, `revoked_at` | timestamptz null | użycie i unieważnienie |

`UNIQUE (credential_id)`. Pozostałe pola według `web-auth/webauthn-symfony-bundle`. W F0a jeden passkey; drugi passkey i step-up od F0b (D-28). Zasady w [security.md](security.md).

### Czat

#### `chat_session`

| Pole | Typ | Opis |
| --- | --- | --- |
| `app_user_id` | uuid FK | użytkownik |
| `model` | text | model z `chat.model` na starcie, np. `claude-haiku-5-5` |
| `effort` | text | effort z `chat.effort` |
| `tool_set` | text | wersja stałej listy narzędzi rozmowy |
| `system_prompt_sha256` | char(64) | hash promptu systemowego |
| `law_snapshot_from` | date | `valid_from` użytego `chat.law_snapshot` |
| `title` | text null | tytuł |
| `started_at` | timestamptz | start |
| `closed_at` | timestamptz null | zamknięcie; pusty przy otwartej rozmowie |
| `input_tokens`, `output_tokens`, `cache_read_tokens`, `cache_write_tokens` | bigint | sumy z wiadomości |
| `cost_usd` | NUMERIC(12,6) | koszt rozmowy |
| `pseudonym_map` | jsonb | mapa pseudonimów sesji; poza widokami `chat_*` |

Zmiana `chat.model`, listy narzędzi albo promptu systemowego w konfiguracji otwiera nową rozmowę. Czat działa tylko na Claude Haiku 5.5, bez eskalacji modelu (D-10, D-29).

#### `chat_message`

| Pole | Typ | Opis |
| --- | --- | --- |
| `id` | bigint identity | kolejność |
| `chat_session_id` | uuid FK | rozmowa |
| `seq` | int | numer w rozmowie |
| `role` | text | `user`, `assistant` albo `system`, wartości API |
| `content` | jsonb | surowe bloki treści w kolejności z API, po maskowaniu |
| `draft_requested` | boolean | przełącznik „Szkic” na Twojej wiadomości; nie trafia do modelu ([modules/czat.md](modules/czat.md)) |
| `stop_reason` | text null | powód zakończenia z API |
| `model`, `effort` | text | model i effort żądania |
| `usage` | jsonb | tokeny wejścia, wyjścia i cache |
| `cost_usd` | NUMERIC(12,6) | koszt żądania |
| `request_id` | text null | identyfikator żądania API |

- `UNIQUE (chat_session_id, seq)`.
- Tylko dopisywanie. Zapisanej historii nie przycina się ani nie maskuje ponownie po zmianie reguł.
- Budżety dzienne i miesięczne liczą się z sum `cost_usd` ([modules/czat.md](modules/czat.md)).

### Obserwacja prawa

#### `legal_watch`

| Pole | Typ | Opis |
| --- | --- | --- |
| `kind` | text | `act` (akt w ELI) albo `process` (proces legislacyjny Sejmu) |
| `ref` | text | `DU/2004/535` albo `term10/2453`; `UNIQUE (kind, ref)` |
| `label` | text | nazwa z `legal_watch.items` |
| `snapshot` | jsonb | posortowana lista: `kategoria\|ELI` powiązań z obserwowanych kategorii albo etapy procesu (`passed` po uchwaleniu) |
| `snapshot_sha256` | char(64) | hash `snapshot` |
| `checked_at`, `changed_at` | timestamptz | ostatni odczyt; ostatnia zmiana stanu (pusty do pierwszej zmiany) |

#### `legal_watch_hit`

| Pole | Typ | Opis |
| --- | --- | --- |
| `legal_watch_id` | uuid FK | pozycja |
| `snapshot_sha256` | char(64) | stan, w którym pojawiły się zmiany; `UNIQUE (legal_watch_id, snapshot_sha256)` |
| `changes` | jsonb | niepusta lista nowych pozycji: akt (`category`, `eli`, `displayAddress`, `title`, `date`) albo etap (`stage`, `title`) |
| `detected_at` | timestamptz | wykrycie |
| `reviewed_at` | timestamptz null | przeniesienie do [facts.md](facts.md) (`app:legal-watch report --mark-reviewed`) |

- Rola aplikacji ma `SELECT`, `INSERT` i `UPDATE`, bez `DELETE`. Tabele nie mają danych osobowych ani kwot.

### Poczta przychodząca

#### `mail_in_message`

Jedna pobrana wiadomość skrzynki `mail_in` (D-92). Treści maila kokpit nie zapisuje; dokumenty są w `stored_file` (`cost_document`, `owner_table` = `mail_in_message`).

| Pole | Typ | Opis |
| --- | --- | --- |
| `environment` | text | środowisko wdrożenia |
| `uid_validity`, `uid` | bigint | `UIDVALIDITY` skrzynki i UID wiadomości; `UNIQUE (environment, uid_validity, uid)`; kursor to największy `uid` dla bieżącego `uid_validity` |
| `message_id_sha256` | char(64) null | sha256 nagłówka `Message-ID`; ta sama wiadomość pod nowym UID dostaje `duplicate` |
| `from_address`, `subject` | text null | adres nadawcy (małe litery) i temat do 200 znaków, bez znaków sterujących |
| `sent_at` | timestamptz null | nagłówek `Date` |
| `size_bytes` | bigint | `RFC822.SIZE` z serwera |
| `status` | text | `new` (są dokumenty), `no_document`, `too_large` (bez pobrania), `duplicate`, `unreadable` |
| `documents`, `skipped` | int | zapisane dokumenty i pominięte załączniki; `documents > 0` tylko przy `new` |
| `fetched_at` | timestamptz | pobranie |
| `handled_at`, `handled_by` | timestamptz null, uuid FK null | „Oznacz jako wpisane” w panelu, tylko przy `new` |

- Rola aplikacji ma `SELECT`, `INSERT` i `UPDATE`, bez `DELETE`. Pliki dziedziczą retencję `stored_file` (koniec roku pobrania + 5 lat).

## Stany encji

### Mapa stanów

| Pole | Wartości | Start | Końcowe | Kto zmienia | Dom przejść |
| --- | --- | --- | --- | --- | --- |
| `invoice.status` | `draft`, `ready`, `approved`, `submitted`, `accepted`, `rejected`, `unknown`, `corrected`, `superseded`, `cancelled_before_send`, `offline_issued` | `draft` | `superseded`, `cancelled_before_send` | Ty i system | ten plik |
| `invoice.cost_status` | `imported`, `reviewed`, `assigned` | `imported` | – | Ty i system | ten plik |
| `invoice.cost_review` | `business`, `private`, `not_mine`, `disputed` | pusty | – | Ty | ten plik |
| `filing.status` | `draft`, `ready`, `approved`, `submitted`, `accepted`, `rejected`, `unknown`, `superseded` | `draft`; `accepted` przy `manual` | `superseded` | Ty i system | ten plik |
| `approval.status` | `pending`, `approved`, `dispatching`, `submitted`, `accepted`, `rejected`, `unknown`, `expired`, `superseded`, `cancelled` | `pending` | `accepted`, `rejected`, `expired`, `superseded`, `cancelled` | Ty: `approved`, `cancelled`, `superseded` (ręczne ponowienie), `accepted` („Skopiowano”), `unknown` (odtworzenie z backupu); system: reszta, także `cancelled` przy `hash_mismatch` | [approval-gate.md](approval-gate.md) |
| `period.status` | `open`, `closed`, `reopened` | `open` | – | system: `closed` po przyjętym JPK_V7M albo złożeniu `manual` z UPO; Ty: `reopened`, ponowne zamknięcie bez korekty | [compliance-calendar.md](compliance-calendar.md) |
| `obligation.status` | `open`, `done`, `not_applicable` | `open` | `done`; `not_applicable` tylko ustawiony przez Ciebie (`done_by` niepusty) | system: silnik, zdarzenia źródłowe, przyjęte złożenie, ponowne otwarcie systemowego `not_applicable`; Ty: zapłata, czynność, `not_applicable` z powodem | [compliance-calendar.md](compliance-calendar.md) |
| `draft.status` | `pending`, `approved`, `discarded`, `executed` | `pending` | `discarded`, `executed` | Ty; system: wygaśnięcie, `hash_mismatch`, wynik wysyłki z kokpitu | [modules/czat.md](modules/czat.md) |
| `credential_state.status` | `valid`, `expiring`, `expired`, `revoked` | `valid` | –; `revoked` → `valid` tylko przez rotację z nowym sekretem | system: `expiring`, `expired`; Ty: rotacja, `revoked` | [security.md](security.md) |
| `verification.result` | `valid`, `invalid`, `unverified_retry`, `manual_evidence` | – | każdy wiersz | system; Ty: `manual_evidence` | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |

Stany tabel odłożonych (`edelivery_message`, `edelivery_case`, `classification`) są w sekcji „Odłożone”.

### Faktura sprzedaży

| Z | Do | Wyzwalacz | Aktor |
| --- | --- | --- | --- |
| – | `draft` | polling FreshBooks znajduje szkic | system |
| `draft` | `ready` | znacznik gotowości; mapowanie FA(3), XSD i warunki wstępne przechodzą; powstaje `approval` w stanie `pending` | Ty (znacznik), system (walidacja) |
| `ready` | `draft` | zdjęcie znacznika przed decyzją albo walidacja przestaje przechodzić; `approval` → `superseded` | Ty (znacznik), system |
| `ready` | `ready` | zmiana `source_hash` przed decyzją; stare `approval` → `superseded`, nowe → `pending` | system |
| `ready` | `ready` | `approval` w `pending` wygasło o północy; nowa prośba z dzisiejszą P_1 | system |
| `ready` | `approved` | `approval` → `approved` | Ty |
| `approved` | `ready` | `approval` wygasło albo anulowane z `hash_mismatch`, zmienił się dzień P_1 (D-16) albo źródło; nowe `approval` | system |
| `approved` | `submitted` | worker wysłał fakturę; numery referencyjne zapisane | system |
| `approved`, `submitted` | `unknown` | timeout albo brak odpowiedzi | system |
| `approved` | `unknown` | odtworzenie z backupu: faktura mogła wyjść po zrzucie; `approval` → `unknown` ([approval-gate.md](approval-gate.md), runbook w [operations.md](operations.md)) | Ty |
| `submitted`, `unknown` | `accepted` | numer KSeF i UPO; `unknown` rozstrzyga `SubmissionReconciler` | system |
| `approved` | `rejected` | odmowa przed nadaniem numeru referencyjnego, np. błąd walidacji | system |
| `submitted`, `unknown` | `rejected` | KSeF odrzucił fakturę; faktura nie została wystawiona | system |
| `unknown` | `ready` | ręczne ponowienie po rekoncyliacji; stare `approval` → `superseded`, nowe `pending` | Ty |
| `rejected` | `ready` | poprawka i nowe `approval`, bez KOR | Ty |
| `draft`, `ready`, `approved` | `cancelled_before_send` | anulowanie w panelu albo usunięcie szkicu we FreshBooks | Ty, system |
| `draft`, `ready`, `approved` | `cancelled_before_send` | faktura z Subject1 o tym samym `kind` i P_2: szkic oddaje `freshbooks_id` fakturze z KSeF; żywe `approval` → `cancelled` z `cancel_reason` = `manual_invoice`. `approved` tylko przy `approval` w stanie `approved`; przy `dispatching` rozstrzyga `SubmissionReconciler` ([modules/ksef.md](modules/ksef.md), [modules/freshbooks.md](modules/freshbooks.md)) | system |
| `accepted` | `corrected` | przyjęta KOR do tej faktury | system |
| `accepted`, `corrected` | `superseded` | przyjęta KOR do zera i faktura zastępcza z `replaces_invoice_id` | system |
| `ready`, `approved` | `offline_issued` | tylko gdy D-15 i D-16 dopuszczą tryb offline | system |
| `offline_issued` | `submitted` | wysyłka w trybie offline w terminie | system |

- Klient dostaje dokument tylko przy `accepted` z numerem KSeF. PDF z KOD I powstaje dopiero wtedy.
- Faktura wystawiona poza kokpitem i pobrana z KSeF jako Subject1 wchodzi od razu jako `accepted`, bez `approval`. Jej XML z KSeF to `stored_file` rodzaju `xml_sent`.
- KOR to osobny wiersz `invoice` z `kind` = `KOR` i tym samym cyklem. Zatwierdzana jest jako `correction_invoice`.
- Credit note albo edycja we FreshBooks po przyjęciu tworzy szkic KOR, nigdy ponowną wysyłkę ([modules/ksef.md](modules/ksef.md)).

### Faktura kosztowa

| Z | Do | Wyzwalacz | Aktor |
| --- | --- | --- | --- |
| – | `imported` | sync Subject2, mail, skan albo wpis ręczny; źródło inne niż `ksef` tylko z plikiem `cost_document` | system, Ty |
| `imported` | `reviewed` | ustawiasz `cost_review` | Ty |
| `reviewed` | `assigned` | przypisanie `vat_period_start` przy `business`; system proponuje najwcześniejszy dozwolony okres | Ty |
| `reviewed`, `assigned` | `imported` | faktura z KSeF różni się od wcześniejszego wpisu ręcznego; okres otwarty | system |
| `assigned` | `reviewed` | zmiana oceny albo okresu przed zamknięciem okresu | Ty |

- Koszt ze źródłem innym niż `ksef` nie powstaje bez `stored_file` rodzaju `cost_document`. Strażnik przejścia do `reviewed` zostaje jako druga bariera.
- Bramka JPK_V7M odrzuca okres z kosztami w stanie `imported`.
- `private`, `not_mine` i `disputed` nie trafiają do JPK. `not_mine` tworzy w panelu zadanie: prośba o KOR albo zgłoszenie oszustwa.
- Koszt w zamkniętym okresie jest niezmienny. Zmiana wymaga ponownego otwarcia okresu.
- Ręczna zmiana `tax_point_date` kosztu, np. przy przedpłacie, wymaga `tax_point_override_reason` i otwartego okresu. Reguła i Q-21: [tax-rules.md](tax-rules.md).
- Wpis ręczny łączy się z późniejszą fakturą z KSeF po sprzedawcy i numerze. Połączony wiersz dostaje `ksef_number`.

### Złożenie

| Z | Do | Wyzwalacz | Aktor |
| --- | --- | --- | --- |
| – | `draft` | budowa pliku z wierszy okresu w tym samym `environment`, także dry run | system, Ty |
| `draft` | `ready` | żądanie budowy do wysyłki przy włączonym konektorze (F5); XSD i bramki kompletności przechodzą; powstaje `approval` w stanie `pending` | Ty (żądanie), system (walidacja) |
| `ready` | `draft` | zmiana danych okresu; `approval` → `superseded` | system |
| `ready`, `approved` | `draft` | `approval` → `cancelled`: anulowanie w panelu albo `hash_mismatch` | Ty, system |
| `ready` | `approved` | `approval` → `approved` | Ty |
| `approved` | `ready` | `approval` wygasło; nowe `approval` | system |
| `approved` | `submitted` | upload zakończony; numer referencyjny zapisany | system |
| `approved`, `submitted` | `unknown` | timeout albo brak statusu | system |
| `approved` | `unknown` | odtworzenie z backupu: plik mógł wyjść po zrzucie; `approval` → `unknown` ([approval-gate.md](approval-gate.md), runbook w [operations.md](operations.md)) | Ty |
| `submitted`, `unknown` | `accepted` | UPO pobrane; duplikat wykryty przez bramkę oznacza przyjęcie oryginału | system |
| `approved` | `accepted` | duplikat przy wysyłce z referencją oryginału (JPK 170); UPO oryginału pobrane | system |
| `approved` | `rejected` | odmowa przed nadaniem numeru referencyjnego | system |
| `submitted`, `unknown` | `rejected` | końcowy status błędu | system |
| `unknown` | `ready` | ręczne ponowienie po rekoncyliacji; stare `approval` → `superseded`, nowe `pending` | Ty |
| `rejected` | `draft` | poprawka danych | Ty |
| –, `draft`, `ready`, `approved`, `rejected`, `unknown` | `accepted` | złożenie poza kokpitem (`submitted_via` = `manual`): wgrane UPO ze step-up, wpis `filing.manual_recorded`; bez `approval`, `xml_sent` opcjonalny; przy `unknown` tylko po nieudanej rekoncyliacji | Ty |
| `accepted` | `superseded` | przyjęta korekta tego okresu (`supersedes_id`) | system |

- Dry run (`app:jpk:dry-run`) zostawia złożenie w `draft`, bez `approval` i bez wywołań konektora `jpk`.
- F5 przebudowuje wiersz z dry runu w miejscu, bo ma ten sam klucz `UNIQUE (environment, type, period_start, purpose, seq)`.
- Wgranie UPO przy żywym `approval` (`pending`, `approved`, `unknown`) przestawia je na `cancelled` z `cancel_reason` = `manual_filing` ([approval-gate.md](approval-gate.md)). Przy `approval` w stanie `dispatching` albo `submitted` wgranie jest zablokowane.
- Przyjęty JPK_V7M zamyka `period` i obowiązek. Kody statusów bramek i rekoncyliacja: [approval-gate.md](approval-gate.md), [modules/jpk.md](modules/jpk.md).

### Powiązanie z bramką zatwierdzeń

Zmiana `approval.status` przestawia stan encji zatwierdzanej. Strona `approval` jest w [approval-gate.md](approval-gate.md).

| `approval.status` | `sales_invoice`, `correction_invoice` | `filing` | `draft` |
| --- | --- | --- | --- |
| `pending` | `ready` | `ready` | `pending` |
| `approved`, `dispatching` | `approved` | `approved` | `approved` |
| `submitted` | `submitted` | `submitted` | – |
| `accepted` | `accepted` | `accepted` | `executed` |
| `rejected` | `rejected` | `rejected` | – |
| `unknown` | `unknown` | `unknown` | – |
| `expired`, `superseded` | `ready` z nowym `approval` albo `draft` | `ready` z nowym `approval` albo `draft` | `pending` |
| `cancelled`, `cancel_reason` = `user` (anulowanie w panelu) | `cancelled_before_send` | `draft` | `discarded` |
| `cancelled`, `cancel_reason` = `hash_mismatch` | `ready` z nowym `approval`; decyzja po wyjaśnieniu alertu | `draft`; nowe `approval` po wyjaśnieniu alertu | `pending` |
| `cancelled`, `cancel_reason` = `manual_filing` | – | `accepted` (przez `manual`) | – |
| `cancelled`, `cancel_reason` = `manual_invoice` | `cancelled_before_send`; fakturę zastępuje wiersz z Subject1 | – | – |

- Przy szkicu tylko do kopiowania (Q-35) Twoje kliknięcie „Skopiowano” przestawia `approval` → `accepted` (bez wysyłki, z `finished_at`) i `draft` → `executed`. Wiersz `accepted` obejmuje więc też kopiowanie.
- Odtworzenie z backupu przestawia `approved` na `unknown` po obu stronach; dalej działa wiersz `unknown`.

### Stany pochodne

Encje bez kolumny stanu. Stan wynika z kolumn i nie jest zapisywany.

| Encja | Stan | Warunek |
| --- | --- | --- |
| `ksef_session` | otwarta | `closed_at` pusty i teraz < `expires_at` |
| `ksef_session` | zamknięta | `closed_at` ustawiony |
| `ksef_session` | wygasła | `closed_at` pusty i teraz ≥ `expires_at` |
| `payment` | nieprzypisana, przypisana, rozliczona | `invoice_id` i `revenue_entry_id` puste; jedno z nich ustawione i `matched_at`; `fx_posted_at` albo płatność w PLN |
| `notification` | zaplanowane, wysłane, błąd | `sent_at` pusty; `sent_at` ustawiony; `last_error` przy pustym `sent_at` |
| `stored_file` | zapisany, sprawdzony, niezgodny, usunięty | wiersz; `last_verified_at`; `verify_failed_at`; `deleted_at` |
| `chat_session` | otwarta, zamknięta | `closed_at` |
| `sync_cursor` | backfill, przyrost | `backfill_done_at` pusty albo ustawiony |
| `setting` | obowiązujący | największe `valid_from` nie późniejsze niż data |
| `revenue_entry`, `fx_rate`, `verification`, `connector_call`, `audit_log`, `chat_message` | brak stanów | wiersz niezmienny; zmiana to nowy wiersz |

## Magazyn plików

Pliki leżą na dysku, a baza trzyma ścieżkę, hash i właściciela w `stored_file`. Jedyną drogą zapisu jest usługa `FileStore`.

**Układ:** `var/data/{env}/{kind}/{yyyy}/{sha256}.{ext}`

| Segment | Wartość |
| --- | --- |
| `{env}` | `environment` wiersza |
| `{kind}` | `stored_file.kind` |
| `{yyyy}` | rok pierwszego zapisu w Europe/Warsaw |
| `{sha256}` | hash dokładnych bajtów, hex małymi literami |
| `{ext}` | rozszerzenie z typu MIME, np. `xml`, `pdf`, `json` |

**Zasady zapisu**

- `FileStore` liczy sha256, zapisuje plik tymczasowy w katalogu docelowym, wykonuje `fsync` i atomowe `rename`. Plik dostaje prawa tylko do odczytu.
- Plik o tym samym hashu już istnieje: `FileStore` dodaje tylko wiersz `stored_file`.
- Nigdy nie nadpisuje ani nie edytuje. Nowa wersja to nowy plik i nowy wiersz.
- Fizyczny plik może wskazywać kilka wierszy. Znika dopiero, gdy żaden wiersz go nie wskazuje i żaden nie ma `legal_hold`.
- `xml_sent` to dokładne wysłane bajty. KOD I liczy się z tych bajtów, więc ponowna serializacja XML jest zabroniona ([modules/ksef.md](modules/ksef.md)).
- Przed wysyłką worker liczy sha256 payloadu i porównuje z `approval.payload_sha256` ([approval-gate.md](approval-gate.md)).
- Cotygodniowe przeliczenie hashy ustawia `last_verified_at` albo `verify_failed_at` z alertem. Zadanie jest w katalogu zadań [operations.md](operations.md).
- Retencja, `legal_hold` i usuwanie RODO: [security.md](security.md). Spójny backup bazy i plików: [operations.md](operations.md).

## Odłożone

Tabele, kolumny i wartości odłożone w D-29. Nie powstają w migracjach. Powrót modułu wymaga nowego rekordu D-xx; warunek powrotu jest w [roadmap.md](roadmap.md).

| Element | Dom | Powrót |
| --- | --- | --- |
| tabele `edelivery_message`, `edelivery_case` | [modules/e-doreczenia.md](modules/e-doreczenia.md) | F4, warunek w [roadmap.md](roadmap.md) |
| `environment` = `int`, zmienna `EDORECZENIA_TARGET`, wiersz celu e-Doręczeń w „Środowiskach” | [modules/e-doreczenia.md](modules/e-doreczenia.md) | F4 |
| `obligation.edelivery_message_id`; `obligation.type` = `edelivery_deemed` z API; `edelivery_reply_due` z `edelivery_case.reply_due_date` | [compliance-calendar.md](compliance-calendar.md) | F4; do tego czasu `edelivery_reply_due` wpisujesz ręcznie (sekcja `obligation`) |
| `notification.edelivery_message_id`, `notification.category` = `edelivery_new` | [compliance-calendar.md](compliance-calendar.md) | F4 |
| `approval.subject_type` = `edelivery_outbound`, `approval.connector` = `edoreczenia` | [approval-gate.md](approval-gate.md) | F4 i zmiana Q-35 |
| `draft.kind` = `edelivery_reply`, `draft.recipient_case_id`; wtedy `recipient_counterparty_id` staje się `null` i wraca `CHECK`: dokładnie jeden odbiorca | [modules/czat.md](modules/czat.md) | F4 i zmiana Q-35 |
| `credential_state.kind` = `edoreczenia_qualified_cert`, `edoreczenia_access_token` | [security.md](security.md) | F4 |
| `sync_cursor.stream` = `inbox`, `outbox` | [modules/e-doreczenia.md](modules/e-doreczenia.md) | F4 |
| `stored_file.kind` = `attachment`; `evidence` z właścicielem `edelivery_message` | [modules/e-doreczenia.md](modules/e-doreczenia.md) | F4 |
| tabela `classification`, klucz `setting` `basal.thresholds`, konfiguracja `basal.model` i `basal.revision` | [modules/basal.md](modules/basal.md) | wiersz basal w [roadmap.md](roadmap.md) |

Definicje niżej zostają jako rezerwacja kolumn. Przy powrocie modułu sesja sprawdza je ze źródłami z pliku modułu.

### `edelivery_message`

| Pole | Typ | Opis |
| --- | --- | --- |
| `ade_address` | text | skrzynka kokpitu (`ade_address` firmy) |
| `message_id` | text | id wiadomości z UA API |
| `direction` | text | `in` (skrzynka odbiorcza) albo `out` (skrzynka nadawcza) |
| `status` | text null | `stub`, `receiving`, `received`, `received_elsewhere`; pusty dla `out` |
| `message_type` | text | typ wiadomości z API |
| `sender_ade`, `sender_name` | text | nadawca |
| `sender_is_public` | boolean | nadawca publiczny; tylko wtedy działa fikcja doręczenia |
| `recipient_ade` | text | odbiorca |
| `subject` | text | temat |
| `event_date` | timestamptz | `eventDate` z API, data wpływu |
| `days_to_receive` | int null | `daysToReceive` z API |
| `api_receipt_date` | date null | `receiptDate` z API |
| `deemed_delivery_date` | date null | data fikcji pokazywana w panelu, z API |
| `deemed_delivery_date_local` | date null | wyliczenie kontrolne; rozbieżność to alert |
| `received_at` | timestamptz null | odbiór z dowodem E.1 albo wykryty odbiór poza kokpitem |
| `receive_requested_by` | uuid null | Ty, przycisk „Odbierz” |
| `receive_audit_id` | bigint FK null | wpis `audit_log` akcji „Odbierz” albo „Pobierz kopię” (`received_elsewhere`); handler GET wymaga tego pola |
| `case_id` | uuid FK null | `edelivery_case` |
| `metadata` | jsonb | surowe metadane z listy |

- `UNIQUE (environment, ade_address, message_id)`.
- GET wiadomości w stanie Stub to prawny odbiór z dowodem E.1; odpowiedź 202 oznacza trwający transfer ([PT UA API 5.27](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf)). Dlatego `received_at` ustawia tylko `receive()` albo synchronizacja odbioru poza kokpitem.
- Fikcja według art. 42 ust. 2 [ustawy o doręczeniach elektronicznych](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text/O/D20260003.pdf) i reguła R13 z PT mogą różnić się o dzień. Stąd dwie kolumny daty.
- Treść i załączniki to `stored_file` rodzaju `attachment`, dowody rodzaju `evidence`, oba z właścicielem `edelivery_message`.

### `edelivery_case`

| Pole | Typ | Opis |
| --- | --- | --- |
| `title` | text | nazwa sprawy |
| `status` | text | `new`, `received`, `awaiting_reply`, `replied`, `closed` |
| `sender_ade` | text | urząd prowadzący sprawę |
| `reply_due_date` | date null | termin odpowiedzi potwierdzony przez Ciebie |
| `obligation_id` | uuid FK null | obowiązek z terminem odpowiedzi |
| `opened_at` | timestamptz | otwarcie |
| `closed_at` | timestamptz null | zamknięcie; pusty przy sprawie otwartej |
| `notes` | text null | notatki |

Model może tylko zaproponować termin z cytatem źródła. `reply_due_date` zapisuje się po Twoim potwierdzeniu ([modules/e-doreczenia.md](modules/e-doreczenia.md)).

### `classification`

| Pole | Typ | Opis |
| --- | --- | --- |
| `subject_type`, `subject_id` | text, uuid | tabela i wiersz, np. `edelivery_message`, `invoice`, `chat_session` |
| `chat_seq` | int null | `seq` pytania w `chat_message` przy wierszu routera ([modules/basal.md](modules/basal.md)) |
| `question_key` | text | pytanie |
| `question_type` | text | typ pytania basal ([modules/basal.md](modules/basal.md)) |
| `criteria_version` | text | wersja treści kryteriów i opcji |
| `options` | jsonb | opcje |
| `input_text` | text | wejście po maskowaniu i przycięciu |
| `chosen_option` | text | wybrana opcja |
| `probabilities` | jsonb | prawdopodobieństwo każdej opcji |
| `confidence` | NUMERIC(6,5) | pewność wyboru |
| `threshold` | NUMERIC(6,5) null | próg z `basal.thresholds` |
| `calibration` | text | `validated`, gdy próg pochodzi z Twojego doboru dla krotki (`question_key`, `model`, `revision`, `engine_mode`, `criteria_version`); dla `act` dodatkowo wartość z API silnika; inaczej `unvalidated`. Reguła w [modules/basal.md](modules/basal.md) |
| `model`, `revision`, `engine_mode` | text | model, rewizja HF; `engine_mode`: tryb silnika, kwantyzacja, wersja silnika i digest obrazu `llama-server` |
| `outcome` | text | `auto_accepted`, `needs_review`, `corrected` |
| `user_label` | text null | Twoja etykieta |
| `labelled_at`, `labelled_by` | timestamptz, uuid null | korekta |

- `CHECK ((subject_type = 'chat_session') = (chat_seq IS NOT NULL))`.

Wiersz zawiera komplet wejścia i parametrów, więc da się go ponownie uruchomić jako przypadek testowy.

### Stany odłożone

| Pole | Wartości | Start | Końcowe | Kto zmienia | Dom przejść |
| --- | --- | --- | --- | --- | --- |
| `edelivery_message.status` | `stub`, `receiving`, `received`, `received_elsewhere` | `stub` | `received`, `received_elsewhere` | Ty: „Odbierz”; system: reszta | [modules/e-doreczenia.md](modules/e-doreczenia.md) |
| `edelivery_case.status` | `new`, `received`, `awaiting_reply`, `replied`, `closed` | `new` | `closed` | Ty i system | [modules/e-doreczenia.md](modules/e-doreczenia.md) |
| `classification.outcome` | `auto_accepted`, `needs_review`, `corrected` | `auto_accepted` albo `needs_review` | `corrected` | system; Ty: `corrected` | [modules/basal.md](modules/basal.md) |

## Zmiany względem SPEC z 5.10

Dawny model miał dziesięć tabel. Każde pole ma teraz swoje miejsce.

| Dawna tabela i pola | Teraz |
| --- | --- |
| `invoice`: kierunek | `invoice.direction` |
| `invoice`: numer, `ksef_number`, `freshbooks_id` | `invoice_number`, `ksef_number`, `freshbooks_id` |
| `invoice`: data | `issue_date`, `service_date`, `sent_at`, `ksef_number_assigned_at`, `revenue_date`, `tax_point_date` |
| `invoice`: waluta, netto, VAT, brutto | `currency`, `net_total`, `vat_total`, `gross_total`; pozycje w `invoice_line` |
| `invoice`: kurs NBP, kwota PLN | `fx_vat_rate_id` i `fx_pit_rate_id` do `fx_rate`, `net_pln`, `vat_pln`; kwota PIT w `revenue_entry.amount_pln` |
| `invoice`: status | `status` dla sprzedaży (dawne `sent` to `submitted`), `cost_status` i `cost_review` dla kosztów |
| `invoice`: ścieżki XML, UPO, PDF | `stored_file` rodzajów `xml_sent`, `upo`, `pdf` |
| `counterparty`: nazwa, kraj, VAT UE, NIP, KRS | te same pola oraz adres, NrID, REGON, klient FreshBooks, rachunki, WHT |
| `verification`: źródło, data, wynik, surowa odpowiedź | `source`, `checked_at`, `result`, `stored_file` `verification_raw`; nowe: rachunek, dzień zapytania, dowód źródła |
| `ksef_session`: numer referencyjny, otwarcie, zamknięcie, status | `reference_number`, `opened_at`, `closed_at`, `status_code`; nowe: `expires_at` |
| `edelivery_message`: `message_id`, nadawca, temat, data wpływu, data fikcji, status, ścieżka dowodów | `message_id`, `sender_ade`, `sender_name`, `subject`, `event_date`, `deemed_delivery_date`, `status`, `stored_file` `evidence`; odłożone (D-29), sekcja „Odłożone” |
| `filing`: typ, okres, numer referencyjny, status, ścieżka UPO | `type` (nowe: `JPK_ST`, `VAT_UE`, `VAT_UEK`), `period_start`, `period_end`, `reference_number`, `status`, `stored_file` `upo` |
| `approval`: czego dotyczy, data prośby, decyzja, data decyzji | `subject_type`, `subject_id`, `requested_at`, `status`, `decided_at`; nowe: hash payloadu, wygaśnięcie, step-up |
| `obligation`: typ, termin, kwota, status | `type`, `nominal_due_date`, `due_date`, `amount_due`, `status` |
| `audit_log`: czas, akcja | `audit_log`: `occurred_at`, `action`, aktor, łańcuch hashy |
| `audit_log`: system, cel, kod odpowiedzi, hash żądania | `connector_call`: `connector`, `host`, `path`, `http_status`, `request_sha256` |
| `classification`: pytanie, typ, opcje, wybrana opcja, pewność, wersja modelu, korekta | `question_key`, `question_type`, `options`, `chosen_option`, `confidence`, `model` i `revision`, `user_label`; nowe: wejście, prawdopodobieństwa, próg, kalibracja; odłożone (D-29), sekcja „Odłożone” |
| Powiązania faktury, kontrahenta, przesyłki, złożenia i obowiązku | zachowane jako klucze obce |
| Powiązania `approval` z fakturą, złożeniem i przesyłką | faktura i złożenie: `subject_type` i `subject_id`; przesyłka przychodząca: „Odbierz” w `audit_log` (`receive_audit_id`), bez `approval`; odpowiedź: `edelivery_outbound` na `draft`; przesyłki odłożone (D-29) |
| `audit_log` powiązany ze wszystkim | zachowane przez `target_type` i `target_id` |
| Statusy sprzedaży `draft` → `approved` → `sent` → `accepted` albo `rejected` | pełny cykl w sekcji „Faktura sprzedaży” |
| Tylko `accepted` z numerem KSeF trafia do klienta | zasada zachowana |
| Pliki na dysku, w bazie ścieżka i hash | zachowane: `stored_file` i `FileStore` |
| Archiwum pisma jako ZIP przy sprawie | pliki osobno z hashem; ZIP generowany na żądanie; odłożone z F4 (D-29) |
| Stałe dane w konfiguracji: mikrorachunek, rachunek ZUS, stawka ryczałtu, forma VAT | `setting`: `company.tax_micro_account`, `company.zus_account`, `ryczalt.service_map` (stawka per usługa); VAT tylko miesięcznie (D-02) |
