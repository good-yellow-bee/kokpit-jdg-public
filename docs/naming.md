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
| administrator skrzynki | osoba wyznaczona przez przedsiębiorcę do obsługi skrzynki ADE; zastępca przy odbiorze pism (Q-18) | inbox administrator |
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
| `notification.category` | wartości `obligation.type` przy przypomnieniu oraz `approval_pending`, `connector_failure`, `budget_alert`, `freshbooks_bypass`; `edelivery_new` odłożone (D-29) |
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
| `rate_counter.bucket` | wzorzec `<connector>.<grupa>`: grupy `ksef.*` z [modules/ksef.md](modules/ksef.md), `latarnia.status`, `white_list.search`, `white_list.check`, `vies.check`, `nbp.rates`, `freshbooks.api`, `jpk.init_upload`, `edeklaracje.send`, `claude.messages`, `push.send`, `mail.send`, `deadman.ping`, `dummy.call`; `edoreczenia.api` i `basal.classify` odłożone (D-29) |
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
| `opening.vat_carry_forward_pln`, `opening.revenue_ytd`, `opening.zus_paid_ytd`, `opening.last_invoice_numbers` | `setting` z `valid_from` = data przejścia, zamiast tabeli `opening_balance`; dowody to `stored_file` rodzaju `evidence` | [compliance-calendar.md](compliance-calendar.md) |

### Konektory

| `connector` (log, liczniki) | Katalog `src/Connector/` |
| --- | --- |
| `ksef`, `latarnia` | `Ksef`, `Latarnia` |
| `edoreczenia` | `EDoreczenia`; odłożone (D-29) |
| `jpk`, `edeklaracje` | `Jpk`, `EDeklaracje` |
| `vies`, `white_list`, `nbp` | `Vies`, `WhiteList`, `Nbp` |
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
| `app:passkey:enrol-link`, `app:passkey:reset` | pierwszy passkey i odzyskanie dostępu przez SSH; w F0a wersja minimalna (D-28) | [security.md](security.md) |
| `app:audit:verify`, `app:files:verify` | łańcuch `audit_log`; hashe plików | [security.md](security.md), [operations.md](operations.md) |
| `app:fixtures:record <connector> <scenariusz>` | nagranie odpowiedzi do fixtures | [../.claude/rules/integrations.md](../.claude/rules/integrations.md) |
| `app:push:test [--channel=mail]` | testowy push ntfy albo mail | [phases/f0a-szkielet.md](phases/f0a-szkielet.md) |
| `app:ledger:import <źródło> <rok> [--file=<plik>]` | idempotentny import ewidencji ze źródła `import`, `freshbooks` albo `ksef_subject1`; `import` wymaga `--file` z eksportem narzędzia (XML JPK_EWP(4) albo CSV); `freshbooks` i `ksef_subject1` od F2.2 | [phases/f2-ewidencja.md](phases/f2-ewidencja.md) |
| `app:jpk:dry-run <typ> <rok> --until=<data>` | JPK bez zatwierdzenia i bez konektora `jpk` | [phases/f2-ewidencja.md](phases/f2-ewidencja.md) |
| `app:ksef:parallel-compare <rok-miesiąc>` | okres równoległy F3: mapuje fakturę FreshBooks połączoną z fakturą Subject1 na FA(3) i porównuje; bez zatwierdzenia i wysyłki | [modules/ksef.md](modules/ksef.md) |
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
| `.github/workflows/ci.yml` | id jobów: `lint`, `unit_property` (`name: unit+property`), `contract`, `xsd`, `integration`, `security`, `mutation`, `evidence`; `live` tylko ręcznie (`workflow_dispatch`), nigdy PROD |
| `mutation` | Infection doradczo, bez blokowania merge, do powstania `src/Tax` w F2 (D-28) |
| faza joba `evidence` | prefiks gałęzi `fX-*` albo wejście `phase` przy `workflow_dispatch`; push do `main` pomija `evidence` |
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
| Komendy | `app:ledger:import ksef_subject1 <rok>` (odtworzenie) |

