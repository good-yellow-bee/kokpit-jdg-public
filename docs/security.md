# Bezpieczeństwo

Sejf dwupoziomowy z inwentarzem sekretów, dostęp do panelu, maskowanie, logi, powiadomienia, retencja i RODO. Stan na 8 października 2026.

## Co chronimy

Najcenniejsze są token KSeF z prawem wystawiania i dane autoryzujące. Każdy z nich pozwala działać w Twoim imieniu wobec urzędów. Certyfikat e-Doręczeń dochodzi dopiero z F4 (D-29).

| Zagrożenie | Ochrona | Sekcja |
| --- | --- | --- |
| wyciek sekretu przez log, wyjątek, kolejkę albo backup | `SecretStore`, `Redactor`, wiadomości z samymi identyfikatorami | Sejf, Maskowanie |
| przypadkowe wywołanie PROD z kodu, testu albo sesji Claude Code | `HostAllowlist`, `CONNECTORS_ENABLED=0`, na laptopie tylko sejf `dev` bez sekretów PROD | [architecture.md](architecture.md), Claude Code i repo |
| wysyłka bez Twojej zgody albo podwójna | bramka z hashem payloadu, step-up, `official_writes` bez ponowień | [approval-gate.md](approval-gate.md) |
| przejęcie panelu | Tailscale, TLS, passkey, step-up od F0b, CSRF | Panel |
| polecenie ukryte w piśmie lub fakturze | czat bez narzędzi zapisujących; szkic z odbiorcą tylko z bazy | [modules/czat.md](modules/czat.md) |
| utrata danych przechowywanych tylko u Ciebie | backup poza serwerem, test odtworzenia | [operations.md](operations.md) |

## Sejf dwupoziomowy

Decyzja: D-12. Jedynym wejściem do sekretów jest `SecretStore`; konektor nigdy nie czyta zmiennych środowiskowych.

