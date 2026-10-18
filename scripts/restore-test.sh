#!/usr/bin/env bash
# Test odtworzenia jednym poleceniem (docs/operations.md, „Test odtworzenia”, D-28, D-89). Uruchamiasz go Ty, na laptopie,
# z checkoutu commita z ostatniego `ops.deployed`. Stawia jednorazowy projekt `kokpit-restore` (tylko `db` i kontenery
# jednorazowe), odtwarza bazę i `var/data` z jednego snapshotu restic, uruchamia `app:ops:restore-check` i usuwa projekt
# z wolumenami. Raport JSON trafia do `var/evidence/restore/` (poza repo).
set -euo pipefail

usage() {
  cat <<'TXT'
Użycie: scripts/restore-test.sh --secrets <katalog> [--expected <plik>] [--snapshot <id>] [--repo-dir <katalog>] [--keep]

  --secrets   katalog z kopiami plików hosta backupu: restic-password, restic-repository, s3-access-key, s3-secret-key
  --expected  liczby z PROD: app:ops:restore-check --counts-only uruchomione w kokpit-prod
  --snapshot  identyfikator snapshotu restic; domyślnie latest
  --repo-dir  lokalna kopia repozytorium restic zamiast restic-repository (tylko odczyt)
  --keep      nie usuwaj projektu po teście (do obejrzenia; potem docker compose -p kokpit-restore down -v)
TXT
}

secrets='' expected='' snapshot=latest repo_dir='' keep=0
while [ $# -gt 0 ]; do
  case "$1" in
    --secrets) secrets=${2:?}; shift 2 ;;
    --expected) expected=${2:?}; shift 2 ;;
    --snapshot) snapshot=${2:?}; shift 2 ;;
    --repo-dir) repo_dir=${2:?}; shift 2 ;;
    --keep) keep=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
  esac
done

# Klucz restic i poświadczenia celu backupu nie trafiają do kontekstu modelu (docs/security.md).
[ -z "${CLAUDECODE:-}" ] || { echo 'Test odtworzenia nie działa w sesji Claude Code (docs/security.md).' >&2; exit 2; }
[ "${KOKPIT_DEPLOYMENT:-restore}" = restore ] || { echo 'Test odtworzenia działa tylko w projekcie kokpit-restore.' >&2; exit 2; }
[[ "$snapshot" =~ ^(latest|[0-9a-f]{8,64})$ ]] || { echo 'Snapshot: latest albo identyfikator szesnastkowy.' >&2; exit 2; }

