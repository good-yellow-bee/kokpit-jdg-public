# Kontrakt nazw

Słownik PL→EN i kontrakt nazw kokpitu: tabele, stany, wyliczenia, konektory, zmienne, usługi, komendy i CI. Stan na 8 października 2026.

## Jak używać

- Ten plik jest autorytatywnym kontraktem nazw od 8.10.2026. Zastępuje sekcje „Słownik PL→EN” i „Kontrakt nazw” z [README.md](README.md).
- Identyfikatory są po angielsku. Tu są tylko nazwy; pola, przejścia i semantykę definiuje plik-dom tematu.
- Nowa nazwa albo zmiana nazwy trafia najpierw tutaj, potem do pliku-domu i do kodu.
- Dopisek „odłożone (D-29)” rezerwuje nazwę. Nie powstaje w kodzie, dopóki nowy rekord w [decisions.md](decisions.md) nie przywróci modułu ([roadmap.md](roadmap.md)).
- Nazwa z „Nazw wycofanych” nie wraca w kodzie ani w docs.
- Ładowanie: krok 0 każdej fazy i przed dodaniem nazwy (tabela „Jak czytać dokumentację” w [README.md](README.md)).

## Słownik PL→EN

Terminy e-Doręczeń API i basal zostają w słowniku. Ich identyfikatory są odłożone (D-29).

| Termin | Znaczenie | EN albo identyfikator |
| --- | --- | --- |
| ADE | adres do doręczeń elektronicznych firmy lub osoby | `ade_address` |
| BAE | baza adresów elektronicznych, publiczny rejestr ADE | electronic address registry |
| INT | środowisko testowe e-Doręczeń | `EDORECZENIA_TARGET=int` |
| UA API, SE API | API skrzynki (lista, odbiór, wysyłka); API wyszukiwarki adresów | konektor `EDoreczenia` |
| Moduł uprawnień | część skrzynki firmy, gdzie w zakładce Systemy rejestrujesz system | permissions module |
| zawiadomienie (Stub) | przesyłka przed odbiorem; widać tylko metadane | `edelivery_message` ze stanem `stub` |
| odbiór | pobranie treści przesyłki; prawny odbiór z dowodem otrzymania E.1 | `receive()` |
| dowód nadania, dowód otrzymania | potwierdzenia e-Doręczeń z mocą prawną | `stored_file` rodzaju `evidence` |
| fikcja doręczenia | pismo uznane za doręczone mimo braku odbioru | `deemed_delivery_date` |
| sprawa | wątek pism dotyczących jednej sprawy | `edelivery_case` |
| KSeF, TE, DEMO | Krajowy System e-Faktur; środowisko testowe i przedprodukcyjne | `KSEF_TARGET` |
| numer KSeF | identyfikator nadany fakturze przez KSeF | `ksef_number` |
| UPO | urzędowe poświadczenie odbioru (KSeF, JPK, e-Deklaracje) | `stored_file` rodzaju `upo` |
| Latarnia | publiczny status dostępności KSeF | konektor `Latarnia` |
| P_1, P_2 | data wystawienia i numer faktury w FA(3) | `issue_date`, `invoice_number` |
| P_6, OkresFa | data albo okres wykonania usługi | `service_date`, `service_period` |
| Subject1, Subject2 | w zapytaniach KSeF: jesteś sprzedawcą; jesteś nabywcą | `sync_cursor.stream` = `subject1`, `subject2` |
| faktura korygująca (KOR) | jedyny sposób zmiany faktury przyjętej przez KSeF | `invoice.corrects_invoice_id` |
| offline24, tryb awaryjny | wystawienie z późniejszym przesłaniem do KSeF | `offline_issued` |
| token KSeF | sekret do uwierzytelnienia z zapisanymi uprawnieniami | `ksef_token_read`, `ksef_token_write` |
| certyfikat KSeF typ 1, typ 2 | uwierzytelnienie; kod QR faktury offline (KOD II) | `ksef_auth_cert`, `ksef_offline_cert` |
| KOD I, KOD II | kod QR weryfikacji faktury; kod QR wystawcy offline | invoice QR code, issuer QR code |
| dane autoryzujące | NIP lub PESEL, imię, nazwisko, data urodzenia i przychód N−2; zamiast podpisu przy JPK i e-Deklaracjach | `auth_data` |
| N−2 | przychód z roku o dwa lata wcześniejszego niż rok złożenia | revenue two years before filing |
| PZ, QES, XAdES | profil zaufany; kwalifikowany podpis elektroniczny; format podpisu XML | trusted profile, qualified e-signature, XML signature |
| certyfikat kwalifikowany | certyfikat od kwalifikowanego dostawcy usług zaufania (QTSP), wymagany do API e-Doręczeń PROD | `edoreczenia_qualified_cert` |
| COI | Centralny Ośrodek Informatyki, wsparcie integracji e-Doręczeń | Central IT Centre |
| mikrorachunek podatkowy | indywidualny rachunek do wpłat PIT i VAT | `tax_micro_account` |
| ewidencja przychodów | rejestr przychodów na ryczałcie; podstawa JPK_EWP | `revenue_entry` |
| wykaz ŚT i WNiP | wykaz środków trwałych i wartości niematerialnych; podstawa JPK_ST | `fixed_asset` |
| zaliczka na ryczałt | okresowa wpłata podatku ryczałtowego | obowiązek `ryczalt_advance` |
| składki ZUS | składki społeczne i zdrowotna wpłacane ręcznie | `zus_payment` |
| import usług | usługa od dostawcy zagranicznego; VAT rozliczasz jako nabywca (K_27–K_30) | import of services (reverse charge) |
| np I, np II | sprzedaż poza Polską; np II to usługi z art. 100 ust. 1 pkt 4 (UE B2B) | wartości FA(3) bez zmian |
| WHT | podatek u źródła pobierany od płatności dla nierezydentów | obowiązek `wht_payment` |
| biała lista | wykaz podatników VAT z rachunkami bankowymi | konektor `WhiteList` |
| VIES | unijna weryfikacja numerów VAT | konektor `Vies` |
| kurs średni NBP (tabela A) | kurs do przeliczeń PIT i VAT | `fx_rate` |
| okres, zamknięcie miesiąca, blokada okresu | miesiąc rozliczeniowy z sekwencją zamknięcia i blokadą po złożeniu | `period` |
| obowiązek | termin z kwotą lub czynnością | `obligation` |
| bramka zatwierdzeń | kolejka zapisów czekających na Twoje zatwierdzenie | `approval`, `ApprovalGate` |
| złożenie | plik wysłany do urzędu (JPK, VAT-UE, PIT-28) z UPO | `filing` |
| szkic | dokument przygotowany przez czat | `draft` |
| kontrahent, weryfikacja | podmiot i dowód sprawdzenia w rejestrze | `counterparty`, `verification` |
| e-Korespondencja | zgoda na doręczenia na konto w e-Urzędzie Skarbowym | e-US delivery consent |
| Aplikacja Podatnika KSeF, e-mikrofirma, Klient JPK WEB, Formularz uproszczony, e-Deklaracje | darmowe narzędzia MF | MF free tools |
| GTU | kody grup towarów i usług w JPK_V7M | goods and services group codes |
| PKWiU 2015 | klasyfikacja usług, od której zależy stawka ryczałtu | product and service classification |
| UPL-1 | pełnomocnictwo do podpisywania deklaracji | power of attorney for tax returns |
| administrator skrzynki | osoba, którą przedsiębiorca może wyznaczyć do obsługi skrzynki ADE; kokpit jej nie zakłada (D-107) | inbox administrator |
| nabywca z UE bez ważnego numeru w VIES | podatnik prowadzący działalność, niezidentyfikowany na potrzeby VAT; scenariusz do potwierdzenia przez doradcę (Q-29) | `invoice.tax_scenario` = `eu_b2b_unidentified` |
| atrapa konektora | konektor testowy bez zewnętrznego systemu | konektor `dummy` |
| lista dowodów fazy | ręcznie pisana lista: kryterium → pass albo fail → artefakt | `docs/evidence/<phase>.md` |

## Kontrakt nazw

Propozycje z [data-model.md](data-model.md), [compliance-calendar.md](compliance-calendar.md), [modules/ksef.md](modules/ksef.md), [modules/freshbooks.md](modules/freshbooks.md), [modules/czat.md](modules/czat.md), [testing.md](testing.md) i plików faz F0a–F2 są przyjęte w tabelach niżej albo wycofane. 8.10.2026 doszły nazwy z dawnych kolejek zmian w plikach-domach (usuniętych tego dnia) oraz z decyzji D-28 do D-35. Nazwy odrzucone albo zastąpione są w „Nazwach wycofanych”.

### Tabele

| Obszar | Tabele | Definicje |
| --- | --- | --- |
| Faktury | `invoice`, `invoice_line`, `ksef_session` | [data-model.md](data-model.md) |
| Kontrahenci | `counterparty`, `verification` | [data-model.md](data-model.md) |
| Kursy i płatności | `fx_rate`, `payment` | [data-model.md](data-model.md) |
| Ewidencja i podatki | `revenue_entry`, `fixed_asset`, `zus_payment`, `period` | [data-model.md](data-model.md) |
| Urząd | `filing`, `obligation`, `approval`, `draft` | [data-model.md](data-model.md) |
| e-Doręczenia | `edelivery_message`, `edelivery_case`: odłożone (D-29), powstają przy powrocie F4 | [data-model.md](data-model.md) |
| Pliki | `stored_file` | [data-model.md](data-model.md) |
| Operacje | `connector_call`, `audit_log`, `rate_counter`, `sync_cursor`, `credential_state`, `notification`, `setting` | [data-model.md](data-model.md) |
| Panel | `app_user`, `webauthn_credential` | [data-model.md](data-model.md) |
| Czat | `chat_session`, `chat_message` | [data-model.md](data-model.md) |
| Obserwacja prawa | `legal_watch`, `legal_watch_hit` (D-75) | [data-model.md](data-model.md) |
| Poczta przychodząca | `mail_in_message` (D-92) | [data-model.md](data-model.md) |
| Wyciąg bankowy | `bank_statement`, `bank_statement_line` (D-108) | [data-model.md](data-model.md) |
| basal | `classification`: odłożone (D-29), poza schematem F0a | [modules/basal.md](modules/basal.md) |

- `app_user` i `edelivery_case` zamiast `user` i `case`, bo to słowa zastrzeżone w PostgreSQL.
- Kolumna `environment`: lista tabel i reguły w [data-model.md](data-model.md).
- `setting` przechowuje parametry z datą obowiązywania (`valid_from`).
- Tabela `opening_balance` nie powstaje (D-29). Bilans otwarcia to klucze `setting` `opening.*` i pliki dowodów („Kolumny i klucze `setting`”).

### Stany

| Pole | Wartości | Definicje |
| --- | --- | --- |
| `approval.status` | `pending`, `approved`, `dispatching`, `submitted`, `accepted`, `rejected`, `unknown`, `expired`, `superseded`, `cancelled` | [approval-gate.md](approval-gate.md) |
| `invoice.status` (sprzedaż) | `draft`, `ready`, `approved`, `submitted`, `accepted`, `rejected`, `unknown`, `corrected`, `superseded`, `cancelled_before_send`, `offline_issued` | [data-model.md](data-model.md) |
| `invoice.cost_status` (koszty) | `imported`, `reviewed`, `assigned` | [data-model.md](data-model.md) |
| `invoice.cost_review` | `business`, `private`, `not_mine`, `disputed` | [data-model.md](data-model.md) |
| `filing.status` | `draft`, `ready`, `approved`, `submitted`, `accepted`, `rejected`, `unknown`, `superseded` | [data-model.md](data-model.md) |
| `period.status` | `open`, `closed`, `reopened` | [compliance-calendar.md](compliance-calendar.md) |
| `edelivery_message.status` | `stub`, `receiving`, `received`, `received_elsewhere`; odłożone (D-29) | [modules/e-doreczenia.md](modules/e-doreczenia.md) |
| `edelivery_case.status` | `new`, `received`, `awaiting_reply`, `replied`, `closed`; odłożone (D-29) | [modules/e-doreczenia.md](modules/e-doreczenia.md) |
| `obligation.status` | `open`, `done`, `not_applicable`; zaległość liczona z daty, nie zapisywana | [compliance-calendar.md](compliance-calendar.md) |
| `draft.status` | `pending`, `approved`, `discarded`, `executed` | [modules/czat.md](modules/czat.md) |
| `credential_state.status` | `valid`, `expiring`, `expired`, `revoked` | [security.md](security.md) |
| `verification.result` | `valid`, `invalid`, `unverified_retry`, `manual_evidence` | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |
| `classification.outcome` | `auto_accepted`, `needs_review`, `corrected`; odłożone (D-29) | [modules/basal.md](modules/basal.md) |

- Odtworzenie z backupu przestawia `approved` na `unknown` w `approval.status`, `invoice.status` i `filing.status`. Aktor: Ty. Przejście definiuje [approval-gate.md](approval-gate.md), runbook [operations.md](operations.md).
- Szkic sprzedaży wyparty przez fakturę z Subject1 o tym samym `kind` i P_2: faktura → `cancelled_before_send`, żywe zatwierdzenie → `cancelled` z `cancel_reason` = `manual_invoice` ([modules/ksef.md](modules/ksef.md)).

### Wyliczenia

| Pole | Wartości |
| --- | --- |
| `filing.type` | `JPK_V7M`, `JPK_EWP`, `JPK_ST`, `VAT_UE`, `VAT_UEK`, `PIT_28` |
| `approval.subject_type` | `sales_invoice`, `correction_invoice`, `filing`, `draft`; `edelivery_outbound` odłożone (D-29) |
| `approval.cancel_reason` | `user`, `hash_mismatch`, `manual_filing`, `manual_invoice` |
| `obligation.type` | `zus`, `ryczalt_advance`, `vat_jpk_v7m`, `vat_ue`, `jpk_ewp`, `jpk_st`, `pit_28`, `invoice_issue`, `ledger_entry`, `wht_payment`, `wht_certificate`, `ift_2r`, `cit_10z`, `health_reconciliation`, `tax_form_choice`, `edelivery_deemed`, `edelivery_reply_due`, `credential_expiry`, `manual_channel_check`, `wl_notice`; `edelivery_deemed` z API F4 odłożony (D-29); `edelivery_reply_due` do powrotu F4 wpisywany ręcznie z pisma (D-29) |
| `notification.category` | wartości `obligation.type` przy przypomnieniu oraz `approval_pending`, `connector_failure`, `budget_alert`, `freshbooks_bypass`, `legal_change` (D-75), `weekly_digest` (D-82); `edelivery_new` odłożone (D-29) |
| `invoice.direction` | `sale`, `cost` |
| `invoice.kind` | `RodzajFaktury` z FA(3): `VAT`, `KOR`, `ZAL`, `ROZ`, `UPR`, `KOR_ZAL`, `KOR_ROZ` |
| `invoice.tax_scenario` | `eu_b2b`, `eu_b2b_unidentified` (do potwierdzenia przez doradcę, Q-29), `non_eu_b2b`, `pl_b2b` |
| `invoice.ksef_issue_mode` | `online`, `offline24`, `maintenance`, `failure`, `total_failure`, `outside_ksef`; pusty tylko przy fakturze z numerem z importu KSeF |
| `invoice.import_services` | `eu_28b`, `other` |
| `invoice.jpk_doc_type` | `WEW` |
| `invoice.source` (koszty) | `ksef`, `email`, `paper`, `cash_register`, `manual` |
| `counterparty.wht_category` | `license` (art. 21 ust. 1 pkt 1 ustawy o CIT), `services_21_2a` (pkt 2a), `none`; pusty: dostawca niesklasyfikowany (Q-21) |
| `revenue_entry.source` | `freshbooks`, `ksef_subject1`, `import`, `manual` |
| `revenue_entry.kind` | `regular`, `correction`, `fx_difference` |
| `fx_rate.source` | `nbp`, `ecb` |
| `filing.submitted_via` | `jpk`, `edeklaracje`, `manual` |
| `draft.kind` | `email`; `edelivery_reply` odłożone (D-29) |
| `edelivery_message.direction` | `in`, `out`; odłożone (D-29) |
| `stored_file.kind` | `xml_sent`, `upo`, `pdf`, `evidence`, `attachment`, `verification_raw`, `cost_document`, `outbound_payload` |
| `credential_state.kind` | `ksef_token_read`, `ksef_token_write`, `ksef_auth_cert`, `ksef_offline_cert`, `ksef_jwt_read`, `ksef_jwt_write`, `auth_data`, `freshbooks_oauth`, `dummy_token` (tylko konektor `dummy`); `edoreczenia_qualified_cert` i `edoreczenia_access_token` odłożone (D-29) |
| `sync_cursor.stream` | KSeF: `subject1`, `subject2`; FreshBooks: `invoices`, `payments`; e-Doręczenia `inbox`, `outbox` odłożone (D-29) |
| `rate_counter.bucket` | wzorzec `<connector>.<grupa>`: grupy `ksef.*` z [modules/ksef.md](modules/ksef.md) (w tym `ksef.export_part`, D-71), `latarnia.status`, `white_list.search`, `white_list.check`, `vies.check`, `nbp.rates`, `eli.read`, `mail_in.fetch`, `freshbooks.api`, `jpk.init_upload`, `jpk.upload`, `jpk.finish_upload`, `jpk.status`, `edeklaracje.send`, `edeklaracje.request_upo`, `claude.messages`, `push.send`, `mail.send`, `deadman.ping`, `dummy.call`; `edoreczenia.api` i `basal.classify` odłożone (D-29) |
| `ryczalt.advance_frequency` (`setting`) | `monthly`, `quarterly` (Q-17) |
| `zus.variant` (`setting`) | `full`, `preferential`, `start_relief`; F0b implementuje `full` (Q-16) |
| `environment` | `test`, `demo`, `prod`; `int` odłożone (D-29) |

- Dwie pary JWT KSeF, bo `credential_state` ma `UNIQUE (environment, kind)`.
- `ceidg_jwt` i konektory `krs`, `ceidg`, `gus` nie powstają (D-20, D-29). Wiersz w [roadmap.md](roadmap.md).
- Semantyka limitów: `rate_counter` z `blocked_until` i `Retry-After` ([architecture.md](architecture.md), D-29).

### Kolumny i klucze `setting`

Nazwy przyjęte 8.10.2026 z plików-domów i z przeglądu. Pełna tabela kluczy `setting` jest w [data-model.md](data-model.md).

| Nazwa | Rodzaj i rola | Dom |
| --- | --- | --- |
| `obligation.note` | kolumna `text null`: opis ręcznego obowiązku, aktywna od F0b także dla `edelivery_reply_due` | [data-model.md](data-model.md) |
| `invoice.freshbooks_status` | kolumna `text null`: ostatni widziany status FreshBooks; wykrywanie obejścia | [modules/freshbooks.md](modules/freshbooks.md) |
| `invoice.tax_point_override_reason` | kolumna `text null`: powód ręcznej zmiany `tax_point_date` na koszcie, np. przedpłata (Q-21) | [tax-rules.md](tax-rules.md) |
| `payment.freshbooks_invoice_id` | kolumna `text`: surowe `invoiceid` z FreshBooks; ponowne dopasowanie bez wywołania API | [modules/freshbooks.md](modules/freshbooks.md) |
| `counterparty.is_natural_person` | kolumna `boolean`: zagraniczna osoba fizyczna; WHT z art. 29 ustawy o PIT, do weryfikacji (Q-21) | [tax-rules.md](tax-rules.md) |
| `ksef.fa3_review` | `setting` z `valid_from`: data przeglądu doradcy, zatwierdzone `tax_scenario` i KOR, sha256 złotych plików | [modules/ksef.md](modules/ksef.md) |
| `ksef.sales_transition_on` | `setting`: dzień pierwszej Twojej faktury w KSeF (Q-10) | [modules/ksef.md](modules/ksef.md) |
| `ksef.token_legal_valid_until` | `setting`: prawny koniec tokenów KSeF; domyślne `valid_until` przy rejestracji tokenu | [modules/ksef.md](modules/ksef.md) |
| `freshbooks.sales_enabled` | `setting`: brama F3 dla znacznika gotowości i wykrywania obejścia; `valid_from` = dzień go-live F3 | [modules/freshbooks.md](modules/freshbooks.md) |
| `ledger.auto_entries_enabled` | `setting`; `valid_from` = dzień włączenia; wpisy automatyczne dopiero po imporcie 2026 | [modules/ewidencja.md](modules/ewidencja.md) |
| `step_up.security_key_required` | `setting` (tak albo nie) z `valid_from`: step-up tylko kluczem FIDO2, gdy masz aktywny klucz; zmiana ze step-upem (D-111) | [security.md](security.md) |
| `opening.vat_carry_forward_pln`, `opening.revenue_ytd`, `opening.zus_paid_ytd`, `opening.last_invoice_numbers` | `setting` z `valid_from` = data przejścia, zamiast tabeli `opening_balance`; dowody to `stored_file` rodzaju `evidence` | [compliance-calendar.md](compliance-calendar.md) |