| Poziom | Co | Gdzie | Zapis |
| --- | --- | --- | --- |
| statyczny | tokeny KSeF, certyfikaty i klucze, dane autoryzujące, klucze API, hasła usług | Symfony Secrets, sejf w repo per wdrożenie (tabela niżej, [Symfony](https://symfony.com/doc/current/configuration/secrets.html)) | CLI `secrets:set`, potem restart workerów, bo wartości są buforowane w procesie |
| rotujący | tokeny zmieniane w trakcie działania | tabela `credential_state`, szyfrowanie sodium secretbox kluczem z sejfu | `SecretStore::put` pod Symfony Lock na `(environment, kind)` |

**Interfejs**

- `get(key, environment)` zwraca wartość. Dla klucza statycznego czyta sekret nazwany według tabeli niżej. Dla rodzaju rotującego odszyfrowuje wiersz `credential_state`.
- `put(kind, environment, value, valid_until)` zapisuje tylko rodzaje rotujące.
- Nazwa sekretu zawiera cel konektora, bo sejf Symfony jest jeden na wdrożenie, a cele są od niego niezależne (D-13).
- Sejf `dev` nie zawiera sekretów celu `prod`. To najsilniejsza bariera po allowliście.

| Forma nazwy | Kiedy | Przykład |
| --- | --- | --- |
| `<CONNECTOR>_<CEL>_<NAZWA>` | konektor z `*_TARGET`; `<CEL>` to wartość celu wielkimi literami | `KSEF_PROD_TOKEN_WRITE` |
| `<CONNECTOR>_<CEL>_<NAZWA>` | konektor bez `*_TARGET` (`freshbooks`, `claude`, `push`, `mail`, `deadman`, rejestry); `<CEL>` to `PROD` w sejfie `prod` i `TEST` w sejfie `dev` | `PUSH_TEST_TOKEN` w sejfie `dev`, `PUSH_PROD_TOKEN` i `FRESHBOOKS_PROD_CLIENT_SECRET` w sejfie `prod` |
| bez `<CEL>` | sekret aplikacji bez konektora: `APP_SECRET`, `SECRETBOX_KEY_<key_id>`, `DB_<ROLE>_PASSWORD` | `DB_KOKPIT_APP_PASSWORD` |

**Sejf per wdrożenie**

Symfony ma osobny sejf i klucz na środowisko. Sejf wybiera `APP_RUNTIME_ENV`, a bez niej `APP_ENV` ([Symfony](https://symfony.com/doc/current/configuration.html)). Wdrożenia opisuje [operations.md](operations.md) (D-28).

| Wdrożenie | `APP_ENV` | Katalog sejfu | Klucz deszyfrujący | Dozwolone cele |
| --- | --- | --- | --- | --- |
| `kokpit-prod` na VPS | `prod` | `config/secrets/prod/` | plik 0400 montowany tylko do usług `kokpit-prod`; kopie w menedżerze haseł i na papierze | wszystkie |
| `kokpit-dev` na laptopie | `dev` | `config/secrets/dev/` | plik 0400 na laptopie, poza git; sekret środowiska GitHub joba `live` ([testing.md](testing.md)); kopia w menedżerze haseł | `test`, `demo` |
| `kokpit-restore`, jednorazowy na laptopie | `prod` | `config/secrets/prod/` | z menedżera haseł, tylko na czas testu odtworzenia | test odtworzenia: żadne, `CONNECTORS_ENABLED=0`; prawdziwa awaria: nowy VPS według [operations.md](operations.md) |
| CI | `test` | sejf tymczasowy z fikcyjnymi wartościami, tworzony na starcie joba | brak klucza poza jobem `live` | `test` |

- `kokpit-dev` nigdy nie działa z `APP_ENV=prod`. Szukałby sejfu PROD, a rejestry wybrałyby hosty PROD ([modules/rejestry-kursy.md](modules/rejestry-kursy.md)).
- `@group live` działa w `APP_ENV=test` z `APP_RUNTIME_ENV=dev`, więc czyta sejf `dev`.
- Klucz sejfu PROD trafia na laptop tylko na czas testu odtworzenia. Po teście usuwasz plik i projekt; w tym katalogu nie działa sesja Claude Code ([operations.md](operations.md)).

**Klucze sejfu**

- Klucz deszyfrujący sejfu PROD nigdy nie trafia do repo ani do backupu bazy i restic. Kopia jest w menedżerze haseł i na papierze.
- Na VPS klucz leży jako plik `config/secrets/prod/prod.decrypt.private.php` z uprawnieniami 0400, poza git ([Symfony](https://symfony.com/doc/current/configuration/secrets.html)). Montują go tylko kontenery aplikacji `kokpit-prod`, tylko do odczytu.
- Plik kopiujesz ręcznie przez SSH przy pierwszym wdrożeniu i po rotacji klucza. `git pull` z kroku 1 wdrożenia go nie dotyka ([operations.md](operations.md)).
- Klucz secretbox dla `credential_state` jest sekretem statycznym. Kolumna `key_id` pozwala go wymienić bez utraty wierszy.
- Zewnętrzne szyfrowanie plików sekretów odpada; zmienne środowiskowe nie przenoszą sekretów. Zmienna `*_FILE` niesie tylko ścieżkę do pliku.
- Jedna aplikacja OAuth FreshBooks na wdrożenie, bo nowy grant unieważnia poprzedni ([FreshBooks](https://www.freshbooks.com/api/authentication)). Firmy testowej nie ma (D-35).

## Inwentarz sekretów

Sekret konektora istnieje osobno dla każdego celu. Sekrety aplikacji i hosta istnieją osobno dla każdego wdrożenia. Kolumny `credential_state` są w [data-model.md](data-model.md), procedury rotacji w [operations.md](operations.md).

| Sekret | `credential_state.kind` | Poziom | Co daje | Ważność | Odcięcie | Faza |
| --- | --- | --- | --- | --- | --- | --- |
| token KSeF z prawem wystawiania | `ksef_token_write` | statyczny, data ważności | wystawianie faktur jako Ty | prawnie do 31.12.2026, technicznie najpóźniej 30.11.2027 ([modules/ksef.md](modules/ksef.md)) | unieważnienie w Aplikacji Podatnika albo przez API | F3 |
| token KSeF do odczytu | `ksef_token_read` | statyczny, data ważności | wgląd we wszystkie faktury | jak wyżej | jak wyżej | F1 |
| certyfikat KSeF typ 1 i klucz w pliku `.p12`, hasło pliku | `ksef_auth_cert` | statyczny, data ważności | uwierzytelnienie bez tokenu | do 2 lat ([MF](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/certyfikaty-ksef/)) | unieważnienie w KSeF | F1 (zapas) |
| poświadczenia pomocnika TE: testowa JDG z `InvoiceWrite` i drugi sprzedawca testowy (token albo certyfikat self-signed z hasłem) | – | statyczny, tylko cel `test` w sejfie `dev`; nigdy w sejfie `prod`; wytwarza je sesja F1 przez `/testdata` (Q-39) | wystawianie faktur testowych na TE w `@group live` ([phases/f1-ksef-odczyt.md](phases/f1-ksef-odczyt.md)) | według TE | unieważnienie na TE | F1 |
| certyfikat KSeF typ 2 i klucz | `ksef_offline_cert` | statyczny, data ważności | KOD II faktury offline | do 2 lat | unieważnienie w KSeF | tylko przy zmianie D-15 |
| tokeny dostępu i odświeżania KSeF, para na każdy token | `ksef_jwt_read`, `ksef_jwt_write` | rotujący | sesja API | odświeżanie do 7 dni ([KSeF](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/uwierzytelnianie.md)) | wygasa sam | F1 (`ksef_jwt_read`), F3 (`ksef_jwt_write`) |
| dane autoryzujące | `auth_data` (JSON: `birth_date`, `revenue_n2`; D-37) | statyczny, data ważności | wysyłka JPK i VAT-UE bez podpisu | kwota N−2 zmienia się co roku; aktualizacja 2 stycznia | zmiana kwoty unieważnia stare dane | F2.4 (data urodzenia w `Podmiot1` dry runu na danych PROD), F5 |
| aplikacja OAuth FreshBooks: id i sekret klienta | – | statyczny | grant OAuth | bez daty | usunięcie aplikacji | F2.2/F2.3 |
| tokeny OAuth FreshBooks | `freshbooks_oauth` | rotujący | faktury i płatności FreshBooks; do F3 tylko odczyt (D-35) | refresh token jednorazowy | odłączenie aplikacji we FreshBooks | F2.2/F2.3 |
| klucz Claude API | – | statyczny | wywołania modelu, koszt | bez daty; osobny workspace z niskim limitem wydatków | unieważnienie w konsoli | F6 |
| token ntfy | – | statyczny; przy self-hosted ntfy kopia tokenu w `auth-tokens` konfiguracji serwera `ntfy` w pliku hosta ([ntfy](https://docs.ntfy.sh/config/)) | wysyłka push | bez daty | nowy token | F0a |
| poświadczenia SMTP | – | statyczny | wysyłka maili | według dostawcy | zmiana hasła u dostawcy | F0a |
| poświadczenie atrapy `dummy` | `dummy_token` | statyczny, wartość fikcyjna; tylko sejf `dev` i sejf tymczasowy CI, nigdy `prod` (D-32) | wywołania konektora `dummy` w testach | bez daty | – | F0a |
| klucz repozytorium backupu | – | host, poza sejfem | odczyt całego backupu; plik czyta tylko usługa `backup` przez `RESTIC_PASSWORD_FILE` ([restic](https://restic.readthedocs.io/)); kopie offline w menedżerze haseł i na papierze | bez daty | nowe repozytorium backupu | F0a |
| poświadczenia celu backupu (S3 albo SFTP) | – | host, poza sejfem | zapis i odczyt snapshotów u dostawcy; tylko usługa `backup`; kopia w menedżerze haseł do odtworzenia | według dostawcy | nowy klucz u dostawcy | F0a |
| hasło roli `kokpit_backup` | – | host, poza sejfem | odczyt całej bazy przez `pg_dump`; `backup` czyta plik przez `PGPASSFILE`, `db` przy tworzeniu roli | bez daty | zmiana hasła roli | F0a |
| adres pingu dead-man `backup.run` | – | host, poza sejfem; tylko `kokpit-prod` | ping z usługi `backup` po udanym `restic backup` | bez daty | nowy check | F0a |
| adresy pingu dead-man zadań harmonogramu | – | statyczny, tylko sejf `prod` | zgłaszanie heartbeat | bez daty | nowy check | F0a |
| `APP_SECRET`, klucz secretbox `SECRETBOX_KEY_<key_id>` | – | statyczny | sesje, CSRF, `credential_state` | bez daty | rotacja z restartem | F0a |
| hasła ról `kokpit_app` i `chat_ro` (F6) | – | statyczny; kopia w pliku hosta dla `db` przy tworzeniu ról | połączenia aplikacji z bazą | bez daty | rotacja obu kopii z restartem | F0a |
| hasło roli `kokpit_owner` | – | host, poza sejfem | DDL i własność tabel; plik tylko dla kontenera migracji z kroku 5 wdrożenia ([operations.md](operations.md)) | bez daty | zmiana hasła roli | F0a |
| hasło superużytkownika PostgreSQL | – | host, poza sejfem | pełna kontrola bazy; `db` czyta je przez `POSTGRES_PASSWORD_FILE` ([obraz postgres](https://hub.docker.com/_/postgres)) | bez daty | zmiana hasła | F0a |
| klucz deszyfrujący sejfu PROD | – | host, poza sejfem | odczyt wszystkich sekretów statycznych `kokpit-prod`; plik tylko na VPS, na laptopie tylko na czas testu odtworzenia; wyłączony z backupu bazy i restic; kopie offline w menedżerze haseł i na papierze | bez daty | `secrets:generate-keys --rotate` | F0a |
| klucz deszyfrujący sejfu `dev` | – | plik na laptopie, poza git | odczyt sekretów celów testowych; plik 0400 na laptopie, sekret środowiska GitHub joba `live`, kopia w menedżerze haseł | bez daty | `secrets:generate-keys --rotate` przy `APP_ENV=dev`; nowy sekret w GitHub | F0a |
| klucz wdrożeniowy repo (deploy key tylko do odczytu) | – | host, poza sejfem | odczyt repo i zaszyfrowanego sejfu | bez daty | usunięcie klucza w GitHub | F0a |
| auth key Tailscale | – | host, poza sejfem | dostęp do sieci panelu | wygasanie klucza węzła serwera wyłączone ([Tailscale](https://tailscale.com/kb/1028/key-expiry)) | usunięcie węzła w Tailscale | F0a |
| token API DNS do DNS-01 albo klucz prywatny TLS panelu | – | host, poza sejfem | wydanie certyfikatu na nazwę panelu | token według dostawcy DNS; certyfikat odnawiany automatycznie (sekcja „TLS i nazwa”) | nowy token u dostawcy DNS; unieważnienie certyfikatu | F0a, tylko przy własnej domenie |

- Sekrety odłożone (D-29) wracają z modułem ([roadmap.md](roadmap.md)): certyfikat i token e-Doręczeń (`edoreczenia_qualified_cert`, `edoreczenia_access_token`, F4) oraz JWT CEIDG i klucz BIR GUS (D-20).
- Klucze publiczne MF nie są sekretami. KSeF trzyma je w puli PSR-16 celu, JPK w konfiguracji per cel jako plik na wolumenie wdrożenia. Ich ważność śledzi [operations.md](operations.md).
- Klucze prywatne passkeyów zostają na Twoich urządzeniach. W bazie są tylko klucze publiczne (`webauthn_credential`).
- Klucz SSH i dostęp do konsoli hostingu zostają na Twoich urządzeniach i w karcie awaryjnej ([operations.md](operations.md)). Dają pełną kontrolę nad hostem, także `app:passkey:reset`.
- Poziom „host, poza sejfem” to plik 0400 poza git, podany przez compose `secrets:` albo zmienną `*_FILE`. Czytają go `vpn`, `proxy`, `db`, `backup`, `ntfy` i `git pull` na hoście.
- Aplikacja czyta z poziomu hosta tylko klucz deszyfrujący swojego sejfu. Wyjątek: kontener migracji z kroku 5 wdrożenia czyta hasło `kokpit_owner`.
- Sekret z dwiema kopiami (Symfony Secrets dla aplikacji, plik hosta dla usługi) rotujesz w obu miejscach naraz ([operations.md](operations.md)).
- Nazwy F1 w sejfie `dev`: `KSEF_TEST_TOKEN_READ`, `KSEF_TEST_AUTH_CERT`, `KSEF_TEST_AUTH_CERT_PASSWORD`. Pomocnik TE: `KSEF_TEST_HELPER_*`, np. `KSEF_TEST_HELPER_SELLER_TOKEN`.
- Dane autoryzujące mogą też nie być przechowywane: sposób podpisu JPK to strategia, a wariant „wpisz przy wysyłce” zostaje otwarty.

## Stany poświadczeń

Ta sekcja to dom semantyki `credential_state.status`.

| Z | Do | Wyzwalacz | Aktor |
| --- | --- | --- | --- |
| – | `valid`, albo `expiring`, gdy do `valid_until` zostało 30 dni albo mniej | rejestracja daty ważności sekretu statycznego albo pierwsze uzyskanie tokenu | Ty, system |
| `valid` | `expiring` | do `valid_until` zostało 30 dni albo mniej | system, zadanie dzienne |
| `valid`, `expiring` | `expired` | minął `valid_until` albo system odrzucił odświeżenie | system |
| `valid`, `expiring`, `expired`, `revoked` | `valid` | rotacja: nowy sekret, `rotated_at`, udane wywołanie testowe w `last_smoke_ok_at` | Ty, system |
| każdy | `revoked` | unieważnienie po incydencie albo wymianie; wycofanie go-live ([operations.md](operations.md)) | Ty, step-up |

- `expiring`, `expired` po dacie i obowiązek `credential_expiry` dotyczą tylko rodzajów statycznych.
- Rodzaje rotujące nie zmieniają stanu, gdy token wygasa. System odświeża go albo uwierzytelnia się od nowa sekretem statycznym, bez przypomnień.
- Rodzaj rotujący przechodzi w `expired` tylko po odrzuceniu odświeżenia albo ponownego uwierzytelnienia. Wtedy dostajesz push z linkiem do ponownego połączenia.
- `revoked` kończy życie poświadczenia. Wiersz `(environment, kind)` wraca do `valid` tylko przez rotację z nowym sekretem.
- Akcja panelu „unieważnienie poświadczenia” zmienia tylko stan lokalny. Unieważnienie u wystawcy robisz osobno, według kolumny „Odcięcie” inwentarza.
- Wznowienie po `revoked`, także po wycofaniu go-live, wymaga nowego tokenu i rotacji.
- Warunek „poświadczenie ważne” spełniają `valid` i `expiring`; `expired` i `revoked` blokują zatwierdzenie i wysyłkę.
- Przypomnienia o wygaśnięciu: obowiązek `credential_expiry` 30, 7 i 1 dzień przed `valid_until`; dla `auth_data` tylko T0, 2 stycznia. Offsety i silnik w [compliance-calendar.md](compliance-calendar.md).
- Panel pokazuje datę ważności każdego poświadczenia, także tokenów KSeF.

## Panel

Panel działa tylko w Tailscale, bez publicznego portu. Uwierzytelnienie nie ufa adresowi IP ani samemu VPN. Zakres F0a jest wąski: jedno logowanie passkeyem; step-up i drugi passkey dochodzą w F0b z bramką (D-28).

**TLS i nazwa**

- Passkey wymaga bezpiecznego kontekstu i domeny jako RP ID; adres IP nie wystarczy ([WebAuthn](https://www.w3.org/TR/webauthn-3/)). Przekierowanie OAuth FreshBooks też wymaga HTTPS.
- Panel PROD działa na nazwie DNS w Tailscale z zaufanym certyfikatem: `tailscale cert` albo własna domena z wyzwaniem DNS-01 (Q-14).
- Nazwy z `tailscale cert` trafiają do publicznych logów Certificate Transparency ([Tailscale](https://tailscale.com/kb/1153/enabling-https)). Nazwa nie może zdradzać firmy ani celu.
- Nazwa panelu jest RP ID passkeyów. Zmiana nazwy, np. przy nowym hoście, wymaga `app:passkey:reset`.
- Certyfikat odnawia się automatycznie: proxy z ACME i DNS-01 albo cron z `tailscale cert` i przeładowaniem proxy. Certyfikat Let's Encrypt jest ważny najwyżej 90 dni ([Let's Encrypt](https://letsencrypt.org/docs/faq/)).
- Węzeł Tailscale serwera ma wyłączone wygasanie klucza ([Tailscale](https://tailscale.com/kb/1028/key-expiry)). Wygasły klucz albo certyfikat odcina panel i zatwierdzenia.
- `/health` zwraca 503 i wysyła push, gdy certyfikat panelu wygasa za mniej niż 14 dni ([operations.md](operations.md)).
- `trusted_proxies` wskazuje tylko proxy TLS w compose. Workery i baza nie mają publikowanych portów.
- `kokpit-dev` na laptopie działa na `localhost`, który przeglądarka traktuje jako bezpieczny kontekst ([Secure Contexts](https://www.w3.org/TR/secure-contexts/)).

**Passkey**

| Krok | Reguła | Faza |
| --- | --- | --- |
| Pakiet | `web-auth/webauthn-symfony-bundle`, Symfony Security | F0a |
| Pierwszy passkey | komenda `app:passkey:enrol-link` przez SSH tworzy jednorazowy link ważny 15 minut | F0a, wersja minimalna |
| Logowanie | jeden passkey | F0a |
| Utrata urządzeń | komenda `app:passkey:reset` przez SSH unieważnia wszystkie passkeye i wydaje nowy link | F0a, wersja minimalna |
| Kanał ICS obowiązków | jedyny adres panelu bez passkeya: `GET /calendar-feed/{token}.ics`, token 256 bitów z `app:calendar:feed-link` przez SSH, w bazie tylko sha256; nowy token unieważnia stary, `--revoke` wyłącza kanał; zły token daje 404. Treść jak push: rodzaj i okres obowiązku, bez kwot, notatek i kontrahentów; adres działa tylko w VPN jak panel (D-77) | po F0b |
| Drugi passkey | co najmniej 2 passkeye na różnych urządzeniach; panel ostrzega, gdy jest jeden; dodanie i unieważnienie ze step-up w `/passkeys`; ostatniego aktywnego panel nie unieważnia (D-40) | F0b |
| Sesja | bezczynność 30 minut, limit bezwzględny 12 godzin; ciasteczko `Secure`, `HttpOnly`, `SameSite=Strict` | F0a |
| CSRF | token Symfony w każdym formularzu zmieniającym stan, także w formularzach Turbo | F0a |
| Nagłówki | CSP bez zewnętrznych skryptów, HSTS na nazwie panelu; skrypty i style inline tylko z nonce żądania (`CspNonce`, D-73), bez `unsafe-inline` | F0a, F6 |

**Step-up** (od F0b, D-28). Akcje niżej wymagają świeżej asercji WebAuthn z `userVerification=required`, nie starszej niż 5 minut. Sprawdza ją `StepUpVerifier`; w `when@test` działa wersja z wirtualnym authenticatorem. Użyty passkey trafia do `approval.step_up_credential_id` albo do szczegółów wpisu `audit_log`.

| Akcja | Dom |
| --- | --- |
| zatwierdzenie w bramce i ręczne ponowienie wysyłki | [approval-gate.md](approval-gate.md) |
| złożenie poza kokpitem z wgranym UPO | [approval-gate.md](approval-gate.md) |
| ponowne otwarcie i ponowne zamknięcie okresu | [compliance-calendar.md](compliance-calendar.md) |
| wpisanie danych autoryzujących przy wysyłce (wariant otwarty) | [modules/jpk.md](modules/jpk.md) |
| potwierdzenie bilansu otwarcia: klucze `setting` `opening.*` | [compliance-calendar.md](compliance-calendar.md) |
| połączenie konta OAuth, unieważnienie poświadczenia | ta strona |
| dodanie albo unieważnienie passkeya | ta strona |

„Odbierz” pismo e-Doręczeń ze step-upem wraca z F4 (D-29, [approval-gate.md](approval-gate.md)).

## Maskowanie: `Redactor`

Jedna usługa `Redactor` maskuje dane we wszystkich ścieżkach wyjścia. Zmiana reguł nigdy nie przepisuje zapisanej historii.

**Reguły**

| Typ | Przykłady |
| --- | --- |
| po kluczu | `authorization`, `cookie`, `token`, `refresh_token`, `password`, `secret`, `private_key`, `pesel`, `birth_date`, `auth_data`, `revenue_n2`, `iban`, `account` |
| po wzorcu | PESEL z sumą kontrolną, NRB ze spacjami i bez, IBAN, JWT, data urodzenia w danych autoryzujących |
| czat | kontrahent (nazwa, NIP, adres, e-mail) jako pseudonim sesji według [modules/czat.md](modules/czat.md); poza tym NIP osoby fizycznej, imiona i nazwiska, adresy, sygnatury spraw; mapa pseudonimów w `chat_session.pseudonym_map` |

**Ścieżki**

| Ścieżka | Mechanizm |
| --- | --- |
| logi aplikacji | procesor Monolog na wszystkich kanałach |
| konektory | nagłówki maskowane; treści żądań i odpowiedzi żadnego konektora (HttpClient i `SoapClient`) nie są logowane; zostają hash i rozmiar w `connector_call` |
| `SoapClient` | bez opcji `trace`; `__getLastRequest` i `__getLastResponse` nigdy nie trafiają do logów |
| `connector_call.path` | szablon trasy, np. `/api/check/nip/{nip}/bank-account/{account}`; nigdy konkretne wartości |
| wyjątki | `zend.exception_ignore_args=On`; ślad bez argumentów |
| wyjątki konektorów | konektor opakowuje wyjątek transportu we własny wyjątek z kodem błędu, bez URL i treści |
| Messenger | wiadomości niosą tylko identyfikatory; szczegóły błędu w transporcie `failed` przechodzą przez `Redactor` (listener `WorkerMessageFailedEvent` albo własny serializer) |
| Doctrine | logowanie SQL z parametrami wyłączone poza środowiskiem deweloperskim |
| odpowiedzi rejestrów | surowa odpowiedź tylko w `stored_file` `verification_raw`, nie w logach |
| czat | maskowanie w widokach `chat_*` i serializerze wyniku narzędzia, przed modelem i przed zapisem |
| `notification.last_error`, `audit_log.details` | zapis po `Redactor` |

Test maskowania: kryterium `cc-07` i „Testy bezpieczeństwa” w [testing.md](testing.md).

## `audit_log` i `connector_call`

Dwa logi o różnych celach. Kolumny są w [data-model.md](data-model.md).

| Cecha | `connector_call` | `audit_log` |
| --- | --- | --- |
| Co | każde wywołanie zewnętrzne, także polling | akcje użytkownika i operacje ze skutkiem prawnym |
| Treść | metoda, host, szablon ścieżki, status, hash i rozmiar | aktor, akcja, cel, `approval_id`, hash payloadu, szczegóły po `Redactor` |
| Zapis | raz, po zakończeniu wywołania | tylko dopisywanie pod blokadą doradczą; łańcuch `prev_hash` i `hash` |
| Ochrona | zwykła tabela | rola `kokpit_app` bez `UPDATE`, `DELETE` i `TRUNCATE`; trigger odrzuca `UPDATE` i `DELETE` |
| Retencja | 90 dni | bez usuwania |
| Sprawdzenie | – | `app:audit:verify` przelicza łańcuch co tydzień |
| Kotwica poza serwerem | – | `backup.run` zapisuje głowę łańcucha w tagu snapshotu restic `audit-head:<sequence>:<hash>`; `app:audit:verify --anchor=<tag>` wykrywa historię przepisaną po tym snapshocie (D-79) |

**Role PostgreSQL**

| Rola | Uprawnienia | Kto używa |
| --- | --- | --- |
| `kokpit_owner` | właściciel tabel, DDL | tylko krok 5 `make deploy`, przez osobny DSN migracji ([operations.md](operations.md)) |
| `kokpit_app` | tylko DML; nie jest właścicielem; na `audit_log` bez `UPDATE`, `DELETE` i `TRUNCATE` | `app`, workery, `scheduler` |
| `kokpit_backup` | rola `pg_read_all_data`, tylko odczyt ([PostgreSQL](https://www.postgresql.org/docs/current/predefined-roles.html)) | `pg_dump` w usłudze `backup` |
| `chat_ro` (F6) | `SELECT` tylko na widokach `chat_*`; na roli `default_transaction_read_only = on` i `statement_timeout` 2 s; powstaje w `docker/db/init-db.sh` przy pliku hosta `chat-ro-password` (D-59) | połączenie Doctrine `chat` ([modules/czat.md](modules/czat.md)) |

- `kokpit_app` nie jest właścicielem tabel. Nie nada sobie uprawnień ani nie wyłączy triggera (`ALTER TABLE … DISABLE TRIGGER`).
- Test `cc-08` ([testing.md](testing.md)) próbuje też wyłączyć trigger rolą `kokpit_app`. Oczekiwany wynik: błąd bazy.

Dawne kryterium „każde zadanie zostawia wpis w `audit_log`” brzmi teraz: wywołanie zewnętrzne zostawia `connector_call`, a akcja z listy niżej zostawia `audit_log`.

**Akcje `audit_log`** (format `obszar.czynność`)

| Obszar | Akcje |
| --- | --- |
| `auth` | `login`, `login_failed`, `step_up`, `passkey_enrolled`, `passkey_revoked`, `recovery_link_issued` |
| `approval` | `requested`, `approved`, `cancelled`, `expired`, `superseded`, `retry_requested` |
| `dispatch` | `started`, `submitted`, `accepted`, `rejected`, `unknown`, `hash_mismatch` |
| `reconcile` | `resolved` |
| `edelivery` | `deadline_confirmed`: termin z pisma odebranego w aplikacji wpisany ręcznie do kalendarza (D-29); `receive_requested`, `copy_requested`, `received`, `received_elsewhere` odłożone do F4 |
| `filing` | `manual_recorded` |
| `period` | `closed`, `reopened` |
| `invoice` | `cost_reviewed`, `delivered_to_client` |
| `obligation` | `marked_not_applicable`, `payment_recorded`, `completed` |
| `ledger` | `manual_entry`, `imported` (import pliku ewidencji z F2.1: rok, format, sha256 pliku, liczby wierszy) |
| `draft` | `approved`, `executed`, `discarded` |
| `credential` | `registered`, `rotated`, `revoked`, `expired` |
| `setting` | `changed`; także klucze bilansu otwarcia `opening.*` |
| `connector` | `target_changed`, `enabled_changed` |
| `file` | `deleted`, `legal_hold_changed`, `hash_mismatch` |
| `ops` | `deployed`, `restored` |

Pełna nazwa akcji to obszar i czynność, np. `setting.changed`. Nowa akcja trafia najpierw do tej tabeli.

## Powiadomienia

Decyzje: D-19 i D-28. Push idzie tylko przez ntfy; wariant self-hosted albo ntfy.sh rozstrzyga Q-15. Treść push i maila powiadomień (`notification`) to kategoria, liczba i link do panelu. Doręczenie faktury klientowi (F3) jest poza tą zasadą ([modules/freshbooks.md](modules/freshbooks.md)).

- Nigdy nadawca pisma, nazwa kontrahenta, kwota ani treść. Szczegóły widać dopiero w panelu po zalogowaniu.
- Kolumny i deduplikacja są w [data-model.md](data-model.md); terminy przypomnień w [compliance-calendar.md](compliance-calendar.md).
- Powiadomienia mailowe z aplikacji e-Doręczeń zostają włączone na stałe, niezależnie od kokpitu. Do powrotu F4 to jedyny sygnał o nowym piśmie (D-29).

| Kanał | Prywatność | Źródło |
| --- | --- | --- |
| self-hosted ntfy (domyślnie) | na iOS serwis pośredni widzi tylko identyfikator wiadomości i hash tematu; telefon pobiera treść z Twojego serwera, więc potrzebuje VPN, inaczej dostarczenie może się opóźnić o godziny | [ntfy FAQ](https://docs.ntfy.sh/faq/) |
| ntfy.sh | nazwa tematu działa jak hasło; serwis loguje tematy i IP, buforuje wiadomości 12 godzin i dostarcza je do aplikacji Android przez Firebase | [ntfy](https://docs.ntfy.sh/config/) |
| mail (zapas powiadomień) | dostawca SMTP z poświadczeniami w Symfony Secrets (Q-40); ta sama zasada treści | D-19 |

Web Push w PWA i bot Telegram odpadają (D-19, D-28). PWA i Web Push są wierszami w [roadmap.md](roadmap.md).

## Retencja

KSeF przechowuje faktury, ale UPO, ewidencja, wysłane XML i snapshoty weryfikacji są tylko u Ciebie (D-03). `FileStore` jest kopią główną; backup poza serwerem nigdy nie jest jedyną kopią.

**Reguła podstawowa.** Dokumenty podatkowe przechowujesz do przedawnienia zobowiązania: 5 lat od końca roku, w którym upłynął termin płatności podatku (art. 70 § 1 i art. 86 § 1 [Ordynacji podatkowej](https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf)). Przykład: JPK_EWP za 2026 i PIT-28 mają termin 30.04.2027, więc przechowujesz je do 31.12.2032.

| Klasa | Gdzie | Okres | Podstawa |
| --- | --- | --- | --- |
| ewidencja przychodów, wykaz ŚT i WNiP | `revenue_entry`, `fixed_asset` | reguła podstawowa za rok podatkowy | art. 86 § 1 Ordynacji |
| faktury z numerem KSeF: XML, UPO, wizualizacja z KOD I | `stored_file` `xml_sent`, `upo`, `pdf`, `cost_document` | reguła podstawowa; KSeF przechowuje faktury 10 lat, kopia lokalna zapewnia ciągłość | art. 112 i 112aa [ustawy o VAT](https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf) |
| faktury i dokumenty spoza KSeF | `stored_file` `cost_document` | reguła podstawowa; przechowywanie w kraju albo elektronicznie z dostępem on-line dla organów | art. 112 i 112a ustawy o VAT |
| złożenia JPK, VAT-UE, PIT-28 | `stored_file` `xml_sent`, `upo` | reguła podstawowa za okres złożenia | art. 70 § 1 Ordynacji |
| dowody weryfikacji kontrahentów | `stored_file` `verification_raw`, `evidence` | jak dokument, który wspierają | konwencja kokpitu |
| certyfikaty rezydencji dostawców | `stored_file` `evidence` przy `counterparty` | reguła podstawowa za rok płatności | konwencja kokpitu |
| dowody bilansu otwarcia | `stored_file` `evidence` przy kluczach `setting` `opening.*` | jak ewidencja roku przejścia | konwencja kokpitu |
| szkice `email` i ich `outbound_payload` | `draft`, `stored_file` `outbound_payload` | 12 miesięcy od `executed` albo `discarded` | minimalizacja danych |
| `audit_log` | baza | bez usuwania; bez treści dokumentów | konwencja kokpitu |
| `connector_call` | baza | 90 dni | konwencja kokpitu |
| `notification` | baza | 90 dni od wysłania | konwencja kokpitu |
| `chat_session`, `chat_message` | baza | 12 miesięcy od zamknięcia rozmowy, potem usunięcie całej rozmowy | minimalizacja danych |
| logi aplikacji | pliki na serwerze | 30 dni | konwencja kokpitu |

- Klasy e-Doręczeń (pisma, dowody, szkice odpowiedzi) wracają z F4, a `classification` z F7 (D-29).
- `FileStore` wylicza `retention_until` przy zapisie z okresu właściciela pliku.
- Wiersze bazy spoza tabeli (`invoice`, `payment`, `fx_rate`, `filing`, `approval`, `verification`, `zus_payment`) podlegają regule podstawowej. `retention.purge` ich nie usuwa.
- Usunięcie (retencja, RODO, rozmowy) obejmuje backup dopiero po wygaśnięciu snapshotów, najdłużej 7 lat dla rocznych ([operations.md](operations.md)). Informujesz o tym w odpowiedzi na żądanie usunięcia.
- `legal_hold` ustawiasz przy kontroli, postępowaniu, sporze albo zawieszeniu biegu przedawnienia. Blokuje usunięcie.
- Zadanie retencji usuwa plik, ustawia `deleted_at` i zostawia wiersz z hashem. Każde usunięcie to wpis `file.deleted`.
- Harmonogram backupu i jego retencja są w [operations.md](operations.md).
- Wybór hostingu poza Polską sprawdzasz z art. 112a ustawy o VAT przed decyzją D-18.

## RODO

Jesteś administratorem danych. Kokpit przetwarza dane osobowe w ramach obowiązków podatkowych i dowodów weryfikacji.

| Kategoria | Przykłady | Ochrona |
| --- | --- | --- |
| Twoje dane | PESEL, data urodzenia, przychód N−2 w danych autoryzujących | sejf statyczny; maskowanie w logach |
| kontrahenci będący osobami fizycznymi | nazwa JDG, NIP, adres, rachunek | `Redactor` w logach i czacie; dane w `buyer_snapshot` tylko z dnia wystawienia |
| treść pism | imiona urzędników, dane z postępowań | kokpit nie pobiera pism, bo API jest odłożone (D-29); do modelu tylko według [modules/czat.md](modules/czat.md) |
| rozmowy z czatem | pytania, wyniki narzędzi | retencja 12 miesięcy; pseudonimy w sesji |

| Obowiązek | Realizacja |
| --- | --- |
| procesorzy: hosting, cel backupu, dostawca SMTP, Anthropic (F6), FreshBooks | umowa powierzenia przy każdym (art. 28 [RODO](https://uodo.gov.pl/pl/file/1460)); Anthropic: DPA z SCC (D-22) |
| naruszenie | ocena i zgłoszenie do UODO w 72 godziny (art. 33 RODO); najpierw unieważnienie poświadczeń według [operations.md](operations.md) |
| prawo do usunięcia | ograniczone obowiązkiem przechowywania dokumentów podatkowych (art. 17 ust. 3 lit. b RODO); poza nim `stored_file.deleted_at` i usunięcie rozmów |
| minimalizacja | push bez danych, pisma tylko po Twojej akcji, `connector_call` bez treści |
| przykłady i fixtures | wyłącznie fikcyjne dane oznaczone jako fikcyjne (D-24, [testing.md](testing.md)) |

## Claude Code i repo

`CLAUDE.md` i reguły w `.claude/rules` to kontekst, nie wymuszenie ([Claude Code](https://code.claude.com/docs/en/memory)). Ochrona działa w kodzie i w konfiguracji.

| Warstwa | Co robi | Ograniczenie |
| --- | --- | --- |
| test `HostAllowlist` w kodzie | pada przy hoście PROD bez celu `prod` | prawdziwa ochrona ([architecture.md](architecture.md)) |
| `CONNECTORS_ENABLED=0` i sejf `dev` bez sekretów PROD na laptopie | kod w sesji nie ma czym zawołać PROD | wyjątek: klucz sejfu PROD na czas testu odtworzenia, bez sesji Claude Code w tym katalogu |
| deny w `.claude/settings.json` | blokuje odczyt `*.decrypt.private.php`, `.env.local`, `.env.local.php`, `.env.*.local`; polecenia Bash i WebFetch z hostami PROD KSeF, JPK, e-Deklaracji i UA API; `secrets:reveal`, `secrets:list --reveal`, `secrets:decrypt-to-local`; `*_TARGET=prod` | nie łapie wywołania PROD z kodu PHP lub testów (`phpunit`, `bin/console`) ani hosta składanego w runtime; dlatego ochroną jest `HostAllowlist` ([uprawnienia](https://code.claude.com/docs/en/permissions)) |
| sandbox Claude Code (opcjonalny) | system operacyjny ogranicza pliki i hosty sieciowe poleceń powłoki; lista dozwolonych domen startuje pusta | obejmuje tylko polecenia powłoki, nie Read ani WebFetch ([sandbox](https://code.claude.com/docs/en/sandboxing)) |

- Repo jest prywatne. Fixtures i docs nie zawierają prawdziwych NIP, PESEL ani IBAN (D-24).
- Plik reguł deny powstaje w `.claude/settings.json`; zasady twarde są w [../.claude/rules/zasady-twarde.md](../.claude/rules/zasady-twarde.md).
