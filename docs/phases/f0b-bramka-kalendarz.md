# F0b bramka i kalendarz

Szkielet bramki zatwierdzeń ze step-upem i drugim passkeyem oraz kalendarz obowiązków z przypomnieniami. Startuje po dry runie JPK_EWP(4) i JPK_ST(1) (D-30). Stan na 8 października 2026.

## Cel

Po F0b kokpit przypomina o każdym terminie ZUS i urzędu skarbowego, z datą po przesunięciu i kwotą ZUS z konfiguracji. Termin z pisma e-Doręczeń wpisujesz ręcznie do kalendarza (D-29). Bramka zatwierdzeń działa na konektorze `dummy`; prawdziwe przedmioty dochodzą w F3, F5 i F6. Nic nie wychodzi do urzędu.

| Zasada | Skutek |
| --- | --- |
| kolejność z D-30: F0a → F2.1 → F2.4 → dry run → F0b → F1 | F0b zastaje tabele `setting` i `revenue_entry` z F2.1; nie zmienia ich kodu |
| step-up i drugi passkey przechodzą z F0a do F0b (D-28) | zatwierdzenie i zmiana passkeyów wymagają świeżej asercji |
| Infection działa doradczo do powstania `src/Tax` w F2 (D-28) | raport mutacji jest dowodem, nie blokadą merge |
| dowód działania jest obowiązkowy (D-28) | faza kończy się listą `docs/evidence/f0b.md` ze wszystkimi wierszami `pass` |

## Wejścia

Brak wejścia oznaczonego „stop” zatrzymuje fazę i kończy się pytaniem. Pozostałe idą na założeniu domyślnym z [../decisions.md](../decisions.md).

| Wejście | Źródło | Brak oznacza |
| --- | --- | --- |
| F0a odebrana: `docs/evidence/f0a.md` ma wszystkie wiersze `pass`, a wiersze `[manual]` są odhaczone | [f0a-szkielet.md](f0a-szkielet.md) | stop |
| dry run zrobiony: wiersze F2.1 i F2.4 w `docs/evidence/f2.md` mają `pass` | D-30, [f2-ewidencja.md](f2-ewidencja.md) | założenie domyślne: F0b czeka na dry run; wcześniejszy start tylko na Twoją decyzję w kroku 0, bo przesuwa 15.12.2026 |
| mikrorachunek podatkowy i rachunek składkowy ZUS | warunki wstępne w [../README.md](../README.md) | stop dla go-live F0b; kod i testy na wartościach fikcyjnych w `kokpit-dev` |
| wariant składek ZUS, próg składki zdrowotnej | Q-16 | założenie domyślne: pełne składki, próg z przychodu bieżącego roku |
| zaliczka co miesiąc czy co kwartał | Q-17 | założenie domyślne: co miesiąc |
| prywatny ADE, zgoda na e-Korespondencję | Q-13 | założenie domyślne: cotygodniowy przegląd ręczny prywatnego ADE, konta e-US i eZUS |
| zastępca, administrator skrzynki ADE, UPL-1 | Q-18, D-03 | założenie domyślne: administrator skrzynki wyznaczony; poza skrzynką zastępcy brak, przypomnienia tylko do Ciebie |
| metoda kasowa i forma opodatkowania 2027 | Q-24, Q-38 | przypomnienie `tax_form_choice` na 20.02.2027, bez decyzji ([ustawa o PIT, art. 14c ust. 1 pkt 3, Dz.U. 2026 poz. 592](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf)) |
| klasyfikacja WHT dostawców | Q-21 | przypomnienia WHT ogólne, bez kwot |
| e-Doręczenia bez API | D-29, Q-30 | – (termin z pisma wpisujesz ręcznie; `edelivery_deemed` nie powstaje) |
| VAT tylko miesięcznie | D-02 | – |
| P_1 i wygaśnięcie zatwierdzeń faktur | D-16 | – (szkielet przyjmuje wygaśnięcie per `subject_type` i stan z konfiguracji) |
| `approval.connector` przy szkicu tylko do kopiowania | Q-35, [../approval-gate.md](../approval-gate.md) | założenie domyślne: `connector` nullable tylko dla `subject_type` = `draft` (`CHECK` w [../data-model.md](../data-model.md)) |
| konektor testowy `dummy` | D-32 | – |

