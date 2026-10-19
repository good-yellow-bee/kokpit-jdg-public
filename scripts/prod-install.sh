#!/usr/bin/env bash
# Pierwsza instalacja kokpit-prod na świeżym VPS (docs/operations.md, „Pierwsza instalacja”, D-98). Uruchamiasz ją Ty,
# raz, jako root, z checkoutu `main`, przed pierwszym `make deploy`. Każdy krok pomija to, co już istnieje, więc po
# błędzie poprawiasz przyczynę i uruchamiasz skrypt ponownie. Sekrety idą do plików hosta i do sejfu PROD; skrypt ich
# nie wypisuje. `--rehearsal` robi to samo na danych fikcyjnych w jednorazowym projekcie, bez Tailscale, rejestru i S3.
set -euo pipefail

usage() {
  cat <<'TXT'
Użycie: sudo scripts/prod-install.sh --node <nazwa> [--host-dir <katalog>] [--mail-host <host> --mail-from <adres> --mail-to <adres>]
        scripts/prod-install.sh --rehearsal [--keep]

  --node        neutralna nazwa węzła Tailscale: jest częścią adresu panelu i RP ID passkeyów, trafia do publicznych
                logów certyfikatów, więc nie zdradza firmy ani celu
  --host-dir    katalog plików hosta poza repo; domyślnie /etc/kokpit (prod.env, secrets/, ntfy/)
  --mail-host, --mail-from, --mail-to
                SMTP powiadomień (MAIL_HOST, MAIL_FROM, MAIL_TO); możesz je dopisać później w prod.env
  --rehearsal   próba na danych fikcyjnych: projekt kokpit-rehearsal, obrazy lokalne APP_IMAGE i PROXY_IMAGE
                (domyślnie kokpit-app:local i kokpit-proxy:local), atrapa interfejsu VPN i certyfikat self-signed
                zamiast Tailscale, bez rejestru, cosign i S3; na końcu pierwszy start i sprawdzenie /health
  --keep        po próbie zostaw projekt (usuniesz go: docker compose -p kokpit-rehearsal down -v)
TXT
}

die() { echo "$*" >&2; exit 2; }
step() { printf '\n%s\n' "$*" >&2; }

node='' host_dir='' mail_host='' mail_from='' mail_to='' rehearsal=0 keep=0
while [ $# -gt 0 ]; do
  case "$1" in
    --node) node=${2:?}; shift 2 ;;
    --host-dir) host_dir=${2:?}; shift 2 ;;
    --mail-host) mail_host=${2:?}; shift 2 ;;
    --mail-from) mail_from=${2:?}; shift 2 ;;
    --mail-to) mail_to=${2:?}; shift 2 ;;
    --rehearsal) rehearsal=1; shift ;;
    --keep) keep=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
  esac
done

root=$(cd "$(dirname "$0")/.." && pwd -P)
cd "$root"
if [ "$rehearsal" = 1 ]; then
  project=kokpit-rehearsal
  node=${node:-kokpit-rehearsal}
  host_dir=${host_dir:-$(mktemp -d)}
  panel_host="$node.rehearsal.invalid"
else
  project=kokpit-prod
  host_dir=${host_dir:-/etc/kokpit}
  # Klucze sejfu, hasła bazy i token ntfy powstają na tym hoście, nie w kontekście modelu (docs/security.md).
  [ -z "${CLAUDECODE:-}" ] || die 'Instalacja PROD nie działa w sesji Claude Code; próba: --rehearsal.'
  [ "$(id -u)" = 0 ] || die 'Uruchom jako root (sudo): skrypt zapisuje pliki hosta i cron odnowienia certyfikatu.'
  [ -n "$node" ] || { usage >&2; exit 2; }
fi
[[ "$node" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]] || die 'Nazwa węzła: małe litery, cyfry i myślniki.'
for value in "$mail_host" "$mail_from" "$mail_to"; do
  [[ "$value" =~ ^[A-Za-z0-9@._+-]*$ ]] || die "Niepoprawna wartość poczty: $value"