### Konektory

| `connector` (log, liczniki) | Katalog `src/Connector/` |
| --- | --- |
| `ksef`, `latarnia` | `Ksef`, `Latarnia` |
| `edoreczenia` | `EDoreczenia`; odłożone (D-29) |
| `jpk`, `edeklaracje` | `Jpk`, `EDeklaracje` |
| `vies`, `white_list`, `nbp` | `Vies`, `WhiteList`, `Nbp` |
| `eli` | `Eli`; API Sejmu dla obserwacji prawa, tylko odczyt (D-75) |
| `mail_in` | `MailIn`; dedykowana skrzynka na dokumenty kosztowe, IMAP tylko do odczytu (D-92) |
| `freshbooks` | `FreshBooks` |
| `claude` | `Claude` |
| `basal` | `Basal`; odłożone (D-29) |
| `push`, `mail` | `Push`, `Mail` |
| `deadman` | `DeadMan` |
| `dummy` | `Dummy` |

- Claude API jest konektorem: testy na nagranych odpowiedziach i licznik `claude.messages` (D-34).
- `deadman` pinguje zewnętrzny serwis dead-man ([operations.md](operations.md)).
- `dummy` to atrapa konektora do testów i bramki przed F3: klasa `Dummy`, `dummy_token`, licznik `dummy.call`, host `dummy.invalid` przez `MockHttpClient`. Nigdy nie jest włączony przy `APP_ENV=prod` (D-32). Spełnia `CHECK` na `approval.connector` ([data-model.md](data-model.md)).

### Zmienne środowiskowe

| Zmienna | Wartości | Domyślnie |
| --- | --- | --- |
| `KSEF_TARGET` | `test`, `demo`, `prod` | `test` |
| `EDORECZENIA_TARGET` | `int`, `prod`; odłożone (D-29) | `int` (test) |
| `JPK_TARGET` | `test`, `prod` | `test` |
| `EDEKLARACJE_TARGET` | `test`, `prod` | `test` |
| `CONNECTORS_ENABLED` | `0`, `1` | `0`, gdy brak |
| `KOKPIT_DEPLOYMENT` | `dev`, `prod`, `restore` | brak; ustawia projekt compose `kokpit-<wartość>`; na VPS tylko `prod`, `dev` na laptopie, `restore` w jednorazowym projekcie lokalnym (D-28) |

Cele są niezależne od `APP_ENV`, mają osobne zestawy poświadczeń i osobną bazę per środowisko. Hosty dozwolone dla celu sprawdza `HostAllowlist`; szczegóły w [architecture.md](architecture.md).

### Messenger

| Transport | Zasada |
| --- | --- |
| `reads` | odczyty i synchronizacja; retry z backoffem |
| `official_writes` | zapisy w urzędzie; `max_retries: 0`, jeden konsument, ponowienie tylko ręcznie |
| `notifications` | push i mail |
| `failed` | wiadomości po błędzie; listener wysyła push |

- Transport Doctrine; wiadomości niosą tylko identyfikatory. Szczegóły w [operations.md](operations.md).
- „Doręcz klientowi” (F3) wysyła e-mail konektorem `mail` synchronicznie w żądaniu panelu, poza Messenger (D-31). Ponowienie tylko nowym kliknięciem.
- Czat (F6) odpowiada w synchronicznym żądaniu z ramką Turbo, bez transportu, workera i Mercure (D-29).

### Usługi, role i konfiguracja

| Nazwa | Rola | Dom |
| --- | --- | --- |
| `SecretStore` | jedyne wejście do sekretów (`get`, `put`): statyczne z Symfony Secrets, rotujące z `credential_state` | [security.md](security.md) |
| `Redactor` | maskowanie w Monolog, widokach czatu i serializerach | [security.md](security.md) |
| `PolishCalendar` | soboty, niedziele, święta (w tym 24 grudnia); `isWorkingDay`, `previousWorkingDay`; przesuwanie terminów | [compliance-calendar.md](compliance-calendar.md) |
| `ObligationEngine` | reguła terminu: data bazowa, polityka przesunięcia, źródło prawne | [compliance-calendar.md](compliance-calendar.md) |
| `ApprovalGate` | zatwierdzenie, hash payloadu, przekazanie do `official_writes` | [approval-gate.md](approval-gate.md) |
| `StepUpVerifier` | sprawdzenie step-up przy zatwierdzeniu (F0b, D-28); w `when@test` wersja z wirtualnym authenticatorem | [security.md](security.md) |
| `SubmissionReconciler` | rozstrzyga stan `unknown` po stronie urzędu | [approval-gate.md](approval-gate.md) |
| `FileStore` | magazyn plików adresowany sha256, zapis jednokrotny | [data-model.md](data-model.md) |
| `HostAllowlist` | hosty dozwolone per cel konektora | [architecture.md](architecture.md) |
| `KsefGateway` | własny interfejs nad biblioteką KSeF | [modules/ksef.md](modules/ksef.md) |
| `FxRateProvider` | `getRate(currency, date)`: ostatnia tabela A NBP przed datą | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |
| role PostgreSQL `kokpit_owner`, `kokpit_app` | `kokpit_owner`: migracje, właściciel tabel; `kokpit_app`: działanie aplikacji, bez `UPDATE` i `DELETE` na `audit_log` | [security.md](security.md) |
| rola PostgreSQL `chat_ro` | `SELECT` tylko na widokach `chat_*`, osobne połączenie Doctrine `chat` | [modules/czat.md](modules/czat.md) |
| `approval.expiry.<subject_type>.pending`, `approval.expiry.<subject_type>.approved` | wygaśnięcie w konfiguracji: `end_of_p1_day` albo czas ISO 8601 (`P7D`, `PT24H`, `P30D`) | [approval-gate.md](approval-gate.md) |
| `chat.model`, `chat.effort` | model czatu (`claude-haiku-5-5`) i effort z konfiguracji | [modules/czat.md](modules/czat.md) |
| `basal.model`, `basal.revision` | model i rewizja basal z konfiguracji; odłożone (D-29) | [modules/basal.md](modules/basal.md) |
| `freshbooks.ready_marker` | wartość znacznika gotowości w `po_number` albo tagu | [modules/freshbooks.md](modules/freshbooks.md) |
| Symfony Clock, `MockClock` | czas w kodzie i w testach | [testing.md](testing.md) |
| `@group live` | testy na żywych systemach, tylko na żądanie | [testing.md](testing.md) |
| `@group contract` | testy kontraktowe schematów API, uruchamiane w CI | [testing.md](testing.md) |
| `EvidencePack` | budowa paczki dowodów i `manifest.json` | [testing.md](testing.md) |
| `Scrubber`, `FixtureScanner`, `LiveGuard` | czyszczenie nagrań, skaner danych zakazanych i strażnik testsuite `live` | [testing.md](testing.md) |
| `ArchitectureRules` | reguły statycznej architektury dla PHPStan | [testing.md](testing.md) |

### Komendy

| Komenda | Rola | Dom |
| --- | --- | --- |
| `app:credential:register <kind> [--valid-until=<data>]`, `app:credential:smoke <kind>` | rejestracja poświadczenia i wywołanie testowe po rotacji; bez flagi `ksef_auth_cert` i `ksef_offline_cert` biorą notAfter z certyfikatu, `ksef_token_*` biorą `ksef.token_legal_valid_until`, pozostałe rodzaje wymagają flagi | [operations.md](operations.md) |
| `app:ops:record-deploy` | wpis `ops.deployed` w `audit_log` w kroku 7 `make deploy` | [operations.md](operations.md) |
| `app:ops:restore-check [--counts-only] [--month=RRRR-MM] [--snapshots=<plik> --snapshot=<id>] [--expected=<plik>\|-]`, `app:ops:record-restore <raport>\|-` | test odtworzenia: liczby z PROD do porównania, raport JSON z kopii w `kokpit-restore` i wpis `ops.restored` w `kokpit-prod` (D-89) | [operations.md](operations.md) |
| `app:passkey:enrol-link`, `app:passkey:reset` | pierwszy passkey i odzyskanie dostępu przez SSH; w F0a wersja minimalna (D-28) | [security.md](security.md) |
| `app:calendar:feed-link [--username] [--base-url] [--revoke]` | adres kanału ICS obowiązków z nowym tokenem albo wyłączenie kanału (D-77) | [security.md](security.md) |
| `app:audit:verify [--anchor=audit-head:<sequence>:<hash>]...`, `app:files:verify` | łańcuch `audit_log` i kotwice z tagów snapshotów (D-79); hashe plików | [security.md](security.md), [operations.md](operations.md) |
| `app:fixtures:record <connector> <scenariusz>` | nagranie odpowiedzi do fixtures | [../.claude/rules/integrations.md](../.claude/rules/integrations.md) |
| `app:push:test [--channel=mail]` | testowy push ntfy albo mail | [phases/f0a-szkielet.md](phases/f0a-szkielet.md) |
| `app:ledger:import <źródło> <rok> [--file=<plik>]` | idempotentny import ewidencji ze źródła `import`, `freshbooks` albo `ksef_subject1`; `import` wymaga `--file` z eksportem narzędzia (XML JPK_EWP(4) albo CSV); `freshbooks` i `ksef_subject1` od F2.2 | [phases/f2-ewidencja.md](phases/f2-ewidencja.md) |
| `app:jpk:dry-run <typ> <rok> --until=<data>` | JPK bez zatwierdzenia i bez konektora `jpk` | [phases/f2-ewidencja.md](phases/f2-ewidencja.md) |
| `app:ksef:parallel-compare <rok-miesiąc>` | okres równoległy F3: mapuje fakturę FreshBooks połączoną z fakturą Subject1 na FA(3) i porównuje; bez zatwierdzenia i wysyłki | [modules/ksef.md](modules/ksef.md) |
| `app:mail-in:fetch` | jedna sesja skrzynki dokumentów kosztowych teraz, wynik JSON (`status`, `messages`, `documents`) (D-92) | [operations.md](operations.md) |
| `app:legal-watch check`, `app:legal-watch report [--mark-reviewed]` | obserwacja prawa teraz; szkic wierszy do tabeli „Obserwacja prawa” w [facts.md](facts.md) z niesprawdzonych trafień (D-75) | [modules/obserwacja-prawa.md](modules/obserwacja-prawa.md) |
| `kokpit:diagnostics [--days=1..90] [--logs=<plik>\|-]` | paczka diagnostyczna JSON w `var/diagnostics` bez sekretów, logi po `Redactor` (D-80) | [operations.md](operations.md) |
| `kokpit:evidence <phase>` | uruchamia komendy kryteriów `ci` albo `live` fazy i zbiera artefakty z `manifest.json`, zamaskowane przez `Redactor`; powstaje w F0a | [testing.md](testing.md) |

Sposób uruchamiania komend: [../.claude/rules/zasady-twarde.md](../.claude/rules/zasady-twarde.md).

### Dowód działania i CI

| Nazwa | Wartość albo rola |
| --- | --- |
| `<phase>` | `f0a`, `f0b`, `f1`, `f2`, `f3`, `f4`, `f5`, `f6`, `f7` (prefiks pliku fazy); `f4` i `f7` odłożone (D-29) |
| klucz kryterium | slug kebab-case unikalny w fazie, na początku kolumny „Kryterium” pliku fazy (np. `compose-up`); `cc-NN` dla kryteriów przekrojowych z [testing.md](testing.md); jedyny format we wszystkich plikach faz (D-33); klucz w `manifest.json`, rejestrze i nazwach artefaktów |
| `tests/Acceptance/<phase>.yaml` | rejestr kryteriów: klucz, komenda, asercja, artefakty, wykonawca; wejście `kokpit:evidence`; bez testu zgodności z tabelą pliku fazy (D-28) |
| wykonawca (`runner`) | `ci`, `live`, `manual` |
| wynik (`result`) | `pass`, `fail`, `pending`; `pending` przy braku artefaktu kroku `[manual]` |
| `var/evidence/<phase>/<YYYYMMDD-HHMM>/` | paczka artefaktów, poza repo (`var/` w `.gitignore`); w CI artefakt przebiegu |
| `var/evidence/<phase>/inbox/` | artefakty kroków `[manual]`, nazwane od klucza; `kokpit:evidence` dołącza je z sha256 |
| `manifest.json` | commit sha, cele środowisk, wersje narzędzi; klucz kryterium → wykonawca → wynik → ścieżki artefaktów i sha256 |
| `docs/evidence/<phase>.md` | ręcznie pisana lista w repo: data, commit, link do przebiegu CI, kryterium → pass albo fail → artefakt, odhaczone `[manual]`; faza skończona tylko z tym plikiem i wszystkimi wierszami pass (D-28) |
| opcje | `kokpit:evidence --only=<wykonawca>` (`ci`, `live`) |
| kolumny kryteriów | `Kryterium`, `Komenda / kroki`, `Oczekiwany wynik`, `Dowód`; `[manual]` też ma dowód (zrzut albo odhaczony wpis listy kontrolnej) |
| testsuite PHPUnit | jeden na katalog: `unit` (`tests/Unit`), `property` (`tests/Property`), `contract` (`tests/Contract`), `xsd` (`tests/Xsd`), `integration` (`tests/Integration`), `security` (`tests/Security`), `live` (`tests/Live`); domyślny przebieg bez `live` |
| `.github/workflows/ci.yml` | id jobów: `lint`, `unit_property` (`name: unit+property`), `contract`, `xsd`, `integration`, `security`, `images` (D-93), `mutation`, `evidence`; `live` tylko ręcznie (`workflow_dispatch`), nigdy PROD |
| `mutation` | Infection, blokuje merge, także przy `pull_request` (D-106) |
| faza joba `evidence` | prefiks gałęzi `fX-*` albo wejście `phase` przy `workflow_dispatch`; push do `main` i gałąź bez prefiksu `<phase>-` (np. `feature/*`) pomijają `evidence` (D-43) |
| `infection.json5` | zakres mutacji i progi `minMsi`, `minCoveredMsi`; wartości w [testing.md](testing.md) |

Repo CI: prywatne `good-yellow-bee/kokpit-jdg`. Zakres jobów i próg MSI: [testing.md](testing.md).

Katalogi: `src/Connector/<System>`, `src/Approval`, `src/Calendar` (`PolishCalendar`, `ObligationEngine`, rejestr reguł terminów), `src/Ledger`, `src/Tax`, `src/Xml`, `resources/xsd`, `tests/Fixtures/<Connector>`, `tests/Acceptance`. Pliki: `var/data/{env}/{kind}/{yyyy}/{sha256}.{ext}`; dowody: `var/evidence/<phase>/<YYYYMMDD-HHMM>/`.

### Implementacja F0a: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| HTTP | `ConnectorHttpClient`, `ConnectorRequestContext`, `ConnectorCallRecorder`, `ConnectorException`, `HostAllowlist` |
| Limity | `RateCounter`, `RateLimitExceededException` |
| Konektory | `PushNotifier`, `MailNotifier`, `DeadManNotifier`, `DummyConnector` |
| Wiadomości | `ConnectorCallMessage`, `FileVerifyMessage`, `AuditVerifyMessage`, `RetentionPurgeMessage`, `FailedMessageNotifier` |
| Zadania harmonogramu | `heartbeat`, `files.verify_hashes`, `audit.verify_chain`, `retention.purge` |
| Akcje audytu | `credential.rotated` |
| Panel i passkey | `PanelController`, `PasskeyController`, `PasskeyEnrollmentService`, `PasskeyAccessSubscriber`, `app:passkey:enrol-link`, `app:passkey:reset` |
| Monitoring | `HealthController`, `HealthChecker`, `HealthCheckResult` |
| Bezpieczeństwo panelu | `SecurityHeadersSubscriber` |
| Wdrożenie | `app:ops:record-deploy`, `ops.deployed`, `connector.target_changed`, `connector.enabled_changed` |
| Akcje audytu panelu | `auth.login`, `auth.login_failed`, `auth.passkey_enrolled`, `auth.passkey_revoked`, `auth.recovery_link_issued` |

### Implementacja F2.1: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Ewidencja | `LedgerImporter`, `LedgerFileParser`, `LedgerRow`, `LedgerImportResult`, `LedgerImportRefused`, `JpkEwpSchema`, `LedgerImportCommand` |
| XSD | `XsdCatalog` (manifest `resources/xsd/checksums.json` z polem `locations`), `XsdValidator`, `XsdDictionary`, `XsdMissingException` |
| Ustawienia | `SettingStore`, `SettingKeys`, `SettingController`; panel `/settings` |
| Akcje audytu | `ledger.imported` |
| Dowód działania | `scripts/ledger-acceptance.php <idempotent\|xml\|sums>`; zmienna `EVIDENCE_DIR` dla artefaktów `db/`, `golden/`, `jpk/` |
| Fixtures | `tests/Fixtures/Jpk/ledger-2026-jan-oct.csv`, `ledger-2026-jan-nov.csv`, `ledger-2026-jpk-ewp.xml`, `golden/ledger-import-sums.txt` |

### Implementacja F2.2: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Ewidencja ze źródeł | `ServiceMap`, `RevenueDocument`, `RevenueEntryWriter`, `RevenueWriteResult`, `Subject1Ledger`, `Subject1LedgerResult` (wyniki `written`, `duplicate`, `fields_only`, `not_vat`, `revenue_date_manual`, `rate_missing`, `fx_rate_pending`), `ManualRevenueEntry`, `EntryErrorCorrection` (`src/Ledger`) |
| Ustawienia | `ryczalt.service_map`, `ledger.auto_entries_enabled` w `SettingKeys` i w panelu `/settings` |
| Panel | `LedgerController`; `/ledger` (braki wpisów, wiersze roku, wpis ręczny), `POST /ledger/manual` |
| Akcje audytu | `ledger.manual_entry`, `ledger.entry_corrected` |
| Komendy | `app:ledger:import ksef_subject1 <rok>` i `app:ledger:import freshbooks <rok>` (odtworzenie) |
| Konektor `freshbooks` (`src/Connector/FreshBooks`) | `FreshBooksOAuth` (`authorizeUrl`, `connect`, `connected`, `session`), `FreshBooksConnector` (`invoices`, `payments`, `invoice`), `FreshBooksInvoice`, `FreshBooksPayment`, `FreshBooksSync` (`pollInvoices`, `pollPayments`, `reconstruct`; `BACKFILL_FROM`), `FreshBooksPollHandler`; wyliczenie `FreshBooksError` (`not_connected`, `grant_lost`, `token_rejected`, `invalid_response`, `http_error`, `rate_limited`), `FreshBooksException` |
| Operacje `connector_call` FreshBooks | `oauth_token`, `me`, `invoices`, `payments`, `invoice` |
| Ewidencja z FreshBooks i płatności (`src/Ledger`) | `FreshBooksLedger` (`transitionDay`, `classify`, `ledger`, `taxIdentity`, `party`, `countryCode`; wyniki `linked`, `unchanged`, `draft_created`, `draft_updated`, `draft_unchanged`, `draft_cancelled`, `deleted`, `skipped_draft`, `pending`, `number_conflict`, `fields_only`, `written`, `duplicate`, `rate_missing`, `fx_rate_pending`), `PaymentLedger` (`upsert`: `new`, `known`, `deleted`; `matchByInvoice`, `matchByNumber`, `awaitingFx`), `FxDifference` (`post`: `posted`, `no_difference`, `already_posted`, `pln`, `unmatched`, `source_missing`, `source_without_fx`, `fx_rate_pending`), `RevenueEntryWriter::append`, `CounterpartyDirectory::freshbooksClient` |
| Tabela | `payment` (migracja F2.2 część 2), klucz obcy `revenue_entry_payment_fk`, indeks `revenue_entry_payment_source` |
| Stan `freshbooks_oauth` | zaszyfrowany JSON z kluczami `refresh_token`, `access_token`, `access_expires_at`, `account_id`, `redirect_uri` (D-45) |
| Sekrety | `FRESHBOOKS_<CEL>_CLIENT_ID`, `FRESHBOOKS_<CEL>_CLIENT_SECRET`; w sejfie testowym fikcyjne `FRESHBOOKS_TEST_*` |
| Harmonogram | `FreshBooksPollMessage` (transport `reads`): `freshbooks.poll_invoices` co 15 min, `freshbooks.poll_payments` co godzinę (minuta 7) |
| Panel | `FreshBooksController`: `/freshbooks`, `POST /freshbooks/connect` (step-up, token CSRF `freshbooks`), `/freshbooks/callback`; sesja `freshbooks.oauth_state`; `ksef.sales_transition_on` w `/settings` |
| Akcje audytu | `freshbooks.connected`; `sale.imported_subject1` z `freshbooks_draft_id`, gdy szkic oddał `freshbooks_id` |
| Fixtures | `tests/Fixtures/FreshBooks` (ręcznie pisane, konto `fikc01`), `FreshBooksTestTools` |
| Korekty i uzgodnienia (`src/Ledger`) | `CorrectionLedger` (`pending`, `close`; przyczyny `other`, `arithmetic_error`), `LedgerReconciliation` (`report`, `csv`; kontrole `import`, `ksef_subject1`, `freshbooks`, `duplicates`; wyniki `ok`, `difference`, `missing`, `duplicate`, `date_difference`, `amount_difference`, `fx_rate_pending`, `not_connected`) |
| Panel korekt i uzgodnień | `/ledger` sekcja „Korekty z KOR”, `POST /ledger/correction/{id}` (token CSRF `ledger`); `/ledger/reconciliation?year=<rok>`, eksport `?format=csv` (`uzgodnienia-<rok>.csv`, średnik) |
| Akcje audytu korekt | `ledger.correction_closed`; `ledger.imported` z `total_in_file` |

