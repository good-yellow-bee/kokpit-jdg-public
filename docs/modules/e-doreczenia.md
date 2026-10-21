# e-Doręczenia

Kanały doręczeń, procedura ręczna i odbiór bez zastępcy. API (wykonalność PROD, INT, UA API v3, odbiór, fikcja, cykl życia pisma) jako odłożony dodatek do F4. Stan na 8 października 2026.

## Status: API odłożone (D-29)

| Element | Teraz | Po powrocie F4 |
| --- | --- | --- |
| odbiór pism | powiadomienia mailowe dostawcy, odbiór ręczny w aplikacji e-Doręczeń | `list()` i `receive()` przez UA API |
| terminy | termin z pisma wpisujesz ręcznie jako `edelivery_reply_due` | `edelivery_deemed` z API, `edelivery_reply_due` ze sprawy |
| inne kanały | `manual_channel_check` co tydzień od F0b | bez zmian |
| zastępca | brak: pisma odbierasz sam (D-107) | bez zmian |
| wniosek INT, certyfikat kwalifikowany, pytanie do COI | nie teraz | po F5, gdy bramki 1–2 mają Twoje odpowiedzi (Q-30) |

- Powrót wymaga nowego rekordu D-xx. Warunek stoi w [../roadmap.md](../roadmap.md).
- Nazwy API są zarezerwowane w [../naming.md](../naming.md) jako odłożone: `edelivery_message`, `edelivery_case`, `edelivery_deemed`, `edelivery_new`, konektor `edoreczenia`, `EDORECZENIA_TARGET`, `edoreczenia.api`, `edoreczenia_*`, środowisko `int`. Nie powstają w kodzie.
- Sekcje od „Dodatek: projekt F4” w dół to projekt na powrót. Sesja F0b ich nie czyta.

## Jak czytać

- F0b czyta „Obowiązek już biegnie”, „Odbiór bez zastępcy”, „Kanały doręczeń” i „Procedura ręczna”. Z nich powstają `manual_channel_check` i ręczny `edelivery_reply_due`.
- Bramki 1–2 z dodatku zapisujesz Ty, poza kodem.
- Przy powrocie F4 sesja czyta cały plik. Semantyka akcji „Odbierz” (step-up, wpis w `audit_log`) jest w „Odbiorze i fikcji doręczenia” niżej.
- Kolumny `edelivery_message` i `edelivery_case` są w [../data-model.md](../data-model.md) jako odłożone. Ten plik jest domem ich stanów.
- Terminy i przypomnienia liczy `ObligationEngine` ([../compliance-calendar.md](../compliance-calendar.md)).

## Obowiązek już biegnie

