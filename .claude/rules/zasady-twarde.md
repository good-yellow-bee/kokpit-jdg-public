# Zasady twarde

Zasady, sposób pracy, komendy, układ katalogów i definicja ukończenia dla każdej sesji Claude Code w kokpicie. Stan na 8 października 2026.

## Projekt

- Kokpit JDG: osobisty kokpit jednej JDG. Automatyzuje KSeF i JPK, weryfikuje kontrahentów i pilnuje terminów, także e-Doręczeń bez API (D-29).
- Jeden użytkownik, jeden NIP, self-hosted, bez `tenant_id`. Stack: PHP 8.4, Symfony (Messenger, Scheduler, Workflow, Lock, Clock, Security, UX), PostgreSQL, Docker Compose.
- Indeks, status i kolejność faz (D-30): [docs/README.md](../../docs/README.md). Słownik PL→EN i kontrakt nazw: [docs/naming.md](../../docs/naming.md).
- Pracujemy fazami, jedna faza albo jej część (np. F2.1) na raz. Dla fazy X czytasz `docs/phases/fX-*.md` i tylko pliki z jego listy.
- Bez `@import` plików faz w `CLAUDE.md`, bo importy ładują się na starcie sesji.

## Zasady twarde

**Środowiska i hosty**

- Cel per konektor: `KSEF_TARGET` (`test`, `demo`, `prod`), `JPK_TARGET` i `EDEKLARACJE_TARGET` (`test`, `prod`). `EDORECZENIA_TARGET` jest odłożony razem z F4 (D-29).
- Domyślnie cel testowy, `CONNECTORS_ENABLED=0` przy braku zmiennej. Cele są niezależne od `APP_ENV`, z osobnymi poświadczeniami i bazą per środowisko.
- `HostAllowlist` w kodzie, z testem, który pada przy hoście PROD bez celu `prod`. Hosty per cel: [docs/architecture.md](../../docs/architecture.md).
- Cel `prod` albo `demo` ustawiasz tylko Ty, ręcznie, osobnym wdrożeniem. Claude Code nigdy nie ustawia `*_TARGET=prod`.
- Na VPS stoi tylko projekt `kokpit-prod`. Dev działa na laptopie (`kokpit-dev`), test odtworzenia w jednorazowym projekcie lokalnym (D-28).
- Sesje i testy wołają wyłącznie hosty testowe:
  - KSeF: `api-test.ksef.mf.gov.pl`, `qr-test.ksef.mf.gov.pl`, `api-latarnia-test.ksef.mf.gov.pl`;
  - JPK: `test-e-dokumenty.mf.gov.pl` i magazyn `taxdocumentstorageNNtst.blob.core.windows.net` (wzorzec w [docs/architecture.md](../../docs/architecture.md));
  - e-Deklaracje: `test-bramka.edeklaracje.gov.pl` (sprawdzić, czy działa);
  - e-Doręczenia INT dopiero po powrocie F4 nowym rekordem D-xx (D-29).
- Wyjątek: jednorazowy GET bez uwierzytelnienia po opublikowane XSD i dokumentację z `www.gov.pl`, `podatki.gov.pl`, `podatki-arch.mf.gov.pl`, `crd.gov.pl`, `raw.githubusercontent.com/CIRFMF`. To nie jest wywołanie API urzędu.
  - Schematy trafiają do `resources/xsd` z manifestem: źródło, data pobrania, sha256 ([integrations.md](integrations.md)). Pliki możesz też dostarczyć Ty.
- Rejestry w dev i testach: tylko test białej listy (`wykazpodatnikowvat-test.mf.gov.pl`, dawny `wl-test.mf.gov.pl`), VIES `check-vat-test-service` albo nagrane odpowiedzi. KRS, CEIDG i GUS są odłożone (D-29).
- Produkcyjne rejestry i NBP tylko do odczytu, przez licznik, nigdy z testów automatycznych.
- FreshBooks bez firmy testowej (D-35): testy na ręcznie pisanych fixtures, prawdziwą firmę czyta tylko `kokpit-prod`. Pierwszy zapis zwrotny robi F3 na PROD: jedna faktura przez bramkę, wynik w dowodach fazy.
- Claude API dostaje w dev i testach tylko dane fikcyjne, z budżetem przebiegu.