### Implementacja F2.3: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Tabela | `zus_payment` (migracja F2.3 część 1) |
| Podatki (`src/Tax`) | `RyczaltAdvance` (`compute`; pola `shares`, `deductions`, `bases`, `taxes`, `advance`, `carryover`), `HealthContribution` (`bracket`, `amount`), `TaxCalculator` (`advance`, `zusAmount`, `healthReconciliation`, `pit28`, `eligibility`, `eurThresholdRate`, `zusParameters`, `revenueAfterSocial`, `revenueByRate`), `ZusPayments` (`record`), `ThresholdForecast` (D-76) |
| Ustawienia | `ryczalt.eligibility`: `{limit_eur, watch_limit_eur, quarterly_limit_eur}` (D-47, `quarterly_limit_eur` z D-76), w `SettingKeys` i w panelu `/settings` |
| Panel | `TaxController`: `/taxes?year=<rok>` (sekcje `ryczalt-eligibility-metric`, `threshold-forecast` z `tax-reserve` (D-76), `ryczalt-advances`, `zus-payments`, `health-reconciliation`, `pit28-summary` z `pit28-rates`, `pit28-due` i `pit28-refused` (D-101)), `POST /taxes/zus-payment` (token CSRF `taxes`) |
| Akcje audytu | `zus.payment_recorded`, `period.correction_required`, `period.ledger_closed`, `period.closed` |
| Tabela (część 2) | `period` (migracja F2.3 część 2, D-48) |
| Okres (`src/Ledger`) | `Periods` (`ensure`, `closed`, `earliestOpen`, `assertOpen`, `correctionRequired`, `markLedgerClosed`, `close`, `corrections`, `month`), wyjątek `ClosedPeriod` (`period`, `proposed`), `LedgerEntryCheck` (`run`, `check`, `missing`, `closeIfComplete`), `LedgerEntryCheckResult` |
| Zadanie | `ledger.entry_check`: `LedgerTaskMessage`, `LedgerTaskHandler`, transport `reads`, cron `0 8 15,20 * *` |
| Panel | `/ledger`: sekcje `period-corrections` („Korekta wymagana”) i `ledger-entry-check` |
| Złożenie poza kokpitem (D-49) | `ManualFiling` (`record`), `MonthChecklist` (`evaluate`; bramki `sales_final`, `costs_reviewed`, `fx_rates`, `vies_eu_b2b`), `Periods` (`saveChecklist`, `status`, `reopen`, `reclose`); `filing.totals` klucz `p62` |
| Panel złożeń | `FilingController`: `/filings` (sekcje `filing-manual`, `filing-periods`, `filing-list`), `POST /filings/manual`, `POST /filings/period/<RRRR-MM>/reopen`, `POST /filings/period/<RRRR-MM>/reclose` (token CSRF `filings`, step-up) |
| Akcje audytu (D-49) | `filing.manual_recorded`, `period.reopened`, `period.reclosed` |

### Implementacja F2.4: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Generatory i dry run | `JpkEwpBuilder`, `JpkStBuilder`, `JpkDocument`, `JpkBuildRefused` (`src/Xml`); `JpkDryRun` (`src/Ledger`); `JpkDryRunCommand` z opcjami `--until` i `--output` |
| Tabele | `filing`, `fixed_asset` (migracja F2.4) |
| Akcje audytu | `jpk.dry_run` |
| Sekret `auth_data` | JSON z kluczami `birth_date`, `revenue_n2` (D-37) |
| Dowód działania | `scripts/jpk-acceptance.php <ewp-build\|ewp-dry-run\|st-dry-run>`; `st-dry-run` od F5.2 (D-64) |
| Fixtures | `tests/Fixtures/Jpk/jpk-2026-dry-run.json`, `golden/jpk-ewp-dry-run.txt` |

### Implementacja F0b: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Kalendarz | `PolishCalendar` (`src/Calendar`): `holidays`, `isHoliday`, `isWorkingDay`, `previousWorkingDay`, `workingDayOnOrAfter`, `shift`, `easter`; polityki `op12`, `zus`, `none` |
| Obowiązki | `ObligationEngine` (`recompute`, `candidates`, `zusAmount`), `ObligationRules` (`RULES`, `MANUAL`, `CHANNELS`, `EXPIRING_KINDS`, `source`, `policy`, `missingAmount`); wiadomość `ObligationRecomputeMessage` (transport `reads`) |
| `obligation.rule_key` | `zus.monthly`, `ryczalt_advance.monthly`, `ryczalt_advance.quarterly`, `vat_jpk_v7m.monthly`, `vat_ue.monthly`, `invoice_issue.monthly`, `ledger_entry.monthly`, `wht_payment.monthly`, `ift_2r.yearly`, `cit_10z.yearly`, `jpk_ewp.yearly`, `jpk_st.yearly`, `pit_28.yearly`, `health_reconciliation.yearly`, `tax_form_choice.yearly`, `credential_expiry.valid_until`, `credential_expiry.auth_data`, `manual_channel_check.weekly`, `manual_channel_check.balance_check`; wpis ręczny `edelivery_reply_due.manual`; z maila z powiadomieniem `manual_channel_check.mail_notice` (D-109) |
| Tabele | `obligation`, `notification` (migracja F0b) |
| Przypomnienia i akcje | `NotificationDispatcher` (`run`, `plan`, `send`, `offsets`, `channels`, `body`; link `/calendar`; klucz zaległości `{obligation_id}:overdue:{data}:push`), `ObligationActions` (`complete`, `recordPayment`, `markNotApplicable`, `addLetterDeadline`), `CredentialExpiryChecker` (`src/Security`), `CredentialValidity` (`rows`; sekcja pulpitu `#connector-validity`); wiadomości `NotificationDispatchMessage` (transport `notifications`), `CredentialExpiryMessage` (`reads`) |
| Panel | `CalendarController`: `/calendar`, `/calendar/letter`, `/calendar/{id}/complete`, `/calendar/{id}/payment`, `/calendar/{id}/not-applicable`; `/settings` z kluczami F0b |
| Passkey i step-up | interfejs `StepUp` (`require`), `StepUpVerifier` (`options`, `verify`, `require`; sesja `step_up.*`, 5 minut), `PasskeyManager` (`active`, `warning`, `enrolmentToken`, `revoke`), `PasskeyPanelController`: `/passkeys`, `/passkeys/add`, `/passkeys/{id}/revoke`, `/passkey/step-up/options`, `/passkey/step-up/verify` (D-40) |
| Klucz FIDO2 | wyliczenie `AuthenticatorKind` (`passkey`, `security_key`; `label`, `enrolPaths`); profil WebAuthn `security_key` i kontroler `enrolment_security_key`: `/passkey/enrol/security-key/options`, `/passkey/enrol/security-key/result`; pole formularza `kind` w `/passkeys/add`; opcja `--security-key` w `app:passkey:enrol-link` i `app:passkey:reset` (D-90) |
| Step-up kluczem | `StepUpKeyPolicy` (`required`, funkcja Twig `step_up_security_key_required`); szablon `panel/_step_up.html.twig` (przycisk `#passkey-step-up`, podpowiedź `#step-up-security-key`); `kind` w szczegółach `auth.step_up`, `step_up_kind` w `approval.approved` (D-111) |
| Bramka zatwierdzeń (`src/Approval`) | `ApprovalGate` (`request`, `renew`, `approve`, `cancel`, `markCopied`, `recordManualFiling`, `markRestoredUnknown`, `retry`), `ApprovalTransitions` (`TABLE`, `LIVE`, `FINAL`, `apply`, `action`), `ApprovalSubject`, `ApprovalSubjects`, `ApprovalPayload`, `OfficialWriter`, `StatusReader`, `ApprovalConnectors`, `DispatchRequest`, `DispatchGuard`, `SubmissionResult`, `ApprovalExpiry`, `ApprovalNotifications` (link `/approvals`; klucz `{approval_id}:{category}:{powód}:push`), `OfficialWritesHandler`, `SubmissionReconciler` (`poll`, `reconcile`), `ApprovalTaskHandler`, `GateBypassException`, `InvalidTransitionException`; tagi `app.approval_subject`, `app.official_writer`, `app.status_reader` (D-41) |
| Wiadomości bramki | `ApprovalDispatchMessage` i `ApprovalDispatchWaitingMessage` (transport `official_writes`), `ApprovalTaskMessage` (`reads`: `approval.expire`, `approval.poll_status`, `approval.reconcile`); middleware `OfficialWritesRetryGuard` |
| Parametry bramki | `approval.expiry` (`approval.expiry.<subject_type>.pending`, `.approved`), `approval.poll_window` per konektor |
| Panel bramki | `ApprovalController`: `/approvals`, `/approvals/{id}/approve`, `/cancel`, `/copied`, `/retry`, `/renew`; token CSRF `approvals` |
| Konsola bramki | `app:approval idle` (krok 3 `make deploy`), `app:approval mark-restored` (krok 4 runbooka odtworzenia), `app:approval dummy-request` (fixtures dev, nigdy przy `APP_ENV=prod`) |
| Atrapa bramki (`src/Connector/Dummy`) | `DummyWriter` (operacje `submit`, `status`), `DummySubject` (usługi `dummy.subject.filing`, `dummy.subject.draft`), `DummySubjects`; scenariusze `accept`, `reject`, `duplicate`, `timeout`, `poll_reject`, `poll_pending` |
| `/health` od F0b | sprawdzenie `approval_unknown` |
| Dead-man | `DeadManNotifier::ping('heartbeat'\|'notifications'\|'ksef_sync')`; sekrety `DEADMAN_PROD_NOTIFICATIONS_URL`, `DEADMAN_PROD_KSEF_SYNC_URL` (D-98) |
| Wpis ręczny | znak pisma bez spacji na brzegach i wielkimi literami; bez znaku `brak-{n}` |
| `setting` od F0b | `company.tax_micro_account`, `company.zus_account` (NRB, 26 cyfr), `zus.parameters`, `zus.variant`, `ryczalt.advance_frequency`; domyślne `monthly` i `full` od 2026-01-01 z migracji |
| Tytuł ZUS | `Składki ZUS {okres}` (D-39) |
| Fixtures | `FictionalIdentifiers::account(n)`; `tests/Support/ObligationTestTools.php` |
| Dowód działania | `scripts/calendar-acceptance.php <golden\|property>`; `tests/Support/CalendarGoldenCases.php` |

### Implementacja F1 (część 1, D-42): nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Tabele | `counterparty`, `verification`, `fx_rate`, `ksef_session`, `invoice`, `invoice_line`, `sync_cursor` (migracja F1); wyzwalacz `invoice_sale_immutable`; klucze obce `revenue_entry_invoice_fk`, `revenue_entry_fx_rate_fk`, `fixed_asset_invoice_fk`, `obligation_invoice_fk`, `obligation_counterparty_fk` |
| FA(3) (`src/Invoice`) | `Fa3Reader` (`read`; schemat `schemat_FA(3)_v1-0E.xsd`), `Fa3Document` (`taxPointDate`), `Fa3Party` (`country`), `Fa3Line`, `Fa3Exception`, `VatFx` (`rateDate`, `amounts`, `selfAssessed`, `convert`), `Subject1Importer` (`import`, `scenario`, `EU`) |
| Kursy (`src/Fx`) | `FxRateProvider` (`getRate`), `FxRate`, `FxRateUnavailable`, interfejs `NbpRateSource` (`range`), konektor `NbpConnector` (`src/Connector/Nbp`, `range`, `parse`; część 2) |
| Kontrahenci (`src/Counterparty`) | `CounterpartyDirectory` (`supplier`, `client`, `manualSupplier`) |
| Koszty (`src/Cost`) | `CostImporter` (`importKsef`, `recordManual`, `MANUAL_SOURCES`), `ManualCost`, `CostReview` (`review`, `assign`, `unassign`, `overrideTaxPoint`, `markPaid`, `proposedPeriod`, `tasks`, `REVIEWS`) |
| Zadania w panelu kosztów | `review_cost`, `assign_vat_period`, `request_correction_or_report_fraud`, `resolve_dispute` (stan pochodny, bez tabeli) |
| `obligation.rule_key` od F1 | `wht_payment.supplier` (`wht_payment:{okres}:{counterparty_id}`), `wht_certificate.supplier` (`wht_certificate:{data}:{counterparty_id}`) |
| `audit_log.action` od F1 | `cost.imported`, `cost.ksef_linked`, `cost.reviewed`, `cost.assigned`, `cost.unassigned`, `cost.tax_point_overridden`, `cost.paid`, `sale.imported_subject1`; czytane: `counterparty.wht_treaty_country_set` |
| `setting` od F1 | `vat.standard_rate` (`"23.00"` od 2026-01-01 z migracji) |
| `stored_file` od F1 | typy `image/jpeg` i `image/png` dla `cost_document` |
| Fixtures | `FictionalIdentifiers::NIPS` z `5555555555` i `7777777777`; `tests/Support/F1TestTools.php` (`fa3`, `plParty`, `euParty`, `foreignParty`, `rates`) |

### Implementacja F1 (część 2, D-42): nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| KSeF (`src/Connector/Ksef`) | interfejs `KsefGateway` (`startExport`, `exportPackage`), `KsefExportPackage`, `KsefExportedInvoice`, `KsefError` (`InvoiceNotYetAvailable` = `21165`, `DuplicateInvoice` = `440`, `ExportTimeout`, `ExportFailed`, `GatewayUnavailable`), `KsefException`, `UnavailableKsefGateway` (do implementacji nad biblioteką z nagraniami) |
| Synchronizacja (`src/Invoice`) | `KsefSync` (`run`, `STREAMS`, `MAX_WINDOW_DAYS`, `MIN_EXPORT_SPACING`, `RETRY_DELAYS_21165`), `TransitionReport` (`build`, `split`); wiadomość `KsefSyncMessage` (`ksef.sync`, `ksef.backfill`) i `KsefSyncHandler` (`NIGHT_ENDS_AT`) |
| Komendy | `app:ksef:sync <subject1\|subject2> [--backfill]`, `app:ksef:transition-report <rok>` |
| Limity (`src/Rate`) | `RateLimitAlerts` (`rateLimited`): push `connector_failure` przy 429, klucz `{environment}:{connector}:{bucket}:{blocked_until}:rate_limited:push` |
| Fixtures | `tests/Support/FakeKsefGateway.php` (mechanika, nie kontrakt) |

### Implementacja F1 (część 3, D-42): nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Konektory | `ViesConnector` (`src/Connector/Vies`, `check`, `fetch`, `parse`), `ViesError` (`MS_UNAVAILABLE`, `TIMEOUT`, `SERVICE_UNAVAILABLE`, `MS_MAX_CONCURRENT_REQ`, `GLOBAL_MAX_CONCURRENT_REQ`, `INVALID_INPUT`, `OTHER`); `WhiteListConnector` (`src/Connector/WhiteList`, `search`, `check`, `fetch`, `parseSearch`, `parseCheck`, `QUIET_FROM_HOUR`); wynik `RegistryResult` (`src/Connector`) |
| `connector_call.operation` | `vies`: `check_vat`; `white_list`: `search`, `check` |
| Kontrahenci (`src/Counterparty`) | `CounterpartyVerifier` (`verify`, `checkAccount`, `vies`, `retry`, `ownVatNumber`, `requesterNip`, `RETRY_WINDOW_HOURS`), `VerificationRecorder` (`record`, `recordManual`, `EVIDENCE_TYPES`), `ManualEvidence` (`add`), `RefreshDue` (`run`), `SelfCheck` (`run`, `due`, `alerts`), `PrePaymentCheck` (`check`, `chooseNotice`, `EXITS`, `NOTICE_DAYS`), `RegistryAlerts` (`connectorFailure`) |
| Wyjścia przy `NIE` powyżej progu | `change_account`, `split_payment`, `notify_tax_office` |
| Alerty samosprawdzenia (stan pochodny) | `status_not_active`, `account_not_listed`, `vat_eu_invalid`, `vat_eu_unverified_retry` |
| Wiadomości | `RegistryTaskMessage` (`counterparty.refresh_due`), `ViesRetryMessage` (`counterpartyId`, `firstCheckedAt`, `attempt`); handler `RegistryTaskHandler` |
| Konfiguracja | `registry.vies_endpoints`, `registry.white_list_endpoints`, `registry.vies_retry_delays`; progi `white_list.search` 80 i `white_list.check` 4000 w `connector.daily_limits` |
| `setting` od F1 | `white_list.pre_payment_threshold_pln` (`"15000.00"` od 2026-01-01 z migracji); `company.identity` dostaje pola `bank_accounts` (lista NRB) i `vat_eu_number` (`PL` + NIP) |
| `obligation.rule_key` od F1 | `wl_notice.manual` (`wl_notice:{dzień zlecenia}:{invoice_id}`) |
| `audit_log.action` od F1 | `cost.pre_payment_checked`, `cost.wl_notice_chosen`, `verification.manual_evidence` |
| `notification.dedupe_key` | `connector_failure` rejestrów: `{environment}:vies:{powód}:{counterparty_id albo self}:{dzień pierwszej próby}:push` |
| Komendy | `app:fixtures:record vies <kraj>-<numer>`, `app:fixtures:record white_list search-<nip>-<data>` |
| Fixtures | `tests/Support/RegistryTestTools.php` (mechanika, nie kontrakt) |

### Plan F3 (D-50): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Części | `F3.1` FA(3) i XSD, `F3.2` gotowość, bramka i blokady, `F3.3` wysyłka i rekoncyliacja, `F3.4` po przyjęciu, `F3.5` api-test; rejestr `tests/Acceptance/f3.yaml`, lista `docs/evidence/f3.md` |
| Akcje audytu | `invoice.written_back` (zapis zwrotny numeru KSeF do FreshBooks) |
| Fixtures | `tests/Fixtures/Ksef/golden/fa3-*.xml`, `tests/Fixtures/Latarnia`; scenariusze `app:fixtures:record ksef <scenariusz>`, `app:fixtures:record latarnia status` |
| Kod | klasy wysyłki, rekoncyliacji, PDF i zapisu zwrotnego dopisuje sesja części przed kodowaniem |

