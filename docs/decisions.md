# Decyzje i otwarte pytania

Otwarte pytania z założeniami domyślnymi oraz rejestr decyzji (ADR) kokpitu. Stan na 8 października 2026.

## Jak używać

- Krok 0 fazy: przeczytaj wiersze z tą fazą w kolumnie „Potrzebne w”.
- W kroku 0 sesja wypisuje otwarte pytania fazy z założeniami domyślnymi w części „pytania” planu. Faza idzie na założeniu domyślnym, bez czekania na odpowiedź. Stop i pytanie tylko w przypadkach z kroku 0 pkt 4 w [../.claude/rules/zasady-twarde.md](../.claude/rules/zasady-twarde.md), np. przy wejściu „stop” w pliku fazy albo [F0]. Rekord D-xx zostaje „proponowana” do Twojej odpowiedzi. Wiersz [F0] blokuje start F0a bez wyjątku.
- Odpowiedź wpisujesz z datą. Trwała decyzja dostaje rekord D-xx w sekcji „Decyzje (ADR)”.
- Id są stałe. Nowe pytanie dostaje kolejny numer, bez przenumerowania.
- Nazwy encji, stanów i zmiennych pochodzą z kontraktu nazw w [naming.md](naming.md).
- Domyślnym właścicielem jesteś Ty; wyjątki w kolumnie „Właściciel”.
- Status rekordu D-xx: `przyjęta` to Twoja decyzja albo zatwierdzony plan. `proponowana` oznacza, że dokumentacja stosuje założenie domyślne z pytania. `zastąpiona` wskazuje rekord następcy.
- Dopisek „(źródła …)” przy `przyjęta` oznacza rozstrzygnięcie weryfikacją źródła zleconą w planie. Test w fazie może ją zastąpić nowym rekordem.

## Pytania

### Rozstrzygnięte