**Bramka zatwierdzeń**

- Każdy zapis w urzędzie (faktura, korekta, JPK, VAT-UE) idzie tylko przez bramkę zatwierdzeń. Zero automatycznych wysyłek.
- Zatwierdzenie wiąże sha256 payloadu. Wysyłka rusza tylko przy zgodnym hashu.
- Zapisy urzędowe bez automatycznych ponowień: transport `official_writes`, `max_retries: 0`, jeden konsument.
- Wynik nieznany daje `unknown` i rekoncyliację tylko odczytem. Ponowienie wyłącznie ręcznie, jako nowe zatwierdzenie.
- Metody konektorów zapisujące w urzędzie (`ksef`, `jpk`, `edeklaracje`) woła tylko handler `official_writes` bramki. Pilnuje tego test architektury.
- Zapis zwrotny do FreshBooks, `mail` i `push` nie są zapisami w urzędzie ([docs/architecture.md](../../docs/architecture.md)). Stany: [docs/approval-gate.md](../../docs/approval-gate.md).
- „Doręcz klientowi” wysyła e-mail konektorem `mail` synchronicznie w żądaniu panelu, poza Messenger (D-31). Ponowienie tylko nowym kliknięciem.

**e-Doręczenia**

- API odłożone (D-29): bez konektora, wniosku INT i certyfikatu kwalifikowanego. Obowiązek pokrywają powiadomienia mailowe, odbiór ręczny w aplikacji e-Doręczeń i `manual_channel_check`.
- Termin z pisma wpisujesz ręcznie do kalendarza po odbiorze w aplikacji.
- Kokpit nigdy sam nie pobiera treści pisma. Po powrocie F4 treść pobiera tylko Twoja akcja w panelu. `list()` daje metadane, a GET zawiadomienia (Stub) jest tylko w `receive()`: to prawny odbiór z dowodem E.1.
- Szczegóły: [docs/modules/e-doreczenia.md](../../docs/modules/e-doreczenia.md).

**Sekrety**

- Sekrety statyczne trzymasz w Symfony Secrets. Jedyne wejście to `SecretStore`; konektor nie czyta zmiennych środowiskowych.
- Tokeny rotujące (FreshBooks OAuth, KSeF JWT) trzymasz w zaszyfrowanej tabeli `credential_state` z kluczem z sejfu. Odświeżanie pod lockiem.
- Sekret nigdy nie trafia do logów, plików `.env`, repo ani kontekstu modelu. Zewnętrzne szyfrowanie plików sekretów odpada.
- Sejf deweloperski nie ma sekretów celu `prod`, a klucz deszyfrujący PROD nigdy nie trafia do repo.
- Inwentarz: [docs/security.md](../../docs/security.md); procedury rotacji: [docs/operations.md](../../docs/operations.md).

**Dokumenty urzędowe**

- Każdy dokument urzędowy przechodzi walidację XSD przed prośbą o zatwierdzenie: FA(3), JPK_V7M(3), JPK_EWP(4), JPK_ST(1), VAT-UE(5), VAT-UEK(5), SIG-2008.
- Schematy leżą w `resources/xsd` (vendored, z sumami kontrolnymi). Walidacja działa offline, bez sieci (`LIBXML_NONET`).
- Zapisujesz bajty dokumentu przed danymi autoryzującymi (`xml_sent`) i ich sha256. Hash faktycznego żądania trafia do `connector_call`.
- Ponowna serializacja po zatwierdzeniu jest zabroniona. Szczegóły: [integrations.md](integrations.md).

**Konektory i dane testowe**

