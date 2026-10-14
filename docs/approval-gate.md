# Bramka zatwierdzeń

Semantyka zatwierdzeń, warunki wstępne, stany, wysyłka bez ponowień i rekoncyliacja. „Odbierz” jako akcja logowana jest odłożone z F4 (D-29). Stan na 8 października 2026.

## Zasady

- Każdy zapis w urzędzie przechodzi przez bramkę. Odczyt i synchronizacja działają bez niej.
- Zatwierdzenie wiąże sha256 dokładnych bajtów payloadu. Worker liczy hash ponownie i odmawia przy niezgodności.
- Pola payloadu są niezmienne od zapisu. Zmiana źródła przy zatwierdzeniu `pending` albo `approved` oznacza nowe zatwierdzenie, a stare dostaje `superseded`.
- Na encję przypada jedno żywe zatwierdzenie (`pending`, `approved`, `dispatching`, `submitted`, `unknown`). Po powrocie `edelivery_outbound` (D-29) szkic ma jedno żywe zatwierdzenie łącznie dla obu typów; pilnuje tego `ApprovalGate`, bo indeks unikalny obejmuje `subject_type`.
- Decyzja wymaga step-up passkeyem ([security.md](security.md)). Step-up powstaje w F0b razem z bramką; F0a ma tylko logowanie jednym passkeyem (D-28).
- Wygasłe zatwierdzenie nie odnawia się samo. Nową prośbę tworzysz akcją „Odnów” w panelu (przegląd S2).
- Zapisy urzędowe idą transportem `official_writes` bez automatycznych ponowień, z jednym konsumentem ([operations.md](operations.md)).
- Wynik nieznany przechodzi do rekoncyliacji tylko odczytem. Ponowienie wyłącznie ręcznie, jako nowe zatwierdzenie.
- „Doręcz klientowi” (F3) nie jest zapisem w urzędzie i omija bramkę. Wysyła e-mail z PDF konektorem `mail` synchronicznie w żądaniu panelu, poza Messenger (D-31).

Kolumny `approval` są w [data-model.md](data-model.md). Tam też jest tabela „Powiązanie z bramką zatwierdzeń”, która przestawia stan faktury, złożenia i szkicu.

## Przedmioty zatwierdzeń

| `subject_type` | Encja | Konektor | Payload wiązany hashem | Faza |
| --- | --- | --- | --- | --- |
| `sales_invoice` | `invoice` sprzedaży | `ksef` | dokładne bajty FA(3) w `stored_file` `xml_sent` | F3 |
| `correction_invoice` | `invoice` z `kind` KOR | `ksef` | dokładne bajty FA(3) KOR | F3 |
| `filing` JPK_EWP, JPK_ST, JPK_V7M | `filing` | `jpk` | XML przed kompresją i szyfrowaniem; klucz AES i metadane z danymi autoryzującymi powstają przy wysyłce | F5 |
| `filing` VAT_UE, VAT_UEK | `filing` | `edeklaracje` | XML deklaracji | F5 |
| `draft` | `draft` | brak przy kopiowaniu (Q-35): `approval.connector` = NULL | kanoniczny JSON szkicu (odbiorca, temat, treść) w `stored_file` rodzaju `outbound_payload` | F6 |
| `edelivery_outbound` | `draft` rodzaju `edelivery_reply` | `edoreczenia` | kanoniczny payload wysyłki; po powrocie F4: podgląd jak `draft` z ADE adresata, wygaśnięcie jak `draft`, rekoncyliacja przez sync skrzynki nadawczej, mapowanie stanów w [modules/e-doreczenia.md](modules/e-doreczenia.md) | odłożone (D-29); potem zmiana Q-35 |