### Implementacja F3.1: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Generator i DTO (`src/Xml`) | `Fa3Builder` (FA(3) 1-0E z DTO, P_1 z Symfony Clock), `Fa3Invoice`, `Fa3InvoiceLine`, `Fa3Correction` (DTO faktury niezależny od źródła), `Fa3Scenario` (wyliczenie `tax_scenario` z mapowaniem P_12 i P_18), `Fa3Payload` (bajty, sha256, kwoty, kurs VAT), `Fa3PresendCheck` (kontrole przed wysyłką), `Fa3BuildRefused` (odmowa z listą problemów) |
| Odczyt | `Fa3Party::fromSnapshot` (nabywca z `buyer_snapshot`) |
| Konfiguracja | `ksef.form_code` (`system_code`, `schema_version`, `value`; `formCode` sesji KSeF), `ksef.invoice_max_bytes` (limit faktury bez załączników) |
| Złote pliki | `tests/Fixtures/Ksef/golden/fa3-eu-b2b.xml`, `fa3-non-eu-b2b.xml`, `fa3-pl-b2b.xml`, `fa3-pl-b2b-eur.xml`, `fa3-kor.xml`, `fa3-kor-reason.xml`, `fa3-kor-zero.xml`, `fa3-kor-nrksefn.xml` |
| Testy i odbiór | `Fa3TestTools`, `Fa3BuilderTestCase`, `scripts/fa3-acceptance.php`; klucze kryteriów `fa3-golden-eu-b2b`, `fa3-golden-non-eu-b2b`, `fa3-golden-pl-b2b`, `fa3-golden-pl-b2b-eur`, `fa3-golden-kor`, `fa3-golden-kor-zero`, `fa3-presend-checks`, `fa3-p1-today`, `fa3-unidentified-blocked` |

### Implementacja F3.2 (część a): nazwy techniczne

Część b: sekcja niżej.

| Rodzaj | Nazwy |
| --- | --- |
| Przedmioty bramki (`src/Invoice`) | `SalesInvoiceSubject` (usługi `invoice.subject.sales` i `invoice.subject.correction` dla `sales_invoice` i `correction_invoice`), `SalesConditions` (`scenario`, `buyerProblem`, `p1Problem`, `fa3ReviewProblem`, `onboarded`, `vatCode`), metoda `ApprovalSubject::checkDecision` (warunki z kolumną „decyzja”) |
| Gotowość (`src/Invoice`) | `SalesReadiness` (`observe`, `evaluate`, `problems`, `setService`); opcjonalny argument `FreshBooksSync::$readiness` |
| Kolumna | `invoice.ready_blockers` (`jsonb null`): powody, dla których szkic ze znacznikiem zostaje w `draft`; pusta bez znacznika i po `ready` |
| Schemat (migracja F3.2 część a) | funkcja i wyzwalacz `invoice_line_delete_draft_only` (`DELETE` pozycji tylko przy sprzedaży w `draft`, `ready`, `approved` i `rejected`); `CHECK` `approval_superseded_successor` zamiast `approval_check3` (faktura po zdjęciu znacznika: `superseded` bez następcy) |
| `setting` | `freshbooks.sales_enabled` (tak albo nie); `ksef.fa3_review` z polami `reviewed_on`, `scenarios` (lista `tax_scenario`), `kor` (tak albo nie), `golden_sha256` (plik → sha256) |
| Konfiguracja | `freshbooks.ready_marker` = `KSEF-GOTOWA`; `registry.pl_client_max_age` = `P30D` (świeżość białej listy klienta z Polski) |
| FreshBooks | `FreshBooksInvoice::$poNumber`; pozycje z kluczami `qty` i `unit_cost` |
| `obligation.rule_key` | `invoice_issue.draft` (`invoice_issue:{miesiąc usługi}:{invoice_id}`) |
| Akcje audytu | `invoice.service_set` (data albo okres usługi wpisany w panelu) |
| Testy i odbiór | `SalesTestTools`, `SalesTestCase`, fixture `tests/Fixtures/FreshBooks/invoices-f3-ready.json`; klucze kryteriów `ready-marker-disabled`, `ready-marker-request`, `ready-marker-removed`, `ready-blockers`, `approval-buyer-id`, `approval-p1-future`, `approval-p2-lock`, `approval-fa3-review-prod`, `invoice-approval-renew`, `invoice-issue-per-draft` |

### Implementacja F3.2 (część b): nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Kolumny | `invoice.panel_task` (`text null`, `CHECK`: `freshbooks_bypass`, `outside_cockpit`, `freshbooks_number_changed`): zadanie sprzedaży w panelu; `invoice.panel_task_at` (`timestamptz null`); `invoice.freshbooks_credit_id` (`text null`, indeks unikalny `invoice_freshbooks_credit`): credit note FreshBooks szkicu KOR |
| Schemat (migracja F3.2 część b) | `invoice.invoice_number` bez `NOT NULL`, `CHECK` `invoice_number_kor_draft` (pusty numer tylko w szkicu KOR przed numerem credit note); `invoice_sale_immutable` dopuszcza zmianę `panel_task` i `panel_task_at`; `sync_cursor.stream` = `credit_notes`; `notification.category` = `freshbooks_number_changed` |
| FreshBooks | `FreshBooksCreditNote` (`fromApi`, `$document`, `$originalNumber`, stała `REFERENCE`: linia `Faktura: <numer>` w `notes`), `FreshBooksConnector::creditNotes`, `FreshBooksSync::pollCreditNotes`, opcjonalny argument `FreshBooksSync::$corrections`; operacja `connector_call` `credit_notes`; wyniki `FreshBooksLedger::classify` `number_changed` i `edited_after_acceptance` |
| Korekty (`src/Invoice`) | `CorrectionDrafts` (`observe`, `fromCreditNote`, `fromEdit`, `numberChanged`); wyniki `kor_draft_created`, `kor_draft_updated`, `kor_unchanged`, `kor_skipped` |
| Obejście i zadania (`src/Invoice`) | `FreshBooksBypass` (`check`), opcjonalny argument `KsefSync::$bypass` (po udanym przebiegu `subject1`); `SalesTasks` (`open`, `close`, stałe `TASKS`, `LINK`); trasa panelu `panel_freshbooks_task_close` |
| Subject1 | opcjonalne argumenty `Subject1Importer::$settings`, `$transitions` i `$tasks`: szkic `draft`, `ready` albo `approved` o tym samym `kind` i P_2 → `cancelled_before_send`, żywe zatwierdzenie → `cancelled` z `manual_invoice`; bez lokalnego wiersza od go-live → `panel_task` = `outside_cockpit` |
| Akcje audytu | `invoice.kor_drafted`, `invoice.panel_task` |
| Panel | sekcja „Zadania sprzedaży” w `panel/freshbooks.html.twig` |
| Testy i odbiór | `CorrectionTestTools`, `SalesTestTools::$corrections`; fixture `tests/Fixtures/FreshBooks/credit-notes.json`; klucze kryteriów `freshbooks-bypass-alert`, `subject1-cancels-approval`, `subject1-outside-cockpit-task`, `kor-draft-from-freshbooks` |

### Implementacja F3.3: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| `KsefGateway` (`src/Connector/Ksef`) | metody `supportsWrites`, `openSession`, `sendInvoice`, `invoiceStatus`, `closeSession`, `invoiceUpo`, `sessions`, `sessionInvoices`, `failedInvoices`; DTO `KsefCall` (`dispatch` tylko w trakcie wysyłki, `read`), `KsefSession`, `KsefInvoiceStatus`; `KsefException::$detail` (kod błędu z odpowiedzi) |
| `KsefError` | `SessionUnavailable` (`21184`), `RateLimited` (`rate_limited`: 429 albo `blocked_until` przy otwarciu sesji), `RequestRefused` (`request_refused`: inna odmowa 4xx) |
| Konektor zapisu | `KsefWriter` (`OfficialWriter` i `StatusReader` dla `ksef`, usługa w `services_connectors.yaml`) |
| Klient KSeF (`src/Connector/Ksef`, D-70) | `KsefApi` (`request`, `json`, `publicKey`, `target`), `KsefAuthenticator` (`accessToken`, `certificateAccessToken`, D-72), `KsefXades` (`authTokenRequest`, D-72), `KsefCrypto` (`rsaOaepSha256`, `aes`), `HttpKsefGateway`; `KsefApi::exportPart` (D-71); parametr `ksef.api_base` (cel → adres `/v2`); użycia kluczy MF `KsefTokenEncryption`, `SymmetricKeyEncryption`; testy `KsefTeProvision`, `MemoryVault`, `KsefTeTestCase`, `KsefTeIssuer` i `KsefTeSyncTest` (D-71), `KsefTeCredentialsTest` i `MfKeysRefreshTest` (D-72), nagrania `tests/Fixtures/Ksef/te-*.json` i `tests/Fixtures/Latarnia/te-status.json` |
| Latarnia (`src/Connector/Latarnia`) | `LatarniaConnector` (`status`, `messages`), `LatarniaStatus` (`AVAILABLE`, `MAINTENANCE`, `FAILURE`, `TOTAL_FAILURE`), `LatarniaStatusStore` (klucz `ksef.latarnia_status` w puli `cache.scheduler`), `LatarniaStatusHandler`; wiadomość `LatarniaStatusMessage` z id `latarnia.status` na `reads`; konfiguracja `latarnia.endpoints` (tylko cel `prod`, D-70) |
| `connector_call.operation` | `ksef`: `export_start`, `export_status`, `export_part` (D-71), `public_keys`, `auth_challenge`, `auth_token`, `auth_xades` (D-72), `auth_status`, `auth_redeem`, `auth_refresh`, `session_open`, `invoice_send`, `invoice_status`, `session_close`, `invoice_upo`, `sessions_list`, `session_invoices`, `session_failed`; `latarnia`: `status`, `messages` |
| Powody czekania (`OfficialWriter::waitingReason` dla `ksef`) | `connectors_disabled`, `target_mismatch`, `gateway_unavailable`, `credential_missing`, `blocked_until`, `latarnia_maintenance`, `latarnia_failure`, `latarnia_total_failure`, `latarnia_unreachable` |
| Klucze `approval.external_refs` (`ksef`) | `correlation_id`, `session_reference`, `ksef_session_id`, `invoice_reference`, `sent_at`, `ksef_number`, `invoice_hash` (Base64 z KSeF), `acquisition_date`, `invoicing_mode`, `upo_file_id`, `original_ksef_number`, `original_session_reference`, `reconciled_by` (`invoice_status`, `session_invoices`, `session_failed`, `sessions_list`, `subject1`) |
| `approval.result_code` (`ksef`) | kod statusu faktury KSeF (np. `200`, `440`, `450`), `form_code_mismatch`, `21184`, `p2_collision`, `subject1_match`, `rate_limited` |
| Przejście zatwierdzenia | `dispatching` → `approved`, zdarzenie `deferred`, wpis `dispatch.deferred`; wynik `SubmissionResult` = `deferred` (pierwsze wywołanie odrzucone 429 albo `blocked_until`, nic nie wyszło) |
| Kolumny i wyliczenia | `invoice.panel_task` = `p2_collision`; `notification.category` = `p2_collision` |
| Subject1 | `Subject1Importer`: lokalna sprzedaż o tym samym `kind` i P_2 w `submitted` albo `unknown` → zgodny sha256 z `xml_sent` daje `accepted` (`subject1_match`), różny `rejected` (`p2_collision`) i zwykły import |
| Testy i odbiór | `KsefSendTestTools`, `KsefSendTestCase`, `KsefRecordings` (odtwarzanie nagrań po metodzie i ścieżce, zmienne `{{nazwa}}`); nagrania `tests/Fixtures/Ksef/send-*.json`, `tests/Fixtures/Latarnia/status-*.json`; klucze kryteriów `ksef-send-accepted`, `ksef-send-hash-mismatch`, `ksef-send-once`, `ksef-send-p1-day-change`, `ksef-send-form-code`, `ksef-send-rejected`, `ksef-send-21184`, `ksef-send-timeout-reconcile`, `ksef-reconcile-paths`, `ksef-retry-440`, `ksef-send-latarnia`, `ksef-send-429` |

### Implementacja F3.4: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| KOD I (`src/Invoice`) | `KodI` (`url`, `sentXml`); konfiguracja `ksef.kod_i_hosts` (cel → adres bazowy: `test` → `https://qr-test.ksef.mf.gov.pl`, `demo` → `https://qr-demo.ksef.mf.gov.pl`, `prod` → `https://qr.ksef.mf.gov.pl`); `Fa3Builder::build` z opcjonalnym `$issueDate` (P_1 tylko do porównania w `app:ksef:parallel-compare`) |
| PDF (`src/Invoice`) | `InvoicePdf` (`render`, `store`), `InvoicePdfDocument` (`html`, `pdf`, `qrUrl`, `qrPng`), `InvoicePdfRefused`; szablon `templates/invoice/pdf.html.twig`; konfiguracja `invoice_pdf.cache_dir`; `stored_file.kind` = `pdf` z właścicielem `invoice` |
| Doręczenie (`src/Invoice`) | `ClientDelivery` (`deliver`, `lastDelivery`), `ClientDeliveryFailed` (powody `not_accepted`, `freshbooks_missing`, `client_email_missing`, `share_link_missing`, `freshbooks_failed`, `mail_disabled`, `mail_failed`); konfiguracja `client_delivery.from` |
| Zapis zwrotny (`src/Invoice`) | `FreshBooksWriteBack` (`writeBack`; wyniki `written`, `already_present`, `already_written`, `not_applicable`), wiadomość `FreshBooksWriteBackMessage` (transport `reads`); linia `notes` w stałej `FreshBooksWriteBack::NOTE` |
| Konektor `freshbooks` | `FreshBooksConnector` (`client`, `shareLink`, `updateInvoice`), DTO `FreshBooksClient` (`id`, `email`, `contact`), pole `FreshBooksInvoice::$notes`; `connector_call.operation` = `client`, `share_link`, `invoice_update` |
| Okres równoległy (`src/Invoice`) | `KsefParallelCompare` (`compare`, `csv`; kolumny `p1`, `p2`, `ksef_number`, `freshbooks_id`, `result`, `field`, `freshbooks`, `subject1`; wyniki wiersza `match`, `difference`, `xml_missing`, `freshbooks_missing`; odmowa mapowania to `difference` z polem `mapping`), komenda `KsefParallelCompareCommand` (`app:ksef:parallel-compare <rok-miesiąc>`) |
| Panel | `InvoiceController`: `/invoices` (`panel_invoices`), `POST /invoices/{id}/deliver` (`panel_invoice_deliver`, token CSRF `invoice_deliver`), `GET /invoices/{id}/pdf` (`panel_invoice_pdf`); szablon `panel/invoices.html.twig` |
| Akcje audytu | `invoice.delivered_to_client`, `invoice.written_back` (z planu F3) |
| Licencje | wyjątek LGPL w `tools/check-licenses.php` tylko dla `dompdf/dompdf`, `dompdf/php-font-lib`, `dompdf/php-svg-lib` (D-55) |
| Testy i odbiór | `PostAcceptanceTestTools`, `PostAcceptanceTestCase`, `ClientDeliveryWebTestCase`; dekoder QR w testach `khanamiryan/qrcode-detector-decoder` (`require-dev`); fixtures `tests/Fixtures/FreshBooks/client-201.json`, `share-link-2001.json`, `invoice-2001-accepted.json`, `invoice-2001-written-back.json`, `invoice-update-2001.json`, `parallel-compare-*.json`; klucze kryteriów `kod-i-url`, `invoice-pdf`, `deliver-to-client`, `deliver-to-client-error`, `freshbooks-write-back`, `ksef-parallel-compare` |

