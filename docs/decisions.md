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
| Q-02 | Kto składa JPK_V7M, VAT-UE, JPK_EWP i JPK_ST oraz PIT-28: biuro rachunkowe czy Ty? Jaki dostęp dla biura? | Ty | decyduje, czy kokpit wysyła, czy tylko eksportuje | F2, F5 | – | Ty składasz wszystko, bez biura. Do go-live faz narzędzia MF albo obecne narzędzie; potem kokpit przez bramkę dla KSeF, JPK_EWP/ST, JPK_V7M i VAT-UE; PIT-28 i płatności ręcznie. D-03; od 2026-10-18 PIT-28 przez bramkę (D-96) | 2026-10-08 |
| Q-03 | Tylko B2B? Klienci konsumenci albo usługi elektroniczne dla konsumentów? | Ty | B2C zmienia miejsce świadczenia, OSS, mapowanie FA(3) i zakres VAT-UE | F3 | – | Tylko B2B: klienci z UE, spoza UE i z Polski. VAT-UE w zakresie, OSS poza zakresem. D-05 | 2026-10-08 |
| Q-04 | Kolejność faz: EWP przed V7M, e-Doręczenia wcześniej, czat i basal po JPK_EWP? | Ty | dawna kolejność nie dawała zapasu przed 30.04.2027 | wszystkie | – | Pierwsza odpowiedź: F0a → F0b → F1 → F2 → F3 → F4 → F5 → F6 → F7 (D-06). Zmieniona tego samego dnia: F0a (wąska) → F2.1 → F2.4 → dry run → F0b → F1 → F2.2/F2.3 → F3 → F5 → F6; F4 PROD i F7 odłożone. Id faz bez zmian. D-30, D-29 | 2026-10-08 |
| Q-05 | Model czatu: Claude Haiku 5.5 czy Sonnet 5.5? | Ty | koszt pytania i profil żądań | F6 | – | Tylko Claude Haiku 5.5 `claude-haiku-5-5`. Eskalacja do Sonnet 5.5 odrzucona (Q-36, D-29). D-10 | 2026-10-08 |
| Q-06 | Managed Agents czy własna pętla narzędzi? | Ty | gdzie działa pętla i gdzie są dane | F6 | – | Managed Agents odrzucone. Messages API i PHP tool runner, pętla w Symfony. D-11 | 2026-10-08 |
| Q-07 | Kto zatwierdza mapowanie FA(3) i stawki ryczałtu, skoro nie ma biura? | Ty | błędne oznaczenie usług zagranicznych oznacza korekty faktur | F3 | – | Ty oraz jednorazowy przegląd doradcy podatkowego jako bramka przed go-live F3. Zakres w Q-29. D-04 | 2026-10-08 |
| Q-08 | Repo publiczne czy prywatne? | Ty | dane w fixtures i dokumentacji, licencja | F0a | – | Prywatne `good-yellow-bee/kokpit-jdg`. Licencja przed ewentualnym upublicznieniem. Fixtures i docs bez prawdziwych NIP, PESEL i IBAN. D-24 | 2026-10-08 |
| Q-09 | Plany płatne dla innych firm teraz czy później? | Ty | wpływ na model danych i zakres | – | – | Odłożone. Notatki poza repo; specyfikacja dla jednego użytkownika. D-25 | 2026-10-08 |
| Q-32 | Kanał VAT-UE: wysyłka z danymi autoryzującymi (`sendUnsignDocument`) czy tylko z podpisem kwalifikowanym? | Ty | źródła były sprzeczne: specyfikacja e-Deklaracji i badanie planów płatnych | F5 | – | Dane autoryzujące przez `sendUnsignDocument`. Sprawdzone 8.10.2026 w [specyfikacji e-Deklaracji 2.4](https://podatki-arch.mf.gov.pl/media/3616/edek_specyfikacja_we-wy_2-4_test_www.pdf): 422 i 423 – tylko podatnik osoba fizyczna, nie pełnomocnik; 418 – deklaracja danego typu wymaga podpisu kwalifikowanego (typ podpisu per formularz: rozporządzenie MF, Dz.U. 2018 poz. 1106). Szczegóły w [modules/jpk.md](modules/jpk.md) i [facts.md](facts.md). Bramka testowa 15.10.2026: VAT-UE i VAT-UEK z danymi autoryzującymi przyjęte z UPO, bez 418 i 410 (D-68). D-27 | 2026-10-08 |
| Q-36 | Czy włączyć eskalację do Sonnet 5.5? | Ty | koszt i profil żądań przy trudnych pytaniach | F6 | wyłączona; model w konfiguracji | Nie. Czat tylko na Claude Haiku 5.5. Eskalacja i warunek powrotu są w [roadmap.md](roadmap.md). D-29 | 2026-10-08 |
| Q-37 | basal: budować w F7 (tryb cienia przez miesiące) czy odłożyć i zacząć od kategorii faktur? | Ty | zbieranie etykiet trwa miesiące; zasoby serwera | F7 | decyzja po F6; jeśli tak, start od kategorii faktur w trybie cienia | Odłożone. [modules/basal.md](modules/basal.md) zostaje z oznaczeniem „odłożone”. Tabela `classification` i reguły basal wypadają ze schematu F0a i z `.claude/rules`. Powrót w [roadmap.md](roadmap.md). D-29 (zastępuje D-26) | 2026-10-08 |
| Q-39 | Dane testowe KSeF TE (podmioty przez `/testdata`, drugi sprzedawca testowy, certyfikat self-signed, tokeny odczytu i `InvoiceWrite`) wytwarza sesja F1 pomocnikiem w `tests/` czy Ty ręcznie? | Ty | warunek startu F1; TE pozwala wytworzyć podmioty, certyfikat i tokeny przez API („KSeF TE” w [testing.md](testing.md)) | F1 | sesja F1, tylko na TE i tylko z fikcyjnymi NIP | Sesja F1, pomocnikiem w `tests/` przez `/testdata`. Tylko TE i fikcyjne NIP. Bez bramki „stop” na starcie F1: kryterium `live` w sesji 1 najpierw wytwarza dane TE. Przegląd S7 (przegląd z 8.10.2026, archiwum poza repo) | 2026-10-08 |
| Q-44 | Czy FreshBooks dostaje osobną firmę testową do F2 i F3? | Ty | dokumentacja API nie opisuje sandboxu ([FreshBooks API, 8.10.2026](https://www.freshbooks.com/api/start)); druga firma kosztuje (informacja właściciela, przegląd S8) | F2.2/F2.3, F3 | – | Nie. F2 i F3 na ręcznie pisanych fixtures i prawdziwej firmie tylko do odczytu. Zapis zwrotny F3 pierwszy raz na PROD: jedna faktura przez bramkę zatwierdzeń, wynik w dowodach fazy. D-35 | 2026-10-08 |
| Q-18 | Kto Cię zastępuje przy terminie, gdy Cię nie ma? Kogo wyznaczasz administratorem skrzynki ADE? Czy ustanawiasz UPL-1 albo inne pełnomocnictwo? | Ty | jedna osoba oznacza przerwę w składaniu i odbiorze pism; administrator skrzynki obsługuje korespondencję w imieniu przedsiębiorcy ([gov.pl, 16.09.2026](https://www.gov.pl/web/e-doreczenia/e-doreczenia-1-pazdziernika-2026-r-wazny-termin-dla-przedsiebiorcow-z-ceidg)) | F0a, F0b, przed go-live F3 | administrator skrzynki ADE jako zastępca przy odbiorze pism (przegląd S9); poza skrzynką zastępcy brak; procedura break-glass w [operations.md](operations.md); UPL-1 do rozważenia | Administrator skrzynki ADE jako jedyny zastępca: wyznaczasz go w aplikacji e-Doręczeń, jego imię i datę wpisujesz tylko na kartę awaryjną. Drugiego loginu w kokpicie i UPL-1 nie ma (D-99). Zmiana 2026-10-21: bez administratora skrzynki i bez zastępcy, pisma odbierasz sam (D-107) | 2026-10-19, 2026-10-21 |
| Q-40 | Dostawca SMTP dla maila zapasowego? | Ty | poświadczenia w Symfony Secrets, umowa powierzenia ([security.md](security.md)), odbiór `push-test-mail` w F0a | F0a | dostawca SMTP w UE z umową powierzenia (art. 28 [RODO](https://uodo.gov.pl/pl/file/1460)) | Scaleway Transactional Email (plan Essential), SMTP `smtp.tem.scaleway.com`, w UE; umowa powierzenia w warunkach Scaleway. Wymaga domeny nadawcy z SPF i DKIM. D-100 | 2026-10-20 |
| Q-41 | Cel backupu poza serwerem: gdzie leży repozytorium restic? | Ty | backup poza serwerem przed końcem F0a ([operations.md](operations.md)); umowa powierzenia ([security.md](security.md)) | F0a | magazyn obiektowy zgodny z S3, w UE, u innego dostawcy niż hosting, dla restic, z umową powierzenia (art. 28 [RODO](https://uodo.gov.pl/pl/file/1460)) | Scaleway Object Storage, region PL-WAW; inny dostawca niż hosting (OVHcloud). Repozytorium restic `s3:https://s3.pl-waw.scw.cloud/<bucket>`. D-100 | 2026-10-20 |
| Q-42 | Zewnętrzny serwis dead-man: healthchecks.io czy Uptime Kuma na innym hoście? | Ty | alert nie może zależeć od monitorowanego serwera („Monitoring” w [operations.md](operations.md)) | F0a | healthchecks.io | healthchecks.io, plan Hobbyist (20 checków; kokpit używa czterech z „Monitoring” w [operations.md](operations.md)). D-100 | 2026-10-20 |

### Otwarte

| Id | Pytanie | Właściciel | Dlaczego | Potrzebne w | Założenie domyślne | Odpowiedź | Data |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Q-10 | Jak wystawiasz faktury w KSeF od 1.04.2026 (narzędzie, seria numeracji)? Czy któryś miesiąc przekroczył 10 tys. zł brutto? | Ty | plan przejścia, import Subject1, kolizje P_2, procedura tymczasowa ([MF](https://ksef.podatki.gov.pl/ponizej-10-000-zl/)) | teraz, F1, F2, F3 | Aplikacja Podatnika KSeF z numeracją z FreshBooks | | |
| Q-11 | Ewidencja przychodów 2026: gdzie i jak prowadzona? Którym narzędziem robisz wpisy od 09.2026 i jaki ma format eksportu (dopuszczalne formaty: „Format importu” w [modules/ewidencja.md](modules/ewidencja.md))? Import z oryginalnymi datami wpisu czy rekonstrukcja? | Ty | źródło prawdy za 2026, wartości K_2, wejście importu w F2.1 (§ 6 [rozporządzenia, Dz.U. 2025 poz. 1294](https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf)) | F2.1 | F2.1 importuje 2026 z CSV albo eksportu JPK_EWP z policzonymi K_8 i K_9 (D-30); K_2 bez antydatowania (D-23) | | |
| Q-12 | Kto i czym składa dziś JPK_V7M? Czy jesteś zarejestrowany do VAT-UE (art. 97 ust. 3 [ustawy o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf))? Jak ujmujesz import usług w K_27–K_30? | Ty | zgodność teraz, zakres F5, import usług to R-18 w [facts.md](facts.md) | F5 | Ty, e-mikrofirma albo Klient JPK WEB; klasyfikację dostawców potwierdza przegląd doradcy (Q-21, Q-29) | | |
| Q-13 | Czy ADE firmy jest aktywny, a powiadomienia mailowe włączone? Czy masz prywatny ADE? Czy wyraziłeś zgodę na e-Korespondencję w e-US? | Ty | obowiązek biegnie od 1.10.2026; decyduje, które kanały monitorować ([gov.pl](https://www.gov.pl/web/e-doreczenia/e-doreczenia-1-pazdziernika-2026-r-wazny-termin-dla-przedsiebiorcow-z-ceidg)) | F0b | ADE aktywny, powiadomienia przetestowane; prywatny ADE i e-US sprawdzane ręcznie raz w tygodniu | | |
| Q-14 | [F0] Hosting: VPS w UE, serwer domowy czy Mac? W jakim kraju? Ile RAM? Tailscale (D-28) jako sidecar czy na hoście? TLS: `tailscale cert` (nazwa w logach CT) czy własna domena z DNS-01 i tokenem API DNS? | Ty | blokuje start F0a; passkey wymaga TLS na stałej nazwie, przechowywanie faktur poza Polską (art. 112a, R-28 w [facts.md](facts.md)), sekrety hosta ([security.md](security.md)), backup | F0a | VPS w Polsce albo w innym kraju UE z dostępem on-line dla organów (art. 112a ust. 2–3). Na VPS tylko projekt prod (D-28), więc RAM ≥ 2 GB ([operations.md](operations.md)). Dev na laptopie, test odtworzenia w jednorazowym projekcie lokalnym. Sidecar Tailscale; `tailscale cert` na neutralnej nazwie węzła. Stały IPv4 potrzebny dopiero przy powrocie do F4 (lista IP na INT, D-29) | Założenie domyślne (D-18). | 2026-10-09 |
| Q-15 | Kanał push: self-hosted ntfy czy ntfy.sh z tokenem? | Ty | prywatność treści powiadomień i dostarczanie na iOS; Web Push w PWA i Telegram odpadają (D-28) | F0a | self-hosted ntfy; treść to kategoria, liczba i link | | |
| Q-16 | ZUS: wariant składek społecznych albo okres preferencyjny? Próg składki zdrowotnej z przychodu bieżącego czy poprzedniego roku (art. 81 ust. 2h [ustawy o świadczeniach, Dz.U. 2025 poz. 1461](https://eli.gov.pl/api/acts/DU/2025/1461/text/T/D20251461L.pdf))? Czy potrzebna miesięczna DRA? | Ty | kwoty w kalendarzu i odliczenia od zaliczki | F0b, F2 | pełne składki społeczne; próg zdrowotnej z przychodu bieżącego roku; DRA do sprawdzenia | | |
| Q-17 | Zaliczka na ryczałt co miesiąc czy co kwartał? | Ty | terminy w kalendarzu i wyliczenie zaliczki | F0b, F2 | co miesiąc; wpłata co kwartał tylko przy przychodzie z poprzedniego roku do 200 tys. EUR (art. 21 ust. 1a i 1b [ustawy o ryczałcie, Dz.U. 2025 poz. 843](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)) | | |
| Q-19 | Biblioteka KSeF PHP: 1.10.x czy 2.0.0-rc.1? | Ty | API QR i kryptografii różni się między liniami ([Packagist](https://packagist.org/packages/n1ebieski/ksef-php-client)) | F1 | 1.10.x przypięta do dokładnej wersji, za interfejsem `KsefGateway`; migracja po wydaniu stabilnej 2.0 | Żadna: własny cienki klient KSeF API 2.0 w `src/Connector/Ksef` (D-70, zastępuje D-14) | 2026-10-15 |
| Q-20 | Rejestry: KRS, CEIDG i GUS zostają czy tylko VIES, biała lista i NBP? | Ty | koszt onboardingu (JWT CEIDG, klucz BIR) wobec wartości przy kontrahentach głównie zagranicznych | F1 | tylko VIES, biała lista i NBP; wiersze KRS, CEIDG i GUS wypadają z tabel konektorów i trafiają do [roadmap.md](roadmap.md) (D-29) | | |
| Q-21 | Zagraniczni dostawcy: traktowanie VAT (K_27–K_30, opłaty Stripe opodatkowane czy zwolnione) i klasyfikacja WHT (licencja, przetwarzanie danych, brak)? Czy masz certyfikaty rezydencji? Jaki moment podatkowy ma przedpłata (kredyty Anthropic)? Czy płacisz zagranicznym osobom fizycznym? | Ty z doradcą | JPK po stronie zakupów, pobór WHT i IFT-2R ([Dz.U. 2026 poz. 554](https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf)); przy przedpłacie obowiązek powstaje z dniem zapłaty (art. 19a ust. 8 [ustawy o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)), a koszt bierze dziś datę z P_6, OkresFa albo P_1 | F0b, F1, F5 | import usług dla Anthropic, FreshBooks i hostingu; klasyfikacja WHT w przeglądzie doradcy (Q-29); certyfikaty rezydencji zbierasz co roku. Przedpłata: ręczna zmiana `tax_point_date` z powodem na wierszu kosztu (przegląd S4). Zagraniczna osoba fizyczna: WHT z art. 29 [ustawy o PIT, Dz.U. 2026 poz. 592](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf), nie z ustawy o CIT; uwaga przy `counterparty.is_natural_person`; do weryfikacji (przegląd S5) | | |
| Q-22 | Jakie usługi fakturujesz i jaki mają kod PKWiU 2015 oraz stawkę ryczałtu? Czy coś rozliczasz okresowo (abonament)? | Ty z doradcą | stawka per pozycja, K_9, podział odliczeń, daty przychodu | F2, F3 | jeden rodzaj usług; stawka do potwierdzenia w przeglądzie doradcy; brak rozliczeń okresowych | | |
| Q-23 | Czy wybierasz art. 31a ust. 2a (kurs VAT według zasad PIT, na co najmniej 12 miesięcy)? | Ty | kolumny kursów PIT i VAT przy fakturze | F2, F3 | bez wyboru; VAT według art. 31a ust. 1 i 2 | | |
| Q-24 | Wypłaty Stripe w EUR i USD czy w PLN? Czy kokpit ma śledzić płatności klientów? Metoda kasowa PIT od 2027 (wybór do 20 lutego, art. 14c [ustawy o PIT, Dz.U. 2026 poz. 592](https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf))? | Ty | tabela `payment`, różnice kursowe w ewidencji, flaga Kasowy_PIT | F0b, F2 | śledzić płatności z FreshBooks w tabeli `payment`; waluta wypłat do sprawdzenia; bez metody kasowej | | |
| Q-25 | Czy masz środki trwałe albo WNiP do wykazu? | Ty | JPK_ST i tabela `fixed_asset`; projekt zwolnienia w toku ([projekt](https://legislacja.gov.pl/projekt/12413609)) | F2, F5 | brak; JPK_ST za flagą zależną od zwolnienia | | |
| Q-26 | Czy FreshBooks zostaje źródłem faktur? Jak oznaczasz szkic jako gotowy? Czy klient dostaje PDF z kokpitu z QR KSeF, czy mail albo link FreshBooks z zapisem zwrotnym? W jakim języku PDF? | Ty | znacznik gotowości, blokady wysyłki, zakres zapisu zwrotnego | F2.2/F2.3 (import), F3 | FreshBooks zostaje; znacznik w `po_number` albo tagu; klient dostaje PDF z kokpitu jako załącznik; PDF dwujęzyczny PL i EN | | |
| Q-27 | Jak dziś korygujesz faktury we FreshBooks: credit note, edycja czy anulowanie? | Ty | mapowanie na KOR i blokada edycji po przyjęciu | F3 | credit note tworzy szkic KOR w bramce; edycja po przyjęciu wymusza korektę | | |
| Q-28 | Przy awarii KSeF: czekać ze wstrzymaną kolejką czy wystawiać offline z certyfikatem typ 2? | Ty | zakres trybu offline i zakup certyfikatu typ 2 | F3 | czekać; Latarnia wstrzymuje kolejkę, panel pokazuje status; bez trybu offline | | |
| Q-29 | Zakres przeglądu doradcy: tylko mapowanie FA(3) (z P_18 dla nabywców spoza UE i złotymi plikami XML) i PKWiU→ryczałt, czy pełna lista z D-04? Dalsze punkty: VAT i WHT dostawców, okresy korekt (TypKorekty, VAT-UEK), odczyt daty fikcji doręczenia, zaokrąglenia zaliczki, stawka różnicy kursowej, K_4 i K_10 wiersza `fx_difference`, K_10 i późne K_2 w ewidencji 2026. Widok faktury w portalu FreshBooks jako wizualizacja bez kodu QR (art. 106gb ust. 5). Nabywca z UE bez ważnego numeru VIES (`eu_b2b_unidentified`). Moment podatkowy przedpłat i WHT osób fizycznych (Q-21). | Ty z doradcą | przegląd zastępuje akcepty, które dawniej przypisywano biuru rachunkowemu; podatnikiem jest prowadzący działalność (art. 28a pkt 1 lit. a), a VAT-UE obejmuje tylko nabywców zidentyfikowanych (art. 100 ust. 1 pkt 4 [ustawy o VAT, Dz.U. 2026 poz. 1263](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf)) | go-live F3 | jedna konsultacja obejmuje wszystkie punkty; minimum to FA(3) i PKWiU→ryczałt. Pierwsze pytania to S3 i S4 z przeglądu. S3: `eu_b2b_unidentified` → `np I`, bez VAT-UE, `manual_evidence` statusu przedsiębiorcy; standard dowodu (art. 18 rozporządzenia wykonawczego 282/2011) do weryfikacji. S4: ręczny `tax_point_date` przedpłat (Q-21) | | |
| Q-30 | Dostawca ADE: Poczta Polska czy dostawca kwalifikowany? Czy pytamy COI o certyfikat dla JDG? Czy akceptujesz stały koszt certyfikatu kwalifikowanego do API PROD? | Ty, po odpowiedzi COI | wykonalność F4 w PROD; certyfikat kwalifikowany obowiązkowy dla podmiotów niepublicznych ([instrukcja PROD](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce)) | powrót do F4 PROD po F5 | Poczta Polska; bramki 1–2 poza kodem; pytanie do COI i zakup dopiero przy powrocie do F4 | F4 PROD odłożone: teraz bez wniosku INT, certyfikatu i pytania do COI. e-Doręczenia to powiadomienia mailowe, odbiór ręczny w aplikacji, `manual_channel_check` i termin w kalendarzu. Pytanie wraca po F5, gdy bramki 1–2 mają odpowiedzi. D-29 | 2026-10-08 |
| Q-31 | Nadwyżka VAT naliczonego: zwrot (który termin) czy przeniesienie? Kody GTU lub procedur? | Ty | logika deklaracji w JPK_V7M(3) | F5 | przeniesienie na następny okres; brak GTU i procedur | | |
| Q-33 | Claude API: transfer do USA na DPA z SCC czy region UE (Bedrock bez effort per wiadomość albo Google Cloud)? Jaka podstawa przetwarzania danych osobowych w czacie (art. 6 RODO)? | Ty | podstawa RODO, wybór dostawcy ([DPA](https://privacy.claude.com/en/articles/7996862-how-do-i-view-and-sign-your-data-processing-addendum-dpa)) | F6 | Claude API bezpośrednio, DPA z SCC; art. 6 ust. 1 lit. f RODO ([modules/czat.md](modules/czat.md)) | | |
| Q-34 | Po powrocie F4: maskowana główna treść pisma z limitem długości czy pełna maskowana treść z załącznikami? | Ty | zakres maskowania i dane opuszczające serwer | F6, po powrocie F4 | maskowana główna treść z limitem długości, bez załączników; streszcza model | | |
| Q-35 | Szkice z czatu wysyła kokpit czy tylko kopiujesz? | Ty | wykonawca szkicu i ryzyko podmiany odbiorcy lub rachunku | F0b (schemat), F6 | tylko kopiowanie | | |
| Q-38 | Forma opodatkowania na 2027, jeśli UD458 wejdzie, a przychód 2026 przekroczy 250 tys. EUR? | Ty | prawo do ryczałtu w 2027 i stawka 17% ([proces druku 3147](https://api.sejm.gov.pl/sejm/term10/processes/3147)) | F0b (przypomnienie), poza fazami: do 20.02.2027 przy przychodzie w styczniu (art. 9 ust. 1 [ustawy o ryczałcie, Dz.U. 2025 poz. 843](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf)) | ryczałt, jeśli przychód 2026 nie przekroczy 250 tys. EUR; metryka przychodu na panelu | | |
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
| D-14 | biblioteka KSeF 1.10.x przypięta, za `KsefGateway` | zastąpiona przez D-70 | Q-19 |
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
| D-45 | FreshBooks w F2.2: szkic `draft` od dnia przejścia, data przychodu z `create_date` albo wcześniejszej zapłaty, stan OAuth jako jeden zaszyfrowany JSON, przypisanie płatności i wiersze `fx_difference` | przyjęta 2026-10-14 | Q-24, Q-26 |
| D-46 | korekty KOR i uzgodnienia w F2.2: różnica netto per stawka z `StanPrzed`, zamknięcie zadania bez step-upu, raport uzgodnień liczony na żądanie bez tabeli, FreshBooks czytany przy otwarciu widoku | przyjęta 2026-10-14 | Q-22 |
| D-47 | kwoty F2.3: zaliczka dopiero po końcu okresu, próg zdrowotnej z przychodu narastająco do końca miesiąca minus zapłacone społeczne, `health_bracket_manual` jako dolna granica, miesiące rozliczenia zdrowotnej od pierwszych parametrów roku, `ryczalt.eligibility` z dwoma limitami w euro | przyjęta 2026-10-14 | Q-16, Q-17, Q-38 |
| D-48 | okres F2.3: `period` powstaje przy pierwszym zapisie z datą w M; zamknięty M to też JPK_V7M w drodze albo przyjęty bez wiersza `period`; `ledger.entry_check` działa tylko po przełączeniu wpisów; komplet szukany kluczem deduplikacji; wpis do minionego M sprawdza komplet od razu | przyjęta 2026-10-14 | Q-11 |
| D-49 | złożenie poza kokpitem F2.3: JPK_V7M i VAT-UE z UPO jako `filing` `accepted`/`manual` bez `approval`; P_62 z pliku albo wpisany; bramki `period.checklist` liczone przy zapisie i tylko alarmują; korekta po ponownym otwarciu jako `purpose` 2; `vat_ue` po bramkach od pierwszego zamkniętego miesiąca | przyjęta 2026-10-14 | – |
| D-50 | kod F3 przed odbiorem F0b, F1 i F2, częściami: F3.1–F3.4 bez sieci na `FakeKsefGateway`, ręcznie pisanych nagraniach KSeF i fixtures FreshBooks; F3.5 na api-test po `KsefGateway` z sesji 1 F1 | przyjęta 2026-10-14 | Q-29 |
| D-51 | założenia F3.1 do przeglądu doradcy (Q-29): P_18 = 1 dla `non_eu_b2b`; `eu_b2b` z `PrefiksPodatnika` PL i wymaganym `vat_eu_number`; `eu_b2b_unidentified` odrzucany na każdym celu; kurs VAT z art. 31a ust. 1–2 bez ust. 2a (Q-23); KOR jako pary pozycji przed i po z `StanPrzed` | przyjęta 2026-10-14 | Q-23, Q-29 |
| D-52 | założenia F3.2 część a: powody pozostania w `draft` w `invoice.ready_blockers`; pozycje szkicu z FreshBooks (`qty`, `unit_cost`, jednostka `usł.`); onboarding = identyfikator i wiersz `verification`; warunki prośby i decyzji z zapisanych weryfikacji (bez wywołań VIES); zdjęcie znacznika daje `superseded` bez następcy; P_1 i dane nabywcy zapisane przy prośbie | przyjęta 2026-10-14 | Q-26 |
| D-53 | założenia F3.2 część b: credit note wskazuje fakturę korygowaną linią `Faktura: <numer>` w `notes`; pozycje „po” = „przed” minus uznanie pozycji o tej samej nazwie; edycja przyjętej faktury daje szkic KOR bez numeru do credit note; zadania sprzedaży w `invoice.panel_task`; obejście sprawdzane po udanym przebiegu Subject1 tylko dla faktur z wierszem `invoice`; zadanie „faktura spoza kokpitu” według P_1 względem `valid_from` | przyjęta 2026-10-14 | Q-27 |
| D-54 | założenia F3.3: 429 przy pierwszym wywołaniu wraca do `approved` (`dispatch.deferred`); niezgodny `form_code` i drugie 21184 → `rejected`; rekoncyliacja odczytem bez zamykania sesji, okno dnia od `dispatching_at`; dopasowanie Subject1 w imporcie; 440 porównuje `invoiceHash` z listy faktur sesji oryginału; status Latarni w `cache.scheduler`; kolizja P_2 jako zadanie `p2_collision` z push | przyjęta 2026-10-14 | Q-28 |
| D-55 | założenia F3.4: dompdf z wąskim wyjątkiem LGPL w sprawdzeniu licencji; KOD I z `xml_sent` o sha256 równym `invoiceHash` przyjęcia, host z `ksef.kod_i_hosts` dla celu faktury; jeden dwujęzyczny PDF zapisany raz; link płatności i adres klienta z endpointów FreshBooks do potwierdzenia na PROD; zapis zwrotny tylko `sales_invoice` VAT, wiadomość przy `accepted`; okres równoległy bez faktur z `approval` | przyjęta 2026-10-14 | Q-26, Q-10 |
| D-56 | kod F5 częściami, przed odbiorem F0b, F1, F2 i F3: F5.1, F5.2, F5.4 i F5.5 bez sieci na `FakeJpkGateway`, `FakeEDeklaracjeGateway` i ręcznie pisanych nagraniach; F5.3 i F5.6 (live) po bramkach testowych; brak XSD zatrzymuje tylko swoją część | przyjęta 2026-10-14 | Q-12, Q-25, Q-31 |
| D-57 | założenia F5.1: kształt JSON API JPK i numer oryginału jako 32 znaki hex do potwierdzenia w F5.3; odmowa przed `FinishUpload` → `rejected`, timeout `FinishUpload` → `unknown`; fikcyjne zaślepki XSD tylko w testach, `jpk-init-upload` i `jpk-auth-data` wstrzymane; `hash_mismatch` → złożenie `draft`; data urodzenia tylko w `Podmiot1` (wymóg XSD); atrapa `dummy` w `FilingSubject` poza prod | przyjęta 2026-10-14 | – |
| D-58 | kod F6 częściami, przed odbiorem F0b–F5: F6.1–F6.4 bez sieci na `MockHttpClient` i ręcznie pisanych odpowiedziach Messages API z danymi fikcyjnymi; F6.5 (live) po kluczu workspace testowego z budżetem przebiegu; model tylko `claude-haiku-5-5`, narzędzia z `chat.phases_live`, `chat_ro` na widokach właściciela | przyjęta 2026-10-14 | Q-33, Q-35 |
| D-59 | założenia F6.1: środowisko widoków `chat_*` z ustawienia sesji `kokpit.environment` (brak → zero wierszy); widoki bez nazw, NIP, adresów, rachunków, PESEL i wolnego tekstu; sumy sprzedaży tylko ze stanów `accepted`, `corrected`, `offline_issued`, koszty z `cost_review` = `business`; `get_fx_rate` tylko z zapisanego kursu dnia roboczego przed datą; rola `chat_ro` poza migracją, granty warunkowe; retencja `chat.retention_months` w UTC | przyjęta 2026-10-14 | – |
| D-60 | założenia F6.2: `anthropic-ai/sdk` 0.58.0 z `transporter` = `Psr18Client` nad `ConnectorHttpClient`, `maxRetries` 0, limit czasu w transporcie; ciało w formacie przewodowym przez `messages->raw->create`, własna pętla zamiast `toolRunner`; wyniki narzędzi zapisane dopiero po udanym żądaniu, otwarte `tool_use` zamykane `is_error`; snapshot prawa to dokładny wiersz `chat.law_snapshot`, brak snapshotu blokuje start rozmowy; `draft_document` na liście, ale do F6.4 zwraca `is_error`; live wymaga `CHAT_LIVE_RUN_BUDGET_USD` | przyjęta 2026-10-14 | – |
| D-61 | założenia F6.3: formularz niesie `seq` następnej wiadomości, pytanie zapisane od tej `seq` → ramka z historii bez API, inna `seq` → komunikat; blokada `chat.turn.<id>`; ramka `chat-turn` sama przy nagłówku `Turbo-Frame`, inaczej cała strona; wykres tylko z `tool_result` executora, dane ponownie z `ChatQueryRunner`; znaczniki w formacie kontrolera Symfony UX Chart.js z tabelą, bez instalacji `symfony/ux-chartjs` i JS panelu w tej sesji; limit czasu żądania 780 s w PHP-FPM i proxy | przyjęta 2026-10-14 | – |
| D-62 | założenia F6.4: prośba o szkic to `chat_message.draft_requested` ostatniej wiadomości `user` z blokiem `text`; odbiorca tylko `K-NN` z mapy i z `counterparty` tego środowiska; `flagged_values` dla IBAN, NRB, NIP z poprawną sumą i kwot z dwoma miejscami spoza bazy odbiorcy, potwierdzane w szkicu przed decyzją; prośba `draft` bez push (`requestByUser`); „Skopiowano” sprawdza termin i hash; Symfony Workflow `draft` bez nowej migracji | przyjęta 2026-10-14 | – |
| D-63 | XSD JPK i e-Deklaracji w `resources/xsd` od 15.10.2026: JPK_V7M(3) 1-0E, JPK_ST(1) 1-2, VAT-UE(5) 2-0E, VAT-UEK(5) 2-1E, SIG-2008 2-0, `initupload.xsd` z plikami bazowymi; importy przez mapę pełnego adresu `schemaLocation` → plik (`locations` w manifeście); `AuthData` poza opublikowanym XSD `InitUpload`, sprawdzany osobno; link `/media/qord0r0j` nieużywany; zaślepki testowe usunięte, `jpk-init-upload` i `jpk-auth-data` odblokowane | przyjęta 2026-10-15 | – |
| D-64 | założenia F5.2 JPK_ST(1): generator na XSD 1-2, węzeł `EWP` z `ZnacznikST` 1 i `Podmiot1` jak JPK_EWP; w pliku środki przyjęte do końca okresu i niewykreślone przed jego początkiem, F_1 od 1 w kolejności F_4; prośba tylko przy `jpk_st.enabled` = true na 31.12. roku, inaczej komunikat Q-25 bez wiersza `filing`; bez bramek JPK_EWP, pusty wykaz przy włączonej fladze to plik bez wierszy; wysyłka tym samym `JpkWriter` | przyjęta 2026-10-15 | Q-25 |
| D-65 | założenia F5.4 JPK_V7M(3): generator `JpkV7mBuilder` z kwot w PLN po kursie VAT z `invoice`, znacznik `JpkMarker` liczony przy budowie (pusty tryb bez numeru blokuje jak `online`); deklaracja z sum K zaokrąglonych HALF_UP do pełnych złotych, P_39 z P_62 przyjętego JPK_V7M za M−1 albo z `opening.vat_carry_forward_pln` z `valid_from` = M, `vat.excess_policy` tylko `carry_forward`; bramki `V7mGates` blokują prośbę, wynik i lista `DI` w `period.checklist`; przyjęcie zamyka okres przez `MonthClose`; korekta `purpose` 2 tylko w okresie `reopened`; `jpk.replace_di` od chwili `xml_sent`; „Otwarcie” przez `OpeningBalance` ze step-up; kwota WHT z `wht.rates` i kursu NBP z bazy | przyjęta 2026-10-15 | Q-21, Q-29, Q-31 |
| D-66 | założenia F5.5 VAT-UE(5) i VAT-UEK(5): `VatUeBuilder` grupuje sprzedaż `eu_b2b` miesiąca (z KOR ze znakiem różnicy) per kraj i numer VAT UE, P_Uc HALF_UP do pełnych złotych, `eu_b2b_unidentified` poza plikiem; twarda bramka K_12 (`VatUeK12Gate`); trasa KOR `vat_ue`/`wait`/`vat_uek`; własny cienki konektor `edeklaracje` (podklasa `SoapClient` na `ConnectorHttpClient`), `DaneAutoryzujace` wstawiane w pamięci workera jako ostatni element korzenia, walidacja deklaracji i SIG-2008 osobno, bo XSD VAT-UE(5) go nie deklaruje; mapowanie statusów `requestUPO` | przyjęta 2026-10-15 | Q-32 |
| D-67 | kształt żądań bramki e-Deklaracji z opublikowanego WSDL bramki testowej: element `sendUnsignDocument` (nie `sendUnsignDocumentRequest`), przestrzeń nazw `https://bramka.e-deklaracje.mf.gov.pl/xsd` i `SOAPAction` `urn:<operacja>` potwierdzone; `GateService.xsd` w `resources/xsd`, ciała żądań walidowane nim w testach | przyjęta 2026-10-15 | F5.6 |
| D-68 | F5.6 e-Deklaracje na bramce testowej: VAT-UE(5) i VAT-UEK(5) z `DaneAutoryzujace` przyjęte z UPO (bez 418 i 410), D-27 i Q-32 potwierdzone; testy live przez bramkę zatwierdzeń z prawdziwym klientem pod `HostAllowlist`, nagrania z przebiegu live przez `Scrubber` (zapis urzędowy nie idzie przez `app:fixtures:record`) | przyjęta 2026-10-15 | F5.6 |
| D-69 | F5.3 i JPK_V7M z F5.6 na test-e-dokumenty: pełna ścieżka (`InitUploadSigned`, Put Blob do `taxdocumentstorageNNtst`, `FinishUpload`, `Status`) działa z opublikowanym certyfikatem testowym MF; bramka testowa weryfikuje dane autoryzujące, więc fikcyjne kończą się 419; kryteria TE sprawdzają stan końcowy (`accepted` z UPO albo `rejected` 419), a przyjęcie z UPO i duplikat po przyjęciu potwierdza pierwsze złożenie PROD; TLS 1.3 z listy sześciu | przyjęta 2026-10-15 | F5.3, F5.6 |
| D-70 | F3.5 KSeF na api-test: własny cienki klient KSeF API 2.0 (`KsefApi`, `KsefAuthenticator`, `KsefCrypto`, `HttpKsefGateway`) zamiast biblioteki z D-14; TE zakładane w teście pieczęcią XAdES z losowym NIP; Latarnia TE zwraca dane statyczne, więc warunek wysyłki tylko na PROD; przyjęta KOR zmienia oryginał na `corrected`; nagrania z przebiegu live bez kroków uwierzytelnienia | przyjęta 2026-10-15 | F3.5 |
| D-100 | dostawcy hostingu: OVHcloud VPS-1 w Warszawie (x86_64, KVM), Tailscale Standard (Personal odrzucony: regulamin tylko do użytku niekomercyjnego), backup restic w Scaleway Object Storage PL-WAW, mail zapasowy przez Scaleway Transactional Email, dead-man healthchecks.io Hobbyist, pakiety GHCR `app` i `proxy` publiczne | przyjęta 2026-10-20 | Q-40, Q-41, Q-42 |
| D-101 | liczby PIT-28 do przepisania w Twój e-PIT na `/taxes`: `Pit28Builder::amounts` liczy części D–N bez pozycji formularza, także dla roku bez wariantu z XSD; generator PIT-28(27) mapuje te same kwoty | przyjęta 2026-10-20 | D-96 |
| D-102 | pierwsze przebiegi joba `live`: `EDeklaracjeTePit28Test` z losowym NIP na przebieg, bo bramka testowa przyjmuje jeden PIT-28 roku na identyfikator podatkowy i kolejny odrzuca kodem 411; paczka dowodów joba `live` także po czerwonym teście (`if`: nie anulowany i testy nie pominięte) | przyjęta 2026-10-20 | D-96, D-97 |
| D-103 | job `mutation` nigdy nie liczył mutacji w CI: obraz `development` bez sterownika pokrycia, a `tee` ukrywał kod wyjścia Infection; krok dostaje `shell: bash` (pipefail), więc job jest uczciwie czerwony i dalej doradczy; `cc-23` = fail do osobnej zmiany z pomiarem | przyjęta 2026-10-20 | D-28, D-43, D-47 |
| D-104 | job `mutation` mierzy: pcov w obrazie `development`, wątek Infection na rdzeń z własną kopią bazy testowej i katalogiem plików (`TEST_TOKEN`), zmutowany plik wczytany przed wyłączeniem wrappera `file://` Infection (brak `fsync`), `memory_limit=2G` (kod 255), raport JSON jako artefakt `mutation`; progi 85/95 bez zmian | przyjęta 2026-10-20 | D-28, D-43, D-103 |
| D-105 | mutanty równoważne: wzorce linii źródła per mutator z uzasadnieniem w `infection.json5` (strażniki `\LogicException`, typy kolumn z wiersza, flagi i głębokość JSON dla `jsonb`); rok i miesiąc daty przez `IsoDate` zamiast `substr`; bez zbędnych `(string)` przy `UuidV7::generate()`; `executionOrder="default"` dla powtarzalnego pokrycia; progi 85/95 bez zmian | przyjęta 2026-10-20 | D-28, D-104 |
| D-110 | reszta importu wyciągu: wydatek → niezapłacony koszt (KSeF i spoza KSeF) z tą kwotą brutto przy rachunku odbiorcy, NIP albo numerze faktury w tytule, tylko przez podgląd; zatwierdzenie wpisuje `invoice.paid_on` = dzień operacji; lista operacji mBanku nie ma salda, więc `/cashflow` pokazuje ruch netto z zapisanych operacji per rachunek i walutę, poza saldem, bez salda i prognozy pozycji | przyjęta 2026-10-21 | D-88, D-108 |
| D-108 | import wyciągu bankowego: lista operacji CSV z mBanku (`MbankCsvParser` za interfejsem `BankStatementParser`), ręczne wgranie na `/bank`, podgląd z propozycjami i zatwierdzenie w jednej transakcji; dopasowanie wpływu do faktury sprzedaży i płatności po numerze w opisie i kwocie, przelewu do ZUS i US do otwartego obowiązku płatniczego z tą kwotą za zakończony okres, tylko przy jednoznaczności w obie strony; idempotentnie po `line_hash`; `src/Bank` w zakresie Infection | przyjęta 2026-10-21 | Q-24, D-45, D-84 |
| D-107 | Q-18: bez administratora skrzynki ADE i bez zastępcy; pisma odbierasz sam, tak samo każdy użytkownik kokpitu; ryzyko fikcji doręczenia (art. 42 ust. 2 u.d.e.) przy nieobecności zaakceptowane, przed dłuższą nieobecnością sprawdzasz skrzynkę; zastępuje część D-99 o administratorze skrzynki | przyjęta 2026-10-21 | Q-18, D-03, D-99 |
| D-106 | job `mutation` blokuje merge: wraca do `pull_request` bez `continue-on-error`, cały zakres na PR i `main` (bez `--git-diff-filter`), bo warunek z D-47 jest spełniony; progi 85/95 bez zmian | przyjęta 2026-10-20 | D-28, D-43, D-47, D-105 |
| D-99 | Q-18: zastępcą jest tylko administrator skrzynki ADE wyznaczony w aplikacji e-Doręczeń; bez drugiego `app_user`, bez UPL-1; imię administratora tylko na karcie awaryjnej, nigdy w repo; „Rola zastępcy” z [roadmap.md](roadmap.md) odrzucona | przyjęta 2026-10-19; część o administratorze skrzynki zastąpiona przez D-107 | Q-18 |
| D-98 | pierwsza instalacja `kokpit-prod`: `scripts/prod-install.sh --node <nazwa>` jako root na VPS tworzy pliki hosta (`/etc/kokpit/secrets`, pytanie o brakujące wartości bez echa), hasła ról bazy w wolumenach przy pustej bazie, `pgpass`, konfigurację ntfy z użytkownikami i tokenem, sejf PROD w wolumenie `vault` (`scripts/init-prod-vault.php`), nazwę węzła `TS_HOSTNAME`, certyfikat `tailscale cert` z cronem `scripts/prod-cert.sh`, repozytorium restic i plik wdrożenia `/etc/kokpit/prod.env`, który czytają `make deploy` i `make rollback`; `PANEL_ORIGIN` i hosty powiadomień trafiają do kontenerów; `/health` po wdrożeniu przez adres VPN z kontenera app z ponowieniami, pierwszy backup przy pierwszym wdrożeniu; check dead-man `ksef.sync`; `--rehearsal` na danych fikcyjnych w CI; serializer transportów pomija `ScheduledStamp` | przyjęta 2026-10-19 | po F0a |
| D-97 | job `live` bierze publiczny certyfikat testowy MF ze zmiennej repozytorium `MF_TEST_CERT_PEM` (nie sekretu), sprawdza go `openssl x509` z ważnością i zapisuje do `var/jpk/mf-test.crt` w kontenerze `app` przed testsuite `live`; bez zmiennej job kończy się przed testami | przyjęta 2026-10-19 | F5.3 |
| D-96 | PIT-28 przez e-Deklaracje: złożenie `PIT_28` za rok przez bramkę zatwierdzeń i konektor `edeklaracje` z `DaneAutoryzujace` z `auth_data`; generator per wariant formularza z opublikowanego XSD w `resources/xsd`, bez wariantu dla roku prośba odmawia; bramki JPK_EWP roku i przyjęty JPK_EWP z tymi samymi sumami per stawka; na PROD dodatkowo zapis przeglądu doradcy wariantu (`pit_28.review`); najpierw bramka testowa; tylko pierwsze złożenie, korekta ręcznie | przyjęta 2026-10-18 | Q-02 |
| D-95 | nowe narzędzia czatu z fazy F2: `get_period_summary` (K_8 z ewidencji, najnowszy przyjęty JPK_V7M, zaliczka i ZUS okresu), `get_cashflow` (reguły `/cashflow`, sumy per waluta), `compare_periods` (dwa okresy: przychód, sprzedaż i koszty netto PLN); widoki `chat_filing` (tylko liczby z `filing.totals`) i `chat_payment` (bez identyfikatorów FreshBooks) dla `chat_ro`; nowy `tool_set`, więc tylko nowe rozmowy; `explain_filing` zostaje w roadmapie | przyjęta 2026-10-18 | F6 |
| D-93 | podpisane obrazy i kanał aktualizacji: job CI `images` buduje `app` i `proxy`, z push do `main` publikuje je w GHCR z tagiem sha i podpisuje digest cosign bez klucza (OIDC GitHub Actions, Sigstore); `make deploy` pobiera obrazy commita zamiast budować, weryfikuje tożsamość `ci.yml@refs/heads/main`, wyzwalacz push i sha commita, przypina digest; `make rollback KOKPIT_COMMIT=<sha>`, `make verify-images` i `make update-check`; cosign i dostęp do GHCR na VPS robi właściciel | przyjęta 2026-10-18 | po F0a |
| D-94 | drugi punkt cache w żądaniach czatu: automatyczny `cache_control` (`ephemeral`, 5 min) na poziomie żądania obok jawnego punktu na snapshocie prawa; API stawia go na ostatnim bloku historii, więc kolejne żądania pętli narzędzi i kolejne pytania czytają historię z cache; zapisane `chat_message` bez znaczników | przyjęta 2026-10-18 | po F6 |
| D-91 | kreator pierwszego uruchomienia `/setup` (tylko stan i linki): kroki od passkeyów, `company.identity`, rachunków, wariantu i parametrów ZUS na bieżący rok, zaliczek, mapy usług i dnia przejścia KSeF po bilans otwarcia, poświadczenia `ksef_token_read`, `auth_data`, `freshbooks_oauth` i test push z e-mailem; zapis zostaje w istniejących formularzach i komendach; liczba kroków do zrobienia na pulpicie | przyjęta 2026-10-18 | po F1 |
| D-90 | klucz FIDO2 jako drugi passkey: rodzaj uwierzytelniacza `passkey` albo `security_key` na linku rejestracji i w `webauthn_credential.kind`; profil ceremonii `security_key` z `authenticatorAttachment` = `cross-platform`, `residentKey` = `discouraged`, `userVerification` = `required`; w panelu „Dodaj klucz FIDO2” po step-upie, przez SSH `--security-key` w `app:passkey:enrol-link` i `app:passkey:reset`; `auth.passkey_enrolled` z rodzajem i transportami; step-up przyjmuje każdy aktywny passkey | przyjęta 2026-10-18 | po F0b |
| D-89 | test odtworzenia jednym poleceniem: `scripts/restore-test.sh` na laptopie stawia jednorazowy `kokpit-restore` tylko z `db`, odtwarza bazę i `var/data` z jednego snapshotu (`restic --no-lock`, `pg_restore`), `app:ops:restore-check` sprawdza łańcuch z kotwicami snapshotu odtworzonego i najstarszego, hashe plików i liczby `approval`, `filing`, `revenue_entry` z miesiąca wobec `--counts-only` z PROD; raport JSON poza repo, `app:ops:record-restore` zapisuje `ops.restored`; bez sejfu PROD na laptopie, odmowa w sesji Claude Code | przyjęta 2026-10-18 | po F0a |
| D-92 | konektor poczty przychodzącej `mail_in`: dedykowana skrzynka na dokumenty kosztowe, IMAPS tylko do odczytu (`EXAMINE`, `BODY.PEEK[]`, lista poleceń w kodzie), host z `MAIL_IN_HOST`, poświadczenie `MAIL_IN_<ENV>_DSN` w sejfie, licznik `mail_in.fetch`, zadanie co 30 min; PDF, JPEG i PNG rozpoznane po sygnaturze do `FileStore` jako `cost_document`, wiadomość w `mail_in_message`, strona `/mail-in`; koszt wpisujesz sam; zmienia wyłączenie konektora poczty z [modules/e-doreczenia.md](modules/e-doreczenia.md) | przyjęta 2026-10-18 | po F1 |
| D-88 | cash-flow na 90 dni `/cashflow` (tylko odczyt): otwarte obowiązki z kwotą do końca horyzontu (z zaległymi, po wpłatach częściowych) wobec należności z przyjętych faktur sprzedaży po dopasowanych `payment` i kosztów bez `paid_on`; saldo tylko w PLN, inne waluty osobno, bez przeliczania; rezerwa „do odłożenia dziś” zostaje na panelu „Podatki” (D-76) | przyjęta 2026-10-18 | po F5 |
| D-87 | raport rok do roku w panelu `/reports`: przychód z ewidencji, VAT (należny, naliczony, P_51) z najnowszego przyjętego JPK_V7M, koszty netto firmowe i średni kurs NBP z tabel w bazie per miesiąc roku wobec poprzedniego, z wierszem roku i okresem porównywalnym; eksport CSV; bez nowego narzędzia czatu (`tool_set` bez zmian) | przyjęta 2026-10-18 | po F2.1 |
| D-86 | kreator zamknięcia roku `/year-close` (tylko odczyt): obowiązki roczne za rok N z terminem w N+1, stan 12 miesięcy (`period`), złożenia roku z liczbą plików XML i UPO, przychód per miesiąc i stawka, klucze `setting` bez wiersza od 1.01 N+1; ZIP roku `kokpit-rok-<N>.zip` z zestawieniami CSV, ewidencją i plikami złożeń sprawdzonymi sha256 | przyjęta 2026-10-18 | po F5 |
| D-85 | panel zdrowia konektorów `/connectors` (tylko odczyt): podsumowanie `connector_call` per konektor (wywołania, błędy, 429 i limit, średni i p95 czas, ostatnie wywołanie i błąd) za dziś, 7 albo 30 dni, wykres dzienny wywołań i błędów, aktywne blokady `rate_counter`, kursory `sync_cursor` i ostatnie 50 błędów bez ścieżek żądań; indeks `connector_call (environment, started_at)` | przyjęta 2026-10-18 | po F1 |
| D-84 | lista płatności klientów w panelu `/payments` (tylko odczyt): data, id płatności i faktury FreshBooks, dokument (faktura albo wiersz ewidencji), kwota, bramka, prowizja, wypłata, kurs faktyczny, przypisanie i stan różnic kursowych; filtr `?unmatched=1` (link z liczby nieprzypisanych na stronie FreshBooks) i eksport CSV | przyjęta 2026-10-18 | po F2.2/F2.3 |
| D-83 | lista faktur kosztowych w panelu `/costs` z eksportem CSV i akcją „Sprawdź przed przelewem”; przy `TAK` dane do przelewu (odbiorca, rachunek, kwota brutto w walucie faktury, tytuł z numerem faktury i numerem KSeF) z przyciskami kopiowania, przy `NIE` powyżej progu trzy wyjścia i zawiadomienie `wl_notice`; wynik wraca z zapisanej `verification`, bez ponownego wywołania białej listy | przyjęta 2026-10-18 | po F1 |
| D-82 | przegląd tygodnia mailem: zadanie `notifications.weekly_digest` w poniedziałek 7:30 planuje jeden mail `weekly_digest` na tydzień ISO z liczbami otwartych, zaległych i bliskich obowiązków, zatwierdzeń `pending` i `unknown` oraz poświadczeń `expiring` i `expired` środowiska; tylko klucze i liczby, bez kwot, nazw i terminów (D-19); wysyła `notifications.dispatch` | przyjęta 2026-10-18 | po F0b |
| D-81 | widżet „Najbliższe terminy” na pulpicie panelu: trzy najbliższe otwarte obowiązki środowiska z kalendarza (zaległe na początku), liczba otwartych i link do kalendarza; bez nowej tabeli i ustawienia | przyjęta 2026-10-17 | po F0b |
| D-80 | paczka diagnostyczna `kokpit:diagnostics`: jeden plik JSON w `var/diagnostics` (wersje, migracje, `/health`, kolejki, poświadczenia bez sekretów, blokady, `connector_call`, logi z wejścia), maskowanie `Redactor` i NIP, skaner fixtures blokuje zapis | przyjęta 2026-10-17 | po F0a |
| D-79 | kotwica łańcucha `audit_log` poza serwerem: `backup.run` zapisuje głowę łańcucha przed zrzutem w tagu snapshotu restic `audit-head:<sequence>:<hash>`, a `app:audit:verify --anchor` sprawdza bazę wobec tagów | przyjęta 2026-10-17 | po F0a |
| D-78 | szybkie wygrane z roadmapy: SBOM CycloneDX 1.6 jako artefakt `sbom` joba `lint` (własny `tools/sbom.php` z `composer.lock` i `importmap.php`, bez wtyczki Composer) i eksport CSV list panelu (ewidencja roku, obowiązki, faktury sprzedaży, złożenia) przez `CsvExport` z ochroną przed formułami arkusza | przyjęta 2026-10-17 | po F0b |
| D-77 | szybkie wygrane z roadmapy: kanał ICS obowiązków (`/calendar-feed/{token}.ics`, token z `app:calendar:feed-link`, w bazie tylko sha256, treść bez kwot jak push) i `composer audit --locked` w jobie `lint` | przyjęta 2026-10-17 | po F0b |
| D-76 | prognoza progów i rezerwa podatkowa (roadmap, priorytet 5 po F6): `ThresholdForecast` z run-rate zakończonych miesięcy wobec limitów w euro i progów zdrowotnej, kwota do odłożenia (ryczałt trwającego okresu i dopłata zdrowotnej), pole `quarterly_limit_eur` i wiersz domyślny `ryczalt.eligibility`; kurs progów w euro z października roku przychodu (art. 4 ust. 2), przed nim szacunek z flagą | przyjęta 2026-10-16 | po F2.3 |
| D-75 | obserwacja prawa automatyczna (roadmap, priorytet 3 po F6): konektor `eli` (`api.sejm.gov.pl`, tylko `GET`, licznik `eli.read`), zadanie tygodniowe `legal_watch.check`, tabele `legal_watch` i `legal_watch_hit`, push `legal_change` i szkic wierszy do [facts.md](facts.md) przez `app:legal-watch report`; ocena skutku zostaje ręczna | przyjęta 2026-10-16 | po F0b |
| D-74 | F1 rejestry i NBP na hostach testowych: nagrania VIES (`check-vat-test-service`), białej listy (`wykazpodatnikowvat-test.mf.gov.pl/api`) i NBP w `tests/Fixtures`; usługa testowa VIES zwraca pusty `requestIdentifier`, więc kryteria testowe go nie wymagają; Imperva przed wykazem daje `waf_challenge`; pusty zakres NBP daje alert `connector_failure` | przyjęta 2026-10-16 | F1 |
| D-73 | JavaScript panelu (domknięcie D-61): `symfony/ux-chartjs` 2.36, wykres przez `render_chart()`; importmap z pakietami JS w `assets/vendor` w repo (npm z kontrolą `integrity`, bez CDN i bez polyfillu); Turbo tylko dla ramek (Drive wyłączony); kontroler wykresu leniwy; CSP z nonce na żądanie (`CspNonce`, `csp_nonce()`) w `script-src` i `style-src`, styl czatu z pliku | przyjęta 2026-10-15 | F6 |
| D-72 | F1 poświadczenia KSeF na api-test: zapas certyfikatem typ 1 w `KsefAuthenticator` (`certificateAccessToken`, `KsefXades`, `ksef_auth_cert` = certyfikat i klucz w PEM; bez sekretu tokena `accessToken` idzie podpisem XAdES); dane TE samopodpisaną pieczęcią zamiast `/testdata/subject`; token `InvoiceRead` dostaje 403 przy otwarciu sesji; klucze MF bez kopii w konfiguracji, wybór certyfikatu ważnego teraz, bez zadania `mf_keys.refresh` dla KSeF; okna backfillu, 21165 i 429 na atrapie bramki | przyjęta 2026-10-15 | F1 |
| D-71 | F1 eksport KSeF na api-test: `HttpKsefGateway::startExport` i `exportPackage` tokenem `ksef_token_read` (klucz AES na eksport w pamięci procesu, części z adresu ze statusu bez tokenu, `partHash`, ZIP z `_metadata.json`); kod 420 to pusta paczka z kursorem na `from`; `ksef-sync-te` z pomocnikiem `KsefTeIssuer` (faktury poza bramką, tylko w `tests/`); nagranie `te-sync-export` trzyma metadane, odtworzenie składa i szyfruje paczkę | przyjęta 2026-10-15 | F1 |

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

- **Status:** przyjęta 2026-10-08. Punkt o zastępcy rozstrzygnęły D-99 i D-107: zastępcy brak, także administratora skrzynki ADE.
- **Kontekst:** Dawna specyfikacja zakładała biuro rachunkowe, które wysyła JPK, prowadzi ewidencję i akceptuje mapowania. Dwóch wysyłających oznaczałoby dwa źródła prawdy. Biura nie ma.
- **Decyzja:**
  - Wszystkie deklaracje, pliki i płatności składasz sam. Do go-live fazy używasz darmowych narzędzi MF albo obecnego narzędzia (ewidencja: Twoje narzędzie, MF go nie daje).
  - Kokpit przejmuje tylko obowiązki z kolumny „Przejmuje kokpit” w README, przez bramkę zatwierdzeń. PIT-28 (do D-96), WHT/IFT-2R/CIT-10Z i płatności zostają ręczne.
  - Kto składa co i która faza przejmuje obowiązek: tabela „Teraz – zgodność” w [README.md](README.md). Jeden obowiązek ma jedno źródło wysyłki.
  - Zastępca i break-glass zostają w zakresie, bo jedna osoba oznacza przerwę w składaniu i odbiorze pism. Administrator skrzynki ADE zastępuje przy odbiorze pism (Q-18). Poza skrzynką domyślnie zastępcy brak, UPL-1 do rozważenia, procedura w [operations.md](operations.md).
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

- **Status:** zastąpiona przez D-70 (15.10.2026): biblioteki nie ma w `composer.json`, kokpit ma własnego klienta.
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
  - Do go-live F2 wpisy robisz w narzędziu z Q-11, co miesiąc do 20.
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
  - `mutation` nie biegnie przy `pull_request`, póki jest doradczy (D-28). Z pierwszym plikiem w `src/Tax` job staje się blokujący i wraca do PR. Wrócił w D-106.
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

### D-45 FreshBooks w F2.2: szkice, data przychodu, OAuth i płatności

- **Status:** przyjęta 2026-10-14 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - [modules/freshbooks.md](modules/freshbooks.md) mówił, że do go-live F3 faktura FreshBooks od dnia przejścia czeka bez wiersza. Plik fazy F2 i kryterium `freshbooks-draft-before-subject1` wymagają szkicu `draft`, który przy imporcie Subject1 oddaje `freshbooks_id`.
  - Pole daty usługi FreshBooks (`fulfillment_date`) nie jest potwierdzone nagraniem, a firmy testowej nie ma (D-35).
  - FreshBooks zwraca jeden refresh token na grant i `account_id` tylko z `users/me`.
- **Decyzja:**
  - Wygrywa plik fazy: od dnia przejścia polling tworzy wiersz `invoice` w stanie `draft` (bez pozycji, bez `ready`), z kontrahentem po `freshbooks_client_id`. Szkic, którego kraju nie da się ustalić, czeka bez wiersza (wynik `pending`). Import Subject1 o tym samym numerze w jednej transakcji przenosi szkic do `cancelled_before_send` i daje `freshbooks_id` fakturze z KSeF.
  - Dzień przejścia: `setting` `ksef.sales_transition_on`, a bez niego P_1 najstarszej faktury sprzedaży z Subject1. Bez faktur z KSeF każda faktura FreshBooks jest sprzed przejścia.
  - Data przychodu faktury FreshBooks sprzed przejścia to `create_date`, a przy wcześniejszej zapłacie w `payment` dzień zapłaty (art. 14 ust. 1c). Różnicę z datą usługi pokazuje uzgodnienie FreshBooks (część 3).
  - Stan `freshbooks_oauth` to jeden zaszyfrowany JSON: refresh token, bearer, jego ważność, `account_id` i adres zwrotny. `valid_until` przesuwa się o rok przy każdym odświeżeniu. Odrzucony refresh token daje stan `expired` i błąd `grant_lost`.
  - Sekrety aplikacji OAuth: `FRESHBOOKS_<CEL>_CLIENT_ID` i `FRESHBOOKS_<CEL>_CLIENT_SECRET` ([security.md](security.md)).
  - Płatność bez faktury z `freshbooks_id` dostaje `revenue_entry_id` wiersza `regular` o tym numerze z najnowszego roku nie późniejszego niż zapłata.
  - Wiersz `fx_difference` ma `source` = `freshbooks` i `invoice_id` wiersza źródłowego. Zerowa różnica nie daje wiersza, ale ustawia `fx_posted_at`.
  - Kursor `invoices` nie cofa się. Faktura oczekująca ma zmianę najwyżej dzień przed `last_value`, więc okno `updated_min` minus 1 dzień ją obejmuje.
- **Skutki:**
  - Tabela „Klasyfikacja faktury FreshBooks” w [modules/freshbooks.md](modules/freshbooks.md) ma szkic `draft` już w F2.2.
  - Powiadomienie push „połącz ponownie FreshBooks” czeka na kategorię `notification`; do tego czasu stan `expired` pokazuje panel `/freshbooks`.
- **Pytania:** Q-24, Q-26.

### D-46 Korekty KOR i uzgodnienia w F2.2

- **Status:** przyjęta 2026-10-14 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - [modules/ewidencja.md](modules/ewidencja.md) opisuje pola wiersza `correction` z KOR i tabelę „Uzgodnienia”, ale nie mówi, jak liczyć różnicę netto KOR ani gdzie trzymać wynik uzgodnień.
  - FA(3) zapisuje korektę metodą „przed i po” (`StanPrzed` = 1 dla pozycji sprzed korekty) albo samą różnicą.
- **Decyzja:**
  - Różnica netto KOR w stawce to suma pozycji po korekcie minus suma pozycji `StanPrzed`. Pozycja bez `ryczalt_rate_pct` dostaje stawkę z `ryczalt.service_map` po P_7. Zerowa różnica w każdej stawce zamknięcia odmawia.
  - Import KOR z Subject1 nie pisze wierszy; zadanie to przyjęta KOR sprzedaży bez wiersza `correction` z jej `invoice_id`. Zamknięcie wymaga tokenu CSRF, bez step-upu, jak wpis ręczny. Audyt `ledger.correction_closed`.
  - Wiersz korygowany w stawce: jedno trafienie wybiera się samo; kilka trafień odmawia z prośbą o wskazanie (`close(..., $entries)`). Panel F2.2 nie ma jeszcze pola wyboru wiersza ani kursu z listy; kurs PIT przyjmuje jako id `fx_rate`.
  - Raport uzgodnień liczy się na żądanie z bazy, bez tabeli. Kontrola FreshBooks czyta faktury roku z API przy otwarciu widoku; bez połączenia raport ma wiersz `not_connected`. Data przychodu i kurs PIT jak w `FreshBooksLedger`.
  - Uzgodnienie importu porównuje liczbę wierszy i sumę K_8 z ostatnim `ledger.imported` roku; audyt importu dostaje `total_in_file`.
- **Skutki:**
  - Kontrola JPK_V7M i zaliczek z tabeli „Uzgodnienia” zostaje na F2.3 i F2.4; „kontrola pliku” na F2.4.
  - Wybór wiersza korygowanego i kursu z listy w panelu to poprawka przy pierwszym takim przypadku.
- **Pytania:** Q-22.

### D-47 Kwoty zaliczki, ZUS i limitu ryczałtu w F2.3

- **Status:** przyjęta 2026-10-14 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - [tax-rules.md](tax-rules.md) podaje wzór zaliczki, progi składki zdrowotnej i kurs „progi w euro”, ale nie mówi, kiedy obowiązek ma kwotę, jak liczyć próg w trakcie roku ani jak zapisać limity ryczałtu.
  - Kod F2.3 powstaje przed odbiorem F2.2, częściami jak F2.2 (D-44): najpierw kwoty i panel „Podatki”, potem okres, wpis do 20. dnia, złożenie poza kokpitem i blokada okresu.
- **Decyzja:**
  - Kwota `ryczalt_advance` powstaje dopiero po końcu okresu (miesiąca albo kwartału); wcześniej `amount_due` jest puste. Odliczenia to składki zapłacone w okresie według `paid_on` (społeczne i 50% zdrowotnej), plus nadwyżka z wcześniejszych okresów tego samego roku.
  - Próg składki zdrowotnej za miesiąc M: przychód od 1 stycznia do końca M minus składki społeczne zapłacone w tym czasie. `health_bracket_manual` z `zus.parameters` jest dolną granicą progu, np. przy wyborze przychodu z poprzedniego roku (art. 81 ust. 2h); próg nie spada w trakcie roku poniżej tej wartości.
  - Roczne rozliczenie zdrowotnej: próg z przychodu rocznego po społecznych, składka progu razy miesiące podlegania liczone od miesiąca pierwszych `zus.parameters` roku. Różnica ujemna daje `amount_due` = 0 i zadanie wniosku RZS-R w panelu.
  - `ryczalt.eligibility` ma pola `limit_eur` (limit obowiązujący) i `watch_limit_eur` (obserwowany próg z projektu, Q-38, może być pusty). Kurs to tabela A z pierwszego dnia roboczego października roku poprzedniego; tabela z innego dnia to błąd, nie zamiennik. D-76 prostuje rok: „roku poprzedzającego rok podatkowy”, czyli października roku przychodu.
  - Składki zapłacone wpisujesz w panelu `/taxes`; jeden wiersz `zus_payment` na miesiąc składkowy, ponowny zapis go zmienia i dopisuje `zus.payment_recorded`.
- **Skutki:**
  - Zestawienie PIT-28 liczy się z ewidencji i `zus_payment` na żądanie; wysyłka zostaje ręczna.
  - Blokada zmian `zus_payment` w zamkniętym miesiącu przychodzi z tabelą `period` w drugiej części F2.3.
  - Pierwszy kod w `src/Tax` miał przełączyć job `mutation` na blokujący (D-28, D-43). `src/Tax` ma covered MSI 98% przy progu 95%, ale próg dotyczy całego zakresu z `infection.json5` (`src/Tax`, `src/Ledger`, `src/Calendar`, `src/Approval`). Job zostaje doradczy, dopóki przebieg na `main` nie pokaże progów na całym zakresie; przełączenie to osobna zmiana.
- **Pytania:** Q-16, Q-17, Q-38.

### D-48 Okres, komplet ewidencji i blokada w F2.3

- **Status:** przyjęta 2026-10-14 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - [compliance-calendar.md](compliance-calendar.md) („Blokada okresu”) i [modules/ewidencja.md](modules/ewidencja.md) („Wpis do 20. dnia”) opisują stany okresu i `ledger.entry_check`, ale nie mówią, co robi kontrola przed przełączeniem wpisów, jak rozpoznać wpis dokumentu ani co zamyka okres, zanim powstanie złożenie z kokpitu.
- **Decyzja:**
  - Wiersz `period` (stan `open`) powstaje przy pierwszym zapisie z datą w M: wiersz ewidencji, KOR z okresem w M, `ledger.entry_check`. Silnik obowiązków go nie tworzy.
  - Miesiąc jest zamknięty, gdy `period.status` = `closed` albo istnieje JPK_V7M za M w stanie `approved`, `submitted`, `accepted` lub `unknown`. To drugie chroni okres, zanim złożenie dostanie `closed_by_filing_id`.
  - Koszt i `zus_payment` w zamkniętym M dostają odmowę `ClosedPeriod` z najwcześniejszym otwartym miesiącem. `revenue_entry` i KOR przechodzą (ewidencja jest tylko dopisywana) i ustawiają `correction_required_at` z wpisem `period.correction_required`.
  - KOR z `TypKorekty` 1 wskazuje okres VAT faktury pierwotnej, inne typy okres samej KOR.
  - `ledger.entry_check` działa tylko przy `ledger.auto_entries_enabled` = tak. Wcześniej wpis robi narzędzie z Q-11, a kokpit nie widzi całej sprzedaży, więc nie zamyka obowiązku `ledger_entry` sam; zamykasz go potwierdzeniem w kalendarzu.
  - Komplet: przyjęta faktura `VAT` z `revenue_date` w M ma wiersz `regular` po `invoice_id`, numerze KSeF albo numerze dokumentu w roku, w każdym `source`; KOR z `issue_date` w M ma wiersz `correction`; rozliczona płatność w walucie z `paid_on` w M ma `fx_posted_at`.
  - Wpis do minionego miesiąca (dokument, różnica kursowa, korekta) od razu sprawdza komplet tego miesiąca, więc obowiązek zamyka się bez czekania na 15. albo 20. dzień.
- **Skutki:**
  - Zadanie „korekta wymagana” jest na `/ledger` w sekcji `period-corrections`; braki poprzedniego miesiąca w sekcji `ledger-entry-check`.
  - Ponowne otwarcie, złożenie `manual` z UPO i bramki `checklist` są w drugiej połowie części 2 F2.3.
- **Pytania:** Q-11.

### D-49 Złożenie poza kokpitem, bramki i ponowne otwarcie w F2.3

- **Status:** przyjęta 2026-10-14 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Do go-live F5 JPK_V7M i VAT-UE składasz w narzędziach MF, a kokpit rejestruje złożenie z UPO ([phases/f2-ewidencja.md](phases/f2-ewidencja.md), „Zakres” F2.3). Plik fazy nie mówi, skąd wziąć P_62, jak zapisać korektę ani od kiedy `vat_ue` czeka na bramki zamiast kursora Subject1.
- **Decyzja:**
  - Panel `/filings` tworzy `filing` od razu w stanie `accepted` z `submitted_via` = `manual`, UPO w `stored_file` (`upo`) i wpisem `filing.manual_recorded` z sha256 UPO. Bez `approval`. Każda zmiana na stronie wymaga step-upu.
  - JPK_V7M wymaga P_62: z elementu `P_62` wgranego pliku (zapisanego jako `xml_sent`) albo wpisanego w formularzu, w pełnych złotych, do `filing.totals`.
  - Przed zapisem JPK_V7M bramki kroku 4, które działają przed F5 (`sales_final`, `costs_reviewed`, `fx_rates`, `vies_eu_b2b`), trafiają do `period.checklist`. Niespełniona bramka to alert na stronie, nie odmowa: kokpit tylko liczy i porównuje.
  - Przyjęty JPK_V7M zamyka okres i oznacza obowiązek `vat_jpk_v7m` za M jako `done`. VAT-UE oznacza `vat_ue` jako `done` i nie zamyka okresu.
  - JPK_V7M za zamknięty M jest odrzucany. Po ponownym otwarciu (powód, step-up) złożenie jest korektą: `purpose` 2, kolejny `seq`, `supersedes_id`, poprzednie `superseded`. Ponowne otwarcie zdejmuje blokadę także przy przyjętym JPK_V7M.
  - Zamknięcie bez korekty ustawia nowe `closed_at`, więc zadanie „korekta wymagana” znika; powód jest w `period.reclosed`.
  - `vat_ue` bez sprzedaży `eu_b2b`: `not_applicable` po zapisie `period.checklist` za M. Od pierwszego miesiąca z `checklist` późniejsze miesiące czekają na swoje bramki; wcześniejsze zostają przy regule kursora Subject1 z F1.
- **Skutki:**
  - Złożenia z bramki (F5) użyją tych samych bramek i zamknięcia; `checklist` przy prośbie o zatwierdzenie.
  - Bilans otwarcia (`opening.*`) i lista DI zostają na F5.
- **Pytania:** brak.

### D-50 Kod F3 częściami, przed odbiorem F0b, F1 i F2

- **Status:** przyjęta 2026-10-14 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - F3 startuje po odbiorze F0b, F1 i F2 (D-30). Ich kod jest na `main`, a odbiory czekają na VPS (D-18) i nagrania z hostów testowych.
  - Sesja w chmurze nie łączy się z hostami testowymi KSeF ani z FreshBooks. XSD FA(3) jest w `resources/xsd`.
- **Decyzja:**
  - Plik fazy [phases/f3-ksef-sprzedaz.md](phases/f3-ksef-sprzedaz.md) dzieli F3 na F3.1–F3.5. Kod F3.1–F3.4 powstaje teraz na gałęziach `f3-…`, każda część z osobnym PR.
  - F3.1–F3.4 działają bez sieci: `FakeKsefGateway`, ręcznie pisane nagrania KSeF z danymi fikcyjnymi i fixtures FreshBooks (D-35).
  - F3.5 (api-test) czeka na `KsefGateway` nad biblioteką i dane TE z sesji 1 F1; nagrania ręczne zastępuje wtedy `app:fixtures:record`.
  - `eu_b2b_unidentified` zostaje zablokowany do przeglądu doradcy (Q-29).
- **Skutki:**
  - Odbiór F3 i go-live nadal czekają na odbiory F0b, F1 i F2 oraz przegląd doradcy (D-04).
- **Pytania:** brak.

### D-51 Założenia FA(3) w F3.1

- **Status:** przyjęta 2026-10-14 (decyzja Claude; wszystkie punkty wracają do przeglądu doradcy, Q-29, D-04).
- **Decyzja:**
  - `non_eu_b2b`: P_18 = 1. [modules/ksef.md](modules/ksef.md) zostawiał to doradcy bez wartości domyślnej.
  - `eu_b2b`: Podmiot1 z `PrefiksPodatnika` PL; brak `vat_eu_number` w `company.identity` blokuje budowę (art. 106e ust. 1 pkt 4).
  - `eu_b2b_unidentified` odrzucany przy budowie na każdym celu, z odwołaniem do Q-29 (D-05, D-50).
  - Kurs VAT według art. 31a ust. 1 i 2, bez wyboru z ust. 2a (Q-23).
  - KOR: pozycje parami przed i po (`StanPrzed`) z jednym `NrWierszaFa`; korekta do zera ma pozycję „po” z ilością 0.
  - Limit rozmiaru przed wysyłką: parametr `ksef.invoice_max_bytes`, nie literał w kodzie.
- **Skutki:** złote pliki w `tests/Fixtures/Ksef/golden` utrwalają te założenia; zmiana po przeglądzie to nowe złote pliki i wpis tutaj.
- **Pytania:** brak.

### D-52 Założenia znacznika gotowości i prośby o fakturę w F3.2 (część a)

- **Status:** przyjęta 2026-10-14 (decyzja Claude).
- **Decyzja:**
  - Powody pozostania szkicu ze znacznikiem w `draft` zapisuje `invoice.ready_blockers` (jsonb, lista komunikatów dla panelu). Bez znacznika i w `ready` kolumna jest pusta.
  - Pozycje szkicu przychodzą z FreshBooks do `invoice_line` (usunięcie i wstawienie, tylko w szkicu, trigger `invoice_line_delete_draft_only`): P_8B i P_9A z `qty` i `unit_cost` (domyślnie 1 i wartość netto), jednostka `usł.`, stawka ryczałtu z `ryczalt.service_map`. P_12 ustala prośba ze scenariusza (`np II`, `np I` albo `vat.standard_rate`).
  - Onboarding klienta: identyfikator podatkowy i wiersz `verification` właściwy typowi (biała lista albo dowód przy NIP, VIES albo dowód przy VAT UE, dowód przy NrID).
  - Warunki prośby i decyzji czytają zapisane wyniki `verification`; prośba nie wywołuje VIES ani białej listy. Wiek wyniku białej listy przy `pl_b2b`: `registry.pl_client_max_age` (P30D).
  - Zdjęcie znacznika przed decyzją: zatwierdzenie `superseded` bez `superseded_by_id` (CHECK `approval_superseded_successor` dopuszcza to tylko dla faktur).
  - Przy prośbie faktura dostaje P_1, `tax_scenario`, `buyer_snapshot`, `verification_id`, kurs i sumy z payloadu; `version` się nie zmienia. Decyzja ustawia `invoice_number_locked_at`, powrót do `ready` albo `draft` go czyści.
  - Decyzja wymaga ważnego poświadczenia zapisu (`ksef_token_write` albo `ksef_auth_cert`) dla celu KSeF.
  - Data i okres usługi wpisujesz w panelu (`SalesReadiness::setService`); interfejs panelu i KOR ze szkicu FreshBooks to F3.2 część b.
- **Skutki:** testy kryteriów F3.2 części a; zmiana założeń to nowy wpis tutaj.
- **Pytania:** brak.

### D-53 Założenia Subject1, obejścia i szkicu KOR w F3.2 (część b)

- **Status:** przyjęta 2026-10-14 (decyzja Claude).
- **Decyzja:**
  - API FreshBooks nie wiąże credit note z fakturą. Fakturę korygowaną wskazuje linia `Faktura: <numer>` w `notes` credit note (`FreshBooksCreditNote::REFERENCE`); do potwierdzenia nagraniem przy go-live.
  - Credit note czyta strumień `credit_notes` przy tym samym zadaniu co faktury (`freshbooks.poll_invoices`). Pozycja „po” = „przed” minus uznanie pozycji o tej samej nazwie; przy jednej pozycji faktury całe uznanie trafia na nią. Suma różnic różna od uznania blokuje `ready`.
  - Edycja zmapowanych pól przyjętej faktury bez credit note daje szkic KOR bez numeru (`invoice_number` pusty tylko w szkicu KOR). Szkic czeka w `draft`, a credit note do tej faktury nadaje mu numer i pozycje. Zmiana samego numeru daje alert `freshbooks_number_changed` i zadanie, bez KOR.
  - Szkic KOR nie wymaga znacznika gotowości: credit note jest Twoją decyzją. Prośba `correction_invoice` powstaje przy spełnionych warunkach i włączonym `freshbooks.sales_enabled`.
  - Credit note do faktury nieznanej kokpitowi daje szkic KOR bez pozycji, zablokowany do oznaczenia oryginału (NrKSeFN) w panelu; to oznaczenie wraca w osobnym kroku.
  - Zadania sprzedaży w panelu to `invoice.panel_task` (`freshbooks_bypass`, `outside_cockpit`, `freshbooks_number_changed`). Zamykasz je w panelu.
  - Obejście sprawdza `FreshBooksBypass` po udanym przebiegu Subject1, dla faktur widzianych przed jego startem. Faktura FreshBooks bez wiersza `invoice` (kraj klienta nieustalony) nie jest kandydatem do czasu onboardingu.
  - Zadanie „faktura spoza kokpitu” powstaje, gdy `freshbooks.sales_enabled` jest włączony w dniu P_1 faktury z Subject1.
  - Szkic, którego zatwierdzenie jest już w `dispatching`, `submitted` albo `unknown`, nie ustępuje fakturze z Subject1: import odmawia, a rozstrzyga `SubmissionReconciler` (F3.3).
- **Skutki:** testy kryteriów F3.2 części b; zmiana założeń to nowy wpis tutaj.
- **Pytania:** brak.

### D-54 Założenia wysyłki i rekoncyliacji KSeF w F3.3

- **Status:** przyjęta 2026-10-14 (decyzja Claude).
- **Decyzja:**
  - Odpowiedź 429 (albo zablokowany `rate_counter`) przy otwarciu sesji to stan, w którym nic nie dotarło do urzędu. Writer zwraca wynik `deferred`, a bramka wraca z `dispatching` do `approved` nowym przejściem `dispatch.deferred` (`result_code` `rate_limited`, `dispatching_at` wyczyszczone). `approval.dispatch_waiting` próbuje ponownie po `blocked_until`; to nie jest automatyczne ponowienie zapisu, bo pierwsze wywołanie się nie odbyło.
  - Niezgodność `ksef_session.form_code` z `invoice.schema_version` przy otwarciu sesji: sesja zamknięta bez wysyłki, zatwierdzenie `rejected` z `form_code_mismatch`. Kod 21184 przy wysyłce: sesja zamknięta, jedna nowa sesja w tym samym przebiegu; drugie 21184 → `rejected` z `21184`.
  - Kody statusu faktury: poniżej 200 w toku (`pending`), 200 z numerem KSeF → `accepted`, 440 → duplikat, pozostałe ≥ 400 → `rejected` z kodem. Niezgodny `invoiceHash` przy statusie 200 zostaje `pending` (do rozstrzygnięcia ręcznie).
  - UPO pobierane per faktura i zapisane w `FileStore` jako `upo` z właścicielem `ksef_session`. Sesję zamyka `status()` po wyniku końcowym; `reconcile()` sesji nie zamyka.
  - Rekoncyliacja `unknown`: z referencją faktury → status; z samą referencją sesji → najpierw lista błędnych, potem lista faktur sesji (dopasowanie po sha256 `xml_sent`); bez referencji → lista sesji online z dnia wysyłki (`dispatching_at`, inaczej `decided_at`, od północy Europe/Warsaw, jedna doba) i lista faktur każdej sesji.
  - Dopasowanie z syncu Subject1 (`ksef_token_read`) działa w `Subject1Importer`: faktura sprzedaży w `submitted` albo `unknown` o tym samym rodzaju i P_2 z bajtami równymi `xml_sent` → `accepted` z `subject1_match`; inne bajty → `rejected` z `p2_collision` i zwykły import. Zastępuje ostatni punkt D-53 dla `submitted` i `unknown`; `dispatching` nadal odmawia.
  - 440: writer czyta listę faktur sesji `originalSessionReferenceNumber`, znajduje `originalKsefNumber` i porównuje jego `invoiceHash` z każdym `xml_sent` tej faktury. Zgodny → `accepted` z `result_code` `440`, numerem i UPO oryginału; inny → `rejected` z `p2_collision`, zadanie panelu `p2_collision` i push. `retryAllowed` KSeF zwraca zawsze `true`, bo 440 chroni przed podwójnym przyjęciem.
  - Kształt odpowiedzi Latarni (`/status`: `status` i `messages`; `/messages`: lista) przyjęty z README i do potwierdzenia nagraniem w F3.5. Latarnia nieosiągalna → `latarnia_unreachable`, wysyłka czeka. Ostatni status trzyma pula `cache.scheduler` (klucz `ksef.latarnia_status`) dla panelu, bez nowej tabeli.
  - `FakeKsefGateway` nie szyfruje: `encryptedInvoiceContent` to Base64 jawnych bajtów; szyfrowanie robi biblioteka w F3.5.
  - Faktura `rejected` wraca do poprawy przez FreshBooks: zmiana szkicu daje `draft_updated` i nową prośbę, bez KOR.
- **Skutki:** migracja `Version20261019000100` (`p2_collision` w `invoice.panel_task` i `notification.category`); testy kryteriów F3.3; zmiana założeń to nowy wpis tutaj.
- **Pytania:** brak.

### D-55 Założenia KOD I, PDF, doręczenia i zapisu zwrotnego w F3.4

- **Status:** przyjęta 2026-10-14 (decyzja Claude).
- **Decyzja:**
  - Silnik HTML→PDF to `dompdf/dompdf` ^3.1 (LGPL-2.1) z zależnościami `dompdf/php-font-lib` (LGPL-2.1-or-later) i `dompdf/php-svg-lib` (LGPL-3.0-or-later), bez modyfikacji (D-17). `tools/check-licenses.php` dopuszcza LGPL tylko dla tych trzech pakietów; lista dozwolonych licencji dla reszty bez zmian. Kod QR: `endroid/qr-code` ^5.1 (PNG jako obraz `data:`), linia wymagana przez `ksef-php-client` 1.10.x (D-14). Wersja 5.1.0 zgłasza pod PHP 8.4 ostrzeżenia `Deprecated` (niejawny typ nullable); znikną po przejściu na ≥6.1 razem z biblioteką KSeF 2.x.
  - dompdf działa bez sieci: `isRemoteEnabled` = false, protokoły tylko `data://`, czcionka DejaVu Sans (polskie znaki), katalog czcionek i plików tymczasowych `invoice_pdf.cache_dir` w `var/cache`.
  - KOD I liczy się z bajtów `xml_sent`, których sha256 równa się `invoiceHash` z przyjęcia (także przy 440 ze zgodnym hashem wcześniejszej wysyłki); bez `invoiceHash` z payloadu przyjętego zatwierdzenia; przy fakturze z Subject1 z jej jedynego pliku. Host z `ksef.kod_i_hosts` według `invoice.environment`; brak wpisu albo adres bez HTTPS to odmowa, nie domyślny host.
  - PDF jest jeden, dwujęzyczny (PL i EN w jednym dokumencie), z danych `xml_sent` przez `Fa3Reader`. Adnotacje z XML: `np II` → odwrotne obciążenie i art. 100 ust. 1 pkt 4; `np I` → usługa poza terytorium kraju, przy P_18 = 1 także odwrotne obciążenie; stawka krajowa → brak. Powstaje tylko w stanie `accepted`, zapisany raz w `FileStore` (`pdf`, właściciel `invoice`) i używany ponownie przy kolejnych doręczeniach.
  - „Doręcz klientowi” czyta fakturę, klienta (`GET users/clients/{customerid}`, `email`, osoba z `fname` i `lname`, inaczej `organization`) i link płatności (`GET invoices/invoices/{id}/share_link?share_method=share_link`, pole `share_link.share_link`, tylko `https://`). Oba endpointy przyjęte z dokumentacji FreshBooks bez firmy testowej (D-35), do potwierdzenia na PROD przy pierwszym doręczeniu. Błąd odczytu FreshBooks → `freshbooks_failed` bez wysyłki. Konektory wyłączone → `mail_disabled`. Audyt `invoice.delivered_to_client` tylko po udanej wysyłce, ze szczegółami `ksef_number` i `pdf_file_id`, bez adresu e-mail; nieudana próba zostaje w `connector_call`.
  - Zapis zwrotny dotyczy tylko faktur `sales_invoice` rodzaju VAT; KOR nie zmienia faktury FreshBooks. Wiadomość `FreshBooksWriteBackMessage` wysyła `SalesInvoiceSubject` przy stanie `accepted`, na transport `reads` w transakcji przyjęcia. Ustawione `written_back_at` kończy obsługę bez odczytu (`already_written`); numer KSeF już w `notes` ustawia `written_back_at` bez `PUT` (`already_present`). `create_date` = P_1 z `invoice.issue_date`.
  - `app:ksef:parallel-compare <RRRR-MM>` bierze faktury sprzedaży VAT z numerem KSeF, `freshbooks_id`, P_1 w miesiącu i bez żadnego `approval` (okres równoległy). Scenariusz z `tax_scenario` importu Subject1, nabywca z kontrahenta, daty usługi z wiersza faktury; P_1 i P_2 z XML Subject1. Odmowa mapowania to wiersz `difference` z polem `mapping`. Komenda zwraca zawsze kod 0; rozbieżności są w CSV, podsumowanie na stderr.
  - Testy sprawdzają treść QR dekoderem `khanamiryan/qrcode-detector-decoder` (MIT, tylko `require-dev`) na obrazie PNG wstawionym do PDF, a teksty na HTML szablonu, bo strumienie PDF dompdf są skompresowane.
- **Skutki:** bez migracji; zależności w `composer.json` i `composer.lock`; testy kryteriów F3.4; zmiana założeń to nowy wpis tutaj.
- **Pytania:** brak.

### D-56 Kod F5 częściami, przed odbiorem F0b, F1, F2 i F3

- **Status:** przyjęta 2026-10-14 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - F5 startuje po F3 (D-30). Kod F0b, F1, F2 i F3.1–F3.4 jest na `main`, a odbiory czekają na VPS (D-18), hosty testowe i przegląd doradcy (D-04).
  - Sesja w chmurze nie łączy się z `test-e-dokumenty.mf.gov.pl` ani z `test-bramka.edeklaracje.gov.pl`. W `resources/xsd` jest tylko XSD JPK_EWP(4); JPK_ST(1) czeka według D-37, a JPK_V7M(3), VAT-UE(5), VAT-UEK(5), SIG-2008 i metadane `InitUpload` nie są jeszcze w repo.
  - F2.4 dał generator JPK_EWP(4), `app:jpk:dry-run` i tabelę `filing`; F0b dał bramkę i `OfficialWritesHandler`; F3.3 dał wzór `OfficialWriter` i `StatusReader` (`KsefWriter`).
- **Decyzja:**
  - Plik fazy [phases/f5-jpk.md](phases/f5-jpk.md) dzieli F5 na F5.1–F5.6. F5.1, F5.2, F5.4 i F5.5 powstają teraz na gałęziach `f5-…`, każda część z osobnym PR.
  - Części offline działają na `FakeJpkGateway`, `FakeEDeklaracjeGateway`, ręcznie pisanych nagraniach z danymi fikcyjnymi i fikcyjnej parze kluczy MF celu `test`. Zapisy idą wyłącznie przez bramkę i `official_writes`.
  - F5.3 i F5.6 (live) czekają na sesję z dostępem do hostów testowych JPK i e-Deklaracji; nagrania ręczne zastępuje wtedy `app:fixtures:record`.
  - Brak XSD zatrzymuje tylko część, która go potrzebuje: JPK_ST(1) → F5.2 (D-37), JPK_V7M(3) → F5.4, VAT-UE(5) i VAT-UEK(5) → F5.5, SIG-2008 i `InitUpload` → wiersze `jpk-auth-data` i `jpk-init-upload` F5.1. Schematy dostarczasz Ty albo sesja pobiera je jednorazowo według wyjątku GET z [../.claude/rules/zasady-twarde.md](../.claude/rules/zasady-twarde.md).
  - Rejestr `tests/Acceptance/f5.yaml` ma od startu wiersze przekrojowe `cc-11`, `cc-25` i `cc-26`; wiersze części dochodzą w ich sesjach.
- **Skutki:**
  - Odbiór F5 i go-live nadal czekają na odbiory F0b, F1, F2 i F3. Go-live F5b czeka na złożenie PROD JPK_EWP (bramka 31.03.2027, D-30).
  - Kod F5.4 i F5.5 może powstać przed go-live F5a, bo nie zależy od sieci.
- **Pytania:** Q-12, Q-25, Q-31.

### D-57 Założenia konektora `jpk` i bramki złożeń w F5.1

- **Status:** przyjęta 2026-10-14 (decyzja Claude).
- **Kontekst:** F5.1 powstaje bez sieci (D-56). Specyfikacja JPK 5.6.0 opisuje przepływ i kody, ale nagrań z `test-e-dokumenty.mf.gov.pl` jeszcze nie ma, a oficjalnych XSD `InitUpload` i SIG-2008 nie ma w `resources/xsd`.
- **Decyzja:**
  - Kształt JSON API przyjęty ze specyfikacji do potwierdzenia nagraniami F5.3 (`jpk-te-fixtures`): błąd `Code`, `Message`, `Errors`; `InitUploadSigned` 200 z `ReferenceNumber`, `TimeoutInSec`, `RequestToUploadFileList` (`BlobName`, `FileName`, `Url`, `Method`, `HeaderList` z `Key` i `Value`); `FinishUpload` z `ReferenceNumber` i `AzureBlobNameList`; `Status` z `Code`, `Description`, `Details`, `Upo`. Numer oryginału przy 170 i 407 to pierwszy 32-znakowy token szesnastkowy w opisie, inny niż numer bieżący.
  - Każde wywołanie idzie przez `ConnectorHttpClient` (`HostAllowlist`, `CONNECTORS_ENABLED`, `rate_counter`, `connector_call`), bez ponowień. Grupy `jpk.upload`, `jpk.finish_upload` i `jpk.status` liczą wywołania bez limitu dobowego; limit dobowy ma tylko `jpk.init_upload` (`connector.daily_limits`). Wyczerpany licznik albo `blocked_until` sprawdza `waitingReason` (`rate_limited`) odczytem `RateCounter::exhausted`; zatwierdzenie zostaje `approved` i `approval.dispatch_waiting` próbuje ponownie. Limit trafiony dopiero przy `InitUploadSigned` daje `deferred` jak w D-54.
  - Odmowa przed potwierdzonym `FinishUpload` kończy `rejected`, bo bez `FinishUpload` sesja przepada i nic nie zostaje złożone: kontrole lokalne (metadane, `AuthData`, certyfikat), kody `InitUploadSigned` poza 170, adres magazynu spoza celu (`storage_host_not_allowed`, bez żadnego Put Blob), liczba adresów niezgodna z częściami, Put Blob bez 201 (`put_blob_failed`). Timeout albo błąd sieci przy `FinishUpload` → `unknown` (`finish_upload_timeout`); rozstrzyga tylko odczyt `Status`.
  - Kody `Status`: 100, 101, 120 w toku; 200 z UPO → `accepted`, UPO jako `upo` z właścicielem `filing`; 407 i 170 → UPO oryginału z `Status/{oryginał}` i `accepted`; 411 oraz 300 po potwierdzonym `FinishUpload` → `unknown` (nowy wynik `unknown` z `StatusReader::status`, `SubmissionReconciler::poll` przyjmuje go od razu), chyba że istnieje przyjęte własne złożenie z tym samym sha256 `xml_sent` (bramka albo `manual`); 300 bez potwierdzonego `FinishUpload` i pozostałe kody → `rejected` z kodem w `filing.status_code` i push `connector_failure` przy każdym odrzuceniu `jpk`.
  - Ręczne ponowienie (`retryAllowed`): bez numeru referencyjnego zawsze; przed wysłaniem `FinishUpload` po 300 albo po `TimeoutInSec`; po wysłaniu `FinishUpload` (także bez odpowiedzi) tylko po 411.
  - Bez oficjalnych XSD `InitUpload` i SIG-2008 ścieżka wysyłki istnieje, ale `JpkInitUpload` i `AuthDataDocument` odmawiają z `jpk_xsd_missing` i jasnym komunikatem, a `waitingReason` zwraca `xsd_missing`. Testy przepływu wysyłki używają fikcyjnych zaślepek `tests/Fixtures/Jpk/xsd-stub` (dopuszczają dowolną treść, oznaczone jako nie-MF); nie zastępują schematów MF. Wiersze `jpk-init-upload` i `jpk-auth-data` zostają wstrzymane, ich testy są pomijane do czasu dostarczenia schematów.
  - Konflikt do rozstrzygnięcia przy `jpk-auth-data`: generator JPK_EWP(4) z F2.4 wpisuje `etd:DataUrodzenia` w `Podmiot1`, bo wymaga tego XSD JPK_EWP(4) (`TIdentyfikatorOsobyFizycznej2`), a kryterium żąda zera wystąpień daty urodzenia w `xml_sent`. Rozstrzygnięcie: kryterium zmienia brzmienie; data urodzenia poza `AuthData` występuje tylko w `Podmiot1`, gdzie wymaga jej schemat dokumentu, a kwota N−2 nigdzie poza `AuthData`.
  - `cancelled` z `hash_mismatch` przestawia złożenie na `draft` według pliku-domu [data-model.md](data-model.md) („Powiązanie z bramką zatwierdzeń”) i [approval-gate.md](approval-gate.md); brzmienie wiersza `jpk-send-hash-mismatch` w pliku fazy poprawione z `ready`.
  - `FilingSubject` zastępuje atrapę `dummy` dla `subject_type` = `filing`; w dev i test identyfikator bez wiersza `filing` obsługuje nadal `dummy.subject.filing` (testy bramki F0b), przy `APP_ENV=prod` atrapy nie ma (D-32). `ApprovalSubject::connector()` dostaje `subjectId`, bo konektor złożenia zależy od jego typu (JPK_EWP → `jpk`).
  - `filing.version` nie rośnie przy zmianie ewidencji; zmiany po prośbie łapią bramki i uzgodnienie K_8 przy kolejnej prośbie. Prośba odmawia przed zapisem `xml_sent`, gdy brak 12 okresów z `ledger_closed_at`, jest sprzedaż w walucie bez kursu PIT albo faktura sprzedaży roku nie jest w stanie końcowym.
  - Certyfikat klucza publicznego MF leży poza repo (`jpk.mf_certificates`, `var/jpk/mf-<cel>.crt`); brak, wygaśnięcie albo zły klucz wstrzymuje wysyłkę (`mf_key_missing`, `mf_key_expired`, `mf_key_invalid`). Klucz RSA krótszy niż 2048 bitów jest odrzucany. W testach para RSA-2048 powstaje w czasie testu (`openssl_pkey_new`), klucz prywatny zostaje w pamięci.
  - Części ZIP mają pełne bloki AES i są o jeden blok krótsze od `jpk.part_max_bytes`, więc limit trzyma się przed i po dopełnieniu PKCS#7. Każda część jest szyfrowana osobno tym samym kluczem i IV.
  - TLS: `crypto_method` TLS 1.2 jako minimum i cztery zestawy TLS 1.2 z listy sześciu w `jpk.tls_ciphers`; dwa zestawy TLS 1.3 to domyślne OpenSSL, bo opcja `ciphers` ich nie ustawia. Wynegocjowany zestaw sprawdza `jpk-te-tls` w F5.3.
- **Skutki:** bez migracji; testy kryteriów F5.1 w `tests/Acceptance/f5.yaml` bez `jpk-init-upload` i `jpk-auth-data`; nazwy w [naming.md](naming.md), „Implementacja F5.1”; zmiana założeń to nowy wpis tutaj.
- **Pytania:** brak.

### D-58 Kod F6 częściami, przed odbiorem F0b–F5

- **Status:** przyjęta 2026-10-14 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - F6 startuje po F5 (D-30). Kod F0b, F1, F2, F3.1–F3.4 i F5.1 jest na `main`, a odbiory czekają na VPS (D-18), hosty testowe, XSD i przegląd doradcy (D-04).
  - Claude API nie ma środowiska testowego; testy idą na nagranych odpowiedziach, a `@group live` kosztuje ([testing.md](testing.md), „Polityka per konektor”). Klucza workspace testowego jeszcze nie ma.
  - Tabel `chat_session`, `chat_message` i `draft` ani roli `chat_ro` nie ma w schemacie. Bramka F0b obsługuje `subject_type` = `draft` atrapą `dummy.subject.draft` (D-41).
- **Decyzja:**
  - Plik fazy [phases/f6-czat.md](phases/f6-czat.md) dzieli F6 na F6.1–F6.5. F6.1–F6.4 powstają teraz na gałęziach `f6-…`, każda część z osobnym PR.
  - Części offline działają na `MockHttpClient` z ręcznie pisanymi odpowiedziami Messages API w `tests/Fixtures/Claude`, wyłącznie z danymi fikcyjnymi, przez ten sam dekorator `HostAllowlist` co w produkcji. Kryteria sprawdzają warstwę narzędzi, schematy i stan bazy, nie treść odpowiedzi modelu.
  - F6.5 (live) czeka na klucz osobnego workspace testowego z niskim limitem wydatków; działa na danych fikcyjnych z budżetem przebiegu `chat.live_run_budget_usd` (propozycja $0.25). Nagrania `app:fixtures:record claude` robisz Ty albo sesja ręcznie, poza CI; inny kształt odpowiedzi niż w nagraniach ręcznych to nowy rekord.
  - Model: walidacja konfiguracji przyjmuje tylko `chat.model` = `claude-haiku-5-5`; `chat.effort` jest wymagany, bez wartości domyślnej (D-10). Zmiana modelu to nowy rekord po teście.
  - Narzędzia i wartości `query` wchodzą do listy z faz w konfiguracji `chat.phases_live`: `get_obligations` od F0b; `query_invoices`, `get_fx_rate`, `verify_counterparty`, `render_chart` i `costs_by_month` od F1; `revenue_by_month` od F2; `sales_by_currency`, `invoice_list` i `top_counterparties` od F3. W dev i testach lista obejmuje wszystkie fazy na danych fikcyjnych; na `kokpit-prod` tylko fazy po go-live.
  - Rola `chat_ro` powstaje w `docker/db/init-db.sh` jak `kokpit_app`, z `default_transaction_read_only = on` i `statement_timeout` 2 s na roli. Widoki `chat_*` należą do `kokpit_owner` bez `security_invoker`, więc `chat_ro` ma tylko `SELECT` na widokach i zero praw do tabel.
  - `chat_message` jest tylko dopisywane: trigger odrzuca `UPDATE`; `retention.purge` usuwa całą rozmowę po 12 miesiącach od `closed_at`, a `draft.chat_session_id` przechodzi na NULL.
  - Koszt liczy cennik `chat.pricing` z konfiguracji (`brick/math`, `NUMERIC(12,6)`), bez literałów w kodzie. `cache_control` na stałym prefiksie (prompt systemowy, narzędzia, snapshot prawa) od pierwszej wersji, jak w „Szybkich wygranych” [roadmap.md](roadmap.md).
  - `DraftSubject` zastępuje atrapę dla `subject_type` = `draft`; poza `APP_ENV=prod` atrapa zostaje dla identyfikatorów bez wiersza `draft` (D-32). Szkic tylko do kopiowania (Q-35), `approval.connector` = NULL.
  - Fakty F6 z [facts.md](facts.md) sprawdza sesja F6.1 jednorazowym odczytem dokumentacji Anthropic; F6.5 potwierdza je przebiegiem live.
  - Rejestr `tests/Acceptance/f6.yaml` ma od startu wiersze przekrojowe `cc-11`, `cc-25` i `cc-26`; wiersze części oraz `cc-28`, `cc-29` i `cc-30` dochodzą w ich sesjach.
- **Skutki:**
  - Odbiór F6 i go-live czekają na odbiory F0b–F5, udany przebieg `live` F6.5 oraz wiersze `[manual]` `chat-dpa` i `chat-spend-limit`.
  - D-22 zostaje „proponowana”: Claude API na DPA z SCC (Q-33) i szkice tylko do kopiowania (Q-35) to założenia domyślne.
  - Nowe nazwy w [naming.md](naming.md), „Plan F6 (D-58)”; zmiana założeń to nowy wpis tutaj.
- **Pytania:** Q-33, Q-35.

### D-59 Założenia danych czatu i roli `chat_ro` w F6.1

- **Status:** przyjęta 2026-10-14 (decyzja Claude).
- **Kontekst:** F6.1 powstaje bez sieci (D-58). Widoki `chat_*` mają filtrować po `environment` i należeć do `kokpit_owner` bez `security_invoker`, ale widok nie zna środowiska wdrożenia. Rola `chat_ro` wymaga superużytkownika (`CREATE ROLE`, `ALTER ROLE … SET`), a migracje działają jako `kokpit_owner`. PROD ma bazę sprzed F6, a rolę czatu dostaje dopiero przy go-live.
- **Decyzja:**
  - Widoki filtrują `environment = current_setting('kokpit.environment', true)`. `ChatQueryRunner` ustawia je przez `set_config(…, true)` w każdej transakcji zapytania na wartość `ConnectorConfiguration::environment()` (`prod` przy `APP_ENV=prod`, inaczej `test`). Bez ustawienia widoki nie zwracają wierszy. `fx_rate` nie ma kolumny `environment`, więc `chat_fx_rate` jej nie filtruje.
  - Zakres widoków: `chat_counterparty` tylko `id`, kraj i typ (bez nazwy, NIP, numerów VAT, adresu i rachunków; nazwę model dostaje jako pseudonim w F6.2); `chat_invoice` bez `buyer_snapshot` i identyfikatorów FreshBooks; `chat_revenue` bez `counterparty_tax_id` i `remarks`; `chat_obligation` bez `note`, `payment_title`, `credential_state_id` i `done_by`; `chat_verification` bez `subject`, `bank_account`, `request_identifier` i samosprawdzeń (`counterparty_id` NULL); `chat_fx_rate` bez `connector_call_id`. `kokpit_app` ma na widokach tylko `SELECT` (domyślne uprawnienia właściciela dawały DML).
  - Nazwane zapytania: sprzedaż w sumach i `top_counterparties` to faktury `sale` w stanach `accepted`, `corrected` i `offline_issued` według `issue_date`; `top_counterparties` sortuje po sumie `net_pln`; koszty to `cost` z `cost_review` = `business` według `issue_date`, grupowane po miesiącu i walucie; `revenue_by_month` sumuje `amount_pln` według `revenue_date`, a `currency` filtruje po walucie oryginału (`PLN` dla wpisów bez oryginału); `invoice_list` obejmuje obie strony. Parametr `currency` to wyliczenie `chat.currencies`. `get_fx_rate` stosuje gałąź cache reguły `FxRateProvider::getRate`: tylko zapisany kurs NBP z dnia roboczego przed datą (`PolishCalendar::previousWorkingDay`), data najwyżej jutro, bez NBP; brak → „brak zapisanego kursu”. `verify_counterparty` przyjmuje UUID po tłumaczeniu pseudonimu (F6.2) i zwraca najnowszy snapshot per źródło z wiekiem w dniach z Symfony Clock.
  - Limit wierszy: zapytanie pobiera `chat.row_limit` + 1 wierszy; nadmiar daje `truncated: true` i `row_limit` w wyniku.
  - Rola `chat_ro` powstaje w `docker/db/init-db.sh` tylko przy pliku hosta `chat-ro-password` (dev generuje go `start-db.sh`) oraz w `scripts/ci-setup.sh` dla wolumenów sprzed F6; hasło trafia do sejfu jako `DB_CHAT_RO_PASSWORD` (`scripts/init-test-vault.php`). Migracja nadaje `SELECT` na widokach tylko, gdy rola istnieje, więc wdrożenie PROD przed go-live czatu przechodzi. Go-live czatu na PROD: plik hosta, rola jak w `init-db.sh`, sekret w sejfie PROD i `GRANT SELECT` na sześciu widokach rolą `kokpit_owner` ([operations.md](operations.md)).
  - `chat_message`: `kokpit_app` ma tylko `SELECT` i `INSERT`; trigger `chat_message_append_only` odrzuca `UPDATE` i `TRUNCATE`. Wiadomości usuwa wyłącznie kaskada klucza obcego przy usuwaniu rozmowy. `draft`: bez `DELETE` dla `kokpit_app` do czasu retencji szkiców.
  - Retencja: `ChatRetention` w `retention.purge` usuwa rozmowy z `closed_at` starszym niż `chat.retention_months` (12) od chwili z Symfony Clock, liczone w UTC; bez wpisu `audit_log`, bo lista akcji nie ma obszaru czatu.
  - `EvidencePack` zachowuje treść `cmd/<klucz>.txt` zapisaną przez test kryterium i dopisuje po niej wyjście komendy; wcześniej wyjście ją nadpisywało.
- **Skutki:** migracja `Version20261021000100`; wiersze F6.1 i `cc-28` w `tests/Acceptance/f6.yaml`; nazwy w [naming.md](naming.md), „Implementacja F6.1 (D-59)”; zmiana założeń to nowy wpis tutaj.
- **Pytania:** brak.

### D-60 Założenia konektora `claude` i pętli narzędzi w F6.2

- **Status:** przyjęta 2026-10-14 (decyzja Claude).
- **Kontekst:** F6.2 powstaje bez sieci (D-58), na ręcznie pisanych, fikcyjnych odpowiedziach Messages API. Plan F6 zakłada SDK z wstrzykniętym klientem PSR-18 i bez ponowień SDK; nazwy opcji trzeba było potwierdzić w kodzie wybranej wersji. Haiku 5.5 wiąże bloki thinking z niezmienioną historią, a `chat_message.content` to `jsonb`.
- **Decyzja:**
  - Zależność `anthropic-ai/sdk` w dokładnej wersji 0.58.0 (MIT; zależności `php-http/discovery`, `open-telemetry/api` i `open-telemetry/context` Apache-2.0, `standard-webhooks/standard-webhooks` MIT). Plugin Composera `php-http/discovery` wyłączony w `allow-plugins`. Sprawdzone w źródle 0.58.0: `RequestOptions::with(transporter: ClientInterface, streamingTransporter:, maxRetries: int, timeout: float)`; `BaseClient` kończy przy `retryCount >= maxRetries`, więc `maxRetries: 0` daje jedno żądanie (test: 500, 529, 429 i timeout → jedno żądanie).
  - `ClaudeClientFactory` daje SDK `Psr18Client` nad `ConnectorHttpClient` z kontekstem `claude`/`messages.create` i grupą `claude.messages`: `HostAllowlist`, `CONNECTORS_ENABLED`, `rate_counter`, `blocked_until` z `Retry-After` i jeden wiersz `connector_call` z hashem i rozmiarem, bez treści. Limit czasu `chat.timeout_s` egzekwuje transport (`timeout` i `max_duration`); opcja SDK `timeout` jest tylko informacyjna. Klucz `CLAUDE_<ENV>_API_KEY` tylko z `SecretStore`; puste `authToken` i `webhookKey`, jawny `baseUrl` z `claude.base_url` i `openTelemetry: false`, więc SDK nie czyta zmiennych `ANTHROPIC_*` dla klucza i adresu. `ConnectorException` niesie wyjątek źródłowy, żeby odróżnić limit czasu od błędu sieci.
  - Żądanie budowane w formacie przewodowym (`ChatRequestProfile::body`) i wysyłane `messages->raw->create` bez przekształceń (sprawdzone: bajty ciała jak tablica). Kolejność: `model`, `max_tokens`, `system` (prompt, potem snapshot prawa z `cache_control`), `thinking` adaptive z `display: omitted`, `output_config.effort`, `tool_choice` `auto`, `tools`, `messages`. Własna pętla `ChatTurn` zamiast `toolRunner` SDK, żeby bloki thinking i historia szły z `chat_message` bez zmian.
  - `chat.model` tylko `claude-haiku-5-5`, `chat.effort` jawny (`low`), bez wartości domyślnej: `ChatConfigurationCheck` w `Kernel::build` zatrzymuje kontener. Rozmowa zapisuje model, effort, `tool_set` (`ts-` i 16 znaków sha256 listy narzędzi), `system_prompt_sha256` i `law_snapshot_from`; zmiana któregoś otwiera nową rozmowę (`ChatSessions::resolve`), a pytanie w starej rozmowie przy nowej konfiguracji jest odrzucane bez żądania.
  - Historia: `jsonb` nie zachowuje kolejności kluczy, więc `ChatTurn` odtwarza bloki w kolejności pól API (thinking: `type`, `thinking`, `signature`; `tool_use`: `type`, `id`, `name`, `input`; `tool_result`: `type`, `tool_use_id`, `content`, `is_error`). Bloki thinking wracają bajt w bajt. Klucze wewnątrz `input` wracają w kolejności `jsonb` (równość semantyczna JSON); bez 400 przy wiązaniu bloków thinking potwierdza wiersz `claude-live-smoke` w F6.5, a niepowodzenie daje nowy rekord z kolumną surowych bajtów. Kolejne wiadomości tej samej roli łączą się w jedną turę żądania.
  - Wyniki narzędzi czekają w pamięci i trafiają do `chat_message` razem z udanym następnym żądaniem. Błąd API, limit czasu, 429, budżet albo rozmiar w pętli zostawiają `tool_use` otwarte; następne pytanie zapisuje dla każdego `tool_result` z `is_error` (`interrupted`) przed treścią pytania. Po `chat.max_iterations` (8) żądaniach tura kończy się kodem `iteration_limit`.
  - Narzędzia z `chat.phases_live` (F0b: `get_obligations`; F1: `query_invoices` z `costs_by_month`, `get_fx_rate`, `verify_counterparty`, `render_chart`; F2: `revenue_by_month`; F3: `sales_by_currency`, `invoice_list`, `top_counterparties`). `draft_document` jest na liście od F6.2 (stały `tool_set` przy F6.4), ale do F6.4 zwraca `is_error` `draft_not_requested`. Przy `APP_ENV=prod` `chat.phases_live` jest pusty do go-live F6.4. Wejście narzędzia walidowane przed SQL (typy, wyliczenia, wzorzec, daty, pola spoza schematu); błąd to `tool_result` z `is_error`, a komunikat bazy nie trafia do modelu.
  - Kontekst modelu: pytanie przechodzi przez `ChatRedaction` (nazwy i NIP kontrahentów środowiska → pseudonimy `K-NN`, potem `Redactor`), wynik narzędzia przez `Redactor`; `counterparty_id` w wynikach zamienia się na pseudonim, a `id` wierszy znika. `chat_invoice.invoice_number` kosztów to tekst dostawcy, więc maskowanie wyniku narzędzia jest obowiązkowe (test `chat-redaction`).
  - Snapshot prawa: ustawienie `setting` `chat.law_snapshot` (`items` z `name`, `value`, opcjonalnie `legal_source`; `note`), wybrane na starcie rozmowy jako wiersz z największym `valid_from` ≤ dziś; rozmowa czyta dokładnie wiersz z `law_snapshot_from`. Brak wiersza blokuje start rozmowy; wartości wpisujesz w panelu ustawień.
  - Budżet: koszt z `usage` i `chat.pricing` w brick/math, 6 miejsc, `HALF_UP`; prompt (wejście z cache) powyżej `threshold_tokens` w stawkach `long`. Domyślnie `chat.budget_daily_usd` `1.00`, `chat.budget_monthly_usd` `10.00`, `chat.budget_alert_ratio` `0.80`. Push `budget_alert` z `dedupe_key` `chat:budget:<daily|monthly>:<okno>:<alert|exceeded>` raz na okno; przy 100 % odmowa przed API. Rozmiar: `ChatContextGuard` liczy prompt jako wejście z cache; w pętli szacunek to prompt, wyjście i 1 token na 3 bajty wyników narzędzi.
  - Live: `LiveGuard` odmawia testsuite `live` bez `CHAT_LIVE_RUN_BUDGET_USD` (wartość `chat.live_run_budget_usd`, dodatnia, do 6 miejsc). Job `live` i `scripts/ci-evidence.sh` przekazują `0.25`. `LiveRunBudget` sumuje koszt przebiegu; `ClaudeLiveBudget::guard` pomija kolejne testy `claude` bez żądań.
- **Skutki:** zależność w `composer.json`; konektor w `src/Connector/Claude`, pętla w `src/Chat`; parametry w `config/services_base.yaml` i `config/services_connectors.yaml`; fikcyjny `CLAUDE_TEST_API_KEY` w `scripts/init-test-vault.php`; wiersze F6.2 i `cc-30` w `tests/Acceptance/f6.yaml`; nazwy w [naming.md](naming.md), „Implementacja F6.2 (D-60)”; zmiana założeń to nowy wpis tutaj.
- **Pytania:** brak.

### D-61 Założenia panelu czatu w F6.3

- **Status:** przyjęta 2026-10-14 (decyzja Claude).
- **Kontekst:** F6.3 powstaje bez sieci (D-58) na pętli `ChatTurn` z F6.2 (D-60). „Architektura” w [modules/czat.md](modules/czat.md) wymaga idempotencji po `chat_message.seq`, ramki Turbo z całą odpowiedzią i komunikatu dla każdego zakończenia. Panel nie ma jeszcze JavaScriptu (bez importmap, Turbo i Stimulus w przeglądarce). Sesja nie mogła zainstalować `symfony/ux-chartjs`: `composer install` przez proxy pada na 403 przy archiwum `phpstan/phpstan` (paczka bez źródła git), a ręczne dołożenie paczki do `vendor` odrzuciła polityka uprawnień sesji.
- **Decyzja:**
  - `/chat` (`ChatController`, `ChatPanel`) za passkeyem: lista rozmów (`chat.session_list_limit`, 50), „Nowa rozmowa”, „Zamknij rozmowę” (`closed_at`), stała etykieta „asystent AI” w ramce od pustej rozmowy. Każdy formularz zmieniający stan ma token CSRF `chat`; brak tokenu → 403, brak sesji → przekierowanie do `/login`, bez wywołania API. Tytuł rozmowy to pierwsze 80 znaków pierwszego pytania po maskowaniu.
  - Idempotencja: formularz niesie `seq` następnej wiadomości. Pytanie z tekstem zapisane od tej `seq` oznacza wcześniejsze wysłanie: ramka z zapisanej historii, zero żądań, także po błędzie API (komunikat `answer_missing`; ponawiasz nowym pytaniem). `seq` różna od następnej → `seq_stale` bez wywołania API. Blokada Symfony Lock `chat.turn.<id>` (PostgreSQL, bez TTL) szereguje równoległe wysłania tej samej rozmowy: drugie czeka i dostaje zapisaną odpowiedź.
  - Ramka `chat-turn`: żądanie z nagłówkiem `Turbo-Frame: chat-turn` dostaje samą ramkę, zwykłe wysłanie formularza całą stronę z tą samą ramką; zawsze HTTP 200. Ramka niesie całą historię rozmowy (tekst wszystkich bloków `text` tury), formularz z następną `seq` i przełącznikiem „Szkic”, wskaźnik pracy `.chat-working` widoczny przy `turbo-frame[busy]`. Pseudonimy `K-NN` panel pokazuje jako nazwy kontrahentów z `pseudonym_map`.
  - Komunikaty: zakończenie zapisane w historii (`refusal`, `max_tokens`, przerwana pętla, brak odpowiedzi) stoi przy turze; błąd bieżącego żądania (limit czasu, błąd API, 429 z godziną końca blokady w Europe/Warsaw, budżet, rozmiar, limit iteracji) zastępuje go przy pytaniu, a bez zapisanego pytania stoi w `#chat-notice`. Teksty z `ClaudeError` i nowego wyliczenia `ChatNotice` (`seq_stale`, `answer_missing`, `turn_incomplete`, `config_changed`, `session_closed`, `empty_question`). Propozycja nowej rozmowy przy `chat.context_warn_tokens`, odmowie rozmiaru, zmianie konfiguracji i zamkniętej rozmowie.
  - Wykres: specyfikację `ChartSpec` panel bierze wyłącznie z bloku `tool_result` bez `is_error` zapisanego przez `ChatToolExecutor` (tekst modelu i pytania nie tworzą takiego bloku) i waliduje ją ponownie. Liczby pobiera tym samym nazwanym zapytaniem z tymi samymi parametrami przez `ChatQueryRunner` (połączenie `chat`). Osie: `revenue_by_month` miesiąc → `amount_pln`; `costs_by_month` miesiąc i waluta → `net_pln`; `sales_by_currency` waluta → `net_pln`; `invoice_list` numer → `net_total`; `top_counterparties` nazwa kontrahenta → `net_pln`. Wartości to napisy dziesiętne z `BigDecimal`, bez `float`.
  - Rysowanie: `canvas` z `data-controller="symfony--ux-chartjs--chart"` i `data-symfony--ux-chartjs--chart-view-value` w formacie, który daje `render_chart()` z `symfony/ux-chartjs`, oraz tabela z tymi samymi liczbami jako widok bez JavaScriptu. Instalacja `symfony/ux-chartjs` (2.x jak `symfony/ux-turbo`, MIT, bez wyjątku licencji), rejestracja pakietu i importmap (`@hotwired/turbo`, `@hotwired/stimulus`, `chart.js`) to osobny krok na środowisku z dostępem do Packagist i GitHub; wtedy znaczniki zastępuje `render_chart()` bez zmiany danych.
  - Limit czasu: żądanie panelu trwa najwyżej `chat.max_iterations` × `chat.timeout_s` (720 s) plus zapas, więc pula PHP-FPM ma `request_terminate_timeout = 780s`, a proxy `fastcgi_read_timeout 780s`.
  - Dowody: paczka przyjmuje podkatalog `screens/<klucz>.html` (ramka z testu WebTestCase bez wartości tokenu CSRF, po `Redactor`).
- **Skutki:** `src/Controller/ChatController.php`, `src/Chat/ChatPanel.php` i klasy widoku; szablony `templates/panel/chat.html.twig` i `_chat_frame.html.twig`; parametr `chat.session_list_limit`; `docker/php/zz-kokpit-pool.conf` i `docker/proxy/*.conf`; wiersze F6.3 w `tests/Acceptance/f6.yaml`; nazwy w [naming.md](naming.md), „Implementacja F6.3 (D-61)”; zmiana założeń to nowy wpis tutaj.
- **Pytania:** brak; instalację `symfony/ux-chartjs` i JavaScriptu panelu domyka D-73.

### D-62 Założenia szkiców `draft_document` w F6.4

- **Status:** przyjęta 2026-10-14 (decyzja Claude).
- **Kontekst:** F6.4 powstaje bez sieci (D-58) na pętli `ChatTurn` z F6.2 (D-60). „Szkice” w [modules/czat.md](modules/czat.md) wymagają szkicu tylko na Twoją prośbę, odbiorcy z bazy, oznaczenia źródeł nieufnych i potwierdzenia każdej wartości niezgodnej z bazą; szkic idzie przez bramkę jako `draft` bez konektora (Q-35). Tabela `draft` z F6.1 (D-59) ma już `provenance`, `flagged_values` i `version`, a pakiet `symfony/workflow` jest w `vendor`, ale bez konfiguracji. Klient nie ma w `counterparty` adresu e-mail.
- **Decyzja:**
  - Prośba: `DraftDocumentTool` czyta `chat_message.draft_requested` ostatniej wiadomości `user` rozmowy, która ma blok `text` (Twoje pytanie bieżącej tury); wiadomości z samymi `tool_result` pomija, flaga z wcześniejszej tury nie przechodzi dalej. Bez flagi, bez rozmowy albo bez narzędzia w executorze: `tool_result` z `is_error` `draft_not_requested` przed jakimkolwiek SQL.
  - Odbiorca: schemat narzędzia przyjmuje jeden napis `K-NN` (`invalid_pattern` dla adresu e-mail, `invalid_type` dla listy). Executor wymaga pseudonimu z `pseudonym_map` (`unknown_pseudonym`) i kontrahenta w `counterparty` środowiska wdrożenia (`invalid_counterparty`). Pseudonimy w temacie i treści zamienia na nazwę kontrahenta z bazy; pseudonim spoza mapy w treści odrzuca szkic. Rodzaj tylko `email`; payload niesie nazwę, NIP i kraj odbiorcy, bez adresu e-mail (brak kolumny), bo i tak kopiujesz.
  - `provenance`: każda wiadomość `user` rozmowy z tekstem (`source` = `chat_message`, `seq`, sha256 tekstu po maskowaniu, pierwsze 160 znaków) i każdy pseudonim z `source_refs` (`source` = `source_ref`). Panel pokazuje listę przy szkicu.
  - `flagged_values` (`DraftValueCheck`): pozycje `type` (`iban`, `nrb`, `nip`, `amount`), `value`, `confirmed_at`; IBAN i NRB spoza `counterparty.bank_accounts` odbiorcy i `company.identity.bank_accounts`; NIP z poprawną sumą kontrolną inny niż NIP odbiorcy i `company.identity.nip`; kwota z dwoma miejscami po przecinku albo kropce różna od każdej kwoty (`net_total`, `vat_total`, `gross_total`, `net_pln`) faktur odbiorcy w tym środowisku, porównanie `BigDecimal`. Potwierdzenie w panelu (`Drafts::confirm`, tylko w `pending`) dopisuje `confirmed_at` i `confirmed_by` bez nowej wersji i bez zmiany payloadu; `DraftSubject::checkDecision` odrzuca decyzję z choć jedną niepotwierdzoną pozycją. Edycja liczy pozycje od nowa.
  - Bramka: `DraftSubject` (usługa `draft.subject`, alias klasy) zastępuje otagowaną atrapę; w dev i test `dummy.subject.draft` bez tagu obsługuje identyfikatory bez wiersza `draft`, przy `APP_ENV=prod` atrapy nie ma. Payload: kanoniczny JSON (`body`, `kind`, `recipient`, `subject`, klucze alfabetycznie, UTF-8 bez ucieczek) jako `outbound_payload`. Prośba ze szkicu czatu i z edycji idzie przez nowe `ApprovalGate::requestByUser` (jak `request`, ale bez push `approval_pending`, aktor to właściciel rozmowy). `approve` bez konektora nie wysyła wiadomości na `official_writes`.
  - „Skopiowano”: `ApprovalGate::markCopied` zwraca stan; po `expires_at` → `expired`, przy bajtach `outbound_payload` innych niż `payload_sha256` → `cancelled` z `hash_mismatch` i push `connector_failure` (alert z tabeli przejść), szkic wraca do `pending`; inaczej `accepted` z wpisem `draft.executed` i szkic `executed` z `executed_at`. Kokpit niczego nie wysyła: brak narzędzia wysyłki, maila i push ze szkicem.
  - Stany: Symfony Workflow `draft` (`state_machine`, magazyn `method` na `DraftState::status`; przejścia `approve`, `reopen`, `execute`, `discard`) według „Powiązania z bramką zatwierdzeń” w [data-model.md](data-model.md). `DraftSubject::onStatus` i `Drafts::discard` (szkic bez żywego zatwierdzenia) zmieniają stan tylko przez workflow; ten sam stan nic nie zmienia, przejście spoza definicji rzuca wyjątek bez zapisu. Odrzucenie przy żywym zatwierdzeniu idzie przez `ApprovalGate::cancel`.
  - Panel: kolejka `/approvals` pokazuje przy szkicu treść z wyróżnionymi wartościami (`mark.draft-flagged`), źródła nieufne, potwierdzenia, edycję, odrzucenie i „Skopiowano”; trasa `panel_draft_action` (`POST /approvals/{id}/draft/{confirm|edit|discard}`, token CSRF `approvals`). Ramka czatu przy pytaniu z flagą linkuje kolejkę.
  - Bez nowej migracji i bez nowej paczki: kolumny z F6.1, `symfony/workflow` 7.4 już zainstalowany.
- **Skutki:** `src/Chat/DraftDocumentTool.php`, `DraftSubject.php`, `DraftState.php`, `Drafts.php`, `DraftValueCheck.php`; zmiany w `ChatToolExecutor`, `ChatTurn`, `ApprovalGate` i `ApprovalController`; `framework.workflows.draft` w `config/packages/framework.yaml`; `draft.subject` w `config/services_base.yaml` i `config/services_connectors.yaml`; szablony `approvals.html.twig` i `_chat_frame.html.twig`; fixtures `tests/Fixtures/Claude/messages-draft-*.json`; wiersze F6.4 i `cc-29` w `tests/Acceptance/f6.yaml`; nazwy w [naming.md](naming.md), „Implementacja F6.4 (D-62)”; zmiana założeń to nowy wpis tutaj.
- **Pytania:** brak.

### D-63 Schematy XSD JPK i e-Deklaracji w repo

- **Status:** przyjęta 2026-10-15 (decyzja Claude na plikach dostarczonych przez Ciebie).
- **Kontekst:** D-56 i D-57 wstrzymały wiersze `jpk-init-upload` i `jpk-auth-data` F5.1 oraz części F5.2, F5.4 i F5.5 do czasu oficjalnych XSD w `resources/xsd`. Pliki dostarczyłeś Ty w jednym archiwum z manifestem (źródło, data pobrania 15.10.2026, sha256); sesja nie pobierała niczego z sieci. Importy schematów wskazują adresy `crd.gov.pl`, a pliki bazowe MF mają te same nazwy w kilku wersjach (`StrukturyDanych` v6, v10, v12; `KodyKrajow` v6, v10, v13; `ElementarneTypyDanych` v6, v10; `KodyUrzedowSkarbowych` v6, v8).
- **Decyzja:**
  - Zestaw w `resources/xsd`, bajt w bajt jak u źródła, każda suma sprawdzona przed dodaniem: `Schemat_JPK_V7M(3)_v1-0E.xsd` (crd.gov.pl, wzór 14090), `Schemat_JPK_ST(1)_v1-2.xsd`, `VAT-UE(5)_v2-0E.xsd` (wzór 10293), `VAT-UEK(5)_v2-1E.xsd` (wzór 10316), `SIG-2008_v2-0.xsd`, `initupload.xsd` (podatki.gov.pl `/media/fmdjp0ri`, wersja 01.02.01.20160617) oraz pliki bazowe `StrukturyDanych_v6-0E`, `StrukturyDanych_v12-0E`, `ElementarneTypyDanych_v6-0E`, `KodyKrajow_v6-0E`, `KodyKrajow_v13-0E`, `KodyUrzedowSkarbowych_v6-0E`, `KodyKrajowUE_v3-0E`. Cztery pliki bazowe z archiwum (`StrukturyDanych_v10-0E`, `ElementarneTypyDanych_v10-0E`, `KodyKrajow_v10-0E`, `KodyUrzedowSkarbowych_v8-0E`) mają sumy równe plikom z F2.1, więc zostaje jedna kopia z dotychczasowym wpisem manifestu.
  - Rozwiązywanie importów: mechanizm z [../.claude/rules/integrations.md](../.claude/rules/integrations.md), „Walidacja offline”, bez przepisywania `schemaLocation`. Loader `XsdValidator` mapuje pełny adres z pola `locations` wpisu manifestu na plik lokalny; o pliku decyduje cały adres, nie nazwa. `XsdCatalog::locations()` odrzuca adres wskazany przez dwa różne pliki. Test `XsdInventoryTest` (job `xsd`) sprawdza sumy, komplet plików w manifeście, kompilację offline każdego schematu głównego (FA(3), JPK_EWP(4), JPK_V7M(3), JPK_ST(1), VAT-UE(5), VAT-UEK(5), SIG-2008, `initupload.xsd`) i to, że każdy `xsd:import` i `xsd:include` trafia w plik z oczekiwaną przestrzenią nazw.
  - `initupload.xsd` nie deklaruje elementu `AuthData`, który specyfikacja 5.6.0 dodaje przy danych autoryzujących; metadane z `AuthData` są wobec niego niezgodne. `JpkInitUpload::check` waliduje tym XSD metadane bez `AuthData`, a `AuthData` sprawdza osobno: najwyżej jeden, ostatnie dziecko `InitUpload`, bez atrybutów i elementów, poprawny Base64. Potwierdza to odpowiedź bramki testowej na `InitUploadSigned` w F5.3 (`jpk-te-fixtures`); kod 140 oznacza nowy rekord D-xx.
  - Link `/media/qord0r0j` na podatki.gov.pl ma błędny opis; kokpit go nie używa, a schemat metadanych pochodzi z `/media/fmdjp0ri`.
  - Konektor `jpk` używa `initupload.xsd` i `SIG-2008_v2-0.xsd` z `resources/xsd`. Fikcyjne zaślepki `tests/Fixtures/Jpk/xsd-stub` znikają; punkt D-57 o zaślepkach i pomijanych testach przestaje obowiązywać. `JpkMetadataSchemaMissingTest` sprawdza `jpk_xsd_missing` na pustym katalogu schematów poza repo.
  - Wiersze `jpk-init-upload` i `jpk-auth-data` wracają do `tests/Acceptance/f5.yaml`; `jpk-auth-data` w brzmieniu z D-57 (data urodzenia poza `AuthData` tylko w `Podmiot1`, kwota N−2 nigdzie).
  - `JpkStBuilder::SCHEMA` wskazuje nadal `Schemat_JPK_ST(1)_v1-0.xsd` i rzuca `XsdMissingException`; przejście na `Schemat_JPK_ST(1)_v1-2.xsd` i generator robi F5.2.
- **Skutki:** pliki i wpisy w `resources/xsd`; `XsdCatalog::locations()`, `JpkInitUpload` (`SCHEMA` = `initupload.xsd`, `check`); testy `XsdInventoryTest`, `JpkInitUploadTest`, `JpkAuthDataTest`, `JpkMetadataSchemaMissingTest`; „Pliki XSD” w [modules/jpk.md](modules/jpk.md); „Wejścia” w [phases/f5-jpk.md](phases/f5-jpk.md); nazwy wycofane w [naming.md](naming.md). F5.2, F5.4 i F5.5 mogą ruszyć offline.
- **Pytania:** brak.

### D-64 Założenia F5.2: JPK_ST(1) z `fixed_asset` przez bramkę

- **Status:** przyjęta 2026-10-15 (decyzja Claude w ramach pełnej odpowiedzialności za projekt; Q-25 dalej otwarte).
- **Kontekst:**
  - D-63 dał `Schemat_JPK_ST(1)_v1-2.xsd` w `resources/xsd`; `JpkStBuilder` wskazywał jeszcze `v1-0` i rzucał `XsdMissingException`.
  - Wersja 1-2 ma `wersjaSchemy` 1-2, `WariantFormularza` 1, `ZnacznikST` w `Podmiot1` i wybór węzła `EWP` albo `PKPIR`. Wiersz `EWP` wymaga F_1, F_2 albo F_3, F_4, F_6, F_7, F_9, F_10 i F_16; F_12–F_14 występują razem, węzeł `KSeF` z F_15 tylko przy nich.
  - Tabela `fixed_asset` nie ma liczby porządkowej ani okresu. Q-25 („plik bez wierszy czy brak pliku”) jest otwarte; obowiązek `jpk_st` czyta flagę na ostatni dzień roku (D-39).
- **Decyzja:**
  - `JpkStBuilder` buduje JPK_ST(1) 1-2: nagłówek jak JPK_EWP(4) z `KodFormularza` `JPK_ST`, `kodSystemowy` `JPK_ST (1)`; `Podmiot1` z `company.identity` i `DataUrodzenia` z `auth_data` przez wspólny `JpkTaxpayer`; `ZnacznikST` = 1 i węzeł `EWP`. Mapowanie F_1–F_16 według „JPK_ST(1): węzeł `EWP`” w [modules/jpk.md](modules/jpk.md); `intangible` wybiera F_3 zamiast F_2, `reclassified` daje F_16 1 albo 2.
  - Plik za rok obejmuje środki z `commissioning_date` nie późniejszą niż koniec okresu i bez wykreślenia przed 1.01. Wykreślenie po końcu okresu nie trafia do pliku. `--until` skraca koniec okresu wierszy (F_4 i F_12); nagłówek zawsze obejmuje cały rok, jak w JPK_EWP(4).
  - F_1 numeruje wiersze pliku od 1 w kolejności `commissioning_date`, `acquisition_date`, `id`; bez nowej kolumny i migracji. `JpkDocument.sum` to suma F_10, `sumsByRate` jest pusta.
  - Brak F_14 przy wykreśleniu daje plik niezgodny z XSD; dry run go odrzuca, prośba odmawia.
  - Prośba o zatwierdzenie (`FilingRequest::jpkSt`) powstaje tylko przy `jpk_st.enabled` = true na 31.12. roku złożenia, jak warunek obowiązku `jpk_st`. Przy false odmowa z komunikatem Q-25 przed zapisem `filing`; `FilingSubject` sprawdza flagę ponownie przy budowie payloadu. Dry run F2.4 czyta flagę na dziś bez zmian.
  - Bramki „przed budową JPK_EWP(4)” (okresy, kursy PIT, sprzedaż) nie dotyczą JPK_ST. Przy włączonej fladze pusty wykaz daje plik bez wierszy zgodny z XSD: włączenie flagi to Twoje rozstrzygnięcie Q-25.
  - Wysyłka idzie tym samym `JpkWriter` i `official_writes` co JPK_EWP: `FormCode` z nagłówka, `FileName` `JPK_ST_<RRRRMMDD>_<sha256[0:12]>.xml`, UPO jako `upo` przy `filing`.
  - Punkt D-63 o `JpkStBuilder::SCHEMA` = `v1-0` przestaje obowiązywać.
- **Skutki:** `JpkStBuilder`, `JpkTaxpayer`, `FilingRequest::jpkSt`, `FilingSubject` (JPK_ST); wiersze `jpk-st-build` i `jpk-st-dry-run` w `tests/Acceptance/f2.yaml` (D-37) i `jpk-st-request` w `tests/Acceptance/f5.yaml`; fikcyjny wykaz `tests/Fixtures/Jpk/fixed-assets-2026.json`; nazwy w „Implementacji F5.2” w [naming.md](naming.md); F5.3 może wysłać JPK_ST (`jpk-te-st-upo`).
- **Pytania:** Q-25.

### D-65 Założenia F5.4: JPK_V7M(3) i zamknięcie miesiąca

- **Status:** przyjęta 2026-10-15 (decyzja Claude w ramach pełnej odpowiedzialności za projekt; Q-21, Q-29 i Q-31 dalej otwarte, kokpit idzie na ich założeniach domyślnych).
- **Kontekst:**
  - D-63 dał `Schemat_JPK_V7M(3)_v1-0E.xsd` z importami. F5.4 buduje plik, deklarację, bramki, zamknięcie, korektę, `jpk.replace_di`, formularz „Otwarcie” i kwoty WHT bez sieci (D-56).
  - Tabela znaczników nie mówi, co z fakturą bez numeru i bez `ksef_issue_mode`. Pusty tryb dopuszcza tylko faktura z numerem z importu KSeF.
  - `setting` ma `UNIQUE (key, valid_from)`, a rola aplikacji nie ma `UPDATE` na `setting`. Formularz „Otwarcie” nie może więc nadpisać wiersza z tą samą datą przejścia.
  - Sposób przeliczenia wypłaty WHT na złote czeka na przegląd doradcy (Q-29). Stawki WHT nie miały klucza `setting`.
- **Decyzja:**
  - **Generator.** `JpkV7mBuilder::build(periodStart, purpose, reason)` buduje JPK_V7M(3) 1-0E z `company.identity` (część JPK z wymaganym `email`) i `DataUrodzenia` z `auth_data` przez `JpkTaxpayer`.
    - Sprzedaż: `accepted`, `corrected` i `superseded` z `vat_period_start` = M. KOR bierze scenariusz faktury korygowanej i kwoty różnicy ze znakiem.
    - Koszty: `assigned` i `business` z `vat_period_start` = M. Import usług daje wiersz sprzedaży (K_27/K_28 dla `other`, K_29/K_30 dla `eu_28b`; podatek z `self_assessed_vat_pln`, przy `vat_exempt` 0,00). Wiersz zakupu (K_42/K_43, środek trwały K_40/K_41) powstaje tylko przy `deductible` bez `vat_exempt`.
    - Koszt zagraniczny bez `import_services` i dokument bez `net_pln` albo `vat_pln` blokują budowę.
    - `NrKontrahenta`: NIP dla PL, prefiks i numer VAT UE, kraj i `foreign_tax_id` spoza UE, „brak” bez identyfikatora. `DataSprzedazy` tylko przy dacie obowiązku innej niż wystawienie. `DataWplywu` z `received_on` albo dnia nadania numeru KSeF w Europe/Warsaw.
    - Wiersze w kolejności daty, numeru i `id`. Bez GTU i procedur (Q-31).
  - **Znacznik.** `JpkMarker::of` według tabeli od góry. Pusty `ksef_issue_mode` bez numeru blokuje budowę jak `online` bez numeru: znacznika nie da się ustalić. `TypDokumentu` i `DokumentZakupu` `WEW` z `jpk_doc_type`.
  - **Deklaracja.** P_x to suma K_x zaokrąglona HALF_UP do pełnych złotych (art. 63 Ordynacji). P_37, P_38 i P_48 to sumy pól już zaokrąglonych.
    - P_38 i P_51 zawsze, „0” przy braku. P_48 zawsze; P_39 tylko > 0.
    - Przy P_48 > P_38: P_51 = 0, P_53 = P_62 = różnica.
    - `vat.excess_policy` przyjmuje tylko `carry_forward` (Q-31); brak wiersza oznacza `carry_forward`. Inna polityka wymagałaby pól P_54–P_61, więc budowa odmawia.
    - P_39 = P_62 z `filing.totals` najnowszego przyjętego (nie `superseded`) JPK_V7M za M−1, także `manual`. Bez niego `opening.vat_carry_forward_pln` z `valid_from` = M, w pełnych złotych z `.00`. Bez obu budowa odmawia.
  - **Bramki.** `V7mGates` to cztery bramki `MonthChecklist` oraz:
    - `eu_b2b_unidentified`: dowód ręczny i scenariusz w `ksef.fa3_review`;
    - `opening_balance`: przyjęty JPK_V7M za M−1 albo bilans z `valid_from` = M potwierdzony wpisem `opening_balance.confirmed`.
    - Od F5.4 niespełniona bramka blokuje prośbę o zatwierdzenie z listą bramek; złożenie `manual` tylko ją zapisuje (D-49).
    - Przy udanej prośbie wynik i `di_items` trafiają do `period.checklist`. Odmowa wycofuje transakcję prośby, więc nie zostawia zapisu.
  - **Zamknięcie i korekta.** Przyjęcie JPK_V7M (z bramki albo `manual_filing` z UPO) woła `MonthClose` w transakcji przejścia:
    - poprzednie przyjęte złożenie okresu dostaje `superseded`;
    - okres dostaje `closed` z `closed_by_filing_id`;
    - obowiązek `vat_jpk_v7m` dostaje `done` z `filing_id` i `done_by` = zatwierdzający;
    - `vat_ue` bez sprzedaży `eu_b2b` dostaje `not_applicable`.
    - Pierwsze złożenie odmawia w okresie `closed` albo z przyjętym złożeniem. Korekta (`FilingRequest::jpkV7mCorrection`) wymaga okresu `reopened` i przyjętego złożenia. Dostaje `purpose` 2, `seq` = max + 1, `supersedes_id`, a P_ORDZU w `filing.totals.p_ordzu`. Szkic korekty bez wysyłki jest używany ponownie.
  - **`jpk.replace_di`** (codziennie 09:00, `reads`). Chwila budowy to `created_at` pliku `xml_sent` przyjętego zatwierdzenia najnowszego przyjętego złożenia okresu. Zapasowo bierze ostatni `xml_sent` złożenia, a potem `accepted_at`.
    - Faktura `offline24` albo `maintenance` bez `WEW`, z numerem nadanym później, ustawia `correction_required_at` przez `Periods::correctionRequired`.
    - Wpis `period.correction_required` niesie `cause` = `jpk.replace_di` i `target_id`. Wpis po tej chwili wyklucza ponowne zgłoszenie.
  - **„Otwarcie”.** `OpeningBalance::confirm` zapisuje cztery klucze `opening.*` w jednej transakcji, każdy z plikiem `evidence` (właściciel `setting`), oraz wpis `opening_balance.confirmed` z passkeyem step-up.
    - Po wpisie `SettingStore::set` odrzuca klucze `opening.*`.
    - Poprawka to nowe potwierdzenie z powodem, przed zamknięciem jakiegokolwiek okresu i z inną datą przejścia, bo wiersza z tą samą datą nie da się nadpisać.
    - Kształty kluczy są w [naming.md](naming.md).
  - **WHT.** Klucz `wht.rates` trzyma stawki `standard` i `treaty` per kraj dla `license` i `services_21_2a`.
    - `amount_due` obowiązku `wht_payment` to suma `gross_total` faktur dostawcy zapłaconych w miesiącu. Każdą przelicza średni kurs NBP z ostatniej tabeli przed `paid_on`, tylko z bazy i do 10 dni wstecz, i mnoży przez stawkę na dzień `paid_on`.
    - Stawka z umowy obowiązuje tylko przy `wht_cert_valid_until` ≥ `paid_on`. Wynik jest w pełnych złotych, HALF_UP.
    - Brak stawki albo kursu daje pustą kwotę.
    - `cit_10z` za rok obowiązuje, gdy suma > 0 albo któraś kwota jest pusta (ostrożnie). Wpłata, IFT-2R i CIT-10Z zostają ręczne.
- **Skutki:**
  - Kod: `JpkV7mBuilder`, `JpkV7mDocument`, `JpkMarker`, `V7mGates`, `MonthClose`, `JpkReplaceDi`, `OpeningBalance`, `OpeningController` (`/opening`).
  - Zmiany w istniejącym kodzie:
    - `FilingSubject`: JPK_V7M;
    - `FilingRequest`: `jpkV7m`, `jpkV7mCorrection`;
    - `SettingKeys` i `SettingStore`: `vat.excess_policy`, `wht.rates`, `opening.*` i blokada;
    - `ObligationEngine`: kwota WHT i warunek `cit_10z`;
    - zadanie `jpk.replace_di` w harmonogramie.
  - Testy i dane: wiersze F5.4 w `tests/Acceptance/f5.yaml`; fikcyjny miesiąc `tests/Fixtures/Jpk/v7m-2026-11.json` i złoty plik `tests/Fixtures/Jpk/golden/jpk-v7m-golden.xml`.
  - Nazwy: „Implementacja F5.4” w [naming.md](naming.md).
  - Bez migracji. F5.5 (VAT-UE) i F5.6 (live) mogą ruszyć.
- **Pytania:**
  - Q-21: klasyfikacja WHT i stawki z umów.
  - Q-29: przeliczenie wypłaty WHT na złote; potwierdzenie mapowania `eu_b2b_unidentified` i importu usług.
  - Q-31: polityka nadwyżki i GTU.

### D-66 Założenia F5.5: VAT-UE(5), VAT-UEK(5) i konektor `edeklaracje`

- **Status:** przyjęta 2026-10-15 (decyzja Claude w ramach pełnej odpowiedzialności za projekt; kształt SOAP, adres usługi i przyjęcie `DaneAutoryzujace` przez bramkę potwierdza F5.6 na `test-bramka.edeklaracje.gov.pl`).
- **Kontekst:**
  - D-63 dał `VAT-UE(5)_v2-0E.xsd` i `VAT-UEK(5)_v2-1E.xsd`; D-27 i Q-32 ustaliły kanał `sendUnsignDocument` z danymi autoryzującymi. F5.5 powstaje bez sieci (D-56) na `FakeEDeklaracjeGateway`.
  - Opublikowane XSD VAT-UE(5) i VAT-UEK(5) nie deklarują elementu `DaneAutoryzujace` (korzeń kończy się na `Pouczenie`, bez `xsd:any`). Dokument z danymi autoryzującymi nie przejdzie więc walidacji samym schematem deklaracji.
  - Specyfikacja we-wy 2.4 opisuje operacje i statusy, ale nie daje WSDL w `resources/xsd` ani nagrań; przestrzeń nazw, `SOAPAction` i adres usługi są założeniem.
- **Decyzja:**
  - **Generator.** `VatUeBuilder::build(periodStart)` bierze sprzedaż `accepted`, `corrected` i `superseded` z `vat_period_start` = M i scenariuszem `eu_b2b` (KOR po scenariuszu faktury korygowanej, kwoty różnicy ze znakiem).
    - Grupa per prefiks i numer VAT UE nabywcy (GR → EL); P_Uc to suma niezaokrąglonych `net_pln` grupy, HALF_UP do pełnych złotych; kolejność po kraju i numerze.
    - `eu_b2b_unidentified` poza plikiem (licznik `excluded_unidentified`). KOR bez `correction_type`, faktura bez ważnego VIES na dzień wystawienia (albo dowodu ręcznego), bez `net_pln` albo nabywca bez numeru VAT UE blokują budowę z listą dokumentów.
    - `Podmiot1` to `etd:OsobaFizyczna` z NIP, imieniem, nazwiskiem i datą urodzenia, bez e-maila i telefonu. Brak sprzedaży `eu_b2b` w M: odmowa (obowiązek `vat_ue` dostaje `not_applicable` przy zamknięciu miesiąca, D-65).
  - **Bramka K_12.** `VatUeK12Gate` porównuje sumę niezaokrąglonych `net_pln` z `filing.totals.k12` najnowszego JPK_V7M okresu (najwyższy `seq`, w dowolnym stanie) do grosza. Różnica albo brak JPK_V7M z K_12 blokuje prośbę z kwotą różnicy. Różnica zaokrąglenia P_Uc najwyżej 0,50 zł × liczba nabywców (z definicji zaokrąglenia, więc stała w kodzie, nie parametr).
  - **Trasa KOR.** `FilingRequest::vatUeRoute`: brak złożenia, `draft`, `ready`, `approved`, `rejected` → `vat_ue` (przebudowa, nowe zatwierdzenie zastępuje poprzednie); `submitted`, `unknown` → `wait` (odmowa); `accepted` → `vat_uek`: VAT-UEK z `supersedes_id`, `purpose` 2, `seq` = max + 1, BYŁO z `filing.totals.positions` złożenia zastępowanego i JEST ze stanu miesiąca, tylko dla zmienionych pozycji. Złożenie `manual` bez pozycji: VAT-UEK składasz w e-Deklaracjach.
  - **Przyjęcie.** `MonthClose::vatUeAccepted`: wcześniejsze przyjęte VAT-UE/VAT-UEK okresu dostają `superseded`, otwarty obowiązek `vat_ue` dostaje `done` z `filing_id`. Okres VAT zamyka tylko JPK_V7M.
  - **Konektor.** Własny cienki klient: `EDeklaracjeSoapClient` (podklasa `SoapClient`, bez WSDL, dokument literalny, `trace` = false) z `__doRequest` przez `ConnectorHttpClient`, więc `HostAllowlist`, `CONNECTORS_ENABLED`, `rate_counter` i `connector_call` jak w innych konektorach. Adres per `EDEKLARACJE_TARGET` w `edeklaracje.endpoints`, przestrzeń nazw i `signatureType` = `PIT` w `edeklaracje.soap`. Limit `edeklaracje.send` = 10 dziennie.
  - **Dane autoryzujące.** `xml_sent` to deklaracja bez `DaneAutoryzujace`; `payload_sha256` wiąże te bajty. `EDeklaracjeWriter::submit` (`#[OfficialWrite]`, tylko z `OfficialWritesHandler`) liczy sha256 ponownie, bierze `AuthDataDocument::element` z `auth_data` i wstawia go przed zamykającym `</Deklaracja>` (bez ponownej serializacji). Kontrola w pamięci: bajty `xml_sent` zgodne z XSD deklaracji, element zgodny z SIG-2008, dokładnie jeden i ostatni w korzeniu. `connector_call.request_sha256` to hash koperty z danymi; treść nie trafia nigdzie.
  - **Statusy `requestUPO`.** 200 z UPO → `accepted` i UPO w `FileStore`; 100–102 i 301–306 → dalsze odpytywanie, bez ponownej wysyłki; 300 i 411 → `unknown`, rekoncyliacja tylko odczytem `requestUPO` albo własnym przyjętym złożeniem z tym samym sha256; 410 i 418 → `rejected` z `decision_required` = D-27; pozostałe → `rejected` z kodem i push. `sendUnsignDocument` bez odpowiedzi → `unknown` (`send_no_response`), bez `refId` z kodem 4xx albo 100–102 → `rejected`. Ręczne ponowienie tylko bez `refId` albo po 300.
- **Skutki:**
  - Kod: `VatUeBuilder`, `VatUeDocument`, `VatUeK12Gate`, `src/Connector/EDeklaracje/*`; zmiany w `FilingSubject`, `FilingRequest`, `MonthClose`, `AuthDataDocument::element`, `config/services_connectors.yaml`.
  - Testy i dane: wiersze F5.5 w `tests/Acceptance/f5.yaml`; `tests/Fixtures/Jpk/vat-ue-2026-11.json`, złoty plik `tests/Fixtures/Jpk/golden/vat-ue-golden.xml`, nagrania `tests/Fixtures/EDeklaracje/soap-*.json` (ręczne, fikcyjne).
  - Nazwy: „Implementacja F5.5” w [naming.md](naming.md). Bez migracji.
  - F5.6 zastępuje nagrania prawdziwymi z bramki testowej i potwierdza: przestrzeń nazw i kształt odpowiedzi, adres usługi PROD, przyjęcie `DaneAutoryzujace` spoza XSD deklaracji (401 albo 410/418 → nowy D-xx), status odpowiedzi `sendUnsignDocument`.
- **Pytania:**
  - Q-32: kanał z danymi autoryzującymi (potwierdzenie na bramce testowej w F5.6).

### D-67 Kształt żądań bramki e-Deklaracji z WSDL bramki testowej

- **Status:** przyjęta 2026-10-15 (decyzja Claude; odczyt opublikowanego WSDL bez uwierzytelnienia, bez wysyłki dokumentu).
- **Kontekst:**
  - D-66 założyło przestrzeń nazw i kształt SOAP ze specyfikacji we-wy 2.4, do potwierdzenia w F5.6.
  - Sesja z dostępem do `test-bramka.edeklaracje.gov.pl` pobrała `uslugi/dokumenty?wsdl` i dołączony `GateService.xsd` (GET bez uwierzytelnienia, host testowy).
- **Decyzja:**
  - Przestrzeń nazw `https://bramka.e-deklaracje.mf.gov.pl/xsd`, `SOAPAction` `urn:sendUnsignDocument` i `urn:requestUPO`, pola `document`, `language`, `signatureType` (`PIT`) oraz odpowiedzi `refId`, `status`, `statusOpis`, `upo` są zgodne z założeniem D-66.
  - Element ciała żądania wysyłki to `sendUnsignDocument`; założony `sendUnsignDocumentRequest` nie istnieje w WSDL i bramka odrzuciłaby żądanie. `EDeklaracjeGateway` poprawiony.
  - `GateService.xsd` trafia do `resources/xsd` z wpisem w `checksums.json`; `EDeklaracjeSendAcceptedTest` waliduje nim ciało każdego żądania SOAP (`FakeEDeklaracjeGateway::soapBodies`).
- **Skutki:**
  - Kod: `EDeklaracjeGateway`, `FakeEDeklaracjeGateway`, testy F5.5; bez migracji.
  - Otwarte dla F5.6 z D-66: przyjęcie `DaneAutoryzujace` spoza XSD deklaracji, kody 410 i 418, adres usługi PROD, prawdziwe nagrania.

### D-68 F5.6: VAT-UE i VAT-UEK na bramce testowej e-Deklaracji

- **Status:** przyjęta 2026-10-15 (przebieg live z sesji z dostępem do `test-bramka.edeklaracje.gov.pl`, na Twoje polecenie; dane i dane autoryzujące fikcyjne).
- **Kontekst:**
  - D-66 i Q-32 zostawiły do F5.6: działanie bramki testowej, przyjęcie `DaneAutoryzujace` spoza XSD deklaracji, brak 418 dla VAT-UE i 410 dla VAT-UEK.
  - Wysyłka idzie tylko przez bramkę zatwierdzeń (`OfficialWritesHandler`), więc komenda `app:fixtures:record` nie może sama wysłać dokumentu.
- **Decyzja:**
  - Testy `tests/Live/EDeklaracjeTeVatUeTest` i `EDeklaracjeTeVatUekTest` składają fikcyjny miesiąc 11.2026 przez bramkę: prośba, zatwierdzenie, worker, `requestUPO` co 6 s do stanu końcowego. Pod `ConnectorHttpClient` i `HostAllowlist` (cel `test`) jest prawdziwy klient Symfony HttpClient (`KsefRecordings` w trybie live).
  - Wynik 15.10.2026: oba przyjęte (`sendUnsignDocument` 301, `requestUPO` 302, potem 200 z UPO, `Przyjeto` = true). Bez 418 i 410: D-27 zostaje, Q-32 zamknięte. Bramka testowa nie sprawdza danych autoryzujących z rejestrem; niezgodne dane PROD dają `rejected` według mapowania D-66.
  - Nagrania: przebieg live zapisuje odpowiedzi po `Scrubber` i `FixtureScanner` do `var/live-recordings/<Connector>` (`LiveRecording`); po przeglądzie trafiają do `tests/Fixtures/EDeklaracje/live-*.json`. `EDeklaracjeLiveRecordingTest` odtwarza je w CI. Ręczne nagrania kodów błędów (`soap-upo-4xx`) zostają, bo bramka testowa ich nie dała.
  - Kryterium `edeklaracje-te-fixtures` ma wykonawcę `ci` (odtworzenie nagrań) zamiast `app:fixtures:record`.
- **Skutki:**
  - Kod testów: `tests/Live/EDeklaracjeLiveTestCase.php`, `EDeklaracjeTeVatUeTest`, `EDeklaracjeTeVatUekTest`, `tests/Support/LiveRecording.php`, tryb live w `KsefRecordings`, parametr `live` w `FakeEDeklaracjeGateway`, `JpkSendTestTools`, `VatUeTestCase`; kod aplikacji bez zmian.
  - Rejestr `tests/Acceptance/f5.yaml`: `edeklaracje-te-vat-ue`, `edeklaracje-te-vat-uek` (`live`), `edeklaracje-te-fixtures` (`ci`).
  - Otwarte w F5.6: `jpk-te-v7m-upo` (JPK_V7M na test-e-dokumenty) czeka na dostęp do magazynu `taxdocumentstorageNNtst` i klucza MF celu `test`.

### D-69 F5.3: JPK na test-e-dokumenty, 419 z fikcyjnymi danymi autoryzującymi

- **Status:** przyjęta 2026-10-15 (przebiegi live z sesji z dostępem do `test-e-dokumenty.mf.gov.pl` i magazynu `taxdocumentstorageNNtst`, na Twoje polecenie; dane i dane autoryzujące fikcyjne).
- **Kontekst:**
  - F5.3 zakładał UPO dla JPK_EWP(4) i JPK_ST(1) na TE oraz duplikat po przyjęciu; F5.6 to samo dla JPK_V7M(3).
  - Twarda zasada: na cele testowe tylko dane fikcyjne, także dane autoryzujące.
  - Specyfikacja API JPK 5.6.0 nie mówi, jak TE traktuje dane autoryzujące; bramka e-Deklaracji testowa ich nie sprawdza (D-68).
- **Decyzja:**
  - Testy `tests/Live/JpkTe*` (wspólny przebieg w traicie `JpkLiveRun`) składają fikcyjne pliki przez bramkę zatwierdzeń, tak jak D-68. Klucz AES opakowuje certyfikat klucza publicznego MF celu `test` z „Plików do pobrania JPK_VAT” na podatki.gov.pl (od 9.07.2025, ważny do 7.07.2027, sha256 PEM `da570840d47308f8aa35f79ffda70fde340b0ec6c00ed01f26791d7c87bd5685`). Plik leży poza repo w `var/jpk/mf-test.crt` (ścieżka `jpk.mf_certificates`), bo zasady wykluczają `*.pem` z commitów.
  - Wynik 15.10.2026: JPK_EWP(4), JPK_ST(1) i JPK_V7M(3) przechodzą `InitUploadSigned` 200, Put Blob 201 (magazyn `taxdocumentstorage19tst`), `FinishUpload` 200; `Status` zwraca 419 „Weryfikacja negatywna – błąd w danych autoryzujących”, „Dane niezgodne z prawdą”. MF odszyfrował plik i `AuthData` (bez 417 i 418), więc koperta, metadane i szyfrowanie są poprawne. TE porównuje dane autoryzujące z danymi podatnika, więc fikcyjne nie przejdą.
  - Kryteria `jpk-te-ewp-upo`, `jpk-te-st-upo` i `jpk-te-v7m-upo` sprawdzają na TE stan końcowy: `accepted` z UPO albo `rejected` z 419 bez UPO (okres JPK_V7M bez zamknięcia). `jpk-te-duplicate`: po przyjętym oryginale kod duplikatu; po odrzuconym (419) MF nadaje nowy numer i zwraca ten sam kod, czyli nie uznaje odrzuconego pliku za oryginał.
  - Przyjęcie z UPO z danymi autoryzującymi i duplikat po przyjęciu potwierdzają wiersze go-live `jpk-ewp-prod-first` i `jpk-v7m-prod-first` (pierwsze złożenie PROD przez bramkę). Prawdziwych danych autoryzujących na TE nie wysyłasz.
  - `jpk-te-tls`: `JpkGateway` z `jpk.tls_ciphers` negocjuje TLS 1.3 `TLS_AES_256_GCM_SHA384`, z listy sześciu (D-57). Tryb live `KsefRecordings` przekazuje teraz `crypto_method` i `ciphers`.
  - Nagrania: `LiveRecording` usuwa podpis SAS (`sig=`) z adresów Put Blob. `tests/Fixtures/Jpk/live-{ewp,st,v7m}-{upload,status}.json` odtwarza w CI `JpkLiveRecordingTest` (kryterium `jpk-te-fixtures`, wykonawca `ci`, jak w D-68).
  - podatki.gov.pl stoi za ochroną przed botami (Imperva): zwykły `GET` dostaje stronę z wyzwaniem JavaScript, nie plik. Sesja pobrała certyfikat przeglądarką bez interfejsu. `mf_keys.refresh` nie może więc zakładać pobierania z serwera; zadanie pilnuje daty ważności, a nowy certyfikat dostarcza człowiek albo sesja.
- **Skutki:**
  - Kod testów: `tests/Live/JpkLiveRun.php`, `JpkLiveTestCase`, `JpkTeEwpUpoTest`, `JpkTeStUpoTest`, `JpkTeDuplicateTest`, `JpkTeTlsTest`, `JpkTeV7mUpoTest`, `tests/Integration/JpkLiveRecordingTest.php`; parametr `live` w `FakeJpkGateway`, `JpkSendTestCase` i `JpkV7mTestCase`; kod aplikacji bez zmian.
  - Rejestr `tests/Acceptance/f5.yaml`: wiersze F5.3 i `jpk-te-v7m-upo`.
  - Job `live` w CI potrzebuje pliku `var/jpk/mf-test.crt`; bez niego testy JPK live kończą się komunikatem o braku certyfikatu. Od D-97 job bierze go ze zmiennej repozytorium `MF_TEST_CERT_PEM`.

### D-70 F3.5: KSeF na api-test, własny klient zamiast biblioteki

- **Status:** przyjęta 2026-10-15 (przebiegi live z sesji z dostępem do `api-test.ksef.mf.gov.pl`, na Twoje polecenie; dane fikcyjne).
- **Kontekst:**
  - F3.1–F3.4 działały na `FakeKsefGateway`; `KsefGateway` wskazywał `UnavailableKsefGateway`. Biblioteka z D-14 nie weszła do `composer.json`.
  - F3.5 miał wejścia „stop”: `KsefGateway` nad biblioteką i dane TE z F1 (testowa JDG, token z `InvoiceWrite`).
  - Klucze publiczne MF wymagają RSA-OAEP z SHA-256 i MGF1-SHA-256; `openssl_public_encrypt` w PHP daje tylko OAEP z SHA-1.
- **Decyzja:**
  - Własny cienki klient KSeF API 2.0 w `src/Connector/Ksef`: `KsefApi` (żądania przez `ConnectorHttpClient`, czyli `HostAllowlist`, `connector_call` i `rate_counter`; klucze publiczne MF z `/security/public-key-certificates`), `KsefCrypto` (OAEP-SHA-256 ręcznie nad surowym RSA, AES-256-CBC z PKCS#7), `KsefAuthenticator` (challenge, token zaszyfrowany kluczem `KsefTokenEncryption`, status, redeem, refresh; para JWT pod Symfony Lock w `credential_state` przez `SecretStore`) i `HttpKsefGateway` (sesja online, wysyłka, status, zamknięcie, UPO, lista sesji, faktury sesji, lista błędnych). Adresy w parametrze `ksef.api_base` per cel. Eksport (F1) zostaje niezaimplementowany: `GatewayUnavailable`. D-14 i Q-19 są zastąpione.
  - Dane TE powstają w teście, nie w F1: TE przyjmuje samopodpisaną pieczęć z `organizationIdentifier` = `VATPL-<NIP>`. `KsefTeProvision` losuje NIP z poprawną sumą kontrolną na każdy przebieg (bez kolizji P_2 z innymi użytkownikami TE), loguje się podpisem XAdES-BES i tworzy token z `InvoiceWrite` i `InvoiceRead`. Token trafia do sejfu w pamięci (`MemoryVault`), nie do repo. Dowody maskują NIP jako `[NIP-TE]`.
  - `app:credential:smoke ksef_token_write` i `ksef_token_read` wykonują pełne uwierzytelnienie (`KsefAuthenticator::accessToken($role, true)`).
  - Latarnia TE zwraca stałe dane przykładowe (`TOTAL_FAILURE` od 31.01.2026), więc `latarnia.endpoints` ma tylko cel `prod`; przy celu `test` i `demo` Latarnia nie wstrzymuje wysyłki. Nagranie `tests/Fixtures/Latarnia/te-status.json` to dowód.
  - Przyjęta KOR zmienia oryginał z `accepted` na `corrected` (`SalesInvoiceSubject`), zgodnie z [data-model.md](data-model.md); wcześniej tego przejścia nie było.
  - Wynik 15.10.2026: `pl_b2b`, `eu_b2b`, `non_eu_b2b` i KOR przyjęte z numerem KSeF i UPO; ponowienie przyjętej faktury dało 440 „Duplikat faktury” z `originalKsefNumber` i stan `accepted` z kodem 440.
  - Nagrania: testy live zapisują odpowiedzi przez `LiveRecording` (`Scrubber`, skaner fixtures, podpis SAS usuwany także z `skoid` i `sktid`); `app:fixtures:record` nie wysyła zapisów urzędowych (jak D-68). Kroki uwierzytelnienia nie są nagrywane; odtworzenie dostaje fikcyjną parę JWT, a klucze MF zastępuje fikcyjny certyfikat (`te-public-keys.json`), więc test odtwarza też szyfrowanie. `KsefTeRecordingTest` (kryterium `ksef-te-fixtures`, wykonawca `ci`) odtwarza wszystkie scenariusze w CI.
- **Skutki:**
  - Kod: `src/Connector/Ksef/{KsefApi,KsefAuthenticator,KsefCrypto,HttpKsefGateway}.php`, `CredentialSmokeCommand`, `SalesInvoiceSubject`, `config/services_connectors.yaml` (alias `KsefGateway` → `HttpKsefGateway`).
  - Testy: `tests/Live/KsefTe*`, `tests/Integration/KsefTeRecordingTest.php`, `tests/Unit/KsefCryptoTest.php`, `tests/Support/{KsefTeProvision,MemoryVault}.php`; rejestr `tests/Acceptance/f3.yaml`: wiersze F3.5.
  - `kod-i-qr-test` zostaje `[manual]`: skan kodu QR z PDF faktury przyjętej na TE robisz Ty.
  - PROD potrzebuje Twojego tokena `ksef_token_write` w sejfie `prod` (go-live).

### D-110 Koszty i saldo z wyciągu bankowego

- **Status:** przyjęta 2026-10-21 (decyzja Claude w ramach pełnej odpowiedzialności za projekt). Zastępuje w D-108 punkt „Poza zakresem” o fakturach kosztowych, `invoice.paid_on` i saldzie.
- **Kontekst:**
  - D-108 zostawiła poza zakresem faktury kosztowe i saldo. Pozycje „Import wyciągu bankowego” i „Cash-flow i rezerwa podatkowa” w [roadmap.md](roadmap.md) czekały na obie rzeczy: zapłatę dostawcy wpisujesz dziś ręcznie („Oznacz jako zapłacony”), a `/cashflow` (D-88) nie zna stanu rachunku.
  - Lista operacji CSV z mBanku nie ma salda (D-108). Jedyne podsumowanie w preambule to sumy wpływów i wydatków okresu (`#Waluta;#Wpływy;#Wydatki;`). Saldo nie wynika z danych bez punktu startowego spoza pliku, a wpis ręczny salda to nowa funkcja, o którą nikt nie prosił.
  - Faktura kosztowa z KSeF (Subject2) i koszt spoza KSeF leżą w jednej tabeli `invoice` (`direction` = `cost`). `invoice.paid_on` to dzień zlecenia przelewu; czytają go `wht_payment` i `wl_notice` ([data-model.md](data-model.md)). W mBanku data operacji obciążenia to dzień zlecenia.
  - Przelew w podzielonej płatności z rachunku firmowego to jedna operacja `PP/…` z pełną kwotą brutto; techniczne przeniesienia VAT (`PP/PRZELEW VAT …`) są już `internal` (D-108). Tytuł komunikatu MPP ma pola `/VAT/`, `/IDC/` (NIP dostawcy), `/INV/` (numer faktury) i `/TXT/`.
  - `counterparty.bank_accounts` ma wpisy jako tekst albo obiekt z polem `number` (`CostController`); rachunek sprawdzony przed zapłatą zostaje w `verification.bank_account` (biała lista, `PrePaymentCheck`).
- **Decyzja:**
  - Dopasowanie wydatku (`kind` = `outgoing`, także z `split_payment`) do kosztu, reguła `cost_invoice_amount`: koszt bez `paid_on` z `cost_review` pustym, `business` albo `disputed` (ten sam warunek co koszty do zapłaty w `/cashflow`, stała `CashFlow::PAYABLE`), w tej samej walucie, z `gross_total` = kwota obciążenia, i co najmniej jedno wskazanie dostawcy: rachunek odbiorcy z `counterparty.bank_accounts` albo z wyniku białej listy `valid` (`verification.bank_account`; wynik `invalid` albo `unverified_retry` nie potwierdza rachunku), bez spacji i prefiksu PL, NIP dostawcy jako całe słowo w opisie (w MPP pole `/IDC/`) albo numer faktury lub numer KSeF jako całe słowo w opisie. Opis propozycji wymienia wskazania. Sama kwota nie wystarcza.
  - Jednoznaczność w obie strony jak w D-108: koszt jest celem `invoice_id`, więc ten sam koszt nie dostanie dwóch wierszy (podgląd i indeks unikalny). Kandydaci są zablokowani `FOR UPDATE` w transakcji zatwierdzenia.
  - Zatwierdzenie zaznaczonej propozycji wpisuje `invoice.paid_on` = data operacji przez `CostReview::markPaid` (wpis `cost.paid` jak przy wpisie ręcznym), w tej samej transakcji co zapis operacji. Skutki `paid_on` (terminy `wht_payment`, `wl_notice`) są takie same jak przy wpisie ręcznym.
  - Migracja zmienia trzy `CHECK` w `bank_statement_line`: nowa wartość `match_rule`, reguła tylko przy obciążeniu, cel tylko `invoice_id`.
  - Saldo: kokpit go nie wylicza i nie prosi o nie. `CashFlow::summary` dostaje `bank` (per NRB i walutę: pierwsza i ostatnia data operacji, liczba, wpływy, wydatki, ruch netto z zapisanych operacji) i `bank_until` (koniec okresu ostatniego zatwierdzonego wyciągu). `/cashflow` pokazuje to w sekcji „Rachunki z wyciągów” z wyjaśnieniem, że to nie saldo. Ruch nie wchodzi do salda PLN ani do prognozy pozycji, bo nie jest stanem rachunku.
  - Czat bez zmian: `get_cashflow` liczy własnym zapytaniem na widokach `chat_*`, nie z `CashFlow::summary`.
  - Warunek powrotu salda: czytnik formatu z saldem zamknięcia (np. MT940, pole `:62F:`) jako kolejny `BankStatementParser` albo Twoja prośba o wpis salda startowego.
- **Skutki:** `src/Bank/BankLineMatcher.php`, `BankMatchRule.php`, `BankStatements.php`, `src/Ledger/CashFlow.php`, `templates/panel/cashflow.html.twig`, `templates/panel/bank_statement.html.twig`, migracja `Version20261029000910`; testy `BankLineMatcherTest`, `BankStatementsTest`, `BankPanelTest`, `CashFlowSummaryTest`, `CashFlowPanelTest`, `BankTestTools`; [data-model.md](data-model.md), [naming.md](naming.md), [roadmap.md](roadmap.md), [compliance-calendar.md](compliance-calendar.md).
- **Pytania:** brak.

### D-108 Import wyciągu bankowego

- **Status:** przyjęta 2026-10-21 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Pozycja „Import wyciągu bankowego” z tabeli integracji w [roadmap.md](roadmap.md) (priorytet 4 po F6): płatności klientów PL, zapłaty ZUS i US wpisujesz dziś ręcznie. `payment` (Q-24, D-45, D-84) zna tylko płatności z FreshBooks.
  - Właściciel dał przykładowy eksport z mBanku: „lista operacji” CSV, UTF-8 z BOM, separator `;`, preambuła z sekcjami `#` (okres, rachunki z NRB), kolumny `#Data operacji;#Opis operacji;#Rachunek;#Kategoria;#Kwota;`, kwota `-1 234,56 PLN`. Opis to segmenty rozdzielone co najmniej dwiema spacjami: tytuł i kontrahent, typ operacji (np. `PRZELEW PODATKOWY`, `PRZELEW ZEWNĘTRZNY DO ZUS`, `PRZELEW WŁASNY`, `PP/…` przy podzielonej płatności) i NRB kontrahenta. Eksport nie ma identyfikatora transakcji ani salda.
  - Plik właściciela nie trafia do repo; fixture to fikcyjny plik o tym samym układzie, z NRB jako znacznikami `{account:N}`, bo skaner fixtures pada na 26 cyfrach.
  - Obowiązki `vat_jpk_v7m` i `pit_28` zamyka przyjęcie złożenia (`MonthClose`), nie wpłata; tylko `zus`, `health_reconciliation`, `ryczalt_advance` i `wht_payment` są czysto płatnicze i mają `amount_due`.
- **Decyzja:**
  - Kod w `src/Bank`: interfejs `BankStatementParser` (`format`, `supports`, `parse`) i jedyna implementacja `MbankCsvParser` (format `mbank_csv`). Inne banki i MT940 to kolejne implementacje, bez kodu teraz. Błąd w dowolnym wierszu odrzuca cały plik; komunikat podaje numer rekordu CSV i pole, nigdy treść operacji. Rachunek wiersza (`nazwa 1234 ... 5678`) musi wskazywać NRB z sekcji `#dla rachunków:` po nazwie i fragmentach numeru (kilka rachunków może mieć tę samą nazwę).
  - Strona `/bank` (link z pulpitu i `/payments`): wgranie pliku do 1 MB (domyślny limit treści żądania nginx to 1 MiB; eksport roku to około 100 KB) → wyciąg `pending` (plik w `FileStore` jako `evidence`, bez wierszy) → podgląd liczony z pliku → „Zatwierdź” albo „Odrzuć”. Najwyżej jeden `pending`; ten sam plik drugi raz (sha256) odmowa.
  - Idempotencja: `line_hash` z NRB rachunku, daty, kwoty, waluty, opisu po zwinięciu białych znaków i numeru wystąpienia takiej samej operacji w pliku; nakładające się eksporty zapisują tylko nowe operacje. Kontrakt: eksport wszystkich operacji rachunków za pełne dni, bez filtrów (panel to mówi). Operacji z datą po dziś (np. `PRZELEW PRZYSZŁY DO ZUS`) nie zapisujemy: po zaksięgowaniu zmienia się opis, więc dałyby duplikat.
  - Dopasowanie (tylko odczyt banku, zero ruchu pieniędzy, zawsze podgląd):
    - przelew do ZUS (typ `… DO ZUS` albo NRB = `company.zus_account`) → otwarty `zus` albo `health_reconciliation`; przelew do US (`PRZELEW PODATKOWY` albo NRB = `company.tax_micro_account`) → otwarty `ryczalt_advance` albo `wht_payment`. Tylko obciążenie w PLN, `amount_due` = kwota, okres obowiązku zakończony przed operacją (miesiąc, kwartał albo rok według `rule_key`; ZUS ma zwykle tę samą kwotę co miesiąc, więc bieżący miesiąc nie może być kandydatem). Symbol w tytule (`VAT-7`, `VAT-7K` bez celu; `PPE`, `PIT-28` → `ryczalt_advance`; `CIT-10Z` → `wht_payment`) i okres `RRMnn`, `RRKnn` albo roczny `RRR` niezgodne z kandydatem wykluczają go. `CHECK` w bazie wiąże reguły obowiązków z obciążeniem, a reguły faktur i płatności z wpływem.
    - wpływ → faktura sprzedaży `VAT` w stanie `accepted` w tej samej walucie, z `gross_total` = kwota i numerem w opisie jako całym słowem; jedyna niedopasowana płatność tej faktury z tą kwotą dochodzi do propozycji. Płatność z wierszem ewidencji bez faktury (`revenue_entry_id`) po numerze dokumentu i kwocie.
    - propozycja tylko przy jednoznaczności w obie strony: wiersz ma jednego kandydata, a kandydat jeden wiersz wśród nowych operacji podglądu; cel powiązany już wierszem w bazie odpada (indeksy unikalne na `invoice_id`, `payment_id`, `obligation_id`). Przelewy między własnymi rachunkami (`PRZELEW WŁASNY`, `PP/PRZELEW VAT …`) i obciążenia bez reguły zostają bez dopasowania; każdy wpływ innego typu idzie do dopasowania faktur i płatności.
  - Zatwierdzenie w jednej transakcji: blokada doradcza importu, `FOR UPDATE` wyciągu `pending`, ponowne parsowanie i propozycje z obowiązkami zablokowanymi `FOR UPDATE`. Odcisk podglądu (sha256 listy nowych `line_hash`) inny niż w formularzu = odmowa „podgląd nieaktualny” (północ, równoległy import). Zaznaczenie niosące token (`line_hash`, reguła, cele) różny od przeliczonej propozycji jest pomijane i liczone, nigdy podmieniane. Obowiązek zamyka `ObligationActions::recordPayment` z kwotą i dniem operacji; rozbicie składek ZUS (`zus_payment`) dalej wpisujesz ręcznie.
  - Formularz zatwierdzenia kończy się polem `complete`; bez niego (PHP obciął pola po `max_input_vars`) zatwierdzenie jest odrzucane, zanim cokolwiek zapisze.
  - Podgląd, którego plik nie da się odczytać (brak pliku, inna suma kontrolna, odmowa czytnika), pokazuje błąd i „Odrzuć”, więc oczekujący wyciąg nigdy nie blokuje kolejnego wgrania.
  - `src/Bank` w zakresie Infection i w regule PHPStan bez `float`. Audyt bez kwot i treści: `bank_statement.uploaded`, `bank_statement.confirmed`, `bank_statement.discarded`.
  - Poza zakresem: saldo (eksport go nie ma), faktury kosztowe i `invoice.paid_on` (dopasowanie kosztów i ruch rachunków dodała D-110), wypłaty Stripe, MT940 i inne banki, ponowne dopasowanie zapisanych operacji.
- **Skutki:** `src/Bank/`, `src/Controller/BankController.php`, `templates/panel/bank.html.twig`, `templates/panel/bank_statement.html.twig`, linki w `dashboard.html.twig` i `payments.html.twig`, `IsoDate::isDate`, `ArchitectureRules`, `infection.json5`, `scripts/run-mutation.sh`, migracja `Version20261028000100`; testy `MbankCsvParserTest`, `BankLineMatcherTest`, `BankStatementsTest`, `BankPanelTest`, `IsoDateTest`, fixture `tests/Fixtures/Bank/mbank-lista-operacji.csv`; tabele w [data-model.md](data-model.md), nazwy w [naming.md](naming.md), retencja i RODO w [security.md](security.md), zakres mutacji w [testing.md](testing.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-107 Bez administratora skrzynki ADE i bez zastępcy

- **Status:** przyjęta 2026-10-21 (Twoja decyzja). Zastępuje część D-99 o administratorze skrzynki.
- **Kontekst:** D-99 (Q-18) zostawiła administratora skrzynki ADE jako jedynego zastępcę przy odbiorze pism; D-03 i przegląd S9 przyjęły go jako założenie domyślne. Administratora nie wyznaczasz, nikogo innego też nie. Repo jest publiczne i kokpit może postawić inna JDG, więc dokumentacja nie może zakładać, że użytkownik ma zastępcę. Wyznaczenie administratora to możliwość z u.d.e., nie obowiązek.
- **Decyzja:**
  - Pisma ze skrzynki ADE firmy odbierasz sam w aplikacji e-Doręczeń. Administratora skrzynki ani innego zastępcy nie ma. To samo zakłada dokumentacja dla każdego użytkownika kokpitu.
  - Zabezpieczenie ADE firmy: powiadomienia mailowe dostawcy i Twój codzienny podgląd skrzynki ([modules/e-doreczenia.md](modules/e-doreczenia.md), „Kanały doręczeń”).
  - Ryzyko zaakceptowane przez Ciebie: pismo od podmiotu publicznego nieodebrane w 14 dni od zawiadomienia jest doręczone fikcją (art. 42 ust. 2 [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf)) także podczas Twojej nieobecności, a terminy z pisma biegną. Przed dłuższą nieobecnością sam sprawdzasz skrzynkę i odbierasz pisma.
  - Z D-99 zostaje: jeden użytkownik kokpitu, bez drugiego `app_user` i roli zastępcy w panelu, bez UPL-1; „Rola zastępcy” z [roadmap.md](roadmap.md) zostaje odrzucona.
  - Karta awaryjna nie ma wpisu o administratorze skrzynki.
- **Skutki:** bez zmian w kodzie. [modules/e-doreczenia.md](modules/e-doreczenia.md) („Odbiór bez zastępcy”, kanały, procedura ręczna), [operations.md](operations.md) (break-glass i karta awaryjna), [README.md](README.md) (role, „Teraz – zgodność”), R-11 i R-25 w [facts.md](facts.md), [roadmap.md](roadmap.md), [naming.md](naming.md), pliki faz F0a, F0b i F3.
- **Kroki tylko dla Ciebie:** wyznaczenie administratora skrzynki i wpis na karcie awaryjnej z D-99 odpadają. Zostaje przegląd skrzynki przed dłuższą nieobecnością.
- **Pytania:** Q-18.

### D-106 Job `mutation` blokuje merge

- **Status:** przyjęta 2026-10-20 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - D-43: job `mutation` nie biegnie przy `pull_request`, póki jest doradczy; jako blokujący wraca do PR. D-47: zostaje doradczy, dopóki przebieg na `main` nie pokaże progów na całym zakresie z `infection.json5`.
  - Przebieg `main` 39419058617 (po D-105): 4665 mutantów, MSI i covered MSI 95,20% przy progach 85/95, 16m42s na 4 wątkach. Warunek D-47 jest spełniony.
  - [testing.md](testing.md) opisywał na PR tylko zmienione pliki (`--git-diff-filter=AM`), ale `scripts/run-mutation.sh` tego nie robił: zawsze cały zakres.
  - Repo `kokpit-jdg-public` jest publiczne, więc minuty GitHub Actions z D-43 nie są kosztem.
- **Decyzja:**
  - Job `mutation` bez `if` i bez `continue-on-error`: biegnie przy `pull_request`, push do `main` i `workflow_dispatch`, a czerwony job to czerwone CI.
  - PR i `main` sprawdzają cały zakres. Próg dotyczy całego zakresu (zapas 221 ocalałych przy limicie 230), a MSI kilku mutantów zmienionego pliku jest niestabilne; `--git-diff-filter` zostaje poza zakresem.
  - Progi 85/95 bez zmian.
- **Skutki:**
  - PR czeka 17–26 min na `mutation` (job bez `needs`, rusza od razu); `concurrency` anuluje starszy przebieg PR. Pierwszy przebieg PR (39423896948): 25m41s, 222 ocalałe (na `main` 221), covered MSI 95%; różnica jednego mutanta między przebiegami zjada zapas do limitu 230.
  - `main` nie ma ochrony gałęzi ani reguł, więc blokada to czerwony check i zasada merge tylko po zielonym CI, nie wymuszenie GitHub.
  - `run-mutation.sh` bez gałęzi z progami 0 dla zakresu bez `src/Tax`: blokujący job nie może przejść bez progów. `CiWorkflowTest` pilnuje progów 85/95 w skrypcie.
  - `.github/workflows/ci.yml`, `scripts/run-mutation.sh`, `tests/Unit/CiWorkflowTest.php`; [testing.md](testing.md), [naming.md](naming.md), `.claude/rules/zasady-twarde.md`.
- **Pytania:** brak.

### D-105 Mutanty równoważne i powtarzalny pomiar

- **Status:** przyjęta 2026-10-20 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Po testach KOKPIT-82 pomiar lokalny: 4811 mutantów, 363 ocalałe, covered MSI 92,45% przy progu 95%. Raporty pięciu przeglądów grup wskazały, że większość ocalałych to mutanty równoważne: zachowanie identyczne przy typach kolumn, gwarancjach `pdo_pgsql` i formacie dat.
  - [testing.md](testing.md): ocalały mutant to nowy test albo wpis ignorowania z uzasadnieniem w `infection.json5`.
  - 18 mutantów `FreshBooksLedger::countryCode` przeżywało losowo: `static $names` buduje się raz na proces, a Infection uruchamia testy w losowej kolejności, jeśli `phpunit.xml` nie ustala `executionOrder`; pokrycie dostawał przypadkowy test.
  - Idiom `(int) substr($date, 0, 4)` dawał mutant równoważny (dłuższy wycinek, ten sam rok) w tej samej linii co zabijalny (przesunięty początek). Wzorzec linii nie odróżnia ich, bo Infection porównuje tylko linię sprzed mutacji.
- **Decyzja:**
  - `(string)` przy `UuidV7::generate()` usunięte w całym `src/` (57 miejsc): metoda zwraca już `string`.
  - Rok i miesiąc daty prawnej liczy `App\Calendar\IsoDate` (format `RRRR-MM-DD` i `checkdate`, inna wartość daje `\InvalidArgumentException`) zamiast `substr` w całym `src/` (40 miejsc). Klasa leży w `src/Calendar`, bo ten katalog jest w zakresie mutacji.
  - `phpunit.xml` ma `executionOrder="default"`: bez jawnej wartości Infection uruchamia testy losowo, a z nią zachowuje kolejność, więc pokrycie trafia zawsze do tych samych testów i pomiar jest powtarzalny.
  - `infection.json5` ignoruje mutanty tylko według wzorca linii źródła per mutator (`ignoreSourceCodeByRegex`), każdy z komentarzem-uzasadnieniem:
    - Throw_ w `?? throw new \LogicException` i `is_*() ? … : throw new \LogicException`, także w pomocnikach `str()`/`int()`. Umowa kokpitu: `\LogicException` oznacza naruszenie niezmiennika; nowe strażniki tej postaci są poza pomiarem Throw_.
    - LogicalOr i negacje w `if (!is_string($row['…']) || …)`: czyste zawężenie typów kolumn NOT NULL albo odfiltrowanych w zapytaniu; w zakresie mutacji każdy taki strażnik kończy się `\LogicException`.
    - IncrementInteger i DecrementInteger w głębokości `json_decode`.
  - Rzutowania w pomocnikach `str()`/`int()` i flagi `json_encode` nie są ignorowane: przy `strict_types` wartość innego typu z bazy daje `TypeError`, a `|`→`&` gubi też `JSON_THROW_ON_ERROR`.
  - Testy blokad advisory (`LedgerTestTools::assertWaitsForNamedLock`, `assertWaitsForYearLock`): korekta wpisu, bilans otwarcia, przeliczenie obowiązków, termin z pisma. Inny klucz blokady nie wywołuje czekania, więc test pada na `fail`.
  - Bez ignorowania całych metod i numerów linii. Progi 85/95 bez zmian.
- **Ryzyka:** wzorce ukrywają też zabijalne mutanty pasujących linii (62 ignorowane w pomiarze). Pokrycie pętli `static $names` w `countryCode` należy do pierwszego testu w kolejności zestawu; nowy wcześniejszy test może je przejąć. Zapas pomiaru lokalnego: 221 ocalałych przy limicie 230.
- **Skutki:** pomiar lokalny 2026-10-20 (8 wątków, 7 min): 4665 mutantów, 4379 zabitych, 221 ocalałych, 62 ignorowane, 3 limity czasu, 0 błędów, MSI i covered MSI 95,20%; Infection kończy się kodem 0. `infection.json5`, `phpunit.xml`, `src/Calendar/IsoDate.php`, `src/` (rzutowania, rok i miesiąc, także `JpkV7mBuilder` i `VatUeBuilder`), `tests/Support/LedgerTestTools.php`, testy blokad i `IsoDateTest`; [testing.md](testing.md), [naming.md](naming.md).
- **Pytania:** brak.

### D-104 Pomiar mutacyjny w CI

- **Status:** przyjęta 2026-10-20 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - D-103: job `mutation` nigdy nie zmierzył MSI, obraz `development` nie miał sterownika pokrycia, a lokalna próba kończyła się kodem 255.
  - Testy integracyjne cofają transakcję na jednej bazie `kokpit_test`, ale pliki `FileStore` w `var/test-files` zostają. Równoległe wątki na jednej bazie i jednym katalogu wpływają na siebie, a ubity mutant może zostawić zmieniony plik.
- **Decyzja:**
  - pcov 1.0.12 tylko w etapie `development` obrazu; `production` bez sterownika pokrycia. Źródło (KOKPIT-89, 2026-10-21): archiwum tagu `v1.0.12` z github.com/krakjoe/pcov z przypiętą sumą sha256, budowa przez `phpize`. Bez pecl.php.net, bo jego awaria (504, brak metadanych REST) blokowała CI; inna suma przerywa budowę.
  - Infection biegnie w `MUTATION_THREADS` wątkach (w CI `nproc`). Wątek `n` (`TEST_TOKEN`) używa bazy `kokpit_test_<n>`, kopii `kokpit_test` z `scripts/mutation-databases.sh`, i katalogu plików `var/test-files-<n>` (`TestFiles::root`). `run-mutation.sh` przed startem sprawdza `doctrine:migrations:up-to-date` każdej kopii (brak albo stara kopia daje kod 2, nie fałszywe zabicia) i czyści katalogi wątków po poprzednim przebiegu.
  - Kod 255: główny proces Infection przekraczał `memory_limit=512M` z `kokpit.ini` przy zapisie raportu JSON (`Allowed memory size … exhausted` w `JsonLogger`; przy 512M zależnie od przebiegu). Infection dostaje `-d memory_limit=2G`; szczyt w przebiegu lokalnym to 0,41 GB.
  - Wrapper `file://` Infection (`IncludeInterceptor`) podmienia zmutowany plik, ale nie obsługuje `fsync`, więc każdy nowy zapis `FileStore` w procesie mutanta kończył się wyjątkiem, czyli fałszywym zabiciem. `tests/bootstrap.php` wczytuje zmutowany plik od razu i przywraca zwykły wrapper.
  - `--only-covering-test-cases` i limit czasu mutanta 120 s (`infection.json5`): przy domyślnych 10 s 474 mutanty `PolishCalendar`, `ApprovalGate` i `ServiceMap` były pomijane i nie liczyły się do MSI.
  - Raport JSON (`var/evidence/mutation.json`) jest artefaktem `mutation` przebiegu, także po czerwonym wyniku; to dowód `cc-23`. Job ma limit 90 min.
  - Zakres bez zmian: całe katalogi z `infection.json5`. Progi 85/95 bez zmian.
- **Ryzyka:** mutant ubity po limicie czasu w trakcie testu, który psuje plik payloadu i przywraca go w `finally`, zostawia zmieniony plik w katalogu wątku do końca przebiegu; kolejne mutanty tego wątku z tym payloadem padają (fałszywe zabicie). Limitów czasu jest kilka na przebieg. `InfectionInterceptorTest` pada, gdy nowa wersja Infection zmieni pole `intercept`. Pomiar lokalny 2026-10-20 (8 wątków, 17 min): 4640 mutantów, MSI i covered MSI 72,7%, 1265 ocalałych. Job zostaje czerwony i doradczy, dopóki testy nie dobiją progów (osobna zmiana).
- **Skutki:** `Dockerfile`, `infection.json5`, `scripts/run-mutation.sh`, `scripts/mutation-databases.sh`, `.github/workflows/ci.yml`, `tests/bootstrap.php`, `tests/Support/TestFiles.php`, `tests/Unit/CiWorkflowTest.php`, `tests/Unit/InfectionInterceptorTest.php`; [testing.md](testing.md) („Testy mutacyjne”), [naming.md](naming.md).
- **Pytania:** brak.

### D-103 Job `mutation` bez pomiaru w CI

- **Status:** przyjęta 2026-10-20 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Paczka `f0b` (przebieg 39385540766) przeszła 38 z 38 wierszy `ci`. Przekrojowe `cc-23` wymaga raportu MSI z joba `mutation`.
  - Log joba: Infection kończy się od razu błędem „no code coverage generator (pcov, phpdbg or xdebug)”. Obraz `development` nie ma sterownika pokrycia. Krok `run-mutation.sh | tee mutation.txt` szedł w domyślnej powłoce bez pipefail, więc kod wyjścia `tee` (0) dawał zielony job. Job nigdy nie zmierzył MSI w CI; covered MSI 98% dla `src/Tax` z D-47 pochodzi z przebiegu lokalnego.
  - Próba lokalna 2026-10-20 z pcov ładowanym tylko na przebieg pokrycia (`--initial-tests-php-options='-d extension=pcov.so'`): 4640 mutantów, jeden wątek, po 22 min przerwana kodem 255 przy ok. 1850 mutantach; wiele mutantów przeżyło, MSI całego zakresu jest wyraźnie poniżej 85/95.
- **Decyzja:**
  - Krok Infection ma `shell: bash` (pipefail): błąd Infection albo wynik poniżej progów daje czerwony job. Job zostaje doradczy (`continue-on-error`, bez `pull_request`), więc nie blokuje merge. Podsumowanie zapisuje się też po błędzie (`if: always()`). `CiWorkflowTest` pilnuje `shell: bash`.
  - `cc-23` w listach `f0b`, `f1`, `f3`, `f5` ma `fail` z tą przyczyną; `mutation-report` [manual] zostaje bez raportu.
  - Prawdziwy pomiar to osobna zmiana (nowy KOKPIT-n): sterownik pokrycia w obrazie `development`, budżet czasu joba, wątki przy jednej bazie testowej, przyczyna kodu 255, potem testy albo wpisy ignorowania dla ocalałych mutantów. Progów nie obniżamy (zasada z [testing.md](testing.md)).
- **Ryzyka:** do tej zmiany jakość testów `src/Tax`, `src/Ledger`, `src/Calendar` i `src/Approval` nie ma pomiaru mutacyjnego w CI; każdy przebieg na `main` pokazuje czerwony job `mutation`.
- **Skutki:** `.github/workflows/ci.yml`, `tests/Unit/CiWorkflowTest.php`; [testing.md](testing.md) („Testy mutacyjne”), listy `docs/evidence/f0b.md`, `f1.md`, `f3.md`, `f5.md`, [README.md](README.md).
- **Pytania:** brak.

### D-102 Pierwsze przebiegi joba `live`: PIT-28 z losowym NIP i dowody po czerwonym teście

- **Status:** przyjęta 2026-10-20 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Zmienna `MF_TEST_CERT_PEM` (D-97) jest ustawiona: certyfikat testowy MF z „Plików do pobrania JPK_VAT”, sha256 PEM zgodny z D-69. Pierwszy ręczny przebieg joba `live` (faza `f1`) dał 23 z 24 testów zielonych, także `white-list-live` z runnera GitHub.
  - `EDeklaracjeTePit28Test` dostał z `requestUPO` status 411. Specyfikacja e-Deklaracji 2.4: „w systemie jest już złożony dokument z takim identyfikatorem podatkowym”. Test miał stały fikcyjny NIP `1111111111` z `Pit28TestTools::IDENTITY`; bramka testowa ma już PIT-28 za 2025 z tym NIP. VAT-UE i VAT-UEK z tym samym przebiegiem przechodzą wielokrotnie, więc ograniczenie dotyczy zeznania rocznego.
  - `kokpit:evidence --only=live` uruchamia komendę każdego wiersza `live` drugi raz w tym samym jobie (D-28), więc przy stałym NIP drugie złożenie PIT-28 dostawałoby 411 nawet na czystej bramce.
  - Kokpit potraktował 411 zgodnie z [modules/jpk.md](modules/jpk.md): `unknown` i rekoncyliacja odczytem, bez ponownej wysyłki.
  - Job `evidence` dla `f0a` nie zbudował paczki: `cc-13` (`scripts/check-ci.sh`) uruchamia cały zestaw z `EVIDENCE_DIR`, a `DraftApprovalCopyPanelTest` zapisywał `screens/draft-approval-copy.html` z fikcyjnym NRB, na którym pada skaner paczki.
  - W paczce `f0a` wiersze `credential-register` i `credential-smoke` (`scripts/credential-acceptance.php`) zostawiały w bazie `kokpit_test` poświadczenie `dummy_token`, wywołanie i licznik `dummy`; `cc-13` uruchamia potem cały zestaw na tej bazie i trzy testy padały (`ObligationEngineTest`, `WhtObligationAmountsTest`, `ConnectorValidityPanelTest`).
  - Paczka `live` dla `f0a` (wiersz `live-targets` uruchamia cały zestaw `live`) raz padła na skanerze: „NIP” w UPO KSeF. Numer referencyjny KSeF (`20261020-SO-141B2BF000-909CEAD3C9-8C`) ma segmenty 10 znaków szesnastkowych; segment z samych cyfr z poprawną sumą kontrolną NIP zdarza się losowo.
  - Krok `ci-evidence.sh` joba `live` szedł po testach bez warunku, więc jeden czerwony test zostawiał przebieg bez paczki dowodów i bez artefaktu.
- **Decyzja:**
  - `EDeklaracjeTePit28Test` bierze losowy NIP z poprawną sumą kontrolną (`KsefTeProvision::randomNip`, jak testy KSeF TE) do `company.identity`, a więc do deklaracji i `DaneAutoryzujace`. Pozostałe testy PIT-28 zostają na stałej tożsamości i złotym pliku.
  - `DraftApprovalCopyPanelTest` zapisuje zrzut z rachunkiem zamaskowanym jako `PL[FICTIONAL_IBAN]`; skaner paczki zostaje bez wyjątków.
  - `credential-acceptance.php smoke` po sprawdzeniu usuwa swoje wiersze `dummy` (`credential_state`, `connector_call`, `rate_counter`) z celu `test`; wpis `audit_log` zostaje.
  - `FixtureScanner` pomija numery referencyjne KSeF (`RRRRMMDD-XX-` i segmenty szesnastkowe 10, 10 i 2) przed szukaniem NIP i PESEL, tak jak identyfikator przebiegu GitHub; numer KSeF faktury z NIP sprzedawcy dalej podlega skanowi. `ScannerTest` tego pilnuje.
  - Krok `ci-evidence.sh` joba `live` ma `if: ${{ !cancelled() && steps.live_tests.outcome != 'skipped' }}`: paczka powstaje także po czerwonym teście, wiersz dostaje `fail` z artefaktem, a job zostaje czerwony. Po nieudanym bootstrapie albo kroku certyfikatu testy nie ruszają drugi raz przez `kokpit:evidence`. `CiWorkflowTest` tego pilnuje.
- **Ryzyka:** losowy NIP z poprawną sumą kontrolną może istnieć w rejestrze, tak jak w testach KSeF TE (D-72); reszta deklaracji i `DaneAutoryzujace` (imię, nazwisko, data urodzenia, kwota, adres) jest fikcyjna, a bramka testowa nie porównuje ich z rejestrem (D-68). Losowy NIP może też trafić na NIP z PIT-28 na bramce testowej; wtedy znów 411 i ponowny przebieg. Na PROD 411 przy PIT-28 oznacza złożone już zeznanie za rok, a kokpit daje `unknown` i rekoncyliację.
- **Skutki:** `tests/Live/EDeklaracjeTePit28Test.php`, `tests/Integration/Pit28TestCase.php`, `tests/Support/Pit28TestTools.php`, `.github/workflows/ci.yml`, `tests/Unit/CiWorkflowTest.php`, `tests/Integration/DraftApprovalCopyPanelTest.php`, `scripts/credential-acceptance.php`, `src/Fixtures/FixtureScanner.php`, `tests/Security/ScannerTest.php`; [testing.md](testing.md), [modules/jpk.md](modules/jpk.md).
- **Pytania:** brak.

### D-101 Liczby PIT-28 do przepisania w Twój e-PIT

- **Status:** przyjęta 2026-10-20 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** szybka wygrana „Liczby pod kreator Twój e-PIT” z [roadmap.md](roadmap.md). D-96 jej nie zastępuje: `Pit28Builder` ma wariant tylko dla przychodu 2025 (PIT-28(27)), pierwszy rok kokpitu to 2026 (`YearClose::FIRST_YEAR`), wzoru dla 2026 jeszcze nie ma, a wysyłka na PROD czeka na przegląd doradcy (`pit_28.review`). Do tego czasu zeznanie składasz ręcznie. Strona „Podatki” pokazywała tylko przychód per stawka i zapłacone składki; podział odliczeń między stawki, podstawa i ryczałt per stawka oraz należny ryczałt istniały tylko w prywatnym mapowaniu na pozycje PIT-28(27).
- **Decyzja:**
  - `Pit28Builder::amounts(int $year)` liczy kwoty zeznania bez numerów pozycji: per stawka przychód, udział, odliczone składki społeczne i 50% zdrowotnej, przychód po odliczeniach, podstawa w pełnych złotych i ryczałt; razem odliczenia, podstawa, ryczałt i należny ryczałt w pełnych złotych. Metoda i zaokrąglenia są te same co w PIT-28(27): społeczne według udziału stawki do dwóch miejsc, zdrowotna według przychodu stawki.
  - Generator PIT-28(27) mapuje te same kwoty na pozycje; złoty plik `pit-28-golden.xml` i klucze `filing.totals` bez zmian.
  - Sekcja `#pit28-summary` na `/taxes` pokazuje tabelę `#pit28-rates` z oznaczeniem części (D, D.7, F.1, F.2, G, K, L), należny ryczałt i wpłacone zaliczki w `#pit28-due` oraz składki E.1 i E.4. Ryczałt miesięcy (część Q) to kolumna „Zaliczka” w tabeli zaliczek na tej samej stronie. Odmowa (brak przychodu, przychód ujemny) daje komunikat `#pit28-refused` i sam przychód per stawka.
  - Strona nie liczy kwoty do zapłaty: kreator Twój e-PIT ma wpłaty z urzędu, więc kokpit podaje swoje wpłaty tylko do porównania.
  - Odrzucone: osobna strona i eksport CSV (przychód per miesiąc i stawka już jest na `/year-close` i w ZIP roku, D-86).
- **Ryzyka:** wzór PIT-28 dla 2026 może zmienić numery pozycji albo kolejność zaokrągleń; strona podaje części, nie pozycje, i prosi o sprawdzenie przy nowym wzorze. Przegląd doradcy (D-04) obejmuje tę samą metodę co generator.
- **Skutki:** `src/Xml/Pit28Builder.php`, `src/Controller/TaxController.php`, `templates/panel/taxes.html.twig`; testy `TaxPanelTest`, `Pit28GoldenTest`; nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md), [tax-rules.md](tax-rules.md).
- **Pytania:** brak.

### D-100 Dostawcy hostingu

- **Status:** przyjęta 2026-10-20 (decyzja Claude w ramach pełnej odpowiedzialności za projekt, plan wdrożenia).
- **Kontekst:** D-18 i tabela „Hosting” w [operations.md](operations.md) opisują wymogi, ale nie dostawców; Q-40, Q-41 i Q-42 czekały na wybór. Obrazy CI są tylko amd64, a sidecar Tailscale potrzebuje `/dev/net/tun` (D-98). Ceny orientacyjne, sprawdzone 2026-10-19, bez gwarancji aktualności:

  | Usługa | Plan | Cena | Źródło |
  | --- | --- | --- | --- |
  | VPS | OVHcloud VPS-1: 2 vCore, 4 GB RAM, 40 GB NVMe | ok. 20 zł brutto/mies. przy zobowiązaniu 12 mies. | [ovhcloud.com/pl/vps](https://www.ovhcloud.com/pl/vps/) |
  | VPN | Tailscale Standard, 1 użytkownik | 8 $/mies.; Personal tylko do użytku niekomercyjnego | [tailscale.com/pricing](https://tailscale.com/pricing) |
  | backup | Scaleway Object Storage | ok. 0,016 €/GB·mies. (Multi-AZ, stawka paryska) | [scaleway.com/en/pricing/storage](https://www.scaleway.com/en/pricing/storage/) |
  | SMTP | Scaleway Transactional Email, Essential | 300 maili/mies. bez opłat, potem 0,25 €/1000 | [scaleway.com/en/pricing/managed-services](https://www.scaleway.com/en/pricing/managed-services/) |
  | dead-man | healthchecks.io Hobbyist | 0 $, 20 checków | [healthchecks.io/pricing](https://healthchecks.io/pricing/) |

- **Decyzja:**
  - VPS: OVHcloud VPS-1 w Warszawie, x86_64 z KVM. Serwer w Polsce zamyka art. 112a (D-18, R-28 w [facts.md](facts.md)); 4 GB to zapas ponad minimum 2 GB. VPS-2 dopiero przy braku pamięci.
  - VPN: Tailscale Standard. Personal odpada, bo kokpit obsługuje działalność.
  - Backup (Q-41): Scaleway Object Storage w regionie PL-WAW, inny dostawca niż hosting. Repozytorium restic `s3:https://s3.pl-waw.scw.cloud/<bucket>`, klucz dostępu tylko do tego bucketu.
  - Mail zapasowy (Q-40): Scaleway Transactional Email, SMTP `smtp.tem.scaleway.com` (`--mail-host` w `scripts/prod-install.sh`). Nadawca z własnej domeny z SPF i DKIM.
  - Dead-man (Q-42): healthchecks.io Hobbyist z checkami z „Monitoring” w [operations.md](operations.md).
  - Pakiety GHCR `app` i `proxy` publiczne: repo jest publiczne, obrazy nie mają sekretów ani sejfu, więc `docker login ghcr.io` na VPS odpada. Podpis cosign dalej sprawdza `make deploy` (D-93).
  - Odrzucone: ARM (obrazy tylko amd64), LXC i OpenVZ (brak `/dev/net/tun`), dostawcy spoza UE, magazyn obiektowy u dostawcy hostingu.
- **Ryzyka:**
  - Strona OVH nie potwierdza lokalizacji Warszawa w treści; sprawdzasz ją w konfiguratorze przy zamówieniu. Zapas: inna lokalizacja OVH w UE z dostępem on-line dla organów (art. 112a) albo Hetzner Cloud CX23.
  - Scaleway trzyma backup i SMTP: awaria konta wyłącza oba. Kanałem głównym jest ntfy (D-19), backup ma test odtworzenia (D-89), a zmiana dostawcy SMTP to tylko nowe poświadczenia w Symfony Secrets.
- **Skutki:** bez zmian w kodzie. [operations.md](operations.md) („Hosting”, „Podpisane obrazy”, „Pierwsza instalacja”, „Monitoring”, „Backup i odtwarzanie”), [security.md](security.md), [README.md](README.md) (warunki wstępne), [phases/f0a-szkielet.md](phases/f0a-szkielet.md).
- **Kroki tylko dla Ciebie:** domena nadawcy z dostępem do DNS (SPF, DKIM); konta OVHcloud, Scaleway, Tailscale Standard i healthchecks.io z umowami powierzenia; VPS-1 w Warszawie, najpierw miesiąc bez zobowiązania, potem 12 mies.; po zakupie `uname -m` → `x86_64`, `ls -l /dev/net/tun`, `systemd-detect-virt` → `kvm`; widoczność pakietów `app` i `proxy` w GHCR na publiczną.
- **Pytania:** Q-40, Q-41, Q-42.

### D-99 Zastępca: tylko administrator skrzynki ADE

- **Status:** przyjęta 2026-10-19 (Twoja decyzja w Q-18: wariant „Inbox administrator only”). Część o administratorze skrzynki zastąpiona przez D-107 (2026-10-21): administratora i zastępcy nie ma. Jeden użytkownik, bez drugiego `app_user` i bez UPL-1 obowiązują dalej. Rekord niżej to stan sprzed D-107.
- **Kontekst:** Q-18 pytało, kto Cię zastępuje: nikt, administrator skrzynki ADE czy pełny zastępca z drugim loginem w kokpicie i UPL-1. D-03 i przegląd S9 przyjęły administratora skrzynki jako założenie domyślne; pozycja „Rola zastępcy” w [roadmap.md](roadmap.md) czekała na tę odpowiedź (R-25).
- **Decyzja:**
  - Jedynym zastępcą jest administrator skrzynki ADE, którego wyznaczasz w aplikacji e-Doręczeń. Odbiera pisma pod Twoją nieobecność i przekazuje Ci termin; do kokpitu nie ma dostępu.
  - Kokpit zostaje przy jednym użytkowniku: bez drugiego `app_user`, bez roli zastępcy w panelu. Pozycja „Rola zastępcy” z [roadmap.md](roadmap.md) jest odrzucona.
  - Bez UPL-1: deklaracje i pliki składasz tylko Ty. Przed dłuższą nieobecnością składasz z wyprzedzeniem ([operations.md](operations.md), „Break-glass i zastępca”).
  - Imię administratora i data wyznaczenia trafiają tylko na kartę awaryjną (menedżer haseł i papier), nigdy do repo, `setting` ani pamięci sesji.
- **Skutki:** bez zmian w kodzie. [operations.md](operations.md) (break-glass i karta awaryjna), [README.md](README.md) (role), [roadmap.md](roadmap.md), R-25 w [facts.md](facts.md).
- **Kroki tylko dla Ciebie:** wyznaczenie administratora w aplikacji e-Doręczeń i wpis na karcie awaryjnej.
- **Pytania:** Q-18.

### D-98 Pierwsza instalacja na VPS

- **Status:** przyjęta 2026-10-19 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** przegląd kroków właściciela przed postawieniem VPS wykazał, że świeży host utknąłby przed pierwszym `make deploy`: nic nie tworzyło plików haseł ról bazy dla PROD, sejfu PROD ani certyfikatu panelu, `PANEL_ORIGIN` i hosty powiadomień nie docierały do kontenerów (logowanie passkeyem sprawdza `PANEL_ORIGIN`), konfiguracja ntfy PROD nie była opisana, a checku dead-man `ksef.sync` nic nie pingowało. Próba instalacji dołożyła kolejne braki: Tailscale bez `TS_HOSTNAME` nadaje węzłowi nazwę kontenera, a ta zmienia się przy każdym odtworzeniu kontenera i zmienia RP ID passkeyów; nikt nie robił `restic init`; `make deploy` sprawdzał `/health` z hosta, który nie jest w tailnecie (sidecar `vpn` żyje w przestrzeni sieciowej `app`); pierwsze `/health` zwraca 503 bez backupu i bez pierwszego przebiegu harmonogramu; worker każdej kolejki padał na wiadomościach z harmonogramu, bo Symfony 7.4 dokleja `ScheduledStamp` także do wiadomości z `RedispatchMessage`, a domyślny serializer nie odtwarza wyzwalacza.
- **Decyzja:**
  - `scripts/prod-install.sh --node <nazwa>` uruchamiasz raz jako root na VPS, z checkoutu `main`, przed pierwszym `make deploy`. Odmawia w sesji Claude Code i na hoście innym niż x86_64 (obrazy CI są tylko amd64). Każdy krok pomija to, co już istnieje, więc po błędzie skrypt powtarzasz. Sekretów nie wypisuje.
  - Pliki hosta leżą poza repo w `--host-dir` (domyślnie `/etc/kokpit`): `secrets/` (`HOST_SECRETS_DIR`, pliki 0400) z kluczem Tailscale, repozytorium i kluczami S3, adresem pingu `backup.run` (pytanie bez echa o brakujące), wygenerowanym hasłem restic i `pgpass`; `ntfy/` z konfiguracją serwera i hasłem telefonu; `prod.env` bez sekretów.
  - Hasła ról `postgres`, `kokpit_owner`, `kokpit_app`, `kokpit_backup` i `chat_ro` powstają w wolumenach `owner_credentials` i `app_credentials` tylko przy pustej bazie; przy istniejącej bazie brak pliku zatrzymuje skrypt (odtwarzasz plik, nie generujesz nowego). Rola `chat_ro` powstaje razem z bazą, więc go-live czatu nie tworzy już roli ręcznie.
  - Sejf PROD w wolumenie `vault` (`/run/kokpit/vault/prod`, właściciel www-data) tworzy `scripts/init-prod-vault.php`: klucze sejfu, `APP_SECRET`, `SECRETBOX_KEY_current` (raz), `DB_KOKPIT_APP_PASSWORD`, `DB_CHAT_RO_PASSWORD` i `PUSH_PROD_TOKEN` (za plikami, które je definiują).
  - ntfy PROD: plik hosta z `auth-default-access: deny-all` i użytkownikami z konfiguracji: `kokpit` publikuje w temacie `kokpit` tokenem z sejfu, `telefon` tylko czyta.
  - Nazwa węzła to `TS_HOSTNAME` w usłudze `vpn`. Adres panelu to nazwa z `CertDomains` tailnetu; certyfikat wystawia `tailscale cert` w kontenerze `vpn` (`scripts/prod-cert.sh`), a cron hosta `/etc/cron.d/kokpit-panel-cert` uruchamia go codziennie i przeładowuje proxy przy nowym pliku. Wymaga MagicDNS i HTTPS Certificates w tailnecie.
  - `/etc/kokpit/prod.env` (`KOKPIT_ENV_FILE`) trzyma `KOKPIT_DEPLOYMENT`, `PANEL_ORIGIN`, `TS_HOSTNAME`, `HOST_SECRETS_DIR`, `NTFY_CONFIG_FILE`, pocztę, `MAIL_IN_HOST`, cele i `CONNECTORS_ENABLED`. Czytają go `make deploy`, `make rollback` i `scripts/prod-cert.sh`; jego wartości wygrywają ze zmiennymi powłoki, więc wdrożenie nie cofa celu przez brak eksportu. Instalacja zapisuje cele `test` i `CONNECTORS_ENABLED=0`; zmiana celu zostaje osobnym wdrożeniem z checklisty go-live.
  - `compose.yaml` przekazuje do kontenerów aplikacji `PANEL_ORIGIN`, `MAIL_HOST`, `MAIL_FROM`, `MAIL_TO`, `PUSH_ENDPOINT` i `DEADMAN_HOST`; pusta wartość daje domyślną z konfiguracji, więc dev działa bez zmian.
  - `make deploy` po `up -d` robi pierwszy backup, gdy `last_success` jeszcze nie istnieje, i sprawdza `/health` z kontenera `app` przez adres VPN (`tailscale ip -4`, `curl --resolve`), do 28 prób co 15 s (`HEALTH_ATTEMPTS`, `HEALTH_INTERVAL`), bo pierwszy przebieg harmonogramu przychodzi do 5 minut po starcie.
  - Check dead-man `ksef.sync`: udany eksport `ksef.sync` albo `ksef.backfill` na celu `prod` pinguje `DEADMAN_PROD_KSEF_SYNC_URL`; nieudany ping trafia do logu i nie cofa eksportu.
  - `backup.run` dokłada do snapshotu restic zaszyfrowany sejf `prod/` bez pliku `*.decrypt.private.php`, bo sejf żyje tylko w wolumenie i bez niego awaria VPS kasuje `SECRETBOX_KEY_current`, a z nim `credential_state`. Klucz deszyfrujący dalej ma tylko menedżer haseł i papier.
  - `RedactingTransportSerializer` usuwa `ScheduledStamp` przed zapisem do kolejki; handlery go nie czytają.
  - `--rehearsal` robi te same kroki na danych fikcyjnych w projekcie `kokpit-rehearsal`: obrazy lokalne, atrapa interfejsu `tailscale0` i certyfikat self-signed zamiast Tailscale, bez rejestru, cosign i S3, potem migracje, start usług, `/health` przez proxy z czekaniem na heartbeat, link passkeya z `PANEL_ORIGIN` i publikacja ntfy tokenem. Job CI `images` uruchamia próbę na obrazach, które właśnie zbudował.
- **Skutki:** `scripts/prod-install.sh`, `scripts/prod-cert.sh`, `scripts/init-prod-vault.php`, `compose.yaml`, `Makefile`, `docker/backup/run-backup.sh`, `src/Messenger/KsefSyncHandler.php`, `src/Connector/DeadMan/DeadManNotifier.php`, `src/Messenger/RedactingTransportSerializer.php`, `.github/workflows/ci.yml`; testy `MakefileTest`, `SchedulerTest`, `KsefSyncTest`; [operations.md](operations.md) („Pierwsza instalacja”), [security.md](security.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Czego próba nie sprawdza:** prawdziwego Tailscale (logowanie kluczem, `CertDomains`, `tailscale cert`, nazwa węzła), pobrania i podpisu obrazów z rejestru, S3 i `restic init`, pingów healthchecks.io, obrazu `backup` (budowany na VPS), crona hosta i dostępu z telefonu do ntfy pod `/ntfy`. To sprawdza dopiero pierwsza instalacja na VPS.
- **Kroki tylko dla Ciebie:** VPS, klucz Tailscale z wyłączonym wygasaniem i HTTPS Certificates w tailnecie, bucket S3, checki healthchecks.io, `cosign` i `docker login ghcr.io`, potem `sudo scripts/prod-install.sh --node <nazwa>` i `make deploy`.
- **Pytania:** brak.

### D-97 Certyfikat testowy MF w jobie `live`

- **Status:** przyjęta 2026-10-19 (Twoje polecenie „go” w wątku z instrukcjami dla właściciela).
- **Kontekst:** testy `tests/Live/JpkTe*` wymagają pliku `var/jpk/mf-test.crt` (D-69). Job `live` startuje z czystego checkoutu, a `var/` i `*.crt` są poza repo, więc pierwszy ręczny przebieg kończyłby się brakiem certyfikatu. `CiWorkflowTest` wyklucza `secrets.` w workflow.
- **Decyzja:**
  - Certyfikat klucza publicznego MF jest publiczny, więc trafia do zmiennej repozytorium GitHub `MF_TEST_CERT_PEM` (Settings → Secrets and variables → Actions → Variables), nie do sekretu.
  - Krok joba `live` po `ci-bootstrap.sh` uruchamia `scripts/ci-mf-test-cert.sh`: usuwa znaki CR, sprawdza plik `openssl x509` (podmiot, ważność i odcisk SHA-256 w logu) oraz `-checkend 0`, potem zapisuje go do `var/jpk/mf-test.crt` w kontenerze `app`.
  - Bez zmiennej, z treścią, która nie jest certyfikatem PEM, albo z wygasłym certyfikatem krok kończy job kodem 2 przed testami.
- **Skutki:** `.github/workflows/ci.yml`, `scripts/ci-mf-test-cert.sh`, `tests/Unit/CiWorkflowTest.php`; [testing.md](testing.md), nazwa w [naming.md](naming.md), D-69.
- **Kroki tylko dla Ciebie:** zmienna `MF_TEST_CERT_PEM` z treścią PEM certyfikatu testowego MF przed pierwszym przebiegiem `live`.
- **Pytania:** brak.

### D-96 PIT-28 przez e-Deklaracje

- **Status:** przyjęta 2026-10-18 (Twoja decyzja: „Full filing through e-Deklaracje”).
- **Kontekst:** D-03 i Q-02 zostawiały PIT-28 ręcznie, a kokpit dawał tylko zestawienie (`TaxCalculator::pit28`, strona `/taxes`). Priorytet 1 w [roadmap.md](roadmap.md) to zamknięcie roku i PIT-28 z kokpitu. Konektor `edeklaracje` z D-66 i D-68 już wysyła VAT-UE z `DaneAutoryzujace` przez bramkę zatwierdzeń, a schemat PIT-28 dopuszcza dane autoryzujące ([struktury e-Deklaracji](https://podatki-arch.mf.gov.pl/e-deklaracje/dokumentacja-it/struktury-dokumentow-xml/)).
- **Decyzja:**
  - Złożenie `PIT_28` za rok przychodu N (`period_start` N-01-01, `period_end` N-12-31) idzie przez bramkę zatwierdzeń i konektor `edeklaracje`, tak jak VAT-UE: payload bez `DaneAutoryzujace`, element z sekretu `auth_data` wstawia worker przy wysyłce (`EDeklaracjeDocument::authorize`), UPO z `requestUPO`. Ta zmiana zastępuje „PIT-28 ręcznie” z D-03 i Q-02.
  - Generator działa per wariant formularza. Wariant dla roku N to schemat opublikowany przez MF, zapisany w `resources/xsd` z manifestem (źródło, data, sha256). Bez wariantu dla roku prośba odmawia z komunikatem; pól nie zgaduje się bez XSD.
  - Zakres formularza to dane kokpitu: przychód per stawka z ewidencji, składki społeczne zapłacone w roku i 50% zapłaconej zdrowotnej z `zus_payment`, wpłacone zaliczki. Ulgi, załączniki i przychody spoza działalności nie wchodzą; wtedy składasz ręcznie, a złożenie zapisujesz jako `manual` z UPO.
  - Przed prośbą: bramki „przed budową JPK_EWP(4)” roku i przyjęty JPK_EWP za N z tymi samymi sumami per stawka co PIT-28. Złożenie z tej samej ewidencji nie może się rozjechać z JPK_EWP.
  - Na PROD prośba wymaga wpisu `pit_28.review` (wariant, data przeglądu, notatka) z przeglądu doradcy mapowania odliczeń i zaokrągleń dla tego wariantu (D-04). Na celu testowym wpis nie jest potrzebny.
  - Kolejność: najpierw przebieg na bramce testowej z fikcyjnymi danymi (`@group live`, jak D-68), dopiero potem cel `prod`, który ustawiasz Ty.
  - Tylko pierwsze złożenie (`CelZlozenia` 1). Korektę PIT-28 składasz ręcznie i rejestrujesz jako `manual`.
- **Skutki:** typ `PIT_28` dostaje konektor `edeklaracje` w `FilingSubject`, `FilingRequest::pit28` i formularz prośby na `/filings`; generator i złoty plik powstają z XSD wariantu. [modules/jpk.md](modules/jpk.md) (sekcja „PIT-28”), [data-model.md](data-model.md), [README.md](README.md), [roadmap.md](roadmap.md), nazwy w [naming.md](naming.md).
- **Wykonanie (2026-10-18):**
  - Wariant PIT-28(27) dla przychodu 2025: schemat `PIT-28(27)_v1-1E.xsd` z siedmioma importami w `resources/xsd` (źródło crd.gov.pl, sumy w manifeście), mapowanie pól w [modules/jpk.md](modules/jpk.md) („PIT-28(27): mapowanie pól”). Wariantu dla przychodu 2026 MF jeszcze nie opublikował ([facts.md](facts.md)); prośba za 2026 odmawia.
  - Część B wymaga adresu zamieszkania, którego kokpit nie trzymał: nowe ustawienie `taxpayer.residence_address` z datą obowiązywania; bez niego budowa odmawia.
  - Zaokrąglenia: udział stawki do setnych procentu (instrukcja formularza), odliczenia per stawka do grosza, podstawa i ryczałt należny do pełnych złotych HALF_UP (OP art. 63 § 1). Składki społeczne odliczone najwyżej do przychodu, zdrowotna per stawka najwyżej do przychodu po społecznych. Część Q liczy ryczałt okresu tym samym wzorem co zaliczka.
  - Odmowa przy stawce 2% (produkty rolne), stawce spoza kolumn części D.1, zaliczkach z groszami i roku bez przychodu.
  - Testy: złoty plik i XSD (`Pit28GoldenTest`), pełna ścieżka bramki na atrapie (`Pit28SendAcceptedTest`), przebieg live `EDeklaracjeTePit28Test` (kryterium `edeklaracje-te-pit-28`).
- **Kroki tylko dla Ciebie:** ręczny przebieg joba `live` (obejmuje `edeklaracje-te-pit-28` na bramce testowej); wpis `taxpayer.residence_address` na `/settings`; przegląd doradcy mapowania z [modules/jpk.md](modules/jpk.md) i wpis `pit_28.review` przed PROD; cel `prod` dla `edeklaracje`.
- **Pytania:** Q-02.

### D-95 Narzędzia okresu w czacie

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Nowe narzędzia czatu” z tabeli „Warstwa AI” w [roadmap.md](roadmap.md) nie wymaga niczego od właściciela. Czat odpowiadał na pytania o miesiąc i przepływy tylko przez kilka wywołań `query_invoices` i `get_obligations`, a liczb z przyjętego JPK_V7M ani płatności nie widział wcale.
- **Decyzja:**
  - Trzy nowe narzędzia w `ChatToolRegistry`, z fazą `F2` w `chat.phases_live`, jako nazwane zapytania `ChatQuery` na widokach `chat_*`; model dalej nie pisze SQL, a parametry przechodzą tę samą walidację przed SQL (`invalid_month` dla `month` spoza RRRR-MM).
  - `get_period_summary` (`month`): suma i liczba wierszy `chat_revenue` miesiąca (K_8), liczby z najnowszego przyjętego JPK_V7M okresu (`seq`, potem `accepted_at`) oraz suma `amount_due` obowiązków `ryczalt_advance` i `zus` z `period_start` równym początkowi miesiąca. Zaliczka kwartalna stoi w pierwszym miesiącu kwartału.
  - `get_cashflow` (`date_to`): wiersze `obligations` (PLN), `receivables` i `payables` per waluta, z tymi samymi warunkami co `CashFlow` (D-88); wynik bez identyfikatorów faktur.
  - `compare_periods` (`date_from`, `date_to`, `compare_from`, `compare_to`): wiersze `current` i `compare` z przychodem z ewidencji, sprzedażą netto PLN (stany jak w `sales_by_currency`) i kosztami firmowymi netto PLN.
  - Nowe widoki z migracją `Version20261027000900`, na zasadach D-59: `chat_filing` niesie z `filing.totals` tylko `sales_net`, `vat_due`, `vat_input`, `k11`, `k12`, `p51`, `p62` (bez XML, list DI i pozycji VAT-UE); `chat_payment` bez `freshbooks_*`, bramki i wypłat. Migracja nadaje `SELECT` roli `chat_ro`, gdy rola istnieje; przy go-live czatu na PROD `GRANT` obejmuje wszystkie widoki `chat_*` jak dotąd.
  - Zmiana listy daje nowy `tool_set`, więc narzędzia działają tylko w nowych rozmowach. Na PROD `chat.phases_live` jest puste do go-live czatu.
  - `explain_filing` (pole pliku → wiersze źródłowe) zostaje w [roadmap.md](roadmap.md): wymaga mapy pól JPK do wierszy, której baza dziś nie trzyma.
- **Skutki:** `src/Chat/ChatQuery.php`, `src/Chat/ChatQueryRunner.php`, `src/Chat/ChatToolRegistry.php`, `src/Chat/ChatToolExecutor.php`, `migrations/Version20261027000900.php`; testy `ChatPeriodToolsTest`, aktualizacja `ChatNamedQueriesTest`, `ChatToolSchemaTest`, `ChatViewsMinimalTest`, `ChatRoDeniedTest`, dane `ChatTestTools::seed`; [modules/czat.md](modules/czat.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-93 Podpisane obrazy i kanał aktualizacji

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Podpisane obrazy i kanał aktualizacji” z tabeli „Ops, bezpieczeństwo, jakość” w [roadmap.md](roadmap.md). Dotąd `make deploy` budował obrazy na VPS z checkoutu, bez dowodu, że uruchamiany obraz pochodzi z kodu po zielonym CI.
- **Decyzja:**
  - Kanałem jest gałąź `main`. Job `images` w `ci.yml` (po `lint`, `unit_property`, `contract`, `xsd`, `integration`, `security`) buduje etapy `production` i `proxy` przy każdym PR. Tylko przy push do `main` wysyła je do `ghcr.io/good-yellow-bee/kokpit-jdg-public/{app,proxy}:<sha>` tokenem przebiegu (`packages: write`) i podpisuje digest `cosign sign` bez klucza (`id-token: write`).
  - Podpis bez klucza zamiast pary kluczy: nie ma klucza prywatnego do przechowania, rotacji ani wycieku; tożsamością jest przebieg CI z certyfikatu Sigstore. Wpis w publicznym logu Rekor ujawnia tylko repo, workflow i commit, które i tak są publiczne.
  - `make deploy` nie buduje. Pobiera obrazy commita z `git pull`, wybiera digest z `KOKPIT_REGISTRY`, uruchamia `cosign verify` z tożsamością `KOKPIT_SIGNER` (`…/ci.yml@refs/heads/main`), wystawcą GitHub Actions, wyzwalaczem `push` i `--certificate-github-workflow-sha` równym commitowi. Ostatnia flaga wyklucza podłożenie starszego podpisanego obrazu pod tag nowego commita. `APP_IMAGE` i `PROXY_IMAGE` są przypięte do digestu, `KOKPIT_IMAGE_DIGEST` (`image_digest` w `ops.deployed`) to digest manifestu w rejestrze.
  - `make rollback` przyjmuje `KOKPIT_COMMIT` z poprzedniego `ops.deployed` zamiast `APP_IMAGE` i `PROXY_IMAGE`; obrazy muszą być lokalnie i przechodzą tę samą weryfikację. `make verify-images` robi samą weryfikację; CI uruchamia ją po podpisie, więc flagi Makefile są sprawdzane na prawdziwym podpisie. `make update-check` pokazuje nowe commity `main` i weryfikuje ich obrazy; wdrożenie zostaje ręczne.
  - Brak `cosign` na hoście zatrzymuje cele przed pierwszym poleceniem; ścieżki bez podpisu nie ma.
- **Skutki:** `Makefile`, `.github/workflows/ci.yml`; testy `MakefileTest`, `CiWorkflowTest`; [operations.md](operations.md) (sekcja „Podpisane obrazy i kanał aktualizacji”), [security.md](security.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Kroki tylko dla Ciebie:** `cosign` 2.x na VPS; `docker login ghcr.io` tokenem z `read:packages` albo publiczne pakiety `app` i `proxy`. Kroki w [operations.md](operations.md).

### D-94 Cache historii rozmowy w czacie

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** F6 stawia jeden jawny `cache_control` na snapshocie prawa, ostatnim bloku stałego prefiksu (narzędzia, prompt systemowy, snapshot). Historia rozmowy za prefiksem idzie przy każdym żądaniu jako zwykłe wejście: w pętli narzędzi (do `chat.max_iterations` żądań na pytanie) i przy każdym kolejnym pytaniu ta sama historia, z blokami thinking i wynikami narzędzi, płaci pełną cenę wejścia. API przyjmuje najwyżej 4 punkty cache w żądaniu; automatyczny `cache_control` na poziomie żądania zajmuje jeden, stawia punkt na ostatnim bloku, który się kwalifikuje, i przesuwa go wraz z rozmową ([prompt caching](https://platform.claude.com/docs/en/build-with-claude/prompt-caching)).
- **Decyzja:**
  - `ChatRequestProfile::body()` dodaje `cache_control: {type: "ephemeral"}` na poziomie żądania. Jawny punkt na snapshocie prawa zostaje: stały prefiks ma własny punkt odczytu niezależny od historii. Razem 2 punkty z 4.
  - Oba punkty mają TTL 5 min (domyślny). Dłuższy TTL musiałby stać przed krótszym; 1 h nie ma sensu przy rozmowie z panelu, a zapis 1 h kosztuje 2×.
  - `messages` i zapisane `chat_message` zostają bez znaczników `cache_control`: historia jest tylko dopisywana ([modules/czat.md](modules/czat.md), „Historia rozmowy”), a punkt stawia API.
  - Koszt liczy dalej `ChatBudget` z `usage` (`cache_creation_input_tokens`, `cache_read_input_tokens`) według `chat.pricing`; bez nowych kluczy.
- **Skutki:** pierwsze żądanie pytania płaci zapis 1,25× za ostatnią turę, kolejne żądania pętli i następne pytanie w ciągu 5 min czytają historię za 0,1×. Krótka historia poniżej minimalnego prefiksu (512 tokenów razem z prefiksem) po prostu się nie zapisuje. `claude-request-profile` sprawdza klucz `cache_control` w żądaniu i brak znaczników w `messages`.

### D-91 Kreator pierwszego uruchomienia

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Kreator pierwszego uruchomienia” z tabeli „Produkt i UX” w [roadmap.md](roadmap.md) nie wymaga niczego od właściciela. Formularze `/settings`, „Otwarcie”, passkeye, połączenie FreshBooks i komendy `app:credential:register` oraz `app:push:test` już istniały, ale nic nie mówiło, czego jeszcze brakuje na nowym wdrożeniu albo po odtworzeniu.
- **Decyzja:**
  - Strona `/setup` (`SetupController`, usługa `SetupChecklist`) pokazuje kroki w stałej kolejności ze stanem „zrobione” albo „do zrobienia”, szczegółem i linkiem do formularza, który zapisuje dany krok. Kreator nie ma własnych formularzy ustawień: każdy zapis przechodzi tę samą walidację i audyt co dotąd.
  - Kroki: dwa aktywne passkeye; `company.identity` z polami `Podmiot1` JPK (`nip`, `tax_office_code`, `first_name`, `last_name`, `email`); `company.tax_micro_account`, `company.zus_account`, `zus.variant`, `ryczalt.advance_frequency`, `ryczalt.service_map`, `ksef.sales_transition_on` (wiersz obowiązujący dziś; wiersz z migracji bez `set_by` dostaje dopisek „wartość domyślna: sprawdź”); `zus.parameters` z `valid_from` w bieżącym roku; potwierdzony bilans otwarcia; poświadczenia `ksef_token_read`, `auth_data` i `freshbooks_oauth` w stanie `valid` albo `expiring` w środowisku wdrożenia (jak rejestruje je `app:credential:register`); test powiadomień.
  - Test powiadomień: `POST /setup/notification-test` (token CSRF `setup`) wysyła push i e-mail `push_test` jak `app:push:test`. Przy `CONNECTORS_ENABLED=0` nic nie wychodzi i strona to mówi. Udany test zapisuje akcję audytu `notification.test_sent`; krok jest zrobiony, gdy taki wpis istnieje w środowisku wdrożenia.
  - Pulpit pokazuje „Pierwsze uruchomienie: do zrobienia N kroków” z linkiem, dopóki jakiś krok czeka, i stały link w nawigacji.
  - Sekrety nie przechodzą przez panel: krok poświadczenia podaje komendę `app:credential:register <kind>`; generowanie klucza sejfu zostaje przy pozycji „Instalator” w [roadmap.md](roadmap.md).
- **Skutki:** `src/Setting/SetupChecklist.php`, `src/Controller/SetupController.php`, `templates/panel/setup.html.twig`, `src/Controller/PanelController.php`, `templates/panel/dashboard.html.twig`; test `SetupPanelTest`; nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md), [operations.md](operations.md).
- **Pytania:** brak.

### D-90 Klucz FIDO2 jako drugi passkey

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Klucz FIDO2 jako drugi passkey” z listy „Szybkie wygrane” i wiersz „Klucz sprzętowy do step-upu” w [roadmap.md](roadmap.md). Drugi passkey działał od F0b (D-40), ale ceremonia nie odróżniała klucza sprzętowego od passkeya w telefonie lub menedżerze haseł: przeglądarka mogła zapisać „zapasowy” passkey w tym samym koncie chmurowym co pierwszy. Każdy wiersz `webauthn_credential` miał etykietę `Passkey`, a przeglądarka nie wysyłała transportów, więc lista w panelu nie mówiła, który passkey jest kluczem.
- **Decyzja:**
  - Rodzaj uwierzytelniacza `AuthenticatorKind`: `passkey` albo `security_key`. Link rejestracji niesie go w `app_user.enrollment_kind` (czyszczony razem z tokenem po użyciu), a nowy wiersz dostaje `webauthn_credential.kind` i etykietę `Passkey` albo `Klucz FIDO2`. Rodzaj wynika z linku, nie z deklaracji przeglądarki.
  - Klucz ma własny profil ceremonii `security_key` i ścieżki `/passkey/enrol/security-key/options` oraz `/passkey/enrol/security-key/result`: `authenticatorAttachment` = `cross-platform` (przeglądarka proponuje tylko klucze USB, NFC i BLE), `userVerification` = `required` (PIN klucza), `residentKey` = `discouraged` (logowanie i step-up podają `allowCredentials` z bazy, a klucze mają mało miejsca na wpisy rezydentne). `EnrollmentUserEntityGuesser` odmawia ceremonii, gdy ścieżka nie pasuje do rodzaju linku. `excludeCredentials` blokuje drugą rejestrację tego samego klucza.
  - Panel `/passkeys`: przycisk „Dodaj klucz FIDO2” obok „Dodaj passkey” w tym samym formularzu, oba po step-upie jak w D-40; nieznany rodzaj daje 400. Lista pokazuje etykietę i transporty (`usb`, `nfc`), a bez klucza podpowiedź, że klucz FIDO2 jest dobrym passkeyem zapasowym.
  - SSH: `app:passkey:enrol-link --security-key` (pierwszy uwierzytelniacz jako klucz) i `app:passkey:reset --security-key` (odzyskanie od razu na klucz). Bez opcji wszystko działa jak dotąd.
  - Audyt: `auth.passkey_enrolled` ma w szczegółach `credential_id`, `kind` i `transports`; `auth.recovery_link_issued` ma `kind`. `passkey.js` wysyła `getTransports()` przy rejestracji każdego rodzaju.
  - Step-up i logowanie przyjmują każdy aktywny passkey, także klucz. Wymóg klucza do zatwierdzeń („zatwierdzenia kluczem, logowanie telefonem”) zostaje poza tą decyzją: przy utracie klucza blokowałby bramkę do resetu przez SSH.
- **Skutki:** `src/Passkey/AuthenticatorKind.php`, `src/Passkey/PasskeyEnrollmentService.php`, `src/Passkey/PasskeyManager.php`, `src/Passkey/WebauthnRepository.php`, `src/Passkey/EnrollmentUserEntityGuesser.php`, `src/Controller/PasskeyController.php`, `src/Controller/PasskeyPanelController.php`, `src/Command/PasskeyEnrolLinkCommand.php`, `src/Command/PasskeyResetCommand.php`, `config/packages/webauthn.yaml`, `assets/passkey.js`, `templates/panel/passkeys.html.twig`, `templates/passkey/enrol.html.twig`; migracja `Version20261026000900`; testy `PasskeyStepUpTest`, `PasskeyTest`; [security.md](security.md), [data-model.md](data-model.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).

### D-89 Test odtworzenia jednym poleceniem

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Pozycja „Test odtworzenia jednym poleceniem” z [roadmap.md](roadmap.md) (R-26) nie wymaga niczego od właściciela do napisania; uruchomienie zostaje kwartalnym `[manual]` (`cc-14`).
  - Runbook z [operations.md](operations.md) miał sześć ręcznych kroków i klucz sejfu PROD na laptopie na czas testu. Sprawdzenia (`app:files:verify`, `app:audit:verify`, liczby wierszy) czytają tylko bazę i pliki, więc sejf PROD nie jest im potrzebny.
- **Decyzja:**
  - `scripts/restore-test.sh --secrets <katalog> [--expected <plik>] [--snapshot <id>] [--repo-dir <katalog>] [--keep]` (bash zgodny z 3.2 z macOS). Katalog ma kopie plików hosta usługi `backup` z menedżera haseł i musi leżeć poza checkoutem; skrypt odmawia przy `CLAUDECODE` (sesja Claude Code) i przy `KOKPIT_DEPLOYMENT` innym niż `restore`.
  - Projekt `kokpit-restore` z `compose.yaml` (bez `compose.dev.yaml`): `APP_ENV=prod`, `CONNECTORS_ENABLED=0`, wszystkie `*_TARGET=test`. Na stałe startuje tylko `db` (`start-db.sh` generuje hasła także dla `restore`); restic, `pg_restore` i sprawdzenia działają w kontenerach jednorazowych, bez `proxy`, workerów, `scheduler`, `backup` i `vpn`. Pułapka `EXIT` usuwa projekt z wolumenami; istniejący projekt `kokpit-restore` to odmowa.
  - Restic w obrazie usługi `backup` z `--no-lock`: test niczego nie zapisuje w repozytorium PROD. Snapshot `latest` albo `--snapshot` zamienia się na pełny identyfikator przed `restic restore`; `--repo-dir` montuje lokalną kopię repozytorium tylko do odczytu.
  - `pg_restore --exit-on-error --single-transaction` w kontenerze `db` (ta sama wersja PostgreSQL co zrzut) ładuje zrzut do pustej bazy z rolami z `init-db.sh`; `var/data` trafia do wolumenu `runtime` przez `tar` jako użytkownik 33.
  - `app:ops:restore-check` łączy się rolą `kokpit_app` przez plik hasła projektu (`DB_OWNER_PASSWORD_FILE`), z jednorazowym `APP_SECRET`. Sprawdza łańcuch `audit_log`, kotwice `audit-head:` snapshotu odtworzonego i najstarszego (D-79; brak tagu to fail), `FileStore::verify` i liczby `approval` (`requested_at`), `filing` (`created_at`), `revenue_entry` (`recorded_at`) z miesiąca w Europe/Warsaw. `passed` wymaga wszystkich czterech; bez `--expected` liczby są tylko w raporcie.
  - Liczby z PROD daje `app:ops:restore-check --counts-only` (domyślnie poprzedni miesiąc); miesiąc z pliku `--expected` obowiązuje w kopii, więc test w innym dniu nie przesuwa okna. Poprzedni miesiąc jest zamknięty przed nocnym snapshotem, więc liczby z PROD i z kopii mają być równe.
  - Raport JSON (`kind` = `kokpit.restore_check`) ma tylko liczby, hashe i identyfikatory snapshotów. Trafia do `var/evidence/restore/` z prawami 0600, a nie do `docs/evidence` jak w szkicu z roadmapy: repo ma kopię publiczną, a liczby złożeń to dane właściciela.
  - `app:ops:record-restore <raport>|-` w `kokpit-prod` sprawdza raport pole po polu i zapisuje `ops.restored` (wynik, snapshot, pliki, łańcuch, kotwice, miesiąc i zgodność liczb); fail też jest zapisywany, z kodem wyjścia 1.
  - Klucz sejfu PROD nie trafia na laptop; potrzebny jest tylko przy prawdziwej awarii na nowym VPS ([security.md](security.md)).
- **Skutki:** `scripts/restore-test.sh`, `docker/db/start-db.sh`, `src/Ops/RestoreCheck.php`, `src/Command/{RestoreCheckCommand,RecordRestoreCommand}.php`; testy `RestoreCheckTest`, `RestoreScriptTest`; [operations.md](operations.md), [security.md](security.md), [testing.md](testing.md) (`cc-14`), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak. Pierwszy przebieg na prawdziwym snapshocie robisz Ty po starcie `kokpit-prod` (`cc-14`).

### D-92 Konektor poczty przychodzącej

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Pozycja „Konektor poczty przychodzącej” z tabeli integracji w [roadmap.md](roadmap.md): koszty spoza KSeF (mail, `invoice.source` = `email`) wymagają dziś ręcznego zapisania pliku. [modules/e-doreczenia.md](modules/e-doreczenia.md) mówił, że kokpit nie ma konektora poczty przychodzącej, więc konektor wymaga nowego rekordu.
  - PHP 8.4 nie ma już rozszerzenia `imap` w rdzeniu, a gotowe biblioteki IMAP mają szerokie API zapisu (flagi, przenoszenie, usuwanie). Kokpit potrzebuje czterech poleceń odczytu.
  - Pozycja nie wymaga niczego od właściciela do napisania kodu; prawdziwą skrzynkę i jej hasło ustawia właściciel.
- **Decyzja:**
  - Konektor `mail_in` (`src/Connector/MailIn`): własny klient IMAP4rev1 (`ImapClient`) przez TLS z weryfikacją certyfikatu, tylko port 993 (IMAPS, bez STARTTLS i bez jawnego tekstu). Dozwolone polecenia: `LOGIN`, `EXAMINE` (skrzynka tylko do odczytu), `UID SEARCH`, `UID FETCH` z `UID`, `RFC822.SIZE` i `BODY.PEEK[]` (bez flagi `\Seen`), `LOGOUT`. Każde inne polecenie (`SELECT`, `STORE`, `COPY`, `MOVE`, `EXPUNGE`, `APPEND`, `BODY[]`) odrzuca `ImapClient::assertReadOnly` przed wysłaniem; pilnuje tego test.
  - Host z konfiguracji wdrożenia `MAIL_IN_HOST` (mapa `HostAllowlist`, jak `mail`); pusty host wyłącza skrzynkę bez połączenia i bez alertu. Poświadczenie `MAIL_IN_<ENV>_DSN` (`imaps://użytkownik:hasło@host/folder`, domyślnie `INBOX`) tylko z `SecretStore`; host z sekretu musi się zgadzać z `MAIL_IN_HOST`. `CONNECTORS_ENABLED=0` blokuje połączenie.
  - Jedna sesja zużywa jeden `mail_in.fetch` (100 na dobę) i daje jeden wiersz `connector_call` (operacja `fetch`) z sha256 i rozmiarem wysłanych i odebranych bajtów liczonymi przyrostowo; treść nie trafia do logów. Błąd daje alert `connector_failure` (`mail_in`) raz na dobę i rodzaj błędu (`MailInError`).
  - Zadanie `mail_in.fetch` co 30 min na `reads`; najwyżej `mail_in.max_messages_per_run` (50) wiadomości na sesję, rosnąco po UID. Kursor to największy UID w `mail_in_message` dla `UIDVALIDITY` skrzynki; ta sama wiadomość po zmianie `UIDVALIDITY` (sha256 `Message-ID`) dostaje stan `duplicate` bez drugiej kopii plików. Wiadomość większa niż `mail_in.max_message_bytes` (25 MB) zostaje bez pobrania (`too_large`).
  - `MimeParser` wyjmuje tylko nagłówki (`From`, `Subject`, `Message-ID`, `Date`) i załączniki, także z przekazanej wiadomości (`message/rfc822`); limity zagnieżdżenia i liczby części. Treść tekstowa i HTML są pomijane: kokpit nie czyta treści maila i nie otwiera linków.
  - Dokumentem jest załącznik PDF, JPEG albo PNG rozpoznany po sygnaturze pliku, nie po nagłówku nadawcy (program z nazwą `.pdf` odpada). Trafia do `FileStore` jako `cost_document` z `owner_table` = `mail_in_message` i oczyszczoną nazwą pliku; inne załączniki liczy kolumna `skipped`.
  - Strona `/mail-in` (link z `/costs`): wiadomości, pliki do pobrania wyłącznie jako załącznik (`Content-Disposition: attachment`, `nosniff`) i akcja „Oznacz jako wpisane”. Fakturę kosztową wpisujesz sam (`CostImporter::recordManual`, źródło `email`); kokpit nie tworzy `invoice` z maila.
  - Poza zakresem tego rekordu, dalej w [roadmap.md](roadmap.md): tekst z PDF (smalot/pdfparser) i skanów (Tesseract) z regułami pól per dostawca oraz rozpoznawanie powiadomień e-US, eZUS i e-Doręczeń. Model nie dostaje treści maili ani plików (D-22 bez zmian).
- **Skutki:** `src/Connector/MailIn/`, `src/Cost/MailInbox.php`, `src/Controller/MailInController.php`, `templates/panel/mail_in.html.twig`, `src/Command/MailInFetchCommand.php`, `src/Messenger/MailInFetchHandler.php`, `ConnectorCallRecorder::recordDigests`, `FileStore::put` (`originalName`), migracja `Version20261027000500` (tabela, `connector_call.connector` i kody `MailInError` w `connector_call.error_code`); testy `MailInboxTest`, `MailInPanelTest`, `MimeParserTest`, `ImapClientTest`, `HostAllowlistTest`, `SchedulerTest`; [modules/e-doreczenia.md](modules/e-doreczenia.md), konektor w [architecture.md](architecture.md), tabela w [data-model.md](data-model.md), zadanie i konfiguracja skrzynki w [operations.md](operations.md), sekret w [security.md](security.md), testy w [testing.md](testing.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak. Skrzynkę, jej hasło (najlepiej hasło aplikacji) i `MAIL_IN_HOST` ustawia właściciel ([operations.md](operations.md), „Skrzynka dokumentów kosztowych”).

### D-88 Cash-flow na 90 dni

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Cash-flow i rezerwa podatkowa” z tabeli „Produkt i UX” w [roadmap.md](roadmap.md) nie wymaga niczego od właściciela. Rezerwę „do odłożenia dziś” daje już panel „Podatki” (D-76); brakowało zestawienia wypływów do urzędów i ZUS z należnościami i kosztami do zapłaty.
- **Decyzja:**
  - Strona `/cashflow` (`CashFlowController`, usługa `CashFlow`), tylko do odczytu, dla środowiska wdrożenia; horyzont `CashFlow::HORIZON_DAYS` = 90 dni od dziś (stała panelu, nie parametr podatkowy). Link „Cash-flow” na pulpicie.
  - Wypływy: otwarte `obligation` z `amount_due` i terminem do końca horyzontu, także zaległe, w kwocie `amount_due` − `amount_paid`; grupy per miesiąc terminu i typ, zaległe osobno na górze. Otwarte obowiązki płatnicze (`zus`, `ryczalt_advance`, `vat_jpk_v7m`, `wht_payment`, `health_reconciliation`) bez kwoty stoją na liście „bez kwoty jeszcze”, poza saldem.
  - Należności: faktury sprzedaży `VAT`, `ZAL`, `ROZ`, `UPR` w stanie `accepted` albo `corrected`, pomniejszone o `payment` przypisane do faktury w tej samej walucie; korekty `KOR` nie są należnością.
  - Koszty do zapłaty: faktury kosztowe bez `paid_on` z `cost_review` pustym, `business` albo `disputed`.
  - Saldo tylko w PLN (należności PLN − obowiązki − koszty PLN), w `brick/math`. Kwoty w innych walutach stoją osobno, bez przeliczania kursem, bo kurs wpływu nie jest znany. Opis „prognoza, nie porada”; listy do 200 najstarszych pozycji, sumy z wszystkich.
- **Skutki:** `src/Ledger/CashFlow.php`, `src/Controller/CashFlowController.php`, `templates/panel/cashflow.html.twig`, `templates/panel/dashboard.html.twig`; test `CashFlowPanelTest`; [compliance-calendar.md](compliance-calendar.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-87 Raport rok do roku w panelu

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Raporty rok do roku” w [roadmap.md](roadmap.md) („Produkt i UX”) chce przychodu, VAT, kosztów i kursów per miesiąc rok do roku z eksportem CSV oraz nowego `query` dla `render_chart`. Strona panelu nie potrzebuje drugiego roku danych, żeby działać: rok bez danych pokazuje „–”. Nowe zapytanie czatu to nowa wersja `tool_set` ([modules/czat.md](modules/czat.md)), a zestaw ewaluacyjny z „Warstwa AI” w [roadmap.md](roadmap.md) ma chodzić przy każdej zmianie `tool_set`.
- **Decyzja:**
  - Strona `/reports` (`ReportController`, `YearOverYearReport`), tylko do odczytu, parametry `year` (od 2026 do bieżącego, inaczej bieżący) i `currency` (waluta z tabel NBP w bazie, domyślnie EUR).
  - Przychód: suma `revenue_entry.amount_pln` wszystkich rodzajów wierszy (z korektami i różnicami kursowymi) według miesiąca `revenue_date`, jak suma K_8 ewidencji.
  - VAT: `totals` (`vat_due`, `vat_input`, `p51`) najnowszego przyjętego JPK_V7M okresu (najwyższy `seq`, jak P_39 w `JpkV7mBuilder`). Raport nie liczy VAT od nowa: bez przyjętego złożenia miesiąc ma „–”.
  - Koszty: suma `invoice.net_pln` faktur kosztowych z `cost_review` = `business` według miesiąca `issue_date`, liczba faktur i liczba faktur bez kwoty w zł.
  - Kurs: średnia `fx_rate.mid` tabel NBP zapisanych w kokpicie (pobranych do faktur i limitów), z liczbą tabel; to nie średnia wszystkich tabel miesiąca.
  - Różnica rok do roku: dla przychodu i kosztów brak w jednym roku liczy się jako 0; dla P_51 i kursu tylko przy obu wartościach.
  - Wiersz „cały rok” i, dla roku bieżącego, „styczeń do bieżącego miesiąca” obu lat, bo pełny rok poprzedni zawyża różnicę. Kurs w tych wierszach to średnia ze wszystkich tabel okresu.
  - Eksport `?format=csv` (D-78): `raport-rok-do-roku-<rok>-<waluta>.csv`, kolumny `<miara>_<rok poprzedni>`, `<miara>_<rok>` i `<miara>_diff`; wiersze `01`–`12`, `rok` i `od-01-do-<miesiąc>`.
  - Wykres i `query` dla `render_chart` zostają przy „Nowe narzędzia czatu” w [roadmap.md](roadmap.md), razem ze zmianą `tool_set` i zestawem ewaluacyjnym.
- **Skutki:** `src/Ledger/YearOverYearReport.php`, `src/Controller/ReportController.php`, `templates/panel/reports.html.twig`, `templates/panel/dashboard.html.twig`; test `YearOverYearReportPanelTest`; [modules/ewidencja.md](modules/ewidencja.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-86 Kreator zamknięcia roku

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Kreator zamknięcia roku” z tabeli „Produkt i UX” w [roadmap.md](roadmap.md) nie wymaga niczego od właściciela. Obowiązki roczne (`ObligationRules`, kadencja `yearly` i `yearly_entity`), zamknięcia miesięcy (`period`) i złożenia z plikami (`filing`, `stored_file`) już istniały, ale panel nie zbierał roku w jednym miejscu, a pliki złożeń z UPO nie dawały się pobrać razem.
- **Decyzja:**
  - Strona `/year-close` (`YearCloseController`, usługa `YearClose`), tylko do odczytu, dla środowiska wdrożenia. Parametr `?year=` od `YearClose::FIRST_YEAR` (2026, pierwszy rok kokpitu) do bieżącego; domyślnie poprzedni rok, przycięty do zakresu. Link „Zamknięcie roku” na pulpicie.
  - Sekcje: obowiązki roczne za rok N, czyli reguły `yearly` i `yearly_entity` z `nominal_due_date` w roku N+1, ze stanem i powiązanym złożeniem; 12 miesięcy ze stanem `period` (brak wiersza = `open`); złożenia z `period_start` w roku N z liczbą plików `xml_sent` i `upo`; przychód z `revenue_entry` per miesiąc i stawka; klucze `setting` z ostatnim `valid_from` i informacją, czy mają wiersz od 1.01 roku N+1 (stawki, progi i parametry ZUS na nowy rok).
  - Strona niczego nie zamyka: obowiązki zamykasz w kalendarzu, miesiące w złożeniach. Terminy pochodzą z wierszy `obligation`, więc strona nie ma własnej listy dat.
  - Archiwum `GET /year-close/<N>.zip` (`kokpit-rok-<N>.zip`, `Cache-Control: no-store`): `obowiazki-roczne.csv`, `miesiace.csv`, `zlozenia.csv`, `przychody-miesiecznie.csv`, `ewidencja-<N>.csv` (kolumny eksportu D-78, z ochroną przed formułami) i pliki złożeń `zlozenia/<typ>-<okres>-<cel>-<seq>/<kind>-<sha256[:12]>.<ext>`. Każdy plik z `FileStore` musi mieć sha256 z rejestru; inaczej archiwum nie powstaje i komunikat wskazuje `app:files:verify`. Rok spoza zakresu daje 404.
- **Skutki:** `src/Ledger/YearClose.php`, `src/Controller/YearCloseController.php`, `templates/panel/year_close.html.twig`, `templates/panel/dashboard.html.twig`; test `YearClosePanelTest`; [compliance-calendar.md](compliance-calendar.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-85 Panel zdrowia konektorów

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Panel zdrowia konektorów” z tabeli „Ops, bezpieczeństwo, jakość” w [roadmap.md](roadmap.md) nie wymaga niczego od właściciela. Stan konektorów był dotąd widoczny tylko w paczce `kokpit:diagnostics` (D-80) i w logach.
- **Decyzja:**
  - Strona `/connectors` (`ConnectorHealthController`, usługa `ConnectorHealth`), tylko do odczytu, dla środowiska wdrożenia. Okres to dni kalendarzowe Europe/Warsaw do dziś: `?days=1`, `7` (domyślnie) albo `30` (`ConnectorHealth::PERIODS`); inna wartość daje domyślny okres.
  - Podsumowanie per konektor: wywołania, błędy (HTTP ≥ 400 albo `error_code`), 429 i `rate_limited`, średni i p95 czas (`percentile_disc`), ostatnie wywołanie i ostatni błąd.
  - Wykres słupkowy Symfony UX Chart.js (jak D-73) wywołań bez błędu i błędów per dzień, z tabelą tych samych liczb; aktywne blokady `rate_counter` (`blocked_until` w przyszłości); kursory `sync_cursor` (ostatni przebieg, sukces, backfill); ostatnie 50 błędów z hostem, statusem, kodem i `Retry-After`.
  - Bez ścieżek żądań i skrótów treści: ścieżka może nieść NIP albo rachunek (biała lista), a do debugowania jest paczka D-80 po `Redactor`.
  - Migracja `Version20261026000100` dodaje indeks `connector_call_environment_started` pod okna czasowe strony i paczki diagnostycznej. Link „Konektory” na pulpicie.
- **Skutki:** `src/Health/ConnectorHealth.php`, `src/Controller/ConnectorHealthController.php`, `templates/panel/connectors.html.twig`, `templates/panel/dashboard.html.twig`, migracja `Version20261026000100`; test `ConnectorHealthPanelTest`; [operations.md](operations.md), [data-model.md](data-model.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-84 Płatności klientów w panelu

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Eksport CSV każdej listy panelu” z listy „Szybkie wygrane” w [roadmap.md](roadmap.md) zostawiła płatności „razem z ich listą w panelu”. Tabela `payment` (F2.2/F2.3, D-44) miała w panelu tylko liczbę nieprzypisanych na stronie FreshBooks, bez wskazania, które to płatności.
- **Decyzja:**
  - Strona `/payments` (`PaymentController`), tylko do odczytu: ostatnie 200 płatności środowiska według `paid_on` malejąco. Kolumny: data, `freshbooks_payment_id` z `freshbooks_invoice_id`, dokument (numer faktury z `invoice_id` albo `document_number` wiersza ewidencji z `revenue_entry_id`), kwota z walutą, bramka, prowizja, wypłata z dniem, kurs faktyczny, przypisanie (`matched_at`) i różnice kursowe (`nie dotyczy` dla PLN, `zaksięgowane` przy `fx_posted_at`, inaczej `czeka`).
  - Filtr `?unmatched=1` pokazuje płatności bez `matched_at`. Liczba nieprzypisanych na stronie FreshBooks linkuje do filtra; link „Płatności” na pulpicie.
  - Eksport `?format=csv` bez limitu wierszy, jak D-78: `platnosci.csv`, z filtrem `platnosci-nieprzypisane.csv`.
  - Strona niczego nie zmienia: przypisanie i różnice kursowe zostają przy synchronizacji FreshBooks (`PaymentLedger`, `FxDifference`), a `fee_*` i `payout_*` przy Q-24.
- **Skutki:** `src/Controller/PaymentController.php`, `templates/panel/payments.html.twig`, `templates/panel/dashboard.html.twig`, `templates/panel/freshbooks.html.twig`; test `PaymentPanelTest`; [modules/freshbooks.md](modules/freshbooks.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-83 Faktury kosztowe w panelu i dane do przelewu

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Przycisk kopiowania danych przelewu przy »Sprawdź przed przelewem«” z listy „Szybkie wygrane” w [roadmap.md](roadmap.md) nie wymaga niczego od właściciela. `PrePaymentCheck` (F1) miał logikę sprawdzenia i zawiadomienia, ale panel nie miał listy kosztów ani tej akcji. Faktura kosztowa nie przechowuje rachunku dostawcy.
- **Decyzja:**
  - Strona `/costs` (`CostController`): ostatnie 200 faktur kosztowych środowiska (data, numer, dostawca, kwota brutto, numer KSeF, stan przeglądu, `paid_on`) i eksport `?format=csv` (`koszty.csv`, bez limitu, jak D-78). Link „Koszty” na pulpicie.
  - Przy koszcie od dostawcy z PL z NIP formularz „Sprawdź przed przelewem”: rachunek NRB (26 cyfr; podpowiedź z `counterparty.bank_accounts`) i dzień zlecenia (domyślnie dziś). Akcja woła `PrePaymentCheck::check` i przekierowuje na `/costs?invoice=…&check=<verification.id>`. Widok czyta wynik przez `PrePaymentCheck::outcome` z zapisanej `verification` (to środowisko, ten dostawca, `white_list`, z rachunkiem), więc odświeżenie nie woła białej listy. Błąd konektora daje komunikat bez zapisu.
  - `TAK`: dane do przelewu w polach tylko do odczytu z przyciskami „Kopiuj” i „Kopiuj wszystko” (kontroler Stimulus `clipboard`, bez skryptu inline): odbiorca, sprawdzony rachunek, kwota brutto w walucie faktury, tytuł `Faktura <numer>` oraz `; KSeF <numer KSeF>`, gdy faktura go ma (art. 108g ustawy o VAT od 1.01.2027). Bez schowka pole zostaje zaznaczone do ręcznego skopiowania. Przelew zlecasz sam; kokpit niczego nie wysyła.
  - `NIE` albo brak wyniku: ostrzeżenie bez danych do przelewu. Powyżej progu `white_list.pre_payment_threshold_pln` trzy wyjścia; „Dodaj obowiązek zawiadomienia” woła `PrePaymentCheck::chooseNotice` (obowiązek `wl_notice`, jak w F1).
  - Sprawdzenie dotyczy rachunku dostawcy na dzień zlecenia, więc ten sam wynik pokazuje dane do przelewu dla każdej faktury tego dostawcy.
- **Skutki:** `src/Controller/CostController.php`, `src/Counterparty/PrePaymentCheck.php` (`outcome`, `title`, pole `transfer`), `templates/panel/costs.html.twig`, `templates/panel/dashboard.html.twig`, `assets/controllers/clipboard_controller.js`; testy `CostPanelTest`, `RegistryPolicyTest`; [modules/rejestry-kursy.md](modules/rejestry-kursy.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-82 Przegląd tygodnia mailem

- **Status:** przyjęta 2026-10-18 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Poniedziałkowy przegląd mailem” z listy „Szybkie wygrane” w [roadmap.md](roadmap.md) nie wymaga niczego od właściciela. Push i mail przypomnień dotyczą pojedynczych obowiązków; nic nie zbierało stanu kokpitu w jednym miejscu poza panelem.
- **Decyzja:**
  - Zadanie `notifications.weekly_digest` w poniedziałek 7:30 Europe/Warsaw na transporcie `notifications`, po `obligations.recompute` i `credentials.check_expiry`. Wstawia jeden wiersz `notification` (`channel` = `mail`, `category` = `weekly_digest`, bez `obligation_id` i `approval_id`) z kluczem `weekly_digest:<środowisko>:<tydzień ISO>:mail` przez `ON CONFLICT (dedupe_key) DO NOTHING`. Wysyła go `notifications.dispatch`, więc przy `CONNECTORS_ENABLED=0` wiersz czeka jak inne.
  - Liczby środowiska (`WeeklyDigest::KEYS`): `obligations_open`, `obligations_overdue` (termin przed dziś), `obligations_due_soon` (termin od dziś do 7 dni), `approvals_pending`, `approvals_unknown`, `credentials_expiring`, `credentials_expired` (bez `revoked_at`). Okno 7 dni to stała przeglądu (`WeeklyDigest::DUE_SOON_DAYS`), nie parametr podatkowy.
  - Treść wiersza: `weekly_digest <tydzień>: klucz=liczba,…; /`. Dispatcher parsuje ją ściśle (`WeeklyDigest::parse`: znane klucze w stałej kolejności, liczby nieujemne) i wysyła `MailNotifier::digest`: temat „Kokpit: przegląd tygodnia <tydzień>”, wiersze `klucz: liczba` i link `/`. Bez kwot, nazw, terminów i treści pism (D-19). Mail idzie także przy samych zerach, jako znak, że zadanie działa.
  - Tylko mail, bez push: przegląd zastępuje przeglądanie panelu raz w tygodniu, a pilne sprawy mają własne przypomnienia.
- **Skutki:** `src/Calendar/WeeklyDigest.php`, `src/Calendar/NotificationDispatcher.php`, `src/Connector/Mail/MailNotifier.php`, `src/Messenger/ScheduledTaskHandler.php`, `src/Scheduler/DefaultScheduleProvider.php`; migracja `Version20261025000100` (kategoria `weekly_digest`); testy `WeeklyDigestTest`, `SchedulerTest`; [operations.md](operations.md), [compliance-calendar.md](compliance-calendar.md), [data-model.md](data-model.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-81 Widżet „Najbliższe terminy”

- **Status:** przyjęta 2026-10-17 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:** pozycja „Widżet »najbliższe 3 terminy« na stronie startowej panelu” z listy „Szybkie wygrane” w [roadmap.md](roadmap.md) nie wymaga niczego od właściciela. Pulpit pokazywał tylko baner celów, passkeye, samosprawdzenie i ważność poświadczeń; terminy były dopiero w `/calendar`.
- **Decyzja:**
  - Sekcja `#upcoming-deadlines` na `/` pokazuje trzy otwarte obowiązki (`status='open'`) bieżącego środowiska w porządku listy kalendarza (`due_date`, `type`, `dedupe_key`), więc zaległe są na początku i oznaczone „zaległy”. Wiersz: termin z linkiem do wiersza w `/calendar`, rodzaj, okres, notatka, kwota albo komunikat o brakujących parametrach ZUS.
  - Pod listą liczba wszystkich otwartych obowiązków i link do kalendarza; bez akcji na pulpicie, zapis zostaje w `/calendar`.
  - Limit 3 to stała widoku (`PanelController::UPCOMING_LIMIT`), nie parametr podatkowy, więc nie trafia do `setting`.
- **Skutki:** `src/Controller/PanelController.php`, `templates/panel/dashboard.html.twig`; test `UpcomingDeadlinesPanelTest`; nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-80 Paczka diagnostyczna

- **Status:** przyjęta 2026-10-17 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Pozycja „Paczka diagnostyczna `kokpit:diagnostics`” z listy „Szybkie wygrane” w [roadmap.md](roadmap.md) nie wymaga niczego od właściciela. Ryzyko z roadmapy: sekret w paczce, stąd „skaner sekretów na paczce”.
  - Logi aplikacji idą na stderr kontenerów (`monolog`), więc komenda w kontenerze `app` ich nie czyta; ma je `docker compose logs` na hoście.
- **Decyzja:**
  - `kokpit:diagnostics [--days=7] [--logs=<plik>|-]` zapisuje jeden plik `var/diagnostics/kokpit-diagnostics-<data>-<czas>.json` (0600). W środku: wersje PHP, rozszerzeń, wybranych pakietów i PostgreSQL, commit, migracje wykonane wobec plików, wynik `HealthChecker`, kolejki Messenger, `credential_state` bez szyfrogramów i nazw sekretów, aktywne blokady `rate_counter`, podsumowanie `connector_call` środowiska z `--days` dni (1–90) i ostatnie 50 błędów (bez hashy i treści), logi z pliku albo wejścia (ostatnie 2000 wierszy).
  - Każdy tekst przechodzi przez `Redactor::redact`, numery o poprawnej sumie NIP zamieniają się w `[NIP]`, a gotowy JSON sprawdza `FixtureScanner`; znalezisko kończy komendę wynikiem `1` bez pliku.
  - Paczka nie wychodzi sama: wyciągasz ją `docker compose cp` i usuwasz po użyciu ([operations.md](operations.md)).
- **Skutki:** `src/Health/Diagnostics.php`, `src/Command/DiagnosticsCommand.php`, `config/services_base.yaml`, `config/services_panel.yaml`; test `DiagnosticsTest`; [operations.md](operations.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-79 Kotwica łańcucha audytu w tagu snapshotu

- **Status:** przyjęta 2026-10-17 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - `app:audit:verify` sprawdza spójność łańcucha `prev_hash` i `hash`, ale ktoś z dostępem do bazy na serwerze może przeliczyć cały łańcuch od zmienionego wpisu i dostać łańcuch znowu spójny. Pozycja „Kotwica hasha `audit_log` w tagu snapshotu restic” z listy „Szybkie wygrane” w [roadmap.md](roadmap.md) nie wymaga niczego od właściciela.
  - Restic szyfruje repozytorium, także tagi, a snapshoty leżą poza serwerem (Q-41).
- **Decyzja:**
  - `backup.run` przed `pg_dump` czyta głowę łańcucha rolą `kokpit_backup` i dodaje do snapshotu tag `audit-head:<sequence>:<hash>` obok `backup.run`. Pusty łańcuch to `0` i 64 zera. Odczyt przed zrzutem, więc zrzut zawiera wpis z kotwicy. Wynik spoza formatu przerywa backup bez snapshotu.
  - `app:audit:verify --anchor=<kotwica>` (opcja wielokrotna, z prefiksem `audit-head:` albo bez) sprawdza łańcuch i to, że wpis o numerze z kotwicy ma w bazie ten sam hash; niezgodność to wynik `1`, zły format `2`. Komenda wypisuje też bieżącą głowę.
  - Test odtworzenia sprawdza kotwicę odtworzonego snapshotu i najstarszego zachowanego; odtworzenie po awarii sprawdza kotwicę snapshotu, z którego odtwarza ([operations.md](operations.md)).
  - Ping dead-man zostaje bez kotwicy: tag w zaszyfrowanym repozytorium restic wystarcza, a usługa monitoringu nie dostaje hashy.
- **Skutki:** `docker/backup/run-backup.sh`, `src/Audit/AuditLog.php` (`head`, `matchesAnchor`), `src/Command/AuditVerifyCommand.php`; testy `AuditLogTest::testHeadAnchorsMatchOnlyTheirOwnChainEntry`, `BackupScriptTest`; [operations.md](operations.md), [security.md](security.md), nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-78 SBOM i eksport CSV list panelu

- **Status:** przyjęta 2026-10-17 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Po D-77 lista „Szybkie wygrane” w [roadmap.md](roadmap.md) ma dalej pozycje bez wymagań wobec właściciela. SBOM domyka pozycję „`composer audit --locked` i SBOM jako artefakt”; eksport CSV to osobna pozycja.
  - Roadmapa wskazywała wtyczkę `cyclonedx/cyclonedx-php-composer`. Wtyczka to nowa zależność z wykonywanym kodem (`allow-plugins`) i nie widzi pakietów JS z `importmap.php` (D-73), które też trafiają do obrazu PROD.
  - Panel ma listy ewidencji, obowiązków, faktur sprzedaży i złożeń. Kosztów i płatności nie ma jeszcze jako listy w panelu.
- **Decyzja:**
  - `tools/sbom.php` wypisuje SBOM CycloneDX 1.6 JSON (`App\Security\Sbom`): każdy pakiet z `composer.lock` jako `pkg:composer/…` z licencją i adresem repozytorium (`packages` zakres `required`, `packages-dev` zakres `excluded`), pakiety z wersją z `importmap.php` jako `pkg:npm/…` (podścieżki jednego pakietu łączą się). Kilka licencji pakietu to wyrażenie `OR`, jak w Composer. Wersja komponentu głównego to commit przebiegu.
  - Job `lint` zapisuje wynik do `sbom.cdx.json` i wysyła go jako artefakt `sbom` po `composer audit --locked`; brak pliku blokuje job.
  - Listy panelu dostają link „Eksport CSV”, czyli `?format=csv` na tej samej trasie: `/ledger` (cały rok z `year`, plik `ewidencja-<rok>.csv`), `/calendar` (`obowiazki.csv`), `/invoices` (`faktury-sprzedazy.csv`), `/filings` (`zlozenia.csv`). Filtr jak na liście, bez limitu wierszy. Format jak „Uzgodnienia”: średnik, UTF-8, nagłówek z nazwami kolumn z bazy; `Cache-Control: no-store`.
  - Komórka tekstu zaczynająca się od `=`, `+`, `-`, `@`, tabulatora albo CR dostaje apostrof, żeby arkusz nie wykonał jej jako formuły; liczby (także ujemne kwoty) zostają bez zmian. Tę samą ochronę dostaje eksport „Uzgodnień”. Eksport jest za passkeyem jak panel i niesie te same dane co lista.
  - Eksport kosztów i płatności dochodzi razem z ich listami w panelu.
- **Skutki:** `src/Security/Sbom.php`, `tools/sbom.php`, `src/Panel/CsvExport.php`, kontrolery `LedgerController`, `CalendarController`, `InvoiceController`, `FilingController`, `LedgerReconciliation::csv`, szablony list, `.github/workflows/ci.yml`; testy `SbomTest`, `CsvExportTest`, `PanelCsvExportTest`, `CiWorkflowTest`; nazwy w [naming.md](naming.md), [roadmap.md](roadmap.md).
- **Pytania:** brak.

### D-77 Kanał ICS obowiązków i skan zależności

- **Status:** przyjęta 2026-10-17 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Po D-76 priorytety 1, 2 i 4 z [roadmap.md](roadmap.md) czekają na właściciela. Lista „Szybkie wygrane” ma dwie pozycje bez wymagań wobec właściciela: eksport ICS obowiązków i `composer audit --locked` w jobie `lint`.
  - Aplikacje kalendarza pobierają kanał bez sesji i passkeya, więc adres musi nieść sekret. Proxy ma wyłączony `access_log` i `error_log`, więc token w adresie nie trafia do logów nginx.
- **Decyzja:**
  - `GET /calendar-feed/{token}.ics`: otwarte `obligation` środowiska jako wydarzenia całodniowe w dniu `due_date` (UID = identyfikator obowiązku), `SUMMARY` = `type` i miesiąc `period_start`, opis z linkiem do `/calendar`. Bez kwot, notatek, rachunków i kontrahentów, jak treść push (D-19). Wykonane i nieobowiązujące znikają z kanału.
  - Token 32 bajty losowe w base64url z `app:calendar:feed-link` przez SSH; w bazie tylko sha256 w `app_user.calendar_feed_token_hash`. Nowy token unieważnia stary, `--revoke` wyłącza kanał; wydanie i wyłączenie trafiają do `audit_log`. Zły albo wyłączony token daje 404, bez rozróżnienia.
  - Kanał działa tylko tam, gdzie panel: w VPN (D-18). Bez przypomnień `VALARM`: przypomnienia zostają w push.
  - Job `lint` uruchamia `composer audit --locked` po sprawdzeniu licencji; znana podatność w `composer.lock` blokuje merge. SBOM z wiersza roadmapy zostaje na później.
- **Skutki:** `src/Calendar/CalendarFeed.php`, `src/Controller/CalendarFeedController.php`, `src/Command/CalendarFeedLinkCommand.php`, migracja `Version20261024000100`, `config/packages/security.yaml`, `.github/workflows/ci.yml`; testy `CalendarFeedTest` (Unit i Integration) i `CiWorkflowTest`; [security.md](security.md), [data-model.md](data-model.md), nazwy w [naming.md](naming.md).
- **Pytania:** brak.

### D-76 Prognoza progów i rezerwa podatkowa

- **Status:** przyjęta 2026-10-16 (decyzja Claude w ramach pełnej odpowiedzialności za projekt).
- **Kontekst:**
  - Po D-75 priorytety 1 i 2 z [roadmap.md](roadmap.md) dalej czekają na właściciela (PIT-28 ręcznie, Q-02; klucz Stripe tylko do odczytu). Priorytet 4, import wyciągu, wymaga formatu pliku z banku właściciela („format pliku per bank do weryfikacji”), więc też czeka. Priorytet 5 nie wymaga niczego od właściciela.
  - Metryka limitu z D-47 brała kurs z października roku przed rokiem przychodu. Art. 4 ust. 2 ustawy o ryczałcie mówi o pierwszym dniu roboczym października „roku poprzedzającego rok podatkowy” ([Dz.U. 2025 poz. 843](https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf), odczyt 16.10.2026). Przychód 2026 decyduje o ryczałcie i wpłacie co kwartał w 2027 (art. 6 ust. 4, art. 21 ust. 1b), więc kurs to 1.10.2026, nie 1.10.2025.
- **Decyzja:**
  - `TaxCalculator::eurThresholdRate(rok)`: tabela A z pierwszego dnia roboczego października roku przychodu; po tym dniu tabela z innego dnia to błąd jak w D-47. Przed nim (i w sam ten dzień przed publikacją) metryka i prognoza biorą ostatnią tabelę A przed dniem dzisiejszym z flagą `rate_estimated`, a panel pisze „szacunek”. Kwoty urzędowe nie korzystają z tej metody.
  - `ThresholdForecast::forecast(rok)`: podstawa to zakończone miesiące roku, średnia miesiąca do grosza, wartości narastająco do grudnia; prognoza roku to większa z wartości grudnia i przychodu do dziś. W styczniu bieżącego roku wynik pusty; rok zamknięty ma podstawę 12 miesięcy.
  - Limity w euro (`limit_eur`, `watch_limit_eur`, nowe `quarterly_limit_eur`) w złotych do grosza po kursie progów; miesiąc przekroczenia rzeczywisty albo z prognozy. Zdrowotna: ta sama metoda na przychodzie po składkach społecznych z `paid_on`.
  - Do odłożenia dziś: ryczałt narosły w trwającym okresie (`TaxCalculator::advance` na przychodzie do dziś) plus dopłata zdrowotnej za miesiące z `zus_payment` przy progu z prognozy. Bez nowych tabel, zadań i powiadomień; panel „Podatki”, sekcja `threshold-forecast`, z opisem „prognoza, nie porada”.
  - Migracja `Version20261023000100` wstawia `ryczalt.eligibility` od 1.01.2026 (2 000 000, obserwacja 250 000 z UD458, 200 000 EUR), tylko gdy klucza nie ma; wiersz ustawiony wcześniej zostaje.
- **Skutki:** `src/Tax/ThresholdForecast.php`, `src/Tax/TaxCalculator.php` (konstruktor z `BusinessDate`), `SettingKeys`, `SettingController`, `TaxController`, szablony `taxes` i `settings`, migracja `Version20261023000100`, `tests/Unit/ThresholdForecastTest.php`, `tests/Integration/ThresholdForecastTest.php`, `TaxCalculatorTest`, `TaxPanelTest`; reguły w [tax-rules.md](tax-rules.md), nazwy w [naming.md](naming.md). Kryterium `ryczalt-eligibility-metric` (F2) zostaje, jego test sprawdza kurs z 1.10.2026.
- **Pytania:** Q-17, Q-38.

### D-75 Obserwacja prawa automatyczna

- **Status:** przyjęta 2026-10-16.
- **Kontekst:**
  - Tabele „Akty prawne” i „Obserwacja prawa” w [facts.md](facts.md) oraz ryzyka R-01, R-10 i R-29 zależały od ręcznych sprawdzeń w API ELI Sejmu. [roadmap.md](roadmap.md) stawia automat na 3. miejscu priorytetów po F6, z warunkiem „host `api.sejm.gov.pl` tylko `GET` w allowliście”.
  - Kod faz F0a–F6 jest na `main`; reszta odbiorów czeka na działania właściciela. Od 16.10.2026 sieć sesji przepuszcza `api.sejm.gov.pl`.
  - Priorytety 1 i 2 z [roadmap.md](roadmap.md) czekają na właściciela: PIT-28 zostaje dziś wysyłany ręcznie (Q-02), a Stripe wymaga klucza tylko do odczytu. Priorytet 3 nie wymaga niczego od właściciela.
  - Powiązania ELI wiszą przy akcie bazowym (np. ustawa o VAT to `DU/2004/535`), a nie przy obwieszczeniu tekstu jednolitego. Strumień `/eli/changes/acts` daje setki zmian tygodniowo w całym Dzienniku Ustaw, więc filtr po liście aktów jest tańszy niż przegląd strumienia.
- **Decyzja:**
  - Konektor `eli` (`src/Connector/Eli`): `references(ELI)` i `process(termN/numer)`, adres z `eli.base_url`, tylko `GET`, licznik `eli.read` (100 na dobę), bez klucza i bez celu testowego. `HostAllowlist` dopuszcza `api.sejm.gov.pl` tylko dla `eli` i tylko `GET`.
  - Lista pozycji w konfiguracji `legal_watch.items`: 14 aktów bazowych z tabeli „Akty prawne” i 2 procesy z tabeli „Obserwacja prawa” (druki 3147 i 2453). Obserwowane kategorie powiązań w `legal_watch.act_categories`: akty zmieniające, informacje o tekście jednolitym, akty wykonawcze, wyroki TK, uchylenie aktu.
  - Zadanie `legal_watch.check` w poniedziałek 6:30 Europe/Warsaw na transporcie `reads`. Pierwszy odczyt zapisuje stan w `legal_watch` bez alertu. Nowe pozycje dają wiersz `legal_watch_hit` i push `legal_change` (treść: nazwa pozycji i odnośnik, klucz `legal_watch:<id>:<sha256 stanu>:push`). Zniknięcia z listy API zmieniają stan bez alertu. Błąd jednej pozycji daje `connector_failure` (`eli`) i nie zmienia jej stanu.
  - `app:legal-watch report` daje szkic wierszy tabeli „Obserwacja prawa” ze stanem „do oceny”; `--mark-reviewed` zamyka trafienia. Skutek i plik-dom ocenia człowiek; kokpit nie zmienia sam reguł ani parametrów.
  - Nagrania w `tests/Fixtures/Eli` z odczytów 16.10.2026 (dwa akty i druk 2453) oraz trzy ręcznie zmienione, fikcyjne warianty (akt zmieniający Dz.U. 2099 poz. 1, nowy etap procesu, 404). Dane są publiczne i bez danych osobowych; nagrania przeszły skaner bez `Scrubber`, który podmienia `displayAddress` jak adres osoby (jak D-74).
  - Próbny przebieg `app:legal-watch check` na API 16.10.2026: 16 pozycji, 16 wywołań z HTTP 200, bez błędów.
- **Skutki:** `src/Connector/Eli/EliConnector.php`, `src/LegalWatch/`, `src/Command/LegalWatchCommand.php`, `src/Messenger/LegalWatchHandler.php`, migracja `Version20261022000100`, `tests/Integration/LegalWatchTest.php`, `tests/Unit/EliConnectorParseTest.php`; [modules/obserwacja-prawa.md](modules/obserwacja-prawa.md), nazwy w [naming.md](naming.md), konektor w [architecture.md](architecture.md), zadanie w [operations.md](operations.md), tabele w [data-model.md](data-model.md).

### D-74 F1: rejestry i NBP na nagraniach z hostów testowych

- **Status:** przyjęta 2026-10-16.
- **Kontekst:**
  - Wiersze F1 `nbp-rate`, `pre-payment-*`, `refresh-due-eu-supplier`, `self-check`, `vies-live` i `white-list-live` czekały na dostęp sesji do `wykazpodatnikowvat-test.mf.gov.pl`, `ec.europa.eu` i `api.nbp.pl`. Od 16.10.2026 sieć sesji je przepuszcza.
  - Usługa testowa VIES odpowiada na numery testowe (100 `valid`, 200 `invalid`, 201 `INVALID_INPUT`, 300 `SERVICE_UNAVAILABLE`), a na każdy inny numer `SERVICE_UNAVAILABLE`. `requestIdentifier` jest zawsze pusty, także z numerem pytającego.
  - Przed wykazem (także testowym) stoi Imperva. Klient HTTP z adresu IP chmury dostaje stronę HTML z wyzwaniem JS i HTTP 200, a przeglądarka po wyzwaniu dostaje JSON. Odpowiedzi API mają kształt ze specyfikacji 1.6.1 z 12.10.2026; Wykaz ruszył 15.10.2026, nie 12.10.
  - Reguła kursu (docs/modules/rejestry-kursy.md, krok 5) każe dać alert przy pustym zakresie NBP; `FxRateProvider` tylko rzucał wyjątek.
- **Decyzja:**
  - Nagrania w `tests/Fixtures/Nbp`, `tests/Fixtures/Vies` i `tests/Fixtures/WhiteList`, z jednorazowych odczytów 16.10.2026: tabela A EUR 19–28.12.2025, 404 dla 1.01.2026 i waluty spoza tabeli; numery testowe VIES z fikcyjnym numerem pytającego; `search` i `check` fikcyjnych NIP i rachunków z `FictionalIdentifiers` (wykaz testowy ich nie zna: `subject` null, `NIE`). Rachunek w ścieżce nagrania to `{{account:N}}`, bo skaner pada na 26 cyfrach. Dane są fikcyjne z konstrukcji, więc nagrania przeszły skaner bez `Scrubber`, który podmienia klucze z `account` i `name` (`accountAssigned`, nazwa przykładowa usługi testowej). NBP nie ma celu testowego i danych osobowych; odczyt z sesji zamiast `kokpit-dev` dał ten sam publiczny plik.
  - Odtwarzanie przez `KsefRecordings` (kolejka po metodzie i ścieżce) za `HostAllowlist`; `RegistryTestTools::play`. Kryteria `pre-payment-*`, `refresh-due-eu-supplier`, `self-check` i `nbp-rate` idą na tych nagraniach; testy mechaniki na odpowiedziach z dokumentacji zostają.
  - Usługa testowa VIES nie daje `requestIdentifier`, więc `vies-live` i `refresh-due-eu-supplier` sprawdzają pusty `request_identifier`, numer pytającego w żądaniu i sha256 `verification_raw`. Wypełniony identyfikator sprawdza pierwsze zapytanie na PROD przy go-live F1.
  - `ConnectorError::WafChallenge` (`waf_challenge`): `WhiteListConnector` rozpoznaje stronę Imperva (`_Incapsula_Resource`) przed oceną statusu, więc panel nie pokazuje jej jako zwykłego `http_error`. `white-list-live` uruchamia job `live` w GitHub Actions; z IP chmury sesji test kończy się `waf_challenge`. Gdy także adres VPS dostanie wyzwanie, zapasem jest plik płaski (R-06).
  - `FxRateProvider` dostaje `RegistryAlerts`: pusty zakres daje push `connector_failure` (`nbp`, `empty_range`, klucz waluta i data) obok `FxRateUnavailable`.
- **Skutki:** `tests/Acceptance/f1.yaml` (7 wierszy), `tests/Integration/FxRateProviderNbpRecordingTest.php`, `RegistryPolicyTest`, `tests/Live/ViesTestServiceLiveTest.php`, `tests/Live/WhiteListTestHostLiveTest.php`, `src/Fx/FxRateProvider.php`, `src/Http/ConnectorError.php`, `src/Connector/WhiteList/WhiteListConnector.php`; fakty białej listy, VIES i NBP w [facts.md](facts.md).

### D-73 JavaScript panelu i wykres czatu

- **Status:** przyjęta 2026-10-15.
- **Kontekst:** D-61 zostawił wykres jako znaczniki kontrolera Symfony UX Chart.js bez JavaScriptu panelu. Panel miał CSP `default-src 'self'`, więc importmap (skrypt inline) i styl inline czatu nie działały. Sandbox sesji ma Packagist, git z GitHub i registry.npmjs.org, ale nie ma jsDelivr, z którego `importmap:require` pobiera pakiety.
- **Decyzja:**
  - `symfony/ux-chartjs` 2.36 (MIT, jak `symfony/ux-turbo`), `ChartjsBundle`; `ChatChartData::chart()` daje model `Chart`, szablon woła `render_chart()`. Dane bez zmian (D-61).
  - `importmap.php`: `app`, `@hotwired/stimulus` 3.2.2, `@hotwired/turbo` 8.0.23, `@symfony/stimulus-bundle`, `chart.js` 4.5.1 z fragmentem `chart.js/chunks/helpers.dataset.js`, `@kurkle/color` 0.3.4 (wersja z zależności Chart.js). Pliki leżą w `assets/vendor` w repo, pobrane z registry.npmjs.org ze sprawdzeniem `integrity` (sha512); licencje MIT. Jedyna zmiana względem npm: Chart.js importuje swój fragment nazwą zamiast ścieżki względnej, bo ładuje się leniwie. `importmap_polyfill: false`, więc żadnego CDN.
  - `assets/app.js` startuje Stimulus i Turbo z `Turbo.session.drive = false`: działają ramki (`chat-turn`), a linki, formularze i passkey zachowują się jak bez JavaScriptu. Kontroler `@symfony/ux-chartjs/chart` jest leniwy, więc Chart.js ładuje się tylko przy wykresie.
  - CSP: `CspNonce` daje nonce na żądanie (`csp_nonce()` w `base.html.twig` dla importmap i `meta csp-nonce` Turbo), a `SecurityHeadersSubscriber` dopisuje go do `script-src` i `style-src` tylko wtedy, gdy strona go użyła. Bez `unsafe-inline`. Styl stanu ładowania czatu przeniesiony do `assets/styles/chat.css`.
  - Sprawdzenie: `PanelImportmapTest` (nonce w CSP i w skryptach, każdy wpis importmap to plik z repo, kontroler wykresu leniwy). Przebieg w Chromium bez sieci zewnętrznej na stronie `/chat` z wykresem `revenue_by_month`: wykres narysowany, zero błędów CSP i 404.
- **Skutki:** `composer.json` (`symfony/ux-chartjs`), `config/bundles.php`, `config/packages/framework.yaml`, `importmap.php`, `assets/`, `src/Panel/CspNonce.php`, `SecurityHeadersSubscriber`, szablony czatu i `base.html.twig`; `.gitignore`: `/public/assets/` (wynik `asset-map:compile` w obrazie) i `/config/reference.php` (plik generowany przez Symfony 7.4).

### D-72 F1: poświadczenia KSeF, zapas certyfikatem i klucze MF

- **Status:** przyjęta 2026-10-15 (przebiegi live na `api-test.ksef.mf.gov.pl`; dane fikcyjne).
- **Kontekst:**
  - Plik fazy zakładał dane TE przez `/testdata/subject`. Ten endpoint zakłada tylko grupę VAT, JST i organ egzekucyjny; JDG to `/testdata/person` (NIP i PESEL). TE przyjmuje jednak samopodpisaną pieczęć `VATPL-<NIP>` dla dowolnego poprawnego NIP (D-70), więc osobne zakładanie podmiotu nie jest potrzebne.
  - Zapas certyfikatem typ 1 był w dokumentacji, ale bez kodu: `KsefAuthenticator` znał tylko token.
  - `mf-keys-refresh` zakładał kopię kluczy MF w konfiguracji celu. `KsefApi` (D-70) pobiera je raz na proces z `GET /security/public-key-certificates`, więc kopia byłaby drugim źródłem prawdy.
- **Decyzja:**
  - `KsefXades` (z pomocnika testów do `src/Connector/Ksef`) buduje i podpisuje `AuthTokenRequest` (XAdES-BES, RSA-SHA256). `KsefAuthenticator::certificateAccessToken()` uwierzytelnia certyfikatem z `ksef_auth_cert` (sekret `KSEF_<CEL>_AUTH_CERT`: certyfikat i klucz prywatny w PEM) przez `POST /auth/xades-signature` (operacja `auth_xades`), dalej ten sam status i redeem co token; para trafia do `ksef_jwt_write`. Gdy sejf nie ma sekretu tokena roli, `accessToken()` idzie tą drogą sam. `app:credential:smoke ksef_auth_cert` sprawdza zapas.
  - Na TE certyfikat to samopodpisana pieczęć z przebiegu. Na PROD certyfikat typ 1 wnioskuje i wgrywa do sejfu `prod` tylko właściciel (Aplikacja Podatnika KSeF); sesja go nie wytwarza.
  - `ksef-te-provision`: `KsefTeProvision` zakłada token `InvoiceRead` i token `InvoiceWrite` testowej JDG oraz token drugiego sprzedawcy; parametr uprawnień w `token()`.
  - `ksef-read-token-scope`: accessToken z tokena `InvoiceRead` dostaje 403 na `POST /sessions/online`; zero faktur.
  - `mf-keys-refresh`: bez kopii kluczy i bez zadania dla KSeF. `KsefApi::publicKey` bierze certyfikat ważny w tej chwili (`validFrom` ≤ teraz < `validTo`), przy kilku najnowszy; brak ważnego daje `mf_key_missing`. Kryterium sprawdza wybór na odpowiedzi w kształcie nagrania `te-public-keys`. Zadanie `mf_keys.refresh` zostaje tylko dla certyfikatu JPK (D-69).
  - `ksef-backfill-windows`, `ksef-21165-retry`, `ksef-429-blocked-until` zostają na atrapie bramki (`KsefSyncTest`): TE nie daje na żądanie 21165, 429 ani historii od 1.02.2026.
- **Wyniki 15.10.2026:** pięć testów `KsefTeCredentialsTest` zielonych na api-test (tokeny, rejestracja dat z obowiązkami `credential_expiry`, smoke tokenem do odczytu, smoke i zapas XAdES, odmowa 403).
- **Skutki:**
  - Kod: `KsefXades`, `KsefAuthenticator::certificateAccessToken`, `CredentialSmokeCommand` (`ksef_auth_cert`), `KsefApi` (zegar, okno ważności kluczy).
  - Testy: `tests/Live/KsefTeCredentialsTest.php`, `tests/Integration/MfKeysRefreshTest.php`; `KsefTeProvision::token($nip, $permissions)`, `seal()`, `pem()`.
  - Rejestr `tests/Acceptance/f1.yaml`: dziewięć wierszy KSeF sesji 1.
  - Poprawka: `EvidenceArtefacts` i `EvidencePack` przyjmują katalog `ksef/` (XML, UPO i numery z api-test, F3.5); wcześniej zapis z `EVIDENCE_DIR` kończył test F3.5 błędem ścieżki.

### D-71 F1: eksport Subject1 i Subject2 na api-test

- **Status:** przyjęta 2026-10-15 (przebiegi live z sesji z dostępem do `api-test.ksef.mf.gov.pl`; dane fikcyjne).
- **Kontekst:**
  - `KsefSync` (F1, D-42) działał tylko na `FakeKsefGateway`; `HttpKsefGateway` z D-70 dawał przy eksporcie `GatewayUnavailable`.
  - Eksport wymaga klucza AES zaszyfrowanego certyfikatem MF `SymmetricKeyEncryption`; paczkę dzieli KSeF na części szyfrowane tym kluczem ([KSeF API](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json), [przyrostowe pobieranie](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/pobieranie-faktur/przyrostowe-pobieranie-faktur.md)).
- **Decyzja:**
  - `startExport`: `POST /invoices/exports` (grupa `ksef.export`) z `PermanentStorage`, `restrictToPermanentStorageHwmDate` = `true`, `compressionType` = `Zip`, losowym kluczem AES i IV; token `ksef_token_read` (`KsefAuthenticator::accessToken('read')`). Klucz żyje w pamięci procesu, więc `KsefSync` woła oba kroki w jednym przebiegu (tak już działa).
  - `exportPackage`: `GET /invoices/exports/{referenceNumber}` (grupa `ksef.export_status`); 100 → `null`; 200 → części po `ordinalNumber` z adresu ze statusu (operacja `export_part`, grupa `ksef.export_part`, bez tokenu, przez `HostAllowlist`), odszyfrowanie, `partHash`, złączenie, ZIP: `{ksefNumber}.xml` i daty z `_metadata.json`; 420 (`from` po `PermanentStorageHwmDate`) → pusta paczka z kursorem na `from`; inny kod → `ExportFailed` z kodem.
  - Na TE adres części to `https://api-test.ksef.mf.gov.pl/storage/...` z podpisem SAS w zapytaniu, więc nie trzeba nowego hosta. Host części PROD sprawdza go-live F1; inny host zatrzyma pobranie na `HostAllowlist`.
  - `ksef-sync-te`: `tests/Live/KsefTeSyncTest` zakłada testową JDG i drugiego sprzedawcę z losowymi NIP (D-70), `KsefTeIssuer` w `tests/` wystawia 3 faktury dla JDG i 2 faktury JDG poza bramką (jak przewiduje plik fazy), potem `KsefSync` pobiera obie role. Wynik 15.10.2026: 3 koszty `imported`, 2 sprzedaże `accepted` bez `approval`; faktury pojawiają się w eksporcie po około 2 minutach (trwały zapis).
  - Nagranie `tests/Fixtures/Ksef/te-sync-export.json` trzyma odpowiedzi startu i statusu oraz metadane faktur po `Scrubber`; NIP przebiegu zastępują przed tym dwa różne NIP z listy fikcyjnych. XML faktur to złoty plik `pl_b2b` pomocnika, więc `KsefTeSyncRecordingTest` składa go z metadanych, buduje ZIP i szyfruje kluczem odszyfrowanym z żądania (fikcyjny certyfikat MF). Ten test to wariant kryterium `ksef-sync-idempotent` na nagraniu TE.
- **Skutki:**
  - Kod: `HttpKsefGateway` (eksport), `KsefApi::exportPart`.
  - Testy: `tests/Live/KsefTeSyncTest.php`, `tests/Support/KsefTeIssuer.php`, `tests/Integration/KsefTeSyncRecordingTest.php`; `LiveRecording::writeData`; `KsefSendTestTools::fictionalJwt($kind)`.
  - Rejestr `tests/Acceptance/f1.yaml`: `ksef-sync-te` i `ksef-sync-idempotent`. Pozostałe wiersze KSeF sesji 1 domyka D-72.

