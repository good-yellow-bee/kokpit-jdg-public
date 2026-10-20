# Reguły podatkowe

Reguła kursu walut, stawki ryczałtu, zaliczka, ZUS, import usług, WHT, płatności i zakres B2B. Stan na 8 października 2026.

## Jak czytać

| Faza | Sekcje |
| --- | --- |
| F1 | „Kursy walut”, „Koszty: import usług i dokumenty spoza KSeF” (z ręczną `tax_point_date`), „Podatek u źródła” (pola dostawcy, podstawa terminu `paid_on`, osoba fizyczna) |
| F2.1 | „Data przychodu według źródła” (wiersz `import`) i tabela „Dopasowanie pozycji do wpisu” (wiersz `import`): K_3, K_8 i K_9 ze źródła, bez `fx_rate` i `ryczalt.service_map` (D-30) |
| F2, reszta zakresu | „Kursy walut” (wiersze PIT, różnicy kursowej i progów w euro; „Data przychodu według źródła”), „Stawki ryczałtu”, „Zaliczka na ryczałt”, „Składki ZUS”, „Płatności, różnice kursowe i metoda kasowa”, „Korekty przychodu”, „Parametry”; podział na części w pliku fazy |
| F3 | „Zakres: tylko B2B”, „Kursy walut”, „Stawki ryczałtu”, „Przegląd doradcy: część podatkowa” |
| F5 | „Koszty: import usług i dokumenty spoza KSeF”, „Podatek u źródła” |

- Kolumny są w [data-model.md](data-model.md). Pobieranie kursów z API NBP opisuje [modules/rejestry-kursy.md](modules/rejestry-kursy.md).
- Terminy wpłat są w [compliance-calendar.md](compliance-calendar.md). Pola JPK są w [modules/jpk.md](modules/jpk.md), mapowanie FA(3) w [modules/ksef.md](modules/ksef.md).
- Parametry są w `setting` z `valid_from`. Zmiana parametru użytego przez zamknięty okres wymaga ponownego otwarcia okresu.
- Pozycje oznaczone „przegląd doradcy” zatwierdza doradca przed go-live F3 (D-04 w [decisions.md](decisions.md)). Do tego czasu obowiązuje założenie domyślne z pytania. Wyjątek: `eu_b2b_unidentified` jest do tego czasu zablokowany.

**Akty prawne (teksty jednolite)**

| Skrót | Akt |
| --- | --- |
| ustawa o VAT | [Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| ustawa o PIT | [Dz.U. 2026 poz. 592](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) |
| ustawa o ryczałcie | [Dz.U. 2025 poz. 843](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) |
| ustawa o CIT | [Dz.U. 2026 poz. 554](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) |
| Ordynacja podatkowa (OP) | [Dz.U. 2026 poz. 622](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf) |
| ustawa o świadczeniach opieki zdrowotnej | [Dz.U. 2025 poz. 1461](https://eli.gov.pl/api/acts/DU/2025/1461/text/T/D20251461L.pdf) |

## Zakres: tylko B2B

Klienci są wyłącznie przedsiębiorcami: z UE, spoza UE i z Polski (D-05). Sprzedaż konsumentom i procedura OSS są poza zakresem.

| `tax_scenario` | Miejsce świadczenia | VAT na fakturze | JPK_V7M | VAT-UE | Identyfikator nabywcy |
| --- | --- | --- | --- | --- | --- |
| `eu_b2b` | państwo nabywcy (art. 28b ustawy o VAT) | np II, rozlicza nabywca | K_11 i K_12 | tak | ważny wynik VIES |
| `eu_b2b_unidentified` | państwo nabywcy (art. 28b); do potwierdzenia przez doradcę (Q-29) | np I | K_11 | nie | `manual_evidence` statusu przedsiębiorcy |
| `non_eu_b2b` | państwo nabywcy (art. 28b) | np I | K_11 | nie | zagraniczny identyfikator podatkowy, dowód `manual_evidence` |
| `pl_b2b` | Polska | stawka krajowa, zwykle 23% | K_19 i K_20 przy 23% | nie | NIP; weryfikacja na białej liście |

- Brak identyfikatora nabywcy blokuje zatwierdzenie faktury. Polityka weryfikacji jest w [modules/rejestry-kursy.md](modules/rejestry-kursy.md).
- Klient z Wielkiej Brytanii i z Irlandii Północnej (`XI`) przy usługach to `non_eu_b2b` ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)). Do potwierdzenia w przeglądzie doradcy (Q-29).

**Nabywca z UE bez ważnego numeru VIES (`eu_b2b_unidentified`)**

Scenariusz czeka na potwierdzenie doradcy (Q-29, D-05). Do tego czasu zatwierdzenie faktury z tym scenariuszem jest zablokowane. Odblokowuje je `tax_scenario` zatwierdzony w `ksef.fa3_review` ([modules/ksef.md](modules/ksef.md)).

