# Fakty, obserwacja prawa i ryzyka

Fakty prawne i techniczne z datą weryfikacji, obserwacja zmian prawa, rejestr ryzyk i źródła kokpitu. Stan na 8 października 2026.

## Jak czytać

- Krok 0 fazy: filtrujesz tabele po kolumnie „Fazy” i sprawdzasz wiersze, których „Następne sprawdzenie” już minęło. Części F2.1–F2.4 (D-30) czytają wiersze F2.
- Wiersze F4 i F7 dotyczą modułów odłożonych (D-29). Sprawdzasz je przy powrocie modułu ([roadmap.md](roadmap.md)).
- Po sprawdzeniu wpisujesz nową datę w kolumnie „Sprawdzono”. Zmieniony fakt poprawiasz tutaj i w pliku-domu tematu.
- `2026-10-08`: fakt sprawdzony w źródle w dniu podziału specyfikacji. Starsza data to dzień ostatniego sprawdzenia.
- `2026-10-05` bez dopisku: fakt przejęty z [archive/SPEC-2026-10-05.md](archive/SPEC-2026-10-05.md), bez ponownej weryfikacji.
- Reguły i mechanika są w plikach-domach z [README.md](README.md). Ten plik trzyma tylko fakt, źródło i datę.
- Pytania Q-xx i decyzje D-xx są w [decisions.md](decisions.md).

## Akty prawne

Stan każdego aktu sprawdzony w API ELI Sejmu 8.10.2026. Przed użyciem przepisu w fazie sprawdzasz, czy nowszy tekst jednolity nie zastąpił publikatora. Sprawdzasz też akty zmieniające ogłoszone po tekście jednolitym (ELI „Akty zmieniające”). Kolumna „Uwagi” wymienia te akty i ich wpływ na przepisy cytowane w tym pliku.