**Poza fazą.** ADE aktywny, powiadomienia mailowe, administrator skrzynki i bramki 1–2 z Q-30 to działania poza kodem. F0b ich nie zastępuje.

## Ładuj tylko

Ta lista jest autorytatywna. [../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md) ładuje się sam.

| Plik | Sekcje |
| --- | --- |
| [../README.md](../README.md) | cały (start sesji), zwłaszcza „Status faz” i „Odłożone” |
| [../naming.md](../naming.md) | cały |
| [../decisions.md](../decisions.md) | wiersze z F0b w kolumnie „Potrzebne w”; D-02, D-03, D-16, D-28, D-29, D-30, D-32, D-33 oraz Q-13, Q-16, Q-17, Q-18, Q-21, Q-24, Q-35, Q-38 |
| [../facts.md](../facts.md) | fakty oznaczone dla F0b |
| [../approval-gate.md](../approval-gate.md) | cały |
| [../compliance-calendar.md](../compliance-calendar.md) | sekcje F0b z jego „Jak czytać” |
| [../modules/e-doreczenia.md](../modules/e-doreczenia.md) | „Obowiązek już biegnie”, „Kanały doręczeń” |
| [../data-model.md](../data-model.md) | „Jak czytać”, „Konwencje”, „Kwoty i zaokrąglenia”, „Daty i strefa czasu”, „Środowiska”, „Mapa tabel”; tabele `approval`, `obligation`, `notification`, `setting`, `credential_state`, `webauthn_credential`; „Powiązanie z bramką zatwierdzeń” |
| [../tax-rules.md](../tax-rules.md) | „Składki ZUS”, „Parametry” |
| [../security.md](../security.md) | „Stany poświadczeń”, „Panel” (passkey i step-up), „`audit_log` i `connector_call`” (akcje), „Powiadomienia” |
| [../operations.md](../operations.md) | „Wdrożenie” (krok 3), „Messenger”, „Katalog zadań” (zadania F0b), „Monitoring” |
| [../testing.md](../testing.md) | cały (plan i koniec fazy) |
| [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md) | „Kontrakt konektora” |

## Zakres

Granica F0a i F0b pochodzi z D-28 i tabeli „Status faz” w [../README.md](../README.md). F0b to step-up z drugim passkeyem, szkielet bramki, `PolishCalendar`, `ObligationEngine`, obowiązki z datami i kwotami z konfiguracji, przypomnienia bez duplikatów i panel `setting`.

**Passkey i step-up.** Dom: „Panel” w [../security.md](../security.md).

| Element | Co powstaje |
| --- | --- |
| `StepUpVerifier` | świeża asercja WebAuthn z `userVerification=required`, nie starsza niż 5 minut; użyty passkey trafia do `approval.step_up_credential_id` albo do szczegółów wpisu; wpis `auth.step_up`; w `when@test` wersja z wirtualnym authenticatorem |
| drugi passkey | dodanie i unieważnienie passkeya w panelu tylko ze step-upem; wpisy `auth.passkey_enrolled` i `auth.passkey_revoked`; ostrzeżenie w panelu przy jednym passkeyu |
| odzyskanie | `app:passkey:reset` z F0a bez zmian; po resecie ostrzeżenie trwa do rejestracji drugiego passkeya |

**Bramka zatwierdzeń (szkielet).** Dom: [../approval-gate.md](../approval-gate.md).