### Implementacja F2.4: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Generatory i dry run | `JpkEwpBuilder`, `JpkStBuilder`, `JpkDocument`, `JpkBuildRefused` (`src/Xml`); `JpkDryRun` (`src/Ledger`); `JpkDryRunCommand` z opcjami `--until` i `--output` |
| Tabele | `filing`, `fixed_asset` (migracja F2.4) |
| Akcje audytu | `jpk.dry_run` |
| Sekret `auth_data` | JSON z kluczami `birth_date`, `revenue_n2` (D-37) |
| Dowód działania | `scripts/jpk-acceptance.php <ewp-build\|ewp-dry-run>` |
| Fixtures | `tests/Fixtures/Jpk/jpk-2026-dry-run.json`, `golden/jpk-ewp-dry-run.txt` |

### Implementacja F0b: nazwy techniczne

| Rodzaj | Nazwy |
| --- | --- |
| Kalendarz | `PolishCalendar` (`src/Calendar`): `holidays`, `isHoliday`, `isWorkingDay`, `previousWorkingDay`, `workingDayOnOrAfter`, `shift`, `easter`; polityki `op12`, `zus`, `none` |
| Obowiązki | `ObligationEngine` (`recompute`, `candidates`, `zusAmount`), `ObligationRules` (`RULES`, `MANUAL`, `CHANNELS`, `EXPIRING_KINDS`, `source`, `policy`, `missingAmount`); wiadomość `ObligationRecomputeMessage` (transport `reads`) |
| `obligation.rule_key` | `zus.monthly`, `ryczalt_advance.monthly`, `ryczalt_advance.quarterly`, `vat_jpk_v7m.monthly`, `vat_ue.monthly`, `invoice_issue.monthly`, `ledger_entry.monthly`, `wht_payment.monthly`, `ift_2r.yearly`, `cit_10z.yearly`, `jpk_ewp.yearly`, `jpk_st.yearly`, `pit_28.yearly`, `health_reconciliation.yearly`, `tax_form_choice.yearly`, `credential_expiry.valid_until`, `credential_expiry.auth_data`, `manual_channel_check.weekly`, `manual_channel_check.balance_check`; wpis ręczny `edelivery_reply_due.manual` |
| Tabele | `obligation`, `notification` (migracja F0b) |
| Przypomnienia i akcje | `NotificationDispatcher` (`run`, `plan`, `send`, `offsets`, `channels`, `body`; link `/calendar`; klucz zaległości `{obligation_id}:overdue:{data}:push`), `ObligationActions` (`complete`, `recordPayment`, `markNotApplicable`, `addLetterDeadline`), `CredentialExpiryChecker` (`src/Security`); wiadomości `NotificationDispatchMessage` (transport `notifications`), `CredentialExpiryMessage` (`reads`) |
| Panel | `CalendarController`: `/calendar`, `/calendar/letter`, `/calendar/{id}/complete`, `/calendar/{id}/payment`, `/calendar/{id}/not-applicable`; `/settings` z kluczami F0b |
| Passkey i step-up | interfejs `StepUp` (`require`), `StepUpVerifier` (`options`, `verify`, `require`; sesja `step_up.*`, 5 minut), `PasskeyManager` (`active`, `warning`, `enrolmentToken`, `revoke`), `PasskeyPanelController`: `/passkeys`, `/passkeys/add`, `/passkeys/{id}/revoke`, `/passkey/step-up/options`, `/passkey/step-up/verify` (D-40) |
| Bramka zatwierdzeń (`src/Approval`) | `ApprovalGate` (`request`, `renew`, `approve`, `cancel`, `markCopied`, `recordManualFiling`, `markRestoredUnknown`, `retry`), `ApprovalTransitions` (`TABLE`, `LIVE`, `FINAL`, `apply`, `action`), `ApprovalSubject`, `ApprovalSubjects`, `ApprovalPayload`, `OfficialWriter`, `StatusReader`, `ApprovalConnectors`, `DispatchRequest`, `DispatchGuard`, `SubmissionResult`, `ApprovalExpiry`, `ApprovalNotifications` (link `/approvals`; klucz `{approval_id}:{category}:{powód}:push`), `OfficialWritesHandler`, `SubmissionReconciler` (`poll`, `reconcile`), `ApprovalTaskHandler`, `GateBypassException`, `InvalidTransitionException`; tagi `app.approval_subject`, `app.official_writer`, `app.status_reader` (D-41) |
| Wiadomości bramki | `ApprovalDispatchMessage` i `ApprovalDispatchWaitingMessage` (transport `official_writes`), `ApprovalTaskMessage` (`reads`: `approval.expire`, `approval.poll_status`, `approval.reconcile`); middleware `OfficialWritesRetryGuard` |
| Parametry bramki | `approval.expiry` (`approval.expiry.<subject_type>.pending`, `.approved`), `approval.poll_window` per konektor |
| Panel bramki | `ApprovalController`: `/approvals`, `/approvals/{id}/approve`, `/cancel`, `/copied`, `/retry`, `/renew`; token CSRF `approvals` |
| Konsola bramki | `app:approval idle` (krok 3 `make deploy`), `app:approval mark-restored` (krok 4 runbooka odtworzenia), `app:approval dummy-request` (fixtures dev, nigdy przy `APP_ENV=prod`) |
| Atrapa bramki (`src/Connector/Dummy`) | `DummyWriter` (operacje `submit`, `status`), `DummySubject` (usługi `dummy.subject.filing`, `dummy.subject.draft`), `DummySubjects`; scenariusze `accept`, `reject`, `duplicate`, `timeout`, `poll_reject`, `poll_pending` |
| `/health` od F0b | sprawdzenie `approval_unknown` |
| Dead-man | `DeadManNotifier::ping('heartbeat'\|'notifications')`; sekret `DEADMAN_PROD_NOTIFICATIONS_URL` |
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
| Pola panelu | `app_user.enrollment_token_hash`, `app_user.enrollment_expires_at`, `app_user.enrollment_used_at`: jednorazowy link SSH; `webauthn_credential.credential_source`: rekord biblioteki WebAuthn |
| Harmonogram i kolejka | `DefaultScheduleProvider`, `HeartbeatStore`, `ScheduledTaskHandler`, `FailureNotificationMessage`, `FailureNotificationHandler`, `RedactingTransportSerializer`, `RateLimitDeferralMiddleware`, `cache.scheduler`, `ops.last_heartbeat`, `scheduler_default` |
| Komendy | `EvidenceCommand`, `FixturesRecordCommand`, `PasskeyEnrolLinkCommand`, `PasskeyResetCommand`, `PushTestCommand`, `RecordDeployCommand` |
| HTTP i SMTP | `GuardedMailTransport`, `dummy.http_client`, `dummy.host_allowlist`, `dummy.mock_http_client`, `http_client.raw` |
| Parametry | `PUSH_ENDPOINT`, `PUSH_HOST`, `MAIL_HOST`, `MAIL_FROM`, `MAIL_TO`, `DEADMAN_HOST`, `PANEL_ORIGIN`, `PANEL_CERTIFICATE_PATH`, `KOKPIT_COMMIT`, `KOKPIT_IMAGE_DIGEST`, `GITHUB_RUN_ID`, `GITHUB_SERVER_URL`, `GITHUB_REPOSITORY` |
| Limity i zdrowie | `connector.daily_limits`, `health.queue_max_age`, `health.heartbeat_max_age`, `health.backup_max_age`, `health.disk_min_free_percent`, `health.tls_min_valid_days`; wyniki `database`, `reads_queue`, `official_writes_queue`, `notifications_queue`, `scheduler_heartbeat`, `failed_messages`, `disk_space`, `credentials`, `connectors`, `backup`, `tls_certificate` |
| Sekrety F0a | `PUSH_TEST_TOKEN`, `PUSH_PROD_TOKEN`, `MAIL_TEST_DSN`, `MAIL_PROD_DSN`, `DEADMAN_PROD_HEARTBEAT_URL`, `DEADMAN_PROD_NOTIFICATIONS_URL` (od F0b; nazwy produkcyjne wyłącznie dla konfiguracji ręcznej); `TS_AUTHKEY_FILE`, `RESTIC_PASSWORD_FILE`, `PGPASSFILE` |
| Narzędzia | `fixtures:scan` (skrypt Composer), `ArchitectureRules`, `OfficialWrite`, `FictionalIdentifiers` |
| Transporty i schemat | `messenger_messages`, `doctrine_migration_versions`, `cache_items` (tabele techniczne Symfony/Doctrine), `prevent_audit_mutation` (funkcja ochrony audytu) |
| Zmienne backupu | `RESTIC_REPOSITORY_FILE`, `AWS_SHARED_CREDENTIALS_FILE`, `BACKUP_DEADMAN_URL_FILE`, `BACKUP_DATABASE`, `BACKUP_HOST` |