root=$(cd "$(dirname "$0")/.." && pwd -P)
[ -n "$secrets" ] && [ -d "$secrets" ] || { usage >&2; exit 2; }
secrets=$(cd "$secrets" && pwd -P)
case "$secrets/" in "$root"/*) echo 'Katalog --secrets musi leżeć poza checkoutem.' >&2; exit 2 ;; esac
[ -s "$secrets/restic-password" ] || { echo 'Brak pliku restic-password.' >&2; exit 2; }
if [ -n "$repo_dir" ]; then
  [ -d "$repo_dir" ] || { echo 'Brak katalogu --repo-dir.' >&2; exit 2; }
  repo_dir=$(cd "$repo_dir" && pwd -P)
else
  for file in restic-repository s3-access-key s3-secret-key; do
    [ -s "$secrets/$file" ] || { echo "Brak pliku $file." >&2; exit 2; }
  done
fi
[ -z "$expected" ] || [ -s "$expected" ] || { echo 'Brak pliku --expected.' >&2; exit 2; }
[ -z "$expected" ] || expected=$(cd "$(dirname "$expected")" && pwd -P)/$(basename "$expected")

cd "$root"
# Jednorazowy projekt: APP_ENV=prod jak PROD, ale konektory wyłączone, cele testowe, bez backup, vpn, proxy i workerów.
export KOKPIT_DEPLOYMENT=restore APP_ENV=prod APP_DEBUG=0 CONNECTORS_ENABLED=0 KSEF_TARGET=test JPK_TARGET=test EDEKLARACJE_TARGET=test
export HOST_SECRETS_DIR="$secrets"
unset COMPOSE_FILE COMPOSE_PROFILES COMPOSE_PROJECT_NAME
KOKPIT_COMMIT=$(git rev-parse HEAD)
APP_IMAGE="kokpit-app:restore-$KOKPIT_COMMIT"
export KOKPIT_COMMIT APP_IMAGE
compose() { docker compose --progress quiet -f compose.yaml "$@"; }
backup() { compose --profile prod run --rm --no-deps -T "$@"; }

if [ -n "$(docker volume ls -q --filter label=com.docker.compose.project=kokpit-restore)" ]; then
  echo 'Projekt kokpit-restore już istnieje; usuń go: docker compose -p kokpit-restore down -v' >&2
  exit 2
fi
cleanup() {
  if [ "$keep" = 1 ]; then
    echo 'Projekt kokpit-restore zostaje (--keep); usuń go: docker compose -p kokpit-restore down -v' >&2
  else
    compose --profile prod down -v --remove-orphans > /dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

echo '1/5 Budowa obrazów app i backup z bieżącego commita.' >&2
compose --profile prod build app backup > /dev/null
echo '2/5 Pusta baza projektu kokpit-restore.' >&2
compose up -d --wait db > /dev/null
# Pierwszy kontener z obrazem app wypełnia wolumen runtime właścicielem 33, zanim zamontuje go kontener backup.
compose run --rm --no-deps -T app true

echo "3/5 Odtworzenie snapshotu $snapshot (restic --no-lock, repozytorium tylko do odczytu)." >&2
# Pusta tablica bez rozwinięcia pod set -u: bash 3.2 na macOS.
repo_mount=()
[ -z "$repo_dir" ] || repo_mount=(-v "$repo_dir:/repo:ro")
snapshot_id=$(backup ${repo_mount[@]+"${repo_mount[@]}"} -e RESTORE_SNAPSHOT="$snapshot" --entrypoint /bin/bash backup -c '
set -euo pipefail
export RESTIC_PASSWORD_FILE=/run/host/restic-password
if [ -d /repo ]; then
  export RESTIC_REPOSITORY=/repo
else
  RESTIC_REPOSITORY=$(cat /run/host/restic-repository)
  case "$RESTIC_REPOSITORY" in s3:https://*) ;; *) echo "Repozytorium wymaga S3 przez HTTPS." >&2; exit 2;; esac
  AWS_ACCESS_KEY_ID=$(cat /run/host/s3-access-key)
  AWS_SECRET_ACCESS_KEY=$(cat /run/host/s3-secret-key)
  export RESTIC_REPOSITORY AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
fi
restic --no-lock snapshots --json > /state/snapshots.json
restic --no-lock snapshots --json "$RESTORE_SNAPSHOT" > /state/target.json
[ "$(grep -o "\"short_id\":" /state/target.json | wc -l)" = 1 ] || { echo "Snapshot $RESTORE_SNAPSHOT nie jest jednoznaczny." >&2; exit 2; }
id=$(grep -oE "\"id\":\"[0-9a-f]{64}\"" /state/target.json | head -n 1 | cut -d "\"" -f 4)
restic --no-lock --quiet restore "$id" --target /state/restore >&2
[ -s /state/restore/state/work/kokpit.dump ] || { echo "Snapshot nie zawiera zrzutu bazy." >&2; exit 2; }
chmod 0644 /state/snapshots.json
printf "%s\n" "$id"
')
[[ "$snapshot_id" =~ ^[0-9a-f]{64}$ ]] || { echo 'Nie ustalono identyfikatora snapshotu.' >&2; exit 1; }

echo "4/5 pg_restore i pliki var/data ze snapshotu ${snapshot_id:0:8}." >&2
backup --entrypoint cat backup /state/restore/state/work/kokpit.dump \
  | compose exec -T --user postgres db pg_restore --dbname=kokpit --exit-on-error --single-transaction
backup --entrypoint /bin/sh backup -c 'if [ -d /state/restore/app/var/data ]; then tar -C /state/restore/app/var -cf - data; else tar -cf - -T /dev/null; fi' \
  | compose run --rm --no-deps -T --entrypoint tar app -C /app/var -xf -

echo '5/5 app:files:verify, app:audit:verify z kotwicami, liczby z miesiąca.' >&2
umask 077
mkdir -p var/evidence/restore
report="var/evidence/restore/kokpit-restore-$(date -u +%Y%m%dT%H%M%SZ).json"
check=(bin/console app:ops:restore-check --snapshots=var/run/backup/snapshots.json "--snapshot=$snapshot_id")
[ -z "$expected" ] || check+=(--expected=-)
# Sekret frameworka jednorazowy; baza przez plik hasła kokpit_app tego projektu, bez sejfu PROD.
APP_SECRET=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
export APP_SECRET
status=0
compose run --rm --no-deps -T -e APP_SECRET -e DB_OWNER_PASSWORD_FILE=/run/kokpit/db/app-password app "${check[@]}" \
  < "${expected:-/dev/null}" > "$report" || status=$?
echo "Raport: $report" >&2
echo 'Wpis ops.restored w kokpit-prod: docker compose -f compose.yaml exec -T app bin/console app:ops:record-restore - < raport.json' >&2
exit "$status"