| Element | Co powstaje |
| --- | --- |
| tabela `approval` | kolumny z [../data-model.md](../data-model.md); jedno żywe zatwierdzenie na encję; pola payloadu niezmienne od zapisu |
| prośba | payload przez `FileStore`, `payload_sha256`, `source_version`, `summary`, wynik `preconditions`; poprzednie `pending` albo `approved` dostaje `superseded`; przy `dispatching`, `submitted` i `unknown` nowa prośba nie powstaje (wyjątek: ręczne ponowienie) |
| decyzja | „Zatwierdź” ze step-upem przez `StepUpVerifier`; „Anuluj” do chwili `dispatching` |
| worker `official_writes` | `SELECT … FOR UPDATE`, ponowny sha256, `cancelled` z `hash_mismatch`, `dispatching`, zapis `external_refs` po każdym kroku; zadanie `approval.dispatch_waiting` dla `approved` z niespełnionym warunkiem wysyłki |
| wygaśnięcie | `expires_at` per `subject_type` i stan z konfiguracji; `approval.expire` co 5 min i o północy |
| rekoncyliacja | `SubmissionReconciler` z interfejsem per system, tylko odczyt; zadania `approval.poll_status`, `approval.reconcile` |
| ręczne ponowienie | tylko z panelu, ze step-upem, jako nowe zatwierdzenie |
| panel | kolejka z podglądem `summary`, początkiem hasha i czasem do wygaśnięcia |
| powiadomienia | `approval_pending`, przypomnienie po 24 h, push `connector_failure` przy `unknown` starszym niż 1 h i przy `hash_mismatch`; eskalację w dniu terminu obowiązku dokładają przedmioty w F3 i F5 |
| log | wpisy `approval.*`, `dispatch.*` i `reconcile.resolved` w `audit_log` |
| wdrożenie i monitoring | krok 3 `make deploy` (stop przy `dispatching`) i sprawdzenie `unknown` w `/health` działają od F0b ([../operations.md](../operations.md)) |
| test architektury | metody zapisujące konektorów wołane tylko z workera bramki |

**`dummy` jako przedmiot do F3.** `approval.subject_id` to uuid bez klucza obcego, więc konektor `dummy` zastępuje przedmiot i wysyłkę. Rejestruje dwa istniejące `subject_type`:

| `subject_type` | `connector` | Payload (`stored_file`) | Przejścia |
| --- | --- | --- | --- |
| `filing` | `dummy` | `xml_sent` | wysyłka, statusy, odmowa, duplikat, timeout; `manual_filing` przez testowe wgranie UPO |
| `draft` | NULL | `outbound_payload` | „Skopiowano”, wpis `draft.executed` |

- Skutki po stronie encji (złożenie `manual`, szkic `executed`) symuluje adapter `dummy`, bo `subject_id` nie ma FK. Prawdziwe mapowania dochodzą w F5 i F6.
- `dummy` liczy wywołania zapisujące i nie wychodzi poza proces. Host `dummy.invalid` obsługuje `MockHttpClient` za `HostAllowlist`.
- Warunek „konektor włączony” `dummy` spełnia własną flagą konfiguracji. Przypadek wyłączonego konektora testuje `approval.dispatch_waiting`.
- Usługi `dummy` są tylko w `when@test` i `when@dev`; nigdy przy `APP_ENV=prod` (D-32).
- Fixtures dev tworzą zatwierdzenie `filing` z `dummy` w `pending`. Na `kokpit-dev` zatwierdzasz je passkeyem w panelu.

Prawdziwe przedmioty dochodzą później: faktury w F3, złożenia w F5, szkice w F6. Każdy dokłada generator payloadu, warunki wstępne, wysyłkę i eskalację w dniu terminu. `edelivery_outbound` jest odłożony (D-29).

**Kalendarz.** Dom: [../compliance-calendar.md](../compliance-calendar.md).

