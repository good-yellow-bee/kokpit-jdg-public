# Propozycje rozwoju (roadmap)

Pomysły na rozwój kokpitu po fazach z kolejności D-30 albo równolegle z nimi, w tym warunki powrotu elementów odłożonych w D-28 i D-29. Stan na 8 października 2026.

Plik stoi w tabeli „Jak czytać dokumentację” w [README.md](README.md) jako „nigdy w sesjach faz”. Niczego nie zmienia w zakresie faz. Propozycja staje się pracą dopiero przez nowy rekord Q-xx lub D-xx w [decisions.md](decisions.md) i plik fazy. Nowe identyfikatory w tabelach to propozycje do kontraktu nazw w [naming.md](naming.md). Elementy z sekcji „Odłożone” w [README.md](README.md) mają tu wiersze w sekcji „Odłożone z planu faz (D-29)”.

Skala: wartość dla właściciela H, M, L. Wysiłek jednej osoby po godzinach: S (do 2 wieczorów), M (do 2 tygodni), L (dłużej). „Wymaga” podaje fazę albo pytanie Q-xx lub decyzję D-xx. „Do weryfikacji” oznacza fakt bez potwierdzenia w źródle pierwotnym.

## Priorytety po F6

1. Zamknięcie roku i PIT-28 z kokpitu: twardy termin 30.04.2027, bez biura; dane z F2 i konektor `edeklaracje` z F5 są wtedy gotowe.
2. Stripe: opłaty i wypłaty: zamyka luki Q-24 (`fee_*`, `payout_*`, `actual_rate`) i comiesięczny dokument DI/WEW ręcznie liczony dziś z raportu.
3. Automatyczna obserwacja prawa: zrobiona dla aktów w ELI i procesów Sejmu (D-75, [modules/obserwacja-prawa.md](modules/obserwacja-prawa.md)). Projekty RCL, komunikaty MF i changelog ksef-docs zostają ręczne.
4. Import wyciągu bankowego i dopasowanie płatności: różnice kursowe, zamykanie obowiązków po wpłacie, podzielona płatność; zero ruchu pieniędzy.
5. Prognoza progów i rezerwa podatkowa: zrobiona na panelu „Podatki” (D-76): run-rate wobec limitów w euro i progów zdrowotnej oraz kwota do odłożenia. Cash-flow z należnościami: strona `/cashflow` (D-88).

## Odłożone z planu faz (D-29)

Wiersze obejmują sekcję „Odłożone” w [README.md](README.md) oraz cięcia z D-28 i D-29. Powrót wymaga nowego rekordu D-xx w [decisions.md](decisions.md); szkic kryteriów powstaje wtedy z pliku modułu. Nazwy odłożone i wycofane są w [naming.md](naming.md).

