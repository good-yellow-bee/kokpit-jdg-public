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

# Rola czatu (F6.1, D-58, D-59): tylko SELECT na widokach chat_* (nadaje migracja), transakcje tylko do odczytu, limit 2 s.
# Bez pliku hasła rola nie powstaje; na PROD tworzy ją krok go-live czatu (docs/operations.md).
if [ -s /run/app/chat-ro-password ]; then
  psql -v ON_ERROR_STOP=1 --username postgres --dbname "$POSTGRES_DB" --set=chat_password="$(cat /run/app/chat-ro-password)" <<'SQL'
CREATE ROLE chat_ro LOGIN PASSWORD :'chat_password';
ALTER ROLE chat_ro SET default_transaction_read_only = on;
ALTER ROLE chat_ro SET statement_timeout = '2s';
GRANT CONNECT ON DATABASE kokpit TO chat_ro;
GRANT USAGE ON SCHEMA public TO chat_ro;
SQL
fi

if [ "$KOKPIT_DEPLOYMENT" = prod ]; then
  [ -s /run/owner/backup-password ] || { echo 'Brak poświadczenia roli backupu.' >&2; exit 2; }
  psql -v ON_ERROR_STOP=1 --username postgres --dbname "$POSTGRES_DB" --set=backup_password="$(cat /run/owner/backup-password)" <<'SQL'
ALTER ROLE kokpit_backup LOGIN PASSWORD :'backup_password';
SQL
fi
