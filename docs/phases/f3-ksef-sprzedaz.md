# F3 KSeF sprzedaż

Sprzedaż w KSeF: szkic FreshBooks → FA(3) → bramka zatwierdzeń → KSeF, potem KOD I, PDF, doręczenie klientowi, zapis zwrotny i korekty KOR. Stan na 14 października 2026.

## Cel

Wystawianie faktur w KSeF obowiązuje od 1.04.2026 ([Dz.U. 2025 poz. 1203](https://api.sejm.gov.pl/eli/acts/DU/2025/1203/text.pdf)). Faktura jest wystawiona w dniu przesłania do KSeF, a klient dostaje dokument dopiero po nadaniu numeru (art. 106na ust. 1, [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Po F3 kokpit wystawia całą Twoją sprzedaż B2B: każda faktura idzie przez bramkę zatwierdzeń, bez automatycznych wysyłek i ponowień.

Według D-30 F3 idzie po F2.2/F2.3. F1 daje odczyt KSeF, `KsefGateway`, `company.identity`, weryfikację kontrahentów i kursy; F2.2 daje szkice FreshBooks w stanie `draft` i `ryczalt.service_map`; F0b daje bramkę, step-up i `invoice_issue`.

F3 dzieli się na pięć części. F3.1–F3.4 powstają offline: na ręcznie pisanych fixtures FreshBooks, ręcznie pisanych nagraniach KSeF z danymi fikcyjnymi i `FakeKsefGateway`. F3.5 wymaga api-test. Zapis zwrotny do FreshBooks pierwszy raz działa na PROD (D-35).

## Wejścia

Brak wejścia oznaczonego „stop” zatrzymuje część i kończy się pytaniem. Pozostałe idą na założeniu domyślnym z [../decisions.md](../decisions.md).

| Wejście | Część | Źródło | Brak oznacza |
| --- | --- | --- | --- |
| F0b, F1 i F2 odebrane (D-30) | wszystkie | [f0b-bramka-kalendarz.md](f0b-bramka-kalendarz.md), [f1-ksef-odczyt.md](f1-ksef-odczyt.md), [f2-ewidencja.md](f2-ewidencja.md) | stop dla odbioru F3; kod F3.1–F3.4 przed odbiorem według D-50 |
| XSD FA(3) 1-0E z importami w `resources/xsd` z sumami w `checksums.json` | F3.1 | [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md) | stop; pliki są w repo od F1 |
| `KsefGateway` nad biblioteką przypiętą do dokładnej wersji, z klientem PSR-18 | F3.5 | D-14, Q-19, sesja 1 F1 | stop dla F3.5; F3.1–F3.4 rozszerzają interfejs i `FakeKsefGateway` |
| dane TE z F1: testowa JDG, token z `InvoiceWrite`, drugi podmiot testowy jako nabywca `pl_b2b`; sejf testowy | F3.5 | Q-39, `ksef-te-provision` w [f1-ksef-odczyt.md](f1-ksef-odczyt.md) | stop dla F3.5 |
| tylko B2B; `eu_b2b_unidentified` zablokowany do przeglądu doradcy | wszystkie | D-05, Q-29 | – |
| zakres i bramka przeglądu doradcy | F3.1, go-live | D-04, Q-29 | założenie domyślne: minimum FA(3) z P_18 i złotymi plikami oraz PKWiU→ryczałt; bez zapisu przeglądu F3 zostaje na api-test |
| FreshBooks źródłem szkiców; znacznik gotowości; PDF dwujęzyczny z kokpitu | F3.2, F3.4 | Q-26, D-07 | założenie domyślne: znacznik `freshbooks.ready_marker` (`KSEF-GOTOWA`) w `po_number`; PDF PL i EN jako załącznik |
| korekty we FreshBooks | F3.2 | Q-27 | założenie domyślne: credit note tworzy szkic KOR; edycja po przyjęciu wymusza KOR |
| awaria KSeF | F3.3 | Q-28, D-15 | założenie domyślne: czekasz; Latarnia wstrzymuje kolejkę; bez trybu offline i certyfikatu typ 2 |
| P_1 | F3.1, F3.3 | D-16 | – |
| generator PDF z KOD I | F3.4 | D-17, Q-26 | założenie domyślne: szablon Twig→PDF, `endroid/qr-code` ^5.1, silnik HTML→PDF bez GPL (np. dompdf, LGPL-2.1); wybór silnika potwierdza sesja |
| „Doręcz klientowi” synchronicznie, poza Messenger | F3.4 | D-31 | – |
| FreshBooks bez firmy testowej; pierwszy zapis zwrotny na PROD | F3.2, F3.4, go-live | D-35 | kod i testy na ręcznie pisanych fixtures oznaczonych jako fikcyjne |
| narzędzie i numeracja sprzedaży przed F3 | F3.4, go-live | Q-10 | założenie domyślne: Aplikacja Podatnika KSeF, numery z FreshBooks |
| usługi, PKWiU 2015 i stawki | F3.1, F3.2 | Q-22 | kod i testy na fikcyjnym `ryczalt.service_map`; pozycja bez mapowania blokuje zatwierdzenie |
| wybór art. 31a ust. 2a | F3.1 | Q-23 | założenie domyślne: bez wyboru; kurs VAT według art. 31a ust. 1 i 2 |
| data usługi z FreshBooks | F3.2 | [../modules/freshbooks.md](../modules/freshbooks.md) | założenie domyślne: `fulfillment_date` niepotwierdzone; data albo okres usługi z panelu przed `ready` |
| zastępca przy terminach | go-live | Q-18 | założenie domyślne z Q-18; kodu F3 nie blokuje |
| `company.identity` (część FA(3)) | wszystkie | [../modules/ksef.md](../modules/ksef.md) | na `kokpit-dev` i w testach wartości fikcyjne; na PROD przed go-live |
| `ksef_token_write` PROD, SMTP PROD, FreshBooks PROD połączony | go-live | [../operations.md](../operations.md), [../security.md](../security.md) | go-live F3 czeka; odbioru F3.1–F3.5 nie blokuje |

Nagrania KSeF dla F3.1–F3.4 piszesz ręcznie według [KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json), wyłącznie z danymi fikcyjnymi, przez skaner fixtures. F3.5 nagrywa je na TE komendą `app:fixtures:record` i zastępuje wersje ręczne ([../../.claude/rules/integrations.md](../../.claude/rules/integrations.md)).

## Ładuj tylko

Ta lista jest autorytatywna. Kolumna „Części” mówi, której części dotyczy wiersz. [../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md) ładuje się sam, a [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md) sam przy edycji XML, XSD, konektorów i fixtures.

| Plik | Sekcje | Części |
| --- | --- | --- |
| [../README.md](../README.md) | cały (start sesji), zwłaszcza „Teraz – zgodność” i „Odłożone” | wszystkie |
| [../naming.md](../naming.md) | cały, z „Nazwami wycofanymi” | wszystkie |
| [../decisions.md](../decisions.md) | wiersze z F3 w kolumnie „Potrzebne w”; D-04, D-05, D-07, D-14, D-15, D-16, D-17, D-31, D-35, D-41; Q-10, Q-18, Q-22, Q-23, Q-26, Q-27, Q-28, Q-29 | wszystkie |
| [../facts.md](../facts.md) | fakty oznaczone dla F3 | wszystkie |
| [../modules/ksef.md](../modules/ksef.md) | części F3 z jego „Zakresu i faz”: „Reguła P_1”, „Mapowanie FA(3): scenariusze B2B”, „Korekty KOR”, „Dane własne sprzedawcy” | F3.1 |
| [../modules/ksef.md](../modules/ksef.md) | „Sprzedaż: przepływ wysyłki”, „Kolizje P_2”, „Skutki zapisu” (wiersze `subject1` z lokalnym szkicem i bez wiersza) | F3.2 |
| [../modules/ksef.md](../modules/ksef.md) | „Wysyłka: sesje, błędy i rekoncyliacja”, „Awarie KSeF i Latarnia”, „Limity i 429”, „Biblioteka i `KsefGateway`” | F3.3, F3.5 |
| [../modules/ksef.md](../modules/ksef.md) | „KOD I, KOD II i PDF”; krok 3 „Przejścia krok po kroku”; kolumna F3 w „Go-live” | F3.4, go-live |
| [../modules/ksef.md](../modules/ksef.md) | „Środowiska i hosty”, „Uwierzytelnienie” (token `ksef_token_write`) | F3.5 |
| [../modules/freshbooks.md](../modules/freshbooks.md) | „Znacznik gotowości i blokada P_2”, „Blokady obejścia”, „Klient i kontrahent”, „DTO faktury niezależne od źródła”, „Środowisko wierszy” | F3.2 |
| [../modules/freshbooks.md](../modules/freshbooks.md) | „Po przyjęciu: zapis zwrotny i doręczenie”, „Stripe i płatności” (numer KSeF na PDF) | F3.4 |
| [../approval-gate.md](../approval-gate.md) | cały, bez „Odbierz” | F3.2, F3.3 |
| [../data-model.md](../data-model.md) | „Jak czytać”, „Konwencje”, „Kwoty i zaokrąglenia”, „Daty i strefa czasu”, „Środowiska”; tabele `invoice`, `invoice_line`, `ksef_session`, `approval`, `stored_file`, `counterparty`, `verification`, `notification`, `obligation`, `connector_call`, `rate_counter`; „Stany encji”: „Faktura sprzedaży” | wszystkie |
| [../tax-rules.md](../tax-rules.md) | „Zakres: tylko B2B”, „Kursy walut” (kurs VAT sprzedaży, korekta po kursie pierwotnym), „Stawki ryczałtu”, „Przegląd doradcy: część podatkowa” | F3.1, F3.2 |
| [../compliance-calendar.md](../compliance-calendar.md) | „Silnik terminów” (`dedupe_key` `invoice_issue` od F3); wiersz `invoice_issue` w „Tabeli obowiązków”; „Przypomnienia”; „Stany obowiązku” | F3.2 |
| [../modules/rejestry-kursy.md](../modules/rejestry-kursy.md) | polityka weryfikacji per typ kontrahenta, `manual_evidence` | F3.2 |
| [../architecture.md](../architecture.md) | „Konektory” (`ksef`, `latarnia`, `freshbooks`, `mail`), „Środowiska i cele per konektor”, „Liczniki limitów”, „Biblioteki” (PDF, QR) | F3.3, F3.4 |
| [../security.md](../security.md) | wiersze F3 w „Inwentarzu sekretów”, „`audit_log` i `connector_call`”, „Panel” (step-up) | wszystkie |
| [../operations.md](../operations.md) | „Katalog zadań” (`latarnia.status`, `freshbooks.poll_invoices`, zadania bramki), „Messenger”, wiersz `ksef_token_write` w „Rotacjach poświadczeń”, wiersz „KSeF sprzedaż” w „Go-live” | F3.3, F3.4, go-live |
| [../testing.md](../testing.md) | „Polityka per konektor”, „Nagrania i odtwarzanie”, „`@group live`”, „KSeF TE”, „Złote przypadki”: „FA(3) i złote pliki” i „Bramka zatwierdzeń”, „Dowód działania”, „Konwencje kryteriów odbioru”, „Kryteria przekrojowe” | wszystkie |

API komponentów F0b, F1 i F2 czytasz z kodu (`src/Approval`, `src/Connector/Ksef`, `src/Connector/FreshBooks`, `src/Invoice`, `src/Xml`) oraz z `docs/evidence/f0b.md`, `docs/evidence/f1.md` i `docs/evidence/f2.md`.

## Zakres

### F3.1 FA(3) i XSD

Dom: [../modules/ksef.md](../modules/ksef.md), przypadki w „FA(3) i złote pliki” w [../testing.md](../testing.md).

| Element | Co powstaje |
| --- | --- |
| generator | `src/Xml`: FA(3) z DTO faktury niezależnego od źródła; nigdy z FreshBooks bezpośrednio |
| pola wspólne | `KodWaluty` zawsze; `JST`, `GV`, `P_16`, `P_17`, `P_18A` = 2; Podmiot1 z `company.identity`, Podmiot2 z `buyer_snapshot`; bez `BrakID` (D-05) |
| scenariusze | `eu_b2b`, `non_eu_b2b`, `pl_b2b` według tabeli scenariuszy; `eu_b2b_unidentified` bez złotego pliku, zablokowany do `ksef.fa3_review` |
| kwoty | VAT per stawka od sumy netto (art. 106e ust. 11); `P_14_xW` tylko przy walucie obcej z polskim VAT; kurs VAT z `FxRateProvider` według [../tax-rules.md](../tax-rules.md) |
| P_1 | dzisiejszy dzień w Europe/Warsaw z Symfony Clock, przy budowie XML; P_6, P_6A albo `OkresFa` z danych usługi (D-16) |
| KOR | `RodzajFaktury` KOR, `DaneFaKorygowanej` z `NrKSeFFaKorygowanej` albo `NrKSeFN`; `PrzyczynaKorekty` i `TypKorekty` opcjonalne; kurs faktury pierwotnej (art. 31b) |
| walidacja | XSD z `LIBXML_NONET` i kontrole przed wysyłką z [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md): UTF-8 bez BOM, bez instrukcji przetwarzania i znaków niezalecanych, do 1 MB, suma kontrolna NIP, `schema_version` |
| złote pliki | `tests/Fixtures/Ksef/golden`: jeden plik na scenariusz i na przypadek KOR; porównanie bajt po bajcie z `MockClock`, stałym P_2 i NIP z `FictionalIdentifiers` |

### F3.2 Gotowość, bramka i blokady

Dom: [../modules/freshbooks.md](../modules/freshbooks.md), [../approval-gate.md](../approval-gate.md).

| Element | Co powstaje |
| --- | --- |
| znacznik | `freshbooks.ready_marker` w `po_number` działa tylko przy włączonym `freshbooks.sales_enabled`; `ready_at`; zdjęcie przed decyzją → `draft` i `superseded` |
| onboarding | `counterparty` z `freshbooks_client_id`; kraj i identyfikator potwierdzasz w panelu; wymagany wiersz `verification` według polityki; bez onboardingu brak `ready` |
| warunki `ready` | data albo okres usługi; każda pozycja ze stawką z `ryczalt.service_map`; `invoice.environment` równy `KSEF_TARGET` |
| przedmioty bramki | `ApprovalSubject` dla `sales_invoice` i `correction_invoice` (D-41): payload z F3.1 zapisany raz jako `xml_sent`, `payload_sha256`, `summary` z podglądem z [../approval-gate.md](../approval-gate.md) |
| warunki prośby i decyzji | identyfikator nabywcy (VIES `valid` albo `manual_evidence`), P_1 nie w przyszłości, P_2 bez kolizji z numerem w KSeF, `tax_scenario` i stawki, `ksef.fa3_review` przy celu `prod`, poświadczenie zapisu |
| P_2 | `invoice_number_locked_at` przy decyzji; zmiana numeru we FreshBooks przed wysyłką → nowe zatwierdzenie; po przyjęciu → alert |
| wygaśnięcie | koniec dnia P_1; „Odnów” buduje XML z nową P_1 i nowym sha256 |
| `invoice_issue` | obowiązek per szkic z kluczem `invoice_issue:{miesiąc usługi}:{invoice_id}`; `done` przy `accepted` |
| obejście | `invoice.freshbooks_status` z każdego przebiegu; faktura poza Draft bez przyjętego odpowiednika po udanym przebiegu Subject1 → `notification` `freshbooks_bypass` i zadanie |
| Subject1 | szkic albo żywe zatwierdzenie o tym samym `kind` i P_2 → `cancelled_before_send` i `cancelled` z `manual_invoice`; faktura bez lokalnego wiersza od go-live → zadanie „faktura spoza kokpitu” |
| szkic KOR | z credit note, z edycji przyjętej faktury albo z panelu (Q-27); `ready` dopiero z numerem credit note; oryginał z numerem KSeF albo `NrKSeFN` |

### F3.3 Wysyłka i rekoncyliacja

Dom: [../modules/ksef.md](../modules/ksef.md), stany w [../approval-gate.md](../approval-gate.md). Wszystko na `FakeKsefGateway` i ręcznie pisanych nagraniach.

| Element | Co powstaje |
| --- | --- |
| `KsefGateway` | operacje zapisu i statusu: otwarcie sesji interaktywnej, wysyłka faktury, status faktury w sesji, zamknięcie, UPO, lista sesji, faktury sesji, lista błędnych; kody 21184 i 440 w `KsefError` |
| `OfficialWriter` KSeF | `submit` z `#[OfficialWrite]`, wołany tylko z `OfficialWritesHandler`; ponowne sha256 bajtów z `FileStore` przed wysyłką; token `ksef_token_write` |
| `StatusReader` KSeF | status faktury, UPO, lista sesji z dnia wysyłki, faktury sesji, lista błędnych; zapasowo dopasowanie z syncu Subject1 tokenem `ksef_token_read` |
| `ksef_session` | sesja na jedną wysyłkę; `form_code` porównany z `invoice.schema_version` przed wysyłką; UPO sesji jako `upo` |
| stany | `dispatching` → `submitted` → `accepted` albo `rejected`; timeout → `unknown`; `external_refs` po każdym kroku; `connector_call` z `approval_id`, `correlation_id` i `request_sha256` |
| 21184 | zamknięcie sesji i nowa sesja z tym samym payloadem |
| ręczne ponowienie | 440: `invoiceHash` porównany z sha256 każdego wcześniejszego `xml_sent` tej faktury; zgodny → `accepted` z `originalKsefNumber`, różny → `rejected` i alert kolizji P_2 |
| Latarnia | konektor `latarnia` (`/status`, `/messages`), zadanie `latarnia.status` co 5 minut i sprawdzenie przed wysyłką; stan inny niż `AVAILABLE` → `approval.dispatch_waiting`; przy `KSEF_TARGET=demo` pominięta |
| limity | 429 z `Retry-After` w grupach `ksef.session_open`, `ksef.invoice_send` i pozostałych z tabeli limitów → `blocked_until`, zatwierdzenie czeka |

### F3.4 Po przyjęciu

Dom: [../modules/ksef.md](../modules/ksef.md) („KOD I, KOD II i PDF”), [../modules/freshbooks.md](../modules/freshbooks.md) („Po przyjęciu”).

| Element | Co powstaje |
| --- | --- |
| KOD I | adres z hosta celu, NIP sprzedawcy, P_1 jako DD-MM-RRRR i SHA-256 bajtów `xml_sent` w Base64URL; pod kodem numer KSeF |
| PDF | szablon Twig→PDF PL i EN z danych `xml_sent`, z KOD I, numerem KSeF i adnotacją scenariusza; tylko w stanie `accepted`; `stored_file` rodzaju `pdf` |
| zapis zwrotny | `PUT` z `action_mark_as_sent`, `create_date` = P_1 i linią dopisaną do `notes`; najpierw odczyt, przy obecnym numerze pominięcie; transport `reads` z retry; `written_back_at` raz |
| „Doręcz klientowi” | akcja w panelu: e-mail konektorem `mail` z PDF i linkiem do płatności, adres i osoba kontaktowa z FreshBooks w chwili wysyłki; synchronicznie, poza Messenger (D-31); błąd → komunikat w panelu, bez ponowienia; wpis `invoice.delivered_to_client` |
| okres równoległy | `app:ksef:parallel-compare <rok-miesiąc>` według kroku 3 przejścia; raport rozbieżności jako CSV; bez `approval` i bez wywołań zapisujących |

### F3.5 api-test

Dom: „KSeF TE” w [../testing.md](../testing.md). Tylko `KSEF_TARGET=test`, testsuite `live`, dane fikcyjne z F1.

| Element | Co powstaje |
| --- | --- |
| uwierzytelnienie | token z `InvoiceWrite` z sejfu testowego, para `ksef_jwt_write` w `credential_state` |
| scenariusze | `eu_b2b`, `non_eu_b2b`, `pl_b2b` i KOR przez bramkę na TE; numer KSeF i UPO przy każdym; P_1 = dziś przy szkicu z datą z przeszłości |
| duplikat | ręczne ponowienie przyjętej faktury → 440 i `accepted` z numerem oryginału |
| nagrania | `app:fixtures:record ksef <scenariusz>` dla endpointów wysyłki i statusu; `app:fixtures:record latarnia status`; skaner fixtures; testy kontraktowe F3.3 przechodzą na nowych nagraniach |
| KOD I | PDF z faktury z TE; kod QR otwiera stronę `qr-test.ksef.mf.gov.pl` |

**Dowody.** Rejestr `tests/Acceptance/f3.yaml` dostaje wiersze części w jej sesji: klucz, komenda, asercja, artefakty, wykonawca ([../testing.md](../testing.md)).

## Poza zakresem

- Tryb offline, KOD II, certyfikat typ 2 i stan `offline_issued`: D-15, Q-28.
- Sesja wsadowa i załączniki do faktur.
- Faktury zaliczkowe i rozliczeniowe (`ZAL`, `ROZ`, `KOR_ZAL`, `KOR_ROZ`) oraz korekta zbiorcza.
- Sprzedaż B2C i OSS (D-05).
- Okna przesuwne limitów KSeF: odłożone do pierwszych odpowiedzi 429 na PROD (D-29).
- JPK_V7M(3), VAT-UE i VAT-UEK z kokpitu: zaślepka F5 w [../README.md](../README.md). F3 tworzy tylko zadania po przyjętej KOR.
- Ewidencja z przyjętej sprzedaży: F2.2 w [f2-ewidencja.md](f2-ewidencja.md); F3 jej nie zmienia.
- Webhooki FreshBooks: kokpit tylko odpytuje.

## Kolejność części

Jedna część na sesję. Każda część ma osobną tabelę w „Kryteriach odbioru”. Sesja kończy się, gdy przechodzą wiersze jej tabeli.

| Część | Zakres | Sieć | Warunek startu |
| --- | --- | --- | --- |
| F3.1 | generator FA(3), kontrole przed wysyłką, reguła P_1, złote pliki, KOR | bez sieci | wejścia „stop” z tabeli „Wejścia” |
| F3.2 | znacznik, onboarding, przedmioty bramki, warunki prośby i decyzji, P_2, wygaśnięcie, `invoice_issue`, obejście, Subject1, szkic KOR | bez sieci; fixtures FreshBooks | F3.1 z pass |
| F3.3 | `KsefGateway` zapisu, `OfficialWriter`, `StatusReader`, rekoncyliacja, 21184, 440, Latarnia, 429 | bez sieci; `FakeKsefGateway`, nagrania ręczne | F3.2 z pass |
| F3.4 | KOD I, PDF, zapis zwrotny, „Doręcz klientowi”, `app:ksef:parallel-compare` | bez sieci; Mailer w pamięci | F3.3 z pass |
| F3.5 | scenariusze na api-test, duplikat 440, nagrania, KOD I na qr-test | api-test, Latarnia test | F3.4 z pass; `KsefGateway` nad biblioteką z F1; dane TE z F1 |

F3.2 może iść równolegle z F3.1 na złotych plikach, bo bramka zna tylko bajty payloadu. F3.4 nie zależy od F3.3 poza stanem `accepted`, który testy ustawiają z fixtures.

## Kryteria odbioru

Komendy uruchamiasz w kontenerze `app`, przy `KSEF_TARGET=test`. Wiersze F3.1–F3.4 działają bez sieci, na danych fikcyjnych.

Kolumna „Kryterium” zaczyna się kluczem z [../naming.md](../naming.md) (D-33). Wiersz bez dopisku ma wykonawcę `ci`, wiersz `(live)` wykonawcę `live`, wiersz `[manual]` wykonawcę `manual`. Układ paczki i `manifest.json`: „Dowód działania” w [../testing.md](../testing.md).

| Zapis w „Dowodzie” | Znaczenie |
| --- | --- |
| `junit.xml`: `Nazwa*` | przypadki testowe z raportu JUnit paczki o nazwie zaczynającej się od `Nazwa` |
| `cmd/<klucz>.txt` | wyjście komendy po `Redactor` |
| `cmd/<klucz>.csv` | raport albo eksport na danych fikcyjnych, po `Redactor` |
| `db/<klucz>-<tabela>.csv` | wiersze tabeli utworzone przez kryterium, po `Redactor` |
| `golden/<klucz>.diff` | różnica wyniku wobec złotego pliku; pusty plik = zgodność |
| `ksef/<klucz>-*.xml` | wysłany XML, UPO i numer KSeF z api-test; sha256 w `manifest.json` |
| `manual/<klucz>.*` | plik z kroku `[manual]` z `var/evidence/f3/inbox/`; bez pliku wynik `pending` |

Wiersze `live` wymagają `CONNECTORS_ENABLED=1` i sejfu testowego. W CI uruchamia je ręcznie job `live` (`kokpit:evidence f3 --only=live`), nigdy na PROD.

### F3.1

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `fa3-golden-eu-b2b`: scenariusz `eu_b2b` | `vendor/bin/phpunit --filter Fa3GoldenEuB2b`: 1 000,00 EUR, VIES `valid`, `MockClock` 16.11.2026 | P_12 `np II`; P_13_9; P_18 = 1; `KodWaluty` EUR; Podmiot2 `KodUE` i `NrVatUE` bez prefiksu; brak P_14_xW; XSD zgodny; bajty równe złotemu plikowi | `junit.xml`: `Fa3GoldenEuB2b*`; `golden/fa3-golden-eu-b2b.diff` pusty; `cmd/fa3-golden-eu-b2b.txt`: wynik `LIBXML_NONET` i sha256 XSD równe `checksums.json` |
| `fa3-golden-non-eu-b2b`: scenariusz `non_eu_b2b` | `vendor/bin/phpunit --filter Fa3GoldenNonEuB2b`: 1 000,00 USD, `manual_evidence` | P_12 `np I`; P_13_8; Podmiot2 `KodKraju` i `NrID`; P_18 według założenia z [../modules/ksef.md](../modules/ksef.md) do przeglądu doradcy; XSD zgodny | `junit.xml`: `Fa3GoldenNonEuB2b*`; `golden/fa3-golden-non-eu-b2b.diff` pusty |
| `fa3-golden-pl-b2b`: scenariusz `pl_b2b` i zaokrąglenie | `vendor/bin/phpunit --filter Fa3GoldenPlB2b`: dwie pozycje po 333,33 zł netto, 23% | P_13_1 666,66; P_14_1 153,33; P_15 819,99; P_18 = 2; Podmiot2 `NIP` | `junit.xml`: `Fa3GoldenPlB2b*`; `golden/fa3-golden-pl-b2b.diff` pusty |
| `fa3-golden-pl-b2b-eur`: `pl_b2b` w EUR | `vendor/bin/phpunit --filter Fa3GoldenPlB2bEur`: 1 000,00 EUR netto, 23%, fikcyjny kurs VAT 4,2500 z nagrania NBP | P_14_1 230,00; P_14_1W 977,50; kurs i numer tabeli z `fx_rate` | `junit.xml`: `Fa3GoldenPlB2bEur*`; `golden/fa3-golden-pl-b2b-eur.diff` pusty |
| `fa3-golden-kor`: KOR kwotowa | `vendor/bin/phpunit --filter Fa3GoldenKor`: różnica −200,00 EUR do przyjętej faktury `eu_b2b`; wariant bez i z `PrzyczynaKorekty` i `TypKorekty` | `RodzajFaktury` KOR; `DaneFaKorygowanej` z `NrKSeF` i `NrKSeFFaKorygowanej`; kurs faktury pierwotnej; oba warianty zgodne z XSD; pusty wybór pomija element | `junit.xml`: `Fa3GoldenKor*`; `golden/fa3-golden-kor.diff` pusty |
| `fa3-golden-kor-zero`: KOR do zera i KOR spoza KSeF | `vendor/bin/phpunit --filter Fa3GoldenKorZero`: błędny NIP nabywcy; faktura sprzed przejścia bez numeru KSeF | KOR do zera i nowa faktura z `replaces_invoice_id`; KOR z `NrKSeFN` i pustym `corrects_invoice_id` | `junit.xml`: `Fa3GoldenKorZero*`; `golden/fa3-golden-kor-zero.diff` pusty |
| `fa3-presend-checks`: kontrole przed wysyłką | `vendor/bin/phpunit --filter Fa3PresendChecks`: BOM, instrukcja przetwarzania, znak niezalecany, plik ponad 1 MB, zła suma NIP, `schema_version` inny niż `formCode`, XML niezgodny z XSD | odmowa przed prośbą w każdym wariancie; zero plików `xml_sent` i wierszy `approval` | `junit.xml`: `Fa3PresendChecks*` |
| `fa3-p1-today`: P_1 z dnia budowy | `vendor/bin/phpunit --filter Fa3P1Today`: szkic z datą 13.11.2026 we FreshBooks, `MockClock` 16.11.2026 10:00 Europe/Warsaw; drugi wariant 15.11.2026 23:30Z | P_1 2026-11-16; w drugim wariancie P_1 2026-11-16, bo w Europe/Warsaw to 16.11 00:30; data z FreshBooks nigdy nie trafia do P_1 | `junit.xml`: `Fa3P1Today*` |
| `fa3-unidentified-blocked`: `eu_b2b_unidentified` | `vendor/bin/phpunit --filter Fa3UnidentifiedBlocked`: VIES `invalid`, `manual_evidence` statusu przedsiębiorcy, cel `test`, `ksef.fa3_review` bez tego scenariusza | prośba zablokowana; zero `xml_sent`; komunikat z odwołaniem do Q-29 | `junit.xml`: `Fa3UnidentifiedBlocked*` |

### F3.2

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `ready-marker-disabled`: znacznik przed włączeniem sprzedaży | `vendor/bin/phpunit --filter ReadyMarkerDisabled`: fixture szkicu z `KSEF-GOTOWA` w `po_number`, `freshbooks.sales_enabled` wyłączony | szkic zostaje w `draft`; zero `approval` i `xml_sent` | `junit.xml`: `ReadyMarkerDisabled*` |
| `ready-marker-request`: prośba o zatwierdzenie | `vendor/bin/phpunit --filter ReadyMarkerRequest`: ten sam szkic przy włączonym `freshbooks.sales_enabled` | `ready` z `ready_at`; jeden `xml_sent`; `approval` `pending` z `payload_sha256` równym sha256 pliku; `summary` z podglądem; push `approval_pending`; wpis `approval.requested` | `junit.xml`: `ReadyMarkerRequest*`; `db/ready-marker-request-approval.csv`; `db/ready-marker-request-stored_file.csv` z sha256 |
| `ready-marker-removed`: zdjęcie znacznika | `vendor/bin/phpunit --filter ReadyMarkerRemoved`: zdjęcie przed decyzją; potem wariant po zatwierdzeniu | przed decyzją `draft` i `superseded`; po zatwierdzeniu bez skutku | `junit.xml`: `ReadyMarkerRemoved*` |
| `ready-blockers`: warunki stanu `ready` | `vendor/bin/phpunit --filter ReadyBlockers`: klient bez onboardingu; brak daty usługi; pozycja bez `ryczalt.service_map`; wiersz z `environment` = `demo` przy celu `test` | każdy wariant zostaje w `draft` z powodem w panelu; zero `approval` | `junit.xml`: `ReadyBlockers*` |
| `approval-buyer-id`: identyfikator nabywcy | `vendor/bin/phpunit --filter ApprovalBuyerId`: `eu_b2b` z VIES `invalid`, `unverified_retry`, potem `valid`; `pl_b2b` bez wyniku białej listy, potem z `manual_evidence` | prośba i decyzja zablokowane bez ważnego wyniku; po `valid` albo `manual_evidence` prośba powstaje (D-05) | `junit.xml`: `ApprovalBuyerId*` |
| `approval-p1-future`: P_1 w przyszłości | `vendor/bin/phpunit --filter ApprovalP1Future`: payload z P_1 jutro | prośba i decyzja zablokowane | `junit.xml`: `ApprovalP1Future*` |
| `approval-p2-lock`: blokada i kolizja P_2 | `vendor/bin/phpunit --filter ApprovalP2Lock`: decyzja ze step-up; potem szkic z P_2 równym fakturze z Subject1; potem zmiana numeru we FreshBooks przed wysyłką | `invoice_number_locked_at` przy decyzji; kolizja blokuje prośbę; zmiana numeru daje `superseded` i nowe `pending` z nowym sha256 | `junit.xml`: `ApprovalP2Lock*`; `db/approval-p2-lock-invoice.csv` |
| `approval-fa3-review-prod`: przegląd doradcy przy celu `prod` | `vendor/bin/phpunit --filter ApprovalFa3ReviewProd`: test jednostkowy warunku decyzji z celem `prod` podanym jako argument, bez zmiennej `KSEF_TARGET` i bez konektora; `ksef.fa3_review` bez `pl_b2b`, potem z nim | bez wpisu decyzja zablokowana; z wpisem przechodzi; przy celu `test` warunek pominięty | `junit.xml`: `ApprovalFa3ReviewProd*` |
| `invoice-approval-renew`: wygaśnięcie z dniem P_1 | `vendor/bin/phpunit --filter InvoiceApprovalRenew`: `pending` z P_1 16.11.2026, `MockClock` 17.11.2026 00:01, potem „Odnów” | `expired`; faktura `ready`; zero nowych `xml_sent` i push; „Odnów” daje `pending` z P_1 2026-11-17 i innym sha256, bez push | `junit.xml`: `InvoiceApprovalRenew*`; `db/invoice-approval-renew-approval.csv` |
| `invoice-issue-per-draft`: obowiązek `invoice_issue` | `vendor/bin/phpunit --filter InvoiceIssuePerDraft`: szkic za usługę z 10.2026, potem przyjęcie w KSeF | obowiązek z `dedupe_key` `invoice_issue:2026-10-01:{invoice_id}` i `due_date` 2026-11-15 (polityka `none`); przy `accepted` stan `done` | `junit.xml`: `InvoiceIssuePerDraft*`; `db/invoice-issue-per-draft-obligation.csv` |
| `freshbooks-bypass-alert`: faktura poza Draft bez KSeF | `vendor/bin/phpunit --filter FreshBooksBypassAlert`: fixture faktury ze statusem `sent` utworzonej po `valid_from` `freshbooks.sales_enabled`, potem udany przebieg Subject1 bez odpowiednika | `notification` `freshbooks_bypass` i zadanie w panelu; faktura z wierszem `revenue_entry` ze źródłem `freshbooks` nie jest kandydatem | `junit.xml`: `FreshBooksBypassAlert*`; `db/freshbooks-bypass-alert-notification.csv` |
| `subject1-cancels-approval`: faktura spoza kokpitu o tym samym P_2 | `vendor/bin/phpunit --filter Subject1CancelsApproval`: szkic z `approved`, potem Subject1 z tym samym `kind` i P_2 | zatwierdzenie `cancelled` z `manual_invoice`; szkic `cancelled_before_send`; faktura z KSeF z jego `freshbooks_id`; zero wywołań zapisujących | `junit.xml`: `Subject1CancelsApproval*`; `db/subject1-cancels-approval-approval.csv` |
| `subject1-outside-cockpit-task`: zadanie „faktura spoza kokpitu” | `vendor/bin/phpunit --filter Subject1OutsideCockpitTask`: Subject1 z nieznanym numerem bez lokalnego wiersza, przed i po `valid_from` `freshbooks.sales_enabled` | przed: `accepted` bez zadania; po: `accepted` i zadanie w panelu | `junit.xml`: `Subject1OutsideCockpitTask*` |
| `kor-draft-from-freshbooks`: szkic KOR | `vendor/bin/phpunit --filter KorDraftFromFreshBooks`: credit note do przyjętej faktury; edycja kwoty przyjętej faktury bez credit note; zmiana samego numeru; KOR do faktury bez numeru KSeF i bez `NrKSeFN` | szkic KOR z różnicą; bez numeru credit note brak `ready`; sama zmiana numeru daje tylko alert; KOR bez numeru oryginału zablokowana | `junit.xml`: `KorDraftFromFreshBooks*`; `db/kor-draft-from-freshbooks-invoice.csv` |

### F3.3

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `ksef-send-accepted`: wysyłka do przyjęcia | `vendor/bin/phpunit --filter KsefSendAccepted`: `approved` faktury `pl_b2b`, `FakeKsefGateway` i nagranie: sesja, wysyłka, status 200, zamknięcie, UPO | `dispatching` → `submitted` → `accepted`; numer KSeF, `ksef_issue_mode` `online`, `sent_at`, `ksef_number_assigned_at`; `external_refs` po każdym kroku; UPO jako `upo`; `ksef_session` zamknięta; `connector_call` z `approval_id` i `request_sha256`; sha256 wysłanych bajtów równe `payload_sha256` | `junit.xml`: `KsefSendAccepted*`; `db/ksef-send-accepted-approval.csv`; `db/ksef-send-accepted-connector_call.csv`; `cmd/ksef-send-accepted.txt`: sha256 `xml_sent` i `payload_sha256` |
| `ksef-send-hash-mismatch`: XML zmieniony po zatwierdzeniu | `vendor/bin/phpunit --filter KsefSendHashMismatch`: podmiana bajtów w `FileStore` po decyzji | `cancelled` z `hash_mismatch`; faktura `ready`; zero wywołań `ksef`; push | `junit.xml`: `KsefSendHashMismatch*`; `db/ksef-send-hash-mismatch-connector_call.csv` bez wierszy `ksef` |
| `ksef-send-once`: jedna wysyłka | `vendor/bin/phpunit --filter KsefSendOnce`: dwa kliknięcia, dwa workery i duplikat wiadomości `official_writes` | jedno otwarcie sesji i jedna wysyłka faktury | `junit.xml`: `KsefSendOnce*` |
| `ksef-send-p1-day-change`: zmiana dnia przed wysyłką | `vendor/bin/phpunit --filter KsefSendP1DayChange`: `approved` z P_1 16.11.2026, worker przy `MockClock` 17.11.2026 00:05 | `expired`; faktura `ready`; zero wywołań `ksef`; push `connector_failure` | `junit.xml`: `KsefSendP1DayChange*` |
| `ksef-send-form-code`: niezgodny formularz sesji | `vendor/bin/phpunit --filter KsefSendFormCode`: `ksef_session.form_code` inny niż `invoice.schema_version` | brak wysyłki faktury; sesja zamknięta bez faktury; zatwierdzenie nie przechodzi do `submitted` | `junit.xml`: `KsefSendFormCode*` |
| `ksef-send-rejected`: odrzucenie walidacyjne | `vendor/bin/phpunit --filter KsefSendRejected`: nagranie statusu faktury z kodem błędu walidacji | `rejected` z `result_code`; faktura `rejected`, potem po poprawce `ready` i nowe `pending`, bez KOR | `junit.xml`: `KsefSendRejected*` |
| `ksef-send-21184`: sesja tymczasowo niedostępna | `vendor/bin/phpunit --filter KsefSend21184`: nagranie 21184 przy pierwszej sesji | zamknięcie i nowa sesja z tym samym payloadem; jedna faktura przyjęta | `junit.xml`: `KsefSend21184*`; `db/ksef-send-21184-ksef_session.csv` z dwiema sesjami |
| `ksef-send-timeout-reconcile`: timeout i rekoncyliacja | `vendor/bin/phpunit --filter KsefSendTimeoutReconcile`: timeout po wysyłce; potem nagranie statusu z numerem i zgodnym `invoiceHash` | `unknown` bez ponowień; `SubmissionReconciler` tylko odczytem; `accepted` z numerem i UPO; zero drugich wywołań zapisujących; `messenger:failed:retry` nie ponawia | `junit.xml`: `KsefSendTimeoutReconcile*`; `db/ksef-send-timeout-reconcile-connector_call.csv` z jedną wysyłką |
| `ksef-reconcile-paths`: ścieżki rekoncyliacji | `vendor/bin/phpunit --filter KsefReconcilePaths`: tylko referencja sesji z fakturą na liście błędnych; brak referencji z fakturą na liście sesji z dnia wysyłki; brak referencji i dopasowanie z syncu Subject1; brak wyniku | `rejected`; `accepted`; `accepted` przez `ksef_token_read`; `unknown` i propozycja ręcznego ponowienia w panelu | `junit.xml`: `KsefReconcilePaths*` |
| `ksef-retry-440`: ręczne ponowienie i duplikat | `vendor/bin/phpunit --filter KsefRetry440`: `unknown` bez wyniku, ręczne ponowienie ze step-up; nagranie 440 z `originalKsefNumber`, w wariancie ze zgodnym i z innym `invoiceHash` | stare zatwierdzenie `superseded`, wpis `approval.retry_requested`; zgodny hash → `accepted` z numerem oryginału, bez drugiej faktury; inny → `rejected` i alert kolizji P_2 | `junit.xml`: `KsefRetry440*`; `cmd/ksef-retry-440.txt`: sha256 wszystkich `xml_sent` faktury i zdekodowany `invoiceHash` |
| `ksef-send-latarnia`: Latarnia wstrzymuje kolejkę | `vendor/bin/phpunit --filter KsefSendLatarnia`: nagrania `/status` `AVAILABLE`, `MAINTENANCE`, `FAILURE`, `TOTAL_FAILURE`; wariant z celem `demo` | przy stanie innym niż `AVAILABLE` zatwierdzenie zostaje `approved` z `approval.dispatch_waiting`, zero wywołań `ksef`, status w panelu; przy `demo` warunek pominięty | `junit.xml`: `KsefSendLatarnia*`; `db/ksef-send-latarnia-connector_call.csv` z wierszami `latarnia` |
| `ksef-send-429`: 429 przy wysyłce | `vendor/bin/phpunit --filter KsefSend429`: nagranie 429 z `Retry-After` przy otwarciu sesji | `blocked_until` w `ksef.session_open`; zatwierdzenie `approved`; zero wywołań przed `blocked_until`; push według reguły limitów | `junit.xml`: `KsefSend429*`; `db/ksef-send-429-rate_counter.csv` |

### F3.4

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `kod-i-url`: adres KOD I | `vendor/bin/phpunit --filter KodIUrl`: przyjęta faktura z fikcyjnym NIP, P_1 16.11.2026, cel `test`; wariant z XML po ponownej serializacji | adres `https://qr-test.ksef.mf.gov.pl/invoice/{NIP}/16-11-2026/{SHA-256 xml_sent w Base64URL}`; host z konfiguracji celu; ponowna serializacja daje inny hash, więc generator czyta tylko bajty `xml_sent` | `junit.xml`: `KodIUrl*`; `cmd/kod-i-url.txt`: adres i sha256 `xml_sent` |
| `invoice-pdf`: PDF z KOD I | `vendor/bin/phpunit --filter InvoicePdf`: przyjęte faktury `eu_b2b`, `non_eu_b2b`, `pl_b2b`; wariant w stanie `approved` | PDF PL i EN z numerem KSeF, adnotacją scenariusza i kodem QR, którego treść równa się adresowi z `kod-i-url`; dane z `xml_sent`; w stanie `approved` brak PDF | `junit.xml`: `InvoicePdf*`; `cmd/invoice-pdf.txt`: sha256 PDF fikcyjnej faktury `eu_b2b` i zdekodowana treść kodu QR |
| `deliver-to-client`: „Doręcz klientowi” | `vendor/bin/phpunit --filter DeliverToClient`: WebTestCase, przyjęta faktura, klient z fixture FreshBooks, Symfony Mailer w pamięci | jeden e-mail z PDF i linkiem do płatności na adres z FreshBooks; zero wierszy `approval` i zero wiadomości Messenger (D-31); wiersz `connector_call` `mail`; wpis `invoice.delivered_to_client` | `junit.xml`: `DeliverToClient*`; `db/deliver-to-client-audit_log.csv`; `db/deliver-to-client-connector_call.csv` |
| `deliver-to-client-error`: błąd doręczenia | `vendor/bin/phpunit --filter DeliverToClientError`: transport w pamięci rzuca błąd; potem drugie kliknięcie; wariant faktury w stanie `submitted` | komunikat w panelu, jedna próba, bez ponowienia; drugie kliknięcie to nowa próba, panel pokazuje datę poprzedniego doręczenia; przed `accepted` akcja niedostępna | `junit.xml`: `DeliverToClientError*` |
| `freshbooks-write-back`: zapis zwrotny | `vendor/bin/phpunit --filter FreshBooksWriteBack`: przyjęta faktura, fixture faktury FreshBooks z `notes`; drugi przebieg z numerem już w `notes` | jedno `PUT` z `action_mark_as_sent` = true, `create_date` = P_1 i linią z numerem KSeF dopisaną do `notes`; drugi przebieg bez `PUT`; `written_back_at` raz; wiadomość na transporcie `reads`, poza `official_writes` | `junit.xml`: `FreshBooksWriteBack*`; `cmd/freshbooks-write-back.txt`: treść `PUT` po `Redactor` |
| `ksef-parallel-compare`: okres równoległy | `bin/console app:ksef:parallel-compare 2026-11` na fixtures: faktury FreshBooks połączone z fakturami Subject1, jedna z inną kwotą P_13_1 | raport: zgodne faktury i jedna różnica P_13_1; P_1 i P_2 z XML Subject1; zero wierszy `approval` i zero wywołań zapisujących | `cmd/ksef-parallel-compare.csv`; `db/ksef-parallel-compare-approval.csv` pusty; `golden/ksef-parallel-compare.diff` pusty |

### F3.5

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `ksef-te-write-auth` (`live`): token z `InvoiceWrite` na TE | `bin/console app:credential:smoke ksef_token_write` | `ok`; para `ksef_jwt_write` w `credential_state`; wiersz `connector_call` z hostem `api-test.ksef.mf.gov.pl` | `cmd/ksef-te-write-auth.txt`: wyjście komendy, wycinek logu z zamaskowanym tokenem i JWT |
| `ksef-te-send-scenarios` (`live`): scenariusze na TE | `vendor/bin/phpunit --testsuite live --filter KsefTeSendScenarios`: szkice z fixtures FreshBooks z datą z przeszłości dla `eu_b2b`, `non_eu_b2b` i `pl_b2b`, zatwierdzenie ze step-up testowym | przy każdym numer KSeF i UPO; P_1 = dzień przebiegu; sha256 `xml_sent` równe `payload_sha256` i zdekodowanemu `invoiceHash` | `junit.xml`: `KsefTeSendScenarios*`; `ksef/ksef-te-send-scenarios-*.xml`: XML, UPO i numery KSeF z api-test |
| `ksef-te-send-kor` (`live`): KOR na TE | `vendor/bin/phpunit --testsuite live --filter KsefTeSendKor`: KOR kwotowa do faktury `pl_b2b` z poprzedniego wiersza | numer KSeF i UPO; oryginał `corrected` | `junit.xml`: `KsefTeSendKor*`; `ksef/ksef-te-send-kor-*.xml` |
| `ksef-te-duplicate-440` (`live`): duplikat na TE | `vendor/bin/phpunit --testsuite live --filter KsefTeDuplicate440`: wymuszony `unknown` po przyjęciu, ręczne ponowienie tego samego XML | KSeF zwraca 440 z `originalKsefNumber`; `accepted` z numerem oryginału; jedna faktura na TE | `junit.xml`: `KsefTeDuplicate440*`; `cmd/ksef-te-duplicate-440.txt`: kod 440, numer oryginału, porównanie hashy |
| `ksef-te-fixtures` (`live`): nagrania wysyłki | `bin/console app:fixtures:record ksef <scenariusz>` dla sesji, wysyłki, statusu, zamknięcia, UPO, listy sesji i listy błędnych; `bin/console app:fixtures:record latarnia status` | nagrania przez scrubber; skaner bez trafień; testy F3.3 przechodzą na nowych nagraniach | `cmd/ksef-te-fixtures.txt`: lista nagrań z sha256 i wynik skanera; `junit.xml`: testy F3.3 na nagraniach |
| `kod-i-qr-test` `[manual]`: KOD I na qr-test | PDF z faktury z `ksef-te-send-scenarios`; skanujesz kod QR telefonem | strona `qr-test.ksef.mf.gov.pl` pokazuje fakturę z tym numerem KSeF | `manual/kod-i-qr-test.png`: zrzut strony qr-test; `manual/kod-i-qr-test.pdf` |

### Go-live w kryteriach

Dwa wiersze `[manual]` wynikają z decyzji, nie z api-test: przegląd doradcy (D-04) i pierwszy zapis zwrotny na PROD (D-35).

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `freshbooks-bypass-checklist` `[manual]`: blokady we FreshBooks | checklista F3 z „Blokad obejścia” w [../modules/freshbooks.md](../modules/freshbooks.md) | Send i e-mail z FreshBooks nieużywane; profile cykliczne bez wysyłki; auto-bill wyłączony | odhaczony wpis w `docs/evidence/f3.md`; `manual/freshbooks-bypass-checklist.png` zamaskowany |
| `advisor-review-recorded` `[manual]`: przegląd doradcy | wynik przeglądu przy Q-29 i D-04 w [../decisions.md](../decisions.md); wiersz `setting` `ksef.fa3_review` | lista zatwierdzonych `tax_scenario` i KOR; sha256 złotych plików równe plikom w `tests/Fixtures/Ksef/golden` | wpis przy D-04; `manual/advisor-review-recorded.txt`: wiersz `ksef.fa3_review` i sha256 |
| `freshbooks-first-write-back` `[manual]`: pierwszy zapis zwrotny na PROD | pierwsza faktura po przełączeniu przez bramkę na `kokpit-prod` (D-35) | numer KSeF we FreshBooks; faktura oznaczona jako wysłana; `written_back_at` ustawione | `manual/freshbooks-first-write-back.png`: zamaskowany zrzut faktury FreshBooks; wpis `audit_log` `invoice.written_back` bez NIP i nazw |

**Kryteria przekrojowe:** wiersze od F0a do F2 w [../testing.md](../testing.md). `cc-17`, `cc-18` i `cc-21` od F3 obejmują też konektor `ksef`: pokrywają je `ksef-send-hash-mismatch`, `ksef-send-once` i `ksef-send-timeout-reconcile`.

## Dowód fazy

**Dowód fazy:** `docs/evidence/f3.md`, jedna lista dla wszystkich części. Status F3 w [../README.md](../README.md) linkuje listę po odbiorze.

| Element | Treść |
| --- | --- |
| paczka | `bin/console kokpit:evidence f3 --only=ci` i `--only=live` → `var/evidence/f3/<YYYYMMDD-HHMM>/`; układ, `manifest.json` i wyniki `pass`, `fail`, `pending` według „Paczki dowodów” w [../testing.md](../testing.md); całość po `Redactor` |
| CI | job `evidence` uruchamia wiersze `ci`, ręczny job `live` wiersze `live`; oba wgrywają `var/evidence/**` jako artefakt przebiegu w `good-yellow-bee/kokpit-jdg`; `var/` jest w `.gitignore` |
| lista | `docs/evidence/f3.md`, pisana ręcznie (D-28): data, commit, linki do przebiegów `evidence` i `live`, kryterium → pass albo fail → artefakt, odhaczone `[manual]`; numery KSeF i UPO z api-test, sha256 wysłanych bajtów |
| odbiór | wymagane joby zielone, udany przebieg `live`, wszystkie wiersze pass, wiersze `[manual]` odhaczone przez Ciebie; `freshbooks-first-write-back` dopiero po przełączeniu (D-35) |

## Go-live

Sekwencja i wycofanie: wiersz „KSeF sprzedaż” w „Go-live” w [../operations.md](../operations.md) oraz kolumna F3 w „Go-live” w [../modules/ksef.md](../modules/ksef.md). Lista go-live nie należy do kryteriów odbioru, poza dwoma wierszami `[manual]` z decyzji.

| Krok | Wynik | Zapis |
| --- | --- | --- |
| api-test | wiersze F3.1–F3.5 z pass | `docs/evidence/f3.md` |
| DEMO | próba generalna w `kokpit-dev` na danych fikcyjnych, jeśli DEMO to umożliwia; inaczej pominięta | wynik albo „pominięta” w liście dowodów |
| poświadczenia PROD | `ksef_token_write` w sejfie PROD; `app:credential:smoke ksef_token_write` | wpis `credential.registered` |
| okres równoległy | pełny miesiąc `app:ksef:parallel-compare` na `kokpit-prod`, bez wysyłki, bez rozbieżności | liczba faktur, liczba różnic i sha256 raportu; bez NIP i nazw |
| przegląd doradcy | `advisor-review-recorded` | wpis przy D-04 i `ksef.fa3_review` |
| przełączenie | 1. dnia miesiąca nowy wiersz `freshbooks.sales_enabled` = włączony; pierwsza faktura przez bramkę; numer i UPO sprawdzone w Aplikacji Podatnika; `freshbooks-first-write-back` | numer KSeF zamaskowany, sha256 UPO |
| stare narzędzie | wyłączone dla wystawiania tego samego dnia | wiersz w liście dowodów |

- Prawdziwe dane nie trafiają na TE ani DEMO. Wiersz `invoice` z prawdziwej firmy przechodzi do `ready` tylko przy `KSEF_TARGET=prod` ([../modules/freshbooks.md](../modules/freshbooks.md)).
- `KSEF_TARGET=prod` ustawiasz Ty osobnym wdrożeniem.
- Wycofanie: nowy wiersz `freshbooks.sales_enabled` = wyłączony z `valid_from` = dziś; żywe `pending` i `approved` anulujesz w panelu; `KSEF_TARGET` zostaje `prod` dla odczytu F1.

## Pierwszy prompt

```text
Przeczytaj docs/README.md i docs/phases/f3-ksef-sprzedaz.md, potem tylko pliki z sekcji „Ładuj tylko” dla tej części.
Krok 0: sprawdź docs/naming.md, wiersze F3 w docs/decisions.md i fakty F3 w docs/facts.md.
Brak wejścia oznaczonego „stop” oznacza stop i pytanie.
Zrealizuj jedną część z sekcji „Kolejność części”: pierwszą, której kryteria nie mają pass, a warunek startu jest spełniony.
F3.1–F3.4 działają bez sieci: fixtures FreshBooks pisane ręcznie, nagrania KSeF pisane ręcznie z danymi fikcyjnymi, FakeKsefGateway.
Każdy zapis do KSeF tylko przez bramkę i official_writes; zapis zwrotny FreshBooks tylko na fixtures.
Zanim zaczniesz kodować, wypisz plan i pytania. Na koniec uruchom testy i bin/console kokpit:evidence f3 --only=ci,
pokaż wynik kryteriów tej części i otwarte wiersze [manual]; po F3.5 także kryteria przekrojowe z docs/testing.md.
```