| Id | Pytanie | Właściciel | Dlaczego | Potrzebne w | Założenie domyślne | Odpowiedź | Data |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Q-01 | Rozliczasz VAT miesięcznie (JPK_V7M)? | Ty | od tego zależy, czy ewidencja 2026 i JPK_EWP za 2026 są wymagane, oraz terminy JPK | F2, F5 | – | Tak, miesięcznie, JPK_V7M. Pierwszy JPK_EWP za 2026, termin 30.04.2027 ([broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf)); komentarz w dokumencie claude.ai rozstrzygnięty. D-02 | 2026-10-08 |
| Q-02 | Kto składa JPK_V7M, VAT-UE, JPK_EWP i JPK_ST oraz PIT-28: biuro rachunkowe czy Ty? Jaki dostęp dla biura? | Ty | decyduje, czy kokpit wysyła, czy tylko eksportuje | F2, F5 | – | Ty składasz wszystko, bez biura. Do go-live faz narzędzia MF albo obecne narzędzie; potem kokpit przez bramkę dla KSeF, JPK_EWP/ST, JPK_V7M i VAT-UE; PIT-28 i płatności ręcznie. D-03 | 2026-10-08 |
| Q-03 | Tylko B2B? Klienci konsumenci albo usługi elektroniczne dla konsumentów? | Ty | B2C zmienia miejsce świadczenia, OSS, mapowanie FA(3) i zakres VAT-UE | F3 | – | Tylko B2B: klienci z UE, spoza UE i z Polski. VAT-UE w zakresie, OSS poza zakresem. D-05 | 2026-10-08 |
| Q-04 | Kolejność faz: EWP przed V7M, e-Doręczenia wcześniej, czat i basal po JPK_EWP? | Ty | dawna kolejność nie dawała zapasu przed 30.04.2027 | wszystkie | – | Pierwsza odpowiedź: F0a → F0b → F1 → F2 → F3 → F4 → F5 → F6 → F7 (D-06). Zmieniona tego samego dnia: F0a (wąska) → F2.1 → F2.4 → dry run → F0b → F1 → F2.2/F2.3 → F3 → F5 → F6; F4 PROD i F7 odłożone. Id faz bez zmian. D-30, D-29 | 2026-10-08 |
| Q-05 | Model czatu: Claude Haiku 5.5 czy Sonnet 5.5? | Ty | koszt pytania i profil żądań | F6 | – | Tylko Claude Haiku 5.5 `claude-haiku-5-5`. Eskalacja do Sonnet 5.5 odrzucona (Q-36, D-29). D-10 | 2026-10-08 |
| Q-06 | Managed Agents czy własna pętla narzędzi? | Ty | gdzie działa pętla i gdzie są dane | F6 | – | Managed Agents odrzucone. Messages API i PHP tool runner, pętla w Symfony. D-11 | 2026-10-08 |
| Q-07 | Kto zatwierdza mapowanie FA(3) i stawki ryczałtu, skoro nie ma biura? | Ty | błędne oznaczenie usług zagranicznych oznacza korekty faktur | F3 | – | Ty oraz jednorazowy przegląd doradcy podatkowego jako bramka przed go-live F3. Zakres w Q-29. D-04 | 2026-10-08 |
| Q-08 | Repo publiczne czy prywatne? | Ty | dane w fixtures i dokumentacji, licencja | F0a | – | Prywatne `good-yellow-bee/kokpit-jdg`. Licencja przed ewentualnym upublicznieniem. Fixtures i docs bez prawdziwych NIP, PESEL i IBAN. D-24 | 2026-10-08 |
| Q-09 | Plany płatne dla innych firm teraz czy później? | Ty | wpływ na model danych i zakres | – | – | Odłożone. Notatki poza repo; specyfikacja dla jednego użytkownika. D-25 | 2026-10-08 |
| Q-32 | Kanał VAT-UE: wysyłka z danymi autoryzującymi (`sendUnsignDocument`) czy tylko z podpisem kwalifikowanym? | Ty | źródła były sprzeczne: specyfikacja e-Deklaracji i badanie planów płatnych | F5 | – | Dane autoryzujące przez `sendUnsignDocument`. Sprawdzone 8.10.2026 w [specyfikacji e-Deklaracji 2.4](https://podatki-arch.mf.gov.pl/media/3616/edek_specyfikacja_we-wy_2-4_test_www.pdf): 422 i 423 – tylko podatnik osoba fizyczna, nie pełnomocnik; 418 – deklaracja danego typu wymaga podpisu kwalifikowanego (typ podpisu per formularz: rozporządzenie MF, Dz.U. 2018 poz. 1106). Szczegóły w [modules/jpk.md](modules/jpk.md) i [facts.md](facts.md). Otwarte do F5: działanie bramki testowej, brak 418 dla VAT-UE i kod 410 dla VAT-UEK. D-27 | 2026-10-08 |
| Q-36 | Czy włączyć eskalację do Sonnet 5.5? | Ty | koszt i profil żądań przy trudnych pytaniach | F6 | wyłączona; model w konfiguracji | Nie. Czat tylko na Claude Haiku 5.5. Eskalacja i warunek powrotu są w [roadmap.md](roadmap.md). D-29 | 2026-10-08 |
| Q-37 | basal: budować w F7 (tryb cienia przez miesiące) czy odłożyć i zacząć od kategorii faktur? | Ty | zbieranie etykiet trwa miesiące; zasoby serwera | F7 | decyzja po F6; jeśli tak, start od kategorii faktur w trybie cienia | Odłożone. [modules/basal.md](modules/basal.md) zostaje z oznaczeniem „odłożone”. Tabela `classification` i reguły basal wypadają ze schematu F0a i z `.claude/rules`. Powrót w [roadmap.md](roadmap.md). D-29 (zastępuje D-26) | 2026-10-08 |
| Q-39 | Dane testowe KSeF TE (podmioty przez `/testdata`, drugi sprzedawca testowy, certyfikat self-signed, tokeny odczytu i `InvoiceWrite`) wytwarza sesja F1 pomocnikiem w `tests/` czy Ty ręcznie? | Ty | warunek startu F1; TE pozwala wytworzyć podmioty, certyfikat i tokeny przez API („KSeF TE” w [testing.md](testing.md)) | F1 | sesja F1, tylko na TE i tylko z fikcyjnymi NIP | Sesja F1, pomocnikiem w `tests/` przez `/testdata`. Tylko TE i fikcyjne NIP. Bez bramki „stop” na starcie F1: kryterium `live` w sesji 1 najpierw wytwarza dane TE. Przegląd S7 (przegląd z 8.10.2026, archiwum poza repo) | 2026-10-08 |
| Q-44 | Czy FreshBooks dostaje osobną firmę testową do F2 i F3? | Ty | dokumentacja API nie opisuje sandboxu ([FreshBooks API, 8.10.2026](https://www.freshbooks.com/api/start)); druga firma kosztuje (informacja właściciela, przegląd S8) | F2.2/F2.3, F3 | – | Nie. F2 i F3 na ręcznie pisanych fixtures i prawdziwej firmie tylko do odczytu. Zapis zwrotny F3 pierwszy raz na PROD: jedna faktura przez bramkę zatwierdzeń, wynik w dowodach fazy. D-35 | 2026-10-08 |

### Otwarte

| Id | Pytanie | Właściciel | Dlaczego | Potrzebne w | Założenie domyślne | Odpowiedź | Data |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Q-10 | Jak wystawiasz faktury w KSeF od 1.04.2026 (narzędzie, seria numeracji)? Czy któryś miesiąc przekroczył 10 tys. zł brutto? | Ty | plan przejścia, import Subject1, kolizje P_2, procedura tymczasowa ([MF](https://ksef.podatki.gov.pl/ponizej-10-000-zl/)) | teraz, F1, F2, F3 | Aplikacja Podatnika KSeF z numeracją z FreshBooks | | |
| Q-11 | Ewidencja przychodów 2026: gdzie i jak prowadzona? Którym narzędziem robisz wpisy od 09.2026 i jaki ma format eksportu (dopuszczalne formaty: „Format importu” w [modules/ewidencja.md](modules/ewidencja.md))? Import z oryginalnymi datami wpisu czy rekonstrukcja? | Ty | źródło prawdy za 2026, wartości K_2, wejście importu w F2.1; wpis za 09.2026 należy się do 20.10.2026 (§ 6 [rozporządzenia, Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf)) | do 20.10.2026, F2.1 | do 20.10.2026 narzędzie wybrane i wpisy za 09.2026 zrobione; F2.1 importuje 2026 z CSV albo eksportu JPK_EWP z policzonymi K_8 i K_9 (D-30); K_2 bez antydatowania (D-23) | | |
| Q-12 | Kto i czym składa dziś JPK_V7M? Czy jesteś zarejestrowany do VAT-UE (art. 97 ust. 3 [ustawy o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf))? Jak ujmujesz import usług w K_27–K_30? | Ty | zgodność teraz, zakres F5, import usług to R-18 w [facts.md](facts.md) | F5 | Ty, e-mikrofirma albo Klient JPK WEB; klasyfikację dostawców potwierdza przegląd doradcy (Q-21, Q-29) | | |
| Q-13 | Czy ADE firmy jest aktywny, a powiadomienia mailowe włączone? Czy masz prywatny ADE? Czy wyraziłeś zgodę na e-Korespondencję w e-US? | Ty | obowiązek biegnie od 1.10.2026; decyduje, które kanały monitorować ([gov.pl](https://www.gov.pl/web/e-doreczenia/e-doreczenia-1-pazdziernika-2026-r-wazny-termin-dla-przedsiebiorcow-z-ceidg)) | do 11.10.2026, F0b | ADE aktywny, powiadomienia przetestowane do 11.10.2026; prywatny ADE i e-US sprawdzane ręcznie raz w tygodniu | | |
| Q-14 | [F0] Hosting: VPS w UE, serwer domowy czy Mac? W jakim kraju? Ile RAM? Tailscale (D-28) jako sidecar czy na hoście? TLS: `tailscale cert` (nazwa w logach CT) czy własna domena z DNS-01 i tokenem API DNS? | Ty | blokuje start F0a; passkey wymaga TLS na stałej nazwie, przechowywanie faktur poza Polską (art. 112a, R-28 w [facts.md](facts.md)), sekrety hosta ([security.md](security.md)), backup | teraz (decyzja w tym tygodniu), F0a | VPS w Polsce albo w innym kraju UE z dostępem on-line dla organów (art. 112a ust. 2–3). Na VPS tylko projekt prod (D-28), więc RAM ≥ 2 GB ([operations.md](operations.md)). Dev na laptopie, test odtworzenia w jednorazowym projekcie lokalnym. Sidecar Tailscale; `tailscale cert` na neutralnej nazwie węzła. Stały IPv4 potrzebny dopiero przy powrocie do F4 (lista IP na INT, D-29) | Założenie domyślne (D-18). | 2026-10-09 |
| Q-15 | Kanał push: self-hosted ntfy czy ntfy.sh z tokenem? | Ty | prywatność treści powiadomień i dostarczanie na iOS; Web Push w PWA i Telegram odpadają (D-28) | F0a | self-hosted ntfy; treść to kategoria, liczba i link | | |
| Q-16 | ZUS: wariant składek społecznych albo okres preferencyjny? Próg składki zdrowotnej z przychodu bieżącego czy poprzedniego roku (art. 81 ust. 2h [ustawy o świadczeniach, Dz.U. 2025 poz. 1461](https://eli.gov.pl/api/acts/DU/2025/1461/text/T/D20251461L.pdf))? Czy potrzebna miesięczna DRA? | Ty | kwoty w kalendarzu i odliczenia od zaliczki | F0b, F2 | pełne składki społeczne; próg zdrowotnej z przychodu bieżącego roku; DRA do sprawdzenia | | |
| Q-17 | Zaliczka na ryczałt co miesiąc czy co kwartał? | Ty | terminy w kalendarzu i wyliczenie zaliczki | F0b, F2 | co miesiąc; wpłata co kwartał tylko przy przychodzie z poprzedniego roku do 200 tys. EUR (art. 21 ust. 1a i 1b [ustawy o ryczałcie, Dz.U. 2025 poz. 843](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)) | | |
| Q-18 | Kto Cię zastępuje przy terminie, gdy Cię nie ma? Kogo wyznaczasz administratorem skrzynki ADE? Czy ustanawiasz UPL-1 albo inne pełnomocnictwo? | Ty | jedna osoba oznacza przerwę w składaniu i odbiorze pism; administrator skrzynki obsługuje korespondencję w imieniu przedsiębiorcy ([gov.pl, 16.09.2026](https://www.gov.pl/web/e-doreczenia/e-doreczenia-1-pazdziernika-2026-r-wazny-termin-dla-przedsiebiorcow-z-ceidg)) | do 11.10.2026 (administrator ADE), F0a, F0b, przed go-live F3 | administrator skrzynki ADE wyznaczony do 11.10.2026 jako zastępca przy odbiorze pism (przegląd S9); poza skrzynką zastępcy brak; procedura break-glass w [operations.md](operations.md); UPL-1 do rozważenia | | |
| Q-19 | Biblioteka KSeF PHP: 1.10.x czy 2.0.0-rc.1? | Ty | API QR i kryptografii różni się między liniami ([Packagist](https://packagist.org/packages/n1ebieski/ksef-php-client)) | F1 | 1.10.x przypięta do dokładnej wersji, za interfejsem `KsefGateway`; migracja po wydaniu stabilnej 2.0 | | |
| Q-20 | Rejestry: KRS, CEIDG i GUS zostają czy tylko VIES, biała lista i NBP? | Ty | koszt onboardingu (JWT CEIDG, klucz BIR) wobec wartości przy kontrahentach głównie zagranicznych | F1 | tylko VIES, biała lista i NBP; wiersze KRS, CEIDG i GUS wypadają z tabel konektorów i trafiają do [roadmap.md](roadmap.md) (D-29) | | |
| Q-21 | Zagraniczni dostawcy: traktowanie VAT (K_27–K_30, opłaty Stripe opodatkowane czy zwolnione) i klasyfikacja WHT (licencja, przetwarzanie danych, brak)? Czy masz certyfikaty rezydencji? Jaki moment podatkowy ma przedpłata (kredyty Anthropic)? Czy płacisz zagranicznym osobom fizycznym? | Ty z doradcą | JPK po stronie zakupów, pobór WHT i IFT-2R ([Dz.U. 2026 poz. 554](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf)); przy przedpłacie obowiązek powstaje z dniem zapłaty (art. 19a ust. 8 [ustawy o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)), a koszt bierze dziś datę z P_6, OkresFa albo P_1 | F0b, F1, F5 | import usług dla Anthropic, FreshBooks i hostingu; klasyfikacja WHT w przeglądzie doradcy (Q-29); certyfikaty rezydencji zbierasz co roku. Przedpłata: ręczna zmiana `tax_point_date` z powodem na wierszu kosztu (przegląd S4). Zagraniczna osoba fizyczna: WHT z art. 29 [ustawy o PIT, Dz.U. 2026 poz. 592](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf), nie z ustawy o CIT; uwaga przy `counterparty.is_natural_person`; do weryfikacji (przegląd S5) | | |
| Q-22 | Jakie usługi fakturujesz i jaki mają kod PKWiU 2015 oraz stawkę ryczałtu? Czy coś rozliczasz okresowo (abonament)? | Ty z doradcą | stawka per pozycja, K_9, podział odliczeń, daty przychodu | F2, F3 | jeden rodzaj usług; stawka do potwierdzenia w przeglądzie doradcy; brak rozliczeń okresowych | | |
| Q-23 | Czy wybierasz art. 31a ust. 2a (kurs VAT według zasad PIT, na co najmniej 12 miesięcy)? | Ty | kolumny kursów PIT i VAT przy fakturze | F2, F3 | bez wyboru; VAT według art. 31a ust. 1 i 2 | | |
| Q-24 | Wypłaty Stripe w EUR i USD czy w PLN? Czy kokpit ma śledzić płatności klientów? Metoda kasowa PIT od 2027 (wybór do 20 lutego, art. 14c [ustawy o PIT, Dz.U. 2026 poz. 592](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf))? | Ty | tabela `payment`, różnice kursowe w ewidencji, flaga Kasowy_PIT | F0b, F2 | śledzić płatności z FreshBooks w tabeli `payment`; waluta wypłat do sprawdzenia; bez metody kasowej | | |
| Q-25 | Czy masz środki trwałe albo WNiP do wykazu? | Ty | JPK_ST i tabela `fixed_asset`; projekt zwolnienia w toku ([projekt](https://legislacja.gov.pl/projekt/12413609)) | F2, F5 | brak; JPK_ST za flagą zależną od zwolnienia | | |
| Q-26 | Czy FreshBooks zostaje źródłem faktur? Jak oznaczasz szkic jako gotowy? Czy klient dostaje PDF z kokpitu z QR KSeF, czy mail albo link FreshBooks z zapisem zwrotnym? W jakim języku PDF? | Ty | znacznik gotowości, blokady wysyłki, zakres zapisu zwrotnego | F2.2/F2.3 (import), F3 | FreshBooks zostaje; znacznik w `po_number` albo tagu; klient dostaje PDF z kokpitu jako załącznik; PDF dwujęzyczny PL i EN | | |
| Q-27 | Jak dziś korygujesz faktury we FreshBooks: credit note, edycja czy anulowanie? | Ty | mapowanie na KOR i blokada edycji po przyjęciu | F3 | credit note tworzy szkic KOR w bramce; edycja po przyjęciu wymusza korektę | | |
| Q-28 | Przy awarii KSeF: czekać ze wstrzymaną kolejką czy wystawiać offline z certyfikatem typ 2? | Ty | zakres trybu offline i zakup certyfikatu typ 2 | F3 | czekać; Latarnia wstrzymuje kolejkę, panel pokazuje status; bez trybu offline | | |
| Q-29 | Zakres przeglądu doradcy: tylko mapowanie FA(3) (z P_18 dla nabywców spoza UE i złotymi plikami XML) i PKWiU→ryczałt, czy pełna lista z D-04? Dalsze punkty: VAT i WHT dostawców, okresy korekt (TypKorekty, VAT-UEK), odczyt daty fikcji doręczenia, zaokrąglenia zaliczki, stawka różnicy kursowej, K_4 i K_10 wiersza `fx_difference`, K_10 i późne K_2 w ewidencji 2026. Widok faktury w portalu FreshBooks jako wizualizacja bez kodu QR (art. 106gb ust. 5). Nabywca z UE bez ważnego numeru VIES (`eu_b2b_unidentified`). Moment podatkowy przedpłat i WHT osób fizycznych (Q-21). | Ty z doradcą | przegląd zastępuje akcepty, które dawniej przypisywano biuru rachunkowemu; podatnikiem jest prowadzący działalność (art. 28a pkt 1 lit. a), a VAT-UE obejmuje tylko nabywców zidentyfikowanych (art. 100 ust. 1 pkt 4 [ustawy o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)) | teraz (rezerwacja w tym tygodniu), go-live F3 | jedna konsultacja obejmuje wszystkie punkty; minimum to FA(3) i PKWiU→ryczałt. Pierwsze pytania to S3 i S4 z przeglądu. S3: `eu_b2b_unidentified` → `np I`, bez VAT-UE, `manual_evidence` statusu przedsiębiorcy; standard dowodu (art. 18 rozporządzenia wykonawczego 282/2011) do weryfikacji. S4: ręczny `tax_point_date` przedpłat (Q-21) | | |
| Q-30 | Dostawca ADE: Poczta Polska czy dostawca kwalifikowany? Czy pytamy COI o certyfikat dla JDG? Czy akceptujesz stały koszt certyfikatu kwalifikowanego do API PROD? | Ty, po odpowiedzi COI | wykonalność F4 w PROD; certyfikat kwalifikowany obowiązkowy dla podmiotów niepublicznych ([instrukcja PROD](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce)) | teraz (bramki 1–2 do 11.10.2026), powrót do F4 PROD po F5 | Poczta Polska; bramki 1–2 zapisujesz do 11.10.2026; pytanie do COI i zakup dopiero przy powrocie do F4 | F4 PROD odłożone: teraz bez wniosku INT, certyfikatu i pytania do COI. e-Doręczenia to powiadomienia mailowe, odbiór ręczny w aplikacji, `manual_channel_check` i termin w kalendarzu. Pytanie wraca po F5, gdy bramki 1–2 mają odpowiedzi. D-29 | 2026-10-08 |
| Q-31 | Nadwyżka VAT naliczonego: zwrot (który termin) czy przeniesienie? Kody GTU lub procedur? | Ty | logika deklaracji w JPK_V7M(3) | F5 | przeniesienie na następny okres; brak GTU i procedur | | |
| Q-33 | Claude API: transfer do USA na DPA z SCC czy region UE (Bedrock bez effort per wiadomość albo Google Cloud)? Jaka podstawa przetwarzania danych osobowych w czacie (art. 6 RODO)? | Ty | podstawa RODO, wybór dostawcy ([DPA](https://privacy.claude.com/en/articles/7996862-how-do-i-view-and-sign-your-data-processing-addendum-dpa)) | F6 | Claude API bezpośrednio, DPA z SCC; art. 6 ust. 1 lit. f RODO ([modules/czat.md](modules/czat.md)) | | |
| Q-34 | Po powrocie F4: maskowana główna treść pisma z limitem długości czy pełna maskowana treść z załącznikami? | Ty | zakres maskowania i dane opuszczające serwer | F6, po powrocie F4 | maskowana główna treść z limitem długości, bez załączników; streszcza model | | |
| Q-35 | Szkice z czatu wysyła kokpit czy tylko kopiujesz? | Ty | wykonawca szkicu i ryzyko podmiany odbiorcy lub rachunku | F0b (schemat), F6 | tylko kopiowanie | | |
| Q-38 | Forma opodatkowania na 2027, jeśli UD458 wejdzie, a przychód 2026 przekroczy 250 tys. EUR? | Ty | prawo do ryczałtu w 2027 i stawka 17% ([proces druku 3147](https://api.sejm.gov.pl/sejm/term10/processes/3147)) | F0b (przypomnienie), poza fazami: do 20.02.2027 przy przychodzie w styczniu (art. 9 ust. 1 [ustawy o ryczałcie, Dz.U. 2025 poz. 843](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)) | ryczałt, jeśli przychód 2026 nie przekroczy 250 tys. EUR; metryka przychodu na panelu | | |
| Q-40 | Dostawca SMTP dla maila zapasowego? | Ty | poświadczenia w Symfony Secrets, umowa powierzenia ([security.md](security.md)), odbiór `push-test-mail` w F0a | F0a | dostawca SMTP w UE z umową powierzenia (art. 28 [RODO](https://uodo.gov.pl/pl/file/1460)) | | |
| Q-41 | Cel backupu poza serwerem: gdzie leży repozytorium restic? | Ty | backup poza serwerem przed końcem F0a ([operations.md](operations.md)); umowa powierzenia ([security.md](security.md)) | F0a | magazyn obiektowy zgodny z S3, w UE, u innego dostawcy niż hosting, dla restic, z umową powierzenia (art. 28 [RODO](https://uodo.gov.pl/pl/file/1460)) | | |
| Q-42 | Zewnętrzny serwis dead-man: healthchecks.io czy Uptime Kuma na innym hoście? | Ty | alert nie może zależeć od monitorowanego serwera („Monitoring” w [operations.md](operations.md)) | F0a | healthchecks.io | | |
| Q-43 | Czy plan GitHub dla `good-yellow-bee` daje ruleset albo ochronę gałęzi `main` z wymaganymi checkami w repo prywatnym? | Ty | wymuszenie zielonego CI przed merge zależy od planu ([testing.md](testing.md), [GitHub](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/about-rulesets)) | F0a | bez wymuszenia; merge tylko przy zielonym CI na PR, sprawdzane ręcznie | | |

## Pokrycie źródeł

| Źródło | Pytania |
| --- | --- |
| Plan, otwarte pytania 1–21 | 1→Q-10, 2→Q-11, 3→Q-12, 4→Q-13 i Q-30, 5→Q-14, 6→Q-26, 7→Q-22, 8→Q-17, 9→Q-23 i Q-24, 10→Q-21, 11→Q-31, 12→Q-25, 13→Q-16, 14→Q-18, 15→Q-28, 16→Q-33 do Q-36, 17→Q-15, 18→Q-19, 19→Q-20, 20→Q-37, 21→Q-38 |
| Plan, decyzje i sprzeczność VAT-UE | Q-01 do Q-09, Q-29, Q-32 |
| `questions[]` 1–20 z `gaps-2026-10-08.json` | 1→Q-01, 2→Q-02, Q-11 i Q-18, 3→Q-10, 4→Q-26 i Q-27, 5→Q-22, 6→Q-23 i Q-24, 7→Q-03, 8→Q-21, 9→Q-31, 10→Q-25, 11→Q-16, 12→Q-14, 13→Q-13 i Q-30, 14→Q-28, 15→Q-33 do Q-35, 16→Q-15, 17→Q-04 i Q-37, 18→Q-19, 19→Q-20, 20→Q-38 |
| `user_question` w lukach | `ewidencja-mandatory-since-2026`→Q-11, `ksef-mandatory-cutover`→Q-10, `edoreczenia-obligation-now`→Q-13, `freshbooks-delivery-bypass`→Q-26, `edoreczenia-prod-qualified-cert`→Q-30, `jpk-v7m3-mapping`→Q-31, `fx-rate-rules`→Q-23 i Q-22, `corrections-flow`→Q-27, `ryczalt-rate-mapping`→Q-22, `phase-plan`→Q-04, `chat-drafts-write-path`→Q-35, `chat-data-policy`→Q-33 i Q-34, `basal-version-calibration`→Q-37, `jpk-st-wykaz`→Q-25, `ksef-outage-policy`→Q-28, `counterparty-verification`→Q-20, `b2c-oss-assumption`→Q-03, `payments-fx-kasowy`→Q-24, `hosting-deploy`→Q-14, `notifications-privacy`→Q-15, `legal-watch-facts`→Q-38, `ksef-library-pin`→Q-19 |
| Dry run faz F0a–F2 (dodatek planu, pkt 2) | Q-39 do Q-43 |
| Przegląd z 8.10.2026 (archiwum poza repo) i decyzje właściciela 2026-10-08 | M1→D-31, M2→D-32, M3→D-33 i D-34, M4 (`claude.messages`)→D-34, M5→Q-11, M6→D-30, S3→Q-29 i D-05, S4 i S5→Q-21, S7→Q-39, S8→Q-44 i D-35, S9→Q-18 i D-03, S10, C4–C7→D-28, C1–C3 i C8–C10→D-29 |
| Luka `decisions-prereqs-roles` | struktura tej tabeli, Q-02, Q-18; tabela warunków wstępnych w [README.md](README.md); D-02 (jeden okres rozliczenia VAT), D-03 (role, zastępca, break-glass) |
| Luki z częścią w rekordach D-xx | `docs-claude-md-plumbing`→D-01 i D-24, `ewidencja-mandatory-since-2026`→D-02 i D-23, `phase-plan` i `ksef-mandatory-cutover`→D-06, `freshbooks-delivery-bypass` i `corrections-flow`→D-07, `edoreczenia-prod-qualified-cert`→D-09, `chat-sonnet-api-profile` i `chat-tax-knowledge-cost`→D-10, `secrets-two-tier`→D-12, `environment-separation`→D-13, `ksef-library-pin`→D-14, `ksef-outage-policy`→D-15, `ksef-p1-issue-date`→D-16, `qr-pdf-pipeline`→D-17, `hosting-deploy`→D-18, `notifications-privacy`→D-19, `counterparty-verification`→D-20, `fx-rate-rules`→D-21, `chat-data-policy` i `chat-drafts-write-path`→D-22, `basal-version-calibration`→D-26, `vat-ue-missing`→D-27 i D-03, `go-live-missing`→D-03, `b2c-oss-assumption`→D-05 |

## Decyzje (ADR)

Rekord ma pola: status, kontekst, decyzja, skutki, pytania. Źródło stoi przy fakcie. Szczegóły realizacji są w pliku-domu tematu, tutaj tylko wybór i jego powód.

| Id | Decyzja | Status | Pytania |
| --- | --- | --- | --- |
| D-01 | źródłem prawdy jest `docs/` w repo | przyjęta 2026-10-08 | – |
| D-02 | VAT miesięczny (JPK_V7M); JPK_EWP za 2026 do 30.04.2027 | przyjęta 2026-10-08 | Q-01 |
| D-03 | składasz wszystko sam, bez biura rachunkowego; zastępca i break-glass w zakresie | przyjęta 2026-10-08 | Q-02, Q-18 |
| D-04 | jednorazowy przegląd doradcy podatkowego to bramka przed go-live F3 | przyjęta 2026-10-08; zakres proponowany | Q-07, Q-29, Q-21, Q-22 |
| D-05 | tylko B2B; VAT-UE w zakresie, OSS poza zakresem | przyjęta 2026-10-08 | Q-03, Q-29 |
| D-06 | najpierw KSeF; kolejność F0a → F7 | zastąpiona przez D-30 | Q-04 |
| D-07 | FreshBooks źródłem faktur; znacznik gotowości; PDF z kokpitu | proponowana | Q-26, Q-27 |
| D-08 | panel webowy zamiast aplikacji natywnej; bez PWA (D-28) | przyjęta 2026-10-05; PWA wycięte 2026-10-08 | – |
| D-09 | budujesz własny kokpit | przyjęta 2026-10-05; koszty 2026-10-08 | Q-30, Q-44 |
| D-10 | czat tylko na Claude Haiku 5.5 | przyjęta 2026-10-08; eskalacja do Sonnet odrzucona (D-29) | Q-05, Q-36 |
| D-11 | Messages API i PHP tool runner; Managed Agents odrzucone | przyjęta 2026-10-08 | Q-06 |
| D-12 | sekrety dwupoziomowe przez `SecretStore` | przyjęta 2026-10-08 | – |
| D-13 | cele per konektor niezależne od `APP_ENV` | przyjęta 2026-10-08 | – |
| D-14 | biblioteka KSeF 1.10.x przypięta, za `KsefGateway` | proponowana | Q-19 |
| D-15 | przy awarii KSeF czekasz; bez trybu offline | proponowana | Q-28 |
| D-16 | P_1 to dzień wysyłki w Europe/Warsaw | przyjęta 2026-10-08 (plan) | – |
| D-17 | własny dwujęzyczny szablon PDF z KOD I, bez zależności tylko na GPL | proponowana | Q-26 |
| D-18 | [F0] VPS w UE, tylko projekt prod, panel na nazwie DNS w VPN | przyjęta 2026-10-09; zakres VPS przyjęty 2026-10-08 (D-28) | Q-14 |
| D-19 | ntfy jedynym kanałem push, treść bez danych | proponowana; tylko ntfy przyjęte 2026-10-08 (D-28) | Q-15, Q-40 |
| D-20 | rejestry: tylko VIES, biała lista i NBP | proponowana; KRS, CEIDG i GUS w roadmap (D-29) | Q-20 |
| D-21 | bez wyboru art. 31a ust. 2a | proponowana | Q-23 |
| D-22 | Claude API na DPA z SCC; maskowana treść pism z limitem długości (po powrocie F4); szkice tylko do kopiowania | proponowana | Q-33, Q-34, Q-35 |
| D-23 | ewidencja 2026: import w F2.1, późne K_2 jawnie | proponowana; jawność K_2 przyjęta 2026-10-08 | Q-11 |
| D-24 | repo prywatne; `.claude/rules` w repo; `CLAUDE.md` lokalny | przyjęta 2026-10-08 | Q-08 |
| D-25 | plany płatne odłożone poza `docs/` | przyjęta 2026-10-08 | Q-09 |
| D-26 | basal: decyzja o budowie po F6 | zastąpiona przez D-29 | Q-37 |
| D-27 | VAT-UE z danymi autoryzującymi przez e-Deklaracje | przyjęta 2026-10-08 (źródła MF); test 418, VAT-UEK i bramki testowej w F5 | Q-32, Q-12 |
| D-28 | wąska F0a: tylko prod na VPS, Tailscale i jeden passkey, push tylko ntfy, dowód z CI i listy kontrolnej | przyjęta 2026-10-08 | Q-14, Q-15, Q-43 |
| D-29 | odłożone: basal, F4 PROD API, KRS/CEIDG/GUS; wycięte: dodatki czatu, okna przesuwne, `opening_balance` | przyjęta 2026-10-08 | Q-20, Q-30, Q-36, Q-37 |
| D-30 | szybka ścieżka do dry run 15.12.2026: F0a → F2.1 → F2.4 → dry run, potem F0b | przyjęta 2026-10-08 | Q-04, Q-11 |
| D-31 | „Doręcz klientowi” synchronicznie w żądaniu panelu, bez transportu Messenger | przyjęta 2026-10-08 | Q-26 |
| D-32 | konektor testowy `dummy`, nigdy przy `APP_ENV=prod` | przyjęta 2026-10-08 | – |
| D-33 | klucze kryteriów odbioru jako slugi z kontraktu nazw | przyjęta 2026-10-08 | – |
| D-34 | licznik Claude `claude.messages`; job CI `unit_property` | przyjęta 2026-10-08 | – |
| D-35 | FreshBooks bez firmy testowej; fixtures i firma prawdziwa tylko do odczytu | przyjęta 2026-10-08 | Q-44, Q-26 |
| D-36 | kod F2.1 przed odbiorem F0a; odbiór F2.1 dalej po F0a | przyjęta 2026-10-10 | Q-11, Q-14 |
| D-37 | kod F2.4 przed odbiorem F2.1; JPK_ST(1) po dostarczeniu XSD; kształt `auth_data` | przyjęta 2026-10-11 | Q-25 |
| D-38 | kod F0b przed dry runem i przed odbiorem F0a, częściami | przyjęta 2026-10-11 | Q-14 |
| D-39 | szczegóły silnika obowiązków F0b: tytuł bez rachunku, warunek `jpk_st`, rytm reguł | przyjęta 2026-10-11 | Q-13, Q-16, Q-17, Q-25 |
| D-40 | step-up w sesji przez 5 minut; ostatniego passkeya nie unieważnisz w panelu | przyjęta 2026-10-11 | – |
| D-41 | szkielet bramki F0b: rejestr przedmiotów, `DispatchGuard`, wiek `unknown`, okna odpytywania, ochrona przed `messenger:failed:retry` | przyjęta 2026-10-11 | Q-35 |
| D-42 | kod F1 przed odbiorem F0a, F0b, F2.1 i F2.4, częściami; nagrania HTTP z laptopa albo ze środowiska z dozwolonymi hostami testowymi | przyjęta 2026-10-11 | Q-19, Q-39 |
| D-43 | oszczędne minuty GitHub Actions: push tylko na `main`, gałąź PR przez `pull_request`, anulowanie starszych przebiegów PR, `mutation` bez PR, póki doradczy, `evidence` tylko na gałęziach faz | przyjęta 2026-10-14 | Q-43 |
| D-44 | kod F2.2 przed odbiorem F0b i F1, częściami: najpierw bez sieci (stawki, kurs PIT, wpisy z Subject1, wpis ręczny, błąd wpisu), potem FreshBooks i płatności, potem korekty KOR i uzgodnienia | przyjęta 2026-10-14 | Q-22 |

### D-01 Źródło prawdy: repo `docs/`, dokument w claude.ai zamrożony

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Specyfikacja żyła w dokumencie claude.ai i w kopii `SPEC.md`. `CLAUDE.md` wskazywał nieistniejącą ścieżkę specyfikacji, a monolit trafiał w całości do każdej sesji.
- **Decyzja:** Źródłem prawdy jest katalog `docs/` w repo. Dokument claude.ai jest zamrożony, oryginał leży w [archive/SPEC-2026-10-05.md](archive/SPEC-2026-10-05.md). Diagramy SVG z dokumentu zastępuje Mermaid w [README.md](README.md) i [architecture.md](architecture.md).
- **Skutki:**
  - Sesja fazy czyta README, plik fazy i tylko pliki z jego listy.
  - Temat ma jeden plik-dom; mapa w README.
  - Bez `@import` plików faz, bo importy ładują się na starcie sesji ([Claude Code](https://code.claude.com/docs/en/memory)).
- **Pytania:** –.

### D-02 VAT miesięczny (JPK_V7M) i termin JPK_EWP za 2026

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Dawna specyfikacja zakładała VAT miesięczny, a konfiguracja dopuszczała też inny okres rozliczenia. Od tej formy zależą ewidencja elektroniczna w 2026 i termin pierwszego JPK_EWP.
- **Decyzja:** Rozliczasz VAT miesięcznie, w JPK_V7M. Okres rozliczenia VAT nie jest opcją konfiguracji. Komentarz w dokumencie claude.ai rozstrzygnięty: pierwszy JPK_EWP jest za 2026.
- **Skutki:**
  - Ewidencja w programie obowiązuje od 1.01.2026, wpis do 20. dnia następnego miesiąca ([rozporządzenie, Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf)); stan 2026 w D-23.
  - JPK_EWP(4) i JPK_ST(1) za 2026 składasz do 30.04.2027, w piątek, bez przesunięcia ([broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf)). JPK_ST(1) zależy od projektu zwolnienia, nieuchwalonego na 08.10.2026 ([projekt](https://legislacja.gov.pl/projekt/12413609)); flaga w Q-25.
  - Przedłużenie terminów z [Dz.U. 2026 poz. 779](https://api.sejm.gov.pl/eli/acts/DU/2026/779/text.pdf) dotyczy tylko ksiąg rachunkowych, nie JPK_EWP.
  - `ObligationEngine` liczy terminy JPK tylko dla trybu miesięcznego. Zmiana formy rozliczenia wymaga nowego rekordu i zmiany kalendarza.
- **Pytania:** Q-01.

### D-03 Brak biura rachunkowego: kto składa i przechowuje co, zastępca i break-glass

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Dawna specyfikacja zakładała biuro rachunkowe, które wysyła JPK, prowadzi ewidencję i akceptuje mapowania. Dwóch wysyłających oznaczałoby dwa źródła prawdy. Biura nie ma.
- **Decyzja:**
  - Wszystkie deklaracje, pliki i płatności składasz sam. Do go-live fazy używasz darmowych narzędzi MF albo obecnego narzędzia (ewidencja: Twoje narzędzie, MF go nie daje).
  - Kokpit przejmuje tylko obowiązki z kolumny „Przejmuje kokpit” w README, przez bramkę zatwierdzeń. PIT-28, WHT/IFT-2R/CIT-10Z i płatności zostają ręczne.
  - Kto składa co i która faza przejmuje obowiązek: tabela „Teraz – zgodność” w [README.md](README.md). Jeden obowiązek ma jedno źródło wysyłki.
  - Zastępca i break-glass zostają w zakresie, bo jedna osoba oznacza przerwę w składaniu i odbiorze pism. Do 11.10.2026 wyznaczasz administratora skrzynki ADE jako zastępcę przy odbiorze pism (Q-18). Poza skrzynką domyślnie zastępcy brak, UPL-1 do rozważenia, procedura w [operations.md](operations.md).
- **Skutki:**
  - Kokpit wysyła, a nie tylko eksportuje. Eksport XML zostaje jako zapas do narzędzi MF; formatu eksportu dla biura nie ma.
  - „Podwójna wysyłka” z dawnych ryzyk oznacza teraz kokpit i Twoje obecne narzędzie w okresie przejścia.
  - KSeF przechowuje faktury. UPO, dowody doręczeń, ewidencja, wysłane XML i snapshoty weryfikacji są tylko u Ciebie: `FileStore` i backup poza serwerem ([operations.md](operations.md)), retencja w [security.md](security.md).
  - Akcepty, które luki przypisywały biuru, przejmują:

    | Dawny akcept biura | Teraz |
    | --- | --- |
    | mapowanie FA(3) dla usług zagranicznych, złote pliki XML | przegląd doradcy (D-04) |
    | okresy korekt (TypKorekty) | przegląd doradcy w zakresie Q-29; bez niego Ty |
    | klasyfikacja WHT i VAT dostawców zagranicznych | Ty z doradcą (Q-21, Q-29) |
    | odczyt daty fikcji doręczenia do wyświetlenia | przegląd doradcy w zakresie Q-29; panel pokazuje datę z API |
    | VAT-UE „składa biuro” | Ty, e-Deklaracje do go-live F5, potem kokpit (D-27) |
    | zapas JPK po nieudanej bramce 31.03.2027 | Ty, Klient JPK WEB albo Formularz uproszczony |
    | import ewidencji biura i uzgodnienie z jego sumami | import z Twojego narzędzia albo rekonstrukcja; uzgodnienie z FreshBooks i Aplikacją Podatnika KSeF (D-23) |

- **Pytania:** Q-02, Q-18.

### D-04 Przegląd doradcy podatkowego jako bramka przed go-live F3

- **Status:** przyjęta 2026-10-08; zakres proponowany (Q-29).
- **Kontekst:** Błędne oznaczenie usług zagranicznych w FA(3) oznacza korekty faktur. Błędna stawka ryczałtu zmienia podatek. Bez biura nikt poza Tobą tego nie sprawdza.
- **Decyzja:**
  - Jednorazowy przegląd doradcy podatkowego to bramka przed go-live F3.
  - Minimum: mapowanie FA(3), w tym P_18 dla nabywców spoza UE i złote pliki XML, oraz PKWiU→ryczałt.
  - Domyślny zakres (Q-29) to wszystkie punkty z tabeli niżej.
  - Pełna lista punktów przeglądu:

    | Punkty | Plik |
    | --- | --- |
    | PKWiU→stawka, zaokrąglenia zaliczki, stawka różnicy kursowej, VAT i WHT dostawców (z art. 29 ustawy o PIT dla osób fizycznych), moment podatkowy przedpłat kosztów, TypKorekty (sekcja „Przegląd doradcy: część podatkowa”) | [tax-rules.md](tax-rules.md) |
    | scenariusze FA(3), w tym `eu_b2b_unidentified`, P_18, złote pliki XML | [modules/ksef.md](modules/ksef.md) |
    | oznaczenie odtworzonych wpisów w K_10, późne K_2 (D-23), K_4 i K_10 wiersza `fx_difference` | [modules/ewidencja.md](modules/ewidencja.md) |
    | widok faktury w portalu FreshBooks jako wizualizacja bez kodu QR (art. 106gb ust. 5) | [modules/freshbooks.md](modules/freshbooks.md) |
    | okres korekty VAT-UEK | [modules/jpk.md](modules/jpk.md) |
    | odczyt daty fikcji doręczenia | [modules/e-doreczenia.md](modules/e-doreczenia.md) |

- **Skutki:**
  - Rezerwujesz doradcę w tygodniu od 8.10.2026, bo I kwartał 2027 to sezon PIT u doradców. Pierwsze pytania: `eu_b2b_unidentified` i przedpłaty (Q-29). Lista działań w „Teraz – zgodność” w [README.md](README.md).
  - Wynik przeglądu zapisujesz jako odpowiedź na Q-29: data i lista zatwierdzonych scenariuszy. Status D-04 dostaje datę przeglądu. Q-07 zostaje bez zmian.
  - Zatwierdzone złote pliki XML stają się fixtures ([testing.md](testing.md)). Tabela scenariuszy FA(3) jest w [modules/ksef.md](modules/ksef.md), stawki w [tax-rules.md](tax-rules.md).
  - Bez zapisu przeglądu F3 zostaje na api-test.
- **Pytania:** Q-07, Q-29, Q-21, Q-22.

### D-05 Tylko B2B: VAT-UE w zakresie, OSS poza zakresem

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Dla konsumentów inne są miejsce świadczenia (art. 28c), próg dla usług elektronicznych (art. 28k) i procedura OSS (art. 130b) ([ustawa o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Dawna specyfikacja tego nie rozstrzygała.
- **Decyzja:** Klienci wyłącznie B2B: z UE, spoza UE i z Polski. VAT-UE jest w zakresie. Sprzedaż konsumentom i OSS są poza zakresem.
- **Skutki:**
  - Zatwierdzenie faktury wymaga identyfikatora podatkowego nabywcy: ważny wynik VIES dla UE, zagraniczny identyfikator podatkowy z dowodem `manual_evidence` spoza UE, NIP sprawdzony na białej liście dla Polski. Brak blokuje zatwierdzenie.
  - Wyjątek do potwierdzenia przez doradcę (Q-29): nabywca z UE bez ważnego numeru VIES, ale prowadzący działalność. Scenariusz `eu_b2b_unidentified` daje `np I`, bez VAT-UE, z `manual_evidence` statusu przedsiębiorcy (art. 28a pkt 1 lit. a i art. 100 ust. 1 pkt 4 ustawy o VAT).
  - Pierwszy klient konsument oznacza nowy rekord i sekcję B2C w [tax-rules.md](tax-rules.md) przed wystawieniem faktury.
  - Reguły B2B są w [tax-rules.md](tax-rules.md), mapowanie FA(3) w [modules/ksef.md](modules/ksef.md).
- **Pytania:** Q-03, Q-29.

### D-06 Kolejność faz i kamienie milowe

- **Status:** zastąpiona przez D-30 (2026-10-08). Kolejność realizacji obowiązuje z D-30, odłożenia z D-29. Id faz, podział F0a i F0b oraz EWP przed V7M zostają. Rekord niżej to stan sprzed D-30.
- **Kontekst:** Dawna kolejność to fundament, KSeF, e-Doręczenia, kontrahenci i JPK, z V7M przed EWP. Wystawianie w KSeF obowiązuje od 1.04.2026 ([Dz.U. 2025 poz. 1203](https://api.sejm.gov.pl/eli/acts/DU/2025/1203/text.pdf)), ewidencja od 1.01.2026 ([Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf)). Suma szacunków nie zostawiała zapasu przed 30.04.2027.
- **Decyzja:** Kolejność z Q-04: F0a, F0b, F1, F2 (dry run do 15.12.2026), F3, F4, F5 (EWP/ST w PROD do 31.03.2027), F6, F7.

  | Wybór | Powód |
  | --- | --- |
  | KSeF najpierw | wystawianie i ewidencja już obowiązują; NBP i VIES są potrzebne przy każdej fakturze |
  | F0 podzielona na F0a i F0b | faza ma się mieścić w sesji |
  | EWP przed V7M | JPK_V7M składasz dziś w narzędziach MF; JPK_EWP to jedyny nowy plik z twardym terminem |
  | e-Doręczenia API po F3 | obowiązek pokrywają powiadomienia mailowe i ręczny odbiór; PROD zależy od certyfikatu kwalifikowanego (Q-30); F4 PROD odłożone w D-29 |
  | wniosek INT ok. 2 tygodnie przed F4 | dostęp trwa co najmniej 30 dni, kolejny wniosek przysługuje po 90 dniach ([regulamin INT](https://www.gov.pl/attachment/f8d3d63b-74ca-4c89-b35b-773eaf2a924a)); teraz bez wniosku (D-29) |
  | czat i basal na końcu | potrzebują danych z F1–F5 i nie wpływają na zgodność; basal odłożony w D-29 |

- **Skutki:**
  - Diagram, daty i bramka zapasowa 31.03.2027 są w [README.md](README.md). Zapas to darmowe narzędzia MF ([narzędzia MF](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_pd/bezplatne-narzedzia)).
  - Pełne pliki faz ma F0a–F2. F3–F7 to zaślepki; plik fazy powstaje przed jej startem.
  - Do go-live każdej fazy obowiązuje procedura „Teraz – zgodność” z README.
- **Pytania:** Q-04. Następca: D-30.

### D-07 FreshBooks jako źródło faktur sprzedaży i sposób doręczenia klientowi

- **Status:** proponowana (Q-26, Q-27).
- **Kontekst:** FreshBooks zostaje do obsługi klientów i płatności (Stripe). Send, wysyłki cykliczne i auto-bill dostarczają dokument przed KSeF, bez numeru KSeF i QR ([FreshBooks API](https://www.freshbooks.com/api/invoices)). Szkic nie ma znacznika gotowości, a numer nadany przy utworzeniu staje się P_2.
- **Decyzja (domyślna):**
  - FreshBooks zostaje źródłem faktur sprzedaży. Gotowość oznaczasz w `po_number` albo tagiem; sam szkic niczego nie wyzwala.
  - Klient dostaje PDF z kokpitu z KOD I jako załącznik, dwujęzyczny PL i EN ([MF, JDG](https://ksef.podatki.gov.pl/jdg-i-msp/)).
  - Credit note tworzy szkic KOR w bramce. Edycja po przyjęciu w KSeF wymusza korektę, nigdy ponowną wysyłkę.
- **Skutki:**
  - Do go-live F3 we FreshBooks bez Send przed numerem KSeF (procedura tymczasowa w [README.md](README.md)). Po go-live F3 Send i e-mail z FreshBooks nigdy. Wysyłki cykliczne i auto-bill wyłączone.
  - Po go-live F3 klient dostaje fakturę tylko akcją „Doręcz klientowi” w panelu: konektor `mail`, PDF z KOD I i link do płatności ([modules/freshbooks.md](modules/freshbooks.md)). Akcja działa synchronicznie w żądaniu panelu, poza Messenger (D-31).
  - Po przyjęciu zapis zwrotny numeru KSeF i oznaczenie faktury jako wysłanej. Alert, gdy faktura opuszcza Draft bez przyjętego odpowiednika. Zapis zwrotny pierwszy raz sprawdzasz na PROD, bo firmy testowej nie ma (D-35).
  - DTO faktury jest niezależne od źródła, więc kolejny adapter nie zmienia FA(3) (D-25).
  - Szczegóły w [modules/freshbooks.md](modules/freshbooks.md), korekty w [modules/ksef.md](modules/ksef.md).
- **Pytania:** Q-26, Q-27.

### D-08 Panel webowy zamiast aplikacji natywnej

- **Status:** przyjęta 2026-10-05 (dawna specyfikacja), potwierdzona 2026-10-08; PWA wycięte 2026-10-08 (D-28).
- **Kontekst:** Wybór między aplikacją webową a natywną na telefon.
- **Decyzja:** Aplikacja webowa w przeglądarce, z backendem na serwerze i panelem tylko przez VPN. Bez PWA: bez manifestu, service workera i Web Push (D-28).

  | Powód | Szczegół |
  | --- | --- |
  | backend i tak musi istnieć | polling KSeF, workery i sekrety działają 24/7 na serwerze |
  | sekrety | tokeny KSeF i FreshBooks nie trafiają na telefon |
  | powiadomienia | aplikacja ntfy na telefonie (D-19) |
  | czat | to kolejny widok panelu |
  | jeden stack | Symfony, Twig i Symfony UX (Turbo, Stimulus, Chart.js), bez osobnego SPA i drugiego repo |

- **Skutki:**
  - Passkey wymaga TLS na stałej nazwie DNS w VPN (D-18, [security.md](security.md)).
  - PWA i Web Push są wierszami w [roadmap.md](roadmap.md).
  - Aplikacja natywna ma sens później, np. do skanowania faktur aparatem albo widżetu terminów. Łączy się wtedy z tym samym backendem.
- **Pytania:** –.

### D-09 Kup czy zbuduj

- **Status:** przyjęta 2026-10-05 (dawna specyfikacja); koszty uzupełnione 2026-10-08.
- **Kontekst:** Jeśli celem jest wyłącznie zgodność z KSeF, gotowy program fakturowy z integracją KSeF załatwi to szybciej.
- **Decyzja:** Budujesz własny kokpit, bo zachowujesz FreshBooks, masz jedno miejsce na alerty i pełną kontrolę danych.
- **Skutki:**

  | Koszt | Kiedy |
  | --- | --- |
  | czas jednej osoby po godzinach | wszystkie fazy |
  | serwer z jednym projektem prod (D-18, D-28) i backup poza serwerem | od F0a |
  | jednorazowy przegląd doradcy podatkowego (D-04) | rezerwacja teraz, przegląd przed go-live F3 |
  | certyfikat kwalifikowany do API e-Doręczeń PROD, koszt stały ([instrukcja PROD](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce)) | odłożone (D-29); tylko po powrocie do F4 i bramkach wykonalności (Q-30) |
  | druga firma FreshBooks jako firma testowa | nie kupujesz (D-35) |
  | Claude API z niskim limitem wydatków (D-10) | F6 |

  Przy niepowodzeniu fazy zostają darmowe narzędzia MF z sekcji „Teraz – zgodność” w [README.md](README.md).
- **Pytania:** Q-30, Q-44.

### D-10 Model czatu: tylko Claude Haiku 5.5

- **Status:** przyjęta 2026-10-08; eskalacja do Sonnet 5.5 odrzucona 2026-10-08 (D-29).
- **Kontekst:** Dawna specyfikacja wybrała Sonnet 5.5. Czat odpowiada na pytania o dane, rysuje wykresy i pisze szkice; kwoty i wysyłki liczy deterministyczny kod. Haiku 5.5 kosztuje $0.10 / $0.50 za MTok do 100 tys. tokenów promptu i $0.50 / $2.50 powyżej, Sonnet 5.5 $2 / $10 ([cennik, 8.10.2026](https://platform.claude.com/docs/en/about-claude/pricing)).
- **Decyzja:**
  - Model czatu to Claude Haiku 5.5: `chat.model` = `claude-haiku-5-5`, effort zawsze jawnie w `chat.effort`.
  - Bez eskalacji do Sonnet 5.5. Warunek powrotu w [roadmap.md](roadmap.md); powrót wymaga nowego rekordu (D-29).
  - Zmiana modelu tylko po teście, jak przy każdym konektorze.
- **Skutki:**
  - Pojedyncze wywołanie 15 tys. + 1 tys. tokenów kosztuje ok. $0.002. Budżet liczy pętlę narzędzi i thinking jako kilka wywołań; dawnych $0.05–0.30 z Sonnet nie przepisujemy.
  - Limit rozmiaru rozmowy, bo powyżej 100 tys. tokenów stawka rośnie pięciokrotnie.
  - Profil żądań bez `display: "updates"`. Effort z konfiguracji, bez routera basal (D-29).
  - Odpowiedź w synchronicznym żądaniu z ramką Turbo, bez Mercure i workera strumieniowania (D-29).
  - Licznik wywołań to `claude.messages` (D-34).
  - Osobny workspace i klucz z niskim limitem wydatków w Symfony Secrets.
  - Pełny profil żądań i budżety w [modules/czat.md](modules/czat.md).
- **Pytania:** Q-05, Q-36.

### D-11 Managed Agents odrzucone: Messages API i PHP tool runner

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Managed Agents to zarządzana uprząż: pętla agenta i sandbox narzędzi działają w infrastrukturze Anthropic. Historia sesji, stan sandboxa i wyniki zostają po stronie serwera ([przegląd, 8.10.2026](https://platform.claude.com/docs/en/managed-agents/overview)). Alternatywa to Messages API z własną pętlą.
- **Decyzja:** Messages API i PHP tool runner z SDK `anthropic-ai/sdk`, pętla w Symfony.

  | Powód | Szczegół |
  | --- | --- |
  | dane | sesje Managed Agents są przechowywane po stronie serwera i nie kwalifikują się do ZDR ([przegląd](https://platform.claude.com/docs/en/managed-agents/overview)) |
  | region | brak na Bedrock i Google Cloud ([cennik](https://platform.claude.com/docs/en/about-claude/pricing)), więc zamyka opcję regionu UE (Q-33) |
  | narzędzia | czat potrzebuje tylko narzędzi do odczytu (nazwane zapytania na roli `chat_ro`, `render_chart`) oraz `draft_document`; sandbox z powłoką, plikami i siecią to zbędna powierzchnia |
  | kontrola | walidacja schematu narzędzi, budżety, `Redactor` i log `connector_call` zostają w kodzie kokpitu |
  | dojrzałość | Managed Agents jest w becie |

- **Skutki:**
  - Historia rozmowy żyje w `chat_session` i `chat_message`, tylko dopisywana.
  - Powrót do Managed Agents wymaga nowego rekordu.
- **Pytania:** Q-06.

### D-12 Sekrety dwupoziomowe: Symfony Secrets i `credential_state` przez `SecretStore`

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Dawna specyfikacja mówiła naraz o Symfony Secrets, o zewnętrznym szyfrowaniu plików i o zmiennych środowiskowych. Symfony Secrets zapisuje się przez CLI i jest buforowany per proces ([Symfony](https://symfony.com/doc/current/configuration/secrets.html)). W trakcie działania rotują tokeny FreshBooks, KSeF i e-Doręczeń ([FreshBooks](https://www.freshbooks.com/api/authentication), [KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/uwierzytelnianie.md)).
- **Decyzja:**
  - Sekrety statyczne trzymasz w Symfony Secrets. Sejf jest w repo, klucz deszyfrujący PROD nigdy: menedżer haseł i kopia papierowa.
  - Tokeny rotujące trzymasz w tabeli `credential_state`, szyfrowane kluczem z sejfu i odświeżane pod lockiem.
  - Jedyne wejście do sekretów to `SecretStore`. Zewnętrzne szyfrowanie plików sekretów odpada.
- **Skutki:**
  - Jedna aplikacja OAuth FreshBooks na środowisko, bo nowy grant unieważnia poprzedni.
  - Inwentarz sekretów w [security.md](security.md), rotacje i przypomnienia o wygaśnięciu w [operations.md](operations.md).
  - Zasada twarda w [../.claude/rules/zasady-twarde.md](../.claude/rules/zasady-twarde.md).
- **Pytania:** –.

### D-13 Cele per konektor (`*_TARGET`) niezależne od `APP_ENV`, osobna baza per środowisko

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Dawna zasada „produkcję włącza zmienna środowiskowa” nie mówiła, która, z jaką wartością domyślną i czy per konektor. KSeF wchodzi na PROD miesiące przed JPK. Bez rozdzielenia dane testowe trafiłyby do ewidencji i JPK, a odtworzony backup zacząłby odpytywać urzędy.
- **Decyzja:**
  - Osobny cel per konektor: `KSEF_TARGET`, `EDORECZENIA_TARGET`, `JPK_TARGET`, `EDEKLARACJE_TARGET`. Domyślnie test, dla e-Doręczeń `int`. `EDORECZENIA_TARGET` działa dopiero po powrocie do F4 (D-29).
  - `CONNECTORS_ENABLED=0`, gdy zmiennej brak.
  - Cele są niezależne od `APP_ENV`, mają osobne poświadczenia i osobną bazę per środowisko.
  - `HostAllowlist` w kodzie, z testem, który pada przy hoście PROD bez flagi.
- **Skutki:**
  - Tabele biznesowe mają kolumnę `environment` ([data-model.md](data-model.md)). Panel pokazuje baner, gdy konektor nie jest na PROD ([architecture.md](architecture.md)).
  - Reguły deny w `.claude/settings.json` to dodatkowa warstwa. `CLAUDE.md` i reguły to kontekst, nie wymuszenie ([Claude Code](https://code.claude.com/docs/en/memory)).
  - NBP i FreshBooks nie mają środowisk testowych; FreshBooks bez firmy testowej (D-35). Biała lista (`wl-test.mf.gov.pl`) i VIES (`check-vat-test-service`) mają hosty testowe. KRS, CEIDG i GUS są odłożone (D-29). Testy automatyczne idą na nagranych odpowiedziach ([testing.md](testing.md)).
  - Konektory bez `*_TARGET` (rejestry, NBP, FreshBooks, Claude, push, mail) biorą hosty z konfiguracji wdrożenia: dev na laptopie, prod na VPS (D-28, [architecture.md](architecture.md)). `HostAllowlist` sprawdza je tak samo.
- **Pytania:** –.

### D-14 Biblioteka KSeF: linia i przypięta wersja

- **Status:** proponowana (Q-19).
- **Kontekst:** `n1ebieski/ksef-php-client` ma dwie linie, obie dla KSeF API 2.0 ([Packagist](https://packagist.org/packages/n1ebieski/ksef-php-client)). 1.10.x działa od PHP 8.1 i dostaje tylko poprawki oraz krytyczne zmiany KSeF. 2.0.0-rc.1 z 20.09.2026 wymaga PHP ^8.4 i zmienia QR, kryptografię oraz fabryki ([UPGRADE.md](https://github.com/N1ebieski/ksef-php-client/blob/main/UPGRADE.md)).
- **Decyzja (domyślna):** 1.10.x przypięta do dokładnej wersji, za własnym interfejsem `KsefGateway`. Migracja po wydaniu stabilnej 2.0.
- **Skutki:**
  - Kod QR i certyfikatów piszesz pod wybraną linię.
  - Zmiany API nowsze niż biblioteka obsługuje `KsefGateway`; wzorcem są oficjalne klienty MF w C# i Javie ([CIRFMF](https://github.com/CIRFMF/ksef-api)).
  - Szczegóły w [modules/ksef.md](modules/ksef.md).
- **Pytania:** Q-19.

### D-15 Polityka awarii KSeF

- **Status:** proponowana (Q-28).
- **Kontekst:** Dawna specyfikacja jednocześnie wstrzymywała kolejkę i zakładała tryb offline z certyfikatem typ 2. Każdy tryb offline ma własny termin dosłania, a KOD II jest potrzebny tylko, gdy nabywca dostaje fakturę przed numerem KSeF ([tryby offline](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/tryby-offline.md)).
- **Decyzja (domyślna):** Przy awarii czekasz. Latarnia wstrzymuje kolejkę `official_writes`, panel pokazuje status. Bez trybu offline i bez certyfikatu typ 2.
- **Skutki:**
  - Latarnia jest konektorem `latarnia` niezależnie od wyboru ([scenariusze Latarni](https://raw.githubusercontent.com/CIRFMF/ksef-latarnia/main/scenariusze.md)).
  - Klienci zagraniczni B2B rzadko potrzebują faktury tego samego dnia.
  - Włączenie trybu offline oznacza certyfikat typ 2 i terminy w `obligation`.
  - Reguły trybów i obsługa Latarni w [modules/ksef.md](modules/ksef.md).
- **Pytania:** Q-28.

### D-16 Reguła P_1 przy wysyłce: data wysyłki czy offline24

- **Status:** przyjęta 2026-10-08 (plan).
- **Kontekst:** Gdy data przesłania jest późniejsza niż P_1, faktura staje się offline24 ([MF, offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/)). P_1 późniejsza niż data przyjęcia daje odrzucenie. Szkic FreshBooks z piątku zatwierdzony we wtorek byłby spóźnioną fakturą offline24.
- **Decyzja:** P_1 to dzień wysyłki w Europe/Warsaw, ustawiany przy przygotowaniu wysyłki. P_1 nigdy nie pochodzi z FreshBooks, a P_1 w przyszłości blokuje zatwierdzenie. Zmiana dnia między zatwierdzeniem a wysyłką wymusza nowy XML, nowy sha256 i ponowne zatwierdzenie.
- **Skutki:**
  - Mechanika w [modules/ksef.md](modules/ksef.md), wiązanie hasha w [approval-gate.md](approval-gate.md).
  - Testy na api-test dla P_1 w przeszłości i w przyszłości.
- **Pytania:** –.

### D-17 Generator PDF z KOD I: kontener Node czy własny szablon

- **Status:** proponowana (Q-26); wybór potwierdza sesja F3.
- **Kontekst:** KOD I zawiera skrót SHA-256 dokładnie wysłanego pliku, więc ponowna serializacja XML psuje kod ([kody QR](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/kody-qr.md)). Biblioteka renderuje PDF przez CLI w Node. Oficjalny generator MF nie ma CLI, a jego plik angielski zawiera tylko placeholdery ([ksef-pdf-generator](https://github.com/CIRFMF/ksef-pdf-generator)).
- **Decyzja (domyślna):** Własny dwujęzyczny szablon Twig→PDF z KOD I przez `endroid/qr-code` w linii wymaganej przez bibliotekę KSeF: ^5.1 przy 1.10.x (D-14), ≥6.1 dopiero po migracji na 2.x ([architecture.md](architecture.md)). Kontener Node przypięty do commita tylko jako zapas. Bez zależności tylko na GPL; LGPL bez modyfikacji dopuszczalna ([architecture.md](architecture.md)). mPDF (GPL-2.0-only) odpada.
- **Skutki:**
  - Zapisujesz dokładne wysłane bajty i ich sha256 (`stored_file` rodzaju `xml_sent`).
  - Bazowy adres QR pochodzi z konfiguracji celu `KSEF_TARGET`.
  - Silnik HTML→PDF wybiera sesja F3 według tej reguły licencji. Kryterium F3: QR otwiera adres qr-test.
- **Pytania:** Q-26.

### D-18 Hosting

- **Status:** przyjęta 2026-10-09 (Q-14, założenie domyślne); tylko projekt prod na VPS przyjęte 2026-10-08 (D-28).
- **Kontekst:** Dostęp INT e-Doręczeń wymaga zgłoszonego stałego IP ([regulamin INT](https://www.gov.pl/attachment/f8d3d63b-74ca-4c89-b35b-773eaf2a924a)); to jedyny twardy wymóg stałego IP, a F4 PROD jest odłożone (D-29). Passkey wymaga TLS na stałej nazwie. Faktury spoza KSeF przechowywane elektronicznie poza Polską wymagają dostępu on-line dla organów (art. 112a ust. 2–3, [ustawa o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)).
- **Decyzja:** VPS w Polsce albo w innym kraju UE z dostępem on-line dla organów. Na VPS tylko projekt prod, RAM ≥ 2 GB bez basal (D-28, Q-14). Panel na nazwie DNS w VPN z zaufanym certyfikatem: sidecar Tailscale i `tailscale cert` na neutralnej nazwie węzła. Stały IPv4 potrzebny dopiero przy powrocie do F4.
- **Skutki:**
  - Usługi compose, minimalny RAM, rozszerzenia PHP i procedura wdrożenia w [operations.md](operations.md).
  - Dev działa na laptopie, test odtworzenia w jednorazowym projekcie lokalnym (D-28).
  - VPS poza Polską: zapewniasz dostęp on-line dla organów albo wybierasz VPS w Polsce ([security.md](security.md)).
  - basal jest odłożony (D-29), więc nie wpływa na wybór hosta.
- **Pytania:** Q-14.

### D-19 Kanał push

- **Status:** proponowana (Q-15); ntfy jako jedyny kanał push przyjęte 2026-10-08 (D-28).
- **Kontekst:** Dawna specyfikacja wymieniała ntfy, Telegram, PWA i mail bez SMTP. Na ntfy.sh nazwa tematu działa jak hasło, a serwis loguje tematy i IP ([ntfy](https://docs.ntfy.sh/config/)). Self-hosted ntfy na iOS wysyła przez ntfy.sh tylko identyfikator i hash tematu, a treść telefon pobiera z Twojego serwera ([ntfy FAQ](https://docs.ntfy.sh/faq/)).
- **Decyzja (domyślna):** Self-hosted ntfy. Treść push to kategoria, liczba i link do panelu; nigdy nazwy, kwoty ani treść pisma. Bez Web Push, VAPID i Telegrama (D-28).
- **Skutki:**
  - Telefon potrzebuje dostępu przez VPN; bez niego powiadomienie na iOS może się opóźnić o godziny.
  - Zapas to mail przez dostawcę SMTP z poświadczeniami w Symfony Secrets; dostawca w Q-40.
  - Szczegóły prywatności w [security.md](security.md).
- **Pytania:** Q-15, Q-40.

### D-20 Zakres rejestrów

- **Status:** proponowana (Q-20); wiersze KRS, CEIDG i GUS usunięte z tabel konektorów 2026-10-08 (D-29).
- **Kontekst:** Kontrahenci są głównie zagraniczni. KRS, CEIDG i GUS wymagają onboardingu (JWT CEIDG, klucz BIR), a opisują tylko polskie podmioty.
- **Decyzja (domyślna):** VIES, biała lista i NBP. Konektory KRS, CEIDG i GUS są odłożone (D-29); warunek powrotu w [roadmap.md](roadmap.md).
- **Skutki:**
  - Klucz JWT CEIDG i klucz BIR nie są warunkami wstępnymi F1 ([README.md](README.md)).
  - KRS, CEIDG i GUS są wierszami w [roadmap.md](roadmap.md), nie w tabelach konektorów (D-29).
  - Kontrahent z UK albo spoza UE dostaje dowód ręczny (`manual_evidence`).
  - Polityka per typ kontrahenta, liczniki i dowody w [modules/rejestry-kursy.md](modules/rejestry-kursy.md).
- **Pytania:** Q-20.

### D-21 Kursy walut: wybór art. 31a ust. 2a

- **Status:** proponowana (Q-23).
- **Kontekst:** Art. 31a ust. 2a pozwala przeliczać VAT według zasad PIT, z wiązaniem na co najmniej 12 miesięcy ([ustawa o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)). Wybór nie obejmuje importu usług.
- **Decyzja (domyślna):** Bez wyboru. VAT według art. 31a ust. 1 i 2, PIT według reguły PIT.
- **Skutki:**
  - Faktura przechowuje osobno kurs PIT i kurs VAT ([data-model.md](data-model.md)).
  - Ewentualny wybór zapisujesz jako `setting` z datą obowiązywania.
  - Reguła kursu w [tax-rules.md](tax-rules.md), użycie API NBP w [modules/rejestry-kursy.md](modules/rejestry-kursy.md).
- **Pytania:** Q-23.

### D-22 Dane do Claude API, region i wykonawca szkiców

- **Status:** proponowana (Q-33, Q-34, Q-35).
- **Kontekst:**
  - Czat wysyła do Anthropic fragmenty pism i dane kontrahentów. DPA z SCC jest częścią warunków komercyjnych ([DPA](https://privacy.claude.com/en/articles/7996862-how-do-i-view-and-sign-your-data-processing-addendum-dpa)).
  - Region UE jest dostępny tylko przez Bedrock albo Google Cloud ([rezydencja danych](https://platform.claude.com/docs/en/manage-claude/data-residency)), z dopłatą 10% ([cennik](https://platform.claude.com/docs/en/about-claude/pricing)).
  - Najgorszy przypadek retencji: wejścia i wyjścia do 30 dni, treści oznaczone do 2 lat ([retencja](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data)).
  - Pismo albo PDF może podsunąć szkic z podmienionym odbiorcą lub rachunkiem.
- **Decyzja (domyślna):**
  - Claude API bezpośrednio, na DPA z SCC.
  - Po powrocie F4 do modelu trafia maskowana główna treść pisma z limitem długości, bez załączników; streszcza model (Q-34).
  - Szkice tylko do kopiowania; kokpit niczego z nich nie wysyła.
- **Skutki:**
  - Pism i faktur nie wysyłasz przez Files API, Batches ani feedback.
  - Szkic ma odbiorcę tylko z bazy i oznacza źródła nieufne. Panel wyróżnia IBAN, NIP i kwoty niezgodne z bazą.
  - Tabela „Dane do modelu” per klasa danych w [modules/czat.md](modules/czat.md).
  - Region UE oznacza nowy rekord, innego dostawcę i utratę części funkcji API.
- **Pytania:** Q-33, Q-34, Q-35.

### D-23 Ewidencja 2026: źródło prawdy, import albo rekonstrukcja, późne K_2

- **Status:** proponowana (Q-11); jawność późnych K_2 przyjęta 2026-10-08.
- **Kontekst:** Przy JPK_V7M ewidencja przychodów w programie obowiązuje od 1.01.2026 ([ustawa o ryczałcie, Dz.U. 2025 poz. 843](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf), art. 15 ust. 12). Wpis każdej faktury należy do 20. dnia następnego miesiąca ([Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf), §6). W JPK_EWP(4) K_2 to data wpisu, a K_3 data przychodu ([broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf)).
- **Decyzja:**
  - Do 20.10.2026 wybierasz narzędzie ewidencji i robisz w nim wpisy za 09.2026 (Q-11). Potem wpis co miesiąc do 20.
  - F2.1 importuje 2026 z CSV albo eksportu JPK_EWP(4) z narzędzia, z policzonymi K_8 i K_9 (D-30). Import zachowuje oryginalne daty wpisu.
  - Miesiące bez wpisu w narzędziu odtwarzasz w pliku importu. F2.2/F2.3 uzgadniają je potem z KSeF Subject1 i FreshBooks.
  - K_2 to faktyczna data wpisu. Kokpit nigdy nie antydatuje wpisów.
- **Skutki:**
  - Wpisy odtworzone mają K_2 równe faktycznej dacie wpisu albo dacie importu w F2.1; ich ujęcie to punkt przeglądu doradcy (Q-29).
  - Dry run JPK_EWP(4) i JPK_ST(1) na danych sty–lis 2026 do 15.12.2026 tylko ze źródła `import` (D-30). Uzgodnienie z Aplikacją Podatnika KSeF i FreshBooks w F2.2/F2.3, po F1.
  - Tabela, import i mapowanie w [modules/ewidencja.md](modules/ewidencja.md).
- **Pytania:** Q-11.

### D-24 Repo prywatne, `.claude/rules` commitowane, `CLAUDE.md` lokalny

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Zasady twarde, komendy i definicja ukończenia muszą przetrwać klon, worktree i sesję w chmurze. Reguły w `.claude/rules` bez `paths:` ładują się w każdej sesji, a z `paths:` tylko przy pasujących plikach ([Claude Code](https://code.claude.com/docs/en/memory)).
- **Decyzja:**
  - Repo prywatne `good-yellow-bee/kokpit-jdg`. Licencja przed ewentualnym upublicznieniem.
  - `.claude/rules/*.md` i `.claude/settings.json` są w repo.
  - `CLAUDE.md` jest lokalny i w `.gitignore`: cienki wskaźnik na reguły i [README.md](README.md), bez zasad.
- **Skutki:**
  - Fixtures i docs bez prawdziwych NIP, PESEL i IBAN; przykłady są fikcyjne i oznaczone.
  - Jesteś jedynym właścicielem praw autorskich. Bez zależności tylko na GPL; LGPL bez modyfikacji dopuszczalna ([architecture.md](architecture.md)).
  - Deny w `settings.json` to dodatkowa warstwa; ochroną jest test allowlisty w kodzie (D-13).
- **Pytania:** Q-08.

### D-25 Plany płatne odłożone poza `docs/`

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Badanie planów płatnych dla innych firm leży w archiwum poza repo.
- **Decyzja:** Plany płatne odłożone. Notatki leżą poza repo i poza sesjami faz. Specyfikacja dotyczy jednego użytkownika.
- **Skutki:** Tanie decyzje, które nie blokują rozwoju później:
  - `SecretStore` zamiast czytania zmiennych środowiskowych w konektorach;
  - DTO faktury niezależne od źródła;
  - centralne maskowanie w Monolog (`Redactor`);
  - bez zależności tylko na GPL; LGPL bez modyfikacji dopuszczalna ([architecture.md](architecture.md));
  - dane firmy w `setting`, nie w stałych: `company.identity` (NIP, nazwa, adres i dane Podmiot1), `company.tax_micro_account`, `company.zus_account`; stawki w `ryczalt.service_map`; okres VAT stały, miesięczny (D-02);
  - adresy i wersje API urzędów w konfiguracji;
  - bez `tenant_id`.
- **Pytania:** Q-09.

### D-26 basal: budować w F7 czy odłożyć

- **Status:** zastąpiona przez D-29 (2026-10-08): basal odłożony. Rekord niżej to stan sprzed D-29.
- **Kontekst:** basal-1.5 jest publiczny od 5.10.2026, na licencji Apache-2.0 ([strona projektu](https://basal.si5.pl/), [karta modelu](https://huggingface.co/Remek/basal-1.5-4.5B)). Programu wcześniejszego dostępu nie ma. Kalibracja wymaga setek oznaczonych przypadków, a przy wolumenie JDG zbiera się je miesiącami.
- **Decyzja (domyślna):** Decyzja o budowie zapada po F6. Jeśli tak, start od kategorii faktur kosztowych w trybie cienia.
- **Skutki:**
  - Punkt „zgłoś zastosowanie basal” usunięty z warunków wstępnych.
  - Model i rewizja w `basal.model` i `basal.revision`; zmiana tylko po teście na zbiorze z `classification` i nowym progu.
  - Uruchomienie i kalibracja w [modules/basal.md](modules/basal.md). Host na Macu oznacza basal natywnie (D-18).
- **Pytania:** Q-37. Następca: D-29.

### D-27 Kanał wysyłki VAT-UE

- **Status:** przyjęta 2026-10-08 (źródła MF); test 418, VAT-UEK i bramki testowej w F5.
- **Kontekst:**
  - VAT-UE składasz elektronicznie do 25. dnia za miesiąc z usługami art. 28b dla podatników z UE (art. 100, [ustawa o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)).
  - Specyfikacja e-Deklaracji opisuje wysyłkę z danymi autoryzującymi przez `sendUnsignDocument` ([specyfikacja e-Deklaracji](https://podatki-arch.mf.gov.pl/media/3616/edek_specyfikacja_we-wy_2-4_test_www.pdf), [struktury](https://podatki-arch.mf.gov.pl/e-deklaracje/dokumentacja-it/struktury-dokumentow-xml/)).
  - Badanie planów płatnych wskazało bramkę tylko z podpisem kwalifikowanym.
  - Źródło sprawdzone 8.10.2026 ([specyfikacja e-Deklaracji 2.4](https://podatki-arch.mf.gov.pl/media/3616/edek_specyfikacja_we-wy_2-4_test_www.pdf), [facts.md](facts.md)). 422 i 423: dane autoryzujące tylko dla podatnika osoby fizycznej, nie dla pełnomocnika. 418: podpis kwalifikowany wymagany dla danego typu deklaracji; czy dotyczy VAT-UE, sprawdza test w F5 (R-30 w [facts.md](facts.md)).
- **Decyzja:** Składasz Ty jako podatnik, z danymi autoryzującymi przez `sendUnsignDocument`, za bramką zatwierdzeń w F5. Weryfikacja źródła 8.10.2026 wskazuje kanał; potwierdzenie testem w F5 ([modules/jpk.md](modules/jpk.md)).
- **Skutki:**
  - Konektor `edeklaracje` z celem `EDEKLARACJE_TARGET`. W F5 sprawdzasz działanie bramki testowej oraz kody 418 i 410 dla VAT-UE i VAT-UEK.
  - Do go-live F5 składasz VAT-UE w e-Deklaracjach (sekcja „Teraz – zgodność” w [README.md](README.md)).
  - Wymóg podpisu kwalifikowanego oznacza nowy rekord i koszt certyfikatu.
- **Pytania:** Q-32, Q-12.

### D-28 Wąska F0a: tylko prod na VPS, jeden passkey, dowód z CI i listy kontrolnej

- **Status:** przyjęta 2026-10-08.
- **Kontekst:**
  - F0a budowała paczki dowodów, komendę demo, trzy projekty compose, step-up i bramki Infection, zanim było cokolwiek do zatwierdzenia.
  - F0a, F0b i F1 miały razem ok. 93 kryteria i 24 kryteria przekrojowe. Przy pracy po godzinach nie da się ich zamknąć przed 15.12.2026.
  - Źródło: przegląd C4–C7 i S10 (przegląd z 8.10.2026, archiwum poza repo).
- **Decyzja:**

  | Obszar | Decyzja |
  | --- | --- |
  | środowiska | na VPS tylko projekt prod, bez projektów dev, staging i restore; dev na laptopie; test odtworzenia w jednorazowym projekcie lokalnym |
  | dostęp do panelu w F0a | Tailscale i jedno logowanie passkeyem; rejestracja i odzyskanie w wersji minimalnej |
  | step-up i drugi passkey | w F0b, razem z bramką zatwierdzeń |
  | push | tylko ntfy; bez PWA, Web Push i VAPID |
  | dowód działania | raport JUnit z CI, artefakty przebiegu CI i ręcznie pisana lista `docs/evidence/<phase>.md`: kryterium → pass albo fail → artefakt |
  | wycięte z narzędzi dowodu | `kokpit:demo`, wykonawca `host`, generator podsumowania, test rejestru wobec tabeli kryteriów |
  | testy mutacyjne | Infection doradczo, bez blokowania merge, do powstania `src/Tax` w F2 |

  Dowód działania zostaje obowiązkowy. Każdy wiersz kryteriów odbioru ma kolumnę „Dowód”. Faza jest skończona tylko, gdy `docs/evidence/<phase>.md` istnieje, wszystkie wiersze mają pass, a wiersze `[manual]` są odhaczone.
- **Skutki:**
  - Mniej RAM; na VPS bez drugiego klucza sejfu, drugiej nazwy DNS i osobnego węzła Tailscale (Q-14, D-18).
  - D-08 traci PWA, a D-19 ogranicza push do ntfy. PWA i Web Push są wierszami w [roadmap.md](roadmap.md).
  - Kryteria drugiego passkeya i step-upu przechodzą z F0a do F0b.
  - Do zmiany: [phases/f0a-szkielet.md](phases/f0a-szkielet.md), [phases/f0b-bramka-kalendarz.md](phases/f0b-bramka-kalendarz.md), [testing.md](testing.md), [operations.md](operations.md), [security.md](security.md) i kontrakt nazw w [naming.md](naming.md).
  - Wymuszenie zielonego CI przed merge zależy od planu GitHub (Q-43).
- **Pytania:** Q-14, Q-15, Q-43.

### D-29 Odłożenia i cięcia zakresu

- **Status:** przyjęta 2026-10-08.
- **Kontekst:**
  - basal potrzebuje ok. 200 etykiet na pytanie, a JDG ma kilka pism i dziesiątki faktur kosztowych miesięcznie.
  - API e-Doręczeń PROD wymaga certyfikatu kwalifikowanego i czterech bramek wykonalności ([modules/e-doreczenia.md](modules/e-doreczenia.md)). Zapas z powiadomieniami mailowymi pokrywa obowiązek.
  - Wolumen KSeF to ok. 2 eksporty na godzinę, więc podwójny stos limitów nie ma uzasadnienia.
  - Źródło: przegląd C1–C3 i C8–C10 (przegląd z 8.10.2026, archiwum poza repo).
- **Decyzja:**

  | Temat | Decyzja | Warunek powrotu |
  | --- | --- | --- |
  | basal (F7) | odłożony; [modules/basal.md](modules/basal.md) zostaje z oznaczeniem „odłożone”; tabela `classification` wypada ze schematu F0a, reguły basal z `.claude/rules` (zostaje wskaźnik na roadmap) | wiersz w [roadmap.md](roadmap.md) |
  | czat: Mercure i worker strumieniowania | wycięte; synchroniczne żądanie i ramka Turbo | wiersz w [roadmap.md](roadmap.md) |
  | czat: eskalacja do Sonnet 5.5 | wycięta | wiersz w [roadmap.md](roadmap.md) |
  | czat: co zostaje | tylko Claude Haiku 5.5; nazwane zapytania, rola `chat_ro`, `draft_document` | – |
  | F4: API e-Doręczeń PROD | odłożone, bez wniosku INT teraz; e-Doręczenia to powiadomienia mailowe, odbiór ręczny w oficjalnej aplikacji, `manual_channel_check` i termin z pisma wpisany do kalendarza | wiersz w [roadmap.md](roadmap.md) |
  | limity wywołań | `rate_counter` z `blocked_until` i `Retry-After` | okna przesuwne: wiersz w [roadmap.md](roadmap.md) |
  | KRS, CEIDG, GUS | wiersze usunięte z tabel konektorów; odłożone jak w D-20 | wiersz w [roadmap.md](roadmap.md) |
  | `opening_balance` | tabela zastąpiona kluczami `setting` i plikami dowodów | – |

- **Skutki:**
  - D-26 zastąpiona. D-10 bez eskalacji. Q-36 i Q-37 rozstrzygnięte; Q-30 czeka na bramki 1–2.
  - Koszt certyfikatu kwalifikowanego wypada z D-09 do powrotu F4.
  - Bez API kalendarz nie wylicza terminów z pism. Termin wpisujesz ręcznie po odbiorze w aplikacji ([compliance-calendar.md](compliance-calendar.md)).
  - Wariant hosta dla odłożonego F7 jest w [roadmap.md](roadmap.md); aktywny host w [operations.md](operations.md).
  - Każdy odłożony element ma w [roadmap.md](roadmap.md) wiersz z warunkiem powrotu. Powrót wymaga nowego rekordu D-xx.
  - Do zmiany: [data-model.md](data-model.md), [modules/czat.md](modules/czat.md), [modules/e-doreczenia.md](modules/e-doreczenia.md), [modules/rejestry-kursy.md](modules/rejestry-kursy.md), [architecture.md](architecture.md), [operations.md](operations.md), `.claude/rules`.
- **Pytania:** Q-20, Q-30, Q-36, Q-37.

### D-30 Szybka ścieżka do dry run JPK_EWP i JPK_ST 15.12.2026

- **Status:** przyjęta 2026-10-08. Zastępuje kolejność z D-06.
- **Kontekst:**
  - Data 15.12.2026 to Twój cel, nie termin ustawowy.
  - Ustawowe są wpis do ewidencji do 20. dnia następnego miesiąca (§ 6 [Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf)) i JPK_EWP(4) za 2026 do 30.04.2027 ([broszura JPK_EWP(4)](https://www.podatki.gov.pl/media/0qmfysqh/broszura_informacyjna_dot_jpk_ewp-4.pdf)).
  - Dawny dry run zależał od F1 na PROD i OAuth FreshBooks. [modules/ewidencja.md](modules/ewidencja.md) ma już źródło `import`: XML JPK_EWP(4) albo CSV z K_1–K_10.
  - Przy ok. 10 h tygodniowo F2.4 w dawnej kolejności kończy się w lutym albo marcu 2027.
  - Źródło: przegląd M6 i sekcja 3 (przegląd z 8.10.2026, archiwum poza repo).
- **Decyzja:** Kolejność realizacji: F0a (wąska, D-28) → F2.1 → F2.4 → dry run → F0b → F1 → F2.2/F2.3 → F3 → F5 → F6. F4 PROD i F7 odłożone (D-29). Id faz bez zmian; [README.md](README.md) pokazuje nową kolejność.

  | Krok | Zakres |
  | --- | --- |
  | F0a | wąska wersja z D-28 |
  | F2.1 | `revenue_entry` i import 2026 z CSV albo eksportu JPK_EWP, z K_8 i K_9 policzonymi poza kokpitem |
  | F2.4 | generator JPK_EWP(4) i JPK_ST(1) z walidacją XSD na schematach w repo |
  | dry run | `app:jpk:dry-run`, bez bramki i bez konektora `jpk` |
  | F0b → F1 → F2.2/F2.3 | bramka i kalendarz, odczyt KSeF, potem źródła KSeF Subject1 i FreshBooks oraz uzgodnienia |
  | F3 → F5 → F6 | sprzedaż w KSeF, JPK w PROD, czat |

  | Wybór | Powód |
  | --- | --- |
  | ewidencja przed bramką i KSeF | JPK_EWP to jedyny nowy plik z twardym terminem; faktury wystawiasz dziś w Aplikacji Podatnika KSeF |
  | K_8 i K_9 policzone w narzędziu | bez nich `FxRateProvider` z F1 wraca na ścieżkę krytyczną |
  | dry run bez bramki | `app:jpk:dry-run` nic nie wysyła, więc nie potrzebuje F0b |
  | KSeF i FreshBooks po dry run | to źródła uzgodnienia, nie warunek pliku |

- **Skutki:**
  - F2.1 i F2.4 nie mogą zależeć od tabel i usług F0b ani F1. Plik fazy F2 wskazuje, skąd biorą `setting` i pliki.
  - Diagram faz i kamienie milowe w [README.md](README.md) idą za tą kolejnością.
  - Termin 30.04.2027 spełnisz też bez kokpitu, darmowymi narzędziami MF (D-06).
  - [phases/f2-ewidencja.md](phases/f2-ewidencja.md) dzieli zakres F2 według tej kolejności. F0b i F1 dostają nowe warunki wejścia.
- **Pytania:** Q-04, Q-11.

### D-31 „Doręcz klientowi” synchronicznie, bez transportu Messenger

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Pliki wskazywały naraz dwa warianty: synchroniczne żądanie panelu i osobny transport Messenger z `max_retries: 0`. Każdy plik kazał drugiemu zmienić się w przeciwną stronę (przegląd z 8.10.2026, M1; archiwum poza repo).
- **Decyzja:** „Doręcz klientowi” wysyła e-mail z PDF faktury konektorem `mail`, synchronicznie w żądaniu panelu, poza Messenger. Dawny transport `client_delivery` nie istnieje.
- **Skutki:**
  - Ponowienie tylko nowym kliknięciem; wynik widzisz od razu w panelu.
  - `client_delivery` znika z kontraktu nazw, z `.claude/rules`, z [architecture.md](architecture.md), [operations.md](operations.md) i [modules/freshbooks.md](modules/freshbooks.md).
- **Pytania:** Q-26.

### D-32 Konektor testowy `dummy`

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Kontrakt nazw przyjął `dummy`, a pliki F0a i F0b używały nazwy `fake`. F0a mówiła „nigdy w konfiguracji prod”, kontrakt „nigdy przy `APP_ENV=prod`” (przegląd M2).
- **Decyzja:** Konektor testowy to `dummy`: klasa `Dummy` w `src/Connector/Dummy`, `credential_state.kind` = `dummy_token`, licznik `dummy.call`, host `dummy.invalid`. Nigdy nie jest włączony przy `APP_ENV=prod`. Nazwa `fake` jest wycofana.
- **Skutki:** Zmiana w [phases/f0a-szkielet.md](phases/f0a-szkielet.md), [phases/f0b-bramka-kalendarz.md](phases/f0b-bramka-kalendarz.md) i w `CHECK` na `approval.connector` ([data-model.md](data-model.md)). `fake` trafia do nazw wycofanych w [naming.md](naming.md).
- **Pytania:** –.

### D-33 Klucze kryteriów odbioru według kontraktu nazw

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** Kontrakt nazw wycofał klucze `<phase>-NN`, wynik `manual_pending` i opcję `--attach`. Pliki F0a, F0b i F1 nadal ich używały (przegląd M3).
- **Decyzja:** Każde kryterium odbioru ma klucz w formacie slug z [naming.md](naming.md): kebab-case unikalny w fazie, `cc-NN` dla kryteriów przekrojowych. Wyniki i ścieżki dowodów pochodzą tylko z kontraktu. Klucze `fX-NN`, wynik `manual_pending` i opcja `--attach` są wycofane.
- **Skutki:**
  - Jedna mechaniczna zmiana wierszy w [phases/f0a-szkielet.md](phases/f0a-szkielet.md), [phases/f0b-bramka-kalendarz.md](phases/f0b-bramka-kalendarz.md) i [phases/f1-ksef-odczyt.md](phases/f1-ksef-odczyt.md).
  - Kolumna „Dowód” zostaje w każdym wierszu (D-28). Odwołania do kluczy w innych plikach idą za zmianą.
- **Pytania:** –.

### D-34 Licznik Claude i id joba CI

- **Status:** przyjęta 2026-10-08.
- **Kontekst:** [architecture.md](architecture.md) używał licznika `claude.api`, a [modules/czat.md](modules/czat.md) `claude.messages`. Job CI miał zapis z myślnikiem i z podkreślnikiem. GitHub dopuszcza w id litery, cyfry, `-` i `_` ([GitHub Actions, składnia workflow](https://docs.github.com/en/actions/writing-workflows/workflow-syntax-for-github-actions)) (przegląd M3 i M4).
- **Decyzja:** `rate_counter.bucket` dla Claude to `claude.messages`. Id joba CI to `unit_property` wszędzie.
- **Skutki:** `claude.api` i `unit-property` to nazwy wycofane w [naming.md](naming.md).
- **Pytania:** –.

### D-35 FreshBooks bez firmy testowej

- **Status:** przyjęta 2026-10-08.
- **Kontekst:**
  - Dokumentacja API FreshBooks nie opisuje sandboxu ani konta testowego. Konto zakłada się przez rejestrację, plan partnerski przez wsparcie ([FreshBooks API, 8.10.2026](https://www.freshbooks.com/api/start)).
  - Druga firma to dodatkowy koszt (informacja właściciela; przegląd S8).
  - [README.md](README.md) i [modules/freshbooks.md](modules/freshbooks.md) zakładały osobną firmę testową.
- **Decyzja:**
  - F2 i F3 działają na ręcznie pisanych fixtures i na prawdziwej firmie tylko do odczytu.
  - Zapis zwrotny F3 sprawdzasz pierwszy raz na PROD: jedna faktura przez bramkę zatwierdzeń. Wynik trafia do dowodów fazy.
- **Skutki:**
  - Do pierwszego zapisu zwrotnego w F3 kokpit tylko czyta z FreshBooks.
  - Testy automatyczne idą na fixtures; nagrania z prawdziwej firmy tylko po oczyszczeniu z danych ([testing.md](testing.md)).
  - Kryterium zapisu zwrotnego w F3 jest `[manual]`, z dowodem z PROD.
  - Wiersz o firmie testowej w warunkach wstępnych [README.md](README.md) zmienia się na fixtures i odczyt.
- **Pytania:** Q-44, Q-26.

### D-36 Kod F2.1 przed odbiorem F0a

- **Status:** przyjęta 2026-10-10 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - F0a ma zielone wszystkie wiersze `ci` (26/26), ale nie ma odbioru: wiersze `[manual]` czekają na VPS z D-18 i Tailscale.
  - Plik F2 ma wejście „F0a odebrana” ze skutkiem „stop” dla F2.1, a F2.1 startuje najpóźniej 15.11.2026 (D-30).
  - F2.1 używa tylko komponentów F0a, które CI już sprawdza: `FileStore`, `audit_log`, `SecretStore`, `Redactor`, panel.
- **Decyzja:**
  - Kod, testy i rejestr kryteriów F2.1 powstają przed odbiorem F0a, tylko na danych fikcyjnych.
  - Odbiór F2.1 (lista `docs/evidence/f2.md`) i import na danych PROD dalej czekają na odbiór F0a.
- **Skutki:**
  - Wejście „F0a odebrana” zatrzymuje odbiór F2.1 i import PROD, nie kod.
  - Wiersze `[manual]` F0a nie blokują pracy nad F2.1 i F2.4 na fikcyjnym zbiorze.
  - Kod F2 trafia na gałąź `f2-ewidencja` (gałąź na fazę), bo job `evidence` bierze fazę z prefiksu gałęzi.
- **Pytania:** Q-11, Q-14.

### D-37 Kod F2.4 przed odbiorem F2.1, JPK_ST(1) po dostarczeniu XSD

- **Status:** przyjęta 2026-10-11 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Plik F2 ma wejście „F2.1 odebrana” ze skutkiem „stop” dla F2.4. Odbiór F2.1 czeka na odbiór F0a (D-36), a F0a na VPS z D-18.
  - Dry run do 15.12.2026 (D-30) potrzebuje generatora JPK_EWP(4) przed tym terminem.
  - XSD JPK_ST(1) nie leży w `resources/xsd`; sesja w chmurze nie pobiera z `www.gov.pl`, więc plik dostarczasz Ty.
  - Wartość sekretu `auth_data` nie miała opisanego kształtu, a F2.4 czyta z niej `DataUrodzenia`.
- **Decyzja:**
  - Kod, testy i rejestr kryteriów F2.4 dla JPK_EWP(4) powstają przed odbiorem F2.1, tylko na danych fikcyjnych. Odbiór F2.4 dalej idzie po odbiorze F2.1.
  - Generator JPK_ST(1) i kryteria `jpk-st-build` i `jpk-st-dry-run` powstają po dostarczeniu XSD. Do tego czasu `app:jpk:dry-run JPK_ST` przy `jpk_st.enabled` = true kończy się błędem braku schematu, a przy false komunikatem Q-25.
  - `auth_data` to JSON z kluczami `birth_date` (RRRR-MM-DD) i `revenue_n2` (kwota N−2, tekst z dwoma miejscami po przecinku). F2.4 czyta tylko `birth_date`.
  - Dry run nie zapisuje XML w `FileStore`. Opcja `--output` zapisuje plik, a `filing.totals` ma sha256 pliku, liczbę wierszy, sumę i datę `--until`.
- **Skutki:**
  - Wejście „F2.1 odebrana” zatrzymuje odbiór F2.4 i dry run na danych PROD, nie kod.
  - Rejestr `tests/Acceptance/f2.yaml` dostaje wiersze `jpk-st-*` w sesji, w której XSD JPK_ST(1) trafia do `resources/xsd`.
- **Pytania:** Q-25.

### D-38 Kod F0b przed dry runem i przed odbiorem F0a

- **Status:** przyjęta 2026-10-11 (decyzja Claude w ramach pełnej odpowiedzialności za projekt; właściciel prosi o pracę bez przestojów).
- **Kontekst:**
  - Plik F0b ma wejście „F0a odebrana” ze skutkiem „stop” i założenie „F0b czeka na dry run”, bo wcześniejszy start mógłby przesunąć 15.12.2026.
  - Kod F2.1 i F2.4 dla JPK_EWP(4) jest gotowy (D-36, D-37). Do dry runu zostały XSD JPK_ST(1) od właściciela i przebieg na danych PROD na VPS, który robi właściciel.
  - Praca nad F0b nie zabiera więc czasu dry runowi.
- **Decyzja:**
  - Kod, testy i rejestr kryteriów F0b powstają teraz, na danych fikcyjnych, na gałęzi `f0b-bramka-kalendarz`.
  - F0b powstaje częściami, każda z osobnym PR do `main`: kalendarz (`PolishCalendar`), obowiązki i przypomnienia, step-up i drugi passkey, bramka zatwierdzeń.
  - Generator JPK_ST(1) ma pierwszeństwo, gdy tylko XSD trafi do `resources/xsd`.
  - Odbiór F0b (`docs/evidence/f0b.md`) dalej czeka na odbiór F0a.
- **Skutki:**
  - Rejestr `tests/Acceptance/f0b.yaml` rośnie z każdą częścią.
  - Wejście „F0a odebrana” zatrzymuje odbiór i go-live F0b, nie kod.
- **Pytania:** Q-14.

### D-39 Szczegóły silnika obowiązków F0b

- **Status:** przyjęta 2026-10-11 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Kryterium `zus-amount` mówi o „tytule z rachunkiem składkowym”, a skaner fixtures i dowodów pada na każdym 26-cyfrowym numerze rachunku.
  - Docs nie mówią, na jaki dzień silnik czyta `jpk_st.enabled` ani jak wygląda zmiana częstotliwości zaliczki w trakcie roku.
- **Decyzja:**
  - `obligation.payment_title` nie zawiera numeru rachunku. Tytuł ZUS to `Składki ZUS {okres}`, np. `Składki ZUS 26M12`. Tytuł powstaje tylko przy ustawionym rachunku (`company.zus_account` albo `company.tax_micro_account`); panel pokazuje rachunek z `setting` obok tytułu.
  - `jpk_st` za rok R czyta `jpk_st.enabled` obowiązujące 31 grudnia roku R.
  - Częstotliwość zaliczki (`ryczalt.advance_frequency`) czyta się na dziś. Przy zmianie obowiązek za pierwszy miesiąc kwartału przechodzi na regułę kwartalną (ten sam `dedupe_key`), a pozostałe miesięczne obowiązki `open` w horyzoncie stają się systemowym `not_applicable`.
  - Systemowy obowiązek `open` z regułą silnika, którego reguła już nie daje w horyzoncie, przechodzi w systemowe `not_applicable`. Wyjątek: stare `credential_expiry` po rotacji przechodzi w `done`.
  - Przegląd kanałów ma `period_start` i termin w dniu poniedziałku; sprawdzenie sald ma `period_start` = pierwszy dzień miesiąca sprawdzenia.
  - `notifications.dispatch` tworzy wiersz tylko dla najpóźniejszego offsetu, który już nadszedł. Przebieg nadrabiający po awarii wysyła jedno przypomnienie na obowiązek i kanał, nie serię starych. Zaległość dostaje jeden push dziennie od 8:00.
  - Przy wyłączonych konektorach wiersze `notification` czekają bez próby wysyłki. Ping dead-man `notifications` idzie tylko po przebiegu bez nieudanej wysyłki.
  - „Potwierdź wykonanie” działa tylko dla obowiązku bez `amount_due`; obowiązek z kwotą zamyka wpis zapłaty.
  - Rachunki fikcyjne w testach powstają w kodzie (`FictionalIdentifiers::account`), z nieistniejącym numerem rozliczeniowym banku 99999999.
- **Skutki:**
  - Treść obowiązków i przypomnień nie niesie numerów rachunków, więc nie trafia do dowodów ani logów.
- **Pytania:** Q-13, Q-16, Q-17, Q-25.

### D-40 Step-up i drugi passkey

- **Status:** przyjęta 2026-10-11 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - docs/security.md wymaga świeżej asercji z `userVerification=required`, nie starszej niż 5 minut, ale nie mówi, czy jedna asercja wystarcza na kilka akcji.
  - F0a ma indeks `webauthn_single_active`: najwyżej jeden aktywny passkey.
- **Decyzja:**
  - `StepUpVerifier` zapisuje udaną asercję w sesji. Każda akcja ze step-upem w ciągu 5 minut od asercji ją przyjmuje; wpis `auth.step_up` powstaje raz, przy asercji, a akcja zapisuje identyfikator użytego passkeya w szczegółach swojego wpisu.
  - Opcje ceremonii żyją w sesji najwyżej 5 minut i są jednorazowe. Asercja musi pochodzić od aktywnego passkeya zalogowanego użytkownika.
  - Drugi passkey powstaje przez ten sam jednorazowy link rejestracji co pierwszy, wydany w panelu po step-upie.
  - Panel nie unieważnia ostatniego aktywnego passkeya; odzyskanie dostępu zostaje przy `app:passkey:reset` przez SSH.
  - Migracja F0b usuwa indeks `webauthn_single_active`.
- **Skutki:**
  - Zatwierdzenie w bramce (kolejna część F0b) wymaga tego samego `StepUpVerifier::require()`.
- **Pytania:** –.

### D-41 Szkielet bramki zatwierdzeń

- **Status:** przyjęta 2026-10-11 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - docs/approval-gate.md opisuje stany, wysyłkę i rekoncyliację, ale nie mówi, jak przedmioty F3, F5 i F6 podłączą się do bramki, jak liczyć wiek stanu `unknown` bez osobnej kolumny ani jak `messenger:failed:retry` ma odmówić ponowienia.
- **Decyzja:**
  - Przedmiot zatwierdzenia to usługa `ApprovalSubject` per `subject_type`: generator payloadu, bieżąca `version` encji i skutki po stronie encji. Konektor zapisu to `OfficialWriter` (metoda `submit` z `#[OfficialWrite]`) i `StatusReader` (tylko odczyt). Do F3 jedynymi przedmiotami są adaptery `dummy` w `when@dev` i `when@test`.
  - Poza regułą PHPStan `architecture.officialWrite` zapis pilnuje w czasie działania `DispatchGuard`: konektor odmawia `submit` bez znacznika ustawionego przez `OfficialWritesHandler` dla tego `approval_id`.
  - Tabela przejść żyje w kodzie (`ApprovalTransitions::TABLE`) jako kopia tabeli „Stany zatwierdzenia”; każde przejście to warunkowy `UPDATE` i jeden wpis `audit_log` z `approval_id` i `payload_sha256`.
  - Wiek stanu `unknown` (push `connector_failure` po 1 h, `/health`) liczy się od najpóźniejszej z chwil `submitted_at`, `dispatching_at`, `decided_at`; bez nowej kolumny.
  - Okno odpytywania `submitted` → `unknown` per konektor w parametrze `approval.poll_window`: `dummy` PT1H, `ksef` PT12H (sesja KSeF), `jpk` i `edeklaracje` P3D; F3 i F5 mogą je zmienić po nagraniach.
  - `OfficialWritesRetryGuard` kończy bez handlera każdą wiadomość ze znacznikiem `SentToFailureTransportStamp` z `official_writes` i każdą wiadomość bramki odebraną z innego transportu.
  - Wiadomości bramki niosą identyfikator w polu `id`, jak reszta wiadomości (`RedactingTransportSerializer`).
  - Klucz obcy `approval.superseded_by_id` jest odroczony, żeby stare zatwierdzenie i nowa prośba powstały w jednej transakcji przy indeksie jednego żywego wiersza.
- **Skutki:**
  - F3, F5 i F6 dokładają przedmioty i konektory jako nowe usługi, bez zmian w `ApprovalGate`.
  - Krok 3 `make deploy` wywołuje `app:approval idle`, a krok 4 runbooka odtworzenia `app:approval mark-restored`.
- **Pytania:** Q-35 (szkic bez konektora).

### D-42 Kod F1 przed odbiorem faz wcześniejszych, częściami

- **Status:** przyjęta 2026-10-11 (decyzja Claude w ramach pełnej odpowiedzialności za projekt; właściciel prosi o pracę bez przestojów).
- **Kontekst:**
  - Plik F1 ma wejścia „F0a i F0b odebrane” oraz „F2.1 i F2.4 odebrane” ze skutkiem „stop”. Kod F0b i F2.4 (JPK_EWP) jest na `main`, a odbiory czekają na VPS z D-18 (D-36, D-38).
  - Sesja Claude w chmurze nie łączy się z hostami testowymi KSeF, białej listy, VIES ani z NBP: proxy odrzuca połączenie. Nagrań HTTP nie da się tam wytworzyć.
  - Od 12.10.2026 wykaz podatników VAT ma nowe hosty `wykazpodatnikowvat.mf.gov.pl` i test `wykazpodatnikowvat-test.mf.gov.pl` ([API wykazu](https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat), sprawdzone 11.10.2026).
- **Decyzja:**
  - Kod, testy i rejestr kryteriów F1 powstają teraz, na danych fikcyjnych, na gałęziach `f1-…`, częściami z osobnym PR do `main`:
    1. model danych F1 (`invoice`, `invoice_line`, `counterparty`, `verification`, `fx_rate`, `sync_cursor`), klucze obce do tabel F2, przegląd kosztów, koszty spoza KSeF, import usług, przedpłata, WHT, obowiązki `vat_ue`, `wht_payment`, `wht_certificate`, odczyt FA(3) i import Subject1 z pliku XML (bez sieci), `FxRateProvider` ze źródłem NBP za interfejsem;
    2. konektor NBP, `KsefGateway`, synchronizacja, backfill, 21165, 429, klucze MF, raport przejścia;
    3. VIES, biała lista z nowymi hostami, polityka świeżości, sprawdzenie przed przelewem, samosprawdzenie.
  - Część 1 nie potrzebuje nagrań HTTP: faktury FA(3) do testów to fikcyjne pliki XML zgodne z XSD, a kursy to wiersze `fx_rate` wstawione w teście.
  - Nagrania HTTP dla części 2 i 3 powstają przez `app:fixtures:record` tylko na hostach testowych i NBP, z laptopa (`kokpit-dev`) albo z sesji w środowisku chmurowym z tymi hostami na liście dozwolonych. Ręcznie pisane odpowiedzi nie zastępują nagrań.
  - Przejście białej listy na nowe hosty robi część 3, razem z `ConnectorHosts` i plikami-domami hostów.
  - Odbiór F1 (`docs/evidence/f1.md`) i go-live dalej czekają na odbiory F0a, F0b, F2.1 i F2.4.
  - Do go-live F1 zostają ogólne reguły `wht_payment.monthly` i `cit_10z.yearly` z F0b obok `wht_payment.supplier`; zamiana na regułę per dostawca i warunek `cit_10z` ruszają z go-live (docs/compliance-calendar.md, „Uwagi do wierszy”).
  - Okres VAT kosztu jest zamknięty, gdy JPK_V7M za ten miesiąc ma stan `approved`, `submitted`, `accepted` albo `unknown`; zmiana kosztu wymaga wtedy korekty pliku. Pełne zamknięcie miesiąca daje F2.
- **Skutki:**
  - Wejścia „stop” F1 zatrzymują odbiór i go-live F1, nie kod.
  - Rejestr `tests/Acceptance/f1.yaml` rośnie z każdą częścią.
  - Wiersze `live` F1 czekają na sesję z dostępem do hostów testowych.
  - Stan 12.10.2026: część 2 ma konektor NBP, `KsefSync` (kursor, okna backfillu, odstęp 15 minut, 21165, 429 z pushem) i raport przejścia za interfejsem `KsefGateway`, sprawdzone na bramce w pamięci. Implementacja bramki nad biblioteką, nagrania, wiersze `f1.yaml` tej części oraz wpisy `ksef.sync` i `ksef.backfill` w harmonogramie czekają na sesję z dostępem do api-test.
  - Stan 12.10.2026, część 3: konektory `vies` i `white_list` (nowe hosty wykazu, dawne zostają na liście dozwolonych do wyłączenia przez MF), polityka świeżości w zadaniu `counterparty.refresh_due`, dowód ręczny, sprawdzenie przed przelewem z `wl_notice` i samosprawdzenie z alertami w panelu, sprawdzone na odpowiedziach w kształcie z dokumentacji API. Przerwa wykazu 22:00–0:00 działa jak próg licznika: zadanie czeka do północy. Ponowienia VIES mają odstępy z `registry.vies_retry_delays`; po 24 h od pierwszej próby jest alert `connector_failure`. Nagrania VIES i białej listy oraz wiersze `f1.yaml` z nagraniami (`pre-payment-*`, `refresh-due-eu-supplier`, `self-check`) czekają na sesję z dostępem do usług testowych.
- **Pytania:** Q-19, Q-39.

### D-43 Oszczędne minuty GitHub Actions

- **Status:** przyjęta 2026-10-14 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Od 12.10.2026 każdy job CI w repo prywatnym kończy się porażką po 1–3 s, bez runnera i bez logu (PR #12 i #13). Tak wygląda wyczerpany limit minut albo limit wydatków GitHub Actions dla repo prywatnego.
  - Workflow uruchamiał się dwa razy na każdy push do gałęzi z PR (`push` i `pull_request`), a każdy przebieg ma 8 jobów, z których każdy buduje obraz od zera (ok. 3 min). Jeden push do PR kosztował ok. 50 minut.
- **Decyzja:**
  - `push` uruchamia CI tylko na `main`. Gałąź z PR liczy się raz, przez `pull_request`.
  - `concurrency` anuluje starszy przebieg tego samego PR po nowym pushu; przebiegi `main` nie są anulowane.
  - `mutation` nie biegnie przy `pull_request`, póki jest doradczy (D-28). Z pierwszym plikiem w `src/Tax` job staje się blokujący i wraca do PR.
  - `evidence` biegnie tylko na gałęziach zaczynających się od `f` (prefiks fazy sprawdza `scripts/ci-evidence.sh`) albo przy `workflow_dispatch`. Gałąź bez fazy nie ma czego dowodzić.
  - Id jobów zostają bez zmian (kontrakt z [naming.md](naming.md)).
- **Skutki:**
  - Push do PR kosztuje ok. 21 minut zamiast ok. 50.
  - Przywrócenie minut (limit wydatków albo nowy okres rozliczeniowy) robi właściciel w ustawieniach rozliczeń GitHub; sesja Claude nie może też ponowić jobów (403).
- **Pytania:** Q-43.

### D-44 Kod F2.2 częściami, przed odbiorem F0b i F1

- **Status:** przyjęta 2026-10-14 (decyzja Claude w ramach pełnej odpowiedzialności za projekt; właściciel prosi o rozwój w czasie blokady CI).
- **Kontekst:**
  - F2.2 startuje po odbiorze F0b i F1. Kod F0b i F1 (części 1–2) jest na `main` albo w PR, a odbiory czekają na VPS i CI (D-36, D-38, D-42, D-43).
  - Sesja w chmurze nie łączy się z FreshBooks ani z hostami testowymi. FreshBooks nie ma firmy testowej, więc jego testy i tak stoją na ręcznie pisanych fixtures (D-35).
- **Decyzja:**
  - Kod F2.2 powstaje teraz na gałęziach `f2-…`, w trzech częściach z osobnym PR:
    1. bez sieci: klucze `ryczalt.service_map` i `ledger.auto_entries_enabled`, `ServiceMap`, data przychodu i kurs PIT przy imporcie Subject1, stawki pozycji, `RevenueEntryWriter` (DTO niezależne od źródła, D-25), odtworzenie `app:ledger:import ksef_subject1`, wpis ręczny w panelu i błąd wpisu;
    2. FreshBooks: OAuth w `credential_state`, polling `invoices` i `payments`, tabela `payment`, różnice kursowe, odtworzenie `freshbooks`;
    3. korekty z KOR (zadanie w panelu) i widok „Uzgodnienia” z eksportem CSV.
  - Data przychodu Subject1 powstaje przy imporcie z pól FA(3) i trafia do `invoice.revenue_date`; różne P_6A zostawiają ją pustą, a fakturę bez wpisu na liście braków w panelu.
  - Brak kursu PIT (`FxRateUnavailable`), stawki albo daty nie blokuje importu Subject1. Faktura czeka na liście braków; ponowne przejście (`Subject1Ledger::apply`, odtworzenie) dopisuje wiersze.
  - Wcześniejszy dzień zapłaty z art. 14 ust. 1c wchodzi z płatnościami FreshBooks w części 2.
  - Błąd wpisu zapisuje akcję audytu `ledger.entry_corrected`; wpis ręczny `ledger.manual_entry`.
- **Skutki:**
  - Wiersze F2.2 w `tests/Acceptance/f2.yaml` rosną z każdą częścią. Odbiór F2.2 dalej czeka na odbiory F0b i F1.
  - Zrzuty `screens/…` z kryteriów F2.2 to kroki `[manual]` przy odbiorze.
- **Pytania:** Q-22.