done
case "$host_dir" in /*) ;; *) die 'Katalog plików hosta podaj ścieżką bezwzględną.' ;; esac
case "$host_dir/" in "$root"/*) die 'Katalog plików hosta musi leżeć poza checkoutem.' ;; esac
secrets_dir="$host_dir/secrets"
env_file="$host_dir/prod.env"

# Kontenery jak w kokpit-prod: APP_ENV=prod, cele testowe, konektory wyłączone. Plik wdrożenia czyta dopiero make deploy.
export KOKPIT_DEPLOYMENT=prod APP_ENV=prod APP_DEBUG=0 CONNECTORS_ENABLED=0 KSEF_TARGET=test JPK_TARGET=test EDEKLARACJE_TARGET=test
export COMPOSE_PROFILES=prod HOST_SECRETS_DIR="$secrets_dir" NTFY_CONFIG_FILE="$host_dir/ntfy/server.yml" TS_HOSTNAME="$node"
unset COMPOSE_FILE COMPOSE_PROJECT_NAME PANEL_ORIGIN
compose() { docker compose --progress quiet -p "$project" -f compose.yaml "$@"; }
random_hex() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

if [ "$rehearsal" = 1 ]; then
  if [ -n "$(docker volume ls -q --filter "label=com.docker.compose.project=$project")" ]; then
    die "Projekt $project już istnieje; usuń go: docker compose -p $project down -v"
  fi
  cleanup() {
    if [ "$keep" = 1 ]; then
      echo "Projekt $project zostaje (--keep); usuń go: docker compose -p $project down -v; pliki hosta: $host_dir" >&2
    else
      compose down -v --remove-orphans > /dev/null 2>&1 || true
      rm -rf "$host_dir"
    fi
  }
  trap cleanup EXIT
fi

step '1/8 Host: architektura, narzędzia, urządzenie VPN, pamięć.'
# CI buduje obrazy tylko dla amd64; na innej architekturze make deploy padłby na exec format error.
[ "$rehearsal" = 1 ] || [ "$(uname -m)" = x86_64 ] || die "Host $(uname -m): obrazy kokpitu są tylko dla x86_64 (docs/operations.md, „Hosting”)."
command -v docker > /dev/null || die 'Brak docker.'
docker compose version > /dev/null || die 'Brak wtyczki docker compose.'
command -v git > /dev/null || die 'Brak git.'
if [ "$rehearsal" = 0 ]; then
  command -v make > /dev/null || die 'Brak make.'
  command -v cosign > /dev/null || die 'Brak cosign 2.x: zainstaluj go z wydań sigstore/cosign ze sprawdzeniem sumy (docs/operations.md).'
  [ -c /dev/net/tun ] || die 'Brak /dev/net/tun: sidecar Tailscale go potrzebuje.'
  memory=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)
  [ "$memory" -ge 1900000 ] || echo "Uwaga: host ma $((memory / 1024)) MB RAM; minimum to 2 GB (docs/operations.md, „Hosting”)." >&2
fi

step "2/8 Pliki hosta w $host_dir."
umask 077
install -d -m 0755 "$host_dir"
install -d -m 0700 "$secrets_dir" "$host_dir/ntfy"
# host_file <plik> <opis> <wzorzec> <wartość fikcyjna> [visible]: pyta o brakującą wartość; w próbie wpisuje fikcyjną.
host_file() {
  local file="$secrets_dir/$1" value=''
  if [ -s "$file" ]; then
    echo "$1: jest" >&2
    return
  fi
  if [ "$rehearsal" = 1 ]; then
    value=$4
  elif [ "${5:-}" = visible ]; then
    read -r -p "$2: " value < /dev/tty
  else
    read -r -s -p "$2 (niewidoczne): " value < /dev/tty
    echo >&2
  fi
  [[ "$value" =~ $3 ]] || die "$1: wartość nie pasuje do wzorca $3."
  printf '%s' "$value" > "$file.partial"
  chmod 0400 "$file.partial"
  mv "$file.partial" "$file"
  echo "$1: zapisany" >&2
}
host_file tailscale-auth 'Klucz uwierzytelniania Tailscale (tskey-auth-…)' '^tskey-[A-Za-z0-9-]+$' tskey-auth-fikcyjny
host_file restic-repository 'Repozytorium restic (s3:https://<endpoint>/<bucket>)' '^s3:https://[^[:space:]]+$' s3:https://s3.fikcyjny.invalid/kokpit visible
host_file s3-access-key 'Klucz dostępu S3' '^[^[:space:]]+$' fikcyjny-klucz-dostepu
host_file s3-secret-key 'Klucz tajny S3' '^[^[:space:]]+$' fikcyjny-klucz-tajny
host_file deadman-backup-url 'Adres pingu healthchecks.io checku backup.run' '^https://hc-ping\.com/[A-Za-z0-9/_-]+$' https://hc-ping.com/fikcyjny-backup visible
if [ ! -s "$secrets_dir/restic-password" ]; then
  random_hex 32 > "$secrets_dir/restic-password"
  chmod 0400 "$secrets_dir/restic-password"
  echo 'restic-password: wygenerowany' >&2
fi
if [ ! -f "$env_file" ]; then
  cat > "$env_file.partial" <<'ENV'
# Plik wdrożenia kokpit-prod (D-98), bez sekretów: sekrety są w sejfie PROD i w plikach HOST_SECRETS_DIR.
# Czytają go make deploy, make rollback i scripts/prod-cert.sh; jego wartości wygrywają ze zmiennymi powłoki.
# Zmiana celu albo CONNECTORS_ENABLED to osobne wdrożenie z checklisty go-live (docs/operations.md, „Go-live”).
KOKPIT_DEPLOYMENT=prod
KSEF_TARGET=test
JPK_TARGET=test
EDEKLARACJE_TARGET=test
CONNECTORS_ENABLED=0
MAIL_IN_HOST=''
ENV
  chmod 0644 "$env_file.partial"
  mv "$env_file.partial" "$env_file"
fi
# set_env <klucz> <wartość>: zamienia albo dopisuje wiersz; pozostałe wiersze pliku zostają.
set_env() {
  [[ "$2" =~ ^[A-Za-z0-9@._:/+-]*$ ]] || die "Niepoprawna wartość $1."
  if grep -q "^$1=" "$env_file"; then
    sed -i "s|^$1=.*|$1='$2'|" "$env_file"
  else
    printf "%s='%s'\n" "$1" "$2" >> "$env_file"
  fi
}
set_env TS_HOSTNAME "$node"
set_env HOST_SECRETS_DIR "$secrets_dir"
set_env NTFY_CONFIG_FILE "$NTFY_CONFIG_FILE"
for pair in "MAIL_HOST:$mail_host" "MAIL_FROM:$mail_from" "MAIL_TO:$mail_to"; do
  if [ -n "${pair#*:}" ] || ! grep -q "^${pair%%:*}=" "$env_file"; then
    set_env "${pair%%:*}" "${pair#*:}"
  fi
done

step '3/8 Obrazy app i proxy.'
if [ "$rehearsal" = 0 ]; then
  # Na VPS nic się nie buduje (D-93): obrazy commita z rejestru, ze sprawdzonym podpisem, przypięte do digestu.
  registry=${KOKPIT_REGISTRY:-ghcr.io/good-yellow-bee/kokpit-jdg-public}
  KOKPIT_COMMIT=$(git rev-parse HEAD)
  for image in app proxy; do
    docker pull --quiet "$registry/$image:$KOKPIT_COMMIT" > /dev/null \
      || die "Obrazu $image commita $KOKPIT_COMMIT nie da się pobrać: CI go nie opublikował albo host nie jest zalogowany (docker login ghcr.io)."
  done
  APP_IMAGE='' PROXY_IMAGE=''
  while IFS='=' read -r name reference; do
    [[ "$reference" =~ ^[a-z0-9./-]+@sha256:[0-9a-f]{64}$ ]] || die 'Niepoprawny wynik weryfikacji obrazów.'
    case "$name" in APP_IMAGE) APP_IMAGE=$reference ;; PROXY_IMAGE) PROXY_IMAGE=$reference ;; esac
  done < <(make --no-print-directory -s verify-images KOKPIT_REGISTRY="$registry" KOKPIT_COMMIT="$KOKPIT_COMMIT")
  [ -n "$APP_IMAGE" ] && [ -n "$PROXY_IMAGE" ] || die 'Weryfikacja obrazów nie zwróciła obu obrazów.'
else
  APP_IMAGE=${APP_IMAGE:-kokpit-app:local} PROXY_IMAGE=${PROXY_IMAGE:-kokpit-proxy:local}
  for image in "$APP_IMAGE" "$PROXY_IMAGE"; do
    docker image inspect "$image" > /dev/null 2>&1 \
      || die "Brak obrazu $image: docker build --target production -t kokpit-app:local . i docker build --target proxy -t kokpit-proxy:local ."
  done
fi
export APP_IMAGE PROXY_IMAGE
echo "app: $APP_IMAGE" >&2
echo "proxy: $PROXY_IMAGE" >&2

step '4/8 Hasła ról bazy w wolumenach owner_credentials i app_credentials.'
# Nowe hasła tylko dla pustej bazy; przy istniejącej bazie brak pliku oznacza zgubione poświadczenie, nie pierwszą instalację.
compose run --rm --no-deps -T --user root --entrypoint /bin/bash db -ec '
umask 077
fresh=1
[ ! -s /var/lib/postgresql/data/PG_VERSION ] || fresh=0
for target in /run/owner/postgres-password /run/owner/owner-password /run/owner/backup-password /run/app/app-password /run/app/chat-ro-password; do
  if [ -s "$target" ]; then echo "${target##*/}: jest" >&2; continue; fi
  if [ "$fresh" = 0 ]; then
    [ "$target" = /run/app/chat-ro-password ] && { echo "chat-ro-password: pominięty (baza istnieje; rola przy go-live czatu)" >&2; continue; }
    echo "Brak ${target##*/} przy istniejącej bazie: odtwórz plik z kopii, nie generuj nowego." >&2
    exit 2
  fi
  openssl rand -hex 32 > "$target"
  echo "${target##*/}: wygenerowany" >&2
