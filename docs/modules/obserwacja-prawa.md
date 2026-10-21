# Obserwacja prawa

Kokpit raz w tygodniu sprawdza w API Sejmu, czy obserwowane akty i projekty ustaw się zmieniły, czy w Sejmie ruszył nowy projekt ustawy o obserwowanym akcie i czy changelog KSeF API ma nową wersję. Daje szkic wiersza do [../facts.md](../facts.md); skutek zmiany oceniasz Ty. Stan na 21 października 2026 (D-75, D-112).

## Zakres

| Pozycja (`legal_watch.kind`) | Endpoint | Co jest stanem | Źródło listy |
| --- | --- | --- | --- |
| akt (`act`) | `GET api.sejm.gov.pl/eli/acts/{ELI}/references` | powiązania z kategorii `legal_watch.act_categories`: akty zmieniające, informacje o tekście jednolitym, akty wykonawcze, wyroki TK, uchylenie aktu | tabela „Akty prawne” w [../facts.md](../facts.md) |
| proces (`process`) | `GET api.sejm.gov.pl/sejm/term{N}/processes/{numer}` | etapy procesu ze stopniami podrzędnymi i znacznik uchwalenia | procesy z tabeli „Obserwacja prawa” w [../facts.md](../facts.md) |
| projekty ustaw spoza listy (`bills`, D-112) | `GET api.sejm.gov.pl/sejm/term{N}/processes?modifiedSince=…&offset=…&limit=100` | numery projektów ustaw (`documentTypeEnum` = `BILL`), których tytuł zawiera słowo z `legal_watch.bill_keywords`; suma narastająca | słowa przypisane aktom z tabeli „Akty prawne” |
| changelog KSeF API (`changelog`, D-112) | `GET raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md` | nagłówki `### Wersja …` z datami wdrożeń TEST, DEMO i PROD | [ksef-docs](https://github.com/CIRFMF/ksef-docs) |

- Lista pozycji jest w konfiguracji `legal_watch.items` (`config/services_connectors.yaml`), nie w kodzie. Akt to zawsze akt bazowy, np. ustawa o VAT to `DU/2004/535`. Powiązania w ELI wiszą przy akcie bazowym, a nie przy obwieszczeniu tekstu jednolitego.
- Nowy akt do obserwacji: wiersz w tabeli „Akty prawne”, potem pozycja w `legal_watch.items`. Pierwszy przebieg zapisuje jego stan bez alertu.
- Projekty ustaw spoza listy: słowa w `legal_watch.bill_keywords` są przypisane do ELI aktu z `legal_watch.items` (np. `podatku od towarów i usług` → ustawa o VAT), małymi literami, porównywane z tytułem procesu. Procesy z `legal_watch.items` i dokumenty inne niż projekt ustawy (uchwały, wnioski, informacje) odpadają. Projekt alarmuje raz; jego kolejne etapy śledzisz, dopisując proces do `legal_watch.items`.
- Szum (D-112): od 1.10 do 21.10.2026 zmieniło się 131 procesów kadencji 10, w tym 103 projekty ustaw; słowa dopasowały 19 z nich, z czego 6 przez ustawę o świadczeniach opieki zdrowotnej (składka zdrowotna). Słowo, które daje sam szum, usuwasz z konfiguracji.
- Changelog: klucz stanu to wersja z datami wdrożeń, więc alert daje nowa wersja i zmiana albo uzupełnienie daty, np. PROD po TEST.
- Poza zakresem: strumień wszystkich zmian (`/eli/changes/acts`), projekty rozporządzeń z RCL i komunikaty MF. Te pozycje z tabeli „Obserwacja prawa” sprawdzasz dalej ręcznie według kolumny „Następne sprawdzenie”.

## Przebieg

1. Zadanie `legal_watch.check` rusza w poniedziałek o 6:30 Europe/Warsaw na transporcie `reads` ([../operations.md](../operations.md)). Przy `CONNECTORS_ENABLED=0` kończy się bez wywołań.
2. Dla każdej pozycji konektor `eli` robi jedno `GET` przez licznik `eli.read` (100 na dobę, [../architecture.md](../architecture.md)); `bills` robi tyle stron po 100, ile trzeba, najwyżej 10. `changelog` czyta plik konektorem `ksef_docs` przez licznik `ksef_docs.read` (10 na dobę). Stan to posortowana lista `kategoria|ELI`, etapów procesu, numerów projektów albo wersji, a `snapshot_sha256` to jego hash.
   - Kursor `bills`: `modifiedSince` to ostatni udany odczyt (`legal_watch.checked_at`) minus dzień zakładki, w czasie Europe/Warsaw, bo API Sejmu podaje daty lokalne. Pierwszy odczyt patrzy 7 dni wstecz. Stan to suma poprzednich i nowych numerów, więc zakładka nie powtarza alertu.
   - Więcej niż 10 stron daje `connector_failure` (`eli`, `result_limit_exceeded`) bez zmiany stanu i kursora; następny przebieg czyta to samo okno.
3. Pierwszy odczyt pozycji zapisuje stan w `legal_watch` bez alertu.
4. Inny hash z nowymi pozycjami daje wiersz `legal_watch_hit` z listą nowych pozycji i push `legal_change` z nazwą pozycji. Klucz push zawiera hash stanu, więc ten sam stan nie wraca drugi raz.
5. Pozycje, które zniknęły z odpowiedzi API, zmieniają stan bez alertu.
6. Błąd pozycji (HTTP inny niż 200, odpowiedź w innym kształcie) daje `connector_failure` dla `eli` albo `ksef_docs` i zostawia jej stan; reszta pozycji idzie dalej. Wyczerpany licznik odkłada całe zadanie do północy.

## Twoja część

1. Po push `legal_change` uruchamiasz `bin/console app:legal-watch report`. Komenda wypisuje szkic wierszy tabeli „Obserwacja prawa” ze stanem „do oceny” i datą następnego sprawdzenia za 7 dni.
2. Czytasz akt albo etap, wpisujesz skutek i plik-dom, a wynik przenosisz do [../facts.md](../facts.md). Zmianę reguły robisz w jej pliku-domu, nie w kokpicie.
3. `bin/console app:legal-watch report --mark-reviewed` zamyka trafienia.
4. `bin/console app:legal-watch check` uruchamia sprawdzenie od razu, poza harmonogramem.

## Dane i testy

- Tabele `legal_watch` i `legal_watch_hit`: [../data-model.md](../data-model.md). Nazwy: [../naming.md](../naming.md), sekcja „Obserwacja prawa (D-75)”.
- API Sejmu i ksef-docs są publiczne, bez klucza i bez danych osobowych. Hosty mają tylko jedną wersję, więc testy automatyczne idą na nagraniach w `tests/Fixtures/Eli` i `tests/Fixtures/KsefDocs`, nigdy na żywym API.
- Nagrania D-112 z 21.10.2026 są skrócone: pięć nagłówków procesów bez opisu i odnośników oraz nagłówki i tabele dat trzech wersji changelogu. Warianty z numerami 9001–9014 i wersją 2.10.0 są fikcyjne.
- Nagrania przeszły skaner fixtures bez `Scrubber`, bo ten zamienia `displayAddress` (adres publikacyjny aktu) jak adres osoby (D-75, tak jak D-74).