| Element | Treść | Podstawa |
| --- | --- | --- |
| podatnik | prowadzi samodzielnie działalność gospodarczą, bez względu na rejestrację do VAT-UE | art. 28a pkt 1 lit. a [ustawy o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| VAT-UE | nie, bo obejmuje tylko nabywców zidentyfikowanych na potrzeby VAT | art. 100 ust. 1 pkt 4 tamże |
| dowód | `manual_evidence` statusu przedsiębiorcy, np. wyciąg z rejestru kraju nabywcy | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |
| standard dowodu | nabywca wystąpił o numer VAT, a Ty masz inny dowód statusu i sprawdzasz jego dane (art. 18 ust. 1 lit. b); do weryfikacji przez doradcę | [rozporządzenie wykonawcze 282/2011](https://eur-lex.europa.eu/legal-content/PL/TXT/?uri=CELEX:32011R0282), sprawdzone 8.10.2026 |

- Nabywca bez numeru i bez wniosku o numer może być traktowany jak niepodatnik (art. 18 ust. 2 rozporządzenia 282/2011). To sprzedaż B2C, poza zakresem (D-05).
- Mapowanie scenariuszy na pola FA(3) jest w [modules/ksef.md](modules/ksef.md). Pola JPK są w [modules/jpk.md](modules/jpk.md).
- Pierwszy klient konsument oznacza nowy rekord D-xx i sekcję B2C tutaj. Zmieniają się wtedy miejsce świadczenia (art. 28c), próg usług elektronicznych (art. 28k) i OSS (art. 130b) ([ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)).
- Usługa, do której stosuje się wyjątek od art. 28b (np. związana z nieruchomością), też wymaga nowej reguły i przeglądu doradcy.

## Kursy walut

Ta sekcja jest jedynym domem reguły kursu. Mechanikę pobierania (`FxRateProvider`, tabela A, dni bez tabeli) opisuje [modules/rejestry-kursy.md](modules/rejestry-kursy.md).

| Wielkość | Kurs | Podstawa | Kolumna |
| --- | --- | --- | --- |
| przychód na ryczałcie (K_8) | średni NBP z ostatniego dnia roboczego przed dniem uzyskania przychodu | ustawa o PIT, art. 11a ust. 1, przez art. 6 ust. 1 ustawy o ryczałcie | `invoice.fx_pit_rate_id`, `revenue_entry.fx_rate_id` |
| podstawa VAT, faktura wystawiona po obowiązku podatkowym | średni NBP z ostatniego dnia roboczego przed dniem powstania obowiązku podatkowego | ustawa o VAT, art. 31a ust. 1 | `invoice.fx_vat_rate_id` |
| podstawa VAT, faktura wystawiona przed obowiązkiem podatkowym | średni NBP z ostatniego dnia roboczego przed dniem wystawienia faktury; dzień wystawienia to P_1 (online: dzień przesłania, art. 106na ust. 1; przesłanie po dniu P_1: tryb offline, data z P_1, art. 106nda ust. 10 i 16); przesłanie później niż następnego dnia roboczego po P_1 (ust. 2): kurs nadal sprzed P_1 i zadanie w panelu, do potwierdzenia w kroku 0 F1 | ustawa o VAT, art. 31a ust. 2 | `invoice.fx_vat_rate_id` |
| import usług | jak art. 31a ust. 1; nigdy według wyboru z ust. 2a | ustawa o VAT, art. 31a ust. 1 i 2d | `invoice.fx_vat_rate_id` |
| faktura korygująca (VAT) | kurs przyjęty dla faktury pierwotnej | ustawa o VAT, art. 31b ust. 1 | kurs faktury korygowanej |
| korekta przychodu (PIT, K_8 wiersza `correction`) | kurs PIT wiersza korygowanego (`fx_rate_id` wiersza z `corrects_entry_id`); przegląd doradcy | ustawa o PIT, art. 11a ust. 1 | `revenue_entry.fx_rate_id` |
| różnica kursowa przy zapłacie | kurs faktycznie zastosowany w dniu wpływu (`payment.paid_on`); bez niego średni NBP z ostatniego dnia roboczego przed `paid_on` (sekcja „Płatności, różnice kursowe i metoda kasowa”) | ustawa o PIT, art. 24c ust. 2 pkt 1 i ust. 4, przez art. 6 ust. 1c ustawy o ryczałcie | `payment.actual_rate`, `payment.fx_rate_id` |
| progi w euro (wpłata co kwartał, limit ryczałtu) | średni kurs euro NBP z pierwszego dnia roboczego października roku poprzedzającego rok podatkowy, czyli października roku przychodu (przychód 2026 i limity na 2027: kurs z 1.10.2026, D-76); wywołanie `getRate('EUR', dzień po pierwszym dniu roboczym października)`; numer tabeli to dowód. Przed tym dniem metryka i prognoza biorą ostatnią tabelę A z oznaczeniem „szacunek” | ustawa o ryczałcie, art. 4 ust. 2 | wyliczenie, bez kolumny |

Źródła: [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), [ustawa o PIT](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf), [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf).

**Daty, od których liczy się kurs**

| Data | Reguła | Podstawa |
| --- | --- | --- |
| uzyskanie przychodu (`revenue_date`) | dzień wykonania usługi albo jej części, nie później niż dzień wystawienia faktury albo zapłaty | ustawa o PIT, art. 14 ust. 1c |
| przychód z usługi rozliczanej okresowo | ostatni dzień okresu rozliczeniowego z umowy albo faktury, co najmniej raz w roku | ustawa o PIT, art. 14 ust. 1e |
| obowiązek podatkowy VAT (`tax_point_date`) | wykonanie usługi; usługi przyjmowane częściowo; usługa rozliczana okresowo: ostatni dzień każdego okresu rozliczeniowego (ust. 3); usługa ciągła ponad rok bez rozliczenia w roku: koniec każdego roku (ust. 3 zd. 2); zaliczka | [ustawa o VAT, art. 19a ust. 1, 2, 3 i 8](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| wystawienie faktury w KSeF | P_1; w trybie online równa dniowi przesłania (art. 106na ust. 1), w offline24 data z P_1 | ustawa o VAT, art. 106na ust. 1, art. 106e ust. 1 pkt 1; [MF, offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/) |

- Przy sprzedaży z kokpitu (D-16, [modules/ksef.md](modules/ksef.md)) P_1 to dzień wysyłki. Przy przyjęciu po północy obowiązuje P_1, nie dzień przyjęcia. Data wystawienia dla VAT i PIT jest więc tym samym dniem w Europe/Warsaw.
- Faktura z Subject1 wystawiona poza kokpitem mogła trafić do KSeF po dniu P_1. Dzień wystawienia to wtedy nadal P_1 (art. 106nda ust. 10 i 16).
- Daty kursu PIT i VAT zwykle się pokrywają. Różnią się m.in. przy fakturze wystawionej przed wykonaniem usługi, także rozliczanej okresowo, i przy zapłacie przed wykonaniem. Kokpit liczy oba kursy niezależnie.
- „Ostatni dzień roboczy przed” oznacza ostatnią tabelę A opublikowaną ściśle przed datą. W weekendy i święta tabeli nie ma.
- Kurs pobiera się na żądanie dla konkretnej daty i zapisuje niezmiennie w `fx_rate`. Dawna reguła „kurs raz dziennie” nie obowiązuje.
- Obliczenia: kurs z 6 miejscami, wynik pośredni ze skalą 10, zaokrąglenie według [data-model.md](data-model.md).

**Data obowiązku podatkowego z pól FA(3)**

Dotyczy importu Subject1 i daty obowiązku u sprzedawcy przy koszcie („Przegląd kosztów”). Faktura ma jeden kurs VAT, więc i jedną `tax_point_date`.

| Pola faktury | `tax_point_date` |
| --- | --- |
| P_6 | P_6 |
| P_6A we wszystkich pozycjach, jednakowe | ta data |
| `OkresFa` | P_6_Do, koniec okresu (art. 19a ust. 3) |
| brak P_6, P_6A i `OkresFa` | P_1; P_6 pomija się, gdy data usługi równa się dacie wystawienia (art. 106e ust. 1 pkt 6) |
| P_6A różne albo nie we wszystkich pozycjach | brak automatycznego kursu; zadanie w panelu, datę wpisujesz Ty |

Źródła: [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), [schemat FA(3)](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/schemy/FA/schemat_FA%283%29_v1-0E.xsd) (P_6 i `OkresFa` wykluczają się). Tabelę zatwierdza przegląd doradcy (Q-29).

**Ręczna `tax_point_date` na koszcie (przedpłata)**

Zapłata przed wykonaniem usługi daje obowiązek podatkowy z dniem zapłaty ([ustawa o VAT, art. 19a ust. 8](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Przykład: kredyty API kupione z góry. Pola faktury tego nie pokazują, więc datę zmieniasz ręcznie.

| Element | Reguła |
| --- | --- |
| gdzie | przegląd kosztu, tylko `direction` = `cost` |
| zapis | nowa `tax_point_date` i powód w `invoice.tax_point_override_reason`; zmiana bez powodu jest odrzucana |
| skutki | nowy kurs VAT (art. 31a ust. 1) i nowy proponowany `vat_period_start`; przy imporcie usług VAT należny w miesiącu zapłaty |
| okres zamknięty | zmiana jak każdy zapis do zamkniętego M („Blokada okresu” w [compliance-calendar.md](compliance-calendar.md)) |
| status | domyślne założenie z Q-21; potwierdza doradca (Q-29) |

Przykład (fikcyjny): kredyty API zapłacone 30.11.2026 (poniedziałek), faktura z 2.12.2026. `tax_point_date` to 30.11.2026, kurs VAT z tabeli z 27.11.2026. Import usług i odliczenie trafiają do JPK_V7M za 11.2026.

**Data przychodu według źródła**

| `revenue_entry.source` | `revenue_date` (K_3) |
| --- | --- |
| `ksef_subject1` | P_6 albo jednakowe P_6A, najpóźniej P_1 albo wcześniejszy dzień zapłaty (art. 14 ust. 1c); `OkresFa`: P_6_Do (art. 14 ust. 1e); bez tych pól: P_1 |
| `freshbooks` | data usługi z DTO, najpóźniej data wystawienia albo wcześniejszy dzień zapłaty (art. 14 ust. 1c); bez daty usługi: data wystawienia |
| `import` | K_3 ze źródła |
| `manual` | data, którą wpisujesz |

- Wiersz `import` niesie K_3, K_8 i K_9 policzone w narzędziu źródłowym, z pustym `fx_rate_id` (D-30). Kokpit nie pobiera dla niego kursu.
- Data wystawienia przy `freshbooks` to data faktury FreshBooks przed przejściem, a od F3 P_1.
- Różne P_6A albo brak P_6A w części pozycji: zadanie w panelu, datę wpisujesz Ty.
- Podstawa: [ustawa o PIT, art. 14 ust. 1c i 1e](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf).

**Przykład (fikcyjne daty).** Faktura z Subject1 z P_6 = 28.10.2026 i P_1 = 2.11.2026: `revenue_date` i `tax_point_date` to 28.10.2026. Ta sama faktura z P_6 = 30.11.2026: `revenue_date` 2.11.2026, `tax_point_date` 30.11.2026. Faktura FreshBooks sprzed przejścia z 16.02.2026, bez daty usługi: `revenue_date` 16.02.2026.

**Wybór art. 31a ust. 2a**

- Podatnik może przeliczać VAT według zasad PIT przez co najmniej 12 miesięcy (ust. 2b). Rezygnacja też wiąże na 12 miesięcy (ust. 2c).
- Wybór nie obejmuje transakcji spoza tych zasad, w tym importu usług (ust. 2d).
- Domyślnie bez wyboru (D-21, Q-23). Ewentualny wybór zapisujesz w `fx.art31a_2a_elected` z `valid_from`; wtedy sprzedaż ma `fx_vat_rate_id` równe `fx_pit_rate_id`.

**Kurs EBC.** Art. 31a ust. 1 zdanie drugie dopuszcza kurs EBC dla VAT. Kokpit go nie stosuje; `fx_rate.source = 'ecb'` wymaga nowej decyzji.

**Przykład (fikcyjne kursy).** Usługa wykonana 30.10.2026 (piątek), faktura wysłana do KSeF 2.11.2026. Data przychodu i obowiązek podatkowy to 30.10.2026, więc oba kursy pochodzą z tabeli z 29.10.2026. Przy fikcyjnym kursie 4,2500 kwota 1 000,00 EUR daje K_8 = 4 250,00 zł.

## Stawki ryczałtu (PKWiU 2015)

Stawka zależy od usługi według PKWiU z 2015 r. ([ustawa o ryczałcie, art. 4 ust. 1 pkt 1 i art. 12 ust. 1](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)). Wybrane stawki dla JDG usługowej:

| Stawka | Usługi (PKWiU) | Art. 12 ust. 1 |
| --- | --- | --- |
| 17% | wolne zawody | pkt 1 |
| 15% | przetwarzanie danych (ex 63.11.1), pozostałe usługi informacyjne (63.99), doradztwo związane z zarządzaniem (ex dział 70), reklama i badanie rynku (dział 73), licencje na programy komputerowe (58.29.50.0) | pkt 2 |
| 14% | usługi architektoniczne i inżynierskie (dział 71), specjalistyczne projektowanie (74.1) | pkt 2a |
| 12% | doradztwo w zakresie sprzętu (62.02.10.0), usługi związane z oprogramowaniem (ex 62.01.1), oryginały oprogramowania (62.01.2), doradztwo w zakresie oprogramowania (ex 62.02), instalowanie oprogramowania (ex 62.09.20.0), zarządzanie siecią i systemami (62.03.1), pakiety oprogramowania (58.29.1, 58.29.2, ex 58.29.3) | pkt 2b |
| 8,5% | pozostałe usługi | pkt 5 lit. a |

**Reguły**

- `ryczalt.service_map` mapuje pozycję FreshBooks albo `service_type` na kod PKWiU 2015 i stawkę. Wiersz ma `valid_from`.
- Stawka jest przy pozycji (`invoice_line.ryczalt_rate_pct`), nie przy firmie. Ewidencja ma wiersz per faktura i stawka (K_9).
- Pozycja bez mapowania blokuje zatwierdzenie faktury i wpis do ewidencji.
- Bramka zatwierdzeń pokazuje stawkę każdej pozycji.
- Mapowanie i kody zatwierdza doradca (przegląd doradcy, Q-22, D-04). Do przeglądu wpisujesz w `ryczalt.service_map` stawkę, którą stosujesz dziś (Twoja odpowiedź na Q-22), z `note` „przed przeglądem doradcy”. Bez wpisu pozycja zostaje zablokowana.

**Wartość `ryczalt.service_map`**

Lista wpisów w `setting.value`; cała lista ma jedno `valid_from`. Stawki są napisami dziesiętnymi, nigdy liczbą JSON (bez `float`, [data-model.md](data-model.md)).

| Pole | Treść |
| --- | --- |
| `service_type` | klucz usługi; trafia do `invoice_line.service_type` |
| `freshbooks_item` | nazwa pozycji FreshBooks albo `null` |
| `p7_match` | lista fragmentów opisu P_7 dla wierszy bez pozycji FreshBooks; może być pusta |
| `pkwiu` | kod PKWiU 2015 |
| `rate_pct` | stawka ryczałtu, np. `"12.00"` |
| `default` | `true` w najwyżej jednym wpisie |

**Dopasowanie pozycji do wpisu**

| Pozycja | Kolejność dopasowania |
| --- | --- |
| FreshBooks: szkic, import `freshbooks` | `freshbooks_item`, potem `default` |
| Subject1 z `invoice.freshbooks_id` | `freshbooks_item` pozycji powiązanej faktury FreshBooks, potem `p7_match` na P_7, potem `default` |
| Subject1 bez `freshbooks_id` | `p7_match` na P_7, potem `default` |
| `manual` | `service_type` wybierasz z listy |
| `import` | K_9 ze źródła, bez mapowania |

- Wpis `default` jest opcjonalny. Założenie domyślne Q-22 to jeden rodzaj usług, więc jeden wpis z `default` = `true`.
- Brak dopasowania: alert brakującej stawki i przypisanie ręczne w panelu. Do tego czasu brak zatwierdzenia i wpisu do ewidencji.

Przykład (fikcyjny, przed przeglądem doradcy): `[{"service_type": "software", "freshbooks_item": "Development", "p7_match": ["programowanie"], "pkwiu": "62.01.1", "rate_pct": "12.00", "default": true}]`.

**Prawo do ryczałtu**

- Ryczałt w roku przysługuje przy przychodzie z poprzedniego roku do 2 000 000 euro ([ustawa o ryczałcie, art. 6 ust. 4](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)).
- Projekt UD458 obniża limit na 2027 do 250 tys. EUR przychodu z 2026 i wprowadza 17% powyżej 300 tys. EUR. Na 08.10.2026 jest nieuchwalony ([proces druku 3147](https://api.sejm.gov.pl/sejm/term10/processes/3147)).
- Wpłata co kwartał w roku przysługuje przy przychodzie z poprzedniego roku do 200 000 euro ([ustawa o ryczałcie, art. 21 ust. 1b](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)).
- Parametry są w `ryczalt.eligibility`; panel pokazuje przychód 2026 wobec 250 tys. EUR (Q-38).

**Prognoza progów i rezerwa** (D-76, sekcja `threshold-forecast` na panelu „Podatki”)

- Podstawa to zakończone miesiące roku: średnia miesiąca = suma K_8 tych miesięcy / ich liczba. Wartość narastająco po podstawie rośnie o średnią co miesiąc; prognoza roku to większa z wartości na grudzień i przychodu do dziś. W styczniu bieżącego roku prognozy nie ma; za rok zamknięty prognoza równa się przychodowi.
- Limity w euro z `ryczalt.eligibility` (`limit_eur`, `watch_limit_eur`, `quarterly_limit_eur`) przelicza się na złote po kursie z wiersza „progi w euro” do grosza (`HALF_UP`). Panel pokazuje miesiąc przekroczenia: rzeczywisty albo z prognozy.
- Progi zdrowotnej z `zus.parameters` liczy się tą samą metodą na przychodzie po składkach społecznych zapłaconych w miesiącu (art. 81 ust. 2g); panel pokazuje próg teraz, próg z prognozy i miesiąc przekroczenia granicy bieżącego progu.
- Do odłożenia dziś = ryczałt narosły w trwającym okresie zaliczki (wzór „Zaliczki na ryczałt” na przychodzie i odliczeniach do dziś) + dopłata zdrowotnej za miesiące z wpisem `zus_payment`, gdyby rok skończył się progiem z prognozy (kwota progu × liczba miesięcy − zapłacona zdrowotna, nie mniej niż zero).
- To prognoza, nie porada: panel tak ją opisuje i niczego nie wysyła ani nie zapisuje.

## Zaliczka na ryczałt

**Odliczenia** ([ustawa o ryczałcie, art. 11](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf))

| Odliczenie | Podstawa | Źródło danych |
| --- | --- | --- |
| zapłacone składki społeczne | art. 11 ust. 1 z art. 26 ust. 1 pkt 2 ustawy o PIT | `zus_payment.social_pln` |
| 50% składki zdrowotnej zapłaconej w roku | art. 11 ust. 1a | `zus_payment.health_pln` |
| podział odliczeń przy kilku stawkach w proporcji przychodów | art. 11 ust. 3 | `revenue_entry` narastająco |

**Wzór dla miesiąca m**

1. `P_r` = suma K_8 wierszy `revenue_entry` w stawce r z `revenue_date` w m.
2. `O` = odliczenia zapłacone w m plus nadwyżka przeniesiona z poprzednich miesięcy tego roku.
3. `u_r` = przychód narastająco w stawce r / przychód narastająco ogółem.
4. Podstawa `B_r` = max(0, `P_r` − `O` × `u_r`), w pełnych złotych.
5. Zaliczka = Σ_r `B_r` × r, w pełnych złotych ([OP, art. 63 § 1](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf)).
6. Nadwyżka = Σ_r max(0, `O` × `u_r` − `P_r`). Przechodzi na kolejne miesiące tego samego roku, nie na następny rok.
7. Przychód narastająco ogółem = 0: `B_r` = 0, a całe `O` przechodzi.

- Przy wpłacie co kwartał m oznacza kwartał: `P_r` i `O` sumujesz za kwartał ([art. 21 ust. 1a](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)).
- Nadwyżka liczona per stawka i jej wygaśnięcie z końcem roku to przegląd doradcy.

Zaokrąglenie podstawy `B_r` ([OP, art. 63 § 1](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf)) to wyjątek od reguły „zaokrąglenie raz” z [data-model.md](data-model.md).

Proporcja z art. 11 ust. 3 jest roczna. W ciągu roku kokpit używa proporcji narastającej; ostateczny podział robi zeznanie PIT-28. Kolejność zaokrągleń przy kilku stawkach to przegląd doradcy.

**Przykład testowy (dane fikcyjne)**

| Pozycja | 12% | 8,5% | Razem |
| --- | --- | --- | --- |
| przychód miesiąca `P_r` | 20 000,00 | 5 000,00 | 25 000,00 |
| udział narastający `u_r` | 0,8 | 0,2 | 1 |
| odliczenia: społeczne 1 600,00 + 50% z 830,58 | 1 612,23 | 403,06 | 2 015,29 |
| podstawa `B_r` | 18 388 | 4 597 | – |
| ryczałt | 2 206,56 | 390,745 | 2 597,305 |
| zaliczka do zapłaty | – | – | 2 597 zł |

**Terminy i częstotliwość**

- Wpłata co miesiąc do 20. dnia za poprzedni miesiąc; za grudzień do 20 stycznia ([art. 21 ust. 1](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)). Terminy w [compliance-calendar.md](compliance-calendar.md).
- Wpłata co kwartał tylko przy przychodzie z poprzedniego roku do 200 000 euro albo przy starcie działalności (art. 21 ust. 1a, 1b). Wybór pokazujesz w zeznaniu (ust. 1c).
- Ustawienie `ryczalt.advance_frequency`, domyślnie co miesiąc (Q-17). Przy wpłacie co kwartał K_10 może zawierać datę otrzymania przychodu ([broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf)).
- Zeznanie PIT-28 składasz od 15 lutego do 30 kwietnia ([art. 21 ust. 2 pkt 2](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)). Kokpit daje zestawienie z liczbami części D–N do przepisania w Twój e-PIT (D-101); wysyłka przez e-Deklaracje tylko dla roku z wariantem formularza (D-96), poza tym ręczna.

## Składki ZUS

Płacisz ręcznie do 20. dnia za poprzedni miesiąc i wpisujesz zapłatę w `zus_payment`. Składki służą do odliczeń od zaliczki.

**Składki społeczne.** Kwoty i podstawy są w `zus.parameters`, wariant w `zus.variant` (Q-16: domyślnie pełne składki). `other_pln` w `zus_payment` to części, których nie odliczasz, np. Fundusz Pracy; podział do potwierdzenia przy Q-16.

**Wartość `zus.parameters`**

Jeden wiersz `setting` na `valid_from`, z kwotami dla wariantu z `zus.variant`. Kwoty są napisami dziesiętnymi, nigdy liczbą JSON (bez `float`).

| Pole | Treść |
| --- | --- |
| `social_base_pln` | podstawa składek społecznych |
| `social_pln` | miesięczne składki społeczne do odliczenia od przychodu |
| `other_pln` | miesięczne części bez odliczenia, np. Fundusz Pracy |
| `health_brackets[]` | progi zdrowotnej: `revenue_up_to_pln` (`null` w ostatnim progu), `base_pln`, `amount_pln`; wartości 2026 w tabeli niżej |
| `health_bracket_manual` | numer progu z `health_brackets[]`, od 1; kwota `zus` bierze go do F2, potem próg liczy się z przychodu |

| `zus.variant` | Znaczenie |
| --- | --- |
| `full` | pełne składki społeczne (domyślnie, Q-16) |
| `preferential` | preferencyjna podstawa składek społecznych |
| `start_relief` | ulga na start: tylko składka zdrowotna, `social_pln` i `other_pln` = 0 |

- Kwotę za miesiąc składkowy M liczysz według `setting` obowiązującego 1. dnia M.
- Kwota obowiązku `zus` za M = `social_pln` + `other_pln` + `amount_pln` progu zdrowotnej.
- Zmiana wariantu to nowy wiersz `zus.variant` i nowy wiersz `zus.parameters` z tym samym `valid_from`.

**Składka zdrowotna na ryczałcie w 2026** ([ZUS](https://www.zus.pl/en/-/informacja-w-sprawie-podstawy-wymiaru-sk%C5%82adki-oraz-kwoty-sk%C5%82adki-na-ubezpieczenie-zdrowotne-w-2026-r.))

| Przychód narastająco w roku | Podstawa | Składka miesięczna |
| --- | --- | --- |
| do 60 000 zł | 5 537,18 zł | 498,35 zł |
| do 300 000 zł | 9 228,64 zł | 830,58 zł |
| powyżej 300 000 zł | 16 611,55 zł | 1 495,04 zł |

- Przychód do progu pomniejszasz o zapłacone składki społeczne (art. 81 ust. 2g [ustawy o świadczeniach](https://eli.gov.pl/api/acts/DU/2025/1461/text/T/D20251461L.pdf)).
- Ust. 2h pozwala przyjąć przychód z poprzedniego roku na cały rok. Domyślnie przychód bieżący (Q-16).
- Przychód narastająco pochodzi z `revenue_entry`, a przed jej odtworzeniem z `opening.revenue_ytd` („Bilans otwarcia” w [compliance-calendar.md](compliance-calendar.md)).
- Roczne rozliczenie składki zdrowotnej robisz do 20 maja; nadpłatę odzyskujesz wnioskiem RZS-R ([ZUS](https://www.zus.pl/en/-/roczne-rozliczenie-sk%C5%82adki-zdrowotnej-za-2025-r.)).
- Kwoty zmieniają się co roku w styczniu. Nowy wiersz `zus.parameters` ma `valid_from` 1 stycznia; parametry 2027 to warunek wstępny z [README.md](README.md).

**Roczne rozliczenie zdrowotnej** (obowiązek `health_reconciliation`, [ZUS](https://www.zus.pl/en/-/roczne-rozliczenie-sk%C5%82adki-zdrowotnej-za-2025-r.))

1. Próg ustalasz z przychodu rocznego po odliczeniu zapłaconych składek społecznych (art. 81 ust. 2g). Przy wyborze ust. 2h bierzesz przychód roku poprzedniego.
2. Składka należna = `amount_pln` tego progu × liczba miesięcy podlegania w roku.
3. Różnica = składka należna − suma `zus_payment.health_pln` za miesiące roku.
4. Dodatnia różnica to `amount_due`. Ujemna daje `amount_due` = 0 i zadanie: wniosek RZS-R o zwrot nadpłaty.

## Koszty: import usług i dokumenty spoza KSeF

Na ryczałcie koszty nie obniżają podatku. Liczą się dla VAT (odliczenie i import usług), WHT i JPK_V7M.

**Import usług.** To usługa od dostawcy bez siedziby w Polsce. VAT rozliczasz jako nabywca ([ustawa o VAT, art. 2 pkt 9, art. 17 ust. 1 pkt 4, art. 28b](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Pola i znaczniki według [broszury JPK_V7M(3)](https://www.podatki.gov.pl/media/wgbkrejs/broszura-jpk_vat-z-deklaracj%C4%85-od-1-lutego-2026-r.pdf).

| Dostawca | `import_services` | VAT należny | VAT naliczony | Dowód |
| --- | --- | --- | --- | --- |
| podatnik VAT z UE, usługa art. 28b | `eu_28b` | K_29, K_30 | K_42, K_43 (środek trwały: K_40, K_41) | faktura zagraniczna, znacznik BFK |
| dostawca spoza UE albo poza art. 28b od podatnika VAT z UE | `other` | K_27, K_28 | K_42, K_43 (K_40, K_41) | faktura zagraniczna, znacznik BFK |
| brak faktury, tylko raport (np. opłaty bramki płatniczej) | jak wyżej | jak wyżej | jak wyżej | dowód wewnętrzny `WEW`, znacznik DI |
| usługa zwolniona po stronie nabywcy | jak wyżej | podstawa, podatek 0,00 | brak | `vat_exempt` |

- Obowiązek podatkowy powstaje według art. 19a, kurs według art. 31a ust. 1 (sekcja „Kursy walut”).
- Odliczenie przysługuje w tym samym rozliczeniu, w którym wykazujesz podatek należny ([art. 86 ust. 10b pkt 3](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)).
- Klasyfikacja stałych dostawców (Anthropic, FreshBooks, hosting) i opłat Stripe to Q-21. Domyślnie import usług; zatwierdza ją doradca w ramach Q-29.
- Przedpłata, np. kredyty Anthropic: ręczna `tax_point_date` z „Kursów walut” (Q-21).

**Krajowe dokumenty spoza KSeF.** Do 31.12.2026 sprzedawca ze sprzedażą do 10 000 zł brutto w miesiącu może wystawiać faktury poza KSeF. Przekroczenie limitu odbiera to prawo trwale ([art. 145m ust. 1–2](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), [MF](https://ksef.podatki.gov.pl/ponizej-10-000-zl/), [README.md](README.md)). Do tej daty dochodzą faktury z kas i paragony z NIP ([art. 145n](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), [MF, pytania i odpowiedzi](https://ksef.podatki.gov.pl/pytania-i-odpowiedzi-ksef-20/)). Takie dokumenty mają źródło `email`, `paper` albo `cash_register` i znacznik BFK.

**Przegląd kosztów**

- Stany `cost_status` i `cost_review` oraz wymagany plik dla źródeł innych niż KSeF opisuje [data-model.md](data-model.md).
- Faktura z KSeF jest otrzymana w dniu nadania numeru KSeF ([art. 106na ust. 3](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)).
- Najwcześniejszy okres odliczenia to późniejszy z dwóch miesięcy w tabeli ([art. 86 ust. 10 i 10b pkt 1](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Kokpit proponuje go jako `vat_period_start`.

| Miesiąc | Data |
| --- | --- |
| obowiązku podatkowego u sprzedawcy | według „Daty obowiązku podatkowego z pól FA(3)” w „Kursach walut”: P_6, P_6_Do z `OkresFa`, jednakowe P_6A, a bez nich P_1 |
| otrzymania faktury | `ksef_number_assigned_at` w Europe/Warsaw albo `received_on` |

- Ręczna `tax_point_date` (przedpłata) zastępuje miesiąc obowiązku z pól FA(3).
- Różne P_6A: brak propozycji, okres wybierasz w panelu.
- Import usług bez zmian: odliczenie w okresie podatku należnego (art. 86 ust. 10b pkt 3).
- Przykład (fikcyjny): `OkresFa` 1–31.12.2026, numer KSeF nadany 20.11.2026. Proponowany `vat_period_start` to 2026-12-01.
- Regułę zatwierdza przegląd doradcy (Q-29).
- KSeF nie ma odrzucenia faktury przez nabywcę; poprawia tylko wystawca przez KOR ([MF, pytania i odpowiedzi](https://ksef.podatki.gov.pl/pytania-i-odpowiedzi-ksef-20/)). Ocena `not_mine` tworzy zadanie: prośba o KOR albo zgłoszenie oszustwa.
- `private`, `not_mine` i `disputed` nie trafiają do JPK. Bramka JPK odrzuca okres z kosztami `imported`.
- Pola JPK wynikają deterministycznie z pól kosztu.

## Podatek u źródła (WHT)

JDG jest płatnikiem podatku od wypłat dla nierezydentów z art. 21 ust. 1 ustawy o CIT ([art. 26 ust. 1](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf)). Dotyczy to m.in. licencji i prawa do używania urządzeń (pkt 1) oraz reklamy, przetwarzania danych i doradztwa (pkt 2a). Stawka podstawowa to 20%.

| Element | Reguła | Podstawa |
| --- | --- | --- |
| stawka z umowy o unikaniu podwójnego opodatkowania | tylko z certyfikatem rezydencji dostawcy i przy zachowaniu należytej staranności | art. 26 ust. 1 |
| wpłata pobranego podatku | do 7. dnia miesiąca po miesiącu wypłaty | art. 26 ust. 3 |
| IFT-2R | do końca marca za poprzedni rok, dla dostawcy i urzędu; także gdy umowa zwolniła z poboru | art. 26 ust. 3a i 3d |
| CIT-10Z | do końca stycznia za rok, w którym pobrano podatek | art. 26a |

Źródło: [ustawa o CIT, Dz.U. 2026 poz. 554](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf).

- Kwalifikacja opłat za SaaS, chmurę i API (licencja, przetwarzanie danych, brak) wymaga oceny. Interpretacje KIS nie są sprawdzone; decyzja należy do Q-21 i przeglądu doradcy (Q-29).
- Dostawca ma pola `wht_category`, `wht_treaty_country`, `wht_cert_valid_until` i `wht_decision_source` ([data-model.md](data-model.md)). Certyfikat leży w `stored_file` rodzaju `evidence`.
- Podstawą terminu jest `invoice.paid_on`. Sposób przeliczenia wypłaty w walucie na złote ustala przegląd doradcy (Q-29).
- Kokpit liczy kwotę i przypomina. Wpłatę, IFT-2R i CIT-10Z składasz ręcznie; żadna faza nie planuje wysyłki.

**Zagraniczna osoba fizyczna (do weryfikacji)**

- Wypłata dla nierezydenta będącego osobą fizyczną, np. freelancera, podlega art. 29 [ustawy o PIT](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf), nie ustawie o CIT.
- Znacznik `counterparty.is_natural_person` = true wyłącza dla dostawcy reguły z tabeli wyżej i obowiązki WHT w [compliance-calendar.md](compliance-calendar.md).
- Stawki, terminy i formularze płatnika z ustawy o PIT kokpit modeluje dopiero po odpowiedzi na Q-21. Do tego czasu panel ostrzega przy koszcie takiego dostawcy.
- Anthropic i FreshBooks to spółki, więc wpływ jest dziś mały.

## Płatności, różnice kursowe i metoda kasowa

Płatności klientów pochodzą z FreshBooks do tabeli `payment` (Q-24; import w [modules/freshbooks.md](modules/freshbooks.md)). Opłaty bramki płatniczej to osobne dokumenty kosztowe.

**Różnice kursowe.** Do przychodów z różnic kursowych stosuje się art. 24c ustawy o PIT. Ujemne różnice zmniejszają przychód roku, w którym powstały ([ustawa o ryczałcie, art. 6 ust. 1c](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)).

| Krok | Reguła |
| --- | --- |
| wartość przychodu | kwota w walucie × kurs PIT z dnia przed datą przychodu |
| dzień wpływu | `payment.paid_on` |
| wartość w dniu wpływu | kurs faktycznie zastosowany (`payment.actual_rate`) tylko przy przewalutowaniu w dniu `paid_on`; wpisujesz go Ty z raportu Stripe. Inaczej średni NBP z ostatniego dnia roboczego przed `paid_on` ([ustawa o PIT, art. 24c ust. 4](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf)) |
| różnica | wartość w dniu wpływu − wartość przychodu; dodatnia zwiększa, ujemna zmniejsza przychód |
| zapis | wiersz `revenue_entry` rodzaju `fx_difference` z `payment_id` ([modules/ewidencja.md](modules/ewidencja.md)) |
| płatność częściowa | różnica proporcjonalnie do zapłaconej części |

Przykład (fikcyjne kursy): przychód 1 000,00 EUR po 4,2500 to 4 250,00 zł. Przewalutowanie w dniu `paid_on` po 4,2800 daje 4 280,00 zł i dodatnią różnicę 30,00 zł. Stawkę ryczałtu dla różnicy kursowej ustala przegląd doradcy; domyślnie stawka przychodu źródłowego.

**Metoda kasowa PIT** ([ustawa o PIT, art. 14c](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf); na ryczałcie przez [art. 6 ust. 1g ustawy o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf))

| Warunek albo skutek | Treść |
| --- | --- |
| limit | przychód z poprzedniego roku do 2 000 000 zł (od 1.01.2026) |
| wybór | oświadczenie na piśmie do 20 lutego roku podatkowego |
| czas obowiązywania | cały rok (ust. 7) i kolejne lata do rezygnacji; rezygnację składasz na piśmie w tym samym terminie, do 20 lutego (ust. 11) |
| zakres | tylko transakcje z przedsiębiorcą w rozumieniu Prawa przedsiębiorców, udokumentowane fakturą w terminie |
| data przychodu | dzień zapłaty, najpóźniej 2 lata od wystawienia faktury |
| ewidencja faktur | data wystawienia, numer, kwota należności, data zapłaty ([art. 15 ust. 11a ustawy o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)) |
| JPK_EWP | znacznik `Kasowy_PIT` w węźle `Podmiot1` ([broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf); mapowanie w [modules/ewidencja.md](modules/ewidencja.md)) |

- Czy klienci zagraniczni są „przedsiębiorcami” w tym rozumieniu, nie jest sprawdzone. Przy klientach głównie zagranicznych metoda kasowa ma ograniczony zasięg.
- Domyślnie bez metody kasowej: `pit.kasowy` = false (Q-24). Decyzja na 2027 to obowiązek `tax_form_choice` do 20.02.2027.
- Ulga na złe długi ([art. 11 ust. 4 ustawy o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)) nie obejmuje dłużników zagranicznych (ust. 10 pkt 3). Nie działa też przy metodzie kasowej (ust. 20). Kokpit jej nie śledzi; przeterminowana należność `pl_b2b` to decyzja ręczna.

## Korekty przychodu

| Przyczyna KOR | Okres przychodu (PIT) |
| --- | --- |
| inna niż błąd rachunkowy albo oczywista omyłka; domyślnie, także dla KOR z Subject1 | miesiąc wystawienia KOR |
| błąd rachunkowy albo oczywista omyłka, oznaczone przez Ciebie w panelu | okres faktury pierwotnej |

- Podstawa: [ustawa o PIT, art. 14 ust. 1m](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf), na ryczałcie przez [art. 6 ust. 1e ustawy o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf).
- `correction_reason` (PrzyczynaKorekty) i TypKorekty nie ustalają okresu PIT.
- Okres korekty w VAT ustala typ wybrany przy zatwierdzeniu KOR (`correction_type`, [modules/ksef.md](modules/ksef.md)). Domyślną regułę daje przegląd doradcy.
- KOR z Subject1 bez TypKorekty: okres VAT wybierasz w panelu.
- Reguła PIT i reguła VAT mogą wskazać różne miesiące. Domyślny okres PIT jest w zakresie przeglądu doradcy (Q-29).
- Kurs VAT korekty to kurs faktury pierwotnej (art. 31b ust. 1); kurs PIT według „Kursów walut”. Zapis w ewidencji opisuje [modules/ewidencja.md](modules/ewidencja.md).

## Parametry

| Klucz `setting` | Treść | Domyślnie |
| --- | --- | --- |
| `ryczalt.service_map` | usługa → PKWiU 2015 → stawka; pola w „Stawkach ryczałtu” | do przeglądu doradcy (Q-22) |
| `ryczalt.advance_frequency` | zaliczka co miesiąc albo co kwartał | co miesiąc (Q-17) |
| `ryczalt.eligibility` | limit prawa do ryczałtu, próg z projektu (obserwacja) i limit wpłaty co kwartał | 2 000 000 euro, obserwacja 250 000 euro z UD458 (Q-38), wpłata co kwartał 200 000 euro; wiersz domyślny od 1.01.2026 wstawia migracja, gdy klucza nie ma (D-76) |
| `zus.parameters` | podstawy i kwoty składek na rok, progi zdrowotnej; pola w „Składkach ZUS” | wartości ZUS na 2026 |
| `zus.variant` | wariant składek społecznych: `full`, `preferential`, `start_relief` | `full` (Q-16) |
| `fx.art31a_2a_elected` | wybór art. 31a ust. 2a | brak wyboru (D-21) |
| `pit.kasowy` | metoda kasowa PIT | wyłączona (Q-24) |
| `vat.excess_policy` | zwrot albo przeniesienie nadwyżki VAT; pola w [modules/jpk.md](modules/jpk.md) | przeniesienie (Q-31) |
| `vat.standard_rate` | stawka podstawowa VAT, np. do VAT należnego z importu usług | 23% ([ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)) |
| `jpk_st.enabled` | JPK_ST włączony; [modules/jpk.md](modules/jpk.md) | wyłączony (Q-25) |

## Przegląd doradcy: część podatkowa

Minimum z D-04 to mapowanie PKWiU→stawka; mapowanie FA(3) jest w [modules/ksef.md](modules/ksef.md). Domyślny zakres Q-29 dodaje punkty z tej listy.

| Punkt | Sekcja |
| --- | --- |
| kody PKWiU 2015 i stawki usług | „Stawki ryczałtu” |
| `eu_b2b_unidentified`: `np I`, bez VAT-UE, standard dowodu statusu przedsiębiorcy | „Zakres: tylko B2B” |
| klient z GB i z Irlandii Północnej (`XI`) przy usługach jako `non_eu_b2b` | „Zakres: tylko B2B” |
| data obowiązku podatkowego z pól FA(3) przy imporcie Subject1 | „Kursy walut” |
| moment podatkowy przedpłat kosztów i ręczna `tax_point_date` | „Kursy walut”, „Koszty: import usług i dokumenty spoza KSeF” |
| WHT od zagranicznych osób fizycznych (art. 29 ustawy o PIT) | „Podatek u źródła” |
| kolejność zaokrągleń zaliczki przy kilku stawkach; nadwyżka odliczeń per stawka i jej wygaśnięcie z końcem roku | „Zaliczka na ryczałt” |
| stawka ryczałtu dla różnicy kursowej | „Płatności, różnice kursowe i metoda kasowa” |
| dzień wpływu przy płatności przez bramkę (`paid_on` wobec `payout_on`) | „Płatności, różnice kursowe i metoda kasowa” |
| klasyfikacja VAT i WHT stałych dostawców, opłaty Stripe | „Koszty”, „Podatek u źródła” |
| najwcześniejszy okres odliczenia przy fakturze przed wykonaniem usługi | „Koszty: import usług i dokumenty spoza KSeF” |
| okres korekty przychodu (domyślnie miesiąc KOR) wobec TypKorekty | „Korekty przychodu” |
| kurs PIT korekty przychodu | „Kursy walut” |
| przeliczenie wypłaty w walucie na złote dla WHT | „Podatek u źródła” |
| ujęcie późnych K_2 w ewidencji 2026 (D-23) | [modules/ewidencja.md](modules/ewidencja.md) |

## Przypadki testowe

Przypadki testowe: [testing.md](testing.md) („Podatki”, „Czas, daty i kursy”), kryteria fazy w plikach faz.
