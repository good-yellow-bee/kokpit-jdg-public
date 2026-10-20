# F0a szkielet

Wąska F0a (D-28): prod na VPS, dev na laptopie, sejf, cele konektorów, kolejki i logi. Do tego panel przez Tailscale z jednym passkeyem, push ntfy, monitoring, backup, CI i lista dowodów. Stan na 8 października 2026.

## Cel

Po F0a kokpit działa na VPS, ale nie łączy się z żadnym urzędem. Następna jest F2.1, potem F2.4 i dry run JPK_EWP(4) do 15.12.2026 (D-30). Kolejne fazy dokładają konektory do gotowej infrastruktury: sejfu, allowlisty hostów, logów i transportów.

| Zasada | Skutek |
| --- | --- |
| na VPS działa tylko `kokpit-prod` (D-28) | kod i testy powstają na laptopie w `kokpit-dev`; kroki `[manual]` z serwera sprawdzasz na `kokpit-prod` |
| F0a nie ma konektorów urzędów, a `dummy` nie istnieje przy `APP_ENV=prod` (D-32) | `kokpit-prod` bez danych firmy jest bezpieczny do odbioru |
| dowód działania jest obowiązkowy (D-28) | faza kończy się listą `docs/evidence/f0a.md`: kryterium → pass albo fail → artefakt; wiersze `[manual]` odhaczone |
| plan trzyma się bramek i dat z [../README.md](../README.md) | F0a kończy się przed 15.11.2026, najpóźniejszym startem F2.1 |

## Wejścia

Brak wejścia oznaczonego „stop” zatrzymuje fazę i kończy się pytaniem. Pozostałe idą na założeniu domyślnym z [../decisions.md](../decisions.md) po Twoim potwierdzeniu w kroku 0.