- Konektorem jest też Claude API, z licznikiem `claude.messages` (D-34).
- Każdy konektor ma testy kontraktowe, log `connector_call` i licznik `rate_counter` z `blocked_until` i `Retry-After`. Odłożone rozszerzenia limitów: [docs/roadmap.md](../../docs/roadmap.md).
- Testy kontraktowe to nagrania HTTP; dla `push` i `mail` transport i Symfony Mailer w pamięci ([docs/testing.md](../../docs/testing.md)).
- Konektor testowy `dummy` (`src/Connector/Dummy`, host `dummy.invalid`) nigdy nie jest włączony przy `APP_ENV=prod` (D-32).
- Nagrania w `tests/Fixtures/<Connector>` są oczyszczone: bez prawdziwych NIP, PESEL, nazw, rachunków i tokenów.
- Testy na żywych systemach tylko w `@group live`, na żądanie.
- Na cele testowe (KSeF TE, bramka JPK test, bramka e-Deklaracji test) wysyłasz wyłącznie dane fikcyjne, także fikcyjne dane autoryzujące.
- W repo, fixtures i docs są tylko dane fikcyjne, oznaczone jako fikcyjne.

**Kwoty, daty i parametry**

- Stawki, progi, kwoty ZUS i limity: `setting` z datą obowiązywania (`valid_from`) albo konfiguracja. Zero literałów w kodzie.
- Kwoty jako `NUMERIC` w bazie i `brick/math` w PHP, nigdy `float`; zaokrąglenia według [docs/data-model.md](../../docs/data-model.md).
- Daty prawne mają typ `date` w Europe/Warsaw, chwile `timestamptz` w UTC. Czas tylko z Symfony Clock; w testach `MockClock`.

**Czat**

- Tylko Claude Haiku 5.5: `chat.model` = `claude-haiku-5-5`, `chat.effort` ustawiany jawnie ([docs/modules/czat.md](../../docs/modules/czat.md)). Bez eskalacji do Sonnet 5.5 (D-10, D-29). Zmiana modelu tylko po teście.
- Odpowiedź w synchronicznym żądaniu z ramką Turbo, bez Mercure, workera i transportu czatu (D-29).
- Narzędzia czatu to nazwane zapytania z parametrami w JSON Schema, nigdy SQL. Połączenie Doctrine `chat` z rolą `chat_ro`: `SELECT` tylko na widokach `chat_*`.
- Jedyny zapis to `draft_document`: odbiorca tylko z bazy, szkic oznaczony źródłami nieufnymi. Bez narzędzi wysyłki i odbioru pism.

**Odłożone**

- Moduły odłożone (m.in. basal, API e-Doręczeń) i warunki powrotu: [docs/roadmap.md](../../docs/roadmap.md).

## Egzekwowanie