| Element | Co powstaje |
| --- | --- |
| `PolishCalendar` | święta stałe i ruchome, 24 grudnia, niedziele; polityki `op12`, `zus`, `none`; `isWorkingDay` i `previousWorkingDay` bez sobót, niedziel i świąt, potrzebne `FxRateProvider` w F1 ([../modules/rejestry-kursy.md](../modules/rejestry-kursy.md)) |
| `ObligationEngine` | reguły z typem, datą bazową, polityką przesunięcia i źródłem prawnym; 13 miesięcy w przód; upsert po `dedupe_key`; `nominal_due_date` i `due_date`; przeliczenie obowiązków `open` i systemowych `not_applicable` (pusty `done_by`), `done` bez zmian; zadanie `obligations.recompute` |
| `obligation` | stany `open`, `done`, `not_applicable`; zaległość liczona z daty, nie zapisywana |
| obowiązki od F0b | typy z „Od fazy” F0b w „Tabeli obowiązków”: `zus` z kwotą z konfiguracji (reguła „Kwota `zus`” niżej), `ryczalt_advance` bez kwoty, `vat_jpk_v7m`, `vat_ue`, `invoice_issue`, `ledger_entry`, `wht_payment`, `ift_2r`, `cit_10z`, `jpk_ewp`, `jpk_st` za flagą, `pit_28`, `health_reconciliation`, `tax_form_choice`, `credential_expiry`, `manual_channel_check` |
| termin z pisma | „Dodaj termin z pisma” w panelu: ręczny obowiązek `edelivery_reply_due` według [../compliance-calendar.md](../compliance-calendar.md); opis w aktywnej od F0b kolumnie `obligation.note` według [../data-model.md](../data-model.md); API pozostaje odłożone (D-29) |
| `setting` w panelu | tabela, odczyt i minimalny formularz z F2.1; klucze kalendarza i przeliczenie po zmianie z `valid_from` i `legal_source`, wpis `setting.changed`; klucze od F0b: `company.tax_micro_account`, `company.zus_account`, `zus.parameters` na 2026, `zus.variant`, `ryczalt.advance_frequency`; klucze zapisane w F2.1 zostają bez zmian |
| wartości domyślne `setting` | bez danych osobowych (`ryczalt.advance_frequency` = `monthly`, `zus.variant` = `full`; „Parametry” w [../tax-rules.md](../tax-rules.md)) wstawia migracja; `jpk_st.enabled` pozostaje z F2.1; fikcyjne rachunki i `zus.parameters` tylko w fixtures dev i test; brak ustawienia: obowiązek bez `amount_due` i `payment_title`, panel pokazuje „brak ustawienia”, silnik nie zgaduje |
| `notification` | offsety z „Przypomnień”, wysyłka o 8:00, push i mail, `dedupe_key`; zadanie `notifications.dispatch` z pingiem dead-man po udanym przebiegu; treść bez kwot i nazw; push `connector_failure` z F0a przechodzi na tę tabelę |
| tytuły przelewów | `obligation.payment_title` z szablonów; symbole do weryfikacji oznaczone w panelu |
| poświadczenia | `credential_expiry` z `credential_state.valid_until`, przypomnienia T−30, T−7, T−1, dla `auth_data` tylko T0 (2 stycznia); zadanie `credentials.check_expiry` |
| kanały doręczeń | `manual_channel_check` co poniedziałek, osobno dla prywatnego ADE, konta e-US i eZUS; sprawdzenie sald po 25. dniu, podmiot `balance_check` |
| panel | kalendarz z datą nominalną i po przesunięciu, kwotą, tytułem przelewu i źródłem prawnym z reguły `rule_key`; wpis zapłaty (`amount_paid`, `paid_on`) zamyka obowiązek; „Potwierdź wykonanie” obowiązku bez `amount_due` (`open` → `done`, `done_by`, wpis `obligation.completed`); „Nie dotyczy” z powodem (`obligation.marked_not_applicable`) |

**Kwota `zus`.** `amount_due` to składki społeczne z `zus.parameters` i `zus.variant` plus zdrowotna z progu wybranego ręcznie w `zus.parameters`. Tak zostaje do części F2 z kwotami zaliczki i ZUS ([f2-ewidencja.md](f2-ewidencja.md)). Potem próg liczy [../tax-rules.md](../tax-rules.md) z `revenue_entry`.

- Silnik czyta oba klucze według `setting` obowiązującego w `period_start` obowiązku (miesiąc składkowy), nie w dniu terminu.
- Składki za 12.2026 z terminem 20.01.2027 biorą więc parametry 2026, nawet gdy jest już wiersz 2027.
- Brak wiersza `zus.parameters` z `valid_from` w roku `period_start`: `amount_due` = NULL, panel pokazuje „brak parametrów ZUS na rok”.
- Silnik nie przenosi kwot z poprzedniego roku. Podstawa: wiersz „Parametry ZUS na 2027” w „Warunkach wstępnych” w [../README.md](../README.md).

**Testy i dowód.** Joby CI i `kokpit:evidence` powstały w F0a. Reguły CI i dowodu są w [../testing.md](../testing.md). F0b dokłada testy niżej.

