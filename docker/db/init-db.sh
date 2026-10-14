#!/bin/bash
set -euo pipefail
psql -v ON_ERROR_STOP=1 --username postgres --dbname "$POSTGRES_DB" --set=owner_password="$(cat /run/owner/owner-password)" --set=app_password="$(cat /run/app/app-password)" <<'SQL'
CREATE ROLE kokpit_owner LOGIN PASSWORD :'owner_password';
CREATE ROLE kokpit_app LOGIN PASSWORD :'app_password';
CREATE ROLE kokpit_backup NOLOGIN;
GRANT pg_read_all_data TO kokpit_backup;
ALTER SCHEMA public OWNER TO kokpit_owner;
GRANT CONNECT ON DATABASE kokpit TO kokpit_owner, kokpit_app;
GRANT USAGE ON SCHEMA public TO kokpit_app;
SQL

if [ "$KOKPIT_DEPLOYMENT" = prod ]; then
  [ -s /run/owner/backup-password ] || { echo 'Brak poświadczenia roli backupu.' >&2; exit 2; }
  psql -v ON_ERROR_STOP=1 --username postgres --dbname "$POSTGRES_DB" --set=backup_password="$(cat /run/owner/backup-password)" <<'SQL'
ALTER ROLE kokpit_backup LOGIN PASSWORD :'backup_password';
SQL
fi