`ConnectorError`: `connectors_disabled`, `host_not_allowed`, `invalid_url`, `network_error`, `rate_limited`, `unscoped_connector_request`, `http_error`. Wiadomość `FailureNotificationMessage.id` wskazuje wiersz transportu `failed`; handler używa potwierdzenia tego wiersza jako klucza idempotencji, bez tabeli `notification`.

Nagłówek `X-Kokpit-Notified` w `messenger_messages.headers` oznacza dostarczony alert F0a.

Usługi monitoringu: `HealthResources`, `NativeHealthResources`; `health.queue_max_age` w minutach.

- `app.webauthn.serializer` — serializer rekordów WebAuthn z fabryki biblioteki, z kodowaniem binarnych pól Base64URL.

- `app.dummy.default_endpoint` — fikcyjny adres bazowy konektora Dummy.
- `dummy.mock_response` — wyłącznie fikcyjna odpowiedź MockHttpClient w dev/test.
- `COMPOSE_PROFILES` — profil usług wybrany przez `KOKPIT_DEPLOYMENT`; `backup` i `vpn` tylko w `prod`.
- `HOST_SECRETS_DIR`, `PANEL_ORIGIN`, `MAIL_HOST`, `MAIL_FROM`, `MAIL_TO`, `PUSH_ENDPOINT`, `DEADMAN_HOST`, `PANEL_CERTIFICATE_PATH` — konfiguracja połączeń i panelu; wartości tajne pozostają w sejfie lub plikach hosta.

