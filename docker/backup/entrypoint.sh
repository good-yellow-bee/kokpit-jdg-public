#!/bin/sh
set -eu
[ "${KOKPIT_DEPLOYMENT:-}" = prod ] || { echo 'Backup działa tylko w kokpit-prod.' >&2; exit 2; }
for file in pgpass restic-password restic-repository s3-access-key s3-secret-key deadman-backup-url; do
  [ -s "/run/host/$file" ] || { echo "Brak pliku konfiguracji backupu: $file." >&2; exit 2; }
done
printf '%s\n' 'SHELL=/bin/bash' 'TZ=Europe/Warsaw' '30 2 * * * root /usr/local/bin/run-backup >> /proc/1/fd/1 2>> /proc/1/fd/2' > /etc/cron.d/kokpit
chmod 0644 /etc/cron.d/kokpit
exec cron -f