- Ten plik to kontekst, nie wymuszenie ([Claude Code](https://code.claude.com/docs/en/memory)). Ochrona działa w kodzie: test `HostAllowlist`, `CONNECTORS_ENABLED=0`, brak sekretów `prod` w sejfie deweloperskim.
- Reguły deny w `.claude/settings.json` to dodatkowa warstwa: pliki deszyfrujące, `.env.local`, ujawnianie sekretów, hosty PROD KSeF, JPK, e-Deklaracji i UA API, `*_TARGET=prod`.
- Wzorce deny są kruche, np. nie łapią polecenia w `sh -c` ([uprawnienia](https://code.claude.com/docs/en/permissions)). Warstwy ochrony: [docs/security.md](../../docs/security.md).

## Sposób pracy

**Krok 0 fazy**

1. Przeczytaj plik fazy `docs/phases/fX-*.md`, [docs/naming.md](../../docs/naming.md) i pliki z listy fazy.
2. Sprawdź w [docs/decisions.md](../../docs/decisions.md) wiersze potrzebne tej fazie (kolumna „Potrzebne w”).
3. Sprawdź w [docs/facts.md](../../docs/facts.md) wiersze z tą fazą w kolumnie „Fazy”, których „Następne sprawdzenie” już minęło. Części F2.1–F2.4 czytają wiersze F2.
4. Otwarte pytania fazy zadajesz w kroku 0 jednym zestawem. Stop przy: wejściu „stop” w pliku fazy, [F0] bez odpowiedzi, braku dostępu lub poświadczenia, fakcie z minioną datą „Następne sprawdzenie”, braku pliku fazy. Pozostałe: założenie domyślne z [docs/decisions.md](../../docs/decisions.md) po Twoim potwierdzeniu; D-xx zostaje „proponowana”.
5. F3, F5 i F6 mają zaślepki w [docs/README.md](../../docs/README.md); plik fazy powstaje z zaślepki, zatwierdzasz go przed kodowaniem. F4 i F7 są odłożone („Odłożone” w README, [docs/roadmap.md](../../docs/roadmap.md)) i nie startują bez nowego D-xx.
6. Przed kodowaniem: plan i pytania.

**W trakcie fazy**

- Nowa nazwa tabeli, stanu, wyliczenia, zmiennej albo usługi trafia najpierw do [docs/naming.md](../../docs/naming.md). Nazwa wycofana nie wraca.
- Temat zmieniasz w jego pliku-domu (tabela w „Jak czytać dokumentację” w README). Inne pliki tylko linkują.

**Koniec fazy**

1. Kryteria z pliku fazy oraz przekrojowe z [docs/testing.md](../../docs/testing.md), od tej fazy. Każdy wiersz ma kolumnę „Dowód”.
2. Zielone CI. Artefakty to raport JUnit i pliki z przebiegu CI; kryteria live mają przebieg joba `live`.
3. Piszesz ręcznie `docs/evidence/<phase>.md`: data, commit, link do przebiegu CI, kryterium → pass albo fail → artefakt. Wiersze `[manual]` odhaczasz Ty.
4. Status fazy i link w kolumnie „Dowód” w [docs/README.md](../../docs/README.md).

## Komendy

Komendy docelowe. Powstają w F0a; do tego czasu to cel, nie stan repo.

| Cel | Komenda |
| --- | --- |
| start | `docker compose up -d` |
| konsola | `docker compose exec app bin/console <komenda>` |
| zależności | `docker compose exec app composer install` |
| testy | `docker compose exec app vendor/bin/phpunit` (bez `@group live`) |
| testy live | `docker compose exec app vendor/bin/phpunit --group live`, tylko na żądanie; bootstrap odmawia przy `*_TARGET=prod` albo bez `CONNECTORS_ENABLED=1` |
| analiza statyczna | `docker compose exec app vendor/bin/phpstan analyse`; poziom 9 to cel, F0a zapisuje go w konfiguracji PHPStan |
| styl kodu | `docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff` |
| migracje | `bin/console doctrine:migrations:migrate --no-interaction` |
| workery | `bin/console messenger:consume reads`, `official_writes`, `notifications`; każdy w osobnym procesie, `official_writes` z jednym konsumentem |
| harmonogram | `bin/console messenger:consume scheduler_default` |
| nieudane wiadomości | `bin/console messenger:failed:show`; wiadomości z `official_writes` nie ponawiasz tą drogą |
| narzędzia kokpitu | `app:credential:smoke <kind>`, `app:audit:verify`, `app:files:verify`, `app:fixtures:record <connector> <scenariusz>`, `app:ledger:import`, `app:jpk:dry-run`; pełna lista w [docs/naming.md](../../docs/naming.md) |
| dowody fazy | `bin/console kokpit:evidence <phase> [--only=ci\|live]`: komendy kryteriów i artefakty z `manifest.json`, zamaskowane przez `Redactor` ([docs/testing.md](../../docs/testing.md)) |
| CI | GitHub Actions, id jobów w [docs/naming.md](../../docs/naming.md), m.in. `lint`, `unit_property`, `contract`, `xsd`, `integration`, `security`. `mutation` (Infection) blokuje merge, także przy `pull_request` (D-106). `live` tylko ręcznie, nigdy PROD |
| wdrożenie | `make deploy` na VPS, tylko projekt `kokpit-prod` ([docs/operations.md](../../docs/operations.md)) |

## Układ katalogów

| Katalog | Zawartość |
| --- | --- |
| `src/Connector/<System>` | konektory; nazwy katalogów z [docs/naming.md](../../docs/naming.md); `Dummy` tylko poza `APP_ENV=prod` |
| `src/Approval` | `ApprovalGate`, `SubmissionReconciler`, handler `official_writes` |
| `src/Calendar` | `PolishCalendar`, `ObligationEngine`, rejestr reguł terminów |
| `src/Ledger` | ewidencja przychodów, okresy |
| `src/Tax` | stawki, zaliczka, ZUS, reguły kursów |
| `src/Xml` | generatory i walidacja FA(3), JPK, VAT-UE |
| `resources/xsd` | schematy urzędowe z sumami kontrolnymi |
| `tests/Fixtures/<Connector>` | oczyszczone nagrania; złote pliki FA(3) w `tests/Fixtures/Ksef/golden` |
| `tests/Acceptance` | rejestr kryteriów `<phase>.yaml`, wejście `kokpit:evidence` |
| `config/secrets/<APP_ENV>` | sejf Symfony Secrets; klucz deszyfrujący nigdy w repo |
| `var/data/{env}/{kind}/{yyyy}/{sha256}.{ext}` | magazyn `FileStore`, poza repo |
| `var/evidence/<phase>/` | artefakty dowodów poza repo; `inbox/` na pliki kroków `[manual]`; w CI artefakt przebiegu |
| `docs/evidence/<phase>.md` | ręcznie pisana lista dowodów fazy, w repo; F2 ma jedną listę `f2.md` |

## Konwencje

- Identyfikatory (tabele, kolumny, klasy, wyliczenia, zmienne) po angielsku. Dokumentacja i panel po polsku.
- Słownik PL→EN i kontrakt nazw są w [docs/naming.md](../../docs/naming.md).
- Klucz główny `uuid` (UUIDv7), wyliczenia jako `text` z `CHECK`, stany przez Symfony Workflow ([docs/data-model.md](../../docs/data-model.md)).
- Wiadomości Messenger niosą tylko identyfikatory. Handlery i zadania cykliczne są idempotentne.
- Kody błędów konektorów to wyliczenia.
- Bez zależności wyłącznie na GPL, np. `mpdf/mpdf`. Lista dozwolonych licencji działa w CI.

## Definicja ukończenia

- Testy zielone w CI (GitHub Actions, wszystkie joby poza `live`), nie tylko lokalnie, razem z `mutation` (D-106). Faza z kryteriami live ma udany przebieg joba `live`.
- Każdy wiersz kryteriów fazy i przekrojowych ma klucz slug (D-33) i dowód w kolumnie „Dowód”.
- `docs/evidence/<phase>.md` jest w commicie i ma pass w każdym wierszu fazy i przekrojowym z [docs/testing.md](../../docs/testing.md).
- Wiersze `[manual]` odhaczyłeś Ty; każdy ma dowód (zrzut albo odhaczony wpis listy kontrolnej).
- Skaner fixtures i sprawdzenie licencji nic nie znajdują.
- Nowe nazwy są w [docs/naming.md](../../docs/naming.md), a plik-dom tematu jest aktualny.
- Status fazy w [docs/README.md](../../docs/README.md) linkuje listę dowodów.
- Nigdy nie ogłaszasz fazy ukończonej bez listy dowodów. Zielone testy w terminalu to nie dowód.

## Git

- Repo prywatne `good-yellow-bee/kokpit-jdg` (D-24).
- Gałąź na fazę, np. `f0a-szkielet`. Merge do `main` po odbiorze fazy.
- Commit tylko na Twoje polecenie.
- `CLAUDE.md` i `CLAUDE.local.md` są lokalne, w `.gitignore`; nigdy ich nie commitujesz. W repo są `.claude/rules/` i `.claude/settings.json`.
- Nigdy w commicie: `config/secrets/*/*.decrypt.private.php`, `.env.local`, `.env.local.php`, `.env.*.local`, klucze i certyfikaty (`*.pem`, `*.key`, `*.p12`, `*.pfx`), `var/`, dane i backupy.