done
# Uprawnienia jak w docker/db/start-db.sh, bo sejf (krok 6) czyta hasła jako www-data przed pierwszym startem bazy.
chown postgres:postgres /run/owner/*
chmod 0400 /run/owner/*
for target in /run/app/app-password /run/app/chat-ro-password; do
  [ ! -e "$target" ] || { chown postgres:33 "$target"; chmod 0440 "$target"; }
done'
backup_password=$(compose run --rm --no-deps -T --user root --entrypoint cat db /run/owner/backup-password)
[[ "$backup_password" =~ ^[0-9a-f]{64}$ ]] || die 'Niepoprawne hasło roli kokpit_backup.'
printf 'db:5432:kokpit:kokpit_backup:%s\n' "$backup_password" > "$secrets_dir/pgpass.partial"
unset backup_password
chmod 0400 "$secrets_dir/pgpass.partial"
mv "$secrets_dir/pgpass.partial" "$secrets_dir/pgpass"
echo 'pgpass: zapisany' >&2

step "5/8 Konfiguracja ntfy z uwierzytelnieniem w $host_dir/ntfy."
ntfy_config="$host_dir/ntfy/server.yml"
# Polecenia ntfy bez serwera; montowanie konfiguracji lokalnej, bo brakujący plik hosta Docker utworzyłby jako katalog.
ntfy() { NTFY_CONFIG_FILE=./docker/ntfy/server.yml compose run --rm --no-deps -T --entrypoint ntfy ntfy "$@" 2> /dev/null; }
password_hash() { printf '%s\n%s\n' "$1" "$1" | ntfy user hash | grep -oE '\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}'; }
if [ ! -s "$ntfy_config" ]; then
  token=$(ntfy token generate)
  publisher_hash=$(password_hash "$(random_hex 24)")
  phone_password=$(random_hex 16)
  phone_hash=$(password_hash "$phone_password")
  [ -n "$publisher_hash" ] && [ -n "$phone_hash" ] || die 'ntfy nie zwrócił skrótu hasła.'
  cat > "$ntfy_config.partial" <<YAML
# Konfiguracja ntfy kokpit-prod (D-98), plik hosta: temat kokpit, publikuje aplikacja tokenem z sejfu (PUSH_PROD_TOKEN),
# czyta telefon (użytkownik telefon, hasło w phone-password obok). Reszta odmowa.
listen-http: ':80'
cache-file: /var/lib/ntfy/cache.db
auth-file: /var/lib/ntfy/auth.db
auth-default-access: deny-all
behind-proxy: true
auth-users:
  - 'kokpit:$publisher_hash:user'
  - 'telefon:$phone_hash:user'
auth-access:
  - 'kokpit:kokpit:wo'
  - 'telefon:kokpit:ro'
auth-tokens:
  - 'kokpit:$token:kokpit-app'
YAML
  printf '%s' "$phone_password" > "$host_dir/ntfy/phone-password"
  chmod 0400 "$ntfy_config.partial" "$host_dir/ntfy/phone-password"
  mv "$ntfy_config.partial" "$ntfy_config"
  unset phone_password publisher_hash phone_hash
  echo 'server.yml i phone-password: zapisane' >&2
else
  echo 'server.yml: jest' >&2
fi
token=$(grep -oE 'tk_[a-z0-9]{29}' "$ntfy_config" | head -n 1)
[ -n "$token" ] || die "Brak tokenu w $ntfy_config."

step '6/8 Sejf PROD w wolumenie vault.'
compose run --rm --no-deps -T --user root --entrypoint sh app -ec 'install -d -o 33 -g 33 -m 0700 /run/kokpit/vault/prod'
printf '%s' "$token" | compose run --rm --no-deps -T --entrypoint php app scripts/init-prod-vault.php
unset token

step '7/8 Adres panelu i certyfikat TLS.'
if [ "$rehearsal" = 0 ]; then
  compose up -d vpn
  status='' panel_host=''
  for _ in $(seq 1 60); do
    status=$(compose exec -T vpn tailscale status --json 2> /dev/null \
      | compose exec -T app php -r '$s = json_decode(stream_get_contents(STDIN), true); echo ($s["BackendState"] ?? ""), " ", ($s["CertDomains"][0] ?? "");' 2> /dev/null || true)
    [ "${status%% *}" != Running ] || break
    sleep 2
  done
  [ "${status%% *}" = Running ] || die "Tailscale nie połączył się (stan: ${status:-brak}); sprawdź klucz w $secrets_dir/tailscale-auth: docker compose -f compose.yaml logs vpn"
  panel_host=${status#* }
  [ -n "$panel_host" ] && [ "$panel_host" != Running ] \
    || die 'Tailnet nie wydaje certyfikatów: w konsoli Tailscale (DNS) włącz MagicDNS i HTTPS Certificates, potem uruchom skrypt ponownie.'
  case "$panel_host" in "$node".*) ;; *) echo "Uwaga: Tailscale nadał nazwę $panel_host zamiast $node.*; ta nazwa zostaje adresem panelu." >&2 ;; esac
  set_env PANEL_ORIGIN "https://$panel_host"
  KOKPIT_ENV_FILE="$env_file" scripts/prod-cert.sh
  if [ -d /etc/cron.d ]; then
    cat > /etc/cron.d/kokpit-panel-cert <<CRON
# Odnowienie certyfikatu panelu kokpit-prod (scripts/prod-cert.sh, D-98).
SHELL=/bin/bash
17 4 * * * root cd $root && KOKPIT_ENV_FILE=$env_file scripts/prod-cert.sh 2>&1 | logger -t kokpit-cert
CRON
    chmod 0644 /etc/cron.d/kokpit-panel-cert
    echo 'Cron odnowienia certyfikatu: /etc/cron.d/kokpit-panel-cert' >&2
  else
    echo "Brak /etc/cron.d: dodaj codzienne uruchomienie KOKPIT_ENV_FILE=$env_file $root/scripts/prod-cert.sh." >&2
  fi
else
  # Próba: adres panelu znany z góry, atrapa interfejsu tailscale0 w przestrzeni sieciowej app i certyfikat self-signed.
  set_env PANEL_ORIGIN "https://$panel_host"
  export PANEL_ORIGIN="https://$panel_host"
  compose up -d app
  vpn_image=$(compose config --format json | compose exec -T app php -r 'echo json_decode(stream_get_contents(STDIN), true)["services"]["vpn"]["image"];')
  docker run --rm --network "container:$(compose ps -q app)" --cap-add NET_ADMIN --entrypoint sh "$vpn_image" -ec \
    'ip link add tailscale0 type dummy 2> /dev/null || ip link add tailscale0 type veth peer name tailscale0p
     ip link set tailscale0p up 2> /dev/null || true
     ip addr add 100.64.0.1/32 dev tailscale0; ip link set tailscale0 up'
  compose run --rm --no-deps -T --user root -v "${project}_certificates:/certificates" --entrypoint sh db -ec "
    openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj '/CN=$panel_host' -addext 'subjectAltName=DNS:$panel_host' \
      -keyout /certificates/panel.key -out /certificates/panel.crt 2> /dev/null
    chmod 0644 /certificates/panel.crt; chmod 0600 /certificates/panel.key"
  echo "Certyfikat self-signed dla $panel_host." >&2
fi
echo "PANEL_ORIGIN=https://$panel_host w $env_file" >&2

step '8/8 Backup: repozytorium restic.'
if [ "$rehearsal" = 0 ]; then
  compose run --rm --no-deps -T --entrypoint /bin/bash backup -ec '
export RESTIC_REPOSITORY AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
RESTIC_REPOSITORY=$(cat /run/host/restic-repository)
AWS_ACCESS_KEY_ID=$(cat /run/host/s3-access-key)
AWS_SECRET_ACCESS_KEY=$(cat /run/host/s3-secret-key)
if restic cat config > /dev/null 2>&1; then
  echo "Repozytorium restic: jest" >&2
else
  restic init > /dev/null
  echo "Repozytorium restic: utworzone" >&2
fi'
  cat >&2 <<TXT

Instalacja gotowa. Dalej, w tym katalogu ($root):
1. make deploy
   Czyta $env_file. Pierwsze wdrożenie robi pierwszy backup i czeka do 7 minut na pierwszy przebieg harmonogramu.
2. export KOKPIT_DEPLOYMENT=prod
   docker compose -f compose.yaml exec app bin/console app:passkey:enrol-link
   Link otwórz w ciągu 15 minut na urządzeniu w Tailscale (https://$panel_host).
3. Kopie do menedżera haseł i na papier, nigdy do repo ani backupu:
   klucz sejfu:    docker compose -f compose.yaml exec -T app base64 -w0 /run/kokpit/vault/prod/prod.decrypt.private.php
   hasło restic:   cat $secrets_dir/restic-password
4. Telefon, aplikacja ntfy: serwer https://$panel_host/ntfy, temat kokpit, użytkownik telefon,
   hasło: cat $host_dir/ntfy/phone-password
5. Sekrety i ustawienia z docs/operations.md (KSeF, dead-man, MAIL_PROD_DSN, …), potem strona /setup.
TXT
  exit 0
fi
echo 'Próba: bez S3, repozytorium restic pominięte.' >&2

step 'Próba: pierwszy start jak w make deploy (migracje, usługi, /health).'
compose run --rm --user root -v "${project}_owner_credentials:/run/migration:ro" -e DB_ROLE=kokpit_owner app sh -ec 'credential=$(mktemp); install -o 33 -g 33 -m 0400 /run/migration/owner-password "$credential"; export DB_OWNER_PASSWORD_FILE="$credential"; exec su -s /bin/sh www-data -c "bin/console doctrine:migrations:migrate --no-interaction --quiet"' 2> >(grep -v 'User Deprecated' >&2)
compose run --rm app bin/console cache:clear --quiet 2> >(grep -v 'User Deprecated' >&2)
compose up -d app worker_reads worker_official_writes worker_notifications scheduler proxy ntfy
# Pierwszy przebieg harmonogramu przychodzi do 5 minut po starcie: próba czeka na heartbeat, bo dowodzi on, że workery
# odczytują wiadomości z harmonogramu. Backupu próba nie robi, a wolne miejsce zależy od maszyny próby.
health='' heartbeat=''
for _ in $(seq 1 30); do
  health=$(compose exec -T app curl --silent --cacert /run/kokpit/certificates/panel.crt --resolve "$panel_host:443:100.64.0.1" "https://$panel_host/health" || true)
  heartbeat=$(printf '%s' "$health" | compose exec -T app php -r 'echo json_decode(stream_get_contents(STDIN), true)["checks"]["scheduler_heartbeat"] ?? "";' || true)
  [ "$heartbeat" != ok ] || break
  sleep 15
done
printf '%s' "$health" | compose exec -T app php -r '
$health = json_decode(stream_get_contents(STDIN), true);
if (!is_array($health) || !is_array($health["checks"] ?? null)) { fwrite(STDERR, "/health nie odpowiada przez proxy.\n"); exit(1); }
$failed = array_keys(array_filter($health["checks"], static fn(string $state): bool => $state !== "ok"));
echo "/health: ", json_encode($health["checks"]), "\n";
if (array_diff($failed, ["backup", "disk_space"]) !== []) { fwrite(STDERR, "Błędne sprawdzenia: " . implode(", ", $failed) . "\n"); exit(1); }' >&2
link=$(compose exec -T app bin/console app:passkey:enrol-link 2> >(grep -v 'User Deprecated' >&2))
case "$link" in *"https://$panel_host/"*) echo "Link rejestracji passkeya wskazuje https://$panel_host." >&2 ;; *) die 'Link rejestracji passkeya nie używa PANEL_ORIGIN.' ;; esac
push=$(compose exec -T app sh -ec 'curl --silent --output /dev/null --write-out "%{http_code}" --data "proba: 1; /" -H "Authorization: Bearer $(cat)" http://ntfy/kokpit' < <(grep -oE 'tk_[a-z0-9]{29}' "$ntfy_config" | head -n 1))
[ "$push" = 200 ] || die "ntfy nie przyjął publikacji tokenem (HTTP $push)."
anonymous=$(compose exec -T app curl --silent --output /dev/null --write-out '%{http_code}' --data x http://ntfy/kokpit)
[ "$anonymous" = 403 ] || die "ntfy przyjął publikację bez tokenu (HTTP $anonymous)."
echo 'ntfy: publikacja tokenem 200, bez tokenu 403.' >&2
echo 'Próba zakończona.' >&2