| Akt | Publikator | Stan na 2026-10-08 | Uwagi |
| --- | --- | --- | --- |
| ustawa o VAT | [Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | obowiązujący tekst jednolity, stan prawny 26.08.2026 | Dz.U. 2025 poz. 775 to nieaktualny tekst jednolity; akt zmieniający po tekście: [Dz.U. 2026 poz. 1270](https://api.sejm.gov.pl/eli/acts/DU/2026/1270/text.pdf); skutki w rejestrze faktów i w „Obserwacji prawa” |
| ustawa o PIT | [Dz.U. 2026 poz. 592](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) | obowiązujący tekst jednolity | Dz.U. 2025 poz. 163 nieaktualny; akty zmieniające po tekście: Dz.U. 2026 poz. 779, 846, 1079, 1098, 1123 i 1287; bez wpływu na przepisy z tego pliku |
| ustawa o ryczałcie | [Dz.U. 2025 poz. 843](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | obowiązujący tekst jednolity | zmiany poza tekstem: [Dz.U. 2026 poz. 779](https://api.sejm.gov.pl/eli/acts/DU/2026/779/text.pdf) (art. 15 ust. 13a, od 16.06.2026) i [Dz.U. 2026 poz. 1098](https://api.sejm.gov.pl/eli/acts/DU/2026/1098/text.pdf) od 1.01.2027 (osobiste konta inwestycyjne; bez wpływu na ewidencję) |
| ustawa o CIT | [Dz.U. 2026 poz. 554](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) | obowiązujący tekst jednolity | akty zmieniające po tekście: Dz.U. 2026 poz. 779, 846, 912, 1123 i 1287; bez wpływu na przepisy z tego pliku |
| Ordynacja podatkowa | [Dz.U. 2026 poz. 622](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf) | obowiązujący tekst jednolity | zmiana z [Dz.U. 2026 poz. 846](https://api.sejm.gov.pl/eli/acts/DU/2026/846/text.pdf) od 1.10.2026 (art. 12 § 5). Inne akty zmieniające po tekście: Dz.U. 2026 poz. 825, 875, 1098, 1154 i 1206. Poza art. 12 § 5 bez wpływu na przepisy z tego pliku |
| ustawa o doręczeniach elektronicznych | [Dz.U. 2026 poz. 3](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf) | obowiązujący tekst jednolity | akt zmieniający po tekście: Dz.U. 2026 poz. 507, art. 10 (brzmienie art. 152 ust. 1–2, od 14.10.2026); art. 152 ust. 3 bez zmian |
| ustawa o KAS | [Dz.U. 2025 poz. 1131](https://api.sejm.gov.pl/eli/acts/DU/2025/1131/text.pdf) | obowiązujący tekst jednolity | akty zmieniające po tekście: Dz.U. 2025 poz. 1423, 1820 i 1863, Dz.U. 2026 poz. 415, 483, 846, 1098 i 1281; art. 35e bez zmian |
| ustawa o systemie ubezpieczeń społecznych | [Dz.U. 2026 poz. 199](https://api.sejm.gov.pl/eli/acts/DU/2026/199/text.pdf) | obowiązujący tekst jednolity | akty zmieniające po tekście: Dz.U. 2026 poz. 252, 426, 473, 507 i 734; bez wpływu na przepisy z tego pliku |
| ustawa o świadczeniach opieki zdrowotnej | [Dz.U. 2025 poz. 1461](https://eli.gov.pl/api/acts/DU/2025/1461/text/T/D20251461L.pdf) | obowiązujący tekst jednolity | akty zmieniające po tekście: Dz.U. 2025 poz. 1537 i 1739, Dz.U. 2026 poz. 26, 203, 791, 972, 986 i 1007; art. 81 bez zmian |
| Prawo przedsiębiorców | [Dz.U. 2025 poz. 1480](https://api.sejm.gov.pl/eli/acts/DU/2025/1480/text.pdf) | obowiązujący tekst jednolity | akty zmieniające po tekście: Dz.U. 2025 poz. 1795 i 1826, Dz.U. 2026 poz. 507; art. 19 bez zmian |
| ustawa o dniach wolnych od pracy | [Dz.U. 2025 poz. 296](https://api.sejm.gov.pl/eli/acts/DU/2025/296/text.pdf) | obowiązujący tekst jednolity | bez aktów zmieniających po tekście |
| ustawa z 5.08.2025 zmieniająca terminy KSeF (art. 145m, 145n) | [Dz.U. 2025 poz. 1203](https://api.sejm.gov.pl/eli/acts/DU/2025/1203/text.pdf) | obowiązująca | bez aktów zmieniających |
| rozporządzenie o korzystaniu z KSeF | [Dz.U. 2025 poz. 1815](https://api.sejm.gov.pl/eli/acts/DU/2025/1815) | obowiązujące od 1.02.2026 | jedyna zmiana: [Dz.U. 2026 poz. 169](https://api.sejm.gov.pl/eli/acts/DU/2026/169/text.pdf) |
| rozporządzenie o ewidencji przychodów i wykazie | [Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf) | obowiązujące od 1.01.2026 | bez aktów zmieniających |

## Rejestr faktów

### VAT

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| Wystawianie faktur ustrukturyzowanych w KSeF obowiązuje od 1.04.2026; odbiór od 1.02.2026 | [Dz.U. 2025 poz. 1203](https://api.sejm.gov.pl/eli/acts/DU/2025/1203/text.pdf), [MF](https://ksef.podatki.gov.pl/ponizej-10-000-zl/) | 2026-10-08 | krok 0 F3 | teraz, F1, F3 |
| Do 31.12.2026 wolno wystawiać poza KSeF, gdy sprzedaż brutto na fakturach z miesiąca nie przekracza 10 000 zł (art. 145m ust. 1) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), [MF](https://ksef.podatki.gov.pl/ponizej-10-000-zl/) | 2026-10-08 | Q-10 | teraz, F1 |
| Prawo do wystawiania poza KSeF traci się od faktury, którą przekroczono limit, trwale do 31.12.2026. Nie wraca w kolejnym miesiącu (art. 145m ust. 2, MF) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf), [MF](https://ksef.podatki.gov.pl/ponizej-10-000-zl/) | 2026-10-08 | Q-10 | teraz, F1 |
| Usługi B2B dla nabywców zagranicznych też podlegają fakturowaniu w KSeF | [MF, pytania i odpowiedzi](https://ksef.podatki.gov.pl/pytania-i-odpowiedzi-ksef-20/), [ustawa o VAT, art. 106gb ust. 4](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F3 | teraz, F3 |
| Do 31.12.2026 faktury z kas rejestrujących i paragony z NIP mogą powstawać poza KSeF (art. 145n) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F1 | F1 |
| Faktura ustrukturyzowana jest wystawiona w dniu przesłania do KSeF (art. 106na ust. 1); faktura kosztowa jest otrzymana w dniu nadania numeru KSeF (ust. 3) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F1 | F1, F3 |
| Faktura przekazana nabywcy poza KSeF ma kod QR; nabywca zagraniczny dostaje ją w uzgodniony sposób (art. 106gb ust. 4 i 5) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F3 | F3 |
| Fakturę wystawiasz najpóźniej 15. dnia miesiąca po wykonaniu usługi, także dla usług z art. 106a pkt 2 (art. 106i ust. 1) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F0b | F0b, F3 |
| Kary z art. 106ni ust. 1–3 i 5–7 wchodzą 1.01.2027 (art. 23 pkt 4 ustawy z 16.06.2023, przytoczony w obwieszczeniu tekstu jednolitego) | [ustawa o VAT, obwieszczenie](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | 2026-12-01 | F3 |
| Od 1.01.2027 nabywca czynny w VAT płacący poleceniem przelewu albo instrumentem z tytułem transferu podaje numer KSeF albo identyfikator zbiorczy (art. 108g ust. 1; art. 17 ustawy z 16.06.2023) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | 2026-12-01 | F1, F3 |
| Art. 108g: przy poleceniu zapłaty obowiązek ma wystawca (ust. 2); status odbiorcy według wykazu na dzień płatności (ust. 3); według brzmienia ust. 1 płatność kartą bez tytułu transferu nie jest objęta | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F3 | F3 |
| KSeF przechowuje faktury 10 lat od końca roku wystawienia (art. 112aa); faktury spoza KSeF przechowujesz w kraju albo elektronicznie z dostępem on-line dla organów (art. 112a ust. 2–3) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | przed F0a (D-18) | F0a |
| Kurs VAT: dzień roboczy przed obowiązkiem podatkowym (art. 31a ust. 1) albo przed wystawieniem faktury wystawionej wcześniej (ust. 2); kurs EBC dopuszczalny (ust. 1) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F1 | F1, F3 |
| Wybór zasad PIT dla kursu VAT wiąże co najmniej 12 miesięcy (art. 31a ust. 2a–2c) i nie obejmuje importu usług (ust. 2d); korekta po kursie pierwotnym (art. 31b ust. 1) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F3 | F3 |
| Import usług: art. 2 pkt 9, art. 17 ust. 1 pkt 4, art. 28b; odliczenie w rozliczeniu, w którym wykazujesz podatek należny (art. 86 ust. 10b pkt 3) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F1 | teraz, F1, F5 |
| Zapłata przed wykonaniem usługi: obowiązek podatkowy powstaje z chwilą jej otrzymania, w odniesieniu do otrzymanej kwoty (art. 19a ust. 8). Wyjątek dla usług z ust. 5 pkt 4 (m.in. telekomunikacyjnych, najmu) nie obejmuje importu usług z art. 28b | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | przegląd doradcy (Q-21) | teraz, F1, F5 |
| VAT per stawka liczysz od sumy netto pozycji, do grosza (art. 106e ust. 11) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F3 | F3 |
| JPK_V7M i wpłata VAT do 25. dnia za poprzedni miesiąc (art. 99 ust. 1, art. 103 ust. 1, art. 109 ust. 3b) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F5 | teraz, F0b, F5 |
| VAT-UE składa zarejestrowany podatnik VAT UE (art. 97 ust. 3) za miesiące z usługami z art. 28b. Termin to 25. dzień, tylko elektronicznie (art. 100 ust. 1 pkt 4, ust. 3, 8, 11). Korektę składasz niezwłocznie (art. 101) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | krok 0 F5 | teraz, F0b, F1, F5 |
| Dla miejsca świadczenia podatnikiem jest podmiot samodzielnie prowadzący działalność gospodarczą (art. 28a pkt 1 lit. a), bez warunku rejestracji VAT-UE. VAT-UE obejmuje usługi z art. 28b tylko dla nabywców zidentyfikowanych na potrzeby VAT (art. 100 ust. 1 pkt 4) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | przegląd doradcy (Q-29) | F1, F3, F5 |
| Sprzedaż konsumentom zmienia miejsce świadczenia (art. 28c), próg usług elektronicznych 10 000 euro (art. 28k) i OSS (art. 130b); poza zakresem (D-05) | [ustawa o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) | 2026-10-08 | przy pierwszym kliencie konsumencie | F3 |
| Zapłata na rachunek spoza wykazu daje solidarną odpowiedzialność (OP art. 117ba § 1); wyjątki: transakcja do 15 000 zł, zawiadomienie urzędu w 7 dni, podzielona płatność, import usług (§ 3) | [Ordynacja podatkowa](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf), [Prawo przedsiębiorców, art. 19](https://api.sejm.gov.pl/eli/acts/DU/2025/1480/text.pdf) | 2026-10-08 | krok 0 F1 | F1 |
| Od 1.01.2027 art. 105a obejmuje też usługi z nowego załącznika nr 16; wyjątek m.in. dla faktury poniżej 15 000 zł, chyba że usługi od jednego dostawcy przekroczą 50 000 zł netto w miesiącu | [Dz.U. 2026 poz. 1270](https://api.sejm.gov.pl/eli/acts/DU/2026/1270/text.pdf) | 2026-10-08 | krok 0 F1 | F1, F5 |

### Ryczałt, PIT i ewidencja

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| Podatnik składający JPK_V7M prowadzi ewidencję przychodów w programie od 1.01.2026 i przesyła ją jako JPK_EWP (art. 15 ust. 12) | [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf), [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf) | 2026-10-08 | krok 0 F2 | teraz, F2 |
| Wpis do ewidencji najpóźniej 20. dnia za poprzedni miesiąc (§ 6) | [rozporządzenie, Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf) | 2026-10-08 | krok 0 F2 | teraz, F0b, F2 |
| Ewidencja jest rzetelna mimo braków uzupełnionych przed kontrolą albo przy błędach do 0,5% przychodu (§ 3 ust. 4 pkt 1 i 3) | [rozporządzenie](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf) | 2026-10-08 | przegląd doradcy (D-23) | F2 |
| Zakres danych wykazu ŚT i WNiP określa § 8; wpis najpóźniej w miesiącu przyjęcia do używania (§ 8 ust. 3) | [rozporządzenie](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf) | 2026-10-08 | krok 0 F2 | F2 |
| JPK_EWP i JPK_ST składasz do terminu zeznania, 30 kwietnia (art. 21 ust. 2 pkt 2); za 2026 to piątek 30.04.2027 | [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf), [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf) | 2026-10-08 | krok 0 F5 | F0b, F2, F5 |
| Inny okres rozliczenia VAT albo zwolnienie z VAT przesunęłyby pierwszy JPK_EWP na plik za 2027, składany w 2028 | [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf) | 2026-10-08 | – (D-02) | F2 |
| Dz.U. 2026 poz. 779 (od 1.07.2026) przedłuża terminy JPK za 2026 tylko dla ksiąg rachunkowych; nowy art. 15 ust. 13a (od 16.06.2026) pozwala podpisać JPK_EWP na UPL-1 | [Dz.U. 2026 poz. 779](https://api.sejm.gov.pl/eli/acts/DU/2026/779/text.pdf) | 2026-10-08 | krok 0 F5 | F2, F5 |
| Dz.U. 2026 poz. 1098 (od 1.01.2027) zmienia w ustawie o ryczałcie tylko art. 21c (PIT-28 udostępniany w e-US) i uchyla art. 21d (korekta zeznania przez organ). Ewidencja 2026, stawki i prawo do ryczałtu bez zmian | [Dz.U. 2026 poz. 1098, art. 37](https://api.sejm.gov.pl/eli/acts/DU/2026/1098/text.pdf) | 2026-10-08 | krok 0 F2 | F2 |
| Prawo do ryczałtu: przychód z poprzedniego roku do 2 000 000 euro (art. 6 ust. 4); progi w euro po kursie NBP z pierwszego dnia roboczego października roku poprzedniego (art. 4 ust. 2) | [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | 2026-10-08 | 2026-11-30 (UD458) | F2 |
| Stawki z art. 12 ust. 1 według PKWiU 2015 (art. 4 ust. 1 pkt 1): 17%, 15%, 14%, 12% i 8,5%; zakresy w [tax-rules.md](tax-rules.md) | [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | 2026-10-08 | przegląd doradcy (Q-22) | F2, F3 |
| Od zaliczki odliczasz zapłacone składki społeczne (art. 11 ust. 1) i 50% zdrowotnej (ust. 1a); przy kilku stawkach w proporcji przychodów (ust. 3) | [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | 2026-10-08 | krok 0 F2 | F2 |
| Zaliczka do 20. dnia za poprzedni miesiąc, za grudzień do 20 stycznia (art. 21 ust. 1). Co kwartał tylko przy przychodzie do 200 000 euro albo na starcie (ust. 1a, 1b). Wybór w zeznaniu (ust. 1c) | [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | 2026-10-08 | krok 0 F0b | teraz, F0b, F2 |
| Przy wpłatach co kwartał K_10 JPK_EWP może zawierać datę otrzymania przychodu | [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf) | 2026-10-08 | krok 0 F2 | F2 |
| PIT-28 składasz od 15 lutego do 30 kwietnia (art. 21 ust. 2 pkt 2) | [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | 2026-10-08 | krok 0 F2 | F0b, F2 |
| Formę opodatkowania wybierasz do 20. dnia miesiąca po pierwszym przychodzie w roku (art. 9 ust. 1) | [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | 2026-10-08 | 2026-11-30 (Q-38) | F0b |
| Kurs PIT: średni NBP z ostatniego dnia roboczego przed dniem przychodu (PIT art. 11a ust. 1 przez art. 6 ust. 1); dzień przychodu z art. 14 ust. 1c, przy usłudze okresowej ust. 1e | [ustawa o PIT](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) | 2026-10-08 | krok 0 F1 | F1, F2 |
| Różnice kursowe przy zapłacie liczysz według PIT art. 24c (przez art. 6 ust. 1c ustawy o ryczałcie) | [ustawa o PIT](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf), [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | 2026-10-08 | krok 0 F2 | F2 |
| Metoda kasowa (PIT art. 14c, art. 6 ust. 1g): limit 2 000 000 zł od 1.01.2026, wybór do 20 lutego, tylko z przedsiębiorcą; ewidencja faktur (art. 15 ust. 11a) i znacznik `Kasowy_PIT` | [ustawa o PIT](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf), [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf) | 2026-10-08 | 2027-01-20 | F0b, F2 |
| Korekta przychodu trafia do okresu korekty, chyba że wynika z błędu rachunkowego albo oczywistej omyłki (PIT art. 14 ust. 1m przez art. 6 ust. 1e) | [ustawa o PIT](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) | 2026-10-08 | przegląd doradcy (Q-29) | F2, F3 |
| Ulga na złe długi (art. 11 ust. 4) nie działa dla dłużnika spoza opodatkowania w Polsce (ust. 10 pkt 3) ani przy metodzie kasowej (ust. 20) | [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) | 2026-10-08 | krok 0 F2 | F2 |
| Podstawy i kwoty podatku zaokrąglasz do pełnych złotych (OP art. 63 § 1) | [Ordynacja podatkowa](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf) | 2026-10-08 | krok 0 F2 | F2, F5 |

### Podatek u źródła

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| Osoba fizyczna prowadząca firmę jest płatnikiem od wypłat z art. 21 ust. 1 ustawy o CIT (art. 26 ust. 1). Obejmuje to m.in. licencje (pkt 1) i przetwarzanie danych (pkt 2a). Stawka 20% (art. 21 ust. 1) | [ustawa o CIT](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) | 2026-10-08 | przegląd doradcy (Q-21) | teraz, F0b, F1, F5 |
| Stawka z umowy o unikaniu podwójnego opodatkowania tylko z certyfikatem rezydencji i przy należytej staranności (art. 26 ust. 1) | [ustawa o CIT](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) | 2026-10-08 | przegląd doradcy (Q-21) | F0b, F5 |
| Wpłata do 7. dnia miesiąca po wypłacie (art. 26 ust. 3); IFT-2R do końca marca, także przy zwolnieniu z umowy (ust. 3a, 3d); CIT-10Z do końca stycznia (art. 26a) | [ustawa o CIT](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf) | 2026-10-08 | krok 0 F0b | teraz, F0b, F1, F5 |
| Wypłata osobie fizycznej bez miejsca zamieszkania w Polsce (art. 3 ust. 2a): ryczałt 20% (art. 29 ust. 1). Obejmuje m.in. prawa autorskie i licencje (pkt 1) oraz usługi doradcze i przetwarzanie danych (pkt 5) | [ustawa o PIT](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) | 2026-10-08 | przegląd doradcy (Q-21) | teraz, F0b, F5 |
| Stawka z umowy o unikaniu podwójnego opodatkowania tylko z certyfikatem rezydencji (art. 29 ust. 2). Płatnikiem jest osoba fizyczna prowadząca działalność (art. 41 ust. 1 i 4) | [ustawa o PIT](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) | 2026-10-08 | przegląd doradcy (Q-21) | teraz, F0b, F5 |
| Płatnik PIT wpłaca ryczałt do 20. dnia następnego miesiąca (art. 42 ust. 1). Deklaracja roczna do końca stycznia (ust. 1a). Imienna informacja dla nierezydenta do końca lutego, także bez poboru z umowy (ust. 2 pkt 2, ust. 6) | [ustawa o PIT](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) | 2026-10-08 | przegląd doradcy (Q-21) | teraz, F0b, F5 |

### Terminy, przechowywanie i wpłaty

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| Termin w sobotę albo w dzień ustawowo wolny przesuwa się na następny dzień roboczy (OP art. 12 § 5); od 1.10.2026 przepis mówi o „terminie do wykonania czynności” | [Ordynacja podatkowa](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf), [Dz.U. 2026 poz. 846](https://api.sejm.gov.pl/eli/acts/DU/2026/846/text.pdf) | 2026-10-08 | krok 0 F0b | F0b |
| 24 grudnia jest dniem ustawowo wolnym od 2025 r. | [Dz.U. 2025 poz. 296](https://api.sejm.gov.pl/eli/acts/DU/2025/296/text.pdf) | 2026-10-08 | krok 0 F0b | F0b |
| Dokumenty podatkowe przechowujesz do przedawnienia: 5 lat od końca roku, w którym upłynął termin płatności (OP art. 70 § 1, art. 86 § 1) | [Ordynacja podatkowa](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf) | 2026-10-08 | krok 0 F0a | F0a |
| Mikrorachunek generujesz raz i się nie zmienia; przyjmuje wpłaty PIT, CIT i VAT; tytuł ma symbol formularza i okres, np. PPE dla zaliczki ryczałtu i PIT-28 dla dopłaty | [podatki.gov.pl](https://www.podatki.gov.pl/mikrorachunek-podatkowy), [generator](https://www.podatki.gov.pl/generator-mikrorachunku-podatkowego) | 2026-10-08 | pierwsza wpłata z tytułem z kokpitu | F0b |

### ZUS

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| Płatnik niebędący jednostką budżetową ani osobą prawną opłaca składki do 20. dnia następnego miesiąca (art. 47 ust. 1 pkt 4) | [ustawa o systemie ubezpieczeń społecznych](https://api.sejm.gov.pl/eli/acts/DU/2026/199/text.pdf) | 2026-10-08 | krok 0 F0b | teraz, F0b |
| Do należności ze składek stosuje się m.in. OP art. 12 (art. 31), więc termin w dzień wolny przesuwa się jak w polityce `op12` | [ustawa o systemie ubezpieczeń społecznych](https://api.sejm.gov.pl/eli/acts/DU/2026/199/text.pdf) | 2026-10-08 | krok 0 F0b | F0b |
| Płatnik opłacający składki tylko za siebie przesyła tylko deklarację rozliczeniową; zwolnienia z kolejnych deklaracji są w art. 47 ust. 2c–2fb (Q-16) | [ustawa o systemie ubezpieczeń społecznych](https://api.sejm.gov.pl/eli/acts/DU/2026/199/text.pdf) | 2026-10-08 | krok 0 F0b | F0b |
| Składka zdrowotna 2026 na ryczałcie przy przychodzie narastająco do 60 000 zł, do 300 000 zł i powyżej. Podstawa: 5 537,18 / 9 228,64 / 16 611,55 zł. Składka: 498,35 / 830,58 / 1 495,04 zł | [ZUS, składka zdrowotna 2026](https://www.zus.pl/en/-/informacja-w-sprawie-podstawy-wymiaru-sk%C5%82adki-oraz-kwoty-sk%C5%82adki-na-ubezpieczenie-zdrowotne-w-2026-r.) | 2026-10-08 | 2027-01-15 | F0b, F2 |
| Przychód do progu zdrowotnej pomniejszasz o zapłacone składki społeczne (art. 81 ust. 2g); możesz przyjąć przychód z poprzedniego roku na cały rok (ust. 2h) | [ustawa o świadczeniach](https://eli.gov.pl/api/acts/DU/2025/1461/text/T/D20251461L.pdf) | 2026-10-08 | krok 0 F2 | F2 |
| Roczne rozliczenie składki zdrowotnej do 20 maja; nadpłatę zwraca wniosek RZS-R | [ZUS, roczne rozliczenie](https://www.zus.pl/en/-/roczne-rozliczenie-sk%C5%82adki-zdrowotnej-za-2025-r.) | 2026-10-08 | 2027-04-01 | F0b, F2 |

### KSeF

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| § 13 ust. 1 pkt 1 rozporządzenia: token do uwierzytelnienia do 31.12.2026; § 13 ust. 2: po logowaniu tokenem nie wytworzysz certyfikatu KSeF | [Dz.U. 2025 poz. 1815](https://api.sejm.gov.pl/eli/acts/DU/2025/1815) | 2026-10-08 | 2026-12-01 | F1 |
| Jedyna zmiana rozporządzenia (Dz.U. 2026 poz. 169, 13.02.2026) dotyczy § 13 ust. 1 pkt 2 i § 15 pkt 2; § 13 ust. 1 pkt 1 bez zmian | [Dz.U. 2026 poz. 169](https://api.sejm.gov.pl/eli/acts/DU/2026/169/text.pdf) | 2026-10-08 | 2026-12-01 | F1 |
| MF: logowanie tokenem zostaje „bezterminowo”, nowelizacja w przygotowaniu (strona z 01.09.2026) | [MF, certyfikaty KSeF](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/) | 2026-10-08 | 2026-12-01 | F1 |
| Konsultacje MF z 11.06.2026: ważność tokena od 1 do 365 dni z automatycznym odnowieniem | [MF, konsultacje](https://ksef.podatki.gov.pl/wyjasnienia/pierwsze-konsultacje-po-czesciowym-wdrozeniu-ksef-podsumowanie/) | 2026-10-08 | 2026-12-01 | F1 |
| Zespół API (10.09.2026): tokeny wydane do 31.12.2026 działają najpóźniej do 30.11.2027; nowy endpoint wydawania od 1.01.2027, stary w trybie zgodności; format tokenu bez zmian | [CIRFMF #834](https://github.com/CIRFMF/ksef-api/issues/834) | 2026-10-08 | 2026-12-01 | F1 |
| Certyfikaty KSeF typ 1 (uwierzytelnienie) i typ 2 (offline) są osobne i ważne najwyżej 2 lata; wniosek tylko po uwierzytelnieniu PZ, QES albo pieczęcią kwalifikowaną | [MF, certyfikaty KSeF](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/) | 2026-10-08 | krok 0 F1 | F1, F3 |
| Challenge ważny 10 minut; refreshToken do 7 dni; drugi redeem tym samym tokenem daje 400 | [uwierzytelnianie KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/uwierzytelnianie.md) | 2026-10-08 | krok 0 F1 | F1 |
| API KSeF 2.8.1 (PROD od 23.09.2026); 2.8.0 dodało błąd 21184, osobne limity zamknięcia sesji i nagłówek `X-KSeF-Feature: subject-identifier-validation` | [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) | 2026-10-08 | krok 0 F1 i F3 | F1, F3 |
| Od API 2.5.0 (TEST od 06.05.2026) domyślne limity TE są równe PROD; DEMO ma limity PROD; jedna linia w `limity-api.md` jest nieaktualna | [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md), [limity API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/limity/limity-api.md) | 2026-10-08 | krok 0 F1 | F1, F3 |
| Limity liczą się na parę kontekst NIP i IP w oknach sekundy, minuty i godziny. 429 niesie `Retry-After`. `/auth/challenge`: 60 na sekundę na IP. Kilka IP w jednym kontekście MF traktuje jak obchodzenie limitów. Tabela grup: [modules/ksef.md](modules/ksef.md) | [limity API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/limity/limity-api.md) | 2026-10-08 | krok 0 F1 | F1, F3 |
| MF zapowiada wyższe limity pobierania w godzinach 20:00–06:00; wartości nieustalone | [limity API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/limity/limity-api.md) | 2026-10-08 | krok 0 F1 | F1 |
| Sync przyrostowy: eksport po `PermanentStorage` z `restrictToPermanentStorageHwmDate`; daty `Issue` i `Invoicing` gubią faktury | [przyrostowe pobieranie](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/pobieranie-faktur/przyrostowe-pobieranie-faktur.md) | 2026-10-08 | krok 0 F1 | F1 |
| Zakres dat w filtrze do 100 dni w UTC; paczka eksportu do 10 000 faktur albo 1 GB; do 20 eksportów na godzinę, co najmniej 15 minut przerwy na rolę | [KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json), [przyrostowe pobieranie](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/pobieranie-faktur/przyrostowe-pobieranie-faktur.md) | 2026-10-08 | krok 0 F1 | F1 |
| Błąd 21165: faktura jeszcze niedostępna do pobrania | [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) | 2026-10-08 | krok 0 F1 | F1 |
| Klucz duplikatu to NIP sprzedawcy, `RodzajFaktury` i P_2, unikalny przez 10 pełnych lat; duplikat daje status 440 z `originalKsefNumber` i `originalSessionReferenceNumber` | [weryfikacja faktury](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/weryfikacja-faktury.md), [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) | 2026-10-08 | krok 0 F3 | F1, F3 |
| Status sesji, lista faktur błędnych i UPO przyjmują `InvoiceWrite`; sesja interaktywna żyje 12 h; weryfikacja jest asynchroniczna | [KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json) | 2026-10-08 | krok 0 F3 | F3 |
| KSeF odrzuca: BOM, kodowanie inne niż UTF-8, instrukcje przetwarzania, P_1 późniejszą niż dzień przyjęcia, plik ponad 1 MB (3 MB z załącznikami); sumę kontrolną NIP sprawdza tylko PROD | [weryfikacja faktury](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/weryfikacja-faktury.md) | 2026-10-08 | krok 0 F3 | F3 |
| Wysyłka po dniu P_1 daje tryb offline24; taka faktura trafia do KSeF najpóźniej następnego dnia roboczego | [MF, offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/), [tryby offline](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/tryby-offline.md) | 2026-10-08 | krok 0 F3 | F3 |
| Tryby offline: offline24, offline przy zapowiedzianej niedostępności, awaryjny z dosłaniem w 7 dni roboczych, awaria całkowita bez FA(3) i QR | [tryby offline](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/tryby-offline.md) | 2026-10-08 | krok 0 F3 | F3 |
| KOD II jest potrzebny tylko, gdy nabywca dostaje fakturę przed numerem KSeF; podpisuje go certyfikat typu Offline | [MF, offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/), [kody QR](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/kody-qr.md) | 2026-10-08 | krok 0 F3 | F3 |
| KOD I: adres z NIP, P_1 i SHA-256 dokładnie wysłanego pliku w Base64URL; host zależny od środowiska; ISO/IEC 18004:2024 | [kody QR](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/kody-qr.md) | 2026-10-08 | krok 0 F3 | F3 |
| FA(3) 1-0E: `PrzyczynaKorekty` i `TypKorekty` mają `minOccurs=0`; `TypKorekty` przyjmuje 1, 2 albo 3 | [schemat FA(3)](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/schemy/FA/schemat_FA%283%29_v1-0E.xsd) | 2026-10-08 | krok 0 F3 | F3 |
| FA(3) 1-0E importuje `StrukturyDanych` z crd.gov.pl, więc walidacja bez lokalnych kopii sięga do sieci | [schemat FA(3)](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/schemy/FA/schemat_FA%283%29_v1-0E.xsd) | 2026-10-08 | krok 0 F2 | F2, F3, F5 |
| Broszura FA(3) (sierpień 2026): np II z P_13_9 dla usług z art. 100 ust. 1 pkt 4, np I z P_13_8; `KodWaluty`, `JST` i `GV` obowiązkowe | [broszura FA(3)](https://ksef.podatki.gov.pl/media/nuclnu0w/broszura-informacyjna-dotyczaca-struktury-logicznej-fa-3.pdf) | 2026-10-08 | przegląd doradcy | F3 |
| Faktury przyjętej nie da się edytować ani anulować; noty korygujące nabywcy nie działają; nabywca nie odrzuci faktury, poprawia tylko wystawca przez KOR | [podręcznik KSeF 2.0, cz. II, 06.08.2026](https://ksef.podatki.gov.pl/media/rronoxyt/podrecznik-ksef-20-cz-ii-wystawianie-i-otrzymywanie-faktur-w-ksef-06082026.pdf), [MF, pytania i odpowiedzi](https://ksef.podatki.gov.pl/pytania-i-odpowiedzi-ksef-20/) | 2026-10-08 | krok 0 F3 | F1, F3 |
| TE i DEMO: tylko losowe NIP, bez faktur produkcyjnych i rzeczywistych danych podmiotów. TE dzieli dane między integratorów i przyjmuje certyfikaty self-signed. Środowiska testowe mają przerwy 16:00–18:00; podmioty testowe przez `/testdata/person` i `/testdata/subject` | [środowiska KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/srodowiska.md), [changelog API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) | 2026-10-08 | krok 0 F1 | F1, F3 |
| Hosty KSeF: API `api-test`, `api-demo` i `api.ksef.mf.gov.pl`; KOD I `qr-test`, `qr-demo` i `qr.ksef.mf.gov.pl`; Latarnia `api-latarnia-test` i `api-latarnia.ksef.mf.gov.pl` | [środowiska KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/srodowiska.md), [kody QR](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/kody-qr.md), [środowiska Latarni](https://raw.githubusercontent.com/CIRFMF/ksef-latarnia/main/srodowiska.md) | 2026-10-08 | krok 0 F1 | F0a, F1, F3 |
| Latarnia: API publiczne `/status` i `/messages`, komunikaty 30 dni; statusy `AVAILABLE`, `MAINTENANCE`, `FAILURE`, `TOTAL_FAILURE`; brak środowiska DEMO | [scenariusze Latarni](https://raw.githubusercontent.com/CIRFMF/ksef-latarnia/main/scenariusze.md), [README Latarni](https://raw.githubusercontent.com/CIRFMF/ksef-latarnia/main/README.md) | 2026-10-08 | krok 0 F3 | F3 |
| `n1ebieski/ksef-php-client`: obie linie celują w KSeF API 2.0; 1.10.x od PHP 8.1 tylko z poprawkami, ostatnie podbicie API 2.7.1; 2.0.0-rc.1 z 20.09.2026 wymaga PHP ^8.4 | [Packagist](https://packagist.org/packages/n1ebieski/ksef-php-client), [UPGRADE.md](https://github.com/N1ebieski/ksef-php-client/blob/main/UPGRADE.md) | 2026-10-08 | krok 0 F1 | F1, F3 |
| Generator PDF MF (TypeScript, Node 22) nie ma CLI, a jego plik angielski zawiera same placeholdery; biblioteka woła fork w Node przez `proc_open` | [ksef-pdf-generator](https://github.com/CIRFMF/ksef-pdf-generator), [README biblioteki](https://raw.githubusercontent.com/N1ebieski/ksef-php-client/main/README.md) | 2026-10-08 | krok 0 F3 | F3 |

### e-Doręczenia

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| Firmy z CEIDG wpisane przed 1.01.2025 mają mieć aktywny ADE od 1.10.2026 (u.d.e. art. 9 ust. 1 pkt 9, art. 152 ust. 3) | [gov.pl, 16.09.2026](https://www.gov.pl/web/e-doreczenia/e-doreczenia-1-pazdziernika-2026-r-wazny-termin-dla-przedsiebiorcow-z-ceidg), [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf) | 2026-10-08 | Q-13 | teraz, F0b |
| Przedsiębiorca może wyznaczyć administratora skrzynki, który obsługuje korespondencję w jego imieniu | [gov.pl, 16.09.2026](https://www.gov.pl/web/e-doreczenia/e-doreczenia-1-pazdziernika-2026-r-wazny-termin-dla-przedsiebiorcow-z-ceidg) | 2026-10-08 | Q-18 | teraz, F0b |
| Fikcja doręczenia: dzień po upływie 14 dni od dowodu D.1, tylko dla pism od podmiotów publicznych (art. 42 ust. 2) | [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text/O/D20260003.pdf) | 2026-10-08 | krok 0 F0b | teraz, F0b, F4 |
| Odbiorem jest każde udostępnienie treści (art. 41 ust. 2); upoważnienie do skrzynki jest imienne (art. 19 ust. 6–7); prywatny ADE to osobny adres (art. 32 ust. 2) | [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf) | 2026-10-08 | krok 0 F0b | F0b, F4 |
| Konto w e-US: doręczenie po zgodzie na e-Korespondencję (OP art. 144 § 1a, ustawa o KAS art. 35e), własna fikcja 14 dni; w części procedur obowiązkowe (OP art. 14zc) | [Ordynacja podatkowa](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf), [ustawa o KAS](https://api.sejm.gov.pl/eli/acts/DU/2025/1131/text.pdf) | 2026-10-08 | krok 0 F0b | teraz, F0b |
| GET wiadomości na zawiadomieniu (Stub) uruchamia transfer i dowód E.1; 202 oznacza transfer w toku po odbiorze; załączniki ponad 15 MB przez `POST /{ADE}/attachments/download`; zbiorczo do 50 identyfikatorów | [PT UA API 5.27, § 3.3.1.6](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf) | 2026-10-08 | przy powrocie F4 (D-29) | F4 |
| Reguła R13: `eventDate` plus 14 dni z przesunięciem na dzień roboczy; może różnić się o dzień od ustawy | [PT UA API 5.27](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf) | 2026-10-08 | przegląd doradcy (Q-29) | F4 |
| Obowiązuje UA API 3 (`3.0.8.yaml`); v1 i v2 wyłączone na INT 26.11.2025 i na PROD 11.01.2026; aktualne SE API 2, 3 i 4; plik yaml jest publiczny | [Interfejsy API](https://www.gov.pl/web/e-doreczenia/interfejsy-api) | 2026-10-08 | przy powrocie F4 (D-29) | F4 |
| Hosty: INT `uaapi-int-ow.poczta-polska.pl` i `int-ow.edoreczenia.gov.pl`; PROD `uaapi-ow.poczta-polska.pl/api/v3/` i `ow.edoreczenia.gov.pl/api/se/v4/` | [PT UA API 5.27](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf), [Interfejsy API](https://www.gov.pl/web/e-doreczenia/interfejsy-api) | 2026-10-08 | przy powrocie F4 (D-29) | F4 |
| SE API v1 wyłączono 1.07.2025 | [instrukcja INT v1.9.7](https://www.gov.pl/attachment/b0bbf5ef-8909-43ff-851d-d1183e96d02a) | 2026-10-08 | przy powrocie F4 (D-29) | F4 |
| Utrzymywane są trzy wersje główne API; poprzednia działa jeszcze 12 miesięcy (§ 4.4.1) | [PT UA API 5.27](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf) | 2026-10-08 | przy powrocie F4 (D-29) | F4 |
| Token dostępowy żyje 5 minut, `refresh_expires_in` = 0; asercja JWT wymaga poprawnego czasu serwera | [instrukcja INT v1.9.6](https://www.gov.pl/attachment/09ea071e-255f-491a-96fe-8ae5cd17ffc3), [instrukcja PROD v1.9](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce) | 2026-10-08 | przy powrocie F4 (D-29) | F4 |
| PROD: podmiot niepubliczny kupuje certyfikat kwalifikowany, bo nie wygeneruje go jak na INT; jeden certyfikat obsługuje jeden system (§ 1.2; nagłówek dokumentu podaje wersję 1.8, historia kończy się na 1.9 z 2025-07-01) | [instrukcja PROD v1.9](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce) | 2026-10-08 | po F5, przy powrocie F4 (Q-30) | F4 |
| Certyfikat do systemu: RSA ≥ 2048 albo ECDSA P-256, `keyUsage` = `digitalSignature`, Subject wskazuje właściciela przez NIP albo REGON | [załącznik 1c, § 3.8](https://www.gov.pl/attachment/0c5a5272-b629-4a57-8c16-7935c6cb7037) | 2026-10-08 | po F5, przy powrocie F4 (Q-30) | F4 |
| Pieczęć kwalifikowaną tworzy tylko osoba prawna (eIDAS art. 3) | [eIDAS, art. 3](https://www.legislation.gov.uk/eur/2014/910/article/3) | 2026-10-08 | po F5, przy powrocie F4 (Q-30) | F4 |
| ADE prowadzone przez dostawcę kwalifikowanego używa API i onboardingu tego dostawcy | [lista dostawców](https://www.gov.pl/web/e-doreczenia/kwalifikowani-dostawcy-uslug-kdu3) | 2026-10-08 | po F5, przy powrocie F4 (Q-30) | F4 |
| Regulamin INT: wniosek 2a albo 2b z QES lub PZ, decyzja do 10 dni roboczych, dostęp co najmniej 30 dni, kolejny wniosek 90 dni po przyznaniu, biała lista publicznych IP, do 6 ADE testowych | [regulamin INT](https://www.gov.pl/attachment/f8d3d63b-74ca-4c89-b35b-773eaf2a924a) | 2026-10-08 | przy powrocie F4 (D-29) | F4 |
| Typy ADE testowych na INT: osoba fizyczna, urząd, zawód zaufania publicznego, organizacja publiczna, przedsiębiorca niebędący osobą fizyczną; typu JDG brak; darmowe certyfikaty tylko na INT | [instrukcja INT v1.9.7](https://www.gov.pl/attachment/b0bbf5ef-8909-43ff-851d-d1183e96d02a) | 2026-10-08 | przy powrocie F4 (D-29) | F4 |

### JPK i e-Deklaracje

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| Specyfikacja interfejsów JPK 5.6.0.v1 z 10.09.2026 zastępuje 5.1.0; TLS 1.2 albo 1.3 z sześcioma zestawami AES-GCM | [specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf) | 2026-10-08 | krok 0 F5 | F2, F5 |
| Hosty API JPK: `test-e-dokumenty.mf.gov.pl` i `e-dokumenty.mf.gov.pl`; części trafiają do magazynu Azure `taxdocumentstorageNN` (z sufiksem `tst` na teście) | [specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf) | 2026-10-08 | krok 0 F5 | F0a, F5 |
| Części do 60 MB (62 914 560 bajtów), metadane `InitUpload` do 100 KB, SHA-256 dokumentu, MD5 części w Base64, klucz AES opakowany RSA-2048 | [specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf) | 2026-10-08 | krok 0 F5 | F5 |
| JPK_V7M(3), JPK_EWP(4) i JPK_ST(1) przyjmują podpis kwalifikowany, podpis zaufany albo dane autoryzujące; dane autoryzujące i XAdES naraz dają kod 136 | [specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf) | 2026-10-08 | krok 0 F5 | F5 |
| Dane autoryzujące (SIG-2008_v2-0): NIP albo PESEL, imię, nazwisko, data urodzenia i przychód z roku N−2 albo 0; tylko dla podatnika będącego osobą fizyczną (kod 422) | [specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf), [XSD SIG-2008](https://podatki-arch.mf.gov.pl/media/10553/sig-2008_v2-0.xsd) | 2026-10-08 | 2027-01-02 (nowe N−2) | F5 |
| Kody 170 i 407 oznaczają duplikat z numerem oryginału; 411 oznacza identyczny dokument już złożony, bez numeru oryginału; `FinishUpload` jest obowiązkowy; `Status` 300 i 4xx są końcowe | [specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf) | 2026-10-08 | krok 0 F5 | F5 |
| Certyfikat klucza publicznego MF jest inny dla testu i produkcji i bywa wymieniany | [pliki do pobrania MF](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_vat-z-deklaracja/pliki-do-pobrania) | 2026-10-08 | krok 0 F5 | F5 |
| Na środowisku testowym JPK wysyłasz tylko dane fikcyjne | [specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf) | 2026-10-08 | krok 0 F5 | F2, F5 |
| JPK_V7M(3) obowiązuje od okresu 02.2026; najnowsza struktura według gov.pl | [MF, nowe wzory](https://www.podatki.gov.pl/aktualnosci/nowe-wzory-struktur-jpk_vat-dostepne-na-epuap), [struktury JPK](https://www.gov.pl/web/kas/struktury-jpk) | 2026-10-05 (strona gov.pl) | krok 0 F5 | teraz, F5 |
| Każdy rekord JPK_V7M(3) ma numer KSeF albo znacznik `OFF`, `BFK` lub `DI`; `DI` koryguje się po nadaniu numeru | [broszura JPK_V7M(3)](https://www.podatki.gov.pl/media/wgbkrejs/broszura-jpk_vat-z-deklaracj%C4%85-od-1-lutego-2026-r.pdf), [objaśnienia znaczników](https://podatki.gov.pl/media/o1jppu0e/jpk_vat-z-deklaracja-stosowanie-znacznikow_v11.pdf) | 2026-10-08 | krok 0 F5 | F5 |
| Nadwyżka VAT naliczonego: zwrot w P_54; termin: P_540 15 dni, P_55 25 dni na rachunek VAT, P_56 25 dni, P_560 40 dni, P_58 180 dni. Zaliczenie w P_59–P_61, przeniesienie w P_62. `CelZlozenia`: 1 złożenie, 2 korekta | [broszura JPK_V7M(3)](https://www.podatki.gov.pl/media/wgbkrejs/broszura-jpk_vat-z-deklaracj%C4%85-od-1-lutego-2026-r.pdf) | 2026-10-08 | krok 0 F5 | F5 |
| JPK_EWP(4) to osobne złożenie z własnym UPO; K_2 to data wpisu, K_3 data przychodu; `Kasowy_PIT` w węźle `Podmiot1` (pole opcjonalne, „1” = metoda kasowa) | [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf) | 2026-10-08 | krok 0 F2 | F2, F5 |
| JPK_ST(1) dla ryczałtowca: węzeł `EWP` z polami F_1–F_16; F_9 (stawka amortyzacyjna) wymagane | [broszura JPK_ST(1)](https://podatki.gov.pl/media/jikpuwwp/broszura_informacyjna_dot_jpk_st-1.pdf) | 2026-10-08 | krok 0 F2 | F2, F5 |
| Darmowe narzędzia MF: Formularz uproszczony (JPK_EWP, JPK_ST) i Klient JPK WEB; MF nie daje narzędzia do prowadzenia ewidencji w ciągu roku | [narzędzia MF](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_pd/bezplatne-narzedzia) | 2026-10-08 | krok 0 F5 | teraz, F5 |
| Schematy JPK_EWP(4) i JPK_ST(1) publikuje strona struktur JPK w podatkach dochodowych | [struktury JPK w PIT](https://www.gov.pl/web/kas/struktury-jpk-w-podatkach-dochodowych) | 2026-10-08 | krok 0 F2 | F2 |
| Bramka e-Deklaracji PROD: `bramka.e-deklaracje.mf.gov.pl`; adres testowy `test-bramka.edeklaracje.gov.pl` pochodzi ze specyfikacji 2.4 z 2019 r. | [specyfikacja e-Deklaracji 2.4](https://podatki-arch.mf.gov.pl/media/3616/edek_specyfikacja_we-wy_2-4_test_www.pdf) | 2026-10-08 | krok 0 F5 | F0a, F5 |
| VAT-UE(5): P_Uc w pełnych złotych (`etd:TKwotaC`, czyli `xsd:integer`) | [schemat VAT-UE(5)](http://crd.gov.pl/wzor/2021/01/12/10293/schemat.xsd), [ElementarneTypyDanych v6-0E](http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2020/03/11/eD/DefinicjeTypy/ElementarneTypyDanych_v6-0E.xsd) | 2026-10-08 | krok 0 F5 | F5 |
| Kanał VAT-UE z danymi autoryzującymi: źródła w sekcji „Kanał VAT-UE” niżej | [struktury e-Deklaracji](https://podatki-arch.mf.gov.pl/e-deklaracje/dokumentacja-it/struktury-dokumentow-xml/) | 2026-10-08 | krok 0 F5 | F5 |
| PIT-28(25) też dopuszcza dane autoryzujące | [struktury e-Deklaracji](https://podatki-arch.mf.gov.pl/e-deklaracje/dokumentacja-it/struktury-dokumentow-xml/) | 2026-10-08 | przy decyzji o automatyzacji PIT-28 | poza fazami |

### Rejestry i kursy NBP

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| Biała lista ma dwa limity: `search` 100 zapytań dziennie po najwyżej 30 podmiotów oraz `check` do 5000 podmiotów; okresu dla `check` strona nie podaje | [API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat) | 2026-10-16 | 2026-11-16 | F1 |
| Po wyczerpaniu limitu dostęp może być zablokowany do 0:00, łącznie z wyszukiwarką na stronie; blokowanie jest fakultatywne; czy dotyczy IP, strona nie mówi | [API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat) | 2026-10-16 | 2026-11-16 | F1 |
| `requestId` potwierdza wykonanie zapytania przed organami; zapytanie może dotyczyć dnia do 5 lat wstecz; specyfikacja 1.6.1 z 12.10.2026 bez zmian ścieżek i pól `requestId`, `requestDateTime` | [opis API 1.6.1](https://www.gov.pl/attachment/cb5cf50e-9c50-4c5e-b316-cf33de2e8c06), [Swagger 1.6.1](https://www.gov.pl/attachment/1d3cf376-ef46-4931-82be-43f249c3600f) | 2026-10-16 | 2026-11-16 | F1 |
| Plik płaski par NIP–rachunek publikowany codziennie o północy | [API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat) | 2026-10-16 | 2026-11-16 | F1 |
| Hosty białej listy od 15.10.2026 (termin przesunięty z 12.10): API `wykazpodatnikowvat.mf.gov.pl/api/`, test `wykazpodatnikowvat-test.mf.gov.pl/api/`; WL2 pod `/api2` (50 000 zapytań dziennie). `wl-api.mf.gov.pl` przekierowuje od 15.10.2026 na nowy adres i działa do 1.12.2026; strona dalej podaje `wl-test.mf.gov.pl` bez daty. Imperva przed hostem testowym odsyła klientowi HTTP z adresu IP chmury stronę HTML z wyzwaniem JS (HTTP 200), a przeglądarka dostaje JSON (sprawdzone 16.10.2026, D-74) | [API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat) | 2026-10-16 | 2026-11-16 | F0a, F1 |
| Zmodernizowany Wykaz od 15.10.2026 (przesunięty z 12.10) pod `wykazpodatnikowvat.mf.gov.pl`; możliwe przerwy codziennie 22:00–0:00 | [MF, wykaz podatników VAT](https://www.gov.pl/web/kas/wykaz-podatnikow-vat) | 2026-10-16 | 2026-11-16 | F1 |
| VIES zwraca `requestIdentifier` tylko z danymi pytającego; błędy kraju przychodzą jako HTTP 200 z `actionSucceed` = false; obejmuje UE i `XI`, bez Wielkiej Brytanii. Usługa testowa: 100 `valid`, 200 `invalid`, 201 `INVALID_INPUT`, 300 `SERVICE_UNAVAILABLE`, inny numer też `SERVICE_UNAVAILABLE`; `requestIdentifier` zawsze pusty, także z numerem pytającego (sprawdzone 16.10.2026, D-74) | [VIES API](https://ec.europa.eu/assets/taxud/vow-information/swagger_publicVAT.yaml), [test VIES](https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service) | 2026-10-16 | krok 0 F3 | F1, F3 |
| API NBP: zakres do 93 dni; dzień bez tabeli i dzisiejsza tabela przed publikacją dają 404; za duże zapytanie 400; tylko HTTPS od 1.08.2025; kursy od 2.01.2002 | [API NBP](https://api.nbp.pl/en.html) | 2026-10-16 | krok 0 F2 | F1 |
| Brak tabel A od 24 do 28.12.2025: tabela 248/A z 23.12.2025, następna 249/A z 29.12.2025 | [zapytanie API NBP](https://api.nbp.pl/api/exchangerates/rates/a/eur/2025-12-20/2025-12-31/) | 2026-10-08 | krok 0 F1 | F1 |
| KRS: odpis aktualny tylko po numerze KRS, dane osobowe zanonimizowane | [Otwarte API KRS](https://prs.ms.gov.pl/krs/openApi) | 2026-10-05 | przy zmianie Q-20 | odłożone (D-29) |
| CEIDG: Hurtownia danych v3, JWT po bezpłatnej rejestracji | [CEIDG](https://otwarteapi.pl/ceidg) | 2026-10-05 | przy zmianie Q-20 | odłożone (D-29) |
| GUS BIR: wersje 1.1 i 1.2, sesja po zalogowaniu kluczem, klucz produkcyjny na mail; stałe IP tylko przy łączeniu przez serwer dostępowy dostawcy | [GUS](https://api.stat.gov.pl/Home/RegonApi) | 2026-10-08 | przy zmianie Q-20 | odłożone (D-29) |

### FreshBooks

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| OAuth 2.0: refresh token działa jeden raz; na aplikację i użytkownika żyje jeden, nowy unieważnia starsze; redirect tylko HTTPS bez parametrów | [FreshBooks, uwierzytelnianie](https://www.freshbooks.com/api/authentication) | 2026-10-08 | krok 0 F2 | F2, F3 |
| `invoice_number` powstaje przy utworzeniu faktury; Send, wysyłka cykliczna, auto-bill i `action_email` dostarczają dokument od razu; filtr `updated_min` przyjmuje tylko datę | [FreshBooks, faktury](https://www.freshbooks.com/api/invoices) | 2026-10-08 | krok 0 F3 | F2, F3 |
| FreshBooks nie ma środowiska testowego. Dokumentacja API nie opisuje sandboxu ani konta testowego; konto powstaje przez rejestrację, plan partnerski przez wsparcie | [FreshBooks, start API](https://www.freshbooks.com/api/start), [FreshBooks, faktury](https://www.freshbooks.com/api/invoices) | 2026-10-08 | krok 0 F2 | F2, F3 |
| `create_date` jest polem zapisywalnym; filtry `date_min` i `date_max` (wyłącznie) działają na nim, `updated_min` na polu `updated`. `fulfillment_date` jest tylko w przykładach odpowiedzi. Tagów faktur dokumentacja nie opisuje | [FreshBooks, faktury](https://www.freshbooks.com/api/invoices) | 2026-10-08 | krok 0 F2 | F2, F3 |
| Bez dziennego limitu zapytań; krótkie serie mogą być ograniczane. Lista zwraca najwyżej 100 wyników niezależnie od `per_page` | [FreshBooks, limity](https://www.freshbooks.com/api/limits) | 2026-10-08 | krok 0 F2 | F2, F3 |
| Płatności mają `invoiceid`, `date`, `amount` i `gateway` | [FreshBooks, płatności](https://www.freshbooks.com/api/payments) | 2026-10-08 | krok 0 F2 | F2 |

### Claude API

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| Claude Haiku 5.5 (`claude-haiku-5-5`): kontekst 1M, wyjście 128K, wiedza do czerwca 2026 | [przegląd Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/overview) | 2026-10-08 | krok 0 F6 | F6 |
| Haiku 5.5: $0.10 / $0.50 za MTok przy prompcie do 100 tys. tokenów, $0.50 / $2.50 powyżej; region UE przez Bedrock albo Google Cloud z dopłatą 10% | [cennik](https://platform.claude.com/docs/en/about-claude/pricing) | 2026-10-08 | krok 0 F6 | F6 |
| Haiku 5.5: cache odczyt 0,1×, zapis 5 min 1,25×, zapis 1 h 2×; minimalny prefiks 512 tokenów | [cennik](https://platform.claude.com/docs/en/about-claude/pricing), [przegląd Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/overview) | 2026-10-08 | krok 0 F6 | F6 |
| Thinking adaptive domyślnie; `display` dla Haiku 5.5: `omitted` (domyślnie) albo `summarized`; effort `low`–`max`, domyślnie `medium` | [przegląd Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/overview), [effort](https://platform.claude.com/docs/en/build-with-claude/effort) | 2026-10-08 | krok 0 F6 | F6 |
| Effort per wiadomość dla Haiku 5.5 działa na Claude API i Google Cloud, nie na Bedrock | [effort](https://platform.claude.com/docs/en/build-with-claude/effort) | 2026-10-08 | krok 0 F6 | F6 |
| Niedomyślne `temperature`, `top_p`, `top_k` i prefill dają 400; wymuszony `tool_choice` działa, ale pomija thinking | [przewodnik migracji Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/migration-guide) | 2026-10-08 | krok 0 F6 | F6 |
| Zmiana `system`, `tools` albo wcześniejszych `messages` przy blokach thinking daje 400; konta sprzed 31.08.2026 tylko z `thinking.block_binding` | [przewodnik migracji Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/migration-guide) | 2026-10-08 | krok 0 F6 | F6 |
| Claude API przetwarza w regionie `us` albo `global`; region UE tylko przez Bedrock albo Google Cloud | [rezydencja danych](https://platform.claude.com/docs/en/manage-claude/data-residency) | 2026-10-08 | krok 0 F6 (Q-33) | F6 |
| Retencja w najgorszym przypadku: wejścia i wyjścia do 30 dni, treści oznaczone do 2 lat; ZDR wymaga osobnej umowy; DPA z SCC jest częścią warunków komercyjnych | [retencja](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data), [API i retencja](https://platform.claude.com/docs/en/manage-claude/api-and-data-retention), [DPA](https://privacy.claude.com/en/articles/7996862-how-do-i-view-and-sign-your-data-processing-addendum-dpa) | 2026-10-08 | krok 0 F6 | F6 |
| Dane z API nie służą do trenowania bez Twojej zgody | [API i retencja](https://platform.claude.com/docs/en/manage-claude/api-and-data-retention) | 2026-10-05 | krok 0 F6 | F6 |
| Managed Agents: beta; historia sesji i stan sandboxa po stronie serwera; bez ZDR; niedostępne na Bedrock i Google Cloud | [Managed Agents](https://platform.claude.com/docs/en/managed-agents/overview), [cennik](https://platform.claude.com/docs/en/about-claude/pricing) | 2026-10-08 | przy powrocie do D-11 | F6 |
| PHP SDK `anthropic-ai/sdk` w becie; tool runner `BetaRunnableTool` i `toolRunner()` | [PHP SDK](https://platform.claude.com/docs/en/cli-sdks-libraries/sdks/php) | 2026-10-08 | krok 0 F6 | F6 |
| Od 2.08.2026 system AI informuje o interakcji z AI od pierwszej wiadomości (AI Act art. 50) | [AI Act, art. 50](https://artificialintelligenceact.eu/article/50/) | 2026-10-08 | krok 0 F6 | F6 |

### basal

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| basal-1.5 jest publiczny od 5.10.2026, Apache-2.0, na bazie Bielik-4.5B-v3.0; programu wcześniejszego dostępu nie ma | [strona projektu](https://basal.si5.pl/), [karta modelu](https://huggingface.co/Remek/basal-1.5-4.5B) | 2026-10-08 | przy powrocie F7 (D-29) | F7 |
| Zamknięty test PL: 93,1% (4.5B), 93,3% (11B), 91,0% (1.5B), basal-1.0: 87,4%; przy celu 1% błędu akceptuje 51,0% decyzji wobec 55,2% w 1.0 | [strona projektu](https://basal.si5.pl/), [karta modelu](https://huggingface.co/Remek/basal-1.5-4.5B) | 2026-10-08 | przy powrocie F7 (D-29) | F7 |
| Typy pytań `choice`, `noul`, `score`, `multi` i `act`; od 2 do 10 opcji; autor zaleca próg na kilkuset oznaczonych żądaniach | [silnik basal](https://github.com/rkinas/basal) | 2026-10-08 | przy powrocie F7 (D-29) | F7 |
| Dostarczona kalibracja sprawdzona tylko dla bf16, `option_keys: hide` i pytań tak/nie; pole `calibration` zwraca serwer tylko dla `act` | [silnik basal](https://github.com/rkinas/basal), [server.py](https://github.com/rkinas/basal/blob/main/basal/server.py) | 2026-10-08 | przy powrocie F7 (D-29) | F7 |
| Tryb `fast` wymaga GPU NVIDIA sm80+; pamięć: Q8_0 4.5B ok. 5,1 GB, Q4_K_M ok. 2,9 GB, mini 1,0–1,7 GB | [wymagania](https://github.com/rkinas/basal/blob/main/docs/HARDWARE.md) | 2026-10-08 | przy powrocie F7 (D-29) | F7 |
| Serwer domyślnie słucha na `0.0.0.0:8000` bez uwierzytelnienia; vLLM i SGLang odrzucają wejście ponad 4096 tokenów | [server.py](https://github.com/rkinas/basal/blob/main/basal/server.py), [karta modelu](https://huggingface.co/Remek/basal-1.5-4.5B) | 2026-10-08 | przy powrocie F7 (D-29) | F7 |

### Infrastruktura i narzędzia

| Fakt | Źródło | Sprawdzono | Następne sprawdzenie | Fazy |
| --- | --- | --- | --- | --- |
| Symfony Secrets zapisuje się przez CLI, a wartości są buforowane w procesie | [Symfony Secrets](https://symfony.com/doc/current/configuration/secrets.html) | 2026-10-08 | krok 0 F0a | F0a |
| Symfony Scheduler bez `stateful` pomija przebiegi z czasu przestoju; `processOnlyLastMissedRun` i `lock()` ograniczają nadrabianie i duplikaty | [Symfony Scheduler](https://symfony.com/doc/current/scheduler.html) | 2026-10-08 | krok 0 F0a | F0a |
| Messenger domyślnie ponawia nieudaną wiadomość 3 razy | [Symfony Messenger](https://symfony.com/doc/current/messenger.html) | 2026-10-08 | krok 0 F0a | F0a |
| Passkey wymaga bezpiecznego kontekstu i domeny jako RP ID; adres IP nie wystarczy | [WebAuthn](https://www.w3.org/TR/webauthn-3/) | 2026-10-08 | krok 0 F0a | F0a |
| Nazwy z `tailscale cert` trafiają do publicznych logów Certificate Transparency | [Tailscale](https://tailscale.com/kb/1153/enabling-https) | 2026-10-08 | krok 0 F0a | F0a |
| ntfy.sh: nazwa tematu działa jak hasło, serwis loguje tematy i IP; self-hosted ntfy na iOS przekazuje przez ntfy.sh tylko identyfikator i hash tematu | [ntfy](https://docs.ntfy.sh/config/), [ntfy FAQ](https://docs.ntfy.sh/faq/) | 2026-10-08 | krok 0 F0a (Q-15) | F0a |
| Docker na macOS nie ma dostępu do Metal | [Docker](https://www.docker.com/blog/docker-model-runner-vllm-metal-macos/) | 2026-10-08 | przy powrocie F7 (D-29) | F7 |
| `mpdf/mpdf` ma licencję GPL-2.0-only | [Packagist](https://repo.packagist.org/p2/mpdf/mpdf.json) | 2026-10-08 | krok 0 F3 | F3 |
| Umowa powierzenia z każdym procesorem (RODO art. 28) | [RODO, tekst UODO](https://uodo.gov.pl/pl/file/1460) | 2026-10-08 | krok 0 F0a | F0a, F6 |
| `CLAUDE.md` i `.claude/rules` to kontekst, nie wymuszenie; reguły bez `paths:` ładują się zawsze, z `paths:` przy pasujących plikach; `@import` ładuje się na starcie | [Claude Code, pamięć](https://code.claude.com/docs/en/memory) | 2026-10-08 | krok 0 F0a | F0a |
| Reguły deny ograniczające argumenty Bash są kruche i nie łapią programu po ścieżce ani w `sh -c`; sandbox obejmuje tylko polecenia powłoki | [uprawnienia](https://code.claude.com/docs/en/permissions), [sandbox](https://code.claude.com/docs/en/sandboxing) | 2026-10-08 | krok 0 F0a | F0a |

## Do ustalenia

Punkty bez potwierdzenia w źródle. Nie trafiają do kodu jako fakt, dopóki ich nie sprawdzisz.

| Punkt | Dotyczy | Jak sprawdzić | Kiedy |
| --- | --- | --- | --- |
| symbol formularza dla VAT w tytule przelewu (zakładany VAT-7) i format okresu | [compliance-calendar.md](compliance-calendar.md) | generator mikrorachunku albo bank przy pierwszej wpłacie | pierwsza wpłata VAT z kokpitu |
| rachunek i symbol dla wpłaty WHT | [compliance-calendar.md](compliance-calendar.md), Q-21 | podatki.gov.pl, przegląd doradcy | przed pierwszą wpłatą WHT |
| przeliczenie wypłaty w walucie na złote dla WHT | [tax-rules.md](tax-rules.md), Q-21 | przegląd doradcy | w trakcie F2 |
| czy któryś zagraniczny dostawca to osoba fizyczna i która kategoria art. 29 ust. 1 ustawy o PIT go obejmuje; wzory deklaracji płatnika PIT i kanał złożenia | [tax-rules.md](tax-rules.md), Q-21 (`counterparty.is_natural_person`) | przegląd doradcy, podatki.gov.pl | przegląd doradcy (Q-21) |
| dzień zapłaty jako moment podatkowy przedpłaconych kredytów Anthropic i źródło tej daty | [tax-rules.md](tax-rules.md), Q-21 (`invoice.tax_point_override_reason`) | przegląd doradcy, potwierdzenia płatności | przegląd doradcy (Q-21) |
| standard dowodu statusu przedsiębiorcy nabywcy z UE bez ważnego numeru w VIES (`eu_b2b_unidentified`): art. 18 [rozporządzenia wykonawczego Rady (UE) nr 282/2011](https://eur-lex.europa.eu/eli/reg_impl/2011/282/oj), niepobrany | [tax-rules.md](tax-rules.md), [modules/ksef.md](modules/ksef.md), Q-29 | tekst rozporządzenia, przegląd doradcy | przed go-live F3 |
| VAT-UE za miesiąc bez transakcji: brak pliku podaje tylko źródło wtórne | [modules/jpk.md](modules/jpk.md) | ustawa i FAQ e-Deklaracji | pierwszy miesiąc bez sprzedaży `eu_b2b` |
| JPK_ST bez środków trwałych: plik bez wierszy czy brak pliku | [modules/jpk.md](modules/jpk.md), Q-25 | broszura JPK_ST(1), MF | przed dry run 15.12.2026 |
| klient zagraniczny jako „przedsiębiorca” przy metodzie kasowej | [tax-rules.md](tax-rules.md), Q-24 | przegląd doradcy | przed 20.02.2027 |
| eZUS: czy działa fikcja doręczenia | [modules/e-doreczenia.md](modules/e-doreczenia.md), Q-13 | ZUS, przepisy o eZUS | krok 0 F0b |
| skrzynka prywatna (osoba fizyczna) bez Modułu uprawnień, więc bez API. Instrukcje INT v1.9.7 i PROD v1.9 tego wprost nie mówią. [modules/e-doreczenia.md](modules/e-doreczenia.md) opiera to na nieaktualnej instrukcji v1.8 | [modules/e-doreczenia.md](modules/e-doreczenia.md) | pytanie do COI razem z Q-30 | przy powrocie F4 (D-29) |
| zakładka Systemy na skrzynce JDG i Twoja rola (bramka 2) | [modules/e-doreczenia.md](modules/e-doreczenia.md), Q-30 | logowanie na skrzynkę | przed F4 |
| podpis kwalifikowany z NIP w Subject jako certyfikat systemu (bramka 3) | [modules/e-doreczenia.md](modules/e-doreczenia.md), Q-30 | pytanie do COI | przy powrocie F4 (D-29) |
| pole dające `sender_is_public`; pole listy pokazujące odbiór poza kokpitem; znaczenie `daysToReceive` i `receiptDate`; schemat `aud` (http albo https) | [modules/e-doreczenia.md](modules/e-doreczenia.md) | testy na INT | przy powrocie F4 (D-29) |
| pole daty usługi i znacznik gotowości (tag albo `po_number`) w odpowiedzi API FreshBooks | [modules/freshbooks.md](modules/freshbooks.md), Q-26 | nagranie z prawdziwej firmy, tylko odczyt, przez scrubber (D-35) | krok 0 F2 (F2.2/F2.3) |
| czy DEMO pozwala przejść pełny przebieg sprzedaży na danych fikcyjnych (prawdziwe dane na DEMO są zakazane) | [modules/ksef.md](modules/ksef.md), [operations.md](operations.md) | dokumentacja środowisk KSeF albo zapytanie do MF | przed go-live F3 |
| VAT-UEK z danymi autoryzującymi a status 410; działanie bramki testowej e-Deklaracji | [modules/jpk.md](modules/jpk.md), Q-32 | wysyłka fikcyjna w F5 | F5 |
| wpływ ustawy z Dz.U. 2026 poz. 1270 (od 1.01.2027) na JPK_V7M(3) i pola kokpitu | [modules/jpk.md](modules/jpk.md) | strona struktur JPK, komunikaty MF | krok 0 F5 |
| wartości wyższych limitów pobierania KSeF w nocy | [modules/ksef.md](modules/ksef.md) | `limity-api.md`, `GET /rate-limits` | krok 0 F1 |
| UD116: stawka 17% dla usług od podmiotów powiązanych; jedyna wzmianka w badaniu luk. Skutek tylko przy transakcjach z podmiotami powiązanymi | [tax-rules.md](tax-rules.md) | wykaz prac legislacyjnych Rady Ministrów, RCL | 2026-11-30, krok 0 F2 |
| składki społeczne 2026, wariant pełny: podstawa i kwoty | [tax-rules.md](tax-rules.md) (`zus.parameters`), Q-16 | komunikat ZUS | go-live F0b |
| od którego miesiąca obowiązują nowe kwoty składki zdrowotnej na ryczałcie (`valid_from` wiersza rocznego) | [tax-rules.md](tax-rules.md) (`zus.parameters`) | ZUS | krok 0 F0b |
| dokładna wersja 1.10.x; czy obsługuje eksport z `restrictToPermanentStorageHwmDate` i `GET /rate-limits` (oba sprzed API 2.7.1) oraz nagłówek `X-KSeF-Feature` z API 2.8.0 | [modules/ksef.md](modules/ksef.md), D-14, Q-19 | changelog i kod biblioteki wobec [changelogu API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) | krok 0 F1 |

## Obserwacja prawa

Zmiany w toku na 8.10.2026. Wynik sprawdzenia wpisujesz tutaj, a skutek w pliku-domu.

| Pozycja | Stan na 2026-10-08 | Skutek, jeśli wejdzie | Następne sprawdzenie | Dom |
| --- | --- | --- | --- | --- |
| UD458, druk 3147 ([proces](https://api.sejm.gov.pl/sejm/term10/processes/3147), [druk](https://api.sejm.gov.pl/sejm/term10/prints/3147/3147.pdf)) | projekt rządowy wpłynął 29.09.2026; I czytanie 6.10.2026, skierowany do komisji FPB; nieuchwalony; 16.10.2026 bez zmian (ostatnia zmiana procesu 7.10.2026) | Limit ryczałtu spada z 2 000 000 do 250 000 euro (art. 6 ust. 4 i 6). Stawka 17% od nadwyżki ponad 300 000 euro (nowy art. 12 ust. 1 pkt 1a). Działa od 1.01.2027 tylko przy ogłoszeniu do 30.11.2026. Projekt zmienia też progi PIT | co tydzień do 2026-11-30 | [tax-rules.md](tax-rules.md) (`ryczalt.eligibility`), Q-38, `tax_form_choice` w [compliance-calendar.md](compliance-calendar.md) |
| Odroczenie kar KSeF ([MF, 16.09.2026](https://www.gov.pl/web/finanse/przedluzenie-odroczenia-kar-za-bledy-w-stosowaniu-ksef-do-konca-2027-r)) | MF zapowiada odroczenie do 31.12.2027; wymaga ustawy, MF rozpoczyna prace nad projektem; nieuchwalone | bez ustawy kary z art. 106ni działają od 1.01.2027; planujesz, jakby obowiązywały | 2026-12-01, potem 2027-01-02 | [modules/ksef.md](modules/ksef.md) (reguła P_1), kamienie milowe w [README.md](README.md) |
| § 13 rozporządzenia o tokenach KSeF ([Dz.U. 2025 poz. 1815](https://api.sejm.gov.pl/eli/acts/DU/2025/1815)) | pkt 1 bez zmian: token do 31.12.2026; MF zapowiada nowelizację; zespół API planuje nowy endpoint tokenów od 1.01.2027 ([CIRFMF #834](https://github.com/CIRFMF/ksef-api/issues/834)) | nowelizacja ustala ważność tokenów; bez niej tokeny tracą podstawę 31.12.2026 | 2026-12-01 | [modules/ksef.md](modules/ksef.md) (`credential_state.valid_until`) |
| Projekt zwolnienia z wykazu ŚT i JPK_ST ([RCL 12413609](https://legislacja.gov.pl/projekt/12413609)) | projekt rozporządzenia MFiG nr 111/2026 z 18.08.2026, status otwarty, ostatnia zmiana 18.08.2026 (uzgodnienia, konsultacje) | ryczałtowcy bez wykazu w programie i bez JPK_ST za 2026–2028; wykaz papierowy zostaje; zwolnienie z ewidencji 2027–2028 tylko dla niebędących czynnymi podatnikami VAT, więc JPK_EWP zostaje | 2026-11-15 i 2026-12-15 | [modules/jpk.md](modules/jpk.md) (`jpk_st.enabled`), [modules/ewidencja.md](modules/ewidencja.md) |
| Zniesienie VAT-UE, druk 2453 ([proces](https://api.sejm.gov.pl/sejm/term10/processes/2453)) | projekt poselski z 8.01.2026; I czytanie 15.05.2026, skierowany do komisji FPB; bez zmian od 15.05.2026; nieuchwalony | VAT-UE i VAT-UEK znikają; F5 bez konektora `edeklaracje` | krok 0 F5 | [modules/jpk.md](modules/jpk.md) |
| Zmiana ustawy o VAT z 4.09.2026 ([Dz.U. 2026 poz. 1270](https://api.sejm.gov.pl/eli/acts/DU/2026/1270/text.pdf)) | ogłoszona; od 1.12.2026 tylko art. 41 ust. 14i (stawka dla różnicy kaucji); reszta od 1.01.2027, części od 1.07.2027, 1.01.2028 i 1.07.2028 | 1.12.2026 bez skutku dla tej JDG; pakiet od 1.01.2027 zmienia m.in. art. 103, 105a z nowym załącznikiem nr 16, art. 113 i załącznik nr 15 | krok 0 F1 i F5 | [modules/jpk.md](modules/jpk.md), [tax-rules.md](tax-rules.md) |
| Parametry ZUS na 2027 | znane wartości na 2026 ([ZUS](https://www.zus.pl/en/-/informacja-w-sprawie-podstawy-wymiaru-sk%C5%82adki-oraz-kwoty-sk%C5%82adki-na-ubezpieczenie-zdrowotne-w-2026-r.)); 2027 publikacja w styczniu | nowe kwoty składek i progi zdrowotnej | 2027-01-15 | [tax-rules.md](tax-rules.md) (`zus.parameters`) |
| Zmodernizowany Wykaz podatników VAT ([MF](https://www.gov.pl/web/kas/wykaz-podatnikow-vat)) | wdrożony 15.10.2026 (przesunięty z 12.10) pod `wykazpodatnikowvat.mf.gov.pl`; 16.10.2026: limity bez zmian, specyfikacja 1.6.1 z tym samym formatem, `wl-api.mf.gov.pl` przekierowany i działa do 1.12.2026; host testowy odpowiada JSON-em zgodnym z nagraniami (D-74) | inny host, limity albo format odpowiedzi | 2026-10-23, potem co tydzień do stabilizacji | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |

Parametry z datą obowiązywania, metryka przychodu 2026 wobec 250 tys. EUR i przypomnienie o wyborze formy na 2027 są już w [tax-rules.md](tax-rules.md), [data-model.md](data-model.md) i [compliance-calendar.md](compliance-calendar.md).

## Kanał VAT-UE: źródła

Sprawdzenie Q-32 z 8.10.2026, wykonane przy pisaniu [modules/jpk.md](modules/jpk.md). Tam są kody statusów i przebieg wysyłki.

| Źródło | Ustalenie |
| --- | --- |
| [struktury dokumentów XML e-Deklaracji](https://podatki-arch.mf.gov.pl/e-deklaracje/dokumentacja-it/struktury-dokumentow-xml/) | wzory z gwiazdką można podpisać danymi autoryzującymi; `VAT-UE(5)_v2-0E` i `VAT-UEK(5)_v2-1E` mają gwiazdkę |
| [specyfikacja wejścia-wyjścia e-Deklaracji 2.4](https://podatki-arch.mf.gov.pl/media/3616/edek_specyfikacja_we-wy_2-4_test_www.pdf) | `sendUnsignDocument` przyjmuje `DaneAutoryzujace` jako ostatni element; status 422: dane autoryzujące tylko dla podatnika będącego osobą fizyczną; 423: nie dla pełnomocnika; 418: wymagany podpis kwalifikowany |
| [e-Deklaracje, pytania i odpowiedzi](https://podatki.gov.pl/e-deklaracje-zloz-deklaracje-podatkowa-online/pytania-i-odpowiedzi) | dokument z danymi autoryzującymi składa tylko podatnik będący osobą fizyczną; pełnomocnik osoby fizycznej podpisuje kwalifikowanym podpisem |
| badanie planów płatnych (archiwum poza repo) | „VAT-UE i PIT-28 przez bramkę tylko podpisem kwalifikowanym”, ze źródłami: specyfikacja JPK 5.6.0 i FAQ e-Deklaracji |

**Wniosek.** Twierdzenie z badania planów płatnych dotyczy pełnomocnika, np. biura składającego za klientów. Ty jako podatnik będący osobą fizyczną składasz VAT-UE z danymi autoryzującymi. Założenie D-27 się potwierdza. Q-32 rozstrzygnięte w [decisions.md](decisions.md) (D-27).

## Rejestr ryzyk

Największe ryzyka są prawne i terminowe, nie techniczne. Mitygacja to skrót; reguła jest w pliku-domu z linku.

### Ryzyka z dawnej specyfikacji

Każdy z 9 wierszy dawnej tabeli ryzyk, zaktualizowany o decyzje z 8.10.2026.

| Id | Ryzyko | Skutek | Mitygacja | Właściciel | Przegląd |
| --- | --- | --- | --- | --- | --- |
| R-01 | § 13 ust. 1 pkt 1 rozporządzenia kończy tokeny KSeF 31.12.2026; MF zapowiada zmianę, zespół API podaje 30.11.2027 dla tokenów z 2026 | brak logowania tokenem po dacie granicznej | tokeny jako ścieżka podstawowa z datą ważności w panelu i przypomnieniami; certyfikat typ 1 jako sprawdzony zapas w kryteriach F1 ([modules/ksef.md](modules/ksef.md)) | Ty | 2026-12-01 |
| R-02 | GET wiadomości na zawiadomieniu to prawny odbiór z dowodem E.1 | terminy biegną bez Twojej decyzji | API odłożone (D-29): odbierasz tylko ręcznie w aplikacji. Po powrocie F4: `list()` tylko metadane, `receive()` tylko z panelu, test blokuje GET poza `receive()` ([modules/e-doreczenia.md](modules/e-doreczenia.md)) | Ty, przy powrocie F4 | przy powrocie F4 |
| R-03 | wersje API e-Doręczeń się zmieniają; v1 i v2 wyłączone 11.01.2026, poprzednia wersja żyje 12 miesięcy po nowej | konektor przestaje działać | API odłożone (D-29). Przy powrocie F4: wersje i adresy w konfiguracji per cel, testy kontraktowe na publicznym yaml, sprawdzenie strony „Interfejsy API” | Ty, przy powrocie F4 | przy powrocie F4 |
| R-04 | błędne oznaczenie usług zagranicznych w FA(3) | korekty faktur, błędny VAT-UE i JPK_V7M | tabela scenariuszy z XSD i broszury, złote pliki XML na api-test, przegląd doradcy przed go-live F3 (D-04, [modules/ksef.md](modules/ksef.md)); `eu_b2b_unidentified` zablokowany do potwierdzenia doradcy (Q-29) | Ty z doradcą | przed go-live F3 |
| R-05 | podwójna wysyłka: kokpit i Twoje obecne narzędzie w okresie przejścia (faktury, JPK, VAT-UE) | dwie faktury albo dwa złożenia, korekty | jeden obowiązek ma jedno źródło wysyłki; okres równoległy tylko liczy; stare narzędzie wyłączasz przy go-live ([README.md](README.md), [operations.md](operations.md)) | Ty | każdy go-live |
| R-06 | blokada białej listy po wyczerpaniu limitu `search` albo `check`, także wyszukiwarki na stronie | brak weryfikacji do północy | dwa liczniki z progami 80 i 4000; plik płaski jako zapas ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)) | Ty (sesja F1) | 2026-10-13 |
| R-07 | biblioteka KSeF: 2.0.0-rc.1 to release candidate, a 1.10.x obsługuje API 2.7.1 przy API 2.8.1 | regresje albo brak nowych funkcji API | 1.10.x przypięta do dokładnej wersji za `KsefGateway`; nowsze zmiany API w gatewayu (D-14) | Ty (Q-19) | krok 0 F1; wydanie stabilnej 2.0 |
| R-08 | awaria KSeF | brak numeru KSeF dla faktury | czekasz: Latarnia wstrzymuje wysyłki, zatwierdzenia wygasają z dniem P_1, obowiązek `invoice_issue` pilnuje 15. dnia; bez trybu offline (D-15) | Ty (Q-28) | krok 0 F3 |
| R-09 | nowa wersja basal inaczej rozstrzyga te same przypadki; próg z 1.0 nie przenosi się na 1.5 | złe etykiety i zły próg pewności | basal odłożony (D-29). Przy powrocie: model i rewizja w konfiguracji, tryb cienia do co najmniej 200 etykiet na pytanie, kalibracja po każdej zmianie ([modules/basal.md](modules/basal.md)) | Ty, przy powrocie F7 | przy powrocie F7 |

### Nowe ryzyka

| Id | Ryzyko | Skutek | Mitygacja | Właściciel | Przegląd |
| --- | --- | --- | --- | --- | --- |
| R-10 | kary z art. 106ni od 1.01.2027 bez uchwalonego odroczenia | kara za spóźnioną fakturę offline24 albo fakturę poza KSeF | P_1 = dzień wysyłki (D-16); zatwierdzenie wygasa z dniem P_1; planujesz, jakby kary obowiązywały | Ty | 2026-12-01 |
| R-11 | e-Doręczenia już obowiązują, a pismo przychodzi na ADE firmy, prywatny ADE, konto e-US albo eZUS | doręczenie fikcją bez Twojej wiedzy | powiadomienia mailowe przetestowane i administrator skrzynki ADE jako zastępca (Q-13, Q-18). Codzienny podgląd skrzynki, `manual_channel_check` co tydzień od F0b. Termin z pisma wpisujesz ręcznie do kalendarza (D-29, [README.md](README.md)) | Ty (Q-13, Q-18) | 2026-10-11 |
| R-12 | API e-Doręczeń PROD niewykonalne dla JDG: certyfikat kwalifikowany, zakładka Systemy, typ certyfikatu | F4 bez konektora PROD | F4 PROD odłożone (D-29); powiadomienia mailowe i odbiór ręczny to stan docelowy do powrotu. Bramki 1–2 poza kodem, bramki 3–4 przy powrocie ([modules/e-doreczenia.md](modules/e-doreczenia.md)) | Ty (Q-30) | 2026-10-11; po F5 |
| R-13 | okno INT wygasa przed końcem F4; kolejny wniosek dopiero po 90 dniach | ok. 60 dni bez INT | teraz bez wniosku INT (D-29); wniosek ok. 2 tygodnie przed powrotem F4, testy kontraktowe na publicznym yaml wcześniej | Ty | przy powrocie F4 |
| R-14 | JPK_EWP(4) za 2026 niegotowy na 30.04.2027 | spóźnione złożenie | dry run do 15.12.2026 ze źródła `import`, bez F0b i F1 (D-30); PROD do 31.03.2027; zapas Klient JPK WEB albo Formularz uproszczony ([README.md](README.md)) | Ty | 2026-11-15 (start F2.1), 2026-12-15, 2027-03-31 |
| R-15 | wpisy ewidencji odtworzone w F2 | błędne albo antydatowane K_2 | do go-live F2 wpisy w narzędziu z Q-11, do 20. każdego miesiąca. F2.1 importuje 2026; K_2 nigdy antydatowane; ujęcie pokazane doradcy (D-23, [modules/ewidencja.md](modules/ewidencja.md)) | Ty z doradcą | 2026-10-20 |
| R-16 | faktura sprzedaży wystawiona poza KSeF po przekroczeniu limitu 10 tys. zł | wadliwe faktury, korekty | odpowiedź na Q-10; backfill Subject1 w F1, uzgodnienie w F2.2/F2.3 ([modules/ksef.md](modules/ksef.md)) | Ty (Q-10) | 2026-10-15 |
| R-17 | VAT-UE niezłożone w terminie | zaległe informacje, korekty | przypomnienia od F0b, wysyłka w F5 (Q-12) | Ty | 2026-10-11 |
| R-18 | import usług (Anthropic, FreshBooks, hosting) pominięty w JPK_V7M | korekty JPK_V7M, odsetki | kontrola K_27–K_30 przed każdym JPK_V7M (Q-12); klasyfikacja dostawców i przedpłat w przeglądzie doradcy (Q-21, Q-29) | Ty | 2026-10-11 |
| R-19 | WHT od płatności dla zagranicznych dostawców niepobrany i niewpłacony: ustawa o CIT dla firm, art. 29 ustawy o PIT dla osób fizycznych | zaległość płatnika, odsetki | klasyfikacja dostawców i `counterparty.is_natural_person` (Q-21), certyfikaty rezydencji, przypomnienia od F0b ([tax-rules.md](tax-rules.md)) | Ty z doradcą | przegląd doradcy przed go-live F3 (D-04) |
| R-20 | wysyłka do urzędu bez zatwierdzenia albo dwukrotna (retry, dwa kliknięcia, dwa workery) | podwójne albo niezatwierdzone złożenie | sha256 payloadu, `official_writes` bez ponowień z jednym konsumentem, rekoncyliacja ([approval-gate.md](approval-gate.md)) | Ty (sesja F0b) | krok 0 F3 |
| R-21 | FreshBooks doręcza klientowi dokument przed KSeF | klient ma dokument bez numeru KSeF i QR | znacznik gotowości, blokada Send, wysyłek cyklicznych i auto-bill, alert ([modules/freshbooks.md](modules/freshbooks.md)) | Ty (Q-26) | krok 0 F3 |
| R-22 | kolizja P_2 z numerem już przyjętym w KSeF | status 440, faktura niewystawiona | import Subject1, ograniczenie unikalności, blokada przy zatwierdzeniu ([modules/ksef.md](modules/ksef.md)) | Ty (sesja F1) | krok 0 F3 |
| R-23 | dane testowe w tabelach produkcyjnych albo odtworzony backup odpytuje urzędy | błędna ewidencja i JPK, niechciane wywołania | cele per konektor, `CONNECTORS_ENABLED=0`, osobna baza, `HostAllowlist` z testem (D-13, [architecture.md](architecture.md)) | Ty (sesja F0a) | krok 0 F0a |
| R-24 | sekrety albo dane osobowe w logach, wiadomościach `failed` i nagraniach | wyciek poświadczeń i danych | `Redactor`, wiadomości tylko z identyfikatorami, oczyszczone fixtures ([security.md](security.md), [testing.md](testing.md)) | Ty (sesja F0a) | każda faza |
| R-25 | jedna osoba: choroba albo wyjazd zatrzymuje składanie i odbiór pism | przekroczone terminy | administrator skrzynki ADE jako zastępca przy odbiorze pism (Q-18, D-03). Poza skrzynką zastępca nieustalony; break-glass, UPL-1 do rozważenia ([operations.md](operations.md)) | Ty | 2026-10-11 (administrator); przed go-live F3 |
| R-26 | cicha awaria serwera, workera albo schedulera | terminy i fikcja biegną bez alertu | zewnętrzny dead-man i `/health`, scheduler z `stateful` ([operations.md](operations.md)) | Ty (sesja F0a) | krok 0 F0a |
| R-27 | wygasające poświadczenia: tokeny, certyfikaty, dane autoryzujące N−2, grant FreshBooks; JWT CEIDG dopiero po powrocie rejestrów (D-29) | przerwa w działaniu konektora | `credential_state` i obowiązek `credential_expiry` z przypomnieniami ([security.md](security.md)) | Ty | krok 0 F0b |
| R-28 | hosting poza Polską przy fakturach spoza KSeF (art. 112a) | naruszenie obowiązku przechowywania | serwer w Polsce albo dostęp on-line dla organów; decyzja w D-18 ([security.md](security.md)) | Ty (Q-14) | przed F0a |
| R-29 | UD458: przychód 2026 ponad 250 tys. EUR | brak ryczałtu w 2027; 17% od nadwyżki ponad 300 tys. EUR | metryka na panelu, obowiązek `tax_form_choice`, Q-38 ([tax-rules.md](tax-rules.md)) | Ty | 2026-11-30 |
| R-30 | bramka e-Deklaracji wymaga podpisu kwalifikowanego dla VAT-UE albo VAT-UEK (418, 410) | brak wysyłki z kokpitu, koszt certyfikatu | test na bramce testowej w F5; zapas: ręczne złożenie przez e-Deklaracje; wymóg podpisu kwalifikowanego = nowy rekord D-xx zastępujący D-27 i koszt certyfikatu | Ty (sesja F5) | krok 0 F5 |
| R-31 | bramka testowa e-Deklaracji nie działa | VAT-UE bez testu przed PROD | udokumentowany brak bramki, nagrania, pierwsze złożenie PROD jako `[manual]` ([modules/jpk.md](modules/jpk.md)) | Ty (sesja F5) | krok 0 F5 |
| R-32 | MF wymienia klucz publiczny do szyfrowania JPK | odrzucenie złożenia | zadanie `mf_keys.refresh`, data ważności w panelu ([modules/jpk.md](modules/jpk.md)) | Ty (sesja F5) | krok 0 F5 |
| R-33 | projekt zwolnienia z JPK_ST rozstrzygnie się po dry run | zbędna praca albo brak JPK_ST | flaga `jpk_st.enabled`, wiersz w „Obserwacji prawa” | Ty (Q-25) | 2026-12-15 |
| R-34 | parametry ZUS na 2027 znane dopiero w styczniu | złe kwoty w kalendarzu | nowy wiersz `zus.parameters` z `valid_from` 1 stycznia ([tax-rules.md](tax-rules.md)) | Ty | 2027-01-15 |
| R-35 | brak tabeli NBP dla daty | zły kurs po cichu | pusty zakres dat przed dniem (404) to błąd i alert; nigdy starszy kurs bez sygnału ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)) | Ty (sesja F1) | krok 0 F1 |
| R-36 | walidacja XSD sięga po schematy do sieci | walidacja zależna od crd.gov.pl albo nieudana | lokalne kopie XSD z sumami, `LIBXML_NONET` ([../.claude/rules/integrations.md](../.claude/rules/integrations.md)) | Ty (sesja F2) | krok 0 F2 |
| R-37 | nadwyżka VAT naliczonego przy sprzedaży np; błędne P_39 w pierwszym miesiącu | zły zwrot albo przeniesienie | klucz `setting` `opening.vat_carry_forward_pln` z plikiem dowodu, bez tabeli `opening_balance` (D-29, [compliance-calendar.md](compliance-calendar.md)); `vat.excess_policy` ([modules/jpk.md](modules/jpk.md), Q-31) | Ty | krok 0 F5 |
| R-38 | polecenie ukryte w piśmie albo PDF podsuwa szkic z podmienionym odbiorcą lub rachunkiem | wysyłka albo przelew w złe miejsce | rola `chat_ro`, nazwane zapytania, szkice tylko do kopiowania, odbiorca z bazy (D-22, [modules/czat.md](modules/czat.md)) | Ty (sesja F6) | krok 0 F6 |
| R-39 | dane osobowe w czacie trafiają do Anthropic w USA, z retencją do 30 dni, a treści oznaczone do 2 lat (fakt wyżej) | ryzyko RODO | DPA z SCC, maskowanie przez `Redactor`, Q-33 i Q-34 (D-22) | Ty | krok 0 F6 |
| R-40 | koszt Claude: pętla narzędzi, thinking, prompt ponad 100 tys. tokenów kosztuje 5× więcej | przekroczony budżet | budżety w kodzie, limit rozmiaru rozmowy, workspace z limitem wydatków ([modules/czat.md](modules/czat.md)) | Ty (sesja F6) | krok 0 F6 |
| R-41 | model zna prawo do czerwca 2026 i odpowiada z pamięci | błędna stawka albo termin w odpowiedzi | stawki, progi i terminy tylko z narzędzi ([modules/czat.md](modules/czat.md)) | Ty (sesja F6) | krok 0 F6 |
| R-42 | serwer basal bez uwierzytelnienia na `0.0.0.0` | dostęp do klasyfikatora z sieci | basal odłożony (D-29); przy powrocie tylko sieć wewnętrzna compose, bez mapowania portu ([modules/basal.md](modules/basal.md)) | Ty, przy powrocie F7 | przy powrocie F7 |
| R-43 | zmodernizowany Wykaz od 15.10.2026 zmienia host, limity albo format | błędy weryfikacji przed przelewem | host i limity w konfiguracji, brak zapytań 22:00–0:00, ponowne sprawdzenie ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)) | Ty | 2026-10-23 |
| R-44 | harmonogram: jedna osoba po godzinach, założenie ok. 10 h tygodniowo ([README.md](README.md)); przegląd szacuje samą F0a na 3–4 tygodnie (przegląd z 8.10.2026, archiwum poza repo) | dry run po 15.12.2026; F3 i F5 przesunięte | wąska F0a (D-28), szybka ścieżka F0a → F2.1 → F2.4 → dry run (D-30), odłożenia (D-29). Najpóźniejszy start F2.1 15.11.2026. 15.12.2026 to cel własny; 30.04.2027 spełnisz narzędziami MF | Ty | 2026-11-15 |
| R-45 | FreshBooks bez firmy testowej: pierwszy zapis zwrotny F3 trafia od razu do prawdziwej firmy | błędny numer KSeF albo status faktury u klienta | do F3 tylko odczyt; pierwszy zapis to jedna faktura przez bramkę zatwierdzeń, wiersz `[manual]` z dowodem z PROD (D-35, [modules/freshbooks.md](modules/freshbooks.md)) | Ty (sesja F3) | krok 0 F3 |
| R-46 | przedpłacone koszty (kredyty Anthropic) dostają moment podatkowy z P_6, OkresFa albo P_1, a nie z dnia zapłaty | import usług w złym okresie JPK_V7M, korekty | ręczna zmiana `tax_point_date` z powodem w `invoice.tax_point_override_reason` (Q-21); pytanie do doradcy (Q-29) ([tax-rules.md](tax-rules.md)) | Ty z doradcą | przegląd doradcy (Q-29) |

## Źródła

Wszystkie źródła z [archive/SPEC-2026-10-05.md](archive/SPEC-2026-10-05.md) oraz źródła nowej dokumentacji. Źródło dawnej specyfikacji ma oznaczenie „SPEC”.

### Źródła dawnej specyfikacji zastąpione

| Dawne źródło | Status | Zastępuje je |
| --- | --- | --- |
| [Specyfikacja interfejsów usług JPK 5.1](https://www.podatki.gov.pl/media/luilf20q/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-5-1-0_04112025.pdf) | nieaktualna | [specyfikacja JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf) |
| [Projekt techniczny UA API 5.0](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2024/06/Projekt_Techniczny_UA_API_v5_0.pdf) | dotyczy wycofanych v1 i v2 | [PT UA API 5.27](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf) |
| [Skrócona instrukcja integracji v1.8](https://www.gov.pl/attachment/da26e5c0-785d-4be8-9fb6-92eaa9d23a8e) | nieaktualna | [instrukcja INT v1.9.7](https://www.gov.pl/attachment/b0bbf5ef-8909-43ff-851d-d1183e96d02a), [instrukcja PROD v1.9](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce) |
| [basal-1.0-4.5B, karta modelu](https://huggingface.co/Remek/basal-1.0-4.5B) | poprzednia wersja | [basal-1.5-4.5B](https://huggingface.co/Remek/basal-1.5-4.5B) |
| [Claude Sonnet 5.5, przegląd modelu](https://platform.claude.com/docs/en/models/sonnet-5-5/overview) | model czatu zmieniony (D-10); eskalacja do Sonnet 5.5 odrzucona (D-29) | [przegląd Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/overview) |
| [Bankier, kary i tokeny KSeF](https://www.bankier.pl/wiadomosc/KSeF-dobre-wiesci-dla-firm-Wbrew-zapowiedziom-tokeny-jednak-nie-znikna-a-kary-za-bledy-zostana-odroczone-9198040.html) | źródło prasowe | [komunikat MF z 16.09.2026](https://www.gov.pl/web/finanse/przedluzenie-odroczenia-kar-za-bledy-w-stosowaniu-ksef-do-konca-2027-r) |
| [pit.pl, tokeny KSeF](https://www.pit.pl/aktualnosci/ksef-wbrew-zapowiedziom-tokeny-jednak-zostana-kto-najwiecej-na-tym-zyska) | źródło prasowe | [MF, certyfikaty KSeF](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/), [CIRFMF #834](https://github.com/CIRFMF/ksef-api/issues/834), [Dz.U. 2025 poz. 1815](https://api.sejm.gov.pl/eli/acts/DU/2025/1815) |
| [mcp-wl-vat, limity białej listy](https://github.com/pwasniowski/mcp-wl-vat) | źródło wtórne | [API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat) |
| [vies-vat-validation-php-sdk-rest](https://github.com/rocketfellows/vies-vat-validation-php-sdk-rest) | biblioteka, nie źródło API | [VIES API (swagger)](https://ec.europa.eu/assets/taxud/vow-information/swagger_publicVAT.yaml) |
| [porownajksiegowosc.pl, JPK dla JDG](https://porownajksiegowosc.pl/blog/jpk-ksiegi-jdg-od-kiedy-wysylka-2027/) | źródło wtórne | [broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf), [ustawa o ryczałcie](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf) |
| [poradnikprzedsiebiorcy.pl, odbiór wiadomości](https://poradnikprzedsiebiorcy.pl/-e-doreczenia-jak-odebrac-wiadomosc) | źródło wtórne | [PT UA API 5.27, § 3.3.1.6](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf), [u.d.e., art. 41](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf) |
| [Autenti, integracja API e-Doręczeń](https://autenti.com/pl/blog/e-doreczenia-api-integracja) | źródło wtórne | [Interfejsy API](https://www.gov.pl/web/e-doreczenia/interfejsy-api) |
| [rp.pl, e-Doręczenia od 1.10.2026](https://www.rp.pl/biznes/art45191561-e-doreczenia-od-1-pazdziernika-2026-r-jak-przygotowac-firme-na-zmiany-przepisow) | źródło prasowe | [gov.pl, 16.09.2026](https://www.gov.pl/web/e-doreczenia/e-doreczenia-1-pazdziernika-2026-r-wazny-termin-dla-przedsiebiorcow-z-ceidg) |

### Akty prawne i Sejm

- [Ustawa o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) – tekst jednolity; zastępuje nieaktualny Dz.U. 2025 poz. 775.
- [Zmiana ustawy o VAT, Dz.U. 2026 poz. 1270](https://api.sejm.gov.pl/eli/acts/DU/2026/1270/text.pdf) – ustawa z 4.09.2026.
- [Ustawa z 5.08.2025 zmieniająca terminy KSeF, Dz.U. 2025 poz. 1203](https://api.sejm.gov.pl/eli/acts/DU/2025/1203/text.pdf).
- [Ustawa o PIT, Dz.U. 2026 poz. 592](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf) – zastępuje nieaktualny Dz.U. 2025 poz. 163.
- [Ustawa o ryczałcie, Dz.U. 2025 poz. 843](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf).
- [Zmiana PIT, CIT i ryczałtu, Dz.U. 2026 poz. 779](https://api.sejm.gov.pl/eli/acts/DU/2026/779/text.pdf).
- [Ustawa o osobistych kontach inwestycyjnych, Dz.U. 2026 poz. 1098](https://api.sejm.gov.pl/eli/acts/DU/2026/1098/text.pdf) – zmienia m.in. ustawę o ryczałcie od 1.01.2027.
- [Ustawa o CIT, Dz.U. 2026 poz. 554](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf).
- [Ordynacja podatkowa, Dz.U. 2026 poz. 622](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf) i [zmiana, Dz.U. 2026 poz. 846](https://api.sejm.gov.pl/eli/acts/DU/2026/846/text.pdf).
- [Ustawa o doręczeniach elektronicznych, Dz.U. 2026 poz. 3](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf) i [wersja z oznaczeniem tekstu](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text/O/D20260003.pdf).
- [Ustawa o KAS, Dz.U. 2025 poz. 1131](https://api.sejm.gov.pl/eli/acts/DU/2025/1131/text.pdf).
- [Ustawa o systemie ubezpieczeń społecznych, Dz.U. 2026 poz. 199](https://api.sejm.gov.pl/eli/acts/DU/2026/199/text.pdf).
- [Ustawa o świadczeniach opieki zdrowotnej, Dz.U. 2025 poz. 1461](https://eli.gov.pl/api/acts/DU/2025/1461/text/T/D20251461L.pdf).
- [Prawo przedsiębiorców, Dz.U. 2025 poz. 1480](https://api.sejm.gov.pl/eli/acts/DU/2025/1480/text.pdf).
- [Ustawa o dniach wolnych od pracy, Dz.U. 2025 poz. 296](https://api.sejm.gov.pl/eli/acts/DU/2025/296/text.pdf).
- [Rozporządzenie o korzystaniu z KSeF, Dz.U. 2025 poz. 1815](https://api.sejm.gov.pl/eli/acts/DU/2025/1815) i [zmiana, Dz.U. 2026 poz. 169](https://api.sejm.gov.pl/eli/acts/DU/2026/169/text.pdf).
- [Rozporządzenie o ewidencji przychodów i wykazie, Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf).
- [Druk 3147 (UD458), proces](https://api.sejm.gov.pl/sejm/term10/processes/3147) i [treść druku](https://api.sejm.gov.pl/sejm/term10/prints/3147/3147.pdf).
- [Druk 2453, proces](https://api.sejm.gov.pl/sejm/term10/processes/2453).
- [Projekt zwolnienia z wykazu i JPK_ST, RCL](https://legislacja.gov.pl/projekt/12413609).
- [eIDAS, art. 3](https://www.legislation.gov.uk/eur/2014/910/article/3) – tekst z legislation.gov.uk, bo EUR-Lex blokuje automaty.
- [Rozporządzenie wykonawcze Rady (UE) nr 282/2011](https://eur-lex.europa.eu/eli/reg_impl/2011/282/oj) – art. 18, niepobrany; punkt w „Do ustalenia”.
- [RODO, tekst UODO](https://uodo.gov.pl/pl/file/1460).
- [AI Act, art. 50](https://artificialintelligenceact.eu/article/50/).

### KSeF

- SPEC: [KSeF 2.0, przewodnik dla integratorów (MF)](https://github.com/CIRFMF/ksef-api).
- SPEC: [KSeF API v2, dokumentacja środowiska testowego](https://api-test.ksef.mf.gov.pl/docs/v2/index.html) – sprawdzać razem z `open-api.json`.
- SPEC: [Certyfikaty i tokeny KSeF (MF)](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/).
- SPEC: [n1ebieski/ksef-php-client (Packagist)](https://packagist.org/packages/n1ebieski/ksef-php-client).
- [KSeF API, open-api.json](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json).
- [Changelog API KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md).
- [Uwierzytelnianie](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/uwierzytelnianie.md).
- [Środowiska KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/srodowiska.md).
- [Limity API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/limity/limity-api.md).
- [Przyrostowe pobieranie faktur](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/pobieranie-faktur/przyrostowe-pobieranie-faktur.md).
- [Weryfikacja faktury](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/weryfikacja-faktury.md).
- [Schemat FA(3) 1-0E](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/faktury/schemy/FA/schemat_FA%283%29_v1-0E.xsd).
- [Kody QR](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/kody-qr.md).
- [Tryby offline](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/tryby-offline.md).
- [Automatyczne określanie trybu offline](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/offline/automatyczne-okreslanie-trybu-offline.md).
- [Latarnia, scenariusze](https://raw.githubusercontent.com/CIRFMF/ksef-latarnia/main/scenariusze.md), [README](https://raw.githubusercontent.com/CIRFMF/ksef-latarnia/main/README.md) i [środowiska](https://raw.githubusercontent.com/CIRFMF/ksef-latarnia/main/srodowiska.md).
- [CIRFMF #834, tokeny po 2026](https://github.com/CIRFMF/ksef-api/issues/834).
- [ksef-pdf-generator (MF)](https://github.com/CIRFMF/ksef-pdf-generator).
- [README ksef-php-client](https://raw.githubusercontent.com/N1ebieski/ksef-php-client/main/README.md) i [UPGRADE.md](https://github.com/N1ebieski/ksef-php-client/blob/main/UPGRADE.md).
- [MF, sprzedaż do 10 000 zł](https://ksef.podatki.gov.pl/ponizej-10-000-zl/).
- [MF, zasady obowiązywania KSeF](https://ksef.podatki.gov.pl/ksef-news/zasady-obowiazywania-ksef-i-przepisy-prawne/).
- [MF, pytania i odpowiedzi KSeF 2.0](https://ksef.podatki.gov.pl/pytania-i-odpowiedzi-ksef-20/).
- [MF, JDG i MŚP](https://ksef.podatki.gov.pl/jdg-i-msp/).
- [MF, tryb offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/).
- [MF, kody weryfikujące QR](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/kody-weryfikujace-qr/).
- [MF, integratorzy IT](https://ksef.podatki.gov.pl/integratorzy-it/).
- [MF, podsumowanie konsultacji](https://ksef.podatki.gov.pl/wyjasnienia/pierwsze-konsultacje-po-czesciowym-wdrozeniu-ksef-podsumowanie/).
- [Podręcznik KSeF 2.0, cz. II (06.08.2026)](https://ksef.podatki.gov.pl/media/rronoxyt/podrecznik-ksef-20-cz-ii-wystawianie-i-otrzymywanie-faktur-w-ksef-06082026.pdf).
- [Broszura FA(3)](https://ksef.podatki.gov.pl/media/nuclnu0w/broszura-informacyjna-dotyczaca-struktury-logicznej-fa-3.pdf).
- [Komunikat MF o odroczeniu kar (16.09.2026)](https://www.gov.pl/web/finanse/przedluzenie-odroczenia-kar-za-bledy-w-stosowaniu-ksef-do-konca-2027-r).

### JPK, e-Deklaracje i podatki

- SPEC: [Generator mikrorachunku podatkowego](https://www.podatki.gov.pl/generator-mikrorachunku-podatkowego).
- [Mikrorachunek podatkowy](https://www.podatki.gov.pl/mikrorachunek-podatkowy).
- [Specyfikacja interfejsów usług JPK 5.6.0](https://www.podatki.gov.pl/media/bngboylg/specyfikacja-interfejs%C3%B3w-us%C5%82ug-jpk-wersja-560.pdf).
- [Broszura JPK_V7M(3)](https://www.podatki.gov.pl/media/wgbkrejs/broszura-jpk_vat-z-deklaracj%C4%85-od-1-lutego-2026-r.pdf) i [objaśnienia znaczników v11](https://podatki.gov.pl/media/o1jppu0e/jpk_vat-z-deklaracja-stosowanie-znacznikow_v11.pdf).
- [Broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf).
- [Broszura JPK_ST(1)](https://podatki.gov.pl/media/jikpuwwp/broszura_informacyjna_dot_jpk_st-1.pdf).
- [MF, nowe wzory struktur JPK_VAT](https://www.podatki.gov.pl/aktualnosci/nowe-wzory-struktur-jpk_vat-dostepne-na-epuap).
- [Struktury JPK](https://www.gov.pl/web/kas/struktury-jpk) i [struktury JPK w podatkach dochodowych](https://www.gov.pl/web/kas/struktury-jpk-w-podatkach-dochodowych).
- [Pliki do pobrania JPK_VAT z deklaracją](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_vat-z-deklaracja/pliki-do-pobrania) i [pliki do pobrania JPK_VAT](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_vat/pliki-do-pobrania-jpk-vat).
- [Bezpłatne narzędzia MF dla JPK w podatkach dochodowych](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_pd/bezplatne-narzedzia).
- [XSD SIG-2008_v2-0](https://podatki-arch.mf.gov.pl/media/10553/sig-2008_v2-0.xsd).
- [Struktury dokumentów XML e-Deklaracji](https://podatki-arch.mf.gov.pl/e-deklaracje/dokumentacja-it/struktury-dokumentow-xml/).
- [Schemat VAT-UE(5)](http://crd.gov.pl/wzor/2021/01/12/10293/schemat.xsd) i [ElementarneTypyDanych v6-0E](http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2020/03/11/eD/DefinicjeTypy/ElementarneTypyDanych_v6-0E.xsd).
- [Specyfikacja wejścia-wyjścia e-Deklaracji 2.4](https://podatki-arch.mf.gov.pl/media/3616/edek_specyfikacja_we-wy_2-4_test_www.pdf).
- [e-Deklaracje, pytania i odpowiedzi](https://podatki.gov.pl/e-deklaracje-zloz-deklaracje-podatkowa-online/pytania-i-odpowiedzi).

### e-Doręczenia

- [Interfejsy API e-Doręczeń](https://www.gov.pl/web/e-doreczenia/interfejsy-api).
- [PT UA API 5.27](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf).
- [Instrukcja INT v1.9.6](https://www.gov.pl/attachment/09ea071e-255f-491a-96fe-8ae5cd17ffc3) i [v1.9.7](https://www.gov.pl/attachment/b0bbf5ef-8909-43ff-851d-d1183e96d02a).
- [Instrukcja PROD v1.9](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce).
- [Załącznik 1c v1.5, dodanie systemu](https://www.gov.pl/attachment/0c5a5272-b629-4a57-8c16-7935c6cb7037).
- [Regulamin INT](https://www.gov.pl/attachment/f8d3d63b-74ca-4c89-b35b-773eaf2a924a).
- [Kwalifikowani dostawcy usług](https://www.gov.pl/web/e-doreczenia/kwalifikowani-dostawcy-uslug-kdu3).
- [gov.pl, e-Doręczenia od 1.10.2026 dla firm z CEIDG](https://www.gov.pl/web/e-doreczenia/e-doreczenia-1-pazdziernika-2026-r-wazny-termin-dla-przedsiebiorcow-z-ceidg).

### Rejestry i NBP

- SPEC: [Otwarte API KRS](https://prs.ms.gov.pl/krs/openApi).
- SPEC: [CEIDG, otwarte API](https://otwarteapi.pl/ceidg).
- SPEC: [API REGON (BIR)](https://api.stat.gov.pl/Home/RegonApi).
- [API Wykazu podatników VAT](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat), [specyfikacja API 1.6.0](https://www.gov.pl/attachment/64f1f32b-b979-4b4d-8ace-57e54c6f1082) i [Wykaz podatników VAT](https://www.gov.pl/web/kas/wykaz-podatnikow-vat).
- [VIES API (swagger)](https://ec.europa.eu/assets/taxud/vow-information/swagger_publicVAT.yaml) i [usługa testowa VIES](https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service).
- [API NBP](https://api.nbp.pl/en.html) i [zapytanie o kursy EUR z końca 2025](https://api.nbp.pl/api/exchangerates/rates/a/eur/2025-12-20/2025-12-31/).

### ZUS

- [Składka zdrowotna w 2026 (ZUS)](https://www.zus.pl/en/-/informacja-w-sprawie-podstawy-wymiaru-sk%C5%82adki-oraz-kwoty-sk%C5%82adki-na-ubezpieczenie-zdrowotne-w-2026-r.).
- [Roczne rozliczenie składki zdrowotnej (ZUS)](https://www.zus.pl/en/-/roczne-rozliczenie-sk%C5%82adki-zdrowotnej-za-2025-r.).

### FreshBooks

- [Start API](https://www.freshbooks.com/api/start), [uwierzytelnianie](https://www.freshbooks.com/api/authentication), [faktury](https://www.freshbooks.com/api/invoices), [płatności](https://www.freshbooks.com/api/payments) i [limity](https://www.freshbooks.com/api/limits).

### Anthropic i Claude

- SPEC: [Effort](https://platform.claude.com/docs/en/build-with-claude/effort).
- SPEC: [API i retencja danych](https://platform.claude.com/docs/en/manage-claude/api-and-data-retention).
- SPEC: [PHP SDK](https://platform.claude.com/docs/en/cli-sdks-libraries/sdks/php).
- [Przegląd Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/overview) i [przewodnik migracji](https://platform.claude.com/docs/en/models/haiku-5-5/migration-guide).
- [Cennik](https://platform.claude.com/docs/en/about-claude/pricing).
- [Rezydencja danych](https://platform.claude.com/docs/en/manage-claude/data-residency).
- [Managed Agents, przegląd](https://platform.claude.com/docs/en/managed-agents/overview).
- [DPA](https://privacy.claude.com/en/articles/7996862-how-do-i-view-and-sign-your-data-processing-addendum-dpa) i [okres przechowywania danych](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data).

### basal

- SPEC: [basal, strona projektu](https://basal.si5.pl/).
- SPEC: [rkinas/basal, silnik](https://github.com/rkinas/basal); [server.py](https://github.com/rkinas/basal/blob/main/basal/server.py) i [wymagania sprzętowe](https://github.com/rkinas/basal/blob/main/docs/HARDWARE.md).
- [basal-1.5-4.5B, karta modelu](https://huggingface.co/Remek/basal-1.5-4.5B).

### Infrastruktura i narzędzia

- [Symfony Secrets](https://symfony.com/doc/current/configuration/secrets.html), [Messenger](https://symfony.com/doc/current/messenger.html) i [Scheduler](https://symfony.com/doc/current/scheduler.html).
- [WebAuthn Level 3](https://www.w3.org/TR/webauthn-3/).
- [Tailscale, HTTPS](https://tailscale.com/kb/1153/enabling-https).
- [ntfy, konfiguracja](https://docs.ntfy.sh/config/) i [FAQ](https://docs.ntfy.sh/faq/).
- [restic](https://restic.readthedocs.io/).
- [Docker, Metal na macOS](https://www.docker.com/blog/docker-model-runner-vllm-metal-macos/).
- [mpdf/mpdf, licencja (Packagist)](https://repo.packagist.org/p2/mpdf/mpdf.json).
- [Claude Code: pamięć](https://code.claude.com/docs/en/memory), [uprawnienia](https://code.claude.com/docs/en/permissions) i [sandbox](https://code.claude.com/docs/en/sandboxing).

## Pokrycie

| Źródło | Miejsce w tym pliku |
| --- | --- |
| dawna sekcja „Ryzyka i rzeczy do sprawdzenia”: 9 wierszy tabeli | R-01 do R-09 |
| dawny akapit o odroczeniu kar KSeF | „Obserwacja prawa”, R-10 |
| dawna sekcja „Źródła” i linki w tekście dawnej specyfikacji | „Źródła”, w tym tabela zastąpionych |
| luka `legal-watch-facts` | rejestr faktów z datami, „Obserwacja prawa”, krok 0 w „Jak czytać” |
| luki `ksef-credentials-lifecycle`, `edoreczenia-api-v3-oidc`, `basal-version-calibration` (wiersze ryzyk) | R-01, R-03, R-09 |
| luka `jpk-st-wykaz` (obserwacja) | „Obserwacja prawa”, R-33 |
| sprzeczność kanału VAT-UE z planu (Q-32) | „Kanał VAT-UE: źródła” |
| przegląd z 8.10.2026 (archiwum poza repo): S3, S4, S5, S8, S9, sekcja 3 | fakty art. 28a i 100 ust. 1 pkt 4, art. 19a ust. 8 ustawy o VAT, art. 29, 41 i 42 ustawy o PIT; FreshBooks bez sandboxu; „Do ustalenia”; R-11, R-18, R-25, R-44–R-46 |
| decyzje D-28 do D-35 z 8.10.2026 | daty „Następne sprawdzenie” F4 i F7 (D-29); R-02, R-03, R-09, R-12–R-15, R-37, R-42 |