- `NTFY_CONFIG_FILE` — plik konfiguracji ntfy poza repo dla prod; domyślnie konfiguracja lokalna bez sekretów.

- `SOURCE_BRANCH`, `DISPATCH_PHASE`, `EVIDENCE_RUNNER` — lokalne wejścia skryptu CI do wyboru fazy i wykonawcy.
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
- `help`, `deploy`, `rollback` — cele Makefile: pomoc (domyślnie), wdrożenie kroków 1–7 i wycofanie do poprzednich obrazów; dwa ostatnie tylko dla `KOKPIT_DEPLOYMENT=prod`.
- `MakefileTest` — kolejność wdrożenia, odmowa poza PROD, zatrzymanie po błędzie, kontrola HTTP 200, wycofanie oraz brak przypisywania celów konektorów; komendy zewnętrzne zastąpione fikcyjnymi programami.

### Kolumny tabel F0a

Nazwy z istniejących tabel aplikacyjnych i technicznych; semantyka tabel aplikacyjnych w [data-model.md](data-model.md).

| Tabela | Kolumny |
| --- | --- |
| `app_user` | `id`, `username`, `display_name`, `disabled_at`, `enrollment_token_hash`, `enrollment_expires_at`, `enrollment_used_at`, `created_at`, `updated_at` |
| `audit_log` | `id`, `sequence`, `occurred_at`, `actor_user_id`, `action`, `target_type`, `target_id`, `environment`, `connector`, `approval_id`, `payload_sha256`, `details`, `prev_hash`, `hash` |
| `cache_items` | `item_id`, `item_data`, `item_lifetime`, `item_time` |
| `connector_call` | `id`, `sequence`, `environment`, `connector`, `operation`, `host`, `http_method`, `path`, `started_at`, `duration_ms`, `http_status`, `error_code`, `retry_after_s`, `request_sha256`, `request_bytes`, `response_sha256`, `response_bytes`, `approval_id`, `correlation_id` |
| `credential_state` | `id`, `environment`, `kind`, `secret_name`, `ciphertext`, `nonce`, `key_id`, `valid_from`, `valid_until`, `refresh_valid_until`, `status`, `rotated_at`, `last_smoke_ok_at`, `revoked_at`, `created_at`, `updated_at` |
| `doctrine_migration_versions` | `version`, `executed_at`, `execution_time` |
| `messenger_messages` | `id`, `body`, `headers`, `queue_name`, `created_at`, `available_at`, `delivered_at` |
| `rate_counter` | `id`, `environment`, `connector`, `bucket`, `window_start`, `count`, `blocked_until` |
| `stored_file` | `id`, `environment`, `kind`, `sha256`, `path`, `size_bytes`, `media_type`, `original_name`, `owner_table`, `owner_id`, `last_verified_at`, `verify_failed_at`, `retention_until`, `legal_hold`, `deleted_at`, `created_at`, `updated_at` |
| `webauthn_credential` | `id`, `app_user_id`, `credential_id`, `public_key`, `sign_count`, `transports`, `aaguid`, `label`, `last_used_at`, `revoked_at`, `credential_source`, `created_at`, `updated_at` |
