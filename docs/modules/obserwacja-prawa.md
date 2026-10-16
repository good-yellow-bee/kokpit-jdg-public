# Obserwacja prawa

Kokpit raz w tygodniu sprawdza w API Sejmu, czy obserwowane akty i projekty ustaw się zmieniły, i daje szkic wiersza do [../facts.md](../facts.md). Skutek zmiany oceniasz Ty. Stan na 16 października 2026 (D-75).

## Zakres

| Pozycja (`legal_watch.kind`) | Endpoint | Co jest stanem | Źródło listy |
| --- | --- | --- | --- |
| akt (`act`) | `GET api.sejm.gov.pl/eli/acts/{ELI}/references` | powiązania z kategorii `legal_watch.act_categories`: akty zmieniające, informacje o tekście jednolitym, akty wykonawcze, wyroki TK, uchylenie aktu | tabela „Akty prawne” w [../facts.md](../facts.md) |
| proces (`process`) | `GET api.sejm.gov.pl/sejm/term{N}/processes/{numer}` | etapy procesu ze stopniami podrzędnymi i znacznik uchwalenia | procesy z tabeli „Obserwacja prawa” w [../facts.md](../facts.md) |

- Lista pozycji jest w konfiguracji `legal_watch.items` (`config/services_connectors.yaml`), nie w kodzie. Akt to zawsze akt bazowy, np. ustawa o VAT to `DU/2004/535`. Powiązania w ELI wiszą przy akcie bazowym, a nie przy obwieszczeniu tekstu jednolitego.
- Nowy akt do obserwacji: wiersz w tabeli „Akty prawne”, potem pozycja w `legal_watch.items`. Pierwszy przebieg zapisuje jego stan bez alertu.
- Poza zakresem: strumień wszystkich zmian (`/eli/changes/acts`), projekty rozporządzeń z RCL, komunikaty MF i changelog ksef-docs. Te pozycje z tabeli „Obserwacja prawa” sprawdzasz dalej ręcznie według kolumny „Następne sprawdzenie”.

## Przebieg

1. Zadanie `legal_watch.check` rusza w poniedziałek o 6:30 Europe/Warsaw na transporcie `reads` ([../operations.md](../operations.md)). Przy `CONNECTORS_ENABLED=0` kończy się bez wywołań.
2. Dla każdej pozycji konektor `eli` robi jedno `GET` przez licznik `eli.read` (100 na dobę, [../architecture.md](../architecture.md)). Stan to posortowana lista `kategoria|ELI` albo etapów procesu, a `snapshot_sha256` to jego hash.
3. Pierwszy odczyt pozycji zapisuje stan w `legal_watch` bez alertu.
4. Inny hash z nowymi pozycjami daje wiersz `legal_watch_hit` z listą nowych pozycji i push `legal_change` z nazwą pozycji. Klucz push zawiera hash stanu, więc ten sam stan nie wraca drugi raz.
5. Pozycje, które zniknęły z odpowiedzi API, zmieniają stan bez alertu.
6. Błąd pozycji (HTTP inny niż 200, odpowiedź w innym kształcie) daje `connector_failure` dla `eli` i zostawia jej stan; reszta pozycji idzie dalej. Wyczerpany licznik odkłada całe zadanie do północy.

## Twoja część

1. Po push `legal_change` uruchamiasz `bin/console app:legal-watch report`. Komenda wypisuje szkic wierszy tabeli „Obserwacja prawa” ze stanem „do oceny” i datą następnego sprawdzenia za 7 dni.
2. Czytasz akt albo etap, wpisujesz skutek i plik-dom, a wynik przenosisz do [../facts.md](../facts.md). Zmianę reguły robisz w jej pliku-domu, nie w kokpicie.
3. `bin/console app:legal-watch report --mark-reviewed` zamyka trafienia.
4. `bin/console app:legal-watch check` uruchamia sprawdzenie od razu, poza harmonogramem.

## Dane i testy

- Tabele `legal_watch` i `legal_watch_hit`: [../data-model.md](../data-model.md). Nazwy: [../naming.md](../naming.md), sekcja „Obserwacja prawa (D-75)”.
- API jest publiczne, bez klucza i bez danych osobowych. Host ma tylko jedną wersję, więc testy automatyczne idą na nagraniach w `tests/Fixtures/Eli`, nigdy na żywym API.
- Nagrania przeszły skaner fixtures bez `Scrubber`, bo ten zamienia `displayAddress` (adres publikacyjny aktu) jak adres osoby (D-75, tak jak D-74).
