# F5 JPK

Złożenia w urzędzie z kokpitu: JPK_EWP(4) i JPK_ST(1) przez API JPK 5.6.0, potem JPK_V7M(3) oraz VAT-UE i VAT-UEK przez bramkę e-Deklaracji. Każde złożenie idzie przez bramkę zatwierdzeń. Stan na 15 października 2026.

## Cel

JPK_EWP(4) i JPK_ST(1) za 2026 składasz do 30.04.2027 ([broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf)). JPK_V7M(3) i VAT-UE składasz do 25. dnia miesiąca (art. 99 ust. 1 i art. 100 ust. 3 [ustawy o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Po F5 kokpit buduje te pliki z własnych danych, waliduje je offline i wysyła po Twojej decyzji, bez automatycznych wysyłek i ponowień.

Według D-30 F5 ma dwa etapy. F5a: JPK_EWP(4) i JPK_ST(1) w PROD do 31.03.2027. F5b po tej bramce: JPK_V7M(3) i VAT-UE. F2.4 daje generator JPK_EWP(4), `app:jpk:dry-run` i tabelę `filing`; F0b daje bramkę zatwierdzeń, step-up i obowiązki; F1 i F3 dają faktury z numerami KSeF; F2.3 daje złożenie `manual` i blokadę okresu (D-49).

F5 dzieli się na sześć części. F5.1, F5.2, F5.4 i F5.5 powstają offline: na `FakeJpkGateway`, `FakeEDeklaracjeGateway`, ręcznie pisanych nagraniach z danymi fikcyjnymi i fikcyjnym zbiorze JPK (D-56). F5.3 i F5.6 (live) wymagają `test-e-dokumenty.mf.gov.pl` i `test-bramka.edeklaracje.gov.pl`.

## Wejścia

Brak wejścia oznaczonego „stop” zatrzymuje część i kończy się pytaniem. Pozostałe idą na założeniu domyślnym z [../decisions.md](../decisions.md).

| Wejście | Część | Źródło | Brak oznacza |
| --- | --- | --- | --- |
| F0b, F1, F2 i F3 odebrane (D-30) | wszystkie | pliki faz w `docs/phases` | stop dla odbioru F5; kod F5.1, F5.2, F5.4 i F5.5 przed odbiorem według D-56 |
| XSD JPK_EWP(4) w `resources/xsd` z sumą w `checksums.json` | F5.1, F5.3 | [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md) | stop; plik jest w repo od F2.4 |
| XSD JPK_ST(1) w `resources/xsd` | F5.2, wiersz `jpk-te-st-upo` w F5.3 | D-37, D-63 | jest od 15.10.2026: `Schemat_JPK_ST(1)_v1-2.xsd` z importami; bez pliku: stop tylko dla F5.2 i tego wiersza |
| XSD SIG-2008_v2-0 i metadanych `InitUpload` w `resources/xsd` | F5.1 (`jpk-init-upload`, `jpk-auth-data`) | [../modules/jpk.md](../modules/jpk.md), „Pliki XSD”; D-63 | jest od 15.10.2026: `SIG-2008_v2-0.xsd` i `initupload.xsd`; bez pliku: stop dla tych dwóch wierszy |
| XSD JPK_V7M(3) z importami w `resources/xsd` | F5.4 | [../modules/jpk.md](../modules/jpk.md), „Pliki XSD”; D-63 | jest od 15.10.2026: `Schemat_JPK_V7M(3)_v1-0E.xsd` z importami; bez pliku: stop dla F5.4 |
| XSD VAT-UE(5) i VAT-UEK(5) w `resources/xsd` | F5.5 | [../modules/jpk.md](../modules/jpk.md), „Pliki XSD”; D-63 | jest od 15.10.2026: `VAT-UE(5)_v2-0E.xsd` i `VAT-UEK(5)_v2-1E.xsd` z importami; bez pliku: stop dla F5.5 |
| dostęp sesji do `test-e-dokumenty.mf.gov.pl` i magazynu `taxdocumentstorageNNtst`; certyfikat klucza publicznego MF celu `test` | F5.3, F5.6 | [../architecture.md](../architecture.md), [../modules/jpk.md](../modules/jpk.md) | stop dla części live; offline testy używają fikcyjnej pary kluczy |
| dostęp do `test-bramka.edeklaracje.gov.pl` i działanie bramki testowej | F5.6 | R-31 w [../facts.md](../facts.md) | bramka niedostępna: udokumentowany brak, nagrania ręczne zostają, pierwsze złożenie PROD jako `[manual]` |
| fikcyjne `auth_data` w sejfie testowym | F5.3, F5.6 | D-37, [../security.md](../security.md) | stop dla części live |
| fikcyjny zbiór JPK w `tests/Fixtures/Jpk` | wszystkie | „Fikcyjny zbiór JPK” w [../testing.md](../testing.md) | uzupełnia sesja części: rekordy `NrKSeF`, `BFK`, `DI`, sprzedaż np I i np II, dostawca z UE i spoza UE, VAT-UE z dwoma nabywcami |
| środki trwałe i JPK_ST | F5.2 | Q-25 | założenie domyślne: brak; `jpk_st.enabled` = false |
| obecne narzędzie JPK_V7M i rejestracja VAT-UE | F5.4, F5.5, go-live | Q-12 | założenie domyślne: e-mikrofirma albo Klient JPK WEB; rejestrację VAT-UE sprawdzasz przed go-live F5b |
| import usług i WHT | F5.4 | Q-21 | założenie domyślne z Q-21; klasyfikację ocenia przegląd doradcy |
| nadwyżka VAT i GTU | F5.4 | Q-31 | założenie domyślne: przeniesienie całej P_53 do P_62; bez GTU i procedur |
| kanał VAT-UE z danymi autoryzującymi | F5.5, F5.6 | D-27, Q-32 | – ; 418 albo 410 na bramce testowej → nowy rekord D-xx |
| korekty okresów sprzed go-live | wszystkie | [../modules/jpk.md](../modules/jpk.md) | składasz w narzędziach MF; kokpit buduje tylko JPK_V7M(3) od go-live |
| `company.identity` (część JPK) i `auth_data` PROD z `revenue_n2` | go-live | [../security.md](../security.md), [../operations.md](../operations.md) | go-live czeka; odbioru F5.1–F5.6 nie blokuje |
| bilans otwarcia `opening.*` w PROD | go-live F5b | „Bilans otwarcia” w [../compliance-calendar.md](../compliance-calendar.md), D-49 | go-live JPK_V7M(3) czeka |

Nagrania API JPK i bramki e-Deklaracji dla części offline piszesz ręcznie według [specyfikacji JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf) i [specyfikacji e-Deklaracji 2.4](https://podatki-arch.mf.gov.pl/media/3616/edek_specyfikacja_we-wy_2-4_test_www.pdf), wyłącznie z danymi fikcyjnymi, przez skaner fixtures. F5.3 i F5.6 nagrywają je komendą `app:fixtures:record` i zastępują wersje ręczne ([../../.claude/rules/integrations.md](../../.claude/rules/integrations.md)).

## Ładuj tylko

Ta lista jest autorytatywna. Kolumna „Części” mówi, której części dotyczy wiersz. [../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md) ładuje się sam, a [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md) sam przy edycji XML, XSD, konektorów i fixtures.

| Plik | Sekcje | Części |
| --- | --- | --- |
| [../README.md](../README.md) | cały (start sesji), zwłaszcza „Teraz – zgodność”, „Kamienie milowe” w „Planie faz” i „Odłożone” | wszystkie |
| [../naming.md](../naming.md) | cały, z „Planem F5 (D-56)” i „Nazwami wycofanymi” | wszystkie |
| [../decisions.md](../decisions.md) | wiersze z F5 w kolumnie „Potrzebne w”; D-02, D-27, D-29, D-30, D-37, D-41, D-49, D-56; Q-12, Q-21, Q-25, Q-29, Q-31, Q-32 | wszystkie |
| [../facts.md](../facts.md) | fakty oznaczone dla F5; R-31 | wszystkie |
| [../modules/jpk.md](../modules/jpk.md) | „Konektor `jpk`: API JPK 5.6.0” (środowiska, przepływ, kody, limity, dane autoryzujące, środowisko testowe), „JPK_EWP(4) i JPK_ST(1)”, „Pliki XSD” | F5.1, F5.2, F5.3 |
| [../modules/jpk.md](../modules/jpk.md) | „JPK_V7M(3)”, „Korekty”, „Uzgodnienia przed zatwierdzeniem” | F5.4, F5.6 |
| [../modules/jpk.md](../modules/jpk.md) | „VAT-UE i VAT-UEK” | F5.5, F5.6 |
| [../modules/ewidencja.md](../modules/ewidencja.md) | „Mapowanie JPK_EWP(4)”, „Wykaz środków trwałych i WNiP” | F5.1, F5.2 |
| [../approval-gate.md](../approval-gate.md) | cały, bez „Odbierz” | F5.1, F5.4, F5.5 |
| [../compliance-calendar.md](../compliance-calendar.md) | „Zamknięcie miesiąca” (bramki JPK_V7M i JPK_EWP(4)), „Blokada okresu”, „Bilans otwarcia”; wiersze `vat_jpk_v7m`, `vat_ue`, `jpk_ewp`, `wht_payment`, `ift_2r`, `cit_10z` w „Tabeli obowiązków” | F5.1, F5.4, F5.5 |
| [../tax-rules.md](../tax-rules.md) | „Kursy walut” (kurs VAT), „Koszty: import usług i dokumenty spoza KSeF”, „Podatek u źródła (WHT)”, „Korekty przychodu” | F5.4, F5.5 |
| [../data-model.md](../data-model.md) | „Jak czytać”, „Konwencje”, „Kwoty i zaokrąglenia”, „Daty i strefa czasu”, „Środowiska”; tabele `filing`, `period`, `fixed_asset`, `invoice`, `approval`, `stored_file`, `connector_call`, `rate_counter`, `setting`, `obligation`; „Stany encji”: „Złożenie” i „Powiązanie z bramką zatwierdzeń” | wszystkie |
| [../architecture.md](../architecture.md) | „Konektory” (`jpk`, `edeklaracje`), „Środowiska i cele per konektor” z „`HostAllowlist`”, „Liczniki limitów”, „Biblioteki” (własne cienkie klienty) | F5.1, F5.3, F5.5, F5.6 |
| [../security.md](../security.md) | wiersz `auth_data` w „Inwentarzu sekretów”, „Maskowanie: `Redactor`”, „`audit_log` i `connector_call`”, „Panel” (step-up) | wszystkie |
| [../operations.md](../operations.md) | „Katalog zadań” (`mf_keys.refresh`, `jpk.replace_di`, zadania bramki), „Messenger”, wiersz `auth_data` w „Rotacjach poświadczeń”, wiersze JPK i VAT-UE w „Go-live” | F5.1, F5.4, go-live |
| [../testing.md](../testing.md) | „Polityka per konektor”, „Nagrania i odtwarzanie”, „`@group live`”, „Fikcyjny zbiór JPK”, „Złote przypadki”: „Ewidencja i JPK_EWP(4)” i „Bramka zatwierdzeń”, „Dowód działania”, „Konwencje kryteriów odbioru”, „Kryteria przekrojowe” | wszystkie |

API komponentów F0b, F1, F2 i F3 czytasz z kodu (`src/Approval`, `src/Xml`, `src/Ledger`, `src/Invoice`, `src/Connector/Ksef` jako wzór `KsefWriter`) oraz z list `docs/evidence/f0b.md`, `f1.md`, `f2.md` i `f3.md`.

## Zakres

### F5.1 Konektor `jpk` i bramka złożeń

Dom: [../modules/jpk.md](../modules/jpk.md), stany w [../approval-gate.md](../approval-gate.md). Wszystko na `FakeJpkGateway`, ręcznie pisanych nagraniach i fikcyjnej parze kluczy MF.

| Element | Co powstaje |
| --- | --- |
| `JpkGateway` | własny cienki klient: `InitUploadSigned`, Put Blob każdej części, `FinishUpload`, `Status/{referenceNumber}`; adresy i wersja API w konfiguracji celu; TLS 1.2 albo 1.3 z sześcioma zestawami szyfrów |
| koperta | ZIP DEFLATE z jednym dokumentem; części do 62 914 560 bajtów; AES-256-CBC z PKCS#7; MD5 części w Base64; klucz AES opakowany RSA-2048 (ECB, PKCS#1) certyfikatem MF celu; zaszyfrowanych części kokpit nie przechowuje |
| metadane | `InitUpload` do 100 KB, `FormCode` z `kodSystemowy` i `wersjaSchemy`, `FileName` zgodne z `[a-zA-Z0-9_.-]{5,55}`, `HashValue` = sha256 dokumentu w Base64; walidacja XSD metadanych lokalnie |
| dane autoryzujące | `AuthData` SIG-2008 z `auth_data` (`birth_date`, `revenue_n2`, D-37) w pamięci workera, przy wysyłce; nigdy razem z XAdES (kod 136); poza `xml_sent`, `connector_call` i logami |
| przedmiot bramki | `FilingSubject` dla `filing` JPK_EWP: `xml_sent` z generatora F2.4 zapisany raz, `payload_sha256`, `summary` z [../approval-gate.md](../approval-gate.md); bramki „przed budową JPK_EWP(4)” przy prośbie |
| `OfficialWriter` i `StatusReader` | `JpkWriter` z `#[OfficialWrite]`, wołany tylko z `OfficialWritesHandler`; ponowne sha256 `xml_sent` przed wysyłką; numer referencyjny do `external_refs` od razu po `InitUploadSigned`; UPO jako `upo` przy `filing`; `submitted_via` = `jpk` |
| kody | tabela „Kody wyników” z [../modules/jpk.md](../modules/jpk.md): 170 i 407 → UPO oryginału i `accepted`; 411 i 300 po `FinishUpload` → `unknown` i `SubmissionReconciler`; 158 → `rejected` i push; pozostałe 4xx → `rejected` z `filing.status_code` |
| limity | `rate_counter` `jpk.init_upload` sprawdzany przed pierwszym wywołaniem; wyczerpany → `approved` i `approval.dispatch_waiting` |
| `HostAllowlist` | magazyn tylko wzorcem `taxdocumentstorage[0-9]{2}tst` przy `JPK_TARGET=test` |

### F5.2 JPK_ST(1)

Dom: „JPK_ST(1): węzeł `EWP`” w [../modules/jpk.md](../modules/jpk.md). Startuje dopiero z XSD JPK_ST(1) w `resources/xsd` (D-37).

| Element | Co powstaje |
| --- | --- |
| generator | `JpkStBuilder` z F2.4 zamiast `XsdMissingException`: węzeł `EWP` z F_1–F_16 z `fixed_asset`; wiersze `jpk-st-build` i `jpk-st-dry-run` trafiają do `tests/Acceptance/f2.yaml` w tej samej sesji (D-37) |
| przedmiot bramki | `FilingSubject` dla JPK_ST tą samą ścieżką co JPK_EWP; prośba tylko przy `jpk_st.enabled` = true, inaczej komunikat Q-25 |

### F5.3 (live) JPK_EWP i JPK_ST na test-e-dokumenty

Dom: „Środowisko testowe” w [../modules/jpk.md](../modules/jpk.md) i „Fikcyjny zbiór JPK” w [../testing.md](../testing.md). Tylko `JPK_TARGET=test`, testsuite `live`, dane i dane autoryzujące fikcyjne.

| Element | Co powstaje |
| --- | --- |
| wysyłka | JPK_EWP(4) z fikcyjnego zbioru przez bramkę; UPO przy `filing`; JPK_ST(1), gdy F5.2 ma pass |
| duplikat | ponowna wysyłka tych samych bajtów → kod duplikatu, UPO oryginału, bez drugiego złożenia |
| TLS | wynegocjowany zestaw szyfrów z listy sześciu |
| nagrania | `app:fixtures:record jpk <scenariusz>`; skaner fixtures; testy kontraktowe F5.1 przechodzą na nowych nagraniach |

### F5.4 JPK_V7M(3) i zamknięcie miesiąca

Dom: „JPK_V7M(3)” w [../modules/jpk.md](../modules/jpk.md), „Zamknięcie miesiąca”, „Blokada okresu” i „Bilans otwarcia” w [../compliance-calendar.md](../compliance-calendar.md). Bez sieci; wysyłka na `FakeJpkGateway` ścieżką z F5.1.

| Element | Co powstaje |
| --- | --- |
| generator | `JpkV7mBuilder`: ewidencja sprzedaży i zakupu według „Mapowania dokumentów”, kwoty w PLN po kursie VAT, K_11 z K_12, import usług w K_27–K_30 i K_42–K_43; dokument `imported` blokuje budowę |
| znaczniki | `JpkMarker` według „Tabeli znaczników”, od góry; `online` bez numeru blokuje budowę; znacznik liczony przy budowie, nie zapisywany |
| deklaracja | P_38, P_48, P_51, P_53, P_62 w pełnych złotych; P_39 = P_62 ostatniego przyjętego JPK_V7M za M−1, także `manual`; pierwszy miesiąc z `opening.vat_carry_forward_pln`; `vat.excess_policy` według Q-31 |
| bramki | bramki kompletności przy prośbie, zapis do `period.checklist`; lista pozycji `DI` w `summary` |
| zamknięcie | przyjęty JPK_V7M zamyka okres (`closed_by_filing_id`) i oznacza `vat_jpk_v7m` jako `done`, jak złożenie `manual` z D-49 |
| korekta | po ponownym otwarciu `CelZlozenia` 2, P_ORDZU, kolejny `seq`, `supersedes_id` |
| `jpk.replace_di` | zadanie codzienne: faktura `offline24` albo `maintenance` z numerem nadanym po `xml_sent` przyjętego JPK_V7M → `period.correction_required_at` |
| bilans otwarcia | formularz „Otwarcie” z krokami 1–7 z [../compliance-calendar.md](../compliance-calendar.md), step-up, wpis `opening_balance.confirmed`; po potwierdzeniu zwykły zapis kluczy `opening.*` odrzucony |
| WHT | kwota `amount_due` w obowiązkach `wht_payment` z kosztu dostawcy, stawki z `setting` i `wht_cert_valid_until`; `cit_10z` tylko przy sumie > 0; wpłatę, IFT-2R i CIT-10Z składasz ręcznie |

### F5.5 VAT-UE i VAT-UEK

Dom: „VAT-UE i VAT-UEK” w [../modules/jpk.md](../modules/jpk.md). Bez sieci; `FakeEDeklaracjeGateway` i ręcznie pisane odpowiedzi SOAP.

| Element | Co powstaje |
| --- | --- |
| generator | `VatUeBuilder`: przyjęte faktury `eu_b2b` z ważnym VIES i `vat_period_start` w M, grupowane po kraju i numerze VAT UE nabywcy; P_Uc w pełnych złotych; KOR ze znakiem różnicy; `eu_b2b_unidentified` poza plikiem |
| bramka K_12 | suma niezaokrąglonych `net_pln` równa K_12 najnowszego JPK_V7M okresu do grosza, tolerancja P_Uc 0,50 zł × liczba kontrahentów; niezgodność blokuje prośbę |
| KOR | tabela „KOR do faktury `eu_b2b`”: przebudowa VAT-UE, czekanie na `accepted` albo VAT-UEK z `supersedes_id` |
| `EDeklaracjeGateway` | SOAP `sendUnsignDocument` i `requestUPO`; `DaneAutoryzujace` wstawiane jako ostatni element dopiero przy wysyłce, walidacja XSD w pamięci; `xml_sent` bez danych autoryzujących |
| `OfficialWriter` i `StatusReader` | `EDeklaracjeWriter`, tylko z `OfficialWritesHandler`; statusy z tabeli `requestUPO`: 200 → `accepted` z UPO, 300 i 411 → `unknown` i rekoncyliacja, 418 → `rejected` i nowy D-xx, 100–102 bez ponownej wysyłki |
| limity | `rate_counter` `edeklaracje.send` przed `sendUnsignDocument` |

### F5.6 (live) JPK_V7M i VAT-UE na bramkach testowych

Dom: „Środowisko testowe” i „Kanał wysyłki: weryfikacja Q-32” w [../modules/jpk.md](../modules/jpk.md). Tylko cele `test`, testsuite `live`, dane fikcyjne.

| Element | Co powstaje |
| --- | --- |
| JPK_V7M(3) | plik z fikcyjnego zbioru przez bramkę na `test-e-dokumenty`; UPO przy `filing` |
| VAT-UE i VAT-UEK | wysyłka na `test-bramka.edeklaracje.gov.pl` z fikcyjnymi danymi autoryzującymi; sprawdzenie 418 i 410; przy niedziałającej bramce udokumentowany brak (R-31) |
| nagrania | `app:fixtures:record jpk <scenariusz>` dla JPK_V7M, `app:fixtures:record edeklaracje <scenariusz>`; skaner fixtures; testy F5.4 i F5.5 przechodzą na nowych nagraniach |

**Dowody.** Rejestr `tests/Acceptance/f5.yaml` ma od startu wiersze przekrojowe; wiersze części dochodzą w jej sesji: klucz, komenda, asercja, artefakty, wykonawca ([../testing.md](../testing.md)).

## Poza zakresem

- PIT-28 z kokpitu: [../roadmap.md](../roadmap.md); składasz ręcznie, złożenie `PIT_28` tylko jako `manual`.
- Podpis kwalifikowany i zaufany (XAdES): sposób uwierzytelnienia to strategia, F5 implementuje tylko dane autoryzujące.
- Korekty okresów sprzed go-live F5 i starsze struktury JPK: narzędzia MF.
- Kody GTU i procedur (Q-31), JPK_V7K, OSS i sprzedaż B2C (D-05).
- Wpłaty VAT i WHT, IFT-2R i CIT-10Z: kokpit liczy i przypomina, nie wysyła.
- Zastępca z UPL-1: składa narzędziami MF ([../operations.md](../operations.md)).
- e-Doręczenia API (F4): odłożone (D-29).

## Kolejność części

Jedna część na sesję. Każda część ma osobną tabelę w „Kryteriach odbioru”. Sesja kończy się, gdy przechodzą wiersze jej tabeli.

| Część | Zakres | Sieć | Warunek startu |
| --- | --- | --- | --- |
| F5.1 | `JpkGateway`, koperta, metadane, `AuthData`, `FilingSubject` JPK_EWP, `JpkWriter`, kody, limity | bez sieci; `FakeJpkGateway`, nagrania ręczne, fikcyjna para kluczy | wejścia „stop” z tabeli „Wejścia” dla F5.1 |
| F5.2 | JPK_ST(1): generator i przedmiot bramki | bez sieci | F5.1 z pass; XSD JPK_ST(1) (stop) |
| F5.3 (live) | JPK_EWP i JPK_ST na test-e-dokumenty, duplikat, TLS, nagrania | `test-e-dokumenty.mf.gov.pl`, magazyn `tst` | F5.1 z pass; dostęp do hostów testowych; fikcyjne `auth_data` |
| F5.4 | JPK_V7M(3), znaczniki, deklaracja, bramki, zamknięcie, korekta, `jpk.replace_di`, bilans otwarcia, kwoty WHT | bez sieci; `FakeJpkGateway` | F5.1 z pass; XSD JPK_V7M(3) (stop) |
| F5.5 | VAT-UE i VAT-UEK, bramka K_12, `EDeklaracjeGateway`, `EDeklaracjeWriter` | bez sieci; `FakeEDeklaracjeGateway` | F5.4 z pass; XSD VAT-UE(5) i VAT-UEK(5) (stop) |
| F5.6 (live) | JPK_V7M i VAT-UE na bramkach testowych, 418 i 410, nagrania | `test-e-dokumenty.mf.gov.pl`, `test-bramka.edeklaracje.gov.pl` | F5.3, F5.4 i F5.5 z pass |

- F5a to F5.1–F5.3 i go-live JPK_EWP(4) do 31.03.2027. F5b to F5.4–F5.6 i go-live JPK_V7M(3) z VAT-UE po tej bramce (D-30).
- Kod F5.4 i F5.5 może powstać przed go-live F5a, bo działa bez sieci (D-56). Go-live F5b czeka na złożenie PROD JPK_EWP.
- F5.2 nie blokuje F5.3 dla JPK_EWP ani F5.4: bez XSD JPK_ST(1) czeka tylko JPK_ST.

## Kryteria odbioru

Komendy uruchamiasz w kontenerze `app`, przy `JPK_TARGET=test` i `EDEKLARACJE_TARGET=test`. Wiersze F5.1, F5.2, F5.4 i F5.5 działają bez sieci, na danych fikcyjnych.

Kolumna „Kryterium” zaczyna się kluczem z [../naming.md](../naming.md) (D-33). Wiersz bez dopisku ma wykonawcę `ci`, wiersz `(live)` wykonawcę `live`, wiersz `[manual]` wykonawcę `manual`. Układ paczki i `manifest.json`: „Dowód działania” w [../testing.md](../testing.md).

| Zapis w „Dowodzie” | Znaczenie |
| --- | --- |
| `junit.xml`: `Nazwa*` | przypadki testowe z raportu JUnit paczki o nazwie zaczynającej się od `Nazwa` |
| `cmd/<klucz>.txt` | wyjście komendy po `Redactor` |
| `db/<klucz>-<tabela>.csv` | wiersze tabeli utworzone przez kryterium, po `Redactor` |
| `golden/<klucz>.diff` | różnica wyniku wobec złotego pliku; pusty plik = zgodność |
| `jpk/<klucz>-*.xml` | wysłany XML i UPO z bramki testowej; sha256 w `manifest.json` |
| `manual/<klucz>.*` | plik z kroku `[manual]` z `var/evidence/f5/inbox/`; bez pliku wynik `pending` |

Wiersze `live` wymagają `CONNECTORS_ENABLED=1` i sejfu testowego. W CI uruchamia je ręcznie job `live` (`kokpit:evidence f5 --only=live`), nigdy na PROD.

### F5.1

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `jpk-envelope`: koperta pliku | `vendor/bin/phpunit --filter JpkEnvelope`: JPK_EWP(4) z fikcyjnego zbioru; wariant powyżej 62 914 560 bajtów po ZIP | odszyfrowanie fikcyjnym kluczem prywatnym i rozpakowanie daje bajty równe `xml_sent`; części do 62 914 560 bajtów; MD5 części w Base64 (24 znaki); `HashValue` = sha256 `xml_sent` w Base64 | `junit.xml`: `JpkEnvelope*`; `cmd/jpk-envelope.txt`: sha256 `xml_sent`, liczba części, MD5 |
| `jpk-init-upload`: metadane | `vendor/bin/phpunit --filter JpkInitUpload` | metadane zgodne z XSD `initupload.xsd`, do 100 KB; `AuthData`, którego opublikowany XSD nie deklaruje (D-63), jest jedynym dodatkiem, ostatnim elementem, w Base64; `FormCode` z dokumentu; `FileName` zgodne ze wzorcem; `AuthData` bez XAdES | `junit.xml`: `JpkInitUpload*` |
| `jpk-auth-data`: dane autoryzujące | `vendor/bin/phpunit --filter JpkAuthData`: fikcyjne `auth_data` z sejfu testowego | `AuthData` zgodne z XSD SIG-2008 i zaszyfrowane kluczem pliku; zero wystąpień kwoty N−2 w `xml_sent`, `connector_call`, logach i `messenger_messages`; data urodzenia tylko w `Podmiot1` dokumentu (wymóg XSD JPK_EWP(4), D-57), poza tym zero wystąpień | `junit.xml`: `JpkAuthData*`; `cmd/jpk-auth-data.txt`: liczba trafień 0 |
| `jpk-ewp-request`: prośba o zatwierdzenie | `vendor/bin/phpunit --filter JpkEwpRequest`: rok z 12 okresami `ledger_closed_at`; warianty: okres otwarty, sprzedaż w walucie bez kursu PIT, faktura w `submitted` | `filing` `ready`, jeden `xml_sent`, `approval` `pending` z `payload_sha256` równym sha256 pliku i `summary`; suma K_8 równa sumie `revenue_entry` roku; każdy wariant odmawia przed prośbą | `junit.xml`: `JpkEwpRequest*`; `db/jpk-ewp-request-approval.csv` |
| `jpk-send-accepted`: wysyłka do przyjęcia | `vendor/bin/phpunit --filter JpkSendAccepted`: `approved`, `FakeJpkGateway` i nagranie: `InitUploadSigned`, Put Blob, `FinishUpload`, `Status` 120, potem 200 | `dispatching` → `submitted` → `accepted`; numer referencyjny w `external_refs` po `InitUploadSigned`; UPO jako `upo`; `submitted_via` = `jpk`; `connector_call` z `approval_id` i `request_sha256` | `junit.xml`: `JpkSendAccepted*`; `db/jpk-send-accepted-filing.csv`; `db/jpk-send-accepted-connector_call.csv` |
| `jpk-send-hash-mismatch`: plik zmieniony po zatwierdzeniu | `vendor/bin/phpunit --filter JpkSendHashMismatch`: podmiana bajtów `xml_sent` w `FileStore` po decyzji | `cancelled` z `hash_mismatch`; złożenie `draft` (tabela „Powiązanie z bramką zatwierdzeń” w [../data-model.md](../data-model.md), D-57); zero wywołań `jpk`; push | `junit.xml`: `JpkSendHashMismatch*` |
| `jpk-send-once`: jedna wysyłka | `vendor/bin/phpunit --filter JpkSendOnce`: dwa kliknięcia, dwa workery i duplikat wiadomości `official_writes` | jedno `InitUploadSigned` | `junit.xml`: `JpkSendOnce*` |
| `jpk-send-duplicate`: duplikat | `vendor/bin/phpunit --filter JpkSendDuplicate`: nagrania 170 przy `InitUploadSigned` i 407 przy `Status` | pobrane UPO oryginału; `accepted`; zero drugich złożeń | `junit.xml`: `JpkSendDuplicate*` |
| `jpk-send-reconcile`: wynik nieznany | `vendor/bin/phpunit --filter JpkSendReconcile`: timeout po `FinishUpload`; `Status` 411; `Status` 300 przed i po `FinishUpload`; potem wgranie UPO | `unknown` bez ponowień, `SubmissionReconciler` tylko odczytem; 300 przed `FinishUpload` → `rejected`; wgranie UPO → `cancelled` z `manual_filing` i `accepted` jako `manual` | `junit.xml`: `JpkSendReconcile*`; `db/jpk-send-reconcile-connector_call.csv` z jedną wysyłką |
| `jpk-send-rejected`: odrzucenie | `vendor/bin/phpunit --filter JpkSendRejected`: `Status` 401, 408, 428 i 422; `InitUploadSigned` 158 | `rejected` z `filing.status_code`; 158 bez numeru referencyjnego i z push; ponowienie tylko nowym zatwierdzeniem | `junit.xml`: `JpkSendRejected*` |
| `jpk-rate-limit`: licznik `jpk.init_upload` | `vendor/bin/phpunit --filter JpkRateLimit`: licznik wyczerpany | zatwierdzenie `approved` z `approval.dispatch_waiting`; zero wywołań `jpk` | `junit.xml`: `JpkRateLimit*`; `db/jpk-rate-limit-rate_counter.csv` |
| `jpk-storage-host`: magazyn plików | `vendor/bin/phpunit --filter JpkStorageHost`: adresy uploadu z magazynem `taxdocumentstorage01tst`, `taxdocumentstorage01` i obcym hostem przy `JPK_TARGET=test` | tylko `tst` dopuszczony; pozostałe odrzucone przed żądaniem; `rejected` bez Put Blob | `junit.xml`: `JpkStorageHost*` |

### F5.2

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `jpk-st-request`: JPK_ST przez bramkę | `vendor/bin/phpunit --filter JpkStRequest`: fikcyjny środek trwały, `jpk_st.enabled` = false, potem true; wysyłka na `FakeJpkGateway` | przy false brak prośby i komunikat Q-25; przy true XML zgodny z XSD, `approval` `pending`, potem `accepted` z UPO; `jpk-st-build` i `jpk-st-dry-run` z pass w `f2.yaml` | `junit.xml`: `JpkStRequest*`; `jpk/jpk-st-request.xml` |

### F5.3

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `jpk-te-ewp-upo` (`live`): JPK_EWP na test-e-dokumenty | `vendor/bin/phpunit --testsuite live --filter JpkTeEwpUpo`: fikcyjny zbiór, zatwierdzenie ze step-up testowym | UPO zapisane przy `filing`; `accepted`; sha256 `xml_sent` równe `payload_sha256` | `junit.xml`: `JpkTeEwpUpo*`; `jpk/jpk-te-ewp-upo-*.xml`: XML i UPO |
| `jpk-te-duplicate` (`live`): ponowna wysyłka | `vendor/bin/phpunit --testsuite live --filter JpkTeDuplicate`: te same bajty nowym zatwierdzeniem | kod duplikatu (170, 407 albo 411 z rekoncyliacją); UPO oryginału; jedno złożenie | `junit.xml`: `JpkTeDuplicate*`; `cmd/jpk-te-duplicate.txt`: kod i numery referencyjne |
| `jpk-te-st-upo` (`live`): JPK_ST na test-e-dokumenty | `vendor/bin/phpunit --testsuite live --filter JpkTeStUpo` po pass F5.2 | UPO przy `filing` JPK_ST | `junit.xml`: `JpkTeStUpo*`; `jpk/jpk-te-st-upo-*.xml` |
| `jpk-te-tls` (`live`): szyfry TLS | `vendor/bin/phpunit --testsuite live --filter JpkTeTls` | wynegocjowany zestaw z listy sześciu, TLS 1.2 albo 1.3 | `cmd/jpk-te-tls.txt` |
| `jpk-te-fixtures` (`live`): nagrania | `bin/console app:fixtures:record jpk <scenariusz>` dla `InitUploadSigned`, `FinishUpload` i `Status` | nagrania przez scrubber; skaner bez trafień; testy F5.1 przechodzą na nowych nagraniach | `cmd/jpk-te-fixtures.txt`: lista nagrań z sha256 i wynik skanera |

### F5.4

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `jpk-v7m-golden`: JPK_V7M(3) z fikcyjnego zbioru | `vendor/bin/phpunit --filter JpkV7mGolden`: miesiąc z rekordami `NrKSeF`, `BFK` i `DI` z `WEW`, sprzedażą np I, np II i 23%, importem usług z UE i spoza UE, fakturą w EUR | XML zgodny z XSD; K_11 zawiera K_12; K_27–K_30 i K_42–K_43; bajty równe złotemu plikowi | `junit.xml`: `JpkV7mGolden*`; `golden/jpk-v7m-golden.diff` pusty; `jpk/jpk-v7m-golden.xml` |
| `jpk-v7m-markers`: znaczniki | `vendor/bin/phpunit --filter JpkV7mMarkers`: każdy wiersz „Tabeli znaczników” | znacznik według pierwszego pasującego warunku; `online` bez numeru blokuje budowę | `junit.xml`: `JpkV7mMarkers*` |
| `jpk-v7m-declaration`: deklaracja | `vendor/bin/phpunit --filter JpkV7mDeclaration`: miesiąc zerowy, miesiąc z nadwyżką, pierwszy miesiąc z `opening.vat_carry_forward_pln`, miesiąc po złożeniu `manual` | „0” w P_38 i P_51 przy zerowej; P_53 i P_62 w pełnych złotych według Q-31; P_39 z P_62 poprzedniego złożenia albo z bilansu otwarcia | `junit.xml`: `JpkV7mDeclaration*` |
| `jpk-v7m-gates`: bramki kompletności | `vendor/bin/phpunit --filter JpkV7mGates`: koszt `imported`; sprzedaż `submitted`; brak kursu VAT; `eu_b2b` bez VIES; pierwszy miesiąc bez `opening.*` | każdy wariant blokuje prośbę z powodem; po usunięciu przyczyn `approval` `pending`, `period.checklist` zapisane, lista `DI` w `summary` | `junit.xml`: `JpkV7mGates*`; `db/jpk-v7m-gates-period.csv` |
| `jpk-v7m-send`: wysyłka i zamknięcie okresu | `vendor/bin/phpunit --filter JpkV7mSend` na `FakeJpkGateway` | `accepted` z UPO; okres `closed` z `closed_by_filing_id`; `vat_jpk_v7m` za M `done` | `junit.xml`: `JpkV7mSend*`; `db/jpk-v7m-send-period.csv` |
| `jpk-v7m-correction`: korekta okresu | `vendor/bin/phpunit --filter JpkV7mCorrection`: przyjęta KOR w zamkniętym M, ponowne otwarcie ze step-up | `CelZlozenia` 2, P_ORDZU, kolejny `seq`, `supersedes_id`; poprzednie złożenie `superseded`; okres `closed` po przyjęciu | `junit.xml`: `JpkV7mCorrection*`; `db/jpk-v7m-correction-filing.csv` |
| `jpk-replace-di`: `DI` → `NrKSeF` | `vendor/bin/phpunit --filter JpkReplaceDi`: faktura `offline24` z numerem nadanym przed i po `xml_sent` przyjętego JPK_V7M | po: `period.correction_required_at` i zadanie „korekta wymagana”; przed: bez zmian; drugi przebieg bez zmian | `junit.xml`: `JpkReplaceDi*` |
| `opening-balance-confirm`: bilans otwarcia | `vendor/bin/phpunit --filter OpeningBalanceConfirm`: WebTestCase, formularz „Otwarcie” bez i ze świeżym step-up; potem zwykły zapis `opening.vat_carry_forward_pln` | bez step-up odmowa; ze step-up klucze kroków 2–5 w jednej transakcji, dowody `evidence`, wpis `opening_balance.confirmed`; zwykły zapis odrzucony | `junit.xml`: `OpeningBalanceConfirm*`; `db/opening-balance-confirm-audit_log.csv` |
| `wht-obligation-amounts`: kwoty WHT | `vendor/bin/phpunit --filter WhtObligationAmounts`: koszt dostawcy z `wht_category` z certyfikatem i bez, zapłacony 10.11.2026 | `wht_payment` z `due_date` 7.12.2026 i kwotą według stawki z `setting`; `cit_10z` tylko przy sumie > 0; zero wysyłek | `junit.xml`: `WhtObligationAmounts*` |

### F5.5

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `vat-ue-golden`: VAT-UE(5) | `vendor/bin/phpunit --filter VatUeGolden`: dwóch nabywców z UE, KOR ze znakiem różnicy, faktura `eu_b2b_unidentified` | XML zgodny z XSD; P_Uc w pełnych złotych; `eu_b2b_unidentified` poza plikiem; bajty równe złotemu plikowi | `junit.xml`: `VatUeGolden*`; `golden/vat-ue-golden.diff` pusty |
| `vat-ue-k12-gate`: suma = K_12 | `vendor/bin/phpunit --filter VatUeK12Gate`: zgodna suma; różnica 0,01 zł; różnica po zaokrągleniu P_Uc w tolerancji | zgodna i w tolerancji → prośba; różnica niezaokrąglonych `net_pln` → odmowa z kwotą różnicy | `junit.xml`: `VatUeK12Gate*`; `cmd/vat-ue-k12-gate.txt`: suma `net_pln` i K_12 |
| `vat-ue-kor-routing`: KOR do `eu_b2b` | `vendor/bin/phpunit --filter VatUeKorRouting`: VAT-UE okresu w `draft`, `submitted`, `accepted` | przebudowa; czekanie do `accepted`; VAT-UEK z `supersedes_id` | `junit.xml`: `VatUeKorRouting*` |
| `edeklaracje-send-accepted`: wysyłka VAT-UE | `vendor/bin/phpunit --filter EDeklaracjeSendAccepted`: `FakeEDeklaracjeGateway`, `refId`, `requestUPO` 301, potem 200 | `accepted` z UPO; `xml_sent` bez `DaneAutoryzujace`; `payload_sha256` równy sha256 `xml_sent`; `request_sha256` w `connector_call` różny od `payload_sha256`; dokument z danymi zgodny z XSD | `junit.xml`: `EDeklaracjeSendAccepted*`; `db/edeklaracje-send-accepted-connector_call.csv` |
| `edeklaracje-status-codes`: statusy bramki | `vendor/bin/phpunit --filter EDeklaracjeStatusCodes`: 100, 300, 410, 411, 412, 418, 422 | 100 bez ponownej wysyłki; 300 i 411 → `unknown` i rekoncyliacja odczytem; 410, 412, 418 i 422 → `rejected` z `status_code`; push | `junit.xml`: `EDeklaracjeStatusCodes*` |
| `edeklaracje-rate-limit`: licznik `edeklaracje.send` | `vendor/bin/phpunit --filter EDeklaracjeRateLimit`: licznik wyczerpany | `approved` z `approval.dispatch_waiting`; zero wywołań `edeklaracje` | `junit.xml`: `EDeklaracjeRateLimit*` |

### F5.6

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `jpk-te-v7m-upo` (`live`): JPK_V7M na test-e-dokumenty | `vendor/bin/phpunit --testsuite live --filter JpkTeV7mUpo`: fikcyjny miesiąc z F5.4 | UPO przy `filing`; `accepted`; okres testowy `closed` | `junit.xml`: `JpkTeV7mUpo*`; `jpk/jpk-te-v7m-upo-*.xml` |
| `edeklaracje-te-vat-ue` (`live`): VAT-UE na bramce testowej | `vendor/bin/phpunit --testsuite live --filter EDeklaracjeTeVatUeTest` | UPO i `accepted`; brak 418; przy niedziałającej bramce test kończy się wynikiem „bramka niedostępna” i wpisem R-31 | `junit.xml`: `EDeklaracjeTeVatUe*`; `cmd/edeklaracje-te-vat-ue.txt` z odpowiedzią bramki |
| `edeklaracje-te-vat-uek` (`live`): VAT-UEK z danymi autoryzującymi | `vendor/bin/phpunit --testsuite live --filter EDeklaracjeTeVatUekTest` | UPO bez 410 i 418; inaczej `rejected` i nowy rekord D-xx zastępujący D-27 | `junit.xml`: `EDeklaracjeTeVatUek*`; `cmd/edeklaracje-te-vat-uek.txt` |
| `edeklaracje-te-fixtures`: nagrania | przebieg live zapisuje nagrania przez `Scrubber` i skaner do `var/live-recordings/EDeklaracje` (D-68); `vendor/bin/phpunit --filter EDeklaracjeLiveRecording` odtwarza `live-send`, `live-upo`, `live-uek-send`, `live-uek-upo` przez bramkę | VAT-UE i VAT-UEK `accepted` z UPO systemu testowego; skaner bez trafień; testy F5.5 przechodzą | `junit.xml`: `EDeklaracjeLiveRecording*` |

### Go-live w kryteriach

Wiersze `[manual]` wynikają z bramek D-30 i z pierwszych złożeń PROD, nie z testów.

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `jpk-ewp-prod-first` `[manual]`: JPK_EWP za 2026 w PROD | pierwsze złożenie przez bramkę na `kokpit-prod` z `auth_data` N−2, do 31.03.2027 | `accepted` z UPO; `submitted_via` = `jpk` | `manual/jpk-ewp-prod-first.txt`: sha256 `xml_sent` i UPO, numer referencyjny zamaskowany |
| `opening-balance-prod` `[manual]`: bilans otwarcia w PROD | formularz „Otwarcie” na `kokpit-prod` | wpis `opening_balance.confirmed`; dowody `evidence` | odhaczony wpis w `docs/evidence/f5.md` |
| `jpk-v7m-prod-first` `[manual]`: pierwszy JPK_V7M w PROD | po dwóch miesiącach zgodnych z obecnym narzędziem, przez bramkę | `accepted` z UPO; okres `closed` | `manual/jpk-v7m-prod-first.txt`: sha256 `xml_sent` i UPO |
| `vat-ue-prod-first` `[manual]`: pierwszy VAT-UE w PROD | razem z JPK_V7M za ten sam miesiąc | `accepted` z UPO; suma równa K_12 | `manual/vat-ue-prod-first.txt`: sha256 i suma bez numerów VAT UE |

**Kryteria przekrojowe:** wiersze od F0a do F3 w [../testing.md](../testing.md). `cc-17`, `cc-18` i `cc-21` od F5 obejmują też konektory `jpk` i `edeklaracje`: pokrywają je `jpk-send-hash-mismatch`, `jpk-send-once`, `jpk-send-reconcile` i `edeklaracje-status-codes`.

## Dowód fazy

**Dowód fazy:** `docs/evidence/f5.md`, jedna lista dla wszystkich części. Status F5 w [../README.md](../README.md) linkuje listę po odbiorze.

| Element | Treść |
| --- | --- |
| paczka | `bin/console kokpit:evidence f5 --only=ci` i `--only=live` → `var/evidence/f5/<YYYYMMDD-HHMM>/`; układ, `manifest.json` i wyniki `pass`, `fail`, `pending` według „Paczki dowodów” w [../testing.md](../testing.md); całość po `Redactor` |
| CI | job `evidence` uruchamia wiersze `ci` z `tests/Acceptance/f5.yaml`, ręczny job `live` wiersze `live`; oba wgrywają `var/evidence/**` jako artefakt przebiegu |
| lista | `docs/evidence/f5.md`, pisana ręcznie (D-28): data, commit, linki do przebiegów `evidence` i `live`, kryterium → pass albo fail → artefakt, odhaczone `[manual]`; UPO z bramek testowych i sha256 wysłanych bajtów |
| odbiór | wymagane joby zielone, udane przebiegi `live` F5.3 i F5.6, wszystkie wiersze pass, wiersze `[manual]` odhaczone przez Ciebie |

## Go-live

Sekwencja i wycofanie: wiersze „JPK_EWP(4), JPK_ST(1)”, „JPK_V7M(3)” i „VAT-UE” w „Go-live” w [../operations.md](../operations.md). Lista go-live nie należy do kryteriów odbioru, poza wierszami `[manual]` z „Go-live w kryteriach”.

| Krok | Wynik | Zapis |
| --- | --- | --- |
| test | wiersze F5.1–F5.3 z pass (F5a), potem F5.4–F5.6 (F5b) | `docs/evidence/f5.md` |
| poświadczenia PROD | `auth_data` z `revenue_n2` za 2025 w sejfie PROD; certyfikat MF PROD w konfiguracji celu | wpis `credential.registered` |
| F5a | `JPK_TARGET=prod` osobnym wdrożeniem; `jpk-ewp-prod-first` do 31.03.2027; JPK_ST przy `jpk_st.enabled` | sha256 UPO |
| bramka D-30 | JPK_EWP w PROD przyjęty | wiersz w liście dowodów |
| bilans otwarcia | `opening-balance-prod` | wpis `opening_balance.confirmed` |
| okres równoległy | dwa miesiące JPK_V7M z kokpitu bez wysyłki, zgodne z obecnym narzędziem | sumy i P_51, P_62 obu narzędzi |
| F5b | `jpk-v7m-prod-first` i `vat-ue-prod-first`; `EDEKLARACJE_TARGET=prod` osobnym wdrożeniem | sha256 UPO |
| stare narzędzie | wyłączone dla JPK_V7M i VAT-UE od tego miesiąca | wiersz w liście dowodów |

- Prawdziwe dane nie trafiają na środowiska testowe; na `test-e-dokumenty` i bramkę testową e-Deklaracji idą tylko dane fikcyjne.
- `JPK_TARGET=prod` i `EDEKLARACJE_TARGET=prod` ustawiasz Ty osobnym wdrożeniem.
- Wycofanie: ten sam XML z kokpitu przez Klient JPK WEB albo Formularz uproszczony; VAT-UE w e-Deklaracjach; UPO wgrywasz jako złożenie `manual`.

## Pierwszy prompt

```text
Przeczytaj docs/README.md i docs/phases/f5-jpk.md, potem tylko pliki z sekcji „Ładuj tylko” dla tej części.
Krok 0: sprawdź docs/naming.md, wiersze F5 w docs/decisions.md i fakty F5 w docs/facts.md.
Brak wejścia oznaczonego „stop” oznacza stop i pytanie; brak XSD JPK_ST(1) zatrzymuje tylko F5.2.
Zrealizuj jedną część z sekcji „Kolejność części”: pierwszą, której kryteria nie mają pass, a warunek startu jest spełniony.
F5.1, F5.2, F5.4 i F5.5 działają bez sieci: FakeJpkGateway, FakeEDeklaracjeGateway, nagrania pisane ręcznie z danymi fikcyjnymi.
Każde złożenie tylko przez bramkę i official_writes; dane autoryzujące tylko w pamięci workera.
Zanim zaczniesz kodować, wypisz plan i pytania. Na koniec uruchom testy i bin/console kokpit:evidence f5 --only=ci,
pokaż wynik kryteriów tej części i otwarte wiersze [manual]; po F5.6 także kryteria przekrojowe z docs/testing.md.
```
