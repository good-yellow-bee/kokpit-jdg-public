# Kalendarz obowiązków

Silnik terminów, pełna tabela obowiązków, przypomnienia, zamknięcie miesiąca, blokada okresu i bilans otwarcia. Stan na 8 października 2026.

## Jak czytać

| Faza | Sekcje |
| --- | --- |
| F0b | „Zasady”, „Silnik terminów”, „`PolishCalendar`”, „Tabela obowiązków”, „Przypomnienia”, „Tytuły przelewów”, „Stany obowiązku”; wpis ręczny `edelivery_reply_due` |
| F1 | „Silnik terminów” (klucz `dedupe_key`); „Tabela obowiązków”: `vat_ue`, `wht_payment`, `wht_certificate`, `cit_10z`, `wl_notice`; „Uwagi do wierszy”: `vat_ue` przed zamknięciem miesiąca, WHT, `wht_certificate`, `wl_notice`; „Stany obowiązku” |
| F2.1, F2.4 | tylko uwaga o dry runie w „Bramkach przed budową JPK_EWP(4)”; obie części nie zależą od obowiązków ani zamknięcia miesiąca (D-30) |
| F2, reszta zakresu | „Zamknięcie miesiąca”, „Blokada okresu”, „Bilans otwarcia”, kwoty w „Tabeli obowiązków”, „Stany obowiązku” (wpis `zus_payment`); podział na części w pliku fazy |
| F3 | „Tabela obowiązków”: `invoice_issue` per szkic |
| F5 | „Zamknięcie miesiąca” (krok JPK, bramki JPK_V7M i JPK_EWP(4)), „Blokada okresu” (korekty), „Bilans otwarcia” (P_39, potwierdzenie w PROD) |

- Kolumny `obligation`, `period`, `notification` i `setting` (klucze `opening.*`) są w [data-model.md](data-model.md). Tu są reguły i przejścia stanów.
- Kryteria odbioru są w plikach faz: [phases/f0b-bramka-kalendarz.md](phases/f0b-bramka-kalendarz.md), [phases/f1-ksef-odczyt.md](phases/f1-ksef-odczyt.md), [phases/f2-ewidencja.md](phases/f2-ewidencja.md). F5: [phases/f5-jpk.md](phases/f5-jpk.md).
- Kwoty (zaliczka, ZUS, WHT) liczy [tax-rules.md](tax-rules.md).
- API e-Doręczeń jest odłożone (D-29). `edelivery_deemed` nie powstaje, a termin odpowiedzi na pismo wpisujesz ręcznie ([modules/e-doreczenia.md](modules/e-doreczenia.md), [roadmap.md](roadmap.md)).
- Obowiązki już biegną. Do go-live faz obowiązuje „Teraz – zgodność” z [README.md](README.md), z datami działań właściciela.

## Zasady

- ZUS i e-Urząd Skarbowy nie mają API. Kokpit liczy kwoty i przypomina; płacisz i sprawdzasz saldo ręcznie.
- Reguła ustawowa ma link do przepisu. Reguła operacyjna albo z datą wpisaną przez Ciebie (`credential_expiry`, `manual_channel_check`, `edelivery_reply_due`) ma link do pliku w docs. Reguła bez źródła nie trafia do kodu.
- Daty prawne są typu `date` w Europe/Warsaw; czas pochodzi z Symfony Clock ([data-model.md](data-model.md)).
- Kwoty, stawki i progi pochodzą z `setting` z `valid_from`, nigdy z literałów w kodzie.
- Zaległość nie jest zapisywana: obowiązek jest zaległy, gdy `status = 'open'` i `due_date` < dziś.
- VAT rozliczasz tylko miesięcznie (D-02 w [decisions.md](decisions.md)). Silnik nie zna innego okresu VAT.

## Silnik terminów

`ObligationEngine` tworzy wiersze `obligation` z reguł. Reguła ma klucz `rule_key` i pola z tabeli niżej.

| Element reguły | Znaczenie |
| --- | --- |
| typ | wartość `obligation.type` z kontraktu nazw w [naming.md](naming.md) |
| data bazowa | dzień miesiąca po okresie, stała data roczna, data z encji (`valid_until`, `wht_cert_valid_until`, `paid_on`) albo data z akcji w panelu (`wl_notice`, `edelivery_reply_due`) |
| polityka przesunięcia | `op12`, `zus` albo `none` (sekcja niżej) |
| źródło prawne | link do przepisu, a przy regule operacyjnej link do pliku w docs; trafia do `setting.legal_source` przy parametrach |
| warunek | np. `jpk_st.enabled`, sprzedaż `eu_b2b` w miesiącu, dostawca z WHT |
| kwota | z `setting` albo z wyliczenia w [tax-rules.md](tax-rules.md); pusta, dopóki faza jej nie liczy |
| przypomnienia | offsety z sekcji „Przypomnienia” |

**Przebieg**