- Złożenie poza kokpitem (`submitted_via` = `manual`) nie przechodzi przez bramkę. Wgrywasz UPO ze step-up, a wpis `filing.manual_recorded` trafia do `audit_log`.
- Wgranie UPO jest możliwe, gdy złożenie nie ma żywego zatwierdzenia albo ma je w stanie `pending` lub `approved`. Zatwierdzenie przechodzi wtedy do `cancelled` z `cancel_reason` = `manual_filing`, a złożenie kończy w `accepted` jako `manual` ([data-model.md](data-model.md)).
- Przy `unknown` wgranie jest możliwe dopiero, gdy `SubmissionReconciler` nie ustalił wyniku, np. po JPK 411 ([modules/jpk.md](modules/jpk.md)). Przy `dispatching` i `submitted` panel blokuje wgranie do statusu końcowego albo wyniku rekoncyliacji.
- Dry run JPK (`app:jpk:dry-run`, po F2.4) działa przed F0b, bez bramki: waliduje XSD, nie tworzy zatwierdzenia i nie woła konektora `jpk` (D-30).
- Zaszyfrowane części JPK różnią się przy każdej wysyłce, bo klucz jest losowy. Dlatego hash wiąże dokument jawny, którego SHA-256 trafia też do metadanych ([specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf)).

## Prośba o zatwierdzenie

Prośba powstaje, gdy faktura albo złożenie przechodzi do `ready` ([data-model.md](data-model.md)), oraz jako prośba zastępcza po zmianie źródła (krok 4). Szkic: przy utworzeniu i przy edycji treści albo odbiorcy ([modules/czat.md](modules/czat.md)). Po wygaśnięciu nową prośbę każdego przedmiotu tworzysz Ty akcją „Odnów” (przegląd S2).

1. Generator buduje payload. Dla faktury P_1 to dzisiejszy dzień w Europe/Warsaw według reguły z [modules/ksef.md](modules/ksef.md) (D-16).
2. Walidacja XSD offline i kontrole przed wysyłką ([../.claude/rules/integrations.md](../.claude/rules/integrations.md)).
3. `FileStore` zapisuje bajty. Prośba zapisuje `payload_sha256`, `source_version`, `summary` i wynik `preconditions`.
4. Poprzednie zatwierdzenie tej encji w stanie `pending` albo `approved` dostaje `superseded` z `superseded_by_id`. Przy `dispatching`, `submitted` albo `unknown` nowa prośba nie powstaje, dopóki wynik nie jest znany; wyjątek to ręczne ponowienie. Zmiana po `accepted` to korekta: KOR ([modules/ksef.md](modules/ksef.md)) albo złożenie z `supersedes_id`.
5. Wpis `approval.requested`. Push `approval_pending` tylko przy prośbie systemowej; prośba z Twojej akcji w panelu („Odnów”, ręczne ponowienie) push nie wysyła.

## Warunki wstępne

Warunek sprawdza się w chwili wskazanej w kolumnie „Kiedy”. Niespełniony warunek przy prośbie lub decyzji blokuje zatwierdzenie. Niespełniony przy wysyłce wstrzymuje ją przed pierwszym wywołaniem API.