### Plan F5 (D-56): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Części | `F5.1` konektor `jpk` i bramka złożeń, `F5.2` JPK_ST(1), `F5.3` JPK_EWP i JPK_ST na test-e-dokumenty, `F5.4` JPK_V7M(3) i zamknięcie miesiąca, `F5.5` VAT-UE i VAT-UEK, `F5.6` JPK_V7M i VAT-UE na bramkach testowych; rejestr `tests/Acceptance/f5.yaml`, lista `docs/evidence/f5.md` |
| Konektor `jpk` (`src/Connector/Jpk`) | `JpkGateway` (własny cienki klient: `InitUploadSigned`, Put Blob, `FinishUpload`, `Status`), `JpkEnvelope` (ZIP DEFLATE, części do 62 914 560 bajtów, AES-256-CBC z PKCS#7, MD5 części w Base64, klucz AES opakowany RSA-2048 certyfikatem MF celu), `JpkInitUpload` (metadane `InitUpload`), `JpkWriter` (`OfficialWriter` i `StatusReader` dla `jpk`), `JpkError` (kody wyników z [modules/jpk.md](modules/jpk.md)); w testach `FakeJpkGateway` i fikcyjna para kluczy MF celu `test` |
| Konektor `edeklaracje` (`src/Connector/EDeklaracje`) | `EDeklaracjeGateway` (SOAP: `sendUnsignDocument`, `requestUPO`), `EDeklaracjeWriter` (`OfficialWriter` i `StatusReader` dla `edeklaracje`), `EDeklaracjeError`; w testach `FakeEDeklaracjeGateway` |
| Dane autoryzujące | `AuthDataDocument` (SIG-2008_v2-0 z `auth_data`; tylko w pamięci workera, przy wysyłce) |
| Generatory (`src/Xml`) | `JpkV7mBuilder` (JPK_V7M(3) z ewidencją i deklaracją), `JpkMarker` (znaczniki `NrKSeF`, `OFF`, `BFK`, `DI`), `VatUeBuilder` (VAT-UE(5) i VAT-UEK(5)); `JpkEwpBuilder`, `JpkStBuilder` i `JpkDocument` z F2.4 |
| Przedmiot bramki | `FilingSubject` (`ApprovalSubject` dla `subject_type` = `filing`; usługa `filing.subject`); warunki prośby z bramek „Zamknięcia miesiąca” i „Bramek przed budową JPK_EWP(4)” w [compliance-calendar.md](compliance-calendar.md) |
| Bilans otwarcia | `OpeningBalance` (`confirm`); panel „Otwarcie” `/opening` (token CSRF `opening`, step-up); akcja audytu `opening_balance.confirmed` |
| Zadania | `jpk.replace_di` (`JpkReplaceDi`) z [operations.md](operations.md) |
| Fixtures | fikcyjny zbiór `tests/Fixtures/Jpk` z „Fikcyjnego zbioru JPK” w [testing.md](testing.md); złote pliki `tests/Fixtures/Jpk/golden/jpk-v7m-*.xml`, `vat-ue-*.xml`; nagrania `tests/Fixtures/Jpk/api-*.json`, `tests/Fixtures/EDeklaracje/*.xml`; scenariusze `app:fixtures:record jpk <scenariusz>`, `app:fixtures:record edeklaracje <scenariusz>` |
| Klucze kryteriów | F5.1: `jpk-envelope`, `jpk-init-upload`, `jpk-auth-data`, `jpk-ewp-request`, `jpk-send-accepted`, `jpk-send-hash-mismatch`, `jpk-send-once`, `jpk-send-duplicate`, `jpk-send-reconcile`, `jpk-send-rejected`, `jpk-rate-limit`, `jpk-storage-host`; F5.2: `jpk-st-request`; F5.3: `jpk-te-ewp-upo`, `jpk-te-duplicate`, `jpk-te-st-upo`, `jpk-te-tls`, `jpk-te-fixtures`; F5.4: `jpk-v7m-golden`, `jpk-v7m-markers`, `jpk-v7m-declaration`, `jpk-v7m-gates`, `jpk-v7m-send`, `jpk-v7m-correction`, `jpk-replace-di`, `opening-balance-confirm`, `wht-obligation-amounts`; F5.5: `vat-ue-golden`, `vat-ue-k12-gate`, `vat-ue-kor-routing`, `edeklaracje-send-accepted`, `edeklaracje-status-codes`, `edeklaracje-rate-limit`; F5.6: `jpk-te-v7m-upo`, `edeklaracje-te-vat-ue`, `edeklaracje-te-vat-uek`, `edeklaracje-te-fixtures`; go-live: `jpk-ewp-prod-first`, `opening-balance-prod`, `jpk-v7m-prod-first`, `vat-ue-prod-first` |
| Kod | pozostałe klasy dopisuje sesja części przed kodowaniem; nazwy z tej tabeli są propozycją planu i mogą się zawęzić w „Implementacji F5.x” |

### Implementacja F5.1 (D-57): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Konektor `jpk` (`src/Connector/Jpk`) | `JpkGateway` (`initUploadSigned`, `assertStorage`, `putBlob`, `finishUpload`, `status`; stałe grup `BUCKET_INIT`, `BUCKET_UPLOAD`, `BUCKET_FINISH`, `BUCKET_STATUS`), `JpkCall` (`dispatch`, `read`), `JpkEnvelope` (`seal`), `JpkSealedFile` (`ivBase64`, `encrypt`, `partNames`), `JpkPart`, `JpkInitUpload` (`formCode`, `build`, `check`, `schemaAvailable`), `JpkUploadSession`, `JpkUploadTarget`, `JpkStatus`, `JpkMfCertificate` (`problem`, `pem`), `JpkException` (`of`, `schemaMissing`), `JpkWriter`; `AuthDataDocument` (`build`, `schemaAvailable`) |
| `JpkError` | kody API `136`, `140`, `142`, `158`, `170`, `100`, `101`, `120`, `200`, `300`, `407`, `411`; kody kokpitu `storage_host_not_allowed`, `upload_targets_mismatch`, `put_blob_failed`, `finish_upload_timeout`, `invalid_response`, `mf_key_invalid`, `xsd_missing`, `metadata_invalid`, `auth_data_invalid`, `no_reference` |
| `connector_call.operation` (`jpk`) | `init_upload_signed`, `put_blob`, `finish_upload`, `status` |
| Powody czekania (`OfficialWriter::waitingReason` dla `jpk`) | `connectors_disabled`, `target_mismatch`, `credential_missing` (`auth_data`), `mf_key_missing`, `mf_key_invalid`, `mf_key_expired`, `xsd_missing`, `rate_limited` |
| Klucze `approval.external_refs` (`jpk`) | `correlation_id`, `reference_number`, `timeout_s`, `init_upload_at`, `blob_names`, `finish_upload_at`, `status_code`, `original_reference_number`, `upo_file_id`, `accepted_at`, `reconciled_by` (`own_filing`), `original_approval_id`, `original_filing_id` |
| Parametry (`config/services_connectors.yaml`) | `jpk.endpoints`, `jpk.init_upload` (`document_type`, `version`, `max_bytes`), `jpk.part_max_bytes`, `jpk.tls_ciphers`, `jpk.mf_certificates`; limit `jpk.init_upload` w `connector.daily_limits` |
| Przedmiot bramki (`src/Ledger`) | `FilingSubject` (usługa `filing.subject`; w dev i test z atrapą `dummy.subject.filing` dla identyfikatorów bez `filing`), `FilingRequest` (`jpkEwp`), `FilingManualUpo` (`record`); `ApprovalSubject::connector(string $subjectId)` |
| Licznik | `RateCounter::exhausted` (odczyt bez zużycia: `blocked_until` albo wyczerpany limit dobowy) |
| Testy | `FakeJpkGateway`, `JpkSendTestTools`, `JpkSendTestCase`; nagrania `tests/Fixtures/Jpk/api-init-upload*.json`, `api-put-blob.json`, `api-finish-upload*.json`, `api-status-*.json`; schematy metadanych `initupload.xsd` i `SIG-2008_v2-0.xsd` z `resources/xsd` (D-63); test `JpkMetadataSchemaMissingTest` (pusty katalog schematów) |

### Implementacja F5.2 (D-64): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Generator (`src/Xml`) | `JpkStBuilder` (`build`; `SCHEMA` = `Schemat_JPK_ST(1)_v1-2.xsd`, `SCHEMA_VERSION` = `JPK_ST(1)`), `JpkTaxpayer` (`identity`, `birthDate`, `appendPerson`, `isDate`; `Podmiot1` wspólny dla JPK_EWP(4) i JPK_ST(1)) |
| Przedmiot bramki (`src/Ledger`) | `FilingRequest::jpkSt`; `FilingSubject::stEnabled` (flaga `jpk_st.enabled` na 31.12. roku), `FilingSubject::stDisabled` (komunikat Q-25); klucze `summary` JPK_ST: `rows`, `sum_f10`, `fixed_asset_count`, `checklist.jpk_st_enabled` |
| Klucze kryteriów | F2 (D-37): `jpk-st-build`, `jpk-st-dry-run`; F5.2: `jpk-st-request` |
| Testy i odbiór | `JpkStBuildTest`, `JpkStRequestTest`; `JpkTestTools` (`stBuilder`, `taxpayer`, `seedFixedAssets`, `stRows`, `stValue`), `JpkSendTestTools::filing(type)`; `scripts/jpk-acceptance.php st-dry-run`; artefakty `jpk/jpk-st-build.xml`, `cmd/jpk-st-build.txt`, `cmd/jpk-st-dry-run-off.txt`, `cmd/jpk-st-dry-run-on.txt`, `jpk/jpk-st-dry-run.xml`, `jpk/jpk-st-request.xml` |
| Fixtures | `tests/Fixtures/Jpk/fixed-assets-2026.json` (fikcyjny wykaz `fixed_asset`) |

### Implementacja F5.4 (D-65): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Generator (`src/Xml`) | `JpkV7mBuilder` (`build`; `SCHEMA` = `Schemat_JPK_V7M(3)_v1-0E.xsd`, `SCHEMA_VERSION` = `JPK_V7M(3)`), `JpkV7mDocument` (`valid`, `sha256`; `totals`, `declaration`, `diItems`, `p39Source`), `JpkMarker` (wyliczenie `NrKSeF`, `OFF`, `BFK`, `DI`; `of`, `wew`) |
| Bramki i zamknięcie (`src/Ledger`) | `V7mGates` (`evaluate`; bramki `sales_final`, `costs_reviewed`, `fx_rates`, `vies_eu_b2b`, `eu_b2b_unidentified`, `opening_balance`), `MonthClose` (`accepted`), `FilingRequest::jpkV7m`, `FilingRequest::jpkV7mCorrection`, `JpkReplaceDi` (`run`), `OpeningBalance` (`confirm`, `confirmed`) |
| Klucze `filing.totals` (JPK_V7M) | `p38`, `p39`, `p39_source` (`previous_filing`, `opening`), `p48`, `p51`, `p53`, `p62`, `k11`, `k12`, `sales_net`, `vat_due`, `vat_input`, `documents`, `sales_rows`, `purchase_rows`, `xml_sha256`, `p_ordzu`, `excess_policy` |
| Klucze `approval.summary` (JPK_V7M) | jak `totals` oraz `type`, `period`, `period_start`, `period_end`, `purpose`, `schema_version`, `di_items` (`register`, `lp`, `document`, `counterparty`, `reason`: `wew`, `offline_without_number`), `checklist`, `previous` (P_51 i P_62 złożenia zastępowanego) |
| Klucze `setting` | `vat.excess_policy` (`carry_forward`, Q-31), `wht.rates` (`standard` i `treaty` per kraj: stawki w procentach dla `license` i `services_21_2a`); kształty kluczy `opening.*`: `opening.vat_carry_forward_pln` (napis w pełnych złotych z `.00`), `opening.revenue_ytd` (stawka ryczałtu → kwota), `opening.zus_paid_ytd` (`social_pln`, `health_pln`), `opening.last_invoice_numbers` (seria → ostatni numer) |
| Panel | „Otwarcie” `/opening` (`panel_opening`), `POST /opening/confirm` (`panel_opening_confirm`), token CSRF `opening`; pliki `evidence_vat`, `evidence_revenue`, `evidence_zus`, `evidence_invoices` |
| Audyt | `opening_balance.confirmed`; `period.correction_required` z `cause` = `jpk.replace_di` i `target_id` faktury |
| Zadania | `jpk.replace_di` przez `LedgerTaskMessage` (transport `reads`, codziennie 09:00) |
| Testy i odbiór | `JpkV7mGoldenTest`, `JpkV7mMarkersTest`, `JpkV7mDeclarationTest`, `JpkV7mGatesTest`, `JpkV7mSendTest`, `JpkV7mCorrectionTest`, `JpkReplaceDiTest`, `OpeningBalanceConfirmTest`, `WhtObligationAmountsTest`; `JpkV7mTestTools`, `JpkV7mTestCase`; fikcyjny miesiąc `tests/Fixtures/Jpk/v7m-2026-11.json`; złoty plik `tests/Fixtures/Jpk/golden/jpk-v7m-golden.xml` |

### Implementacja F5.5 (D-66): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Generator (`src/Xml`) | `VatUeBuilder` (`build`, `buildCorrection`, `positions`; `SCHEMA_UE` = `VAT-UE(5)_v2-0E.xsd`, `SCHEMA_UEK` = `VAT-UEK(5)_v2-1E.xsd`, `SCHEMA_VERSION_UE` = `VAT-UE(5)`, `SCHEMA_VERSION_UEK` = `VAT-UEK(5)`), `VatUeDocument` (`valid`, `sha256`, `totals`; `positions`, `before`, `netPlnSum`, `pUcSum`) |
| Bramka i zamknięcie (`src/Ledger`) | `VatUeK12Gate` (`check`; wynik `k12`, `net_pln_sum`, `p_uc_sum`, `difference`, `rounding_difference`, `tolerance`, `v7m_filing_id`), `FilingRequest::vatUe` (trasa KOR: `vat_ue`, `wait`, `vat_uek`), `FilingRequest::vatUeRoute`, `MonthClose::vatUeAccepted` |
| Konektor `edeklaracje` (`src/Connector/EDeklaracje`) | `EDeklaracjeGateway` (`sendUnsignDocument`, `requestUpo`; stałe grup `BUCKET_SEND` = `edeklaracje.send`, `BUCKET_UPO` = `edeklaracje.request_upo`), `EDeklaracjeSoapClient` (podklasa `SoapClient` bez WSDL, `__doRequest` przez `ConnectorHttpClient`), `EDeklaracjeCall` (`dispatch`, `read`), `EDeklaracjeReceipt`, `EDeklaracjeStatus`, `EDeklaracjeDocument` (`authorize`: `DaneAutoryzujace` jako ostatni element `Deklaracja`, tylko w pamięci workera), `EDeklaracjeException`, `EDeklaracjeWriter` (`OfficialWriter` i `StatusReader` dla `edeklaracje`); `AuthDataDocument::element` |
| `EDeklaracjeError` | statusy bramki `100`, `101`, `102`, `200`, `300`, `301`, `302`, `303`, `306`, `401`, `408`, `409`, `410`, `411`, `412`, `414`, `418`, `419`, `422`, `423`; kody kokpitu `invalid_response`, `document_invalid`, `auth_data_invalid`, `send_no_response`, `no_reference` |
| `connector_call.operation` (`edeklaracje`) | `send_unsign_document`, `request_upo` |
| Powody czekania (`OfficialWriter::waitingReason` dla `edeklaracje`) | `connectors_disabled`, `target_mismatch`, `credential_missing` (`auth_data`), `xsd_missing`, `rate_limited` |
| Klucze `approval.external_refs` (`edeklaracje`) | `correlation_id`, `ref_id`, `send_status`, `sent_at`, `status_code`, `upo_file_id`, `accepted_at`, `decision_required` (`D-27` przy 410 i 418), `reconciled_by` (`own_filing`), `original_approval_id`, `original_filing_id` |
| Klucze `filing.totals` (`VAT_UE`, `VAT_UEK`) | `positions` (`country`, `vat_number`, `net_pln`, `p_uc`, `invoices`), `before` (pozycje złożenia zastępowanego, tylko `VAT_UEK`), `net_pln_sum`, `p_uc_sum`, `buyers`, `invoices`, `excluded_unidentified`, `k12`, `v7m_filing_id`, `difference`, `rounding_difference`, `tolerance`, `xml_sha256` |
| Parametry (`config/services_connectors.yaml`) | `edeklaracje.endpoints`, `edeklaracje.soap` (`namespace`, `language`, `signature_type`); limit `edeklaracje.send` w `connector.daily_limits` |
| Testy i odbiór | `VatUeGoldenTest`, `VatUeK12GateTest`, `VatUeKorRoutingTest`, `EDeklaracjeSendAcceptedTest`, `EDeklaracjeStatusCodesTest`, `EDeklaracjeRateLimitTest`; `VatUeTestTools`, `VatUeTestCase`, `FakeEDeklaracjeGateway`; fikcyjne uzupełnienie miesiąca `tests/Fixtures/Jpk/vat-ue-2026-11.json`; złoty plik `tests/Fixtures/Jpk/golden/vat-ue-golden.xml`; nagrania SOAP `tests/Fixtures/EDeklaracje/soap-send*.json`, `soap-upo-*.json` (treść SOAP w `body_text`); artefakty `cmd/vat-ue-k12-gate.txt`, `golden/vat-ue-golden.diff`, `db/edeklaracje-send-accepted-connector_call.csv`, `db/edeklaracje-rate-limit-rate_counter.csv`, `cmd/edeklaracje-status-<kod>.txt`; `JpkSendTestTools` dostaje `edeklaracje`, `edeklaracjeWriter` i `vatUe`; F5.6 (D-68): `EDeklaracjeLiveTestCase`, `EDeklaracjeTeVatUeTest`, `EDeklaracjeTeVatUekTest`, `EDeklaracjeLiveRecordingTest`, `LiveRecording` (katalog `var/live-recordings/<Connector>`), tryb live `KsefRecordings` (`recorded`), parametr `live` w `FakeEDeklaracjeGateway`, `JpkSendTestTools` i `VatUeTestCase`; nagrania bramki testowej `tests/Fixtures/EDeklaracje/live-send.json`, `live-upo.json`, `live-uek-send.json`, `live-uek-upo.json`; artefakty `cmd/edeklaracje-te-vat-ue.txt`, `cmd/edeklaracje-te-vat-uek.txt`; schemat `GateService.xsd` (D-67); F5.3 i JPK_V7M z F5.6 (D-69): trait `JpkLiveRun`, `JpkLiveTestCase`, `JpkTeEwpUpoTest`, `JpkTeStUpoTest`, `JpkTeDuplicateTest`, `JpkTeTlsTest`, `JpkTeV7mUpoTest`, `JpkLiveRecordingTest`, stała `JpkSendTestTools::LIVE_CERTIFICATE`, parametr `live` w `FakeJpkGateway`, `JpkSendTestCase` i `JpkV7mTestCase`; nagrania `tests/Fixtures/Jpk/live-ewp-*.json`, `live-st-*.json`, `live-v7m-*.json`; artefakty `cmd/jpk-te-ewp-upo.txt`, `cmd/jpk-te-st-upo.txt`, `cmd/jpk-te-duplicate.txt`, `cmd/jpk-te-duplicate-first.txt`, `cmd/jpk-te-tls.txt`, `cmd/jpk-te-v7m-upo.txt`, `jpk/jpk-te-*-xml.xml` |

### Plan F6 (D-58): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Części | `F6.1` dane czatu i rola `chat_ro`, `F6.2` konektor `claude` i pętla narzędzi, `F6.3` panel czatu i wykresy, `F6.4` szkice `draft_document`, `F6.5` Claude API na danych fikcyjnych; rejestr `tests/Acceptance/f6.yaml`, lista `docs/evidence/f6.md` |
| Konektor `claude` (`src/Connector/Claude`) | `ClaudeClientFactory` (klient `anthropic-ai/sdk` z `transporter` = dekorowany `Psr18Client`, ponowienia wyłączone), `ChatRequestProfile` (profil żądania Haiku 5.5), `ClaudeError` (wyliczenie kodów: `connectors_disabled`, `host_not_allowed`, `rate_limited`, `timeout`, `api_error`, `refusal`, `max_tokens`, `budget_exceeded`, `context_limit`, `invalid_response`); `connector_call.operation` = `messages.create` |
| Czat (`src/Chat`) | `ChatTurn` (pętla narzędzi), `ChatToolRegistry` (lista narzędzi i `tool_set`), `ChatToolExecutor`, `ChatQuery` (wyliczenie nazwanych zapytań), `ChatQueryRunner` (połączenie `chat`), `PseudonymMap`, `ChatBudget`, `ChatContextGuard`, `ChartSpec`, `DraftDocumentTool`, `DraftSubject` (`ApprovalSubject` dla `subject_type` = `draft`; usługa `draft.subject`); Symfony Workflow `draft` |
| Widoki `chat_*` | `chat_obligation`, `chat_invoice`, `chat_revenue`, `chat_counterparty`, `chat_fx_rate`, `chat_verification`, od D-95 `chat_filing`, `chat_payment`; połączenie Doctrine `chat` z rolą `chat_ro` |
| Konfiguracja aplikacji | `chat.max_tokens`, `chat.max_iterations` (8), `chat.timeout_s`, `chat.row_limit` (200), `chat.context_warn_tokens` (80 000), `chat.context_max_tokens` (100 000), `chat.budget_daily_usd`, `chat.budget_monthly_usd`, `chat.budget_alert_ratio` (0,8), `chat.pricing` (USD za MTok: wejście, wyjście, odczyt i zapis cache, próg 100 tys. tokenów), `chat.phases_live` (fazy po go-live, z których czat bierze narzędzia i wartości `query`), `chat.live_run_budget_usd` (tylko testsuite `live`); obok istniejących `chat.model` i `chat.effort` |
| Panel | trasa `/chat` (`ChatController`), token CSRF `chat`, ramka Turbo `chat-turn`, przełącznik „Szkic”, etykieta „asystent AI”, przycisk „Skopiowano” przy szkicu; biblioteka `symfony/ux-chartjs` (`render_chart()`, `ChatChartData::chart`, D-73); JavaScript panelu: `importmap.php`, `assets/app.js`, `assets/vendor`, `assets/styles/chat.css`; nonce CSP `CspNonce` (funkcja Twig `csp_nonce()`, atrybut żądania `_csp_nonce`, `meta csp-nonce`); test `PanelImportmapTest` (D-73) |
| Fixtures | `tests/Fixtures/Claude/messages-*.json` (ręcznie pisane odpowiedzi Messages API z danymi fikcyjnymi, potem nagrania F6.5); scenariusze `app:fixtures:record claude <scenariusz>` |
| Klucze kryteriów | F6.1: `chat-ro-denied`, `chat-views-environment`, `chat-views-minimal`, `chat-named-queries`, `chat-history-append-only`, `chat-retention`; F6.2: `claude-request-profile`, `chat-model-fixed`, `claude-transport`, `chat-tool-loop`, `chat-tool-schema`, `chat-tool-set`, `chat-stop-reasons`, `chat-interrupted-turn`, `chat-rate-limit`, `chat-budget`, `chat-context-limit`, `chat-redaction`, `chat-law-snapshot`, `claude-live-guard`; F6.3: `chat-turbo-frame`, `chat-seq-idempotent`, `chat-render-chart`, `chat-panel-messages`; F6.4: `draft-requested-only`, `draft-recipient-db`, `draft-flagged-values`, `draft-approval-copy`; F6.5: `claude-live-smoke`, `claude-fixtures-record`; go-live: `chat-dpa`, `chat-spend-limit` |
| Kod | pozostałe klasy dopisuje sesja części przed kodowaniem; nazwy z tej tabeli są propozycją planu i mogą się zawęzić w „Implementacji F6.x” |

### Implementacja F6.1 (D-59): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Czat (`src/Chat`) | `ChatQuery` (wartości `revenue_by_month`, `sales_by_currency`, `costs_by_month`, `invoice_list`, `top_counterparties`, `get_obligations`, `get_fx_rate`, `verify_counterparty`; `isInvoiceQuery`, `requiredParameters`, `optionalParameters`), `ChatQueryRunner` (`run`), `ChatQueryResult` (`rows`, `truncated`, `rowLimit`, `notice`, `toArray`; komunikaty `NO_FX_RATE` „brak zapisanego kursu”, `NO_VERIFICATION` „brak zapisanej weryfikacji”), `ChatQueryRejected` (`reason`), `ChatRetention` (`purge`, część `retention.purge`) |
| `ChatQueryRejected.reason` | `unknown_parameter`, `invalid_type`, `missing_parameter`, `invalid_date`, `date_range`, `invalid_currency`, `invalid_status`, `invalid_counterparty`, `date_in_future` |
| Parametry nazwanych zapytań | `date_from`, `date_to`, `currency` (wartości `query`, opcjonalny), `status` (`get_obligations`), `date` (`get_fx_rate`), `counterparty` (`verify_counterparty`, UUID po tłumaczeniu pseudonimu) |
| Kolumny wyników | `month`, `amount_pln`, `entries`, `currency`, `net_total`, `vat_total`, `gross_total`, `net_pln`, `invoices`, `counterparty_id`, `age_days` i kolumny widoków `chat_*` |
| Schemat | `chat_session`, `chat_message`, `draft` (CHECK `kind` = `email`, `status`); indeksy `chat_session_closed`, `draft_chat_session`; funkcja `prevent_chat_message_update` i trigger `chat_message_append_only` |
| Środowisko widoków | ustawienie sesji PostgreSQL `kokpit.environment` (`set_config` w transakcji zapytania); brak ustawienia → widoki bez wierszy |
| Konfiguracja aplikacji | `chat.currencies` (wyliczenie parametru `currency`), `chat.retention_months` (12); obok `chat.row_limit` (200) |
| Połączenie i sekrety | połączenie Doctrine `chat` (usługa `doctrine.dbal.chat_connection`); sekret `DB_CHAT_RO_PASSWORD`; plik hosta `chat-ro-password` w wolumenie `app_credentials` (`/run/app/chat-ro-password` w `db`, `/run/kokpit/db/chat-ro-password` w `app`) |
| Testy | `ChatTestTools`; `ChatRoDeniedTest`, `ChatViewsEnvironmentTest`, `ChatViewsMinimalTest`, `ChatNamedQueriesTest`, `ChatHistoryAppendOnlyTest`, `ChatRetentionTest` |

### Implementacja F6.2 (D-60): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Zależność | `anthropic-ai/sdk` 0.58.0 (dokładna wersja); opcje `RequestOptions::with(transporter:, streamingTransporter:, maxRetries: 0, timeout:)`; wywołanie `messages->raw->create` z ciałem w formacie przewodowym |
| Konektor `claude` (`src/Connector/Claude`) | `ClaudeClientFactory` (`create`; stałe `CONNECTOR` = `claude`, `OPERATION` = `messages.create`, `BUCKET` = `claude.messages`), `ClaudeMessages` (`create`), `ClaudeResponse` (`decode`, `blocks`, `text`; `content`, `stopReason`, `refusalCategory`, `usage`, `requestId`), `ClaudeException` (`error`, `blockedUntil`), `ChatRequestProfile` (`MODEL`, `EFFORTS`, `assertValid`, `body`); nowa wartość `ClaudeError` = `iteration_limit` |
| Czat (`src/Chat`) | `ChatTurn` (`ask`), `ChatTurnResult` (`error`, `text`, `requests`, `suggestNewSession`, `blockedUntil`, `refusalCategory`, `message`), `ChatSessions` (`start`, `resolve`, `matches`, `find`, `lawSnapshot`, `systemPromptSha`), `ChatToolRegistry` (`tools`, `names`, `schema`, `queryValues`, `toolSet`; `PHASES`, `PSEUDONYM_PATTERN`), `ChatToolExecutor` (`execute`, `interrupted`), `ChatRedaction` (`question`, `value`), `PseudonymMap` (`pseudonym`, `resolve`, `toJson`), `ChartSpec`, `ChatBudget` (`cost`, `assertAvailable`, `alerts`, `spentSince`), `ChatContextGuard` (`promptTokens`, `warns`, `exceeds`, `estimate`), `ChatConfigurationCheck` (compiler pass w `Kernel::build`); `ChatQueryRunner::check` |
| Wartość `tool_set` | `ts-` i 16 znaków hex sha256 listy narzędzi z żądania |
| Pseudonimy | `K-NN` (`K-01`, `K-02`, …; wzorzec `^K-[0-9]{2,3}$`) w `chat_session.pseudonym_map` (pseudonim → `counterparty.id`) |
| Błędy wyniku narzędzia (`tool_result` z `is_error`) | nowe `ChatQueryRejected.reason`: `unknown_tool`, `invalid_enum`, `invalid_pattern`, `invalid_query`, `unknown_pseudonym`, `invalid_chart`, `draft_not_requested` (do F6.4); poza `ChatQueryRejected`: `query_failed`, `interrupted` |
| Ustawienie `setting` | `chat.law_snapshot` (`items`: `name`, `value`, `legal_source`; `note`); kolumna `chat_session.law_snapshot_from` = `valid_from` wiersza |
| Konfiguracja aplikacji | `chat.system_prompt`, `claude.base_url`; wartości: `chat.effort` `low`, `chat.max_tokens` 8000, `chat.timeout_s` 90, `chat.budget_daily_usd` `1.00`, `chat.budget_monthly_usd` `10.00`; `chat.phases_live` pusty przy `APP_ENV=prod` do go-live F6.4 |
| Sekret | `CLAUDE_<ENV>_API_KEY` w Symfony Secrets (`CLAUDE_TEST_API_KEY` fikcyjny w sejfie testowym) |
| Push | `notification.category` = `budget_alert`, `dedupe_key` `chat:budget:<daily|monthly>:<okno>:<alert|exceeded>`, link `/chat` |
| Live | zmienna `CHAT_LIVE_RUN_BUDGET_USD` (wartość `chat.live_run_budget_usd`), `LiveRunBudget` (`src/Architecture`: `fromEnvironment`, `start`, `current`, `record`, `exhausted`), `ClaudeLiveBudget` (`tests/Support`: `guard`, `record`) |
| Testy | `ClaudeTestTools`, `ClaudeTestCase`; fixtures `tests/Fixtures/Claude/messages-*.json` z `README.md`; testy `ClaudeRequestProfileTest`, `ChatModelFixedTest`, `ClaudeTransportTest`, `ChatToolLoopTest`, `ChatToolSchemaTest`, `ChatToolSetTest`, `ChatStopReasonsTest`, `ChatInterruptedTurnTest`, `ChatRateLimitTest`, `ChatBudgetTest`, `ChatContextLimitTest`, `ChatRedactionTest`, `ChatLawSnapshotTest`, `ClaudeLiveGuardTest` |
| Wycofane z planu F6 | `DraftDocumentTool` i `DraftSubject` czekają na F6.4 (bez zmiany nazw) |

### Implementacja F6.3 (D-61): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Panel (`src/Controller`) | `ChatController`: trasy `panel_chat` (`GET /chat`, parametr `session`), `panel_chat_new` (`POST /chat/new`), `panel_chat_close` (`POST /chat/{id}/close`), `panel_chat_ask` (`POST /chat/{id}/ask`); pola formularza `_token` (token CSRF `chat`), `seq`, `question`, `draft` (przełącznik „Szkic”); nagłówek `Turbo-Frame` = `chat-turn` daje samą ramkę |
| Czat (`src/Chat`) | `ChatPanel` (`FRAME` = `chat-turn`, `sessions`, `start`, `close`, `owned`, `nextSeq`, `ask`, `frame`), `ChatPanelResult` (`turn`, `notice`, `questionSeq`, `replayed`, `message`), `ChatFrame` (`sessionId`, `turns`, `notice`, `suggestNewSession`, `nextSeq`, `closed`), `ChatPanelTurn` (`seq`, `question`, `draftRequested`, `answer`, `status`, `charts`), `ChatChart` (`fromToolResult`, `data`), `ChatChartData` (`query`, `chartType`, `parameters`, `datasetLabel`, `labels`, `values`, `truncated`, `view`); wyliczenie `ChatNotice` |
| Wyliczenie `ChatNotice` | `seq_stale`, `answer_missing`, `turn_incomplete`, `config_changed`, `session_closed`, `empty_question` |
| Blokada | `chat.turn.<chat_session.id>` (Symfony Lock): jedno pytanie rozmowy naraz, drugie wysłanie tej samej `seq` czeka i dostaje zapisaną odpowiedź |
| Szablony | `templates/panel/chat.html.twig`, `templates/panel/_chat_frame.html.twig`; identyfikatory `#chat-sessions`, `#chat-ai-label`, `#chat-transcript`, `#chat-turn-<seq>`, `#chat-notice`, `#chat-suggest-new`, `#chat-form`, `.chat-working`, `.chat-chart` (atrybuty `data-chart-query`, `data-chart-type`, `data-chart-labels`, `data-chart-values`); `canvas` w formacie kontrolera `symfony--ux-chartjs--chart` |
| Konfiguracja | `chat.session_list_limit` (50); limit czasu żądania panelu 780 s (`chat.max_iterations` × `chat.timeout_s` + 60 s zapasu) w `request_terminate_timeout` puli PHP-FPM (`docker/php/zz-kokpit-pool.conf`) i `fastcgi_read_timeout` proxy (`docker/proxy/*.conf`) |
| Dowody | podkatalog paczki `screens/<klucz>.html` w `EvidenceArtefacts` i `EvidencePack` |
| Testy | `ChatWebTestCase`, `ClaudeTestTools::panel`; testy `ChatTurboFrameTest`, `ChatSeqIdempotentTest`, `ChatRenderChartTest`, `ChatPanelMessagesTest`; fixtures `tests/Fixtures/Claude/messages-render-chart.json`, `messages-chart-end-turn.json`, `messages-context-limit.json` |

### Implementacja F6.4 (D-62): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Czat (`src/Chat`) | `DraftDocumentTool` (`create`), `DraftSubject` (usługa `draft.subject`, alias klasy; `canonical`, `moveTo`, `list`), `DraftState` (obiekt znakowania workflow: `getStatus`, `setStatus`), `Drafts` (`fromApproval`, `confirm`, `edit`, `discard`), `DraftValueCheck` (`flag`, `TYPES`); `ChatToolExecutor::execute` z argumentem `$sessionId` |
| Symfony Workflow | `draft` (`state_machine`, magazyn `method` na polu `status`, usługa `state_machine.draft`); przejścia `approve` (`pending` → `approved`), `reopen` (`approved` → `pending`), `execute` (`approved` → `executed`), `discard` (`pending`, `approved` → `discarded`) |
| Bramka (`src/Approval`) | `ApprovalGate::requestByUser` (prośba z Twojej akcji bez push); `ApprovalGate::markCopied` zwraca `accepted`, `expired` albo `cancelled` |
| Błędy wyniku narzędzia | nowe `ChatQueryRejected.reason`: `invalid_draft`; poza `ChatQueryRejected`: `draft_failed`; `draft_not_requested` zostaje dla szkicu bez flagi |
| Pozycja `draft.flagged_values` | `type` (`iban`, `nrb`, `nip`, `amount`), `value`, `confirmed_at`, `confirmed_by` |
| Pozycja `draft.provenance` | `source` = `chat_message` (`seq`, `sha256`, `excerpt`) albo `source_ref` (`pseudonym`) |
| Wynik `draft_document` | `status`, `recipient`, `flagged_values` (liczba), `untrusted_sources` (liczba), `message` |
| Payload `outbound_payload` | kanoniczny JSON: `body`, `kind`, `recipient` (`country`, `name`, `nip`), `subject` |
| Panel | trasa `panel_draft_action` (`POST /approvals/{id}/draft/{action}`, akcje `confirm`, `edit`, `discard`; pola `confirm[]`, `subject`, `body`; token CSRF `approvals`); klasy `.draft-preview`, `.draft-body`, `mark.draft-flagged`, `.draft-sources`, `.draft-confirm`, `.draft-edit`, `.chat-draft-link` |
| Testy | `DraftTestTools`, `ClaudeTestTools::responseWith` (znaczniki `{{NAZWA}}` w fixture, np. `FICTIONAL_IBAN`), `ApprovalTestTools::owner` (publiczna); testy `DraftRequestedOnlyTest`, `DraftRecipientDbTest`, `DraftFlaggedValuesTest`, `DraftApprovalCopyTest`, `DraftApprovalCopyPanelTest`; fixtures `tests/Fixtures/Claude/messages-draft-document.json`, `messages-draft-recipients.json`, `messages-draft-flagged.json`; dowody `cmd/draft-requested-only.txt`, `db/draft-approval-copy-approval.csv`, `screens/draft-approval-copy.html` |

### Obserwacja prawa (D-75): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Konektor (`src/Connector/Eli`) | `eli`, `EliConnector` (`references`, `process`, `fetch`, `isEli`, `isProcess`, `referencesUrl`, `processUrl`, `parseReferences`, `parseProcess`); operacje `connector_call` `references` i `process`; licznik `eli.read`; host `api.sejm.gov.pl` |
| Obserwacja (`src/LegalWatch`) | `LegalWatch` (`run`, wynik `checked`, `hits`, `failed`; link `/`), `LegalWatchReport` (`draftRows`, `markReviewed`, `NEXT_CHECK_DAYS`) |
| Tabele | `legal_watch` (`kind`: `act`, `process`; `ref`, `label`, `snapshot`, `snapshot_sha256`, `checked_at`, `changed_at`), `legal_watch_hit` (`legal_watch_id`, `snapshot_sha256`, `changes`, `detected_at`, `reviewed_at`); indeks `legal_watch_hit_open` |
| Pozycja `legal_watch_hit.changes` | akt: `category`, `eli`, `displayAddress`, `title`, `date`; proces: `stage`, `title` |
| Konfiguracja | `eli.base_url`, `legal_watch.items` (`kind`, `ref`, `label`), `legal_watch.act_categories` |
| Messenger | `LegalWatchMessage` (`legal_watch.check`, transport `reads`), handler `LegalWatchHandler` |
| Push | `notification.category` = `legal_change`, `dedupe_key` `legal_watch:<legal_watch_id>:<snapshot_sha256>:push` |
| Komendy | `app:legal-watch check`, `app:legal-watch report [--mark-reviewed]` |
| Testy | `LegalWatchTest`, `EliConnectorParseTest`; fixtures `tests/Fixtures/Eli/references-du-2025-1815.json`, `references-du-2025-1815-amended.json`, `references-du-2025-1294.json`, `references-du-2099-404.json`, `process-term10-2453.json`, `process-term10-2453-next-stage.json` |

### Import wyciągu bankowego (D-108): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Kod (`src/Bank`) | `BankStatementParser` (`format`, `supports`, `parse`; tag `app.bank_statement_parser`), `MbankCsvParser` (format `mbank_csv`), `ParsedStatement`, `BankLine`, `BankLineKind`, `BankLineMatcher` (`propose`), `BankMatch` (`token`, `targets`), `BankMatchRule` (`Cost` od D-110), `BankPreview` (`fingerprint`), `BankStatements` (`upload`, `preview`, `confirm`, `discard`, `MAX_BYTES`), `BankStatementRefused`; `IsoDate::isDate` |
| Tabela `bank_statement` | `format`, `file_sha256`, `file_id`, `original_name`, `period_from`, `period_to`, `status`, `lines_in_file`, `lines_added`, `matches_applied`, `uploaded_at`, `uploaded_by`, `decided_at`, `decided_by`; indeksy `bank_statement_file`, `bank_statement_pending` |
| Tabela `bank_statement_line` | `statement_id`, `line_hash`, `account`, `booked_on`, `amount`, `currency`, `operation_type`, `description`, `counterparty_account`, `kind`, `split_payment`, `invoice_id`, `payment_id`, `obligation_id`, `match_rule`, `matched_at`, `matched_by`; indeksy `bank_statement_line_statement`, `bank_statement_line_invoice_id`, `bank_statement_line_payment_id`, `bank_statement_line_obligation_id` |
| `bank_statement.format` | `mbank_csv` |
| `bank_statement.status` | `pending`, `confirmed`, `discarded` |
| `bank_statement_line.kind` | `incoming`, `outgoing`, `zus`, `tax`, `internal` |
| `bank_statement_line.match_rule` | `invoice_title_amount`, `payment_title_amount`, `zus_amount`, `tax_amount`, `cost_invoice_amount` (D-110) |
| Pliki | `stored_file` `evidence` (`text/csv`) z `owner_table` = `bank_statement` |
| Audyt | `bank_statement.uploaded`, `bank_statement.confirmed`, `bank_statement.discarded`; zapłata obowiązku jak wpis ręczny: `obligation.payment_recorded`; zapłata kosztu jak wpis ręczny: `cost.paid` (D-110) |
| Panel | `BankController`: trasy `panel_bank` (`GET /bank`), `panel_bank_upload` (`POST /bank/upload`, pole `statement`), `panel_bank_statement` (`GET /bank/{id}`), `panel_bank_confirm` (`POST /bank/{id}/confirm`, pola `fingerprint`, `match[]`, `complete`), `panel_bank_discard` (`POST /bank/{id}/discard`); token CSRF `bank`; szablony `templates/panel/bank.html.twig`, `templates/panel/bank_statement.html.twig`; identyfikatory `#bank-notice`, `#bank-error`, `#bank-problems`, `#bank-upload`, `#bank-statements`, `#bank-statement-<id>`, `#bank-preview`, `#bank-preview-error`, `#bank-summary`, `#bank-lines`, `#bank-line-<line_hash albo id>` |
| Migracje | `Version20261028000100`; reguła `cost_invoice_amount`: `Version20261029000910` (D-110) |
| Testy | `MbankCsvParserTest`, `BankLineMatcherTest`, `BankStatementsTest`, `BankPanelTest`, `BankTestTools`; fixture `tests/Fixtures/Bank/mbank-lista-operacji.csv` (NRB jako znaczniki `{account:N}`) |

### PIT-28 przez e-Deklaracje (D-96): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Generator (`src/Xml`) | `Pit28Builder` (`variants`, `schemas`, `variant`, `variantMissing`, `inputs`, `amounts` (D-101), `build`; `SCHEMA_VERSION_PREFIX` = `PIT-28`; wariant 2025: `VARIANT_27` = `PIT-28(27)`, `SCHEMA_27` = `PIT-28(27)_v1-1E.xsd`, `TNS_27`), `Pit28Document` (`valid`, `sha256`; `year`, `variant`, `schema`, `totals`) |
| Bramka i zamknięcie (`src/Ledger`) | `FilingRequest::pit28`, `MonthClose::pit28Accepted`; konektor `edeklaracje` dla `filing.type` `PIT_28` |
| Ustawienia | `pit_28.review` (`variant`, `reviewed_on`, `note`) w `SettingKeys::PIT_28_REVIEW` i w panelu `/settings`; `taxpayer.residence_address` (`province`, `county`, `municipality`, `street`, `building`, `unit`, `city`, `postal_code`) w `SettingKeys::TAXPAYER_RESIDENCE_ADDRESS`, sekcja `#taxpayer-residence_address` na `/settings` |
| Kwoty `Pit28Builder::amounts` (D-101) | klucze `inputs` oraz `rates` (per stawka: `revenue`, `share`, `social`, `health`, `net`, `base`, `ryczalt`), `social_deductible`, `social_deducted`, `health_deducted`, `base`, `ryczalt`, `ryczalt_due` |
| Klucze `filing.totals` (`PIT_28`) | kwoty z `Pit28Builder::inputs`: `revenue` (per stawka), `revenue_total`, `social_paid`, `health_paid`, `health_deductible`, `advances_paid`; z budowy: `social_deducted`, `health_deducted`, `base`, `ryczalt`, `ryczalt_due`, `to_pay`, `overpaid`, `advances_computed`; `jpk_ewp_filing_id`, `xml_sha256` |
| Panel | trasa `panel_filings_request` (`POST /filings/request`, pola `type`, `period`, `reason`), sekcja `#filing-request` na `/filings`; `FilingController::REQUEST_TYPES` |
| Testy | `Pit28RequestTest`, `Pit28ReviewSettingTest`, `FilingPanelTest::testFilingRequestValidatesPeriodAndRefusesWithoutConnectors`; `JpkSendTestTools::$pit28`; `Pit28GoldenTest`, `Pit28SendAcceptedTest`, `Pit28TestCase`, `Pit28TestTools`, `ResidenceAddressSettingTest`, live `EDeklaracjeTePit28Test`; złoty plik `tests/Fixtures/Jpk/golden/pit-28-golden.xml` |
| Kryteria i dowody | `pit-28-golden`, `pit-28-send-accepted` (`ci`), `edeklaracje-te-pit-28` (`live`) w `tests/Acceptance/f5.yaml`; artefakty `golden/pit-28-golden.diff`, `cmd/edeklaracje-te-pit-28.txt`; nagrania `live-pit28-send`, `live-pit28-upo` |
| Schematy (`resources/xsd`) | `PIT-28(27)_v1-1E.xsd` z importami `PIT_O(30)_Z28_v1-0E.xsd`, `PIT_D(31)_Z_v1-0E.xsd`, `PIT-2K(9)_Z_v2-0E.xsd`, `PIT-28_B(20)_Z_v1-0E.xsd`, `ORD-ZU(3)_v10-0E.xsd`, `KodyUrzedowSkarbowychExWUS_v8-0E.xsd`, `KodyWalut_v2-0E.xsd` |

### Narzędzia okresu w czacie (D-95): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Narzędzia czatu | `get_period_summary` (`month`), `get_cashflow` (`date_to`), `compare_periods` (`date_from`, `date_to`, `compare_from`, `compare_to`); faza w `chat.phases_live`: `F2` |
| Czat (`src/Chat`) | `ChatQuery::PeriodSummary`, `ChatQuery::Cashflow`, `ChatQuery::ComparePeriods`; stała `ChatToolRegistry::MONTH_PATTERN` |
| `ChatQueryRejected.reason` | `invalid_month` |
| Widoki | `chat_filing` (`type`, `period_start`, `purpose`, `seq`, `status`, `accepted_at` i z `filing.totals`: `sales_net`, `vat_due`, `vat_input`, `k11`, `k12`, `p51`, `p62`), `chat_payment` (`invoice_id`, `paid_on`, `amount`, `currency`, `matched_at`); migracja `Version20261027000900` |
| Kolumny wyników | `revenue_pln`, `jpk_v7m_status`, `jpk_v7m_purpose`, `advance_due`, `zus_due` (`get_period_summary`); `kind` (`obligations`, `receivables`, `payables`), `amount`, `items` (`get_cashflow`); `period` (`current`, `compare`), `sales_net_pln`, `sales_invoices`, `costs_net_pln`, `cost_invoices` (`compare_periods`) |
| Testy | `ChatPeriodToolsTest` |

### Zestaw ewaluacyjny czatu (D-114): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Zestaw (`tests/Fixtures/Claude/eval`) | `cases.yaml` (lista `cases`; pola przypadku `id`, `category`, `question`, `tools`, `no_tools`, `replay`, `numbers`; pola wywołania `name`, `input`, `step`, `error`), nagranie przypadku `<id>.json` (`fictional`, `case`, `source`, `responses`), `manifest.json` (`source`, `recorded_on`, `model`, `effort`, `tool_set`, `system_prompt_sha256`) |
| Wartości `source` | `hand_written`, `live` |
| Kategorie przypadków | `obligations`, `revenue`, `sales`, `costs`, `invoices`, `counterparties`, `fx`, `period`, `cashflow`, `compare`, `chart`, `law`, `refuse`, `guard` |
| Wynik przypadku | `status`: `pass`, `fail`, `skipped`; pola `failures`, `calls`, `requests`, `cost_usd`, `unsupported_numbers` |
| Testy (`tests/`) | `ChatEvalRunner` (`tests/Support`: `profile`, `cases`, `manifest`, `recording`, `seed`, `run`, `lastSession`, `skipped`, `report`, `ratio`, `numbers`; stałe `NOW`, `SNAPSHOT`, `MIN_LIVE_PASS_RATIO` = `0.90`), `ChatEvalTest` (offline, testsuite `integration`), `ChatEvalLiveTest` (testsuite `live`, `#[Group('live')]`); opcje `profile` i `prompt` w `ClaudeTestTools::turn` |
| Live | zmienne `CHAT_EVAL_LIVE` (`1` włącza przebieg) i `CLAUDE_TEST_API_KEY` (klucz workspace testowego tylko na czas przebiegu, do `MemoryVault`); nagrania przebiegu `var/live-recordings/Claude/eval/<id>.json` i `manifest.json` |
| Raport i artefakty | `var/chat-eval/offline.{json,md}`, `var/chat-eval/live.{json,md}`; artefakt joba `integration` `chat-eval`; w paczce dowodów `cmd/chat-eval-offline.txt`, `cmd/chat-eval-live.txt` |

### Kreator pierwszego uruchomienia (D-91): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Ustawienia (`src/Setting`) | `SetupChecklist` (`steps`, `pending`, `sendTest`; stałe `IDENTITY_REQUIRED`, `CREDENTIALS`, `TEST_ACTION`) |
| Panel | `SetupController`: trasy `panel_setup` (`GET /setup`), `panel_setup_notification_test` (`POST /setup/notification-test`, token CSRF `setup`) |
| Klucze kroków | `passkeys`, `company_identity`, `company_tax_micro_account`, `company_zus_account`, `zus_variant`, `ryczalt_advance_frequency`, `ryczalt_service_map`, `ksef_sales_transition_on`, `zus_parameters`, `opening_balance`, `credential_ksef_token_read`, `credential_auth_data`, `credential_freshbooks_oauth`, `notification_test` |
| Akcje audytu | `notification.test_sent` |
| Szablon | `templates/panel/setup.html.twig`; identyfikatory `#setup-summary`, `#setup-steps`, `#setup-step-<klucz>` (klasa `setup-done` albo `setup-pending`), `#setup-notification-test`; na pulpicie `#setup-pending` |
| Testy | `SetupPanelTest` |

### Pierwsza instalacja na VPS (D-98): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Skrypty | `scripts/prod-install.sh --node <nazwa> [--host-dir <katalog>] [--mail-host <host>] [--mail-from <adres>] [--mail-to <adres>]` albo `--rehearsal [--keep]`; `scripts/prod-cert.sh` (certyfikat panelu, cron odnowienia); `scripts/init-prod-vault.php` (sejf PROD) |
| Plik wdrożenia | `/etc/kokpit/prod.env` (bez sekretów); zmienna Makefile `KOKPIT_ENV_FILE`; cron hosta `/etc/cron.d/kokpit-panel-cert`, znacznik logu `kokpit-cert` |
| Pliki hosta | `secrets/tailscale-auth`, `restic-password`, `restic-repository`, `s3-access-key`, `s3-secret-key`, `deadman-backup-url`, `pgpass`; `ntfy/server.yml`, `ntfy/phone-password` |
| Pliki haseł bazy | `owner_credentials`: `postgres-password`, `owner-password`, `backup-password`; `app_credentials`: `app-password`, `chat-ro-password` |
| ntfy | użytkownicy `kokpit` (publikacja tokenem w temacie `kokpit`) i `telefon` (tylko odczyt); `auth-default-access: deny-all` |
| Zmienne | `TS_HOSTNAME` (nazwa węzła Tailscale), `HEALTH_ATTEMPTS`, `HEALTH_INTERVAL` (ponowienia `/health` w `make deploy`) |
| Sekret | `DEADMAN_PROD_KSEF_SYNC_URL`; check `ksef.sync` pingowany po udanym eksporcie na celu `prod` |
| Próba | projekt compose `kokpit-rehearsal`; nazwa panelu `<node>.rehearsal.invalid`; zmienne `APP_IMAGE`, `PROXY_IMAGE` (domyślnie `kokpit-app:local`, `kokpit-proxy:local`) |
| Testy | `MakefileTest::testHealthIsRetriedUntil200`, `MakefileTest::testDeploymentFileWinsOverTheShell`, `SchedulerTest::testScheduledMessagesSurviveTheTransportSerializer`, `KsefSyncTest::testExportOnProdTargetPingsKsefSyncDeadManAndPingFailureKeepsTheExport` |

### Test odtworzenia jednym poleceniem (D-89): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Skrypt | `scripts/restore-test.sh --secrets <katalog> [--expected <plik>] [--snapshot <id>] [--repo-dir <katalog>] [--keep]`; pliki katalogu jak u usługi `backup`: `restic-password`, `restic-repository`, `s3-access-key`, `s3-secret-key` |
| Operacje (`src/Ops`) | `RestoreCheck` (`previousMonth`, `counts`, `countsDocument`, `snapshots`, `report`, `parseCounts`, `restoredEntry`; stałe `KIND` = `kokpit.restore_check`, `COUNTS_KIND` = `kokpit.restore_counts`, `COUNTED`) |
| Komendy | `app:ops:restore-check` (`RestoreCheckCommand`), `app:ops:record-restore` (`RecordRestoreCommand`); wpis `ops.restored` |
| Klucze raportu | `kind`, `checked_at`, `commit`, `schema_version`, `snapshot`, `oldest_snapshot`, `files`, `audit` (`chain_valid`, `head`, `anchors`), `counts` (`month`, `restored`, `expected`, `matches`), `passed` |
| Pliki | `var/evidence/restore/kokpit-restore-<RRRRMMDD>T<GGMMSS>Z.json` (0600, poza repo); w wolumenie `backup_state` projektu `kokpit-restore`: `snapshots.json`, `target.json`, `restore/` |
| Testy | `RestoreCheckTest`, `RestoreScriptTest` |

### Poczta przychodząca (D-92): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Konektor (`src/Connector/MailIn`) | `mail_in`, `MailInConnector` (`fetch`, `configured`), `ImapClient` (`assertReadOnly`, `connect`, `login`, `examine`, `uidsFrom`, `sizes`, `message`, `logout`, `sentDigest`, `receivedDigest`), `ImapStream`, `TlsImapStream`, `MimeParser`, `ParsedMail`, `MailAttachment`, `FetchedMail`, `MailInError`, `MailInException`; operacja `connector_call` `fetch`; licznik `mail_in.fetch` |
| `MailInError` | `network_error`, `login_failed`, `mailbox_unavailable`, `protocol_error`, `write_refused`, `invalid_credential`, `import_failed`; nowe kody dopuszcza CHECK `connector_call_error_code` |
| Koszty (`src/Cost`) | `MailInbox` (`run`, wynik `status`: `ok`, `failed`, `rate_limited`, `disabled`, `not_configured`; `mediaType`) |
| Tabela | `mail_in_message` (`uid_validity`, `uid`, `message_id_sha256`, `from_address`, `subject`, `sent_at`, `size_bytes`, `status`, `documents`, `skipped`, `fetched_at`, `handled_at`, `handled_by`); indeksy `mail_in_message_open`, `mail_in_message_message_id` |
| `mail_in_message.status` | `new` (są dokumenty), `no_document`, `too_large`, `duplicate`, `unreadable` |
| Pliki | `stored_file` `cost_document` z `owner_table` = `mail_in_message`; `original_name` z nagłówka nadawcy po oczyszczeniu |
| Audyt | `mail_in.received`, `mail_in.handled` |
| Konfiguracja | `MAIL_IN_HOST` (`app.mail_in.default_host`, pusty = skrzynka wyłączona), `ConnectorConfiguration::mailInHost`, `mail_in.timeout_s`, `mail_in.max_message_bytes`, `mail_in.max_messages_per_run`; sekrety `MAIL_IN_TEST_DSN`, `MAIL_IN_PROD_DSN` (`imaps://użytkownik:hasło@host/folder`) |
| Messenger | `MailInFetchMessage` (`mail_in.fetch`, transport `reads`), handler `MailInFetchHandler` |
| Panel | `MailInController`: trasy `panel_mail_in` (`GET /mail-in`), `panel_mail_in_file` (`GET /mail-in/files/{id}`), `panel_mail_in_handled` (`POST /mail-in/{id}/handled`, token CSRF `mail_in`); szablon `templates/panel/mail_in.html.twig`; identyfikatory `#mail-in-status`, `#mail-in-open`, `#mail-in-error`, `#mail-in-notice`, `#mail-in-messages`, `#mail-in-<id>` |
| Komenda | `app:mail-in:fetch` (`MailInFetchCommand`) |
| Testy | `MailInboxTest`, `MailInPanelTest`, `MimeParserTest`, `ImapClientTest`, `ScriptedImapStream`; fixtures `tests/Fixtures/MailIn/invoice-pdf.eml`, `nested-forward.eml`, `no-attachment.eml`, `session-first.txt`, `session-second.txt`, `session-new-validity.txt`, `session-login-failed.txt` |

### Powiadomienia systemowe z poczty (D-109): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Kalendarz (`src/Calendar`) | `MailNotices` (`channel`, `record`; stałe `CHANNELS`, `RULE_KEY`, `LABELS`) |
| Kanał powiadomienia | `e_us` (e-Urząd Skarbowy i powiadomienia KAS), `ezus` (PUE/eZUS), `ade` (e-Doręczenia, ADE firmy albo prywatny) |
| Kolumna | `mail_in_message.notice_channel` (`NULL` albo kanał powiadomienia, CHECK) |
| Obowiązek | `manual_channel_check` z regułą `manual_channel_check.mail_notice`; `dedupe_key` `manual_channel_check:{dzień pobrania}:{kanał}:{mail_in_message.id}` |
| Audyt | `mail_in.notice_recognized` (`channel`) |
| Konfiguracja | `mail_in.notices`: lista reguł `channel`, `sender` (pełny adres) albo `domain` (domena bez poddomen), opcjonalnie `subject` (wyrażenie regularne PCRE) |
| Testy | `MailNoticesTest` (`configuredRules`), `MailInboxTest::testSystemNoticesCreateOneChannelCheckEach`, `MailInPanelTest::testSystemNoticeShowsSourceOnBothPages`; fixtures `tests/Fixtures/MailIn/session-notices.txt`, `session-notices-new-validity.txt`, `notice-e-us.eml`, `notice-jpk.eml`, `notice-jpk-other-subject.eml`, `notice-spoofed-name.eml`, `notice-typosquat.eml` |

### Cash-flow na 90 dni (D-88): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Ewidencja (`src/Ledger`) | `CashFlow` (`summary`; stałe `HORIZON_DAYS`, `MAX_ROWS`, `PAYABLE`; klucze `bank`, `bank_until` od D-110) |
| Panel | `CashFlowController`: trasa `panel_cashflow` (`GET /cashflow`) |
| Szablon | `templates/panel/cashflow.html.twig`; identyfikatory `#cashflow-balance`, `#cashflow-receivables-pln`, `#cashflow-obligations-pln`, `#cashflow-payables-pln`, `#cashflow-balance-pln`, `#cashflow-bank`, `#cashflow-bank-<account>-<currency>` (D-110), `#cashflow-obligations`, `#cashflow-obligation-<id>`, `#cashflow-without-amount`, `#cashflow-receivables`, `#cashflow-receivable-<id>`, `#cashflow-payables`, `#cashflow-payable-<id>` |
| Testy | `CashFlowPanelTest`, `CashFlowSummaryTest` |

### Raport rok do roku (D-87): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Usługa | `YearOverYearReport` (`src/Ledger`): miary `revenue_pln`, `vat_due`, `vat_input`, `vat_payable`, `costs_net_pln`, `costs_documents`, `costs_without_pln`, `fx_mid`, `fx_tables` |
| Panel | `ReportController`: trasa `panel_reports` (`GET /reports`, parametry `year`, `currency`, `format=csv`); plik `raport-rok-do-roku-<rok>-<waluta>.csv`; kolumny `<miara>_<rok>`, `<miara>_diff`; wiersze `01`–`12`, `rok`, `od-01-do-<miesiąc>`; link `#reports-csv` |
| Szablon | `templates/panel/reports.html.twig`; identyfikatory `#reports-filter`, `#report-revenue`, `#report-vat`, `#report-costs`, `#report-fx`, wiersze `#revenue-<wiersz>`, `#vat-<wiersz>`, `#costs-<wiersz>`, `#fx-<wiersz>` |
| Testy | `YearOverYearReportPanelTest` |

### Kreator zamknięcia roku (D-86): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Ewidencja (`src/Ledger`) | `YearClose` (`checklist`, `archive`, `annualRules`; stała `FIRST_YEAR`) |
| Panel | `YearCloseController`: trasy `panel_year_close` (`GET /year-close`, parametr `year`), `panel_year_close_archive` (`GET /year-close/{year}.zip`) |
| Archiwum | `kokpit-rok-<rok>.zip`: `obowiazki-roczne.csv`, `miesiace.csv`, `zlozenia.csv`, `przychody-miesiecznie.csv`, `ewidencja-<rok>.csv`, katalog `zlozenia/<typ>-<okres>-<cel>-<seq>/` |
| Szablon | `templates/panel/year_close.html.twig`; identyfikatory `#year-close-archive`, `#year-close-years`, `#year-close-summary`, `#year-close-obligations`, `#year-obligation-<id>`, `#year-close-months`, `#year-month-<RRRR-MM>`, `#year-close-filings`, `#year-filing-<id>`, `#year-close-revenue`, `#year-close-settings` |
| Testy | `YearClosePanelTest` |

### Panel zdrowia konektorów (D-85): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Zdrowie (`src/Health`) | `ConnectorHealth` (`summary`, `chart`; stałe `PERIODS`, `DEFAULT_PERIOD`, `MAX_ERRORS`) |
| Panel | `ConnectorHealthController`: trasa `panel_connectors` (`GET /connectors`, parametr `days`) |
| Szablon | `templates/panel/connectors.html.twig`; identyfikatory `#connectors-period`, `#connector-summary`, `#connector-<konektor>`, `#connector-daily`, `#rate-blocks`, `#sync-cursors`, `#connector-errors` |
| Indeks | `connector_call_environment_started` (migracja `Version20261026000100`) |
| Testy | `ConnectorHealthPanelTest` |

### Płatności klientów w panelu (D-84): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Panel | `PaymentController`: trasa `panel_payments` (`GET /payments`, parametry `unmatched=1`, `format=csv`); pliki `platnosci.csv`, `platnosci-nieprzypisane.csv`; linki `#payments-csv`, `#payments-unmatched` (strona FreshBooks) |
| Szablon | `templates/panel/payments.html.twig`; identyfikatory `#payments`, `#payment-<id>`, `#payments-filter` |
| Testy | `PaymentPanelTest` |

### Faktury kosztowe w panelu i dane do przelewu (D-83): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Panel | `CostController`: trasy `panel_costs` (`GET /costs`, parametry `invoice`, `check`, `format=csv`), `panel_cost_check` (`POST /costs/{id}/check`, pola `account`, `order_date`), `panel_cost_notice` (`POST /costs/{id}/notice`, pole `order_date`); token CSRF `costs`; plik `koszty.csv`; link `#costs-csv` |
| Szablon | `templates/panel/costs.html.twig`; identyfikatory `#cost-invoices`, `#cost-<id>`, `#cost-notice`, `#cost-error`, `#pre-payment-result`, `#transfer-details`, `#transfer-recipient`, `#transfer-account`, `#transfer-amount`, `#transfer-title`, `#transfer-all`, `#pre-payment-warning`, `#pre-payment-exits`, `#pre-payment-notice` |
| Rejestry (`src/Counterparty`) | `PrePaymentCheck::outcome`, `PrePaymentCheck::title`; pole wyniku `transfer` (`recipient`, `account`, `amount`, `currency`, `title`) i `order_date` |
| Stimulus | kontroler `clipboard` (`assets/controllers/clipboard_controller.js`): akcja `clipboard#copy`, parametr `source` (id pola), cel `status` |
| Testy | `CostPanelTest` |

### Przegląd tygodnia mailem (D-82): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Kalendarz (`src/Calendar`) | `WeeklyDigest` (`counts`, `plan`, `body`, `parse`; stałe `CATEGORY`, `LINK` = `/`, `KEYS`, `DUE_SOON_DAYS`) |
| Klucze przeglądu | `obligations_open`, `obligations_overdue`, `obligations_due_soon`, `approvals_pending`, `approvals_unknown`, `credentials_expiring`, `credentials_expired` |
| Konektor `mail` | `MailNotifier::digest` |
| Zadanie | `notifications.weekly_digest` (`NotificationDispatchMessage`, transport `notifications`) |
| Mail | `notification.category` = `weekly_digest`, `dedupe_key` `weekly_digest:<environment>:<RRRR-Wtt>:mail` |
| Testy | `WeeklyDigestTest` |

### Widżet „Najbliższe terminy” (D-81): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Panel | sekcja `#upcoming-deadlines` na pulpicie (`panel_dashboard`), stała `PanelController::UPCOMING_LIMIT`, zmienne widoku `upcoming`, `open_obligations` |
| Testy | `UpcomingDeadlinesPanelTest` |

### Paczka diagnostyczna (D-80): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Diagnostyka (`src/Health`) | `Diagnostics` (`collect`, `render`); klucze paczki `generated_at`, `period_days`, `deployment`, `versions`, `migrations`, `health`, `queues`, `credentials`, `rate_blocks`, `connector_calls`, `connector_errors`, `logs` |
| Komenda | `kokpit:diagnostics` (`DiagnosticsCommand`), opcje `--days`, `--logs` |
| Pliki | `var/diagnostics/kokpit-diagnostics-<RRRRMMDD>-<GGMMSS>.json` (0600, poza repo) |
| Testy | `DiagnosticsTest` |

### Kotwica łańcucha audytu (D-79): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Audyt (`src/Audit`) | `AuditLog::head` (kotwica `<sequence>:<hash>`), `AuditLog::matchesAnchor` |
| Komenda | opcja `--anchor` w `app:audit:verify` (`AuditVerifyCommand`) |
| Backup | tag snapshotu restic `audit-head:<sequence>:<hash>` obok `backup.run`; zmienna `audit_head` w `run-backup.sh` |
| Testy | `AuditLogTest::testHeadAnchorsMatchOnlyTheirOwnChainEntry`, `BackupScriptTest` |

### SBOM i eksport CSV list panelu (D-78): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| SBOM | `App\Security\Sbom` (`build`), skrypt `tools/sbom.php [<wersja>]` (CycloneDX 1.6 JSON na stdout); w CI plik `sbom.cdx.json`, artefakt `sbom` joba `lint` |
| Panel | `App\Panel\CsvExport` (`build`, `response`, `cell`); parametr `?format=csv` na `/ledger` (z `year`), `/calendar`, `/invoices`, `/filings`; pliki `ewidencja-<rok>.csv`, `obowiazki.csv`, `faktury-sprzedazy.csv`, `zlozenia.csv`; linki `#ledger-csv`, `#calendar-csv`, `#invoices-csv`, `#filings-csv` |
| Testy | `SbomTest`, `CsvExportTest`, `PanelCsvExportTest`, `CiWorkflowTest` |

### Kanał ICS obowiązków i skan zależności (D-77): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Kalendarz (`src/Calendar`) | `CalendarFeed` (`issue`, `revoke`, `userForToken`, `render`, `escape`, `fold`) |
| Panel | `CalendarFeedController`, trasa `calendar_feed` (`GET /calendar-feed/{token}.ics`, token 43 znaki base64url, dostęp publiczny w `access_control`, zły token 404) |
| Kolumna | `app_user.calendar_feed_token_hash` (migracja `Version20261024000100`) |
| Komenda | `app:calendar:feed-link` (`CalendarFeedLinkCommand`) |
| Akcje audytu | `calendar.feed_link_issued`, `calendar.feed_revoked` |
| CI | krok `composer audit --locked` w jobie `lint` |
| Testy | `CalendarFeedTest` (Unit i Integration), `CiWorkflowTest` |

### Prognoza progów i rezerwa (D-76): nazwy

| Rodzaj | Nazwy |
| --- | --- |
| Podatki (`src/Tax`) | `ThresholdForecast` (`forecast`, `runRate`, `cumulative`, `crossMonth`); `TaxCalculator::eurThresholdRate` (wynik `rate`, `day`, `estimated`); pola metryki `eligibility` `rate_day`, `rate_estimated`, `quarterly_limit_eur` |
| Wynik `forecast` | `basis_months`, `basis_end`, `ytd_end`, `revenue_ytd`, `revenue_basis`, `run_rate_monthly`, `projected_revenue`; `eur` (`rate`, `rate_table`, `rate_date`, `rate_day`, `rate_estimated`, `limits[]` z `key`: `eligibility`, `eligibility_watch`, `quarterly`; `limit_eur`, `limit_pln`, `crossed`, `projected_cross`, `cross_month`); `health` (`revenue_after_social_ytd`, `projected_revenue_after_social`, `bracket_now`, `bracket_projected`, `next_limit_pln`, `next_cross_month`, `months_paid`, `health_paid`, `surcharge`); `reserve` (`period_start`, `ryczalt_open_period`, `health_surcharge`, `total`) |
| Ustawienie | pole `quarterly_limit_eur` w `ryczalt.eligibility`; wiersz domyślny z migracji `Version20261023000100` |
| Panel | `/taxes` sekcja `threshold-forecast`, akapit `tax-reserve`; `/settings` pole `quarterly_limit_eur` |
| Testy | `ThresholdForecastTest` (Unit i Integration), `TaxCalculatorTest::testRyczaltEligibilityMetricEstimatesRateBeforeFirstWorkingDayOfOctober` |

## Nazwy wycofane

| Nazwa | Zamiast niej | Decyzja albo dom |
| --- | --- | --- |
| `credential_state.kind` = `ksef_jwt` | `ksef_jwt_read`, `ksef_jwt_write` | – |
| `subject_type` dla strumieni KSeF | `sync_cursor.stream` | – |
| id joba `unit-property` | `unit_property` (`name: unit+property`) | D-34 |
| klucz kryterium `<phase>-NN` (`f0a-NN`, `f1-NN`) | slug kryterium w pliku fazy, `cc-NN` w kryteriach przekrojowych | D-33 |
| plik wyjścia `out/<id>.txt` | `cmd/<klucz>.txt` w paczce ([testing.md](testing.md)) | D-33 |
| wynik `manual_pending` | `pending` | D-33 |
| opcja `--attach=<id>=<plik>` | plik w `var/evidence/<phase>/inbox/` z nazwą od klucza | D-33 |
| `@group property`, `@group integration`, `@group security`, `@group xsd` | testsuite PHPUnit katalogu (`property`, `integration`, `security`, `xsd`) | [testing.md](testing.md) |
| zaślepki `tests/Fixtures/Jpk/xsd-stub`, `JpkSendTestTools::catalog()` i `official()` | oficjalne `initupload.xsd` i `SIG-2008_v2-0.xsd` w `resources/xsd`, `XsdCatalog::default()` | D-63 |
| plik schematu `InitUpload.xsd` (`JpkInitUpload::SCHEMA`) | `initupload.xsd`, nazwa pliku u źródła MF | D-63 |
| zmienna repozytorium `KOKPIT_PHASE` | faza z prefiksu gałęzi albo z wejścia `phase` | [testing.md](testing.md) |
| konektor `fake`, katalog `src/Connector/Fake`, host `fake.invalid` | `dummy`, `src/Connector/Dummy`, `dummy.invalid` | D-32 |
| `rate_counter.bucket` = `claude.api` | `claude.messages` | D-34 |
| transport Messenger `client_delivery` | „Doręcz klientowi” synchronicznie w żądaniu panelu | D-31 |
| transport Messenger `chat`, usługi `worker_chat` i `mercure` | czat w synchronicznym żądaniu z ramką Turbo | D-29 |
| tabela `opening_balance` | klucze `setting` `opening.*` i pliki dowodów | D-29 |
| `kokpit:demo <phase>`, opcja `--transcript` | brak; dowód z CI i z `docs/evidence/<phase>.md` | D-28 |
| opcja `kokpit:evidence --summary` | ręcznie pisany `docs/evidence/<phase>.md` | D-28 |
| wykonawca `host` | `manual` z plikiem w `inbox/` | D-28 |

## Nazwy techniczne F0a: rdzeń

| Element | Nazwy |
| --- | --- |
| Usługi | `Kernel` (szkielet Symfony), `Vault`, `SecretStore`, `Redactor`, `BusinessDate`, `AuditLog`, `FileStore`, `ConnectorConfiguration` |
| Komendy pomocnicze | `CredentialRegisterCommand`, `CredentialSmokeCommand`, `AuditVerifyCommand`, `FilesVerifyCommand` |
| Kolumny F0a | `id`, `sequence`, `environment`, `created_at`, `updated_at`; pełna lista poniżej |
| Akcje audytu F0a | `credential.registered`, `credential.rotated`, `file.deleted`, `file.hash_mismatch`, `ops.deployed`, `connector.target_changed`, `connector.enabled_changed`, `auth.login`, `auth.login_failed`, `auth.passkey_enrolled`, `auth.passkey_revoked`, `auth.recovery_link_issued` |
| Parametry infrastruktury | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_ROLE`, `VAULT_DIRECTORY`, `APP_RUNTIME_ENV`, `APP_ENV`, `APP_DEBUG`, `APP_IMAGE`, `COMPOSE_PROFILES`, `PANEL_HOST`, `HOST_SECRETS_DIR`, `DB_OWNER_PASSWORD_FILE`, `APP_SECRET`, `SECRETBOX_KEY_current`, `DB_KOKPIT_APP_PASSWORD`, `DUMMY_TEST_TOKEN` |
| Wolumeny Compose | `database`, `vendor`, `runtime`, `vault`, `app_credentials`, `owner_credentials`, `backup_state`, `tailscale`, `certificates`, `ntfy_data` |
| Role | `kokpit_backup` |

W F0a klucze główne siedmiu tabel aplikacyjnych są UUIDv7; tabele techniczne Symfony/Doctrine zachowują klucze wymagane przez biblioteki. `sequence` (identity) zachowuje kolejność logów, a licznik ma dodatkowy klucz UNIQUE `(environment, connector, bucket, window_start)`.

### Uzupełnienia implementacji F0a

| Rodzaj | Nazwy i znaczenie |
| --- | --- |
| Konfiguracja i wyniki | `ConnectorConfiguration`, `ConnectorHosts`, `ConnectorError`, `RateCounterResult`, `TargetBanner` |
| Passkey | `AppUser`, `AppUserProvider`, `WebauthnRepository`, `EnrollmentUserEntityGuesser`, `PasskeyAccessSubscriber`, `SessionLifetimeSubscriber`, `AuthenticationAuditSubscriber` |
| Pola panelu | `app_user.enrollment_token_hash`, `app_user.enrollment_expires_at`, `app_user.enrollment_used_at`: jednorazowy link SSH; `webauthn_credential.credential_source`: rekord biblioteki WebAuthn; `app_user.enrollment_kind` i `webauthn_credential.kind` (`passkey`, `security_key`): rodzaj uwierzytelniacza (D-90) |
| Harmonogram i kolejka | `DefaultScheduleProvider`, `HeartbeatStore`, `ScheduledTaskHandler`, `FailureNotificationMessage`, `FailureNotificationHandler`, `RedactingTransportSerializer`, `RateLimitDeferralMiddleware`, `cache.scheduler`, `ops.last_heartbeat`, `scheduler_default` |
| Komendy | `EvidenceCommand`, `FixturesRecordCommand`, `PasskeyEnrolLinkCommand`, `PasskeyResetCommand`, `PushTestCommand`, `RecordDeployCommand` |
| HTTP i SMTP | `GuardedMailTransport`, `dummy.http_client`, `dummy.host_allowlist`, `dummy.mock_http_client`, `http_client.raw` |
| Parametry | `PUSH_ENDPOINT`, `PUSH_HOST`, `MAIL_HOST`, `MAIL_FROM`, `MAIL_TO`, `DEADMAN_HOST`, `PANEL_ORIGIN`, `PANEL_CERTIFICATE_PATH`, `KOKPIT_COMMIT`, `KOKPIT_IMAGE_DIGEST`, `GITHUB_RUN_ID`, `GITHUB_SERVER_URL`, `GITHUB_REPOSITORY` |
| Limity i zdrowie | `connector.daily_limits`, `health.queue_max_age`, `health.heartbeat_max_age`, `health.backup_max_age`, `health.disk_min_free_percent`, `health.tls_min_valid_days`; wyniki `database`, `reads_queue`, `official_writes_queue`, `notifications_queue`, `scheduler_heartbeat`, `failed_messages`, `disk_space`, `credentials`, `connectors`, `backup`, `tls_certificate` |
| Sekrety F0a | `PUSH_TEST_TOKEN`, `PUSH_PROD_TOKEN`, `MAIL_TEST_DSN`, `MAIL_PROD_DSN`, `DEADMAN_PROD_HEARTBEAT_URL`, `DEADMAN_PROD_NOTIFICATIONS_URL` (od F0b; nazwy produkcyjne wyłącznie dla konfiguracji ręcznej); `TS_AUTHKEY_FILE`, `RESTIC_PASSWORD_FILE`, `PGPASSFILE` |
| Narzędzia | `fixtures:scan` (skrypt Composer), `ArchitectureRules`, `OfficialWrite`, `FictionalIdentifiers` |
| Transporty i schemat | `messenger_messages`, `doctrine_migration_versions`, `cache_items` (tabele techniczne Symfony/Doctrine), `prevent_audit_mutation` (funkcja ochrony audytu) |
| Zmienne backupu | `RESTIC_REPOSITORY_FILE`, `AWS_SHARED_CREDENTIALS_FILE`, `BACKUP_DEADMAN_URL_FILE`, `BACKUP_DATABASE`, `BACKUP_HOST` |

`ConnectorError`: `connectors_disabled`, `host_not_allowed`, `invalid_url`, `network_error`, `rate_limited`, `unscoped_connector_request`, `http_error`, `waf_challenge` (strona HTML z wyzwaniem Imperva zamiast odpowiedzi API, D-74). Wiadomość `FailureNotificationMessage.id` wskazuje wiersz transportu `failed`; handler używa potwierdzenia tego wiersza jako klucza idempotencji, bez tabeli `notification`.

Nagłówek `X-Kokpit-Notified` w `messenger_messages.headers` oznacza dostarczony alert F0a.

Usługi monitoringu: `HealthResources`, `NativeHealthResources`; `health.queue_max_age` w minutach.

- `app.webauthn.serializer` — serializer rekordów WebAuthn z fabryki biblioteki, z kodowaniem binarnych pól Base64URL.

- `app.dummy.default_endpoint` — fikcyjny adres bazowy konektora Dummy.
- `dummy.mock_response` — wyłącznie fikcyjna odpowiedź MockHttpClient w dev/test.
- `COMPOSE_PROFILES` — profil usług wybrany przez `KOKPIT_DEPLOYMENT`; `backup` i `vpn` tylko w `prod`.
- `HOST_SECRETS_DIR`, `PANEL_ORIGIN`, `MAIL_HOST`, `MAIL_FROM`, `MAIL_TO`, `PUSH_ENDPOINT`, `DEADMAN_HOST`, `PANEL_CERTIFICATE_PATH` — konfiguracja połączeń i panelu; wartości tajne pozostają w sejfie lub plikach hosta.

- `NTFY_CONFIG_FILE` — plik konfiguracji ntfy poza repo dla prod; domyślnie konfiguracja lokalna bez sekretów.

- `SOURCE_BRANCH`, `DISPATCH_PHASE`, `EVIDENCE_RUNNER` — lokalne wejścia skryptu CI do wyboru fazy i wykonawcy.
- `IsoDate` (`App\Calendar`) — rok i miesiąc z daty prawnej ISO `RRRR-MM-DD` (D-105).
- `MUTATION_THREADS`, `TEST_TOKEN`, `kokpit_test_<n>`, `var/test-files-<n>`, `scripts/mutation-databases.sh` — wątki joba `mutation`: liczba wątków Infection, numer wątku, kopia bazy testowej i katalog plików testów wątku (D-104); `TestFiles` — katalog plików testów.
- `MF_TEST_CERT_PEM` — zmienna repozytorium GitHub (nie sekret) z publicznym certyfikatem testowym MF w PEM; job `live` zapisuje ją skryptem `scripts/ci-mf-test-cert.sh` do `var/jpk/mf-test.crt` (D-97).
- `RESTIC_REPOSITORY`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `TS_AUTHKEY` — wyłącznie środowisko narzędzi w kontenerach backup/VPN, odczyt z plików hosta.
- `files.storage` — wspólna blokada zapisu i retencji FileStore.
- `manifest.sha256` — suma kontrolna ukończonego manifestu paczki dowodów.

- `live-targets` — kryterium live F0a: wyłącznie kontrola celów bez sieci.

Paczka dowodów dodaje do katalogu minutowego losowy sufiks, aby nie nadpisać poprzedniego przebiegu w tej samej minucie.

- `COMPOSER_CACHE_DIR` — cache Composera na wolumenie runtime, dostępny dla użytkownika aplikacji.

- `connector_context`, `rate_bucket` — kontekst i grupa limitu w opcjach `extra` klienta HTTP/Psr18; nieskopowany klient odmawia wywołania.
- `connector_call_error_code` — CHECK na tekstowych wartościach `ConnectorError`.

### Izolacja wdrożeń i kontrole F0a

- `COMPOSE_FILE` — domyślne pliki wdrożenia dev: `compose.yaml:compose.dev.yaml`; PROD i restore używają jawnie tylko `-f compose.yaml`.
- `PROXY_IMAGE` — obraz nginx z katalogiem `public/` i skompilowanymi zasobami z tej samej wersji co `APP_IMAGE`; oba obrazy wycofywane razem.
- `FailureNotificationHandler::sweep` — heartbeat kolejkuje identyfikatory niepowiadomionych wierszy `failed`, gdy konektory są włączone; znacznik `X-Kokpit-Notified` powstaje tylko po udanym push.
- `DeploymentTest`, `check-fpm.sh`, `check-compose.php`, `check-assets.php` — kontrole konfiguracji FPM, izolacji montowań i zasobów logowania bez debugowania.
- `base`, `development`, `production`, `proxy` — etapy Dockerfile: wspólne PHP 8.4, narzędzia dev, aplikacja z zależnościami bez dev i zasobami, nginx z zasobami tej samej wersji.
- `help`, `deploy`, `rollback` — cele Makefile: pomoc (domyślnie), wdrożenie kroków 1–7 i wycofanie do obrazów commita `KOKPIT_COMMIT`; dwa ostatnie tylko dla `KOKPIT_DEPLOYMENT=prod`.
- `verify-images`, `update-check` — cele Makefile kanału aktualizacji (D-93): weryfikacja podpisu lokalnych obrazów commita i sprawdzenie nowego commita `main` z podpisanymi obrazami.
- `images` — job CI (D-93): budowa obrazów przy PR, push do GHCR i podpis `cosign` bez klucza tylko z push do `main`.
- `app`, `proxy` — nazwy obrazów w rejestrze: `<KOKPIT_REGISTRY>/app:<sha>` (etap `production`) i `<KOKPIT_REGISTRY>/proxy:<sha>`.
- `KOKPIT_REGISTRY`, `KOKPIT_SIGNER` — zmienne Makefile: repozytorium obrazów (`ghcr.io/good-yellow-bee/kokpit-jdg-public`) i tożsamość certyfikatu podpisu (`…/.github/workflows/ci.yml@refs/heads/main`).
- `MakefileTest` — kolejność wdrożenia, odmowa poza PROD, zatrzymanie po błędzie, kontrola HTTP 200, wycofanie oraz brak przypisywania celów konektorów; komendy zewnętrzne zastąpione fikcyjnymi programami.

### Kolumny tabel F0a

Nazwy z istniejących tabel aplikacyjnych i technicznych; semantyka tabel aplikacyjnych w [data-model.md](data-model.md).

| Tabela | Kolumny |
| --- | --- |
| `app_user` | `id`, `username`, `display_name`, `disabled_at`, `enrollment_token_hash`, `enrollment_expires_at`, `enrollment_used_at`, `enrollment_kind` (D-90), `calendar_feed_token_hash` (D-77), `created_at`, `updated_at` |
| `audit_log` | `id`, `sequence`, `occurred_at`, `actor_user_id`, `action`, `target_type`, `target_id`, `environment`, `connector`, `approval_id`, `payload_sha256`, `details`, `prev_hash`, `hash` |
| `cache_items` | `item_id`, `item_data`, `item_lifetime`, `item_time` |
| `connector_call` | `id`, `sequence`, `environment`, `connector`, `operation`, `host`, `http_method`, `path`, `started_at`, `duration_ms`, `http_status`, `error_code`, `retry_after_s`, `request_sha256`, `request_bytes`, `response_sha256`, `response_bytes`, `approval_id`, `correlation_id` |
| `credential_state` | `id`, `environment`, `kind`, `secret_name`, `ciphertext`, `nonce`, `key_id`, `valid_from`, `valid_until`, `refresh_valid_until`, `status`, `rotated_at`, `last_smoke_ok_at`, `revoked_at`, `created_at`, `updated_at` |
| `doctrine_migration_versions` | `version`, `executed_at`, `execution_time` |
| `messenger_messages` | `id`, `body`, `headers`, `queue_name`, `created_at`, `available_at`, `delivered_at` |
| `rate_counter` | `id`, `environment`, `connector`, `bucket`, `window_start`, `count`, `blocked_until` |
| `stored_file` | `id`, `environment`, `kind`, `sha256`, `path`, `size_bytes`, `media_type`, `original_name`, `owner_table`, `owner_id`, `last_verified_at`, `verify_failed_at`, `retention_until`, `legal_hold`, `deleted_at`, `created_at`, `updated_at` |
| `webauthn_credential` | `id`, `app_user_id`, `credential_id`, `public_key`, `sign_count`, `transports`, `aaguid`, `label`, `kind` (D-90), `last_used_at`, `revoked_at`, `credential_source`, `created_at`, `updated_at` |