| Element | Co powstaje |
| --- | --- |
| złote przypadki kalendarza | jeden przypadek na każdy wiersz tabel kalendarza i terminów z „Czasu, dat i kursów” w [../testing.md](../testing.md): święta 2026 i 2027, `previousWorkingDay`, terminy z `due_date` |
| testy własności (testsuite `property`) | właściwości „przesunięcie” i „`previousWorkingDay`, dzień biznesowy” z „Testów własności” w [../testing.md](../testing.md), z generatorem dat i ziarnem w wyniku; dodatkowo Wielkanoc to niedziela od 22.03 do 25.04, a Boże Ciało to czwartek |
| testy przejść bramki | jeden przypadek na każde przejście z „Stanów zatwierdzenia” w [../approval-gate.md](../approval-gate.md), na `dummy` jako `filing` albo `draft`, z wpisem w `audit_log`; `dummy` symuluje przyjęcie, odmowę, duplikat i timeout; para (z, do) spoza tabeli kończy się odmową |
| obejście bramki (testsuite `security`) | metoda zapisująca `dummy` poza workerem bramki; wiadomość `official_writes` dla zatwierdzenia w stanie innym niż `approved` |
| mutacje | job `mutation` obejmuje `PolishCalendar` i `ApprovalGate` z progami z „Testów mutacyjnych” w [../testing.md](../testing.md); wynik doradczy do powstania `src/Tax` (D-28) |

## Poza zakresem

- Prawdziwe przedmioty bramki i ich wysyłka: faktury w zaślepce F3, JPK i VAT-UE w [f5-jpk.md](f5-jpk.md), szkice w zaślepce F6 ([../README.md](../README.md)).
- Kwoty zaliczki, progu składki zdrowotnej i rozliczenia rocznego, bo wymagają ewidencji: [f2-ewidencja.md](f2-ewidencja.md).
- `period`, zamknięcie miesiąca, blokada okresu i bilans otwarcia (klucze `opening.*` w `setting`): [f2-ewidencja.md](f2-ewidencja.md).
- Wykrywanie miesięcy ze sprzedażą `eu_b2b` i WHT per dostawca: [f1-ksef-odczyt.md](f1-ksef-odczyt.md).
- API e-Doręczeń: `edelivery_deemed`, „Odbierz”, `edelivery_new`, `edelivery_outbound`. Odłożone (D-29): [../modules/e-doreczenia.md](../modules/e-doreczenia.md) i [../roadmap.md](../roadmap.md).
- PWA i Web Push: odłożone (D-28), [../roadmap.md](../roadmap.md).
- Odczyt maili z powiadomieniami do kalendarza: poza zakresem projektu ([../modules/e-doreczenia.md](../modules/e-doreczenia.md)).

## Kryteria odbioru

Komendy `bin/console` i `vendor/bin` uruchamiasz w kontenerze `app` według [../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md). PHPUnit działa w jądrze `APP_ENV=test`. Wiersze niżej są tylko dla F0b; step-up przy zatwierdzeniu, hash payloadu, podwójna wysyłka, wygaśnięcie i przesunięcia terminów są w kryteriach przekrojowych.