| Warunek | Dotyczy | Kiedy | Dom reguły |
| --- | --- | --- | --- |
| XSD offline z `LIBXML_NONET`; UTF-8 bez BOM, bez instrukcji przetwarzania, limit rozmiaru | faktury, złożenia | prośba | [../.claude/rules/integrations.md](../.claude/rules/integrations.md) |
| cel konektora równy `environment` encji; konektor włączony | wszystko z konektorem | prośba, wysyłka | [architecture.md](architecture.md) |
| brak `blocked_until` w `rate_counter` (po 429 z `Retry-After`) i niewyczerpany limit dobowy, np. `jpk.init_upload`; bez okien przesuwnych (D-29) | wszystko z konektorem | wysyłka | [architecture.md](architecture.md) |
| P_1 równe dzisiejszemu dniowi w Europe/Warsaw; P_1 w przyszłości blokuje | faktury | prośba, decyzja, wysyłka | [modules/ksef.md](modules/ksef.md) |
| P_2 unikalny w parze z rodzajem faktury, bez kolizji z numerami już w KSeF; blokada przy decyzji (`invoice_number_locked_at`) | faktury | decyzja | [modules/ksef.md](modules/ksef.md) |
| identyfikator podatkowy nabywcy (D-05): ważny VIES dla UE, zagraniczny identyfikator spoza UE, NIP dla Polski | faktury | prośba, decyzja | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |
| `eu_b2b_unidentified`: dowód `manual_evidence` statusu przedsiębiorcy i scenariusz w `ksef.fa3_review`; do potwierdzenia przez doradcę (Q-29) zatwierdzenie zablokowane na każdym celu (D-05) | faktury | prośba, decyzja | [modules/ksef.md](modules/ksef.md) |
| `tax_scenario` ustawiony; każda pozycja ma stawkę ryczałtu | faktury | prośba | [tax-rules.md](tax-rules.md) |
| faktura korygowana przyjęta z numerem KSeF albo oznaczona NrKSeFN | KOR | prośba | [modules/ksef.md](modules/ksef.md) |
| źródło bez zmian: `version` encji równe `approval.source_version`; zmiana `source_hash` faktury podnosi `version` | faktury, złożenia, szkice | decyzja, wysyłka | [data-model.md](data-model.md) |
| scenariusz `tax_scenario` faktury albo KOR zatwierdzony w przeglądzie doradcy: wpis w `setting` `ksef.fa3_review` (D-04) | faktury przy `KSEF_TARGET=prod` | decyzja | [modules/ksef.md](modules/ksef.md) |
| Latarnia w stanie AVAILABLE; inny stan wstrzymuje kolejkę (D-15); przy `KSEF_TARGET=demo` warunek pominięty, bo Latarnia nie ma DEMO ([architecture.md](architecture.md)) | faktury | wysyłka | [modules/ksef.md](modules/ksef.md) |
| poświadczenie zapisu ważne: KSeF `ksef_token_write` albo `ksef_auth_cert`; JPK i e-Deklaracje `auth_data` | faktury, złożenia | decyzja, wysyłka | [security.md](security.md) |
| bramki kompletności okresu z [compliance-calendar.md](compliance-calendar.md) | JPK_V7M | prośba | [compliance-calendar.md](compliance-calendar.md) |
| uzgodnienia: K_8 JPK_EWP równe sumie ewidencji; suma VAT-UE równa K_12 JPK_V7M tego miesiąca | złożenia | prośba | [modules/jpk.md](modules/jpk.md) |
| brak innego żywego złożenia o tym samym kluczu; po przyjętym tylko korekta z `supersedes_id` | złożenia | prośba | [data-model.md](data-model.md) |
| klucz publiczny MF celu ważny | JPK | wysyłka | [operations.md](operations.md) |
| dokładnie jeden odbiorca z bazy; każda wartość z `flagged_values` potwierdzona osobno | szkice | decyzja | [modules/czat.md](modules/czat.md) |

## Podgląd w panelu

Panel pokazuje `summary` zapisane przy prośbie, więc widzisz dokładnie to, co wiąże hash. Obok jest początek `payload_sha256` i czas do wygaśnięcia.

| Przedmiot | Podgląd |
| --- | --- |
| `sales_invoice` | nabywca z `buyer_snapshot` i wynik weryfikacji z wiekiem; scenariusz i P_12; P_1, P_2, P_6 albo OkresFa; pozycje; netto, VAT i brutto w walucie oraz w PLN z kursem i numerem tabeli; wizualizacja z dokładnego XML |
| `correction_invoice` | jak wyżej oraz faktura korygowana, różnica ze znakiem i przyczyna |
| `filing` JPK | okres i cel złożenia; sprzedaż netto, VAT należny i naliczony, liczba dokumentów; wynik deklaracji; różnica wobec poprzedniego złożenia; lista kontrolna okresu |
| `filing` VAT-UE | nabywcy z numerem VAT UE i kwotą w PLN; suma i porównanie z K_12 |
| `draft` | odbiorca z bazy; treść z wyróżnionym IBAN, NRB, NIP i kwotą niezgodnymi z bazą; lista źródeł nieufnych |

## Decyzja