1. Zadanie `obligations.recompute` codziennie o 6:00 i po każdej zmianie `setting` albo encji źródłowej ([operations.md](operations.md)).
2. Horyzont: `due_date` od dziś (data biznesowa) do dziś + 13 miesięcy. Pierwszy przebieg nie tworzy obowiązków z `due_date` < dziś. Reguły tygodniowe i miesięczne (`manual_channel_check`, `balance_check`) działają w tym samym horyzoncie.
3. Data encji wpisana później (np. `paid_on`) tworzy obowiązek także z `due_date` < dziś. Taki obowiązek jest od razu zaległy.
4. Silnik zapisuje obowiązki przez upsert po `dedupe_key`. Wiadomość Messenger niesie tylko identyfikatory. Ponowny przebieg nie tworzy duplikatów.
5. Zmiana reguły albo parametru przelicza obowiązki `open` oraz `not_applicable` ustawione przez system (pusty `done_by`). Obowiązki `done` zostają bez zmian.
   Wiersze z akcji w panelu (`wl_notice`, `edelivery_reply_due`) silnik tworzy raz, przy akcji. Przeliczenie ich nie zmienia i nie usuwa.
6. Wiersz ma `nominal_due_date` (z przepisu) i `due_date` (po przesunięciu). Przypomnienia liczą się od `due_date`.

**Klucz `dedupe_key`.** Wzorzec: `{type}:{okres}[:{podmiot}]`. Okres to pierwszy dzień miesiąca albo roku; reguły tygodniowe używają daty poniedziałku. Klucze per typ są w tabeli.

| Typ | `dedupe_key` |
| --- | --- |
| okresowe, np. `vat_jpk_v7m`, `zus`, `ift_2r`, `cit_10z` | `{type}:{okres}`, np. `vat_jpk_v7m:2026-11-01` |
| `wht_payment` | F0b: `wht_payment:{okres}`; od F1: `wht_payment:{okres}:{counterparty_id}` |
| `invoice_issue` | F0b: `invoice_issue:{miesiąc usługi}`; od F3: `invoice_issue:{miesiąc usługi}:{invoice_id}` |
| `manual_channel_check` | `manual_channel_check:{poniedziałek}:{kanał}`, np. `manual_channel_check:2026-10-12:ezus`; saldo: `manual_channel_check:{miesiąc sprawdzenia}:balance_check` |
| `credential_expiry` | `credential_expiry:{valid_until}:{kind}`, data `valid_until` w Europe/Warsaw; `auth_data`: `credential_expiry:{rok}:auth_data` |
| `wht_certificate` | `wht_certificate:{wht_cert_valid_until albo data bazowa}:{counterparty_id}` |
| `wl_notice` | `wl_notice:{dzień zlecenia przelewu}:{invoice_id}` |
| `edelivery_reply_due` | `edelivery_reply_due:{data odbioru}:{znak pisma}` |

- Kanały `manual_channel_check`: `ade_private`, `e_us`, `ezus` (co tydzień) i `balance_check` (co miesiąc).
- Klucz z datą ważności daje nowy obowiązek po rotacji poświadczenia albo certyfikatu. Stary zamyka się jako `done` („Stany obowiązku”).
- Znak pisma w kluczu `edelivery_reply_due`: bez spacji na brzegach, wielkie litery. Pismo bez znaku dostaje `brak-{n}`, gdzie n to kolejny numer w dniu odbioru.

### Polityki przesunięcia