Klucze, wykonawcy, wyniki i zapis dowodów działają jak w „Kryteriach odbioru” w [f0a-szkielet.md](f0a-szkielet.md) i według [../naming.md](../naming.md) (D-33). Pliki kroków `[manual]` kładziesz w `var/evidence/f0b/inbox/` z nazwą od klucza.

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `previous-working-day` poprzedni dzień roboczy | `ci:` `vendor/bin/phpunit --filter PolishCalendar` | `previousWorkingDay` dla 29.12.2025 zwraca 23.12.2025, a dla 4.01.2027 zwraca 31.12.2026 | `junit.xml`: `PolishCalendar*` |
| `calendar-golden` złote przypadki kalendarza | `ci:` `vendor/bin/phpunit --filter PolishCalendarGolden` | każdy wiersz tabel kalendarza i terminów z „Czasu, dat i kursów” w [../testing.md](../testing.md) ma przypadek; każdy `due_date` zgodny z tabelą | `junit.xml`: `PolishCalendarGolden*`, nazwa przypadku to data wejściowa; `cmd/calendar-golden.txt`: data, polityka, wynik |
| `calendar-property` własności kalendarza | `ci:` `vendor/bin/phpunit --testsuite property --filter PolishCalendar` | własności z wiersza „testy własności” w „Testach i dowodzie” spełnione dla wszystkich losowych dat | `junit.xml`: `PolishCalendarProperty*`; `cmd/calendar-property.txt`: ziarno i liczba prób |
| `obligation-engine` silnik obowiązków | `ci:` `vendor/bin/phpunit --filter ObligationEngine` | dwa przebiegi z tym samym `MockClock` dają tę samą liczbę wierszy; obowiązki na 13 miesięcy; każdy ma `nominal_due_date`, `due_date` i `rule_key` ze źródłem prawnym; przy `jpk_st.enabled` = false `jpk_st` ma `not_applicable` | `junit.xml`: `ObligationEngine*` |
| `zus-amount` kwota ZUS | `ci:` `vendor/bin/phpunit --filter ObligationEngine`: obowiązek `zus` | `amount_due` według `setting` z `period_start`; tytuł z rachunkiem składkowym; składki za 12.2026 (termin 20.01.2027, wiersz 2027 od 1.01.2027) z parametrów 2026; za 01.2027 bez wiersza 2027: `amount_due` puste i „brak parametrów ZUS na rok” | `junit.xml`: `ObligationEngine*` |
| `advance-title` tytuł zaliczki | `ci:` `vendor/bin/phpunit --filter ObligationEngine`: obowiązek `ryczalt_advance` | tytuł z symbolem PPE i okresem; `amount_due` puste do F2 | `junit.xml`: `ObligationEngine*` |
| `reminder-dedupe` przypomnienia bez duplikatów | `ci:` test: `MockClock` na T−3, 8:00, dwa przebiegi `notifications.dispatch` | jeden push na obowiązek; treść bez kwot i nazw | `junit.xml`: `NotificationDispatch*` |
| `obligation-confirm` potwierdzenie czynności | `ci:` test: „Potwierdź wykonanie” obowiązku `manual_channel_check` | stan `done`, ustawione `done_by`, wpis `obligation.completed`; zero nowych wierszy `notification` | `junit.xml`: `ObligationConfirm*` |
| `credential-expiry` wygasające poświadczenie | `ci:` test: `MockClock` na T; `app:credential:register dummy_token --valid-until=<T+31 dni>`; `obligations.recompute`; `MockClock` T+1 i `credentials.check_expiry`; potem T−30, T−7 i T−1 o 8:00 i `notifications.dispatch` | po rejestracji `valid` i obowiązek `credential_expiry`; po zadaniu `expiring`; przypomnienie push i mail przy każdym offsecie | `junit.xml`: `CredentialExpiry*`; `cmd/credential-expiry.txt`: wyjście rejestracji i stan po zadaniu |
| `channel-check` przegląd kanałów i sald | `ci:` test: `MockClock` na poniedziałek 12.10.2026, potem na 26.11.2026; `obligations.recompute` | trzy wiersze `manual_channel_check` z 2026-10-12 w `dedupe_key`: `ade_private`, `e_us`, `ezus`; wiersz `manual_channel_check:2026-11-01:balance_check` z `nominal_due_date` po 25.11.2026 według [../compliance-calendar.md](../compliance-calendar.md) | `junit.xml`: `ManualChannelCheck*` |
| `letter-deadline` termin z pisma | `ci:` WebTestCase: „Dodaj termin z pisma” z datą i fikcyjnym opisem; ponowne wysłanie formularza; `obligations.recompute` | jeden obowiązek `edelivery_reply_due` w stanie `open`, `due_date` = data z pisma, opis w `obligation.note`; wpis `edelivery.deadline_confirmed`; przeliczenie bez zmian; bez tabel API e-Doręczeń | `junit.xml`: `LetterDeadline*` |
| `passkey-step-up` step-up i drugi passkey | `ci:` WebTestCase z wirtualnym authenticatorem: dodanie drugiego passkeya raz bez świeżej asercji, raz z nią; unieważnienie passkeya; panel z jednym passkeyem | bez asercji odmowa; z asercją passkey dodany, wpisy `auth.step_up` i `auth.passkey_enrolled`; unieważnienie daje `auth.passkey_revoked`; przy jednym passkeyu ostrzeżenie | `junit.xml`: `PasskeyStepUp*` |
| `gate-happy-path` ścieżka bramki | `ci:` `vendor/bin/phpunit --filter ApprovalGate` z `dummy` jako `filing` i testowym `StepUpVerifier` | `pending` → `approved` → `dispatching` → `submitted` → `accepted`; każde przejście ma wpis w `audit_log` | `junit.xml`: `ApprovalGate*` |
| `gate-transitions` przejścia bramki | `ci:` `vendor/bin/phpunit --filter ApprovalGateTransitions` | każde przejście z „Stanów zatwierdzenia” ma przypadek na `dummy` jako `filing` albo `draft`, a wynikiem jest stan z tabeli i jeden wpis `approval.*`, `dispatch.*`, `reconcile.resolved` albo `draft.executed`; każda para spoza tabeli, także ze stanu końcowego: wyjątek, stan bez zmian, zero wpisów i zero wywołań zapisujących | `junit.xml`: `ApprovalGateTransitions*`, nazwa przypadku „z → do” |
| `gate-supersede` druga prośba | `ci:` `vendor/bin/phpunit --filter ApprovalGate`: druga prośba przy `pending` albo `approved`, potem przy `dispatching`, `submitted` albo `unknown` | najpierw poprzednie zatwierdzenie `superseded`, a bezpośredni `INSERT` drugiego żywego wiersza daje błąd unikalności; potem odmowa bez nowego wiersza `approval` | `junit.xml`: `ApprovalGate*` |
| `gate-timeout` timeout i rekoncyliacja | `ci:` `vendor/bin/phpunit --filter ApprovalGate`: `dummy` zwraca timeout | stan `unknown`; `SubmissionReconciler` rozstrzyga tylko odczytem; wpis `reconcile.resolved`; zero ponownych wywołań zapisujących | `junit.xml`: `ApprovalGate*` |
| `gate-bypass` obejście bramki | `ci:` `vendor/bin/phpunit --testsuite security --filter ApprovalGate` | metoda zapisująca `dummy` poza workerem bramki rzuca wyjątek; wiadomość `official_writes` dla stanu innego niż `approved` kończy się bez skutku; zero wywołań zapisujących | `junit.xml`: `ApprovalGate*` z testsuite `security` |
| `failed-retry` brak ponowienia zapisu | `ci:` test: `CommandTester` uruchamia `messenger:failed:retry` na wiadomości z `official_writes` | brak skutku; zero nowych wywołań zapisujących | `junit.xml`: `FailedRetry*`; `cmd/failed-retry.txt`: wyjście komendy i licznik wywołań `dummy` |
| `payload-immutable` niezmienny payload | `ci:` `vendor/bin/phpunit --filter ApprovalGate`: zmiana pola payloadu po zapisie prośby | odmowa | `junit.xml`: `ApprovalGate*` |
| `setting-change` zmiana parametrów | `[manual]` zmiana `zus.parameters` z nowym `valid_from` w panelu `kokpit-dev` | przeliczone obowiązki `open` i systemowe `not_applicable` z `period_start` od tej daty; `done` i Twoje `not_applicable` bez zmian; wpis `setting.changed` | `inbox/setting-change.png`: kalendarz po zmianie; `inbox/setting-change.txt`: wpis |
| `zus-payment` zapłata ZUS | `[manual]` wpis zapłaty ZUS w panelu `kokpit-dev` | obowiązek `done`; przypomnienia ustają | `inbox/zus-payment.png`: obowiązek `done`; `inbox/zus-payment.txt`: wpis `obligation.payment_recorded` i zero wierszy `notification` po dacie zapłaty |
| `gate-panel` zatwierdzenie w panelu | `[manual]` na `kokpit-dev` z fixtures dev, `CONNECTORS_ENABLED=1` i deweloperskim tematem ntfy: push `approval_pending`, link, „Zatwierdź” passkeyem; potem `bin/console app:audit:verify` | link otwiera podgląd z `summary`, początkiem hasha i czasem do wygaśnięcia; po step-upie `dummy` kończy w `accepted` z wpisami `audit_log`; łańcuch bez błędu | `inbox/gate-panel.png`: podgląd; `inbox/gate-panel.txt`: wpisy i wynik `app:audit:verify` |
| `mutation-report` raport mutacji | job `mutation` w CI; lokalnie `vendor/bin/infection` z `infection.json5` | raport JSON Infection dla `PolishCalendar` i `ApprovalGate`; MSI i covered MSI zapisane w `docs/evidence/f0b.md`; wynik poniżej progu nie blokuje (D-28), ocalałe mutanty trafiają na listę zadań | raport JSON z artefaktu przebiegu; link do przebiegu w `docs/evidence/f0b.md` |
| `ci-green` CI zielone na PR | `[manual]` `gh run view <id> --json conclusion,headSha,jobs,url` dla przebiegu PR `f0b-bramka-kalendarz` → `main` | joby poza `live` i `mutation` zielone, także `evidence`; `mutation` na niepustym zakresie; `headSha` równy commitowi z `manifest.json` albo potomkiem ze zmianami tylko w `docs/` | `inbox/ci-green.json`; adres przebiegu w `docs/evidence/f0b.md` |