- Klikasz „Zatwierdź” i potwierdzasz passkeyem (step-up od F0b). Identyfikator passkeya trafia do `step_up_credential_id`, a wpis `approval.approved` do `audit_log`.
- Bramka ponownie sprawdza warunki z kolumną „decyzja”. Przejście `pending` → `approved` jest warunkowe, więc drugie kliknięcie nic nie zmienia.
- Decyzja ustawia `expires_at` według tabeli niżej. Przy fakturze blokuje też P_2 (`invoice_number_locked_at`).
- „Anuluj” działa do chwili `dispatching` i zapisuje `cancel_reason` = `user`.
- Sync Subject1 może przynieść fakturę wystawioną poza kokpitem, o tym samym `kind` i P_2 co lokalny szkic. Wtedy anuluje żywe zatwierdzenie `pending` albo `approved` z `cancel_reason` = `manual_invoice`, a szkic przechodzi do `cancelled_before_send` ([modules/ksef.md](modules/ksef.md)).

## Wygaśnięcie

Zatwierdzenie to zgoda na wysyłkę teraz, nie kiedyś. Wartości dla `filing` i `draft` to propozycja kokpitu w konfiguracji aplikacji. Granica dla faktur wynika z reguły P_1 (D-16, [modules/ksef.md](modules/ksef.md)).

| Przedmiot | `pending` | po zatwierdzeniu | Powód |
| --- | --- | --- | --- |
| `sales_invoice`, `correction_invoice` | koniec dnia P_1 w Europe/Warsaw | koniec dnia P_1 | zmiana dnia wymusza nowy XML z nowym P_1 i nowym hashem (D-16) |
| `filing` | 7 dni | 24 h od decyzji | dane okresu mogą się zmienić; wysyłka po awarii wymaga świeżej decyzji |
| `draft` | 30 dni | 24 h od decyzji | szkic traci aktualność |

`approval.expire` wygasza zatwierdzenia po `expires_at`, przy fakturach o północy w Europe/Warsaw. Encja wraca według tabeli „Powiązanie z bramką zatwierdzeń” w [data-model.md](data-model.md); faktura do `ready` bez żywego zatwierdzenia.

- System nie tworzy nowej prośby, pliku `xml_sent` ani push (przegląd S2). Inaczej każdy niezatwierdzony szkic dawałby co noc nowy plik i nowe powiadomienie.
- Nową prośbę tworzysz akcją „Odnów” w panelu. Przy fakturze generator buduje XML z dzisiejszym P_1 i nowym sha256.
- Wygasłe `approved` oznacza zatwierdzoną wysyłkę, która nie wyszła. Daje push `connector_failure`.
- Faktura w `ready` bez żywego zatwierdzenia przypomina się przez obowiązek `invoice_issue` ([compliance-calendar.md](compliance-calendar.md)).

## Stany zatwierdzenia

Ta sekcja to dom przejść `approval.status`.

