# Operacje

Hosting, usługi compose, wdrożenie, Messenger, katalog zadań, monitoring, backup, rotacje poświadczeń, go-live i break-glass. Stan na 8 października 2026.

## Hosting

Decyzja przyjęta 2026-10-09: Q-14, D-18; dostawcy 2026-10-20: D-100. Na VPS działa tylko projekt `kokpit-prod`; dev działa na laptopie, test odtworzenia w jednorazowym projekcie lokalnym (D-28).

| Wymóg | Dlaczego | Źródło |
| --- | --- | --- |
| nazwa DNS w VPN z zaufanym certyfikatem | passkey i przekierowanie OAuth FreshBooks wymagają HTTPS | [security.md](security.md) |
| brak publicznych portów | panel, baza i workery tylko w Tailscale albo w sieci compose | [security.md](security.md) |
| lokalizacja w UE | dane podatkowe i RODO; przechowywanie poza Polską sprawdzasz z art. 112a ustawy o VAT | [security.md](security.md) |
| architektura x86_64 (amd64), KVM z `/dev/net/tun` | job CI `images` buduje obrazy tylko dla amd64; sidecar Tailscale potrzebuje `/dev/net/tun`, więc kontenery LXC i OpenVZ odpadają | `.github/workflows/ci.yml`, `compose.yaml` (usługa `vpn`) |
| zegar przez NTP | ważność tokenów KSeF; P_1 to dzień wysyłki w Europe/Warsaw (D-16) | [uwierzytelnianie KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/uwierzytelnianie.md) |
| stały publiczny IPv4 | teraz niepotrzebny; dostęp INT e-Doręczeń działa tylko ze zgłoszonych adresów, więc wymóg wraca z F4 (D-29) | [regulamin INT](https://www.gov.pl/attachment/f8d3d63b-74ca-4c89-b35b-773eaf2a924a) |

**Dostawcy (D-100).**

| Usługa | Dostawca |
| --- | --- |
| VPS | OVHcloud VPS-1 (2 vCore, 4 GB RAM, 40 GB NVMe) w Warszawie |
| VPN | Tailscale Standard |
| backup restic | Scaleway Object Storage, region PL-WAW (Q-41) |
| mail zapasowy | Scaleway Transactional Email, `smtp.tem.scaleway.com` (Q-40) |
| dead-man | healthchecks.io Hobbyist (Q-42) |
| obrazy | GHCR, pakiety publiczne |

**Pamięć (szacunek).** Minimum to 2 GB RAM dla jednego projektu `kokpit-prod`: PostgreSQL, PHP-FPM, trzy workery, `scheduler`, `proxy`, `ntfy` i sidecar Tailscale. Czat (F6) nie dodaje usług, a wariant z basal odpada (D-29).

## Usługi compose

Każde wdrożenie to osobny projekt compose z osobną bazą. `KOKPIT_DEPLOYMENT` wybiera projekt `kokpit-<wartość>` ([naming.md](naming.md)).

| Usługa | Rola | Porty | Faza |
| --- | --- | --- | --- |
| `proxy` | TLS na nazwie DNS w Tailscale, przekazanie do `app` | tylko interfejs VPN | F0a |
| `app` | PHP-FPM, panel i `/health` | brak | F0a |
| `worker_reads` | `messenger:consume reads` | brak | F0a |
| `worker_official_writes` | `messenger:consume official_writes`; dokładnie jedna replika | brak | F0a |
| `worker_notifications` | `messenger:consume notifications` | brak | F0a |
| `scheduler` | `messenger:consume scheduler_default` | brak | F0a |
| `db` | PostgreSQL, wersja przypięta w obrazie | brak | F0a |
| `ntfy` | self-hosted push (D-19); odpada przy ntfy.sh (Q-15) | tylko interfejs VPN | F0a |
| `vpn` | sidecar Tailscale; przy Tailscale na hoście usługi brak (Q-14) | – | F0a |
| `backup` | `pg_dump` i restic według harmonogramu | brak | F0a |
| `pdf` | kontener Node przypięty do commita, tylko przy zapasie z D-17 | brak | F3 |

Usługi mają `restart: unless-stopped`. Workery i `scheduler` mają `stop_grace_period: 120s`. Z `pcntl` worker po SIGTERM kończy bieżącą wiadomość i dopiero wtedy wychodzi ([Symfony Messenger](https://symfony.com/doc/current/messenger.html)). Test kryterium przekrojowego sprawdza brak opublikowanych portów poza interfejsem VPN ([testing.md](testing.md)).

Czat działa w żądaniu panelu, bez osobnych usług (D-29). Kontenery basal są odłożone razem z F7 ([modules/basal.md](modules/basal.md)).

`compose.yaml` nie montuje kodu ani `vendor/`: aplikacja i workery mają kod oraz zależności z `composer.lock` w obrazie `production`; `proxy` ma `public/` wraz ze skompilowanymi zasobami z tej samej wersji. Plik `compose.dev.yaml` dodaje montowania checkoutu i wolumenu `vendor` oraz etap `development` wyłącznie na laptopie. `.env` wybiera oba pliki przez `COMPOSE_FILE`; każda komenda PROD i restore używa jawnie `docker compose -f compose.yaml`, więc pomija domyślny plik dev. Dla PROD operator ustawia `KOKPIT_DEPLOYMENT`, `APP_ENV` i profile zgodnie z tabelą niżej.

PHP-FPM słucha tylko na `127.0.0.1:9000` z `listen.allowed_clients=127.0.0.1`. Log dostępu FPM i logi żądań nginx są wyłączone; `error_log /dev/null` w obu serwerach nginx zapobiega ujawnieniu URI linku rejestracji także przy błędach upstream. Błędy aplikacji pozostają w maskowanym Monolog, a stan usług w `/health`.

**Wdrożenia**

Cele konektorów są niezależne od `APP_ENV` ([architecture.md](architecture.md)), więc tabela podaje je osobno.

| Projekt | Gdzie | `APP_ENV` | Sejf i klucz | Cele domyślne | Panel | Usługi | Dead-man |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `kokpit-prod` | VPS | `prod` | sejf `prod`; klucz 0400 na hoście ([security.md](security.md)) | każdy cel zmieniany osobno według checklisty go-live | nazwa DNS w Tailscale, port 443 | wszystkie z tabeli wyżej | checki PROD |
| `kokpit-dev` | laptop | `dev` | sejf `dev` tylko z sekretami celów testowych | testowe; `CONNECTORS_ENABLED` według potrzeby | `localhost` | bez `backup` i `vpn` | brak |
| `kokpit-restore` | laptop, jednorazowy | `prod` | bez sejfu PROD; plik hasła bazy projektu i jednorazowy sekret frameworka (D-89) | testowe; `CONNECTORS_ENABLED=0` | brak | tylko `db` i kontenery jednorazowe `scripts/restore-test.sh`; usuwany po teście | brak |
| CI | GitHub Actions | `test` | sejf tymczasowy z fikcyjnymi wartościami; job `live` czyta sejf `dev` ([security.md](security.md), [testing.md](testing.md)) | testowe | – | joby z [testing.md](testing.md) | brak |

- Na VPS działa tylko `kokpit-prod` (D-28): bez drugiego klucza sejfu, drugiej nazwy DNS i drugiego węzła Tailscale.
- Hosty PROD rejestrów wynikają z konfiguracji wdrożenia, czyli `APP_ENV=prod` ([architecture.md](architecture.md), [modules/rejestry-kursy.md](modules/rejestry-kursy.md)).

**Rozszerzenia PHP:** `pdo_pgsql`, `intl`, `bcmath`, `sodium`, `openssl`, `soap`, `zip`, `xml` (`dom`, `libxml`, `xmlreader`, `xmlwriter`, `simplexml`), `gd`, `curl`, `mbstring`, `opcache`, `pcntl`.

**Ustawienia PHP:** `zend.exception_ignore_args=On`, `date.timezone=UTC`. Daty biznesowe w Europe/Warsaw liczy jedna funkcja ([data-model.md](data-model.md)).

## Wdrożenie

`make deploy` działa tylko na VPS dla `kokpit-prod`. Wykonuje kroki w tej kolejności. Krok, który się nie powiedzie, zatrzymuje wdrożenie. Po przerwaniu w krokach 3–5 `docker compose -f compose.yaml start` wznawia stare kontenery.

| Krok | Komenda | Po co |
| --- | --- | --- |
| 1 | `git pull`, pobranie obrazów `app` i `proxy` commita z rejestru, weryfikacja podpisu (`cosign verify`); `APP_IMAGE` i `PROXY_IMAGE` przypięte do digestu | nowa wersja tylko z podpisanych obrazów CI; poprzednie obrazy zostają lokalnie pod tagiem swojego commita |
| 2 | `docker compose -f compose.yaml stop scheduler worker_official_writes worker_reads worker_notifications` | workery kończą bieżącą wiadomość w `stop_grace_period` i stoją do kroku 6 |
| 3 | sprawdzenie, że żadne `approval` nie jest w stanie `dispatching`; jeśli jest, wdrożenie staje | wdrożenie nie przerywa wysyłki do urzędu |
| 4 | `pg_dump` przed migracją | punkt powrotu |
| 5 | `docker compose -f compose.yaml run --rm app bin/console doctrine:migrations:migrate --no-interaction`, następnie `docker compose -f compose.yaml run --rm app bin/console cache:clear` | schemat i cache na nowym obrazie |
| 6 | `docker compose -f compose.yaml up -d` | nowe kontenery |
| 7 | pierwszy backup, gdy `last_success` jeszcze nie istnieje; `/health` zwraca 200 (z kontenera `app` przez adres VPN, do 28 prób co 15 s); `docker compose -f compose.yaml run --rm app bin/console app:ops:record-deploy` | potwierdzenie; wpis `ops.deployed` ze szczegółami: commit, digest obrazu `app` w rejestrze, `*_TARGET`, `CONNECTORS_ENABLED` |

- Bez `messenger:stop-workers`: przy `restart: unless-stopped` Compose podniósłby workery na starych kontenerach zaraz po wyjściu.
- Stan `dispatching` ustawia tylko worker, więc po kroku 2 nowa wysyłka nie startuje. Zatwierdzenie z panelu czeka w kolejce w stanie `approved` ([approval-gate.md](approval-gate.md)).
- Krok 3 to `bin/console app:approval idle`: kod 1 przy zatwierdzeniu w `dispatching`. Przy pierwszym wdrożeniu F0b tabeli `approval` jeszcze nie ma, więc krok przechodzi (D-41).
- Migracje są wstecznie zgodne (najpierw rozszerzenie schematu, usunięcie w kolejnym wydaniu).
- Wycofanie: `docker compose -f compose.yaml up -d` z obrazami commita z poprzedniego `ops.deployed`, bez budowy i instalowania zależności; przed startem `cache:clear` na wybranym poprzednim obrazie, bo cache jest na wolumenie runtime; kod, zależności i zasoby wracają razem. Przy migracji niezgodnej wstecz także odtworzenie zrzutu z kroku 4.
- Zmiana `*_TARGET` albo `CONNECTORS_ENABLED` to osobne wdrożenie z checklisty go-live, nigdy przy okazji.
- Krok 7 porównuje szczegóły z poprzednim `ops.deployed`. Każda różnica daje jeden wpis `connector.target_changed` albo `connector.enabled_changed` ([architecture.md](architecture.md)). Pierwsze wdrożenie zapisuje tylko `ops.deployed`.
- Wpisy zmian celu powstają tylko w kroku 7, nie przy starcie kontenerów, więc nie mają duplikatów.
- `deploy` i `rollback` najpierw czytają plik wdrożenia `KOKPIT_ENV_FILE` (domyślnie `/etc/kokpit/prod.env`, D-98), jeśli istnieje; jego wartości wygrywają ze zmiennymi powłoki. Bez pliku zmienne podajesz w powłoce jak dotąd.
- `/health` w kroku 7 idzie z kontenera `app` na nazwę panelu z `PANEL_ORIGIN` przez adres `tailscale ip -4` sidecara `vpn` (`curl --resolve`), bo host nie jest w tailnecie. Pierwsze wdrożenie czeka na pierwszy przebieg harmonogramu (co 5 min), stąd ponowienia (`HEALTH_ATTEMPTS`, `HEALTH_INTERVAL`). Pierwszy backup w kroku 7 sprawdza od razu pliki hosta backupu i ping `backup.run`.
- Makefile wymaga `KOKPIT_DEPLOYMENT=prod` (z pliku wdrożenia albo z powłoki); `deploy` wymaga też `PANEL_ORIGIN` (adres HTTPS panelu w VPN). Ustawia `APP_ENV=prod`, `APP_DEBUG=0` i `COMPOSE_PROFILES=prod`, pomija `COMPOSE_PROJECT_NAME` i używa tylko `compose.yaml`. Nie ustawia celów konektorów ani `CONNECTORS_ENABLED`: operator wybiera je ręcznie. Zrzut przed migracją trafia do lokalnego `backups/deploy-<sha>-<sufiks>` z uprawnieniami 0600; plik `.partial` oznacza przerwany zrzut. Wrapper migracji kopiuje poświadczenie `kokpit_owner` z wolumenu tylko do odczytu do pliku tymczasowego 0400 w kontenerze i uruchamia PHP jako `www-data`, aby zachować właściciela cache; pozostałe komendy aplikacji używają roli `kokpit_app`.
- `make rollback KOKPIT_COMMIT=<sha>` bierze commit z poprzedniego `ops.deployed`. Obrazy `app` i `proxy` tego commita muszą być lokalnie; przed startem przechodzą tę samą weryfikację podpisu co przy wdrożeniu. Cel czyści cache i uruchamia je bez budowy ani pobierania. Odtworzenie zrzutu przy niezgodnej migracji pozostaje osobnym krokiem operatora.

### Podpisane obrazy i kanał aktualizacji

Decyzja: D-93. Na VPS nic się nie buduje: `make deploy` uruchamia tylko obrazy, które zbudował i podpisał CI.

| Element | Jak działa |
| --- | --- |
| kanał | gałąź `main`: każdy push do `main` z zielonymi testami daje obrazy `app` (etap `production`) i `proxy` z tagiem sha commita w `ghcr.io/good-yellow-bee/kokpit-jdg-public` (`KOKPIT_REGISTRY`) |
| podpis | job `images` podpisuje digest każdego obrazu przez `cosign sign` bez klucza: certyfikat Sigstore z tożsamością OIDC przebiegu `ci.yml@refs/heads/main`, wpis w publicznym logu Rekor; nie ma klucza prywatnego do przechowania ani rotacji |
| weryfikacja | `cosign verify` sprawdza tożsamość `KOKPIT_SIGNER`, wystawcę `https://token.actions.githubusercontent.com`, wyzwalacz `push` i sha commita w certyfikacie; obraz spoza `KOKPIT_REGISTRY` albo bez zgodnego podpisu zatrzymuje wdrożenie przed krokiem 2 |
| przypięcie | `APP_IMAGE` i `PROXY_IMAGE` to odwołania `repo@sha256:…`, więc uruchomiony obraz jest tym zweryfikowanym, nawet gdy tag w rejestrze się zmieni |
| `make update-check` | `git fetch` gałęzi `main`, lista nowych commitów względem checkoutu, pobranie i weryfikacja obrazów najnowszego commita; kod 0 oznacza, że `make deploy` może ruszyć |
| `make verify-images KOKPIT_COMMIT=<sha>` | sama weryfikacja lokalnych obrazów commita; wypisuje przypięte `APP_IMAGE` i `PROXY_IMAGE`; CI uruchamia ją po podpisie, więc flagi weryfikacji są sprawdzane na prawdziwym podpisie przy każdym push do `main` |

- Obrazy commita powstają dopiero po zielonym przebiegu CI na `main`; wcześniej `make deploy` i `make update-check` kończą się komunikatem, że obrazu nie da się pobrać.
- `docker compose up` lokalnie (`kokpit-dev`) dalej buduje z checkoutu; podpis dotyczy tylko `kokpit-prod`.

**Kroki tylko dla Ciebie (raz, przy stawianiu VPS):**

1. Zainstaluj `cosign` 2.x na VPS z wydań [sigstore/cosign](https://github.com/sigstore/cosign/releases), ze sprawdzeniem sumy z `cosign_checksums.txt`. Bez niego `deploy`, `rollback`, `verify-images` i `update-check` odmawiają (kod 2).
2. Dostęp do rejestru: pakiety `app` i `proxy` w GHCR powstają przy pierwszym push do `main` po D-93. Ustaw je jako publiczne (D-100): obrazy nie zawierają sekretów ani sejfu, a logowanie VPS do rejestru odpada. Przy pakietach prywatnych VPS loguje się raz: `docker login ghcr.io` tokenem klasycznym tylko z `read:packages` (token w menedżerze haseł, nie w repo).
3. Opcjonalnie cron na VPS z `make update-check` i powiadomieniem o nowej wersji; wdrożenie zostaje ręczne.

- Na laptopie `kokpit-dev` startuje przez `docker compose up -d` (oba pliki z `COMPOSE_FILE`) z `KOKPIT_DEPLOYMENT=dev` i migracją z kroku 5. Kroki 3, 4 i 7 odpadają.
- Po zmianie zasobów w dev przebuduj i odtwórz `proxy` (`docker compose up -d --build proxy`), żeby nginx serwował aktualne skompilowane pliki.
- Po pierwszym wdrożeniu i po odtworzeniu na nowym hoście strona `/setup` panelu („Pierwsze uruchomienie”, D-91) pokazuje brakujące ustawienia, poświadczenia i test powiadomień, z linkami do formularzy.

### Pierwsza instalacja

Decyzja: D-98. Na świeżym VPS `scripts/prod-install.sh` przygotowuje wszystko, czego `make deploy` nie tworzy, i kończy się listą dalszych komend. Uruchamiasz go raz jako root w checkoucie `main`; każdy krok pomija to, co już istnieje, więc po błędzie poprawiasz przyczynę i uruchamiasz go ponownie. W sesji Claude Code odmawia.

**Przed uruchomieniem (Ty):** VPS z tabeli „Hosting” z Dockerem i wtyczką compose, `git`, `make` i `cosign` („Podpisane obrazy”); `docker login ghcr.io`, jeśli pakiety są prywatne; w konsoli Tailscale klucz uwierzytelniania (wygasanie klucza węzła wyłączysz po pierwszym logowaniu) oraz MagicDNS i HTTPS Certificates w ustawieniach DNS; bucket w Scaleway Object Storage PL-WAW z kluczem dostępu tylko do niego (repozytorium `s3:https://s3.pl-waw.scw.cloud/<bucket>`); domena nadawcy z SPF i DKIM w Scaleway Transactional Email (`--mail-host smtp.tem.scaleway.com`); w healthchecks.io checki z tabeli „Monitoring”.

`sudo scripts/prod-install.sh --node <nazwa> [--mail-host <host> --mail-from <adres> --mail-to <adres>]`

| Krok | Co robi | Gdzie zostaje |
| --- | --- | --- |
| 1 | odmawia na hoście innym niż x86_64; sprawdza `docker compose`, `git`, `make`, `cosign`, `/dev/net/tun` i pamięć | – |
| 2 | pyta bez echa o brakujące pliki hosta: klucz Tailscale, repozytorium restic `s3:https://…`, klucze S3, adres pingu `backup.run`; generuje hasło restic; zakłada plik wdrożenia | `/etc/kokpit/secrets/` (0400), `/etc/kokpit/prod.env` |
| 3 | pobiera obrazy `app` i `proxy` commita i sprawdza podpis jak `make verify-images` | lokalne obrazy |
| 4 | przy pustej bazie generuje hasła ról `postgres`, `kokpit_owner`, `kokpit_app`, `kokpit_backup`, `chat_ro`; przy istniejącej bazie brak pliku zatrzymuje skrypt; zapisuje `pgpass` dla `kokpit_backup` | wolumeny `owner_credentials`, `app_credentials`; `secrets/pgpass` |
| 5 | konfiguracja ntfy: `deny-all`, użytkownik `kokpit` publikuje w temacie `kokpit` tokenem, użytkownik `telefon` tylko czyta | `/etc/kokpit/ntfy/server.yml`, `ntfy/phone-password` |
| 6 | sejf PROD: klucze, `APP_SECRET`, `SECRETBOX_KEY_current`, `DB_KOKPIT_APP_PASSWORD`, `DB_CHAT_RO_PASSWORD`, `PUSH_PROD_TOKEN` (`scripts/init-prod-vault.php`) | wolumen `vault`, katalog `prod/` |
| 7 | startuje `vpn` z `TS_HOSTNAME` = `--node`, czeka na tailnet, bierze nazwę z `CertDomains`, zapisuje `PANEL_ORIGIN`, wystawia certyfikat (`scripts/prod-cert.sh`) i zakłada cron odnowienia | wolumen `certificates`, `/etc/cron.d/kokpit-panel-cert` |
| 8 | `restic init`, jeśli repozytorium nie istnieje | bucket S3 |

- `--node` to nazwa węzła w Tailscale i pierwsza część adresu panelu, czyli RP ID passkeyów. Trafia do publicznych logów certyfikatów, więc nie zdradza firmy ani celu ([security.md](security.md)). Gdy Tailscale nada inną nazwę (np. z sufiksem `-1`), skrypt ostrzega i zapisuje tę, którą tailnet wydał.
- Plik wdrożenia `/etc/kokpit/prod.env` nie ma sekretów. Trzyma `KOKPIT_DEPLOYMENT`, `PANEL_ORIGIN`, `TS_HOSTNAME`, `HOST_SECRETS_DIR`, `NTFY_CONFIG_FILE`, `MAIL_HOST`, `MAIL_FROM`, `MAIL_TO`, `MAIL_IN_HOST`, cele i `CONNECTORS_ENABLED`. Instalacja zapisuje cele `test` i `CONNECTORS_ENABLED=0`; zmiana celu to edycja tego pliku i osobne wdrożenie według „Go-live”.
- Po instalacji: `make deploy` (pierwszy backup i czekanie na harmonogram), `export KOKPIT_DEPLOYMENT=prod`, link passkeya `docker compose -f compose.yaml exec app bin/console app:passkey:enrol-link`, kopie klucza sejfu (`docker compose -f compose.yaml exec -T app base64 -w0 /run/kokpit/vault/prod/prod.decrypt.private.php`) i hasła restic do menedżera haseł i na papier, aplikacja ntfy na telefonie (serwer `PANEL_ORIGIN` + `/ntfy`, użytkownik `telefon`), sekrety z „Rotacje poświadczeń” i [security.md](security.md), strona `/setup`.
- `docker compose` bez `-f compose.yaml` i bez `KOKPIT_DEPLOYMENT=prod` czyta `.env` repozytorium i trafia w projekt `kokpit-dev`, dlatego komendy PROD zaczynasz od `export KOKPIT_DEPLOYMENT=prod`.

**Próba.** `scripts/prod-install.sh --rehearsal` przechodzi te same kroki na danych fikcyjnych w projekcie `kokpit-rehearsal`: obrazy lokalne `APP_IMAGE` i `PROXY_IMAGE`, atrapa interfejsu `tailscale0` i certyfikat self-signed zamiast Tailscale, bez rejestru, cosign i S3. Potem migruje bazę, startuje usługi, czeka na heartbeat w `/health` przez proxy, sprawdza link passkeya z `PANEL_ORIGIN` oraz publikację ntfy tokenem i bez tokenu, a na końcu usuwa projekt (`--keep` go zostawia). Job CI `images` uruchamia próbę na obrazach, które właśnie zbudował. Próba nie sprawdza prawdziwego Tailscale, rejestru, S3, healthchecks.io, crona hosta ani dostępu telefonu do ntfy.

## Messenger

Transport Doctrine; wiadomości niosą tylko identyfikatory encji ([Symfony Messenger](https://symfony.com/doc/current/messenger.html)).

| Transport | Wiadomości | Ponowienia | Konsumenci | Po błędzie |
| --- | --- | --- | --- | --- |
| `reads` | sync, polling, statusy wysyłek, rekoncyliacja, kursy, weryfikacje, zapis zwrotny do FreshBooks | `max_retries: 3`, opóźnienie 60 s, mnożnik 5 | 1 lub więcej | `failed` |
| `official_writes` | wysyłka zatwierdzenia | `max_retries: 0` | dokładnie 1 proces | `failed` i push; ponowienie tylko z panelu przez nowe zatwierdzenie |
| `notifications` | push i mail powiadomień | `max_retries: 5`, opóźnienie 60 s, mnożnik 3 | 1 | `failed` |
| `failed` | wiadomości po błędzie | – | przegląd w panelu | listener `WorkerMessageFailedEvent` wysyła push kategorii `connector_failure` tylko przy `!$event->willRetry()`, czyli po ostatniej próbie |

- Komenda `messenger:failed:retry` nie dotyczy wiadomości z `official_writes`. Handler wysyłki i tak nic nie robi, jeśli `approval` nie jest w stanie `approved` ([approval-gate.md](approval-gate.md)).
- „Doręcz klientowi” (F3) idzie synchronicznie w żądaniu panelu przez Symfony Mailer, poza Messenger i `notifications` (D-31). Jedno kliknięcie to jedna próba, bez automatycznego ponowienia; wpis `invoice.delivered_to_client` ([modules/freshbooks.md](modules/freshbooks.md)).
- Czat (F6) odpowiada w synchronicznym żądaniu panelu z ramką Turbo, bez transportu Messenger (D-29, [modules/czat.md](modules/czat.md)).
- Workery startują z `--time-limit=3600 --memory-limit=256M`. Compose podnosi je po wyjściu.
- Gdy push nie zostanie doręczony przy wyłączonych konektorach, wiersz `failed` nie dostaje `X-Kokpit-Notified`. Po włączeniu konektorów każde `heartbeat` ponownie kolejkuje niepowiadomione identyfikatory na `notifications`. Blokada wiersza i znacznik po udanym push zapobiegają ponownej wysyłce przy duplikatach.
- Każdy handler jest idempotentny: ponowna wiadomość sprawdza stan w bazie i kończy się bez skutku.

## Katalog zadań

Jeden harmonogram `default` z `stateful(cache)`, `processOnlyLastMissedRun(true)` i `lock()` ([Symfony Scheduler](https://symfony.com/doc/current/scheduler.html)).

- `stateful` pamięta ostatnie uruchomienie, więc po przestoju zadanie nadrabia. `processOnlyLastMissedRun` uruchamia je raz, bo każde zadanie samo wybiera zaległą pracę z bazy.
- `lock()` obejmuje cały harmonogram: drugi proces `scheduler` czeka jako zapas. Zadania przekazują pracę do transportów, nie wykonują jej w procesie harmonogramu.
- Cron liczy się w Europe/Warsaw, ustawionym jawnie przy każdym zadaniu.
- Zadanie oznaczone „dead-man” pinguje zewnętrzny monitoring po udanym przebiegu.
- `backup.run` to jedyne zadanie poza `default`: cron w usłudze `backup` uruchamia `pg_dump` i restic.

**Magazyny wspólne dla kontenerów**

| Element | Magazyn | Dlaczego |
| --- | --- | --- |
| stan `stateful` harmonogramu | pula cache na adapterze `cache.adapter.doctrine_dbal` w PostgreSQL | domyślna pula plikowa w kontenerze znika w kroku 6 wdrożenia, a z nią pamięć pominiętych przebiegów |
| `lock()` harmonogramu, Lock `SecretStore` na `(environment, kind)` | Symfony Lock z `DoctrineDbalPostgreSqlStore` (blokady doradcze PostgreSQL) | domyślny `flock` działa w jednym kontenerze; zapasowy `scheduler` i workery potrzebują wspólnej blokady |
| znacznik `heartbeat` | klucz `ops.last_heartbeat` w puli harmonogramu, zapisywany przez handler `heartbeat` | źródło „ostatniego przebiegu harmonogramu” w `/health` |

| Zadanie | Harmonogram (Europe/Warsaw) | Transport | Klucz idempotencji | Dead-man | Faza | Dom |
| --- | --- | --- | --- | --- | --- | --- |
| `heartbeat` | co 5 min | `reads` | brak stanu | tak | F0a | ten plik |
| `backup.run` | codziennie 02:30; w niedzielę po backupie `restic check` | cron w usłudze `backup`, poza harmonogramem Symfony | snapshot dnia | tak, ping z kontenera i plik `last_success` po udanym `restic backup` | F0a | ten plik |
| `files.verify_hashes` | niedziela 03:30 | `reads` | `stored_file.last_verified_at` | – | F0a | [data-model.md](data-model.md) |
| `audit.verify_chain` | niedziela 04:00 | `reads` | tylko odczyt | – | F0a | [security.md](security.md) |
| `retention.purge` | 1. dnia miesiąca 04:30 | `reads` | `stored_file.retention_until` bez `legal_hold` → `deleted_at`; wiek `connector_call` i `notification` (90 dni); od F6 `chat_session` (12 mies. od zamknięcia) | – | F0a | [security.md](security.md) |
| `obligations.recompute` | codziennie 06:00 oraz po każdej zmianie `setting` albo encji źródłowej reguły | `reads` | `obligation.dedupe_key` | – | F0b | [compliance-calendar.md](compliance-calendar.md) |
| `notifications.dispatch` | co 5 min | `notifications` | `notification.dedupe_key`, `sent_at` | tak | F0b | [compliance-calendar.md](compliance-calendar.md) |
| `credentials.check_expiry` | codziennie 07:00 | `reads` | stan `credential_state` | – | F0b | [security.md](security.md) |
| `notifications.weekly_digest` | poniedziałek 07:30 | `notifications` | `notification.dedupe_key` z tygodniem ISO; wysyła `notifications.dispatch` | – | po F0b (D-82) | [compliance-calendar.md](compliance-calendar.md) |
| `approval.expire` | co 5 min oraz 00:00 | `reads` | stan `approval` | – | F0b | [approval-gate.md](approval-gate.md) |
| `approval.poll_status` | co 2 min dla stanu `submitted` | `reads` | `approval.external_refs` | – | F0b | [approval-gate.md](approval-gate.md) |
| `approval.reconcile` | co 10 min dla `unknown` i starych `dispatching`; wysyła `connector_failure` dla `unknown` starszego niż 1 h | `reads` | tylko odczyt statusu; push z deduplikacją po `approval_id` | – | F0b | [approval-gate.md](approval-gate.md) |
| `approval.dispatch_waiting` | co 5 min | `official_writes` | ponownie kolejkuje każde `approved` ze spełnionymi warunkami wysyłki; duplikat nie szkodzi, bo handler blokuje wiersz i wymaga `approved`; nigdy po pierwszym wywołaniu API | – | F0b | [approval-gate.md](approval-gate.md) |
| `ksef.sync` | co godzinę dla każdej roli, Subject1 i Subject2 przesunięte o 30 min | `reads` | `sync_cursor` | tak | F1 | [modules/ksef.md](modules/ksef.md) |
| `ksef.backfill` | każdej nocy 01:00 kolejne okna, do ustawienia `backfill_done_at` | `reads` | `sync_cursor.backfill_from`, `backfill_done_at` | – | F1 | [modules/ksef.md](modules/ksef.md) |
| `mf_keys.refresh` | poniedziałek 05:00 | `reads` | odcisk sha256 certyfikatu JPK i `validTo`; KSeF bez zadania (D-72) | – | F5 | ten plik |
| `nbp.fetch_rates` | dni robocze 12:20; przy 404 ponowienie co 15 min do publikacji; tabela A wychodzi między 11:45 a 12:15 ([NBP](https://nbp.pl/statystyka-i-sprawozdawczosc/kursy/informacja-o-terminach-publikacji-kursow-walut/)) | `reads` | `UNIQUE` w `fx_rate`; tylko rozgrzewa cache, kurs przy dokumencie zawsze z `FxRateProvider::getRate` | – | F1 | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |
| `counterparty.refresh_due` | codziennie 08:00 | `reads` | nowy wiersz `verification` | – | F1 | [modules/rejestry-kursy.md](modules/rejestry-kursy.md) |
| `freshbooks.poll_invoices` | co 15 min | `reads` | `sync_cursor`, `source_hash` | – | F2.2/F2.3, F3 | [modules/freshbooks.md](modules/freshbooks.md) |
| `freshbooks.poll_payments` | co godzinę | `reads` | `sync_cursor` | – | F2.2/F2.3 | [modules/freshbooks.md](modules/freshbooks.md) |
| `ledger.entry_check` | 15. i 20. dnia miesiąca 08:00 | `reads` | obowiązek `ledger_entry` | – | F2, po F0b | [modules/ewidencja.md](modules/ewidencja.md) |
| `latarnia.status` | co 5 min | `reads` | ostatni status | – | F3 | [modules/ksef.md](modules/ksef.md) |
| `jpk.replace_di` | codziennie 09:00 | `reads` | idempotentne przypisanie numeru KSeF | – | F5 | [modules/jpk.md](modules/jpk.md) |
| `legal_watch.check` | poniedziałek 06:30 | `reads` | `legal_watch.snapshot_sha256` i klucz push ze stanem | – | po F6 (D-75) | [modules/obserwacja-prawa.md](modules/obserwacja-prawa.md) |
| `mail_in.fetch` | co 30 min | `reads` | kursor UID w `mail_in_message`, `UNIQUE (environment, uid_validity, uid)` | – | po F1 (D-92) | niżej, „Skrzynka dokumentów kosztowych” |

- Przypomnienia o ręcznym przeglądzie kanałów doręczeń tworzy `obligations.recompute` jako obowiązek `manual_channel_check` ([compliance-calendar.md](compliance-calendar.md), [modules/e-doreczenia.md](modules/e-doreczenia.md)).
- Polling skrzynki e-Doręczeń jest odłożony razem z F4 (D-29). Skrzynkę ADE firmy sprawdzasz w aplikacji e-Doręczeń.

### Skrzynka dokumentów kosztowych

Konektor `mail_in` (D-92) czyta jedną dedykowaną skrzynkę tylko do odczytu. Bez `MAIL_IN_HOST` zadanie `mail_in.fetch` kończy się bez połączenia. Uruchomienie robisz Ty:

1. Załóż osobną skrzynkę (albo alias z osobnym kontem) tylko na faktury i paragony; nie podłączaj głównej skrzynki. Włącz w niej IMAP i utwórz hasło aplikacji, jeśli dostawca je ma.
2. Ustaw `MAIL_IN_HOST` (host IMAP dostawcy, port 993) w zmiennych wdrożenia `kokpit-prod`; compose przekazuje go do usług aplikacji.
3. Zapisz poświadczenie w sejfie wdrożenia: `bin/console secrets:set MAIL_IN_PROD_DSN` z wartością `imaps://<użytkownik>:<hasło>@<host>/INBOX` (znaki `@`, `:` i `/` w użytkowniku i haśle zakodowane jak w URL, np. `%40`). Host musi być ten sam co w `MAIL_IN_HOST`.
4. Restart workerów, potem `bin/console app:mail-in:fetch`: wynik `"status":"ok"` i wiersz `connector_call` konektora `mail_in` bez `error_code`.
5. Dokumenty są na stronie „Dokumenty z poczty” (`/mail-in`, link na stronie kosztów).

- Powiadomienia systemowe (D-109): w głównej skrzynce ustaw regułę przekazywania (przekierowanie z zachowaniem nagłówka `From`, nie „przekaż jako załącznik”) maili od `e-urzadskarbowy@podatki.gov.pl`, `jpk.powiadomienia@mf.gov.pl`, `powiadomienia.jpk@mf.gov.pl`, domeny `zus.pl` i domeny nadawcy powiadomień e-Doręczeń do skrzynki `mail_in`. Kopia zostaje w głównej skrzynce. Każdy taki mail daje obowiązek `manual_channel_check` na dziś; na `/mail-in` ma dopisek „powiadomienie”. Inny adres nadawcy prawdziwego powiadomienia zmienia `mail_in.notices` w `config/services_connectors.yaml` (Claude Code, nowe wdrożenie) i wiersz w [facts.md](facts.md).
- Rotacja: nowe hasło u dostawcy → `secrets:set MAIL_IN_PROD_DSN` → restart workerów → `app:mail-in:fetch`.
- Błąd sesji daje push `connector_failure` (`mail_in`, np. `login_failed`) raz na dobę; kod jest też na stronie `/mail-in` i w `/connectors`.

## Monitoring

Alert nie może zależeć tylko od serwera, który monitoruje. Fikcja doręczenia i terminy biegną także przy awarii kokpitu.

**Dead-man.** Zewnętrzny serwis healthchecks.io (Q-42, D-100) czeka na pingi i sam alarmuje telefon.

Pingi wysyła tylko `kokpit-prod`: adresy pingu są w jego sejfie i w pliku hosta usługi `backup`. `kokpit-dev` i `kokpit-restore` nie pingują, więc nie maskują martwego PROD.

| Check | Okres | Zapas |
| --- | --- | --- |
| `heartbeat` | 5 min | 15 min |
| `backup.run` | 1 dzień | 6 h |
| `notifications.dispatch` | 5 min | 30 min |
| `ksef.sync` | 1 h | 3 h; ping po udanym eksporcie na celu `prod` (D-98), więc check zakładasz przy go-live odczytu KSeF |

**`/health`** zwraca 200 albo 503 i JSON bez danych osobowych. Dostęp tylko w VPN.

| Sprawdzenie | Stan błędu |
| --- | --- |
| połączenie z bazą | brak połączenia |
| wiek najstarszej wiadomości per transport | `reads` ponad 30 min, `official_writes` ponad 5 min, `notifications` ponad 30 min |
| ostatni przebieg harmonogramu: klucz `ops.last_heartbeat` w puli harmonogramu („Katalog zadań”) | ponad 10 min |
| liczba wiadomości w `failed` | większa od zera |
| wiek ostatniego udanego backupu: czas modyfikacji pliku `last_success` („Backup i odtwarzanie”) | ponad 26 h |
| wolne miejsce na dysku | poniżej 20% |
| ważność certyfikatu TLS panelu | mniej niż 14 dni ([security.md](security.md)) |
| poświadczenia `expiring` i `expired` | każde `expired` |
| zatwierdzenia w `unknown` | każde starsze niż 1 h |
| `CONNECTORS_ENABLED` i cele konektorów | informacja, nie błąd |

**Zdrowie konektorów** (D-85): strona `/connectors` panelu pokazuje wywołania, błędy, 429, czasy, blokady `rate_counter` i kursory `sync_cursor` za dziś, 7 albo 30 dni, bez ścieżek żądań.

**Paczka diagnostyczna** (D-80) do własnego debugowania i wsparcia bez zdalnego dostępu: `docker compose logs --since 168h | docker compose exec -T app bin/console kokpit:diagnostics --logs=-` zapisuje `var/diagnostics/kokpit-diagnostics-<data>-<czas>.json` z prawami 0600. W środku: wersje PHP, rozszerzeń i pakietów, commit, stan migracji, wynik `/health`, kolejki Messenger, poświadczenia (rodzaj, stan i daty, bez sekretów), aktywne blokady `rate_counter`, podsumowanie `connector_call` z `--days` dni (domyślnie 7) i ostatnie 50 błędów oraz ostatnie 2000 wierszy logów. Teksty przechodzą przez `Redactor`, numery o poprawnej sumie NIP stają się `[NIP]`, a gdy skaner fixtures coś znajdzie, paczka nie powstaje. Plik wyciągasz `docker compose cp` i usuwasz po użyciu.

Powiadomienia mailowe z aplikacji e-Doręczeń zostają włączone na stałe jako niezależny kanał. Do powrotu F4 to jedyny sygnał o nowym piśmie (D-29).

## Backup i odtwarzanie

Backup obejmuje bazę i pliki `FileStore` w jednym snapshocie. Cel jest poza serwerem, w UE, u innego dostawcy niż hosting: Scaleway Object Storage PL-WAW (Q-41, D-100).

| Element | Reguła |
| --- | --- |
| Narzędzie | `pg_dump -Fc`, potem restic snapshot zrzutu, `var/data` i zaszyfrowanego sejfu `prod/` bez klucza deszyfrującego (D-98) ([restic](https://restic.readthedocs.io/)) |
| Spójność | najpierw zrzut bazy, potem pliki; pliki są zapisywane raz, więc plik nowszy niż zrzut nie psuje odtworzenia |
| Szyfrowanie | restic szyfruje repozytorium kluczem z pliku hosta ([security.md](security.md)) |
| Klucze | klucz repozytorium i klucz deszyfrujący sejfu PROD w menedżerze haseł i na papierze, nigdy w tym samym backupie |
| Retencja | 30 dziennych, 12 miesięcznych, 7 rocznych snapshotów |
| RODO | usunięty plik znika z backupu po wygaśnięciu snapshotów; zasady w [security.md](security.md) |
| Sprawdzenie | `restic check` co tydzień po `backup.run` |
| Kotwica audytu | przed zrzutem `backup.run` czyta głowę łańcucha `audit_log` i zapisuje ją w tagu snapshotu `audit-head:<sequence>:<hash>` (pusty łańcuch: `0` i 64 zera); tagi pokazuje `restic snapshots`, a `app:audit:verify --anchor=<tag>` sprawdza, że wpis o tym numerze ma w bazie ten sam hash (D-79) |
| Znacznik sukcesu | po udanym `restic backup` kontener `backup` zapisuje plik `var/run/backup/last_success` na wolumenie montowanym w `app` tylko do odczytu; `/health` czyta jego czas modyfikacji |

**Test odtworzenia** w jednorazowym projekcie `kokpit-restore` na laptopie (D-28), jednym poleceniem (D-89)

1. Na VPS: `docker compose -f compose.yaml exec -T app bin/console app:ops:restore-check --counts-only > restore-counts.json`. Plik ma liczby `approval` (`requested_at`), `filing` (`created_at`) i `revenue_entry` (`recorded_at`) z poprzedniego miesiąca w Europe/Warsaw; skopiuj go na laptop.
2. Na laptopie, w checkoucie commita z ostatniego `ops.deployed`: połóż kopie `restic-password`, `restic-repository`, `s3-access-key` i `s3-secret-key` z menedżera haseł w katalogu poza checkoutem i uruchom `scripts/restore-test.sh --secrets <katalog> --expected restore-counts.json`. Skrypt odmawia w sesji Claude Code (`CLAUDECODE`) i przy katalogu wewnątrz checkoutu.
3. Skrypt sam robi kroki runbooka:
   - stawia `kokpit-restore` (`KOKPIT_DEPLOYMENT=restore`, `APP_ENV=prod`, `CONNECTORS_ENABLED=0`, wszystkie `*_TARGET` testowe) tylko z usługą `db`; bez `backup`, `vpn`, `proxy`, workerów i `scheduler`, więc nic nie wychodzi do sieci, a pingi dead-man nie wychodzą („Monitoring”);
   - odtwarza bazę i `var/data` z jednego snapshotu (domyślnie `latest`, `--snapshot <id>` dla innego): `restic --no-lock` tylko czyta repozytorium, `pg_restore --single-transaction` ładuje zrzut do pustej bazy, pliki trafiają do wolumenu `runtime`;
   - uruchamia `app:ops:restore-check`: `app:audit:verify` z kotwicami `audit-head:` snapshotu odtworzonego i najstarszego, `app:files:verify` i porównanie liczb z plikiem z kroku 1;
   - zapisuje raport JSON w `var/evidence/restore/` (poza repo) i usuwa projekt razem z wolumenami (`--keep` zostawia go do obejrzenia).
4. Oczekiwany wynik: `Test odtworzenia: pass.` i kod wyjścia 0, czyli zero niezgodnych hashy, łańcuch poprawny, obie kotwice zgodne, liczby zgodne z PROD. Inny wynik czytasz w raporcie i w podsumowaniu na stderr.
5. Na VPS: `docker compose -f compose.yaml exec -T app bin/console app:ops:record-restore - < raport.json` zapisuje wpis `ops.restored`. Raport dołączasz do dowodu `cc-14` ([testing.md](testing.md)).
6. Usuń katalog z kopiami plików backupu i raport z laptopa po dołączeniu do dowodu. W tym katalogu nie działa sesja Claude Code ([security.md](security.md)).

Test nie potrzebuje klucza sejfu PROD: aplikacja w `kokpit-restore` łączy się z bazą plikiem hasła tego projektu, a sekret frameworka jest jednorazowy. Sprawdzenia czytają tylko bazę i pliki. Klucz sejfu PROD z menedżera haseł potrzebny jest dopiero przy prawdziwej awarii (niżej).

Test odtworzenia robisz na koniec F0a, a potem raz na kwartał (`[manual]`, [testing.md](testing.md)).

**Odtworzenie po awarii** w `kokpit-prod` na nowym VPS

Krok 4 używa przejścia `approved` → `unknown` z wyzwalaczem „odtworzenie z backupu” i aktorem Ty. Przejście definiuje [approval-gate.md](approval-gate.md), nazwę stanu [naming.md](naming.md).

1. Postaw `kokpit-prod` z `CONNECTORS_ENABLED=0`. Zaszyfrowany sejf `prod/` z tego samego snapshotu połóż w wolumenie `vault`, a obok plik klucza sejfu PROD z menedżera haseł, z uprawnieniami 0400 dla `www-data` ([security.md](security.md)); dopiero potem `scripts/prod-install.sh`, który wtedy nie tworzy nowych kluczy. Panel dostaje tę samą nazwę DNS, bo passkey jest związany z nią jako RP ID; inaczej `app:passkey:reset`.
2. Odtwórz bazę i `var/data` z jednego snapshotu. Uruchom `app:files:verify` i `app:audit:verify --anchor=<tag audit-head tego snapshotu>`.
3. Ustaw cele PROD z ostatniego `ops.deployed` i `CONNECTORS_ENABLED=1`. Startuj usługi bez `worker_official_writes` i `scheduler`.
4. Każde `approved` z odtworzonej bazy przestaw na `unknown` (odtworzenie z backupu) poleceniem `bin/console app:approval mark-restored`, bo mogło wyjść po zrzucie. Nie anulujesz go: anulowanie zamyka fakturę jako `cancelled_before_send`.
5. Uruchom ręcznie rekoncyliację tylko odczytem: `ksef.sync` Subject1 od czasu zrzutu, odpytanie statusu złożeń, `SubmissionReconciler` dla `dispatching`, `submitted` i `unknown`. Wyniki dopasuj po P_2 i `payload_sha256`.
6. Dokument znaleziony w KSeF albo w bramce JPK, a lokalnie w `pending`, `ready` albo bez wiersza, rozstrzygasz w panelu `[manual]`. Resztę `pending` wygasza `approval.expire`; faktura wraca wtedy do `ready`.
7. Startuj `worker_official_writes` i `scheduler`, na końcu połącz ponownie FreshBooks.

## Rotacje poświadczeń

Inwentarz i stany są w [security.md](security.md). Zakończenie rotacji zależy od rodzaju sekretu:

| Sekret | Koniec rotacji |
| --- | --- |
| rodzaj statyczny z `credential_state.kind`: `ksef_token_read`, `ksef_token_write`, `ksef_auth_cert`, `ksef_offline_cert`, `auth_data` | `app:credential:register <kind> --valid-until=…`, `app:credential:smoke <kind>` i wpis `credential.rotated` |
| `auth_data` | test dymny to lokalna walidacja XSD SIG-2008, bez wysyłki |
| sekret bez `credential_state.kind` (klucz Claude API, ntfy, SMTP, skrzynka `mail_in`, restic, sejf PROD) | wywołanie testowe i wpis `credential.rotated` |
| rodzaj rotujący (`ksef_jwt_*`, `freshbooks_oauth`) | zapis przez `SecretStore::put`, bez Twojego udziału |

| Rodzaj | Kiedy | Kroki |
| --- | --- | --- |
| `ksef_token_read`, `ksef_token_write` | przed `valid_until`, po incydencie, przy zmianie regulacji tokenów ([README.md](README.md)) | Przed `valid_until` (31.12.2026 do nowelizacji §13) decyduje przegląd §13 przed 1.12.2026 ([README.md](README.md)). Nowelizacja opublikowana: nowy `valid_until` i nowy token według nowych zasad od 1.01.2027. Bez nowelizacji: uwierzytelnienie przez `ksef_auth_cert` zarejestrowany na PROD przed 31.12.2026 ([phases/f1-ksef-odczyt.md](phases/f1-ksef-odczyt.md)). Nowy token: Aplikacja Podatnika po zalogowaniu PZ albo QES ([modules/ksef.md](modules/ksef.md)) → `secrets:set KSEF_<CEL>_TOKEN_…` → restart workerów → wywołanie testowe → unieważnienie starego tokenu |
| `ksef_auth_cert`, `ksef_offline_cert` | 30 dni przed końcem ważności, najwyżej po 2 latach | wniosek z podpisem XAdES; tokenem nie da się go złożyć ([MF](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/)) → plik i hasło do sejfu → uwierzytelnienie testowe certyfikatem |
| `ksef_jwt_read`, `ksef_jwt_write` | automatycznie | odświeżanie pod lockiem na parę `(environment, kind)`; przy błędzie pełne uwierzytelnienie tokenem albo certyfikatem |
| `auth_data` | przed dry runem na danych PROD (data urodzenia do `Podmiot1`), co roku 2 stycznia i przed go-live F5 | nowa kwota przychodu N−2 → `secrets:set` → walidacja XSD SIG-2008 lokalnie |
| `freshbooks_oauth` | automatycznie; po utracie grantu | nowy refresh token zapisany w tej samej transakcji; po `expired` ponowne połączenie w panelu ze step-up |
| klucz Claude API | po incydencie albo raz w roku | nowy klucz w workspace kokpitu → sejf → wywołanie testowe → usunięcie starego |
| token ntfy, SMTP | po incydencie | nowa wartość → sejf → testowe powiadomienie (`app:push:test`) |
| klucz restic, klucz sejfu PROD | po incydencie | `restic key add` i usunięcie starego; `secrets:generate-keys --rotate`; aktualizacja menedżera haseł i papieru |
| klucze publiczne MF (KSeF, JPK) | `mf_keys.refresh` co tydzień | KSeF: bez kopii i bez zadania; `KsefApi` pobiera `GET /security/public-key-certificates` ([open-api](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/open-api.json)) raz na proces i bierze certyfikat ważny w tej chwili (D-72). JPK: certyfikat w konfiguracji per cel jako plik na wolumenie wdrożenia, nie w obrazie ([modules/jpk.md](modules/jpk.md)). Nowy plik tylko ze stałego adresu MF z konfiguracji, inaczej wymiana ręczna. Panel pokazuje `validTo` obu i przypomina 30 dni wcześniej; bez przypinania certyfikatów TLS |

Rotacje poświadczeń e-Doręczeń wracają z F4 (D-29), według [modules/e-doreczenia.md](modules/e-doreczenia.md).

**Incydent.** Przy podejrzeniu wycieku unieważnij najpierw `ksef_token_write`, potem rotuj pozostałe sekrety. Następnie oceń zgłoszenie do UODO ([security.md](security.md)).

## Go-live

Właścicielem każdej checklisty jesteś Ty. Do go-live obowiązek składasz według sekcji „Teraz – zgodność” w [README.md](README.md); jeden obowiązek ma jedno źródło wysyłki.

**Sekwencja dla każdego modułu**

1. Próba na DEMO (KSeF) albo na środowisku testowym, w `kokpit-dev` na laptopie albo w CI, nigdy w `kokpit-prod`. Tylko dane fikcyjne; MF zabrania rzeczywistych danych na TE i DEMO ([modules/ksef.md](modules/ksef.md)).
2. Poświadczenia PROD w sejfie PROD; data ważności zarejestrowana.
3. Zmiana celu konektora osobnym wdrożeniem; baner w panelu pokazuje nowy stan.
4. Test dymny tylko do odczytu.
5. Pierwszy zapis przez bramkę ze step-up i sprawdzeniem UPO.
6. Okres równoległy: kokpit liczy i porównuje, wysyła tylko jedno narzędzie.
7. Wyłączenie starego narzędzia dla tego obowiązku.
8. Monitoring po go-live: przez dwa cykle przegląd `failed`, błędów `connector_call` i dead-man po każdym przebiegu.

| Moduł | Faza | Kiedy | Warunek wejścia | Pierwsza operacja PROD | Wycofanie |
| --- | --- | --- | --- | --- | --- |
| ewidencja 2026 | F2.1, F2.4 | dry run do 15.12.2026, cel własny (D-30) | eksport z narzędzia z Q-11 z policzonymi K_8 i K_9 (D-23); `auth_data` z datą urodzenia | import 2026 do `kokpit-prod`; dry run JPK_EWP(4) i JPK_ST(1) zgodny z XSD | wpisy zostają w narzędziu z Q-11; XML z `app:jpk:dry-run JPK_EWP <rok> --until=<dzień>` do wczytania w Klient JPK WEB albo Formularzu uproszczonym oraz CSV `revenue_entry` (`psql \copy`) |
| ewidencja bieżąca | F2.2/F2.3 | po uzgodnieniu 2026 z KSeF Subject1 i FreshBooks | `setting` `ledger.auto_entries_enabled` ([modules/ewidencja.md](modules/ewidencja.md)) | wpisy bieżącego miesiąca do 20. | narzędzie z Q-11 |
| KSeF odczyt | F1 | po kryteriach F1 | token do odczytu PROD | backfill Subject1 i Subject2 od 1.02.2026; liczba i suma zgodne z Aplikacją Podatnika | `KSEF_TARGET=test` |
| KSeF sprzedaż | F3 | po przeglądzie doradcy (D-04) | wynik przeglądu w [decisions.md](decisions.md); we FreshBooks wyłączone Send, wysyłki cykliczne i auto-bill; próba na DEMO tylko na danych fikcyjnych, jeśli DEMO to umożliwia; inaczej pominięta ([modules/ksef.md](modules/ksef.md)) | pierwsza faktura przez bramkę; KOD I otwiera adres QR; pierwszy zapis zwrotny do FreshBooks (D-35) | Nowy wiersz `setting` `freshbooks.sales_enabled` = wyłączony, `valid_from` = dziś ([modules/freshbooks.md](modules/freshbooks.md)): znacznik gotowości przestaje działać i nie powstają nowe zatwierdzenia. Żywe `pending` i `approved` anulujesz w panelu. `KSEF_TARGET` zostaje `prod` dla odczytu F1; wystawiasz w Aplikacji Podatnika KSeF. `revoked` tylko przy prawdziwym unieważnieniu („Incydent”) |
| JPK_EWP(4), JPK_ST(1) | F5 | do 31.03.2027 | dry run zgodny z XSD; dane autoryzujące N−2; klucz MF PROD | JPK_EWP za 2026 przez bramkę, UPO zapisane | XML z kokpitu przez Klient JPK WEB albo Formularz uproszczony do 30.04.2027 ([narzędzia MF](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_pd/bezplatne-narzedzia)) |
| JPK_V7M(3) | F5 | po JPK_EWP | bilans otwarcia (`setting` `opening.*`) potwierdzony; dwa miesiące zgodne z Twoim obecnym narzędziem | pierwszy miesiąc przez bramkę | e-mikrofirma albo Klient JPK WEB |
| VAT-UE | F5 | razem z JPK_V7M | rejestracja VAT-UE sprawdzona; kanał D-27 potwierdzony | pierwszy VAT-UE przez bramkę; suma równa K_12 | e-Deklaracje ręcznie |
| czat | F6 | po kryteriach F6 | klucz z limitem wydatków; budżety w kodzie; `[manual]` DPA z SCC z Anthropic zawarte (D-22, [modules/czat.md](modules/czat.md)); rola `chat_ro`: plik hosta `chat-ro-password` w `app_credentials`, rola jak w `docker/db/init-db.sh` (jako `postgres`), sekret `DB_CHAT_RO_PASSWORD` w sejfie PROD (przy instalacji według D-98 plik, rola i sekret już są), `GRANT SELECT` na widokach `chat_*` rolą `kokpit_owner` (D-59) | pierwsza rozmowa | konektor `claude` wyłączony w konfiguracji |

Kolejność wierszy idzie za D-30. e-Doręczenia API (F4) i basal (F7) są odłożone (D-29); e-Doręczenia obsługujesz według „Teraz – zgodność” w [README.md](README.md).

## Break-glass i zastępca

Decyzje: D-03, D-99, D-107. Zastępcy nie ma: bez administratora skrzynki ADE, bez UPL-1 i bez drugiego loginu w kokpicie. Pisma odbierasz i składasz tylko Ty.

| Sytuacja | Procedura |
| --- | --- |
| utrata wszystkich passkeyów | SSH → `app:passkey:reset` → nowy link → nowy passkey; od F0b co najmniej dwa ([security.md](security.md)) |
| utrata dostępu SSH | konsola dostawcy hostingu; dane dostępowe w karcie awaryjnej |
| serwer niedostępny przy terminie | narzędzia MF z sekcji „Teraz – zgodność” w [README.md](README.md); po powrocie rekoncyliacja otwartych wysyłek |
| pismo w skrzynce ADE pod Twoją nieobecność | nikt go nie odbiera; po 14 dniach od zawiadomienia pismo od podmiotu publicznego jest doręczone fikcją i terminy z niego biegną; ryzyko zaakceptowane (D-107). Po powrocie odbierasz zaległe pisma i wpisujesz terminy do kalendarza ([modules/e-doreczenia.md](modules/e-doreczenia.md), [compliance-calendar.md](compliance-calendar.md)) |
| Twoja nieobecność, bez zastępcy | terminy biegną dalej; przed dłuższą nieobecnością składasz z wyprzedzeniem oraz sprawdzasz skrzynkę ADE i odbierasz pisma |

**Karta awaryjna** w menedżerze haseł i na papierze:

- dostęp do konsoli hostingu i klucza SSH;
- miejsce klucza sejfu PROD, klucza restic i poświadczeń celu backupu;
- lista obowiązków z terminami i narzędziem MF dla każdego;
- kontakt do doradcy podatkowego.