Firmy z CEIDG wpisane przed 1.01.2025 muszą mieć ADE wpisany do BAE od 1.10.2026 ([gov.pl](https://www.gov.pl/web/e-doreczenia/e-doreczenia-1-pazdziernika-2026-r-wazny-termin-dla-przedsiebiorcow-z-ceidg); art. 9 ust. 1 pkt 9 i art. 152 ust. 3 [ustawy o doręczeniach elektronicznych, Dz.U. 2026 poz. 3](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf)). Pisma mogą już wpływać, a fikcja doręczenia biegnie niezależnie od kokpitu.

Obowiązek pokrywa procedura ręczna z tego pliku i sekcja „Teraz – zgodność” w [../README.md](../README.md). Warunek (Q-13, D-107): ADE aktywny, powiadomienia mailowe przetestowane, codzienny podgląd skrzynki.

Polling metadanych przez API odpada do powrotu F4. PROD wymaga certyfikatu kwalifikowanego i bramek z dodatku (D-29).

## Odbiór bez zastępcy

Pisma ze skrzynki ADE odbierasz tylko Ty (D-107). Administratora skrzynki ani innego zastępcy nie ma i dokumentacja go nie zakłada, także dla innych użytkowników kokpitu.

| Element | Reguła |
| --- | --- |
| Zabezpieczenie | powiadomienia mailowe dostawcy ADE i codzienny podgląd skrzynki w aplikacji e-Doręczeń |
| Nieobecność | pismo od podmiotu publicznego nieodebrane w 14 dni od zawiadomienia jest doręczone fikcją (art. 42 ust. 2 [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf)) także wtedy, gdy Cię nie ma; ryzyko zaakceptowane (D-107; R-11, R-25 w [../facts.md](../facts.md)) |
| Przed dłuższą nieobecnością | sprawdzasz skrzynkę i odbierasz pisma; deklaracje składasz z wyprzedzeniem ([../operations.md](../operations.md), „Break-glass i zastępca”) |
| Po powrocie | odbierasz zaległe pisma; terminy liczysz od daty odbioru albo fikcji i wpisujesz do kalendarza („Procedura ręczna” niżej) |

## Kanały doręczeń

Każdy kanał ma swój skutek prawny. Kokpit nie monitoruje żadnego kanału przez API do powrotu F4.

| Kanał | Skutek prawny | Monitoring teraz | Po powrocie F4 | Kto |
| --- | --- | --- | --- | --- |
| ADE firmy (BAE) | fikcja doręczenia pism od podmiotów publicznych (art. 42 ust. 2 [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf)) | powiadomienia mailowe dostawcy, Twój codzienny podgląd w aplikacji e-Doręczeń; termin z pisma ręcznie do kalendarza | przy spełnionych bramkach PROD: polling `list()` co 15 min, push, obowiązek `edelivery_deemed`; powiadomienia mailowe zostają na stałe | Ty |
| prywatny ADE (osoba fizyczna) | osobny adres (art. 32 ust. 2 [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf)); żaden przepis nie mówi, który adres wybierze urząd | powiadomienia mailowe i przypomnienie `manual_channel_check` raz w tygodniu | bez zmian: API dla skrzynki prywatnej niezweryfikowane (instrukcje INT v1.9.7 i PROD v1.9 tego nie opisują); pytanie do COI razem z Q-30 ([../facts.md](../facts.md)) | Ty |
| konto w e-Urzędzie Skarbowym | doręczenie na konto po zgodzie na e-Korespondencję (art. 144 § 1a [Ordynacji podatkowej](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf); art. 35e [ustawy o KAS](https://api.sejm.gov.pl/eli/acts/DU/2025/1131/text.pdf)); własna fikcja 14 dni; w części procedur obowiązkowe (np. art. 14zc Ordynacji) | przypomnienie `manual_channel_check` raz w tygodniu; mail z powiadomieniem daje obowiązek na dziś (D-109) | bez zmian; API brak, scraping poza zakresem | Ty |
| skrzynka eZUS | powiadomienie mailem albo SMS-em; każdy płatnik ją ma; czy działa fikcja – niezweryfikowane | przypomnienie `manual_channel_check` raz w tygodniu; mail z powiadomieniem daje obowiązek na dziś (D-109) | bez zmian; API brak | Ty |

- Przypomnienia `manual_channel_check` tworzy `ObligationEngine` od F0b, jedno na kanał i tydzień.
- Konektor poczty przychodzącej `mail_in` (D-92) czyta tylko dedykowaną skrzynkę, tylko do odczytu. Od D-109 rozpoznaje w niej maile z powiadomieniami e-US, eZUS i e-Doręczeń po dokładnym nadawcy i temacie (`mail_in.notices`) i tworzy obowiązek `manual_channel_check` na dzień pobrania, z kanałem, nadawcą i tematem w notatce. Powiadomienia trafiają tam Twoją regułą przekazywania ([../operations.md](../operations.md), „Skrzynka dokumentów kosztowych”).
- Mail z powiadomieniem nie jest treścią pisma ani jego odbiorem. Kokpit nie czyta treści maila, nie otwiera linków i nie łączy się z hostami urzędów; do kanału logujesz się sam, ze znanego adresu. Fałszywy mail daje najwyżej zbędne sprawdzenie kanału; maila bez rozpoznania (np. nowy adres nadawcy) nie widzi nikt poza Tobą, więc cotygodniowy `manual_channel_check` i powiadomienia dostawcy zostają.
- Aktywność prywatnego ADE i zgoda na e-Korespondencję: Q-13. Domyślnie oba kanały sprawdzasz ręcznie.
- Reguł automatycznego przekazywania pism do innych systemów (art. 19 ust. 6 pkt 1 lit. b [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf)) nie ustawiasz: przekazanie treści może oznaczać odbiór (art. 41 ust. 2).

## Procedura ręczna

Obowiązuje od teraz do powrotu F4. Kokpit niczego nie pobiera; przechowuje tylko termin, który wpiszesz.

| Krok | Kto | Co |
| --- | --- | --- |
| 1 | dostawca ADE | mail o nowym zawiadomieniu; od zawiadomienia biegnie 14 dni fikcji dla pism od podmiotów publicznych (art. 42 ust. 2 [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf)) |
| 2 | Ty | logowanie do aplikacji e-Doręczeń; podgląd listy to jeszcze nie odbiór |
| 3 | Ty | otwarcie pisma to odbiór z dowodem otrzymania (art. 41 ust. 2); od tej daty liczysz terminy z pisma |
| 4 | Ty | odczyt terminu z treści pisma; termin względny („14 dni od doręczenia”) liczysz od daty odbioru |
| 5 | Ty | wpis w panelu: obowiązek `edelivery_reply_due` z terminem, datą doręczenia, nadawcą i sygnaturą; przypomnienia T−7, T−3, T−1, T0 ([../compliance-calendar.md](../compliance-calendar.md)) |
| 6 | Ty | odpowiedź wysyłasz z aplikacji e-Doręczeń; obowiązek zamykasz ręcznie (`done`) |

- Pismo nieodebrane po 14 dniach jest doręczone fikcją. Terminy liczysz wtedy od daty fikcji, a odbiór robisz i tak.
- Polityka przesunięcia `edelivery_reply_due` to `none`: wpisujesz dokładny dzień, bez przeliczania przez `PolishCalendar`.
- Termin z pisma inny niż odpowiedź (np. wpłata): typ obowiązku rozstrzyga [../compliance-calendar.md](../compliance-calendar.md).
- Dowody (D.1, E.1), treść i załączniki zostają w aplikacji e-Doręczeń. Kokpit nie ma na nie tabeli do powrotu F4.
- Pole na opis pisma i klucz deduplikacji bez `edelivery_case_id` definiują [../compliance-calendar.md](../compliance-calendar.md) i [../data-model.md](../data-model.md).
- Odczyt daty fikcji do wyświetlania zostaje w przeglądzie doradcy (Q-29); do powrotu F4 datę czytasz z aplikacji.

## Dodatek: projekt F4 (odłożony)

Wszystko niżej to projekt konektora na powrót F4 (D-29). Fakty sprawdzone 8.10.2026; przy powrocie sprawdzasz wersje API i instrukcji ponownie (R-03 w [../facts.md](../facts.md)).

### Wykonalność PROD

API PROD wymaga certyfikatu kwalifikowanego. Podmiot niepubliczny kupuje go u kwalifikowanego dostawcy usług zaufania, bo darmowe certyfikaty działają tylko na INT ([instrukcja PROD v1.9, §1.2](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce); [instrukcja INT v1.9.7](https://www.gov.pl/attachment/b0bbf5ef-8909-43ff-851d-d1183e96d02a)). Wykonalność dla JDG nie jest potwierdzona.

**Bramki w kolejności.** Każda kolejna ma sens tylko po spełnieniu poprzedniej. Właściciel: Ty (Q-30). Bramki 1 i 2 zapisujesz przy logowaniu do skrzynki; bramki 3 i 4 dopiero przy powrocie po F5.

| Nr | Bramka | Jak sprawdzić | Przy porażce |
| --- | --- | --- | --- |
| 1 | typ dostawcy ADE | zapisz, czy ADE prowadzi dostawca publiczny (Poczta Polska), czy kwalifikowany ([lista dostawców](https://www.gov.pl/web/e-doreczenia/kwalifikowani-dostawcy-uslug-kdu3)) | dostawca kwalifikowany ma własne API i onboarding; ten dodatek zakłada dostawcę publicznego |
| 2 | zakładka Systemy | zaloguj się na skrzynkę firmy i sprawdź Uprawnienia → Systemy oraz rolę właściciela albo administratora; dla JDG niezweryfikowane | brak systemu = brak API |
| 3 | typ certyfikatu | pieczęć kwalifikowaną tworzy tylko osoba prawna ([eIDAS, art. 3 pkt 24](https://eur-lex.europa.eu/eli/reg/2014/910/oj)); podpis kwalifikowany z NIP w Subject – niezweryfikowany; pytasz COI | brak akceptowanego typu = brak API |
| 4 | zakup | certyfikat na własny CSR; klucz zostaje plikiem w sejfie; koszt stały wraca do D-09 | odmowa kosztu = brak API |

- Wymagania certyfikatu: RSA ≥ 2048 albo ECDSA P-256 lub mocniejszy, `keyUsage` = `digitalSignature`, Subject wskazuje właściciela przez NIP albo REGON ([załącznik 1c, §3.8](https://www.gov.pl/attachment/0c5a5272-b629-4a57-8c16-7935c6cb7037)).
- Jeden certyfikat obsługuje jeden system ([instrukcja PROD v1.9](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce)).
- **Porażka dowolnej bramki:** F4 nie wraca. Zostaje procedura ręczna z tego pliku.

**Rejestracja systemu**

| Krok | INT | PROD |
| --- | --- | --- |
| 1 | na testowej skrzynce firmy: Moduł uprawnień → Systemy → nowy system | na skrzynce firmy: Moduł uprawnień → Systemy → nowy system |
| 2 | „żądanie certyfikatu”: wgrywasz CSR, dostajesz certyfikat testowy | wgrywasz kwalifikowany `.crt` jako kwalifikowany środek uwierzytelniający |
| 3 | certyfikat testowy i klucz prywatny przez `SecretStore` (`edoreczenia_qualified_cert`, cel `int`), data ważności w `credential_state` | certyfikat i klucz przez `SecretStore` (`edoreczenia_qualified_cert`), data ważności w `credential_state` |

- Odnowienie wyznacza data ważności certyfikatu. Przypomnienia o wygaśnięciu: [../security.md](../security.md) i [../operations.md](../operations.md).
- Odcięcie po incydencie: najpierw usuwasz system w Module uprawnień, potem unieważniasz certyfikat u dostawcy.

### Środowisko INT

Dostęp INT ma okno czasowe. Wniosek składasz ok. 2 tygodnie przed startem F4 po powrocie, nie wcześniej ([regulamin INT](https://www.gov.pl/attachment/f8d3d63b-74ca-4c89-b35b-773eaf2a924a)).

| Zasada | Wartość |
| --- | --- |
| Wniosek | formularz 2a albo 2b podpisany QES lub PZ, mail na test.edoreczenia@cyfra.gov.pl |
| Decyzja | do 10 dni roboczych |
| Czas dostępu | co najmniej 30 dni |
| Kolejny wniosek | dopiero 90 dni po przyznaniu, czyli ok. 60 dni przerwy po wygaśnięciu |
| Sieć | biała lista zgłoszonych publicznych IP serwera i testera; stały IPv4 serwera potrzebny dopiero wtedy (Q-14, D-18); tester = serwer (testy `@group live` przez SSH, bo runner CI nie ma stałego IP) albo stałe IP wyjściowe VPN |
| Skrzynki testowe | do 6 ADE testowych, żaden typu JDG |

- We wniosku prosisz o ADE testowe urzędu (nadawca publiczny) i ADE testowe firmy.
- COI pytasz, który typ skrzynki testowej odpowiada JDG.
- Bez ADE urzędu nie da się sprawdzić zawiadomienia i fikcji doręczenia.

### API i uwierzytelnienie

**Wersje.** Obowiązuje UA API 3 (`3.0.8.yaml`). Wersje 1 i 2 wyłączono 26.11.2025 na INT i 11.01.2026 na PROD ([Interfejsy API](https://www.gov.pl/web/e-doreczenia/interfejsy-api)). Aktualne są SE API 2, 3 i 4; projekt używa SE API 4 (`4.0.5.yaml`, [Interfejsy API](https://www.gov.pl/web/e-doreczenia/interfejsy-api), sprawdzone 2026-10-08).

- Utrzymywane są trzy główne wersje, a poprzednia działa jeszcze 12 miesięcy ([PT UA API 5.27, §4.4.1](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf)).
- Wersje API i adresy są w konfiguracji per cel `EDORECZENIA_TARGET`, nie w kodzie.
- Plik yaml jest publiczny, więc testy kontraktowe startują przed dostępem INT ([Interfejsy API](https://www.gov.pl/web/e-doreczenia/interfejsy-api)).
- Wiersz ryzyka zmian wersji i okresowe sprawdzanie strony „Interfejsy API”: [../facts.md](../facts.md).

| Cel | UA API | SE API | Token OIDC |
| --- | --- | --- | --- |
| `int` | `uaapi-int-ow.poczta-polska.pl/api/v3/` | `int-ow.edoreczenia.gov.pl/api/se/v4/` | `int-ow.edoreczenia.gov.pl/auth/realms/EDOR/protocol/openid-connect/token` |
| `prod` | `uaapi-ow.poczta-polska.pl/api/v3/` | `ow.edoreczenia.gov.pl/api/se/v4/` | `ow.edoreczenia.gov.pl/auth/realms/EDOR/protocol/openid-connect/token` |

Hosty sprawdza `HostAllowlist` ([../architecture.md](../architecture.md)). Źródło adresów: [instrukcja INT v1.9.6](https://www.gov.pl/attachment/09ea071e-255f-491a-96fe-8ae5cd17ffc3), [instrukcja PROD v1.9](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce), [PT UA API 5.27](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf).

**OIDC z asercją JWT** ([instrukcja INT v1.9.6](https://www.gov.pl/attachment/09ea071e-255f-491a-96fe-8ae5cd17ffc3), [instrukcja PROD v1.9](https://www.gov.pl/attachment/d48d0188-cc7a-42c2-8497-99da442ef9ce))

| Element | Wartość |
| --- | --- |
| `iss`, `sub` | `<ade_address>.SYSTEM.<nazwa systemu>` |
| `aud` | adres realm `<host tokena>/auth/realms/EDOR`; `http` albo `https` sprawdzasz na INT |
| `iat`, `nbf` | teraz |
| `jti` | UUIDv4 |
| podpis | RS256 kluczem prywatnym systemu |
| żądanie | POST na endpoint tokena z `?login_hint=ADE.<ade_address>`, grant `client_credentials` z asercją `jwt-bearer` (RFC 7523) |
| przykład `login_hint` | `ADE.AE:PL-00000-00000-AAAAA-00` (adres fikcyjny); prefiks `ADE.` tylko raz |
| ważność tokena | 5 minut, `refresh_expires_in` = 0 |

- Token trzymasz w `credential_state` (`edoreczenia_access_token`) do wygaśnięcia minus kilka sekund. Potem nowa asercja, nigdy wcześniej.
- Serwer potrzebuje poprawnego czasu: NTP w compose ([../operations.md](../operations.md)).
- Token otwiera UA API (skrzynka) i SE API (wyszukiwarka adresów w BAE).

### Odbiór i fikcja doręczenia

**Odbiór to doręczenie.** Dla podmiotów niepublicznych GET `/{ADE}/messages/{id}` na zawiadomieniu (Stub) uruchamia transfer i wystawienie dowodu otrzymania E.1, czyli prawny odbiór ([PT UA API 5.27, §3.3.1.6](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf)). Odbiorem jest każde udostępnienie treści (art. 41 ust. 2 [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf)).

| Metoda konektora | Co robi | Kto może wywołać |
| --- | --- | --- |
| `list()` | lista metadanych skrzynki odbiorczej i nadawczej; nigdy GET pojedynczej wiadomości | scheduler, panel, handler „Pobierz kopię” (kontrola przed GET) |
| `receive(id)` | GET wiadomości, dowody, załączniki; jedyne miejsce z GET na wiadomość | tylko handler akcji „Odbierz” albo „Pobierz kopię” z panelu |

- `list()` wywołują tylko scheduler, panel i handler „Pobierz kopię”. Czat nie wywołuje konektora, czyta tylko bazę ([czat.md](czat.md)); basal jest odłożony ([basal.md](basal.md)).
- Treść pisma pobiera się wyłącznie po akcji użytkownika.
- Panel pokazuje metadane, datę fikcji doręczenia (tylko nadawca publiczny) i ostrzeżenie, że od odbioru biegną terminy.
- „Odbierz” wymaga step-upu passkeyem ([../security.md](../security.md)). Wpisy w `audit_log` ([../security.md](../security.md)): `edelivery.receive_requested` przed pierwszym GET, `edelivery.received` po zapisie treści i dowodów.
- Panel ustawia `receiving`, `receive_audit_id` i `receive_requested_by` przed pierwszym GET. „Pobierz kopię” przy `received_elsewhere` też ustawia `receive_audit_id`, bez step-upu, bo to nie jest odbiór. Wpis: `edelivery.copy_requested`.
- Handler wykonuje GET tylko dla stanu `receiving` albo `received_elsewhere` z ustawionym `receive_audit_id`. Każdy inny przypadek odrzuca.
- Ścieżka „Pobierz kopię”: handler przed GET pobiera świeże metadane wiadomości przez `list()`. Jeśli wiadomość jest nadal zawiadomieniem (Stub), odmawia GET, cofa stan do `stub` i pokazuje „Odbierz” ze step-upem.
- Handler działa na transporcie `reads`. Ponowienie jest bezpieczne, bo GET wymaga stanu ustawionego Twoją akcją.
- Odpowiedź 202 oznacza trwający transfer, a odbiór już nastąpił. Handler ponawia GET z backoffem, wiadomość zostaje w `receiving`.
- Ponowienie po 202 albo po timeoucie nie ma nowego skutku prawnego, bo dotyczy pisma, które już kliknąłeś.
- Załączniki ponad 15 MB pobiera się przez `POST /{ADE}/attachments/download`. Wywołania zbiorcze przyjmują najwyżej 50 identyfikatorów.
- Zawiadomienia nie da się usunąć ani spakować do ZIP przez API. Do triage wystarczą metadane: temat, nadawca, `eventDate`, `daysToReceive`, `receiptDate`.
- Test F4: żadne żądanie GET na wiadomość nie powstaje poza `receive()` („Odłożone przypadki odbioru” niżej).

**Fikcja doręczenia**

| Źródło | Reguła | Kolumna |
| --- | --- | --- |
| API | pola `daysToReceive` i `receiptDate` zawiadomienia; dokładne znaczenie pól potwierdzasz na INT | `deemed_delivery_date`, `days_to_receive`, `api_receipt_date` |
| ustawa | doręczenie w dniu następującym po upływie 14 dni od dowodu D.1 (art. 42 ust. 2 [u.d.e.](https://api.sejm.gov.pl/eli/acts/DU/2026/3/text.pdf)) | – |
| PT, reguła R13 | `eventDate` + 14 dni z przesunięciem na dzień roboczy przez `PolishCalendar` ([PT UA API 5.27](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf)) | `deemed_delivery_date_local` |

- Panel pokazuje datę z API. Wyliczenie lokalne to kontrola: rozbieżność daje alert i obie daty w panelu. Obowiązek `edelivery_deemed` (odłożony) liczy się od daty z API ([../compliance-calendar.md](../compliance-calendar.md)).
- Ustawa i R13 mogą różnić się o dzień. Odczyt do wyświetlania jest w zakresie przeglądu doradcy według Q-29 (D-03, D-04).
- Fikcja dotyczy tylko pism od nadawców publicznych. Dla `sender_is_public` = false kokpit nie liczy terminu i nie pokazuje odliczania.
- Skąd `sender_is_public`: metadane wiadomości albo SE API; pole potwierdzasz na INT.
- Zawiadomienie bez odbioru po `deemed_delivery_date` panel oznacza jako doręczone fikcją. Terminy z pisma biegną wtedy od tej daty.

### Polling i powiadomienia

- `list()` na transporcie `reads`: co 15 minut, osobno skrzynka odbiorcza i nadawcza (`sync_cursor`: `inbox`, `outbox`; zadanie `edoreczenia.list`, odłożone razem z F4, poza aktywnym katalogiem zadań).
- Nowe zawiadomienie: wiersz `edelivery_message` w stanie `stub`, push kategorii `edelivery_new`. Treść push to kategoria, liczba i link, bez nadawcy i tematu ([../security.md](../security.md)).
- Nadawca publiczny: odłożony obowiązek `edelivery_deemed` z `due_date` = `deemed_delivery_date`, klucz `edelivery_deemed:{edelivery_message_id}`, przypomnienia T−7, T−2, T−1, T0 push i mail.
- Odbiór w kokpicie albo poza nim zamyka `edelivery_deemed` i kończy przypomnienia.
- Konektor ma licznik `edoreczenia.api` w `rate_counter` i respektuje `Retry-After`, jak każdy konektor ([integrations.md](../../.claude/rules/integrations.md)). Wartości: tabela „Konektor i testy”.

### Cykl życia pisma

**Przesyłka przychodząca (`edelivery_message.status`, `direction` = `in`)**

| Z | Do | Wyzwalacz | Aktor |
| --- | --- | --- | --- |
| – | `stub` | `list()` widzi nowe zawiadomienie | system |
| `stub` | `receiving` | „Odbierz” w panelu ze step-upem; wpis w `audit_log` | Ty |
| `receiving` | `received` | GET zwraca treść; dowód E.1, treść i załączniki zapisane | system |
| `receiving` | `receiving` | odpowiedź 202 albo timeout; ponowienie z backoffem; po limicie prób alert i przycisk „Ponów” | system |
| `stub` | `received_elsewhere` | `list()` pokazuje, że wiadomość nie jest już zawiadomieniem (odbiór w aplikacji albo innym systemie); wpis `edelivery.received_elsewhere` w `audit_log` | system |
| `received_elsewhere` | `stub` | „Pobierz kopię”: świeże `list()` pokazuje nadal zawiadomienie; zero GET, alert w panelu | system |

- Pole listy wskazujące odbiór poza kokpitem potwierdzasz na INT ([../facts.md](../facts.md)).
- `received_elsewhere` nie wyzwala odbioru, bo odbiór już nastąpił. Panel proponuje „Pobierz kopię”: ten sam `receive()`, ta sama ścieżka logowania, z kontrolą `list()` przed GET.
- `received_at` ustawia tylko `receive()` albo wykrycie odbioru poza kokpitem.
- Przesyłki z `direction` = `out` nie mają stanu (`status` pusty). Sync `outbox` zapisuje tylko ich metadane; dowody nadania zostają w aplikacji e-Doręczeń. `receive()` ich nie pobiera.

**Sprawa (`edelivery_case.status`)**

| Z | Do | Wyzwalacz | Aktor |
| --- | --- | --- | --- |
| – | `new` | nowe zawiadomienie bez powiązanej sprawy; system zakłada sprawę, Ty możesz dołączyć pismo do otwartej sprawy | system, Ty |
| `new` | `received` | pierwsze pismo sprawy w stanie `received` albo `received_elsewhere` | system |
| `received` | `awaiting_reply` | potwierdzasz `reply_due_date` (wpis `edelivery.deadline_confirmed`); powstaje obowiązek `edelivery_reply_due` | Ty |
| `received` | `replied` | odpowiedź powiązana ze sprawą przed potwierdzeniem terminu | Ty, system |
| `received` | `closed` | pismo nie wymaga odpowiedzi | Ty |
| `awaiting_reply` | `replied` | odpowiedź w skrzynce nadawczej powiązana ze sprawą albo przyjęta wysyłka z kokpitu | Ty, system |
| `awaiting_reply` | `awaiting_reply` | kolejne pismo dołączone; obowiązek zostaje | system |
| `replied` | `received` | kolejne pismo w stanie `received` albo `received_elsewhere` dołączone do sprawy | system, Ty |
| `awaiting_reply`, `replied` | `closed` | zamykasz sprawę; otwarty obowiązek → `not_applicable` | Ty |
| `closed` | – | nowe pismo zakłada nową sprawę (`new`) | system |

- **Termin odpowiedzi.** Model może tylko zaproponować datę z cytatem zdania źródłowego (narzędzie `get_edelivery_message` w [czat.md](czat.md), po powrocie F4). `reply_due_date` zapisuje się dopiero po Twoim potwierdzeniu.
- Termin względny („14 dni od doręczenia”) panel przelicza przez `PolishCalendar` od daty bazowej z tabeli niżej. Panel pokazuje źródło daty bazowej. Wynik i tak potwierdzasz.

| Doręczenie | Data bazowa terminu względnego |
| --- | --- |
| odbiór w kokpicie | data z dowodu E.1 |
| `received_elsewhere` | data odbioru z metadanych listy (`api_receipt_date`), nie chwila wykrycia |
| fikcja | do odpowiedzi na Q-29 wcześniejsza z `deemed_delivery_date` i `deemed_delivery_date_local` |

- **Skrzynka nadawcza.** Sync `outbox` zapisuje metadane odpowiedzi wysłanych z aplikacji e-Doręczeń. System proponuje sprawę po ADE adresata, powiązanie potwierdzasz Ty.

### Archiwum

Po odbiorze kokpit zapisuje treść, załączniki i dowody pisma przychodzącego, w tym E.1. Pliki trafiają do `FileStore`, każdy z hashem.

| Element | `stored_file.kind` | Właściciel |
| --- | --- | --- |
| treść i załączniki pisma | `attachment` | `edelivery_message` |
| dowody pisma przychodzącego (w tym E.1) | `evidence` | `edelivery_message` |

- Pisma wychodzące (`direction` = `out`) mają w kokpicie tylko metadane z sync `outbox`. Dowody nadania zostają w aplikacji e-Doręczeń.
- ZIP sprawy powstaje na żądanie z plików ([../data-model.md](../data-model.md)).
- Duże załączniki i odpowiedź 202 obsługuje ten sam backoff co odbiór.
- Retencja i blokada usunięcia: [../security.md](../security.md). Te pliki są tylko u Ciebie, więc obejmuje je backup poza serwerem ([../operations.md](../operations.md)).

### Wysyłka

Pisma wysyłasz z aplikacji e-Doręczeń. Wysyłka z kokpitu wejdzie dopiero po powrocie F4 PROD i decyzji Q-35, zawsze przez bramkę zatwierdzeń (wiersz w [../roadmap.md](../roadmap.md)).

**Propozycja mapowania `edelivery_outbound`.** Zatwierdzaną encją jest szkic `draft` rodzaju `edelivery_reply` ([czat.md](czat.md)). Stany `approval` definiuje [../approval-gate.md](../approval-gate.md).

| `approval.status` | `draft.status` | Skutek w e-Doręczeniach |
| --- | --- | --- |
| `pending` | `pending` | – |
| `approved`, `dispatching`, `submitted` | `approved` | `submitted`: identyfikator wiadomości zapisany w `external_refs` |
| `accepted` | `executed` | wiersz `edelivery_message` z `direction` = `out` (tylko metadane); sprawa → `replied` |
| `rejected` | `pending` | błąd API; poprawiasz szkic |
| `unknown` | `approved` | `SubmissionReconciler` szuka wiadomości w sync `outbox`; bez pewności rozstrzygasz Ty |
| `expired`, `superseded` | `pending` | – |
| `cancelled`, `cancel_reason` = `user` | `discarded` | – |
| `cancelled`, `cancel_reason` = `hash_mismatch` | `pending` | nowe zatwierdzenie po wyjaśnieniu alertu |

- Szkic ma naraz najwyżej jedno żywe zatwierdzenie: `draft` (kopiowanie) albo `edelivery_outbound` (wysyłka). Pilnuje tego `ApprovalGate`.
- Wysyłka idzie transportem `official_writes`, bez automatycznych ponowień.

### Konektor i testy

| Element | Wartość |
| --- | --- |
| Slug, katalog | `edoreczenia`, `src/Connector/EDoreczenia`; odłożone (D-29) |
| Cel | `EDORECZENIA_TARGET` = `int` albo `prod`; domyślnie `int`; działa dopiero po powrocie F4 (D-13) |
| Sekrety | przez `SecretStore`: klucz systemu i certyfikat statycznie, token rotujący w `credential_state` ([../security.md](../security.md)) |
| Logi | `connector_call` z hashem i rozmiarem; treści żądań i odpowiedzi nie trafiają do logów |
| Fixtures | `tests/Fixtures/EDoreczenia`, nagrania oczyszczone, adresy fikcyjne ([../testing.md](../testing.md)) |
| Testy kontraktowe | na publicznym yaml UA API 3.0.8 i SE API 4.0.5, możliwe przed dostępem INT |
| Licznik | `rate_counter.bucket` = `edoreczenia.api`, jedna grupa na konektor; wartości z konfiguracji. [PT UA API 5.27](https://edoreczenia.poczta-polska.pl/wp-content/uploads/2025/09/COI-Projekt-Techniczny-UA-API_5.27-1.pdf) nie publikuje limitów liczby zapytań, więc domyślnie ostrożnie: 500 wywołań na dobę (polling dwóch skrzynek co 15 min to co najmniej 192). 429 i `Retry-After` → `blocked_until` |

**Odłożone przypadki odbioru (D-29).** Po przywróceniu F4 asercje liczą żądania w logu `MockHttpClient`. Do tego czasu przypadki nie działają w CI ani w `kokpit:evidence` i nie blokują odbioru faz.

| Wejście | Oczekiwany wynik |
| --- | --- |
| `edoreczenia.list` z nagraniem trzech zawiadomień, dwa przebiegi | trzy wiersze `stub`; zero GET na `/{ADE}/messages/{id}` |
| `receive(id)` przy `stub` bez `receive_audit_id`; czat `get_edelivery_message` na `stub` | odmowa albo tylko metadane z bazy; zero wywołań konektora przez czat, zero GET |
| „Odbierz” ze step-up | `edelivery.receive_requested` przed pierwszym GET; `receiving` → `received`; E.1 w archiwum; `edelivery.received` |
| odpowiedź 202 | `receiving`; ponowienie GET z backoffem; jeden wpis `receive_requested` |
| `received_elsewhere`, „Pobierz kopię” | GET bez step-up; `edelivery.copy_requested` |
| dawne `cc-27`: reguła architektury i log żądań | GET na wiadomość wyłącznie w `receive()`, przy `receiving` albo `received_elsewhere` z `receive_audit_id`; scheduler tylko `list()`, czat czyta wyłącznie bazę |

**Szkic kryteriów F4 (przy powrocie).** Plik fazy powstanie z tej listy i przypadków odbioru wyżej; klucze slug nada wtedy ([../naming.md](../naming.md)).

- `[manual]` rejestracja systemu na INT → certyfikat testowy w `credential_state` z datą ważności;
- nowe zawiadomienie od nadawcy publicznego → push `edelivery_new` i obowiązek `edelivery_deemed` z datą z API; rozbieżność z R13 → alert z obiema datami;
- „Odbierz” ze step-upem na INT → `edelivery.receive_requested` przed pierwszym GET, stan `received`, E.1 w archiwum;
- „Pobierz kopię” przy `received_elsewhere` → treść i dowody w archiwum bez step-upu, wpis `edelivery.copy_requested`;
- odpowiedź wysłana z aplikacji e-Doręczeń → sync `outbox` tworzy wiersz z `direction` = `out` i propozycję sprawy po ADE adresata; po Twoim potwierdzeniu sprawa → `replied`.

## Otwarte pytania

| Id | Temat | Stan |
| --- | --- | --- |
| Q-13 | ADE firmy, powiadomienia, prywatny ADE, e-Korespondencja | otwarte; kanały sprawdzasz ręcznie |
| Q-18 | zastępca przy odbiorze pism | rozstrzygnięte: zastępcy brak, także administratora skrzynki (D-99, D-107) |
| Q-30 | dostawca ADE, pytanie do COI, koszt certyfikatu | odłożone (D-29): bramki 1–2 poza kodem; reszta po F5 |
| Q-29 | odczyt daty fikcji w przeglądzie doradcy | otwarte; do powrotu F4 datę czytasz z aplikacji |
| Q-35 | wysyłka szkiców z kokpitu albo tylko kopiowanie | otwarte; `edelivery_outbound` tylko po powrocie F4 |

Decyzje w [../decisions.md](../decisions.md): D-29 (API odłożone, procedura ręczna), D-30 (kolejność faz), D-03 (zastępca i break-glass), D-99 i D-107 (bez zastępcy i administratora skrzynki), D-09 (koszt certyfikatu po powrocie), D-13 (`EDORECZENIA_TARGET` po powrocie), D-18 (stały IPv4 po powrocie), D-19 (treść push).
