#!/bin/bash
set -euo pipefail
umask 077
export PGPASSFILE=/run/host/pgpass RESTIC_PASSWORD_FILE=/run/host/restic-password
export RESTIC_REPOSITORY AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
RESTIC_REPOSITORY=$(cat /run/host/restic-repository)
AWS_ACCESS_KEY_ID=$(cat /run/host/s3-access-key)
AWS_SECRET_ACCESS_KEY=$(cat /run/host/s3-secret-key)
case "$RESTIC_REPOSITORY" in s3:https://*) ;; *) echo 'Backup wymaga S3 przez HTTPS.' >&2; exit 2;; esac
# Cron startuje wyłącznie w kontenerze prod; klucze nie są częścią snapshotu.
mkdir -p /state/work
trap 'rm -f /state/work/kokpit.dump' EXIT
# Kotwica łańcucha audit_log przed zrzutem, więc zrzut zawiera ten wpis; sprawdza ją app:audit:verify --anchor (D-79).
audit_head=$(psql -h db -U kokpit_backup -d kokpit -AtX -v ON_ERROR_STOP=1 -c "SELECT coalesce((SELECT sequence || ':' || hash FROM audit_log ORDER BY sequence DESC LIMIT 1), '0:' || repeat('0', 64))")
[[ "$audit_head" =~ ^[0-9]+:[0-9a-f]{64}$ ]] || { echo 'Niepoprawna głowa łańcucha audit_log.' >&2; exit 2; }
pg_dump -h db -U kokpit_backup -d kokpit -Fc -f /state/work/kokpit.dump
restic backup --quiet --tag backup.run --tag "audit-head:$audit_head" /state/work/kokpit.dump /app/var/data
restic forget --quiet --prune --keep-daily 30 --keep-monthly 12 --keep-yearly 7
if [ "$(TZ=Europe/Warsaw date +%u)" = 7 ]; then restic check --quiet; fi
touch /state/last_success
heartbeat_url=$(cat /run/host/deadman-backup-url)
case "$heartbeat_url" in https://hc-ping.com/*) ;; *) echo 'Niepoprawny host dead-man.' >&2; exit 2;; esac
printf 'url = "%s"\n' "$heartbeat_url" | curl --config - --proto '=https' --max-redirs 0 --connect-timeout 5 --max-time 20 --fail --silent --output /dev/null || { echo 'Ping dead-man nie powiódł się.' >&2; exit 1; }
echo "backup.run: zapisano snapshot (audit-head:$audit_head) i znacznik sukcesu."