| Z | Do | Wyzwalacz | Aktor | Wpis `audit_log` |
| --- | --- | --- | --- | --- |
| – | `pending` | faktura albo złożenie w `ready`; prośba zastępcza po zmianie źródła; szkic: utworzenie albo edycja ([modules/czat.md](modules/czat.md)); „Odnów” po wygaśnięciu; payload zapisany, warunki prośby spełnione | system; Ty przy „Odnów” | `approval.requested` |
| `pending` | `approved` | decyzja ze step-up; warunki decyzji spełnione | Ty | `approval.approved` |
| `pending`, `approved` | `superseded` | zmiana źródła, także wykryta przez workera przy wysyłce; nowe zatwierdzenie | system | `approval.superseded` |
| `pending`, `approved` | `expired` | minął `expires_at` przed wysyłką, także przy fakturze ze zmianą dnia P_1; bez nowej prośby (przegląd S2) | system | `approval.expired` |
| `pending`, `approved` | `cancelled` | anulowanie w panelu (`cancel_reason` = `user`) albo wgranie UPO złożenia poza kokpitem (`cancel_reason` = `manual_filing`) | Ty | `approval.cancelled` |
| `pending`, `approved` | `cancelled` | sync Subject1 przynosi fakturę o tym samym `kind` i P_2 co szkic; `cancel_reason` = `manual_invoice`; szkic → `cancelled_before_send` ([modules/ksef.md](modules/ksef.md)) | system | `approval.cancelled` |
| `approved` | `cancelled` | niezgodny hash payloadu; `cancel_reason` = `hash_mismatch`, alert; encja wraca według tabeli „Powiązanie z bramką zatwierdzeń” w [data-model.md](data-model.md) (faktura `ready`, złożenie `draft`, szkic `pending`); nowe zatwierdzenie po wyjaśnieniu alertu | system | `dispatch.hash_mismatch` |
| `approved` | `dispatching` | worker zablokował wiersz; hash, termin i warunki wysyłki zgodne | system | `dispatch.started` |
| `approved` | `accepted` | `draft` tylko do kopiowania (Q-35): Twoje kliknięcie „Skopiowano”; szkic → `executed` | system | `draft.executed` |
| `approved` | `unknown` | odtworzenie z backupu: zatwierdzenie mogło wyjść po zrzucie; krok 4 runbooka „Odtworzenie po awarii” w [operations.md](operations.md); encja też → `unknown`; rozstrzyga tylko `SubmissionReconciler` | Ty | `approval.marked_unknown` |
| `dispatching` | `submitted` | system urzędu przyjął żądanie; numer referencyjny w `external_refs`; JPK dopiero po udanym `FinishUpload` | system | `dispatch.submitted` |
| `dispatching` | `rejected` | odmowa przed nadaniem numeru referencyjnego, poza duplikatem, np. błąd walidacji | system | `dispatch.rejected` |
| `dispatching` | `accepted` | duplikat przy wysyłce z referencją oryginału (JPK 170); UPO oryginału | system | `dispatch.accepted` |
| `dispatching` | `unknown` | timeout albo błąd sieci; awaria workera: `approval.reconcile` znajduje `dispatching` starsze niż 10 min, potem zwykła rekoncyliacja | system | `dispatch.unknown` |
| `submitted` | `accepted` | status końcowy pozytywny z UPO albo duplikat oryginału (KSeF 440 ze zgodnym `invoiceHash`, JPK 407); UPO i numer oryginału | system | `dispatch.accepted` |
| `submitted` | `rejected` | status końcowy negatywny | system | `dispatch.rejected` |
| `submitted` | `unknown` | brak statusu końcowego w oknie odpytywania (per konektor, z konfiguracji aplikacji); JPK 411 (identyczny dokument bez numeru oryginału) albo 300 po `FinishUpload`; e-Deklaracje 411 i 300; rekoncyliacja po sha256 ([modules/jpk.md](modules/jpk.md)) | system | `dispatch.unknown` |
| `unknown` | `accepted`, `rejected` | `SubmissionReconciler` ustalił wynik, np. JPK 300 bez potwierdzonego `FinishUpload` → `rejected` | system | `reconcile.resolved` |
| `unknown` | `cancelled` | wgranie UPO złożenia, gdy rekoncyliacja nie ustaliła wyniku, np. po JPK 411; `cancel_reason` = `manual_filing` | Ty | `approval.cancelled` |
| `unknown` | `superseded` | ręczne ponowienie po rekoncyliacji; nowe zatwierdzenie | Ty | `approval.retry_requested` |

Stany końcowe: `accepted`, `rejected`, `expired`, `superseded`, `cancelled`. Każde przejście zapisuje w `audit_log` dokładnie jeden wpis z ostatniej kolumny ([security.md](security.md)). Wpis złożenia `filing.manual_recorded` przy wgraniu UPO jest poza tym licznikiem.

## Wysyłka

Wiadomość `official_writes` niesie tylko `approval_id`. Handler jest idempotentny, więc duplikat wiadomości, podwójne kliknięcie i drugi worker kończą się bez skutku.

1. Transakcja: `SELECT … FOR UPDATE` na wierszu `approval`. Stan inny niż `approved` kończy handler bez skutku. Minione `expires_at`: handler ustawia `expired` (wpis `approval.expired`) bez wywołań API i bez nowej prośby; push `connector_failure` („Wygaśnięcie”).
2. `FileStore` czyta payload; worker liczy sha256 i porównuje z `payload_sha256`. Niezgodność: `cancelled` z `cancel_reason` = `hash_mismatch`, wpis `dispatch.hash_mismatch`, push.
3. Warunki z kolumną „wysyłka”. Skutek niespełnionego warunku zależy od jego rodzaju:
   - przejściowe (limit, `blocked_until`, Latarnia, poświadczenie, klucz MF, cel i włączenie konektora): stan zostaje `approved`, a `approval.dispatch_waiting` spróbuje później;
   - zmiana źródła (`version` encji różne od `source_version`): `superseded` i nowa prośba;
   - inny dzień P_1: jak minione `expires_at` w kroku 1, czyli `expired` i push; nową prośbę z dzisiejszym P_1 tworzysz „Odnów” ([modules/ksef.md](modules/ksef.md)).

   Żaden z tych skutków nie jest ponowieniem, bo nic nie wyszło do urzędu.