Termin w sobotę albo w dzień ustawowo wolny przesuwa się na następny dzień roboczy ([Ordynacja podatkowa, art. 12 § 5, Dz.U. 2026 poz. 622](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf)). Wyjątkiem jest inna reguła w ustawie podatkowej. Od 1.10.2026 przepis mówi o „terminie do wykonania czynności” ([Dz.U. 2026 poz. 846](https://api.sejm.gov.pl/eli/acts/DU/2026/846/text.pdf)).

| Polityka | Reguła | Stosowana do |
| --- | --- | --- |
| `op12` | sobota, niedziela albo święto → następny dzień roboczy | wpłaty i złożenia w urzędzie skarbowym: zaliczka, VAT, JPK, VAT-UE, PIT-28, WHT, IFT-2R, CIT-10Z, zawiadomienie ZAW-NR (`wl_notice`) |
| `zus` | jak `op12`; OP art. 12 stosuje się przez art. 31 [ustawy o systemie ubezpieczeń społecznych](https://api.sejm.gov.pl/eli/acts/DU/2026/199/text.pdf) | składki ZUS, roczne rozliczenie składki zdrowotnej |
| `none` | bez przesunięcia | wystawienie faktury, wpis do ewidencji, oświadczenia o wyborze formy, termin z pisma wpisany przez Ciebie, certyfikaty i poświadczenia, przeglądy kanałów |

- `none` przy czynnościach z ustawy to wybór ostrożny: celem jest data nominalna, nawet jeśli przesunięcie byłoby dopuszczalne.
- Termin z pisma wpisujesz już po ewentualnym przesunięciu. Kokpit go nie przesuwa.

### `PolishCalendar`

Dni wolne według ustawy o dniach wolnych od pracy; 24 grudnia jest dniem wolnym od 2025 r. ([Dz.U. 2025 poz. 296](https://api.sejm.gov.pl/eli/acts/DU/2025/296/text.pdf)).

| Dzień | Reguła | 2026 | 2027 |
| --- | --- | --- | --- |
| Nowy Rok, Trzech Króli | 1.01, 6.01 | 1.01, 6.01 | 1.01, 6.01 |
| Wielkanoc, Poniedziałek Wielkanocny | algorytm daty Wielkanocy, +1 | 5.04, 6.04 | 28.03, 29.03 |
| Święto Pracy, Święto Konstytucji | 1.05, 3.05 | 1.05, 3.05 | 1.05, 3.05 |
| Zielone Świątki | Wielkanoc +49 | 24.05 | 16.05 |
| Boże Ciało | Wielkanoc +60 | 4.06 | 27.05 |
| Wniebowzięcie | 15.08 | 15.08 | 15.08 |
| Wszystkich Świętych, Niepodległości | 1.11, 11.11 | 1.11, 11.11 | 1.11, 11.11 |
| Wigilia, Boże Narodzenie | 24.12, 25.12, 26.12 | 24–26.12 | 24–26.12 |
| każda niedziela | dzień ustawowo wolny | – | – |

Metody: `isWorkingDay(date)` (nie sobota, niedziela ani święto) i `previousWorkingDay(date)` dla `FxRateProvider` ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)).

| Metoda | Data wejściowa | Wynik |
| --- | --- | --- |
| `previousWorkingDay` | 29.12.2025 (poniedziałek) | 23.12.2025 |
| `previousWorkingDay` | 4.01.2027 (poniedziałek) | 31.12.2026 |

Sobota nie jest świętem, ale przesuwa termin według OP art. 12 § 5. Testy jednostkowe obejmują przypadki z tabeli:

| Termin nominalny | Obowiązek | Dzień | Polityka | `due_date` |
| --- | --- | --- | --- | --- |
| 25.10.2026 | JPK_V7M za 09.2026 | niedziela | `op12` | 26.10.2026 |
| 7.11.2026 | WHT za 10.2026 | sobota | `op12` | 9.11.2026 |
| 20.12.2026 | zaliczka i ZUS za 11.2026 | niedziela | `op12`, `zus` | 21.12.2026 |
| 24.12.2026 | termin testowy | czwartek, dzień wolny | `op12` | 28.12.2026 |
| 25.12.2026 | JPK_V7M za 11.2026 | piątek, święto | `op12` | 28.12.2026 |
| 31.01.2027 | CIT-10Z za 2026 | niedziela | `op12` | 1.02.2027 |
| 20.02.2027 | metoda kasowa na 2027 | sobota | `none` | 20.02.2027 |
| 25.04.2027 | JPK_V7M za 03.2027 | niedziela | `op12` | 26.04.2027 |
| 30.04.2027 | JPK_EWP i PIT-28 za 2026 | piątek | `op12` | 30.04.2027 |

## Tabela obowiązków

Kolumna „Od fazy” mówi, kiedy kokpit przypomina, liczy kwotę i wysyła. Wysyłka zawsze przechodzi przez bramkę zatwierdzeń ([approval-gate.md](approval-gate.md)).

| Typ | Termin nominalny | Polityka | Kwota albo czynność | Źródło | Od fazy |
| --- | --- | --- | --- | --- | --- |
| `zus` | 20. dzień miesiąca za poprzedni miesiąc | `zus` | składki społeczne z `zus.parameters` i `zus.variant`; zdrowotna według progu przychodu ([tax-rules.md](tax-rules.md)) | [ustawa o systemie ubezpieczeń społecznych, art. 47 ust. 1 pkt 4, Dz.U. 2026 poz. 199](https://api.sejm.gov.pl/eli/acts/DU/2026/199/text.pdf); kwoty: [ZUS 2026](https://www.zus.pl/en/-/informacja-w-sprawie-podstawy-wymiaru-sk%C5%82adki-oraz-kwoty-sk%C5%82adki-na-ubezpieczenie-zdrowotne-w-2026-r.) | przypomnienie F0b, kwota z konfiguracji F0b, próg zdrowotnej F2 |
| `ryczalt_advance` | 20. dzień za poprzedni miesiąc; za grudzień 20 stycznia; przy wpłatach co kwartał 20. dzień po kwartale | `op12` | zaliczka według wzoru z [tax-rules.md](tax-rules.md); `ryczalt.advance_frequency` | [ustawa o ryczałcie, art. 21 ust. 1, 1a, 1b](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | przypomnienie F0b, kwota F2 |
| `vat_jpk_v7m` | 25. dzień za poprzedni miesiąc | `op12` | JPK_V7M(3) i wpłata P_51 | [ustawa o VAT, art. 99 ust. 1, art. 103 ust. 1, art. 109 ust. 3b](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | przypomnienie F0b, plik F5 |
| `vat_ue` | 25. dzień po miesiącu powstania obowiązku podatkowego | `op12` | VAT-UE(5) dla usług art. 28b na rzecz podatników z UE | [ustawa o VAT, art. 100 ust. 1 pkt 4 i ust. 3](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | przypomnienie F0b, wykrycie miesięcy ze sprzedażą `eu_b2b` F1, wysyłka F5 |
| `invoice_issue` | 15. dzień miesiąca po wykonaniu usługi; dotyczy też usług dla UE i spoza UE | `none` | faktura przyjęta w KSeF | [ustawa o VAT, art. 106i ust. 1 z art. 106a pkt 2](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | przypomnienie ogólne F0b, per szkic (`invoice_id`) F3 |
| `ledger_entry` | 20. dzień za poprzedni miesiąc | `none` | wpisy w ewidencji za miesiąc ([modules/ewidencja.md](modules/ewidencja.md)) | [rozporządzenie, § 6, Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf) | przypomnienie F0b; do tego czasu wpisy w narzędziu z Q-11; zamknięcie przez `period.ledger_closed_at` F2 |
| `wht_payment` | 7. dzień miesiąca po wypłacie dostawcy | `op12` | podatek pobrany od dostawcy zagranicznego | [ustawa o CIT, art. 26 ust. 1 i 3, Dz.U. 2026 poz. 554](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) | przypomnienie F0b, obowiązek per dostawca z `paid_on` F1, kwota F5; wpłata ręczna |
| `wht_certificate` | `counterparty.wht_cert_valid_until`; jeśli data pusta, a `wht_treaty_country` ustawione, data bazowa z „Uwag do wierszy” | `none` | zebranie certyfikatu rezydencji dostawcy (plik `evidence`) | [ustawa o CIT, art. 26 ust. 1](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) | F1 |
| `ift_2r` | koniec marca za poprzedni rok | `op12` | IFT-2R per dostawca, także przy stawce z umowy o unikaniu podwójnego opodatkowania | [ustawa o CIT, art. 26 ust. 3a i 3d](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) | przypomnienie F0b, kwoty F5; składasz ręcznie |
| `cit_10z` | koniec stycznia za poprzedni rok, tylko za rok, w którym powstał obowiązek pobrania podatku | `op12` | CIT-10Z | [ustawa o CIT, art. 26a](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) | przypomnienie F0b, kwota F5; składasz ręcznie |
| `jpk_ewp` | termin zeznania: 30 kwietnia za poprzedni rok | `op12` | JPK_EWP(4) | [ustawa o ryczałcie, art. 15 ust. 12 i art. 21 ust. 2 pkt 2](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | dry run F2.4, przypomnienie F0b, wysyłka F5 |
| `jpk_st` | jak `jpk_ewp` | `op12` | JPK_ST(1), tylko przy `jpk_st.enabled`; inaczej `not_applicable` | [broszura JPK_ST(1)](https://podatki.gov.pl/media/jikpuwwp/broszura_informacyjna_dot_jpk_st-1.pdf), [projekt zwolnienia](https://legislacja.gov.pl/projekt/12413609) | jak `jpk_ewp` |
| `pit_28` | okno 15.02–30.04 za poprzedni rok | `op12` | zeznanie roczne; kokpit daje zestawienie | [ustawa o ryczałcie, art. 21 ust. 2 pkt 2](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | przypomnienie F0b, zestawienie F2; składasz ręcznie |
| `health_reconciliation` | 20 maja za poprzedni rok; za 2026 wylicza się 20.05.2027 | `zus` | roczne rozliczenie składki zdrowotnej; nadpłata przez wniosek RZS-R | [ZUS, roczne rozliczenie](https://www.zus.pl/en/-/roczne-rozliczenie-sk%C5%82adki-zdrowotnej-za-2025-r.) | przypomnienie F0b, kwota F2 |
| `tax_form_choice` | 20 lutego: metoda kasowa; forma opodatkowania do 20. dnia miesiąca po pierwszym przychodzie w roku | `none` | decyzja i oświadczenie na piśmie (Q-24, Q-38) | [ustawa o PIT, art. 14c ust. 1 pkt 3, Dz.U. 2026 poz. 592](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf), [ustawa o ryczałcie, art. 9 ust. 1](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | F0b: 20 lutego, pierwszy termin 20.02.2027; wariant po pierwszym przychodzie F2 |
| `edelivery_reply_due` | termin odpowiedzi z pisma odebranego w aplikacji e-Doręczeń, wpisany przez Ciebie | `none` | odpowiedź na pismo | [modules/e-doreczenia.md](modules/e-doreczenia.md) | F0b: wpis ręczny (D-29) |
| `credential_expiry` | `credential_state.valid_until`; `auth_data`: 2 stycznia | `none` | odnowienie poświadczenia; runbook w [operations.md](operations.md) | [security.md](security.md) | F0b |
| `manual_channel_check` | co tydzień w poniedziałek, jeden obowiązek na kanał; saldo e-US i eZUS raz w miesiącu (`balance_check` w „Uwagach do wierszy”) | `none` | przegląd prywatnego ADE, konta e-US i eZUS; sprawdzenie sald | [modules/e-doreczenia.md](modules/e-doreczenia.md) | F0b |
| `wl_notice` | dzień zlecenia przelewu + 7 dni | `op12` | zawiadomienie ZAW-NR o zapłacie na rachunek spoza wykazu; składasz ręcznie | [Ordynacja podatkowa, art. 117ba § 3](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf); akcja w [modules/rejestry-kursy.md](modules/rejestry-kursy.md) | F1 |

**Uwagi do wierszy**

- `ryczalt_advance`: wpłata co kwartał tylko przy przychodzie z poprzedniego roku do 200 tys. EUR (Q-17, [tax-rules.md](tax-rules.md)).
- `vat_ue`: obowiązek dotyczy miesięcy z transakcjami. Brak pliku za miesiąc bez transakcji podaje źródło wtórne; do weryfikacji w [modules/jpk.md](modules/jpk.md).
- `vat_ue` od F1 do zamknięcia miesiąca z F2: system ustawia `not_applicable` za M, gdy spełnione są oba warunki. Kursor `subject1` (`sync_cursor.last_hwm`) przekracza koniec 15. dnia M+1, czyli termin `invoice_issue`. Brak sprzedaży `eu_b2b` z `tax_point_date` w M. Późna sprzedaż `eu_b2b` z `tax_point_date` w M przywraca `open`.
- `vat_ue` po zamknięciu miesiąca z F2: system ustawia `not_applicable` za M dopiero po bramkach kompletności z kroku 4 zamknięcia M, nie z końcem M.
- `vat_ue`: sprzedaż `eu_b2b_unidentified` nie tworzy obowiązku, bo VAT-UE obejmuje tylko nabywców zidentyfikowanych (art. 100 ust. 1 pkt 4 [ustawy o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Scenariusz czeka na potwierdzenie doradcy (Q-29, [tax-rules.md](tax-rules.md)).
- WHT w F0b: `wht_payment` (`wht_payment:{okres}`), `ift_2r` i `cit_10z` to przypomnienia ogólne bez podmiotu i kwoty (Q-21).
- WHT od F1: reguła `wht_payment` per dostawca zastępuje ogólną. Ogólne `open` z `due_date` ≥ go-live F1 przechodzą w systemowe `not_applicable`.
- WHT od F1 dotyczy dostawców z `counterparty.is_natural_person` = false. Dla zagranicznej osoby fizycznej reguły brak do odpowiedzi na Q-21: art. 29 ustawy o PIT, do weryfikacji ([tax-rules.md](tax-rules.md)).
- `cit_10z`: tylko za rok, w którym powstał obowiązek pobrania podatku. F0b przypomina co roku. Od F1 warunek: w roku jest obowiązek `wht_payment` per dostawca.
- `cit_10z` od F5: warunek to suma `amount_due` obowiązków `wht_payment` w roku > 0.
- `wht_certificate`: bez `wht_cert_valid_until`, a z `wht_treaty_country`, data bazowa to najwcześniejszy `invoice.paid_on` dostawcy bez ważnego certyfikatu. Bez wypłaty: dzień ustawienia `wht_treaty_country` (z `audit_log`). Przeliczenie nie zmienia tej daty.
- `credential_expiry`: obowiązek tworzy `obligations.recompute` po rejestracji poświadczenia statycznego. `credentials.check_expiry` zmienia tylko stan w `credential_state`. Rodzaje poświadczeń są w [data-model.md](data-model.md).
- `auth_data`: data bazowa to stały 2 stycznia każdego roku, niezależnie od `valid_until` (jak w [operations.md](operations.md)). Nowa kwota N−2 obowiązuje od 1 stycznia; wcześniejsza zmiana psuje grudniowe złożenia. Przypomnienia: T0 i zaległość, bez T−30.
- `tax_form_choice`: F0b tworzy jeden obowiązek rocznie na 20 lutego. Wariant „20. dzień miesiąca po pierwszym przychodzie w roku” działa od F2.
- `manual_channel_check`: kanały i ich obsługa są w [modules/e-doreczenia.md](modules/e-doreczenia.md) (Q-13).
- `balance_check`: termin to następny dzień roboczy po `due_date` obowiązku `vat_jpk_v7m` za poprzedni miesiąc. Polityka `none`, przypomnienie T0. Jeden obowiązek obejmuje oba salda: e-US i eZUS.
- `edelivery_reply_due`: po odbiorze pisma w aplikacji e-Doręczeń wpisujesz w panelu datę odbioru, znak pisma i termin odpowiedzi. Wpis daje `edelivery.deadline_confirmed` w `audit_log`.
- `edelivery_reply_due`: bez API kalendarz nie liczy terminów z pism (D-29). Termin względny, np. „14 dni od doręczenia”, liczysz sam według pisma ([modules/e-doreczenia.md](modules/e-doreczenia.md)).
- `edelivery_deemed` jest odłożony (D-29). Bez API kalendarz nie zna daty fikcji doręczenia; chronią Cię powiadomienia mailowe i odbiór w aplikacji ([modules/e-doreczenia.md](modules/e-doreczenia.md)).
- Odłożony projekt terminów z API F4: [modules/e-doreczenia.md](modules/e-doreczenia.md); warunek powrotu: [roadmap.md](roadmap.md).
- `wl_notice`: obowiązek tworzy akcja „Sprawdź przed przelewem”. Przy wyniku `NIE` wybierasz zawiadomienie i podajesz dzień zlecenia ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)).
- `wl_notice`: `invoice_id` wskazuje fakturę kosztową. Obowiązek zamyka „Potwierdź wykonanie” po złożeniu ZAW-NR.

## Przypomnienia

Przypomnienie to wiersz `notification`. Kategoria to `obligation.type`, a przy prośbie zatwierdzenia `approval_pending`. `dedupe_key` łączy przyczynę (obowiązek albo `approval_id`), offset i kanał, więc przebieg nadrabiający po awarii niczego nie dubluje ([data-model.md](data-model.md)).

| Typ | Offsety (T = `due_date`) | Kanał |
| --- | --- | --- |
| domyślnie (wpłaty i złożenia) | T−7, T−3, T−1, T0 | push |
| `zus` | T−3, T−1, T0 | push |
| `invoice_issue`, `ledger_entry` | T−5, T−1 | push |
| `tax_form_choice` | T−30, T−7, T−1 | push i mail |
| `credential_expiry` | T−30, T−7, T−1; dla `auth_data` tylko T0 | push i mail |
| `wht_certificate` | T−30, T−7, T0 | push |
| `manual_channel_check` | T0 | push |
| `edelivery_reply_due` | T−7, T−3, T−1, T0 | push |
| `wl_notice` | T−3, T−1, T0 | push |
| `approval_pending` | 24 h po `requested_at`; w dniu `due_date` powiązanego obowiązku 8:00 i 15:00 | push |
| zaległy obowiązek | codziennie od T+1 do zamknięcia | push |

- `notifications.dispatch` tworzy wiersz, gdy offset nadszedł (8:00 Europe/Warsaw) i obowiązek jest `open` ([operations.md](operations.md)). Zapis idzie przez `INSERT … ON CONFLICT (dedupe_key) DO NOTHING`. Potem zadanie wysyła wiersze bez `sent_at`.
- Zamknięty obowiązek nie dostaje nowych wierszy, a jego niewysłane wiersze zadanie pomija.
- `dedupe_key` wiersza: `{obligation_id}:{offset}:{channel}`, np. `{obligation_id}:T-3:push`. Zaległość: `{obligation_id}:overdue:{data}:{channel}`. Prośba: `{approval_id}:{24h|due_am|due_pm}:{channel}`.
- Treść push to kategoria, liczba i link do panelu, bez kwot i nazw ([security.md](security.md)).
- Powstanie prośby zatwierdzenia i jej pierwszy push opisuje [approval-gate.md](approval-gate.md). Tu są terminy przypomnień i deduplikacja.

## Tytuły przelewów

`obligation.payment_title` powstaje z szablonu reguły. Rachunki są w `setting`: `company.tax_micro_account` i `company.zus_account`. Mikrorachunek generujesz raz w [generatorze MF](https://www.podatki.gov.pl/generator-mikrorachunku-podatkowego); nie zmienia się.

Szablon to `{symbol} {okres}`. Tytuł nie zawiera numeru rachunku; panel pokazuje rachunek z `setting` (D-39). Okres ma format `RRTNN`: `RR` to dwie ostatnie cyfry roku, `T` typ okresu (`M` miesiąc, `K` kwartał, `R` rok), `NN` numer miesiąca albo kwartału. Rok nie ma numeru. Szablony i symbole są założeniem do weryfikacji ([facts.md](facts.md)).

| Obowiązek | Rachunek | Szablon tytułu (do weryfikacji) |
| --- | --- | --- |
| `ryczalt_advance` | mikrorachunek podatkowy | `PPE {okres}`: `PPE 26M10` przy wpłacie miesięcznej, `PPE 26K04` przy kwartalnej ([podatki.gov.pl](https://www.podatki.gov.pl/mikrorachunek-podatkowy)) |
| dopłata z PIT-28 | mikrorachunek podatkowy | `PIT-28 {okres}`, np. `PIT-28 26R` ([podatki.gov.pl](https://www.podatki.gov.pl/mikrorachunek-podatkowy)) |
| `vat_jpk_v7m` | mikrorachunek podatkowy | `VAT-7 {okres}`, np. `VAT-7 26M10`; symbol do weryfikacji |
| `wht_payment` | rachunek i symbol do weryfikacji; zakładany mikrorachunek z symbolem CIT-10Z | `CIT-10Z {okres}` z miesiącem wypłaty, np. `CIT-10Z 26M11` |
| `zus` | rachunek składkowy | rachunek indywidualny; kwota łączna; `Składki ZUS {okres}`, np. `Składki ZUS 26M12`, bez numeru rachunku (D-39) |

- Format okresu w tytule sprawdzasz przy pierwszej wpłacie w generatorze albo w banku. Wynik trafia do [facts.md](facts.md).
- Mikrorachunek przyjmuje wpłaty PIT, CIT i VAT ([podatki.gov.pl](https://www.podatki.gov.pl/mikrorachunek-podatkowy)).

**Numer KSeF w przelewie do dostawcy.** Numer KSeF w tytule przelewu do dostawcy od 1.01.2027 nie jest obowiązkiem w kalendarzu. Regułę i akcję „Sprawdź przed przelewem” opisuje [modules/rejestry-kursy.md](modules/rejestry-kursy.md).

## Zamknięcie miesiąca

Kolejność zamknięcia miesiąca M w miesiącu M+1. Wynik bramek kompletności trafia do `period.checklist`.

| Krok | Do dnia M+1 | Czynność | Bramka |
| --- | --- | --- | --- |
| 1 | 7. | wpłata WHT za wypłaty z M | dostawcy z kategorią WHT mają decyzję (Q-21) |
| 2 | 15. | faktury za usługi z M wystawione i przyjęte w KSeF | brak faktur sprzedaży z M w stanach innych niż `accepted`, `corrected`, `superseded`, `cancelled_before_send` |
| 3 | 20. | ewidencja M kompletna, zaliczka i ZUS zapłacone | `period.ledger_closed_at` ustawione; `zus_payment` wpisane |
| 4 | przed budową JPK | bramki kompletności | lista niżej |
| 5 | 25. | JPK_V7M i VAT-UE przez bramkę; wpłata VAT | `approval` przyjęte albo złożenie `manual` z UPO |
| 6 | po przyjęciu | okres `closed`, obowiązki `done`, sprawdzenie sald e-US i eZUS | `closed_by_filing_id` ustawione |

**Bramki kompletności przed budową JPK_V7M**

- Wszystkie faktury sprzedaży z `vat_period_start` = M są `accepted`, `corrected`, `superseded` albo `cancelled_before_send`.
- Żaden koszt z `issue_date` albo datą otrzymania (`ksef_number_assigned_at`, `received_on`) do końca M nie jest w stanie `imported`. Koszt `business` z tego zakresu jest `assigned` ([data-model.md](data-model.md)).
- Każdy dokument w walucie ma kurs VAT; sprzedaż ma też kurs PIT ([tax-rules.md](tax-rules.md)).
- Sprzedaż `eu_b2b` ma ważny wynik VIES przy zatwierdzeniu. Sprzedaż spoza kokpitu (Subject1): wiersz `verification` VIES `valid` z `checked_for_date` ≤ dzień wystawienia albo `manual_evidence`.
- Sprzedaż `eu_b2b_unidentified` ma `manual_evidence` statusu przedsiębiorcy i scenariusz potwierdzony przez doradcę (Q-29, [tax-rules.md](tax-rules.md)).
- Lista pozycji ze znacznikiem DI jest pokazana przed zatwierdzeniem ([modules/jpk.md](modules/jpk.md)).
- Pierwszy miesiąc w kokpicie wymaga kluczy `opening.*` potwierdzonych ze step-up („Bilans otwarcia”).

Przed go-live F5 kokpit tylko liczy i porównuje. JPK_V7M składasz w narzędziu MF i wgrywasz UPO jako złożenie `manual`, które zamyka okres. Złożenie `manual` JPK_V7M wymaga też `xml_sent` albo P_62 wpisanego ze step-up do `filing.totals`. To źródło P_39 następnego miesiąca.

**Bramki przed budową JPK_EWP(4)**

Bramki działają przy prośbie zatwierdzenia ([approval-gate.md](approval-gate.md)). Dry run z F2.4 nie tworzy prośby, więc ich nie wymaga.

- 12 okresów roku ma `period.ledger_closed_at`.
- Każda sprzedaż w walucie ma kurs PIT ([tax-rules.md](tax-rules.md)). Wiersz `import` niesie K_8 z narzędzia źródłowego (D-30).
- Żadna faktura sprzedaży z roku nie jest w stanie innym niż `accepted`, `corrected`, `superseded` albo `cancelled_before_send`.

JPK_EWP(4) powstaje tylko z `revenue_entry`, więc nie czeka na bilans otwarcia. Bilans potwierdzasz w PROD przy go-live JPK_V7M(3), czyli po JPK_EWP ([operations.md](operations.md)).

## Blokada okresu

Okres to miesiąc z kluczem `period_start`. Stan zmienia się według tabeli.

| Z | Do | Wyzwalacz | Aktor |
| --- | --- | --- | --- |
| – | `open` | pierwszy zapis z datą w M albo przebieg silnika | system |
| `open` | `closed` | przyjęty JPK_V7M za M albo złożenie `manual` z UPO (`closed_by_filing_id`) | system |
| `closed` | `reopened` | ponowne otwarcie z powodem w `reopen_reason`; step-up | Ty |
| `reopened` | `closed` | przyjęta korekta JPK_V7M za M | system |
| `reopened` | `closed` | ponowne zamknięcie bez korekty JPK_V7M, z powodem w `audit_log`; step-up | Ty |

**Co blokuje stan `closed`**

- Pola VAT faktur z M i `vat_period_start` = M, łącznie z kursem VAT.
- Przypisanie kosztów do M. Zapis kosztu do zamkniętego M jest odrzucany. System proponuje najwcześniejszy otwarty okres dozwolony przez prawo do odliczenia, a przypisujesz Ty.
- `zus_payment` za M i wiersze `setting` użyte przez M.
- `revenue_entry` jest tylko dopisywana, więc blokada jej nie dotyczy. Nowy wiersz z `revenue_date` w M ustawia jednak `correction_required_at`.

**Korekta wymagana.** Zapis, który zmienia dane zamkniętego M, ustawia `period.correction_required_at`. Panel pokazuje zadanie „korekta wymagana”, dopóki `correction_required_at` jest późniejsze niż `closed_at`. Zadanie nie jest obowiązkiem w kalendarzu.

| Wyzwalacz | Wskazane skutki |
| --- | --- |
| przyjęta KOR z okresem w M (TypKorekty, [modules/ksef.md](modules/ksef.md)) | korekta JPK_V7M; VAT-UEK przy `eu_b2b`; wiersz korekty w ewidencji |
| edycja faktury we FreshBooks po przyjęciu | szkic KOR; dalej jak wyżej |
| poprawka kursu albo stawki ryczałtu | korekta JPK_V7M albo różnica zaliczki |
| numer KSeF dla pozycji ze znacznikiem DI | korekta JPK_V7M ([modules/jpk.md](modules/jpk.md)) |
| późny wpis przychodu z `revenue_date` w M | przeliczenie zaliczki za M |

## Bilans otwarcia

Jednorazowy formularz „Otwarcie”: w F2 na danych fikcyjnych, w PROD przy go-live JPK_V7M(3) w F5 ([operations.md](operations.md)). Bilans to klucze `setting`, bez osobnej tabeli (D-29). Każdy klucz ma `valid_from` = data przejścia i dowód w `stored_file` rodzaju `evidence`.

| Krok | Klucz albo czynność | Wartość | Dowód |
| --- | --- | --- | --- |
| 1 | `valid_from` kluczy z kroków 2–5 | data przejścia: pierwszy dzień miesiąca, od którego kokpit liczy VAT | – |
| 2 | `opening.vat_carry_forward_pln` | nadwyżka VAT do przeniesienia (P_62) z ostatniego JPK_V7M złożonego poza kokpitem; po korekcie (R-18) z tej korekty | UPO i plik tego JPK |
| 3 | `opening.revenue_ytd` | przychód narastająco per stawka ryczałtu | ewidencja z F2 albo z Twojego narzędzia ([modules/ewidencja.md](modules/ewidencja.md)) |
| 4 | `opening.zus_paid_ytd` | składki społeczne i zdrowotna zapłacone w roku, osobno | zestawienie z eZUS |
| 5 | `opening.last_invoice_numbers` | ostatni numer faktury per seria; chroni przed kolizją P_2 ([modules/ksef.md](modules/ksef.md)) | lista z Aplikacji Podatnika KSeF |
| 6 | `sync_cursor`, `obligation` | kursory startowe; obowiązki już zapłacone jako `done` | – |
| 7 | „Potwierdź” | zapis kluczy z kroków 2–5 w jednej transakcji, ze step-up | wpis `opening_balance.confirmed` w `audit_log` |

- Kwoty w wartościach JSON są napisami dziesiętnymi, nigdy liczbą JSON ([data-model.md](data-model.md)).
- Po potwierdzeniu zwykły zapis kluczy `opening.*` jest odrzucany. Poprawka to nowe potwierdzenie ze step-up, z powodem w `audit_log`.
- Po zamknięciu pierwszego okresu kokpitu poprawka wymaga jego ponownego otwarcia („Blokada okresu”). Mechanizm blokady i właściciel plików dowodu: [data-model.md](data-model.md).
- `opening.revenue_ytd` zasila próg składki zdrowotnej i metrykę limitu ryczałtu na panelu (Q-38).
- Gdy `revenue_entry` obejmuje rok od stycznia (import z F2.1), krok 3 porównuje sumy. Różnica to alert, nie nadpisanie.
- P_39 pierwszego JPK_V7M z kokpitu za M = P_62 ostatniego przyjętego JPK_V7M za M−1, także złożenia `manual` ([modules/jpk.md](modules/jpk.md)). `opening.vat_carry_forward_pln` tylko wtedy, gdy po dacie przejścia nie ma złożenia.

## Stany obowiązku

| Z | Do | Wyzwalacz | Aktor |
| --- | --- | --- | --- |
| – | `open` | przebieg `ObligationEngine` | system |
| `open` | `done` | przyjęte złożenie (`filing_id`) i zapłata, gdy jest kwota | system, Ty |
| `open` | `done` | wpisana zapłata (`amount_paid`, `paid_on`) albo „Potwierdź wykonanie” z wpisem `obligation.completed`, np. `manual_channel_check`, `wl_notice`, `edelivery_reply_due`; przy `zus` tylko do F2 | Ty |
| `open` | `done` | od F2: wpis `zus_payment` z `obligation_id`; kokpit kopiuje sumę kwot wiersza i `paid_on` do `amount_paid` i `paid_on` obowiązku (jedno miejsce wpisu) | Ty |
| `open` | `done` | zdarzenie źródłowe reguły: `period.ledger_closed_at` (`ledger_entry`), faktura `accepted` (`invoice_issue` z `invoice_id`), nowe `credential_state.valid_until` (`credential_expiry`), nowe `wht_cert_valid_until` (`wht_certificate`) | system |
| `open` | `not_applicable` | warunek reguły niespełniony, np. brak sprzedaży `eu_b2b`, `jpk_st.enabled` wyłączone, ogólne WHT zastąpione regułą per dostawca | system |
| `open` | `not_applicable` | wyłączenie z powodem zapisanym w `audit_log` | Ty |
| `not_applicable` | `open` | warunek reguły znów spełniony (zmiana `setting`, późna sprzedaż `eu_b2b`, KOR); tylko przy pustym `done_by` | system |

`done` jest końcowy; `not_applicable` ustawiony przez Ciebie też. Obowiązek z kwotą do zapłaty zamyka się dopiero po wpisaniu zapłaty.
