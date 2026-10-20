#!/bin/sh
set -eu
# Tylko fikcyjna baza projektu dev. Sekrety nie trafiają do checkoutu.
[ "${KOKPIT_DEPLOYMENT:-dev}" = dev ] || exit 2
docker compose exec -T db bash -c 'psql -U postgres -d kokpit -v ON_ERROR_STOP=1 --set=app_password="$(cat /run/app/app-password)" --set=owner_password="$(cat /run/owner/owner-password)" --set=chat_password="$(cat /run/app/chat-ro-password)"' <<'SQL'
ALTER ROLE kokpit_app PASSWORD :'app_password';
ALTER ROLE kokpit_owner PASSWORD :'owner_password';
-- Rola czatu (F6.1, D-59): wolumen sprzed F6 nie przeszedł init-db.sh z chat_ro, więc powstaje tutaj.
SELECT 'CREATE ROLE chat_ro LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'chat_ro')
\gexec
ALTER ROLE chat_ro LOGIN PASSWORD :'chat_password';
ALTER ROLE chat_ro SET default_transaction_read_only = on;
ALTER ROLE chat_ro SET statement_timeout = '2s';
GRANT CONNECT ON DATABASE kokpit TO chat_ro;
GRANT USAGE ON SCHEMA public TO chat_ro;
SQL
docker compose exec -T --user root app php scripts/init-test-vault.php dev
docker compose exec -T --user root app php scripts/init-test-vault.php test
docker compose exec -T db bash -c 'PGPASSWORD="$(cat /run/owner/postgres-password)" psql -U postgres -h localhost -d kokpit -v ON_ERROR_STOP=1' <<'SQL'
SELECT 'CREATE DATABASE kokpit_test OWNER kokpit_owner' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname='kokpit_test')
\gexec
SQL
project="${COMPOSE_PROJECT_NAME:-kokpit-dev}"
# Migracja używa osobnego połączenia właściciela tylko w tym jednorazowym kontenerze.
docker compose run --rm -T --user root -v "${project}_owner_credentials:/run/migration:ro" -e APP_ENV=test -e DB_NAME=kokpit_test -e DB_ROLE=kokpit_owner -e DB_OWNER_PASSWORD_FILE=/run/migration/owner-password app bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm -T --user root -v "${project}_owner_credentials:/run/migration:ro" -e DB_ROLE=kokpit_owner -e DB_OWNER_PASSWORD_FILE=/run/migration/owner-password app bin/console doctrine:migrations:migrate --no-interaction

docker compose exec -T --user root app find /app/var -path /app/var/run/backup -prune -o -exec chown 33:33 {} +