| Element | Co wraca | Do tego czasu | Warunek powrotu | Wymaga | Najwcześniej | Decyzja |
| --- | --- | --- | --- | --- | --- | --- |
| F7 basal | lokalny klasyfikator w trybie cienia ([modules/basal.md](modules/basal.md)), start od kategorii faktur kosztowych. Po kalibracji progu automat, potem „czy pismo wymaga odpowiedzi”; raport trafności przed każdą zmianą (R-09); router effortu wycięty | decyzje klasyfikacji podejmujesz w panelu | publiczny zbiór ewaluacyjny dla pytania albo co najmniej 200 Twoich etykiet na pytanie (pisma albo faktury kosztowe). Panel nie ma dziś pola etykiety (jest tylko `invoice.cost_review`), więc etykiety wymagają najpierw decyzji o takim polu albo trybu cienia po powrocie | host z 8 GB RAM poza wąskim VPS (Q-14, D-28), Q-37, nowy D-xx | po F6; realnie 2028 | D-29 |
| F4 e-Doręczenia API (INT i PROD) | lista i odbiór pism przez API, `edelivery_deemed` z API; potem wysyłka odpowiedzi i streszczenie pisma (wiersze w „Propozycjach”) | powiadomienia mailowe, odbiór ręczny w aplikacji e-Doręczeń, `manual_channel_check`, termin z pisma wpisany ręcznie jako `edelivery_reply_due` | bramki wykonalności 1–2 z pozytywną odpowiedzią (Q-30) i zaakceptowany stały koszt certyfikatu kwalifikowanego (D-09). Potem bramki 3–4 z [modules/e-doreczenia.md](modules/e-doreczenia.md), wniosek INT ok. 2 tygodnie przed startem i stały IPv4 (D-18) | Q-30, nowy D-xx | po F5 | D-29 |
| strumieniowanie odpowiedzi czatu | worker ze streamingiem API i hub zdarzeń do panelu | odpowiedź w synchronicznym żądaniu z ramką Turbo | odpowiedzi czatu trwają ponad 10 s | F6, nowe nazwy w [naming.md](naming.md), nowy D-xx | po F6 | D-29 |
| eskalacja czatu do Sonnet 5.5 | przejście na Sonnet 5.5 w tej samej rozmowie. Sonnet 5.5 czyta bloki thinking Haiku 5.5, więc rozumowanie zostaje; kierunek tylko w górę, bo bloków Sonnet 5.5 nie czyta inny model. Wymuszony `tool_choice` daje 400; domyślny effort `high`, więc effort jawnie ([przewodnik migracji Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/migration-guide)). $2 / $10 za MTok ([cennik](https://platform.claude.com/docs/en/about-claude/pricing), [facts.md](facts.md)) | tylko Claude Haiku 5.5 | zestaw ewaluacyjny czatu (tabela „Warstwa AI”) pokazuje pytania, na których Haiku 5.5 nie trafia | zestaw ewaluacyjny, nowy D-xx | po zestawie ewaluacyjnym | D-10, D-29 |
| okna przesuwne limitów | okna przesuwne per grupa limitów obok `rate_counter` | `rate_counter` z `blocked_until` i `Retry-After` | pierwsze odpowiedzi 429 w `connector_call` | nowy D-xx | po F1 | D-29 |
| rejestry KRS, CEIDG, GUS | konektory KRS, CEIDG i GUS; klucz BIR i JWT CEIDG | VIES, biała lista, NBP; kontrahent spoza UE z `manual_evidence` | stali kontrahenci z Polski będący firmami, zwłaszcza gdy biała lista nie rozstrzyga statusu albo potrzeba danych z REGON | Q-20, nowy D-xx | po F1 | D-20, D-29 |
| PWA i Web Push | manifest, service worker z cache widoków tylko do odczytu, skróty, zapis tylko online, zero sekretów w cache. Web Push na iOS 16.4+ tylko dla aplikacji z ekranu głównego, subskrypcja po geście ([WebKit](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/)). Treść jak D-19; hosty push Apple, Google i Mozilla to nowe wyjścia w [architecture.md](architecture.md). Ryzyko: CSP, unieważnianie cache, drugi kanał push | push przez aplikację ntfy; panel w przeglądarce przez VPN | ntfy nie wystarcza: zawodzi przez VPN na iOS albo potrzebny ekran startowy z terminami | Q-15, D-19, nowy D-xx | po F0b | D-08, D-28 |
| komenda demo fazy i generator podsumowania dowodów | odtworzenie szczęśliwej ścieżki fazy i szkic `docs/evidence/<phase>.md` z artefaktów; dawne nazwy są wycofane w [naming.md](naming.md) | raport JUnit z CI, artefakty przebiegu i ręcznie pisana lista `docs/evidence/<phase>.md` | ręczne zebranie dowodów zajmuje ponad 1 h na fazę | nowy D-xx | po pierwszej liście dowodów (F0a) | D-28 |

## Propozycje

### Zgodność po F6

| Propozycja | Co i po co | Wartość | Wysiłek | Ryzyko | Wymaga | Najwcześniej |
| --- | --- | --- | --- | --- | --- | --- |
| PIT-28 przez e-Deklaracje | XML PIT-28(25) z ewidencji i odliczeń, XSD, dane autoryzujące przez `edeklaracje`, bramka zatwierdzeń. Wzór ma gwiazdkę ([struktury e-Deklaracji](https://podatki-arch.mf.gov.pl/e-deklaracje/dokumentacja-it/struktury-dokumentow-xml/)). Najpierw bramka testowa (R-31) | H | M | M: błędne odliczenia; korekta w e-US | F5, nowy D-xx zmieniający „PIT-28 ręcznie” w D-03 | okno 15.02–30.04.2027 tylko przy F5 w PROD (D-30); realnie 2028 |
| Kreator zamknięcia roku | Zrobione (D-86, strona `/year-close` i ZIP roku). Jedna lista z datami: N−2 (2.01), CIT-10Z (31.01), IFT-2R (31.03), JPK_EWP, JPK_ST i PIT-28 (30.04), zdrowotna (20.05), parametry ZUS; zestawienie roczne i ZIP roku z UPO | H | S | L | F5; obowiązki roczne są od F0b | po F5; rok 2026 zamykasz ręcznie według „Teraz – zgodność” w [README.md](README.md) |
| Korekty półautomatyczne | Zadanie „korekta wymagana” ([modules/jpk.md](modules/jpk.md)) buduje szkic korekty JPK_V7M albo VAT-UEK z różnicą wobec przyjętego pliku i P_ORDZU; bramka | M | M | M: okres korekty (Q-29); kod 410 dla VAT-UEK (R-30) | F5, przegląd doradcy | po F5 |
| Jeden podpis kwalifikowany | Decyzja zakupu QES: wniosek o certyfikat KSeF typ 1 i 2 ([MF](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/)), IFT-2R i CIT-10Z, zapas przy kodzie 418 (R-30). XAdES w kodzie to osobna praca (M) | M | S zakup, M XAdES | M: koszt roczny; PZ wystarcza do wniosku o certyfikat | Q-21, Q-29, nowy D-xx | przed 1.12.2026, jeśli § 13 nie zmieni się (R-01); inaczej 2027 |
| IFT-2R i CIT-10Z z kokpitu | XML z kwot `wht_payment`, XSD, bramka. Wzory bez gwiazdki, więc podpis kwalifikowany; FAQ wymienia tylko QES i dane autoryzujące „głównie PIT” ([FAQ](https://podatki.gov.pl/e-deklaracje-zloz-deklaracje-podatkowa-online/pytania-i-odpowiedzi)); do weryfikacji. Bez QES tylko eksport XML | L | M | M: klasyfikacja WHT (Q-21) | F5, QES z wiersza wyżej, Q-21 | pełny cykl: styczeń–marzec 2028 |
| KSeF offline24 z certyfikatem typ 2 | KOD II, stan `offline_issued`, terminy dosłania w `obligation` przez `PolishCalendar`. Tylko gdy awarie z Latarni blokują wysyłkę albo klient PL potrzebuje faktury tego dnia | L | M | M: dwa terminy dosłania; kary od 2027 (R-10) | Q-28, nowy D-xx zastępujący D-15; certyfikat typ 2 ważny do 2 lat | po go-live F3, po pierwszym incydencie |
| Wysyłka odpowiedzi e-Doręczeń | `edelivery_outbound` ze szkicu `edelivery_reply`, `official_writes`; mapowanie stanów jest w [modules/e-doreczenia.md](modules/e-doreczenia.md); nazwy odłożone (D-29) | M | M | M: odbiorca tylko z bazy (D-22) | powrót F4 PROD („Odłożone z planu faz”), Q-35 | po powrocie F4 PROD |
| Obserwacja prawa automatyczna | Zrobione w części ELI i procesów Sejmu (D-75); zostają druki z Sejm process API spoza listy i changelog ksef-docs. Zadanie tygodniowe: ELI `/changes/acts?since=` i `/acts/.../references` dla aktów z tabeli „Akty prawne” w [facts.md](facts.md), Sejm process API dla druków, changelog ksef-docs. Wynik: push i szkic wiersza do [facts.md](facts.md) | H | M | L: szum (877 zmian od 1.10.2026); filtr po liście ELI i słowach | F0b; host `api.sejm.gov.pl` tylko `GET` w allowliście | po F0b, równolegle z F1 |

- ELI API: `/changes/acts` ma `since`, `limit` do 500; `/acts/search` filtruje po datach ogłoszenia, wejścia w życie i publikacji ([OpenAPI](https://api.sejm.gov.pl/eli/openapi/)). Klucz „Akty zmieniające” w `/references` aktu bazowego: do weryfikacji (odpowiedź obcięta).
- PIT-28 tańsza ścieżka: kreator Twój e-PIT obsługuje działalność; przychody i ryczałt wpisujesz sam, wpłaty są wstępnie wypełnione ([MF, 10.04.2024](https://www.gov.pl/web/finanse/twoj-e-pit-dla-przedsiebiorcow)). Patrz „Szybkie wygrane”.

### Integracje

| Propozycja | Co i po co | Wartość | Wysiłek | Ryzyko | Wymaga | Najwcześniej |
| --- | --- | --- | --- | --- | --- | --- |
| Import wyciągu bankowego | Ręczny upload CSV albo MT940 → `bank_statement_line` → dopasowanie do `invoice` i `payment` (klienci PL) oraz do `obligation` po tytule i kwocie (`done`). Wykrywa podzieloną płatność. Tylko odczyt | H | M | L: błędne dopasowanie; zawsze podgląd | F2.2/F2.3 (`payment`), Q-24; format pliku per bank do weryfikacji | po F2.2/F2.3 |
| PSD2 AIS przez licencjonowanego AISP | Np. GoCardless Bank Account Data: AISP w EOG, do 90 dni ciągłego dostępu, limity banków do 4 wywołań dziennie, cennik niepodany ([docs](https://docs.gocardless.com/bank-account-data/overview)). Rozporządzenie 2022/2360 dopuszcza odnowienie SCA co 180 dni ([EUR-Lex](https://eur-lex.europa.eu/eli/reg_del/2022/2360/oj)) | M | M | H: nowy procesor danych bankowych (art. 28 RODO), odnawianie zgody; pokrycie PL do weryfikacji | import CSV wyżej, nowy D-xx, umowa powierzenia | 2027 H2, tylko gdy CSV nie wystarcza |
| Wise i Revolut | Wise: token osobisty nie daje wyciągów poza US, CA, AU, NZ, SG, MY ([Wise](https://docs.wise.com/guides/developer/auth-and-security/personal-api-token)), więc konto w UE zostaje na CSV. Revolut Business API tylko w planach Grow, Scale, Enterprise ([Revolut](https://help.revolut.com/business/help/integrating-with-external-apps/revolut-business-api/question-using-revolut-business-api/)) | L | S CSV, M API | M: koszt planu Revolut; nowe hosty | Q-24 (waluta wypłat), import CSV | po imporcie CSV |
| Stripe: opłaty i wypłaty | Klucz tylko do odczytu w `SecretStore`, host `api.stripe.com`. `balance_transactions` ma `fee`, `fee_details`, `net`, `exchange_rate`, typ `payout` ([Stripe](https://docs.stripe.com/api/balance_transactions/object)) → `payment.fee_*`, `payout_*`, `actual_rate`; miesięczny dokument DI/WEW opłat do JPK_V7M | H | M | M: VAT od opłat Stripe (Q-21, Q-29) | F2.2/F2.3 (`payment`), Q-21, Q-24; dokument JPK po F5 | po F2.2/F2.3 |
| Konektor poczty przychodzącej | Konektor zrobiony (D-92): IMAPS tylko do odczytu na dedykowanej skrzynce, załączniki PDF, JPEG i PNG do `stored_file` `cost_document`, strona `/mail-in`, licznik `mail_in.fetch`; koszt (`invoice.source` = `email`, stan `imported`) wpisujesz ręcznie. Zostaje tekst lokalnie: smalot/pdfparser (LGPL-3.0, [Packagist](https://repo.packagist.org/p2/smalot/pdfparser.json)), Tesseract dla skanów (Apache-2.0, [GitHub](https://github.com/tesseract-ocr/tesseract)); pola proponuje reguła per dostawca, zatwierdzasz Ty | H | M | M: złośliwe załączniki (bez wykonywania, bez linków); model dostaje tekst tylko po `Redactor` i po zmianie D-22 | F1 (koszty spoza KSeF), D-92 | po F1 |
| Powiadomienia systemowe z poczty | Ten sam konektor rozpoznaje maile e-US, eZUS i e-Doręczeń po nadawcy i temacie i tworzy obowiązek ad hoc „sprawdź kanał”. Mail nie jest treścią pisma, więc nie ma odbioru. e-US wysyła SMS i e-mail po zgodzie, fikcja po 14 dniach ([MF, 22.12.2023](https://www.gov.pl/web/finanse/juz-14-mln-osob-korzysta-z-e-korespondencji)); eZUS do weryfikacji | H (R-11); rośnie po odłożeniu F4 (D-29) | S | L: fałszywe maile KAS; kokpit tylko sygnalizuje, nigdy nie otwiera linku | konektor poczty, Q-13 | po konektorze poczty |
| Eksport ICS obowiązków | Zrobione (D-77). Kanał iCalendar ([RFC 5545](https://datatracker.ietf.org/doc/html/rfc5545)) z `obligation` pod adresem z tokenem, tylko w VPN; tytuły bez kwot (D-19) | M | S | L | F0b | po F0b |

### Produkt i UX

| Propozycja | Co i po co | Wartość | Wysiłek | Ryzyko | Wymaga | Najwcześniej |
| --- | --- | --- | --- | --- | --- | --- |
| Prognoza progów | Zrobione (D-76, [tax-rules.md](tax-rules.md), „Prognoza progów i rezerwa”). Run-rate przychodu do końca roku wobec 250 tys. EUR (UD458, Q-38), 200 tys. EUR (zaliczki kwartalne, Q-17) i progów zdrowotnej 60 i 300 tys. zł; kurs z pierwszego dnia roboczego października. Rozszerza metrykę z [tax-rules.md](tax-rules.md) | H | S | L: prognoza, nie porada; etykieta w panelu | F2.1 (`revenue_entry`) | po F2.1 |
| Cash-flow i rezerwa podatkowa | Zrobione (D-88, strona `/cashflow`; rezerwa na panelu „Podatki”, D-76); saldo z wyciągu czeka na import. Otwarte `obligation` z kwotami na 90 dni (zaliczka, P_51, ZUS, WHT) wobec należności z `payment` i FreshBooks; „do odłożenia dziś”. Saldo z wyciągu, gdy jest import | H | M | L | F0b (kwoty), F2.2/F2.3 (`payment`), F5 (VAT) | po F2.2/F2.3, pełne po F5 |
| Raporty rok do roku | Strona panelu i eksport CSV zrobione (D-87): przychód, VAT, koszty, kursy per miesiąc roku wobec poprzedniego. Zostaje nowe `query` dla `render_chart` (nowy `tool_set`) razem z „Nowe narzędzia czatu” | M | S | L | F2.1 i drugi rok danych | wykres po F6 z zestawem ewaluacyjnym |
| Kreator pierwszego uruchomienia | Zrobione (D-91, strona `/setup`: stan kroków z linkami do formularzy i test push z e-mailem; zapis w istniejących formularzach). Jeden przebieg: `company.identity`, mikrorachunek, rachunek ZUS, wariant ZUS, `ryczalt.service_map`, rejestracja poświadczeń, test push. Zastępuje ręczne wpisy `setting`; wspólny z instalatorem planów płatnych | M | M | L | F2.1 (tabela `setting`, [data-model.md](data-model.md)), F0b (klucze kalendarza), F1 (poświadczenia) | po F1 |
| Rola zastępcy | Drugi `app_user` z passkeyem: podgląd terminów, oznaczanie wpłat, bez zatwierdzeń. Pisma e-Doręczeń odbiera w aplikacji tylko osoba upoważniona imiennie (art. 19 ust. 6–7 u.d.e.); dziś robi to administrator skrzynki ADE (Q-18) | M (R-25) | M | M: zmiana założenia „jeden użytkownik”; UPL-1 | Q-18, D-03, F0b (drugi passkey, D-28) | po F0b |

### Warstwa AI

| Propozycja | Co i po co | Wartość | Wysiłek | Ryzyko | Wymaga | Najwcześniej |
| --- | --- | --- | --- | --- | --- | --- |
| Streszczenie pisma po odbiorze | Po `receive()` zadanie `letter_analysis` daje streszczenie maskowanej treści, terminy z cytatem zdania i propozycję `reply_due_date`; potwierdzasz Ty. Dziś analiza tylko na pytanie w czacie | H | S | M: zakres treści (Q-34); wstrzyknięcia (R-38), wynik to tylko tekst i data | powrót F4 PROD („Odłożone z planu faz”), F6, Q-34 | po powrocie F4 PROD i po F6 |
| Nowe narzędzia czatu | `get_period_summary`, `get_cashflow` i `compare_periods` zrobione (D-95, widoki `chat_filing` i `chat_payment`); zostaje `explain_filing`. Pierwotnie: `get_period_summary` (K_8, K_12, P_51, zaliczka), `get_cashflow`, `explain_filing` (pole pliku → wiersze źródłowe), `compare_periods`; każde jako nazwane zapytanie na `chat_ro`, nowy `tool_set` | M | S na narzędzie | L | F5, F6 | po F6 |
| Zestaw ewaluacyjny czatu | 50–100 pytań z oczekiwanymi wywołaniami narzędzi i liczbami (historia `chat_message`, złote przypadki z [testing.md](testing.md)); uruchamiany przy zmianie `chat.model`, `chat.effort`, promptu i `tool_set`; raport jako artefakt przebiegu CI (D-28) | H (R-41) | M | L: koszt; Batch API daje 50% zniżki ([cennik](https://platform.claude.com/docs/en/about-claude/pricing)) | F6 | po F6, przed pierwszą zmianą modelu |
| Koszt czatu: cache i uzgodnienie | `cache_control` na stałym prefiksie (system, narzędzia, snapshot prawa): odczyt z cache to 0,1× ceny wejścia Haiku 5.5. Miesięczne porównanie sum `cost_usd` z Cost API; Admin API tylko dla konta organizacji, nie indywidualnego ([Usage and Cost API](https://platform.claude.com/docs/en/manage-claude/usage-cost-api)) | M | S | L | F6 | cache prefiksu w F6, cache historii rozmowy D-94; uzgodnienie po F6 |
| Tryb bez chmury | Ten sam tool runner na lokalnym modelu przez llama.cpp, np. Bielik-11B-v3.0-Instruct (Apache-2.0, kwantyzacje dostępne, [HF](https://huggingface.co/speakleash/Bielik-11B-v3.0-Instruct)); te same narzędzia i `Redactor`. Jakość oceniona zestawem ewaluacyjnym | M (Q-33 bez transferu do USA) | L | M: jakość; VPS ma 2 GB (D-28), więc inny host (Q-14) | F6, zestaw ewaluacyjny, Q-14 | 2028 |

### Ops, bezpieczeństwo, jakość

| Propozycja | Co i po co | Wartość | Wysiłek | Ryzyko | Wymaga | Najwcześniej |
| --- | --- | --- | --- | --- | --- | --- |
| Klucz sprzętowy do step-upu | Rejestracja passkeya z `authenticatorAttachment` = `cross-platform` filtruje do kluczy FIDO2 ([WebAuthn L3](https://www.w3.org/TR/webauthn-3/)): rejestracja klucza jako drugiego passkeya zrobiona (D-90); zostaje wymóg klucza do zatwierdzeń przy logowaniu telefonem | M | S | L: utrata klucza → `app:passkey:reset` przez SSH | F0b (`StepUpVerifier` i drugi passkey, D-28) | po F0b |
| Sandbox Claude Code | Sandbox systemowy poleceń powłoki z pustą listą domen plus hosty testowe; uzupełnia deny w `.claude/settings.json`, ochroną zostaje `HostAllowlist` ([security.md](security.md)) | M | S | L | F0a | przed F1 |
| SBOM i skan zależności | `composer audit --locked` w jobie `lint` zrobiony (D-77); SBOM CycloneDX jako artefakt `sbom` zrobiony (D-78, własny `tools/sbom.php` zamiast wtyczki `CycloneDX:make-sbom`, także pakiety JS z importmap); zostaje Dependabot dla Composer w repo prywatnym ([GitHub](https://docs.github.com/en/code-security/dependabot/ecosystems-supported-by-dependabot/supported-ecosystems-and-repositories)) | M | S | L: plan GitHub (Q-43) | F0a (CI) | po F0a |
| Test odtworzenia jednym poleceniem | Zrobione (D-89): `scripts/restore-test.sh` stawia jednorazowy projekt lokalny (D-28) z ostatniego snapshotu, `app:ops:restore-check` sprawdza pliki, łańcuch z kotwicami i liczby z PROD, raport JSON w `var/evidence/restore/` (poza repo), `app:ops:record-restore` zapisuje `ops.restored`. Ręcznie zostaje uruchomienie, czytanie raportu i dowód `cc-14` | H (R-26) | M | L: bez sejfu PROD na laptopie; `CONNECTORS_ENABLED=0` | F0a, pierwszy test ręczny | po F0a |
| Kotwica łańcucha `audit_log` | Zrobione (D-79): codzienny hash głowy łańcucha w tagu snapshotu restic `audit-head:`, sprawdzany przez `app:audit:verify --anchor`; wykrywa przepisanie historii na serwerze | M | S | L | F0a | po F0a |
| Panel zdrowia konektorów | Zrobione (D-85, strona `/connectors`). Wykresy z `connector_call` i `rate_counter`: błędy, 429, czas odpowiedzi, `blocked_until`, wiek `sync_cursor`; zamiast grep po logach | M | S | L | F1 | po F1 |
| OpenTelemetry | SDK PHP stabilny dla śladów, metryk i logów ([OTel](https://opentelemetry.io/docs/languages/php/)); lokalny kolektor na VPS; instrumentacja Symfony do weryfikacji | L: jeden użytkownik, `/health` wystarcza | M | L | F0a | tylko przy problemach z workerami |
| Gotowość na CRA | Tylko gdy obraz trafi do innych: SBOM wyżej, polityka zgłaszania podatności, kanał poprawek. Zgłoszenia od 11.09.2026, pełne obowiązki od 11.12.2027 ([KE](https://digital-strategy.ec.europa.eu/en/policies/cyber-resilience-act)) | L dziś | M | L | zmiana D-25 | przy decyzji o dystrybucji |

### Ścieżki do planów płatnych

Tylko tanie wzmocnienia z listy „Nie blokować później” w notatkach o planach płatnych (poza repo). Bez `tenant_id`, licencji, rozliczeń i publicznej rejestracji (D-25).

| Propozycja | Co i po co | Wartość | Wysiłek | Ryzyko | Wymaga | Najwcześniej |
| --- | --- | --- | --- | --- | --- | --- |
| Instalator | Kreator pierwszego uruchomienia (sekcja „Produkt i UX”) generuje klucz sejfu u klienta i prowadzi przez poświadczenia; dla Ciebie skraca odtworzenie na nowym hoście | M | M | L | kreator, F1 | po F1 |
| Paczka diagnostyczna | Zrobione (D-80). `kokpit:diagnostics`: wersje, `/health`, `connector_call` z 7 dni, logi po `Redactor`; dla własnego debugowania po tygodniach i dla wsparcia bez zdalnego dostępu | M | S | L: skaner sekretów na paczce | F0a | po F0a |
| Podpisane obrazy i kanał aktualizacji | Zrobione (D-93): job `images` publikuje obrazy commitów `main` w GHCR z podpisem cosign bez klucza, `make deploy` i `make rollback` sprawdzają podpis, `make update-check` pokazuje nową wersję; zmniejsza ryzyko podmiany obrazu także przy jednym wdrożeniu | M | S | L | F0a | po F0a |
| Publiczny HTTPS za passkeyem | Opcjonalny profil compose: proxy z publicznym portem, limit prób logowania, bez VPN. Dla Ciebie wyłączony | L | M | H: ekspozycja panelu | zmiana D-18, D-25 | tylko przy planie B |

## Szybkie wygrane (do 1 dnia każda)

- Eksport ICS obowiązków z tabeli integracji: zrobione (D-77).
- Liczby pod kreator Twój e-PIT: przychód i ryczałt per miesiąc i stawka, do przepisania; wpłaty kreator ma z urzędu.
- Przycisk kopiowania danych przelewu przy „Sprawdź przed przelewem”: rachunek, kwota, tytuł z numerem KSeF od 1.01.2027: zrobione (D-83, strona `/costs`).
- `composer audit --locked` w jobie `lint` (zrobione, D-77) i SBOM jako artefakt (zrobione, D-78).
- Sandbox Claude Code z pustą listą domen przed F1.
- Kotwica hasha `audit_log` w tagu snapshotu restic: zrobione (D-79).
- `cache_control` na stałym prefiksie czatu od pierwszej wersji F6: zrobione w F6 (`ChatRequestProfile`). Drugi punkt cache na rosnącej historii rozmowy: zrobione (D-94).
- Paczka diagnostyczna `kokpit:diagnostics`: zrobione (D-80).
- Klucz FIDO2 jako drugi passkey: zrobione (D-90).
- Widżet „najbliższe 3 terminy” na stronie startowej panelu: zrobione (D-81).
- Poniedziałkowy przegląd mailem: liczby otwartych obowiązków, zatwierdzeń i poświadczeń `expiring`, bez kwot (D-19): zrobione (D-82).
- Eksport CSV każdej listy panelu: ewidencja, obowiązki, faktury sprzedaży i złożenia zrobione (D-78), koszty zrobione (D-83), płatności razem z ich listą `/payments` zrobione (D-84).

## Świadomie odrzucone

| Pomysł | Dlaczego nie | Źródło |
| --- | --- | --- |
| SaaS multi-tenant, `tenant_id`, RLS, KMS, rozliczenia, licencje, UI ról, publiczna rejestracja | odłożone; wraca tylko z decyzją o planie B lub C | D-25, notatki o planach płatnych poza repo |
| Scraping e-US, eZUS i aplikacji e-Doręczeń | poza zakresem; kruche i wbrew regulaminom | [README.md](README.md) |
| Automatyczny odbiór pism i reguły przekazywania | udostępnienie treści to odbiór (art. 41 ust. 2 u.d.e.); upoważnienia tylko imienne | R-02, [modules/e-doreczenia.md](modules/e-doreczenia.md) |
| Automatyczne wysyłki i ponowienia do urzędu | zasada twarda; każdy zapis przez bramkę | [../.claude/rules/zasady-twarde.md](../.claude/rules/zasady-twarde.md), R-20 |
| Automatyczne przelewy (PIS, pliki przelewów bez Ciebie) | pieniądze zawsze ręcznie; kokpit przygotowuje tylko dane | [compliance-calendar.md](compliance-calendar.md) |
| Własny status AISP albo bezpośrednie API banków | wymaga wpisu w KNF według ustawy o usługach płatniczych ([t.j. Dz.U. 2026 poz. 623](https://api.sejm.gov.pl/eli/acts/DU/2026/623)); artykuł do weryfikacji; pośrednik albo CSV | tabela integracji |
| Wise przez OAuth partnera | ścieżka dla partnerów, nie dla własnego konta; CSV wystarcza | [Wise](https://docs.wise.com/guides/developer/auth-and-security/personal-api-token) |
| Managed Agents | sesje po stronie serwera, bez ZDR, zbędny sandbox | D-11 |
| Region UE Claude przez Bedrock | brak effort per wiadomość, dopłata 10%; tylko po zmianie Q-33 | D-22, [facts.md](facts.md) |
| OCR faktur i pism przez Files API albo wizję modelu | do modelu tylko maskowana treść z limitem długości, bez plików i obrazów ([modules/czat.md](modules/czat.md) „Dane do modelu”); OCR lokalnie | D-22 |
| B2C, OSS, KPiR i skala „na zapas” | poza zakresem; forma na 2027 to Q-38, nie kod na zapas | D-05, Q-38 |
| Aplikacja natywna | panel w przeglądarce wystarcza; wraca tylko przy skanowaniu aparatem | D-08, D-28 |
| Telegram jako kanał push | treść powiadomień u obcego operatora; ntfy jedynym kanałem | D-19, D-28 |
| Tabela `opening_balance` | zastąpiona kluczami `setting` `opening.*` i plikami dowodów | D-29 |
| mPDF i inne zależności tylko-GPL | lista dozwolonych licencji w CI | [architecture.md](architecture.md) |
| Format eksportu dla biura rachunkowego | biura nie ma; składasz sam | D-03 |
| Fine-tuning basal albo własny model | kalibracja progu wystarcza; danych za mało | [modules/basal.md](modules/basal.md) |
| Kubernetes, Helm, pełny stos Grafana | jedna osoba; compose, `/health` i dead-man wystarczą | [operations.md](operations.md) |

## Źródła

Sprawdzone 8.10.2026, chyba że zaznaczono inaczej. Źródła prawa i API użyte w fazach są w [facts.md](facts.md).

- e-Deklaracje: [struktury dokumentów XML](https://podatki-arch.mf.gov.pl/e-deklaracje/dokumentacja-it/struktury-dokumentow-xml/) (gwiazdka przy PIT-28(25); brak przy IFT-2R i CIT-10Z), [pytania i odpowiedzi](https://podatki.gov.pl/e-deklaracje-zloz-deklaracje-podatkowa-online/pytania-i-odpowiedzi).
- MF: [Twój e-PIT dla przedsiębiorców, 10.04.2024](https://www.gov.pl/web/finanse/twoj-e-pit-dla-przedsiebiorcow), [e-Korespondencja, 22.12.2023](https://www.gov.pl/web/finanse/juz-14-mln-osob-korzysta-z-e-korespondencji), [certyfikaty KSeF](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/).
- ZUS: [zmiana nazwy nadawcy SMS-ów](https://www.zus.pl/-/zmiana-nazwy-nadawcy-sms-ow-z-zus); treść strony nieodczytana automatycznie, powiadomienia eZUS do weryfikacji.
- Sejm ELI: [OpenAPI](https://api.sejm.gov.pl/eli/openapi/), [zmiany od 1.10.2026](https://api.sejm.gov.pl/eli/changes/acts?since=2026-10-01T00:00:00&limit=5), [ustawa o usługach płatniczych, t.j.](https://api.sejm.gov.pl/eli/acts/DU/2026/623).
- UE: [rozporządzenie delegowane 2022/2360](https://eur-lex.europa.eu/eli/reg_del/2022/2360/oj), [Cyber Resilience Act](https://digital-strategy.ec.europa.eu/en/policies/cyber-resilience-act).
- Banki i płatności: [GoCardless Bank Account Data](https://docs.gocardless.com/bank-account-data/overview), [Wise, tokeny osobiste](https://docs.wise.com/guides/developer/auth-and-security/personal-api-token), [Revolut Business API](https://help.revolut.com/business/help/integrating-with-external-apps/revolut-business-api/question-using-revolut-business-api/), [Stripe, balance transactions](https://docs.stripe.com/api/balance_transactions/object).
- Narzędzia: [composer audit](https://getcomposer.org/doc/03-cli.md), [cyclonedx-php-composer](https://github.com/CycloneDX/cyclonedx-php-composer), [Dependabot, ekosystemy](https://docs.github.com/en/code-security/dependabot/ecosystems-supported-by-dependabot/supported-ecosystems-and-repositories), [smalot/pdfparser](https://repo.packagist.org/p2/smalot/pdfparser.json), [Tesseract](https://github.com/tesseract-ocr/tesseract), [OpenTelemetry PHP](https://opentelemetry.io/docs/languages/php/), [RFC 5545](https://datatracker.ietf.org/doc/html/rfc5545).
- Web: [WebAuthn Level 3](https://www.w3.org/TR/webauthn-3/), [WebKit, Web Push na iOS 16.4](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/).
- Modele: [cennik Claude](https://platform.claude.com/docs/en/about-claude/pricing), [przewodnik migracji Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/migration-guide), [Usage and Cost API](https://platform.claude.com/docs/en/manage-claude/usage-cost-api), [Bielik-11B-v3.0-Instruct](https://huggingface.co/speakleash/Bielik-11B-v3.0-Instruct).