| Wejście | Źródło | Brak oznacza |
| --- | --- | --- |
| hosting: VPS w UE tylko z projektem prod, RAM ≥ 2 GB; Tailscale i nazwa DNS w VPN dla TLS | Q-14 [F0], D-18, D-28 | stop: F0a nie startuje |
| laptop z Docker Compose dla `kokpit-dev` | D-28 | – |
| kanał push | Q-15, D-19 | założenie domyślne: self-hosted ntfy; treść to kategoria, liczba i link |
| dostawca SMTP dla maila zapasowego; poświadczenia w Symfony Secrets | Q-40, D-19, D-100 | założenie domyślne: dostawca w UE z umową powierzenia; odbiór `push-test-mail` czeka |
| cel backupu poza serwerem w UE; klucze w menedżerze haseł i na papierze | Q-41, D-100 | założenie domyślne: magazyn zgodny z S3 w UE u innego dostawcy; odbiór F0a czeka, termin przed końcem F0a |
| zewnętrzny serwis dead-man | Q-42, D-100 | założenie domyślne: healthchecks.io; odbiór F0a czeka |
| wymagane checki na `main` w repo prywatnym | Q-43 | założenie domyślne: bez wymuszenia; merge tylko przy zielonym CI na PR, pilnujesz Ty |
| umowy powierzenia z hostingiem, celem backupu i dostawcą SMTP (art. 28 [RODO](https://uodo.gov.pl/pl/file/1460)) | „RODO” w [../security.md](../security.md) | odbiór F0a czeka |
| repo prywatne `good-yellow-bee/kokpit-jdg` z commitem docs, `.claude/rules` w repo, `CLAUDE.md` lokalny; sprawdzenie: `git remote -v` | D-24 (Q-08) | stop: brak klonu z remote `origin`; bez `git init` |
| GitHub Actions w tym repo | D-24 | – |
| sekrety dwupoziomowe przez `SecretStore` | D-12 | – |
| cele per konektor niezależne od `APP_ENV` | D-13 | – |
| panel webowy bez PWA, Web Push i VAPID | D-08, D-28 | – |
| tanie decyzje na później: `SecretStore`, `Redactor`, zero GPL, adresy i wersje API w konfiguracji | D-25 | – |
| zastępca i break-glass | D-03, Q-18 | karta awaryjna bez zastępcy poza skrzynką ADE |

**Poza fazą.** Wynajęcie VPS według D-18 (Q-14) to działanie poza kodem; bez VPS kroki `[manual]` F0a na `kokpit-prod` czekają.

## Ładuj tylko

Ta lista jest autorytatywna. [../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md) ładuje się sam, a [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md) sam przy edycji konektorów, XML, XSD i fixtures.

| Plik | Sekcje |
| --- | --- |
| [../README.md](../README.md) | cały (start sesji), zwłaszcza „Status faz”, „Odłożone” i „Warunki wstępne” |
| [../naming.md](../naming.md) | cały |
| [../decisions.md](../decisions.md) | wiersze z F0a w kolumnie „Potrzebne w”; D-03, D-08, D-12, D-13, D-18, D-19, D-24, D-25, D-28, D-29, D-30, D-32, D-33, D-34 |
| [../facts.md](../facts.md) | fakty oznaczone dla F0a |
| [../architecture.md](../architecture.md) | cały |
| [../operations.md](../operations.md) | „Hosting”, „Usługi compose”, „Wdrożenie”, „Messenger”, „Katalog zadań” (zadania F0a), „Monitoring”, „Backup i odtwarzanie”, „Rotacje poświadczeń”, „Break-glass i zastępca” |
| [../security.md](../security.md) | cały |
| [../data-model.md](../data-model.md) | „Jak czytać”, „Konwencje”, „Daty i strefa czasu”, „Środowiska”, „Mapa tabel”; tabele `stored_file`, `connector_call`, `audit_log`, `rate_counter`, `credential_state`, `app_user`, `webauthn_credential`; „Magazyn plików” |
| [../testing.md](../testing.md) | cały (plan i koniec fazy) |

## Zakres

Granica F0a i F0b pochodzi z D-28 i tabeli „Status faz” w [../README.md](../README.md). F0a to infrastruktura bez przedmiotów zatwierdzeń, bez terminów i bez step-upu.

| Element | Co powstaje | Dom |
| --- | --- | --- |
| compose | jeden plik compose; projekt `kokpit-<KOKPIT_DEPLOYMENT>`: `kokpit-prod` na VPS, `kokpit-dev` na laptopie, `kokpit-restore` jednorazowo lokalnie; osobna baza per projekt; usługi `proxy`, `app`, `worker_reads`, `worker_official_writes` (dokładnie 1 replika), `worker_notifications`, `scheduler`, `db`, `ntfy`, `vpn` (sidecar Tailscale, Q-14), `backup` (tylko prod); rozszerzenia i ustawienia PHP | [../operations.md](../operations.md) |
| wdrożenie | `make deploy` na VPS dla `kokpit-prod`: kroki z „Wdrożenia”, migracje wstecznie zgodne, wycofanie; krok 3 pominięty do F0b; krok 7 `app:ops:record-deploy` zapisuje `ops.deployed` i zmiany celów | [../operations.md](../operations.md) |
| sejf | `SecretStore` (`get`, `put`): Symfony Secrets per `APP_ENV` i `credential_state` szyfrowane sodium secretbox, odświeżanie pod Symfony Lock; `app:credential:register`, `app:credential:smoke <kind>` z zapisem `last_smoke_ok_at` | [../security.md](../security.md) |
| cele konektorów | `KSEF_TARGET`, `JPK_TARGET`, `EDEKLARACJE_TARGET` z wartościami domyślnymi; `CONNECTORS_ENABLED=0`, gdy brak; mapa hostów konektorów z planu, bez odłożonych (D-29); `HostAllowlist` jako dekorator HttpClient; baner w panelu | [../architecture.md](../architecture.md) |
| konektor `dummy` | klasa `Dummy` w `src/Connector/Dummy`, `dummy_token`, licznik `dummy.call`, host `dummy.invalid` przez `MockHttpClient`; usługi tylko w `when@dev` i `when@test`, nigdy przy `APP_ENV=prod` (D-32) | [../naming.md](../naming.md), [../architecture.md](../architecture.md) |
| Messenger | transporty `reads`, `official_writes` (`max_retries: 0`, jeden konsument), `notifications`, `failed`; wiadomości z samymi identyfikatorami; limity czasu i pamięci workerów | [../operations.md](../operations.md) |
| push po błędzie | listener `failed` wysyła na `notifications` wiadomość z samym id; handler buduje treść: kategoria `connector_failure`, liczba, link; bez deduplikacji do F0b, która przenosi to na `notification` | [../operations.md](../operations.md) |
| harmonogram | `default` ze `stateful`, `processOnlyLastMissedRun` i `lock()`; zadania F0a: `heartbeat`, `files.verify_hashes`, `audit.verify_chain`, `retention.purge` | [../operations.md](../operations.md) |
| logi | `connector_call` przy każdym wywołaniu zewnętrznym; `audit_log` z łańcuchem `prev_hash` i `hash`, triggerem i rolą bez `UPDATE` i `DELETE`; `app:audit:verify` | [../security.md](../security.md) |
| liczniki | `rate_counter`: atomowy upsert, odłożenie zadania przy limicie, `blocked_until` z `Retry-After`; bez okien przesuwnych (D-29) | [../architecture.md](../architecture.md) |
| maskowanie | `Redactor` w Monolog, nagłówkach HttpClient i serializerach; `zend.exception_ignore_args=On`; bez logowania treści żądań i odpowiedzi konektorów | [../security.md](../security.md) |
| pliki | `FileStore` i `stored_file`: zapis jednokrotny adresowany sha256, `app:files:verify`, retencja z `legal_hold` | [../data-model.md](../data-model.md), [../security.md](../security.md) |
| czas | Symfony Clock; jedna funkcja daty biznesowej w Europe/Warsaw z testami na północ i zmianę czasu | [../data-model.md](../data-model.md) |
| panel | Twig i Symfony UX; TLS na nazwie DNS w VPN (`tailscale cert`, Q-14); logowanie jednym passkeyem przez `web-auth/webauthn-symfony-bundle`; `app:passkey:enrol-link` i `app:passkey:reset` w wersji minimalnej; sesja, CSRF, CSP, HSTS; step-up i drugi passkey w F0b (D-28) | [../security.md](../security.md) |
| powiadomienia | konektory `push` (ntfy) i `mail` (zapas SMTP); `app:push:test [--channel=mail]`; treść: kategoria, liczba i link | [../security.md](../security.md) |
| monitoring | `/health` z listą sprawdzeń; konektor `deadman` pinguje zewnętrzny serwis po zadaniach oznaczonych w katalogu, tylko z `kokpit-prod` | [../operations.md](../operations.md) |
| backup | `pg_dump` i restic do celu w UE, retencja snapshotów; `backup.run`: cron 02:30 w usłudze `backup`, poza harmonogramem Symfony; ping dead-man z kontenera po udanym `restic backup`; test odtworzenia w jednorazowym `kokpit-restore` na laptopie z `CONNECTORS_ENABLED=0` (D-28) | [../operations.md](../operations.md) |
| testy | testsuite PHPUnit per katalog: `unit`, `property`, `contract`, `xsd`, `integration`, `security`, `live`; domyślny przebieg bez `live`, przy `CONNECTORS_ENABLED=0`; bootstrap `live` odmawia przy `*_TARGET=prod` albo `CONNECTORS_ENABLED` różnym od `1`; `phpstan` poziom 9 z regułami architektury; `php-cs-fixer`; lista dozwolonych licencji; nagrania przez `MockHttpClient` i `Psr18Client` za `HostAllowlist`; `app:fixtures:record` ze scrubberem; skaner fixtures; `MockClock`; biblioteka testów własności zapisana w [../testing.md](../testing.md) (pakiet, wersja) | [../testing.md](../testing.md) |
| CI | `.github/workflows/ci.yml` z jobami z tabeli „CI” niżej | [../testing.md](../testing.md) |
| dowód działania | `kokpit:evidence <phase>` z `--only=ci` albo `--only=live`; rejestr `tests/Acceptance/f0a.yaml` bez testu zgodności z tabelą (D-28); `manifest.json`; pliki kroków `[manual]` w `var/evidence/f0a/inbox/`; ręcznie pisana lista `docs/evidence/f0a.md` | [../testing.md](../testing.md), [../naming.md](../naming.md) |
| karta awaryjna | dostęp do hostingu, miejsce kluczy, lista obowiązków z narzędziem MF, kontakt do doradcy, administrator skrzynki ADE (Q-18) | [../operations.md](../operations.md) |

Pracujesz na klonie repo, na gałęzi fazy `f0a-szkielet`, według [../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md).

**CI.** Plik `.github/workflows/ci.yml` w `good-yellow-bee/kokpit-jdg`. Kontrakt CI: [../testing.md](../testing.md); id jobów: [../naming.md](../naming.md). Każdy job PHPUnit uruchamia swój testsuite.

- Tylko `live` startuje przy `workflow_dispatch`; pozostałe joby startują przy `push` i `pull_request`.
- Fazę daje prefiks gałęzi (`f0a-szkielet` → `f0a`); push do `main` pomija `evidence`.
- Id joba nie dopuszcza znaku `+`, więc job ma id `unit_property` i `name: unit+property` (D-34).

| Job | W F0a | Dochodzi później |
| --- | --- | --- |
| `lint` | cały kod; `php-cs-fixer`, `phpstan` (poziom 9) z regułami architektury, lista dozwolonych licencji | zapisy tylko z workera bramki (F0b) |
| `unit_property` | `Redactor`, scrubber, data biznesowa, strażnik `live` | ewidencja i generator JPK (F2.1, F2.4); `PolishCalendar` i `ApprovalGate` (F0b) |
| `contract` | `dummy`, `push`, `mail` | KSeF open-api (F1) |
| `xsd` | sumy kontrolne `resources/xsd` | JPK_EWP(4) i JPK_ST(1) (F2.4), FA(3) (F3), JPK_V7M(3) i VAT-UE (F5) |
| `integration` | `audit_log`, `stored_file`, `rate_counter`, `credential_state`, transport `failed`, logowanie do panelu | `revenue_entry`, `setting` (F2.1); `approval`, `obligation`, `notification` (F0b) |
| `security` | `HostAllowlist`, maskowanie, skanery | obejście bramki (F0b), wstrzyknięcie w czat (F6) |
| `mutation` | `infection.json5` z progami z „Testów mutacyjnych” w [../testing.md](../testing.md); zakres pusty, job zapisuje „brak kodu w zakresie”; doradczo: `continue-on-error: true`, poza wymaganymi checkami (D-28) | zakres rośnie z `src/Ledger` (F2.1), `PolishCalendar` i `ApprovalGate` (F0b); blokuje merge od D-106 |
| `evidence` | faza `f0a`; PostgreSQL jako kontener usługi; sejf tymczasowy z wartościami fikcyjnymi tworzony na starcie joba; `kokpit:evidence f0a --only=ci` i wysyłka `var/evidence/**` jako artefakt | każda faza |
| `live` | tylko `workflow_dispatch`; w F0a jeden test celów, bez sekretów i bez wywołań urzędów | KSeF TE (F1), dalsze cele testowe |

## Poza zakresem

- Tabela `setting` i minimalny panel edycji: [f2-ewidencja.md](f2-ewidencja.md), F2.1 według [../data-model.md](../data-model.md).
- Bramka zatwierdzeń, step-up, drugi passkey, `PolishCalendar`, `ObligationEngine`, tabele `approval`, `obligation`, `notification`, klucze kalendarza i przeliczenia po edycji `setting`: [f0b-bramka-kalendarz.md](f0b-bramka-kalendarz.md), po dry runie (D-30).
- `revenue_entry`, import ewidencji 2026 i generator JPK: [f2-ewidencja.md](f2-ewidencja.md), F2.1 i F2.4 zaraz po F0a.
- Konektory urzędów i rejestrów: KSeF, VIES, biała lista i NBP w [f1-ksef-odczyt.md](f1-ksef-odczyt.md); FreshBooks w F2.2/F2.3; reszta w [f3-ksef-sprzedaz.md](f3-ksef-sprzedaz.md), [f5-jpk.md](f5-jpk.md) i [f6-czat.md](f6-czat.md).
- Odłożone (D-29, sekcja „Odłożone” w [../README.md](../README.md)): API e-Doręczeń z `EDORECZENIA_TARGET`, basal z tabelą `classification`, rejestry KRS, CEIDG i GUS.
- Wycięte z F0a (D-28): projekty dev, staging i restore na VPS, PWA, Web Push, VAPID, `kokpit:demo`, wykonawca `host`, generator podsumowania.
- Usługa `pdf` (F3, tylko zapas z D-17).
- Jakikolwiek zapis w urzędzie.

## Kryteria odbioru

Komendy `bin/console` i `vendor/bin` uruchamiasz w kontenerze `app` według [../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md). `make`, `curl`, `docker compose` i `gh` uruchamiasz na urządzeniu z wiersza. Wiersze niżej są tylko dla F0a; reszta to kryteria przekrojowe.

| Zasada | Reguła |
| --- | --- |
| klucz | slug na początku kolumny „Kryterium” (D-33, [../naming.md](../naming.md)) |
| wykonawca | `ci:` to komenda albo test w jobie CI; `[manual]` to Twój krok na VPS, laptopie albo telefonie |
| `junit.xml`: `Nazwa*` | przypadki z raportu JUnit przebiegu o nazwie zaczynającej się od `Nazwa` |
| `cmd/<klucz>.txt` | wyjście komendy w paczce, po `Redactor` |
| `inbox/<klucz>.<ext>` | plik kroku `[manual]` w `var/evidence/f0a/inbox/`; `kokpit:evidence` dołącza go z sha256 |
| wynik | `pass`, `fail`, `pending`; `[manual]` bez pliku ma `pending` do Twojego odhaczenia w `docs/evidence/f0a.md` |

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `secret-store` sejf | `ci:` `vendor/bin/phpunit --filter SecretStore` | sekret statyczny czytany z sejfu; rodzaj rotujący zapisany przez `put` i odczytany przez `get`; w `credential_state` brak jawnej wartości | `junit.xml`: `SecretStore*` |
| `credential-register` rejestracja poświadczenia | `ci:` `bin/console app:credential:register dummy_token --valid-until=<dziś+90 dni>` przy `APP_ENV=test` | wiersz `credential_state` w stanie `valid`; wpis `credential.registered` | `cmd/credential-register.txt`: wyjście, wiersz bez wartości sekretu, wpis |
| `credential-smoke` test poświadczenia | `ci:` `bin/console app:credential:smoke dummy_token` przy `APP_ENV=test`, `CONNECTORS_ENABLED=1` i celach testowych; `dummy` odpowiada przez `MockHttpClient` | `ok`; `last_smoke_ok_at` ustawione; jeden wiersz `connector_call` z `connector` = `dummy` | `cmd/credential-smoke.txt` |
| `connector-call-log` log wywołania | `ci:` test: wywołanie `dummy` przez `HostAllowlist` | jeden wiersz `connector_call` z hashem i rozmiarem, bez treści | `junit.xml`: `ConnectorCall*` |
| `failed-transport` błąd handlera | `ci:` test: handler rzuca wyjątek | wiadomość w `failed`; na `notifications` wiadomość z samym id; push `connector_failure` z liczbą i linkiem; w `messenger_messages` tylko identyfikatory | `junit.xml`: `FailedTransport*` |
| `file-store` magazyn plików | `ci:` `vendor/bin/phpunit --filter FileStore` | ten sam plik dla dwóch właścicieli: jeden plik na dysku, dwa wiersze `stored_file`; ponowny zapis tego samego właściciela bez nowego wiersza; plik tylko do odczytu; zmianę na dysku wykrywa `app:files:verify` | `junit.xml`: `FileStore*` |
| `rate-counter` liczniki | `ci:` `vendor/bin/phpunit --filter RateCounter` | równoległe zwiększanie daje dokładny licznik; po limicie zadanie odłożone, bez błędu; 429 z `Retry-After` ustawia `blocked_until`, przed nim zero wywołań | `junit.xml`: `RateCounter*` |
| `retention-purge` retencja | `ci:` test: plik po `retention_until`, raz bez `legal_hold`, raz z nim | pierwszy usunięty (`deleted_at`, wpis `file.deleted`); drugi zostaje | `junit.xml`: `RetentionPurge*` |
| `target-change` zmiana celu | `ci:` test: dwa przebiegi `app:ops:record-deploy` z innym `KSEF_TARGET` i `CONNECTORS_ENABLED`; panel w WebTestCase | pierwszy przebieg: tylko `ops.deployed`; drugi: po jednym `connector.target_changed` i `connector.enabled_changed`; baner pokazuje cel inny niż `prod` i `CONNECTORS_ENABLED=0` | `junit.xml`: `RecordDeploy*`, `TargetBanner*` |
| `health-checks` `/health` | `ci:` WebTestCase: każde sprawdzenie z „Monitoringu” w [../operations.md](../operations.md) raz w stanie błędu, w tym brak bazy | 503 przy błędzie, 200 bez błędów; JSON bez danych osobowych | `junit.xml`: `HealthCheck*` |
| `live-guard` strażnik `live` | `ci:` `vendor/bin/phpunit --testsuite live` przy domyślnym `CONNECTORS_ENABLED=0` | bootstrap odmawia startu, zero żądań; odmowę przy `*_TARGET=prod` sprawdza test jednostkowy na wstrzykniętych zmiennych | `cmd/live-guard.txt`: komunikat i kod wyjścia; `junit.xml`: `LiveGuard*` |
| `scrubber` czyszczenie nagrań | `ci:` `vendor/bin/phpunit --filter Scrubber` | nagranie z NIP, PESEL i JWT spoza listy fikcyjnych zapisane bez tych wartości | `junit.xml`: `Scrubber*` |
| `ci-shape` kształt CI | `ci:` `vendor/bin/phpunit --filter CiWorkflow` | joby i id z tabeli „CI”, w tym `unit_property`; każdy job PHPUnit uruchamia swój testsuite; `live` tylko przy `workflow_dispatch`, reszta przy `push` i `pull_request`; `evidence` bierze fazę z gałęzi; żaden job nie ustawia `*_TARGET=prod`; joby poza `live` bez sekretów; `mutation` bez `continue-on-error`, także przy `pull_request` (D-106); `evidence` wysyła `var/evidence/**` | `junit.xml`: `CiWorkflow*` |
| `evidence-pack` paczka dowodów | `ci:` `vendor/bin/phpunit --filter Evidence` | wiersz `ci` bez `pass` daje kod wyjścia różny od 0; wiersz `[manual]` bez pliku w `inbox/` ma `pending` i nie zmienia kodu; fikcyjny sekret, PESEL i JWT nie trafiają do paczki; sha256 w `manifest.json` zgodne z plikami | `junit.xml`: `Evidence*` |
| `deploy-prod` wdrożenie | `[manual]` na VPS: `make deploy` dla `kokpit-prod` z `CONNECTORS_ENABLED=0`, potem `docker compose ps --format json` | kroki 1–7 z „Wdrożenia” w [../operations.md](../operations.md), krok 3 pominięty do F0b; usługi F0a działają, `worker_official_writes` ma jedną replikę; `/health` 200; wpis `ops.deployed` | `inbox/deploy-prod.txt`: log `make deploy` z kodem 0, wyjście `ps` i wiersz `ops.deployed` |
| `vpn-only` dostęp tylko w VPN | `[manual]` `curl -sSv https://<nazwa panelu>/health` z urządzenia w Tailscale; to samo spoza VPN | w VPN certyfikat zaufany bez ostrzeżeń; spoza VPN brak odpowiedzi | `inbox/vpn-only.txt`: łańcuch certyfikatu i timeout spoza VPN |
| `passkey-enrol` pierwszy passkey | `[manual]` `bin/console app:passkey:enrol-link` przez SSH na VPS, rejestracja passkeya, logowanie; drugie użycie linku i link po 15 minutach | rejestracja i logowanie dają wpisy `auth.passkey_enrolled` i `auth.login`; drugie użycie i stary link dają odmowę | `inbox/passkey-enrol.txt`: wyjście komendy z linkiem zamaskowanym przez `Redactor`, oba wpisy; `inbox/passkey-enrol.png`: odmowa |
| `passkey-reset` odzyskanie dostępu | `[manual]` `bin/console app:passkey:reset` przez SSH, potem rejestracja z nowego linku | wszystkie passkeye unieważnione; nowy link działa; wpisy `auth.passkey_revoked` i `auth.recovery_link_issued` | `inbox/passkey-reset.txt`: wyjście z zamaskowanym linkiem i wpisy |
| `connectors-enabled` włączenie konektorów | `[manual]` zmiana `CONNECTORS_ENABLED` z `0` na `1` osobnym wdrożeniem `kokpit-prod` | wpis `connector.enabled_changed`; baner bez komunikatu `CONNECTORS_ENABLED=0`; działają tylko `push`, `mail` i `deadman` | `inbox/connectors-enabled.txt`: wpis; `inbox/connectors-enabled.png`: panel |
| `push-test` push na telefon | `[manual]` `bin/console app:push:test` na `kokpit-prod` | push dochodzi na telefon; treść to kategoria, liczba i link | `inbox/push-test.png`: zrzut; `inbox/push-test.txt`: wyjście komendy |
| `push-test-mail` mail zapasowy | `[manual]` `bin/console app:push:test --channel=mail` na `kokpit-prod` | mail dochodzi; treść to kategoria, liczba i link | `inbox/push-test-mail.png`: zrzut; `inbox/push-test-mail.txt`: wyjście komendy |
| `emergency-card` karta awaryjna | `[manual]` karta awaryjna | jest w menedżerze haseł i na papierze; klucz sejfu PROD i klucz restic poza serwerem | odhaczony wpis z datą w `docs/evidence/f0a.md`, bez treści karty i bez zrzutu |
| `ci-green` CI zielone na PR | `[manual]` `gh run view <id> --json conclusion,event,headSha,jobs,url` dla przebiegu `pull_request` z `f0a-szkielet` do `main` | joby `lint`, `unit_property`, `contract`, `xsd`, `integration` i `security` zielone; `mutation` z wynikiem w logu, bez blokady; `evidence` wysłał paczkę; `live` pominięty; `headSha` równy commitowi z `manifest.json` albo potomkiem ze zmianami tylko w `docs/` | `inbox/ci-green.json`; adres przebiegu w `docs/evidence/f0a.md` |

Dawne kryterium Fazy 0 („każde zadanie zostawia wpis w `audit_log`”) brzmi teraz według [../security.md](../security.md): wywołanie zewnętrzne zostawia `connector_call`, akcja z listy zostawia `audit_log`.

**Kryteria przekrojowe:** [../testing.md](../testing.md), wszystkie wiersze „od F0a”, w tym test odtworzenia z backupu w jednorazowym projekcie lokalnym (D-28). `kokpit:evidence f0a` uruchamia też ich wiersze `ci`. Tabele `approval` i `notification` powstają w F0b, `revenue_entry` w F2.1, a `filing` w F2.4. Do tego czasu pomijane są:

- krok 3 `make deploy` i sprawdzenie `unknown` w `/health`, dopóki nie ma `approval`;
- wiek `notification` w `retention.purge` i `notification` w kryterium maskowania;
- w teście odtworzenia tabele spoza F0a: porównujesz tylko `audit_log` i `stored_file`.

## Go-live

F0a nie ma osobnego wdrożenia PROD. Jedyny projekt na VPS to `kokpit-prod` z wierszy `deploy-prod` i `connectors-enabled`. Punkty niżej odhaczasz z datą w `docs/evidence/f0a.md`, bez treści umów i sekretów.

- `CONNECTORS_ENABLED=1` na PROD włącza tylko `push`, `mail` i `deadman`, bo innych konektorów jeszcze nie ma.
- Sejf PROD zawiera tylko sekrety F0a.
- Checki dead-man skonfigurowane w zewnętrznym serwisie; pierwszy backup i test odtworzenia zrobione.
- `[manual]` umowy powierzenia z hostingiem, celem backupu i dostawcą SMTP zawarte.
- Merge `f0a-szkielet` do `main` po odbiorze. Commit `docs/evidence/f0a.md` zmienia tylko `docs/`, a przebieg PR jest zielony ([../testing.md](../testing.md)).
- `workflow_dispatch` działa dopiero, gdy plik workflow jest na gałęzi domyślnej ([GitHub, sprawdzone 8.10.2026](https://docs.github.com/en/actions/writing-workflows/choosing-when-your-workflow-runs/events-that-trigger-workflows)). Do merge to, że `live` startuje tylko ręcznie, dowodzi `ci-shape`.
- Następna faza to F2.1 ([f2-ewidencja.md](f2-ewidencja.md), D-30). Obowiązki dalej realizujesz według „Teraz – zgodność” w [../README.md](../README.md).

## Pierwszy prompt

```text
Przeczytaj docs/README.md i docs/phases/f0a-szkielet.md, potem tylko pliki z sekcji „Ładuj tylko”.
Krok 0: docs/naming.md, wiersze F0a w docs/decisions.md i fakty F0a w docs/facts.md.
Brak wejścia oznaczonego „stop” (hosting, Q-14) oznacza stop i pytanie.
Zrealizuj wąską F0a (D-28): jeden plik compose z kokpit-prod na VPS i kokpit-dev na laptopie,
SecretStore (Symfony Secrets i credential_state), cele per konektor z HostAllowlist, konektor dummy,
transporty Messenger, connector_call, audit_log, rate_counter, Redactor,
FileStore, panel przez Tailscale z TLS i jednym passkeyem, push ntfy z mailem zapasowym,
/health z dead-man, backup z testem odtworzenia w jednorazowym projekcie lokalnym,
CI w GitHub Actions, rejestr tests/Acceptance/f0a.yaml i komendę kokpit:evidence.
Zanim zaczniesz kodować, wypisz plan i pytania. Na koniec uruchom bin/console kokpit:evidence f0a
i napisz docs/evidence/f0a.md: kryterium → pass albo fail → artefakt, link do zielonego
przebiegu CI i lista otwartych wierszy [manual].
```

## Dowód fazy

`docs/evidence/f0a.md`: ręcznie pisana lista (D-28) z datą, commitem, linkiem do przebiegu CI z artefaktem `evidence` i wierszami kryterium → pass albo fail → artefakt. Faza jest skończona tylko z tym plikiem, wszystkimi wierszami `pass` i odhaczonymi wierszami `[manual]`.