**Kryteria przekrojowe:** [../testing.md](../testing.md), wiersze „od F0a” i „od F0b”. `kokpit:evidence f0b` uruchamia ich wiersze `ci`; dowód określa [../testing.md](../testing.md).

## Go-live

Po kryteriach wdrażasz `kokpit-prod` przez `make deploy` na VPS ([../operations.md](../operations.md)). Punkty niżej odhaczasz z datą w `docs/evidence/f0b.md`.

- `make deploy` wykonuje krok 3: wdrożenie staje przy `approval` w `dispatching`.
- `[manual]` drugi passkey na PROD na drugim urządzeniu, ze step-upem; ostrzeżenie o jednym passkeyu znika.
- W panelu PROD wpisujesz prawdziwe wartości `setting`: mikrorachunek, rachunek składkowy, parametry ZUS na 2026, wariant i częstotliwość zaliczki.
- Merge `f0b-bramka-kalendarz` do `main` po odbiorze. Przebieg CI na `main` jest zielony; `mutation` zostaje doradczy (D-28).
- Pierwsze przypomnienia dochodzą na telefon. Kokpit niczego nie składa; obowiązki realizujesz według „Teraz – zgodność” w [../README.md](../README.md).
- Bramka na PROD nie ma przedmiotów do go-live F3. `dummy` nie istnieje przy `APP_ENV=prod` (D-32).
- Check dead-man `notifications.dispatch` (5 min, zapas 30 min) dodany w zewnętrznym serwisie. `[manual]` zatrzymanie `worker_notifications` daje alert w czasie zapasu; zrzut alertu w `inbox/`.
- Następna faza to F1 ([f1-ksef-odczyt.md](f1-ksef-odczyt.md), D-30).