4. Stan `dispatching` z `dispatching_at` i zatwierdzenie transakcji. Od tej chwili wynik ustala tylko odpowiedź albo rekoncyliacja.
5. Kroki API. Po każdym kroku krótka transakcja zapisuje `external_refs`. Każde wywołanie niesie `approval_id` i `correlation_id` w `connector_call`.
6. Przyjęcie żądania daje `submitted`. Timeout albo odpowiedź niejednoznaczna daje `unknown`. Odmowa walidacyjna daje `rejected` z `result_code`.
7. Odpytywanie statusu to odczyt: zadanie `approval.poll_status` na transporcie `reads`.

## Statusy i rekoncyliacja

`SubmissionReconciler` rozstrzyga `unknown` i `dispatching` starsze niż 10 minut. Używa wyłącznie zapytań odczytu; nigdy nie wysyła ponownie.

- Stare `dispatching` najpierw przechodzi do `unknown` (wiersz „awaria workera” w tabeli stanów), potem reconciler je rozstrzyga.
- Przy rekoncyliacji status w toku → stan zostaje `unknown`; `approval.reconcile` sprawdza ponownie co 10 min.
- Okno odpytywania dla `submitted` → `unknown` jest per konektor, w konfiguracji aplikacji.

| System | Statusy | Rekoncyliacja | Źródło |
| --- | --- | --- | --- |
| KSeF | weryfikacja faktury jest asynchroniczna: status faktury w sesji; sesja żyje 12 h od utworzenia; błąd 21184 oznacza otwarcie nowej sesji | 1. referencja faktury → status faktury i UPO; 2. tylko referencja sesji → lista faktur sesji i lista odrzuconych; 3. brak referencji → lista sesji z dnia wysyłki i faktury sesji, zapasowo dopasowanie z syncu Subject1 ([modules/ksef.md](modules/ksef.md)); 4. brak wyniku → stan zostaje `unknown`, panel proponuje ręczne ponowienie chronione statusem 440 | [weryfikacja faktury](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/weryfikacja-faktury.md), [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md), [open-api](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json) |
| KSeF, ponowienie | duplikat klucza NIP, RodzajFaktury i P_2 daje status 440 z `originalKsefNumber` i `originalSessionReferenceNumber` | ręczne ponowienie po `unknown` nie wystawi drugiej faktury: `invoiceHash` oryginału zgodny z sha256 któregokolwiek wcześniej wysłanego `xml_sent` tej faktury → `accepted` z numerem oryginału; różny od wszystkich → kolizja P_2, `rejected` i alert; szczegóły w [modules/ksef.md](modules/ksef.md) | jak wyżej |
| KSeF, token | status sesji, lista odrzuconych i UPO faktury działają na tokenie z prawem wystawiania | ścieżka główna działa na `ksef_token_write`, więc przy zapisanej referencji sesji token do odczytu nie jest potrzebny; zapasowe dopasowanie z syncu Subject1 używa `ksef_token_read` ([modules/ksef.md](modules/ksef.md)) | jak wyżej |
| bramka JPK | `InitUploadSigned` zwraca `TimeoutInSec`; `FinishUpload` jest obowiązkowy, bez niego sesja przepada; `Status/{referenceNumber}`: 1xx w toku (120 weryfikacja), 200 UPO, 300 i 4xx końcowe | `submitted` dopiero po udanym `FinishUpload`; numer referencyjny znany → odpytywanie statusu; kody 170 i 407 to duplikat z numerem oryginału → pobranie UPO oryginału i `accepted`; 411 (identyczny dokument już złożony, bez numeru oryginału) → `unknown`; `SubmissionReconciler` szuka własnego złożenia o tym samym sha256 albo wgrywasz UPO jako `manual` ([modules/jpk.md](modules/jpk.md)); 300 bez potwierdzonego `FinishUpload` → `rejected` z `result_code` 300, nic nie złożono; 300 po `FinishUpload` → `unknown` i rekoncyliacja jak przy 411; brak referencji → nic nie złożono, dopuszczalne ręczne ponowienie; znana referencja bez `FinishUpload` → ponowienie dopiero, gdy odczyt `Status/{referenceNumber}` albo upływ `TimeoutInSec` potwierdzi, że sesja nie istnieje | [specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf) |
| e-Deklaracje | `sendUnsignDocument` zwraca numer referencyjny; `requestUPO` zwraca status w toku albo końcowy | numer znany → `requestUPO` do statusu końcowego; 300 albo 411 → `unknown`; `SubmissionReconciler` szuka własnego złożenia o tym samym sha256; bez wyniku wgrywasz UPO jako `manual` (jak JPK 411); brak numeru → nie ma czego odpytać; ręczne ponowienie jest dozwolone, bo duplikat wraca jako 411 albo 200 z UPO oryginału; kody i kanał w [modules/jpk.md](modules/jpk.md) (D-27) | [specyfikacja e-Deklaracji](https://podatki-arch.mf.gov.pl/media/3616/edek_specyfikacja_we-wy_2-4_test_www.pdf) |
| szkic | kopiowanie (Q-35): bez systemu zewnętrznego | Twoje kliknięcie „Skopiowano”: system przestawia zatwierdzenie `approved` → `accepted`, szkic → `executed`, wpis `draft.executed` | [modules/czat.md](modules/czat.md) |

Odrzucona faktura nie została wystawiona. Poprawiasz ją i zatwierdzasz ponownie, bez KOR ([modules/ksef.md](modules/ksef.md)).

## Ręczne ponowienie

- Tylko z panelu, ze step-up, jako nowe zatwierdzenie z bieżącym payloadem. Stare przechodzi do `superseded`, wpis `approval.retry_requested`.
- Dozwolone, gdy rekoncyliacja potwierdziła, że nic nie dotarło do systemu.
- Albo gdy duplikat chroni przed drugim dokumentem: KSeF 440; JPK 170, 407, 411; e-Deklaracje 411 i 200 z UPO oryginału ([modules/jpk.md](modules/jpk.md)).
- Przy JPK ze znanym numerem referencyjnym i wysłanym `FinishUpload` ponowienia nie ma; zostaje odpytywanie statusu.
- Bez `FinishUpload` sesja przepada. Ręczne ponowienie jest dozwolone dopiero, gdy odczyt `Status/{referenceNumber}` albo upływ `TimeoutInSec` potwierdzi, że sesja nie istnieje.
- `messenger:failed:retry` nie ponawia zapisów urzędowych ([operations.md](operations.md)).

## „Odbierz” (odłożone, D-29)

Odbiór przez API jest osobną akcją użytkownika, poza bramką zatwierdzeń. Odłożone reguły F4, w tym step-up i test odbioru wyłącznie z panelu, są w [modules/e-doreczenia.md](modules/e-doreczenia.md); warunek powrotu w [roadmap.md](roadmap.md). Do tego czasu pisma odbierasz ręcznie w aplikacji e-Doręczeń, a termin z pisma wpisujesz do kalendarza ([compliance-calendar.md](compliance-calendar.md)).

## Powiadomienia i eskalacja

- Nowa prośba systemowa: push kategorii `approval_pending` z liczbą oczekujących i linkiem. Prośba z Twojej akcji w panelu i wygaśnięcie `pending` push nie wysyłają (przegląd S2).
- Prośba czekająca 24 h: przypomnienie. W dniu terminu powiązanego obowiązku (np. wystawienie faktury do 15., JPK do 25.) przypomnienie rano i po południu.
- Stan `unknown` starszy niż 1 h, `cancelled` z `hash_mismatch` oraz wygasłe `approved`: push kategorii `connector_failure`.
- Terminy przypomnień i deduplikacja: [compliance-calendar.md](compliance-calendar.md).

Złote przypadki bramki i kryteria przekrojowe: [testing.md](testing.md).