## Pierwszy prompt

```text
Przeczytaj docs/README.md i docs/phases/f0b-bramka-kalendarz.md, potem tylko pliki z sekcji „Ładuj tylko”.
Krok 0: docs/naming.md, wiersze F0b w docs/decisions.md i fakty F0b w docs/facts.md.
Brak wejścia oznaczonego „stop” oznacza stop i pytanie.
Zrealizuj F0b: StepUpVerifier z drugim passkeyem, szkielet bramki zatwierdzeń (approval,
sha256 payloadu, step-up, wygaśnięcie, official_writes, SubmissionReconciler) testowany
na konektorze dummy, PolishCalendar z previousWorkingDay, ObligationEngine, obowiązki z kalendarza,
panel setting z valid_from, termin z pisma jako edelivery_reply_due, przypomnienia bez duplikatów
i cotygodniowy przegląd kanałów doręczeń. Dołóż złote przypadki i testy własności kalendarza,
testy wszystkich przejść bramki oraz doradcze mutacje PolishCalendar i ApprovalGate.
Zanim zaczniesz kodować, wypisz plan i pytania. Na koniec uruchom bin/console kokpit:evidence f0b
i napisz docs/evidence/f0b.md: kryterium → pass albo fail → artefakt, link do zielonego
przebiegu CI i lista otwartych wierszy [manual].
```

## Dowód fazy

`docs/evidence/f0b.md`: ręcznie pisana lista (D-28) z datą, commitem, linkiem do przebiegu CI i wynikiem mutacji. Wiersze mają postać kryterium → pass albo fail → artefakt. Faza jest skończona tylko z tym plikiem, wszystkimi wierszami `pass` i odhaczonymi wierszami `[manual]`.
