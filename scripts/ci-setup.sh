#!/bin/sh
set -eu
# Tylko fikcyjna baza projektu dev. Sekrety nie trafiają do checkoutu.
[ "${KOKPIT_DEPLOYMENT:-dev}" = dev ] || exit 2
docker compose exec -T db bash -c 'psql -U postgres -d kokpit -v ON_ERROR_STOP=1 --set=app_password="$(cat /run/app/app-password)" --set=owner_password="$(cat /run/owner/owner-password)"' <<'SQL'
ALTER ROLE kokpit_app PASSWORD :'app_password';
ALTER ROLE kokpit_owner PASSWORD :'owner_password';
SQL
docker compose exec -T --user root app php scripts/init-test-vault.php dev
docker compose exec -T --user root app php scripts/init-test-vault.php test
docker compose exec -T db bash -c 'PGPASSWORD="$(cat /run/owner/postgres-password)" psql -U postgres -h localhost -d kokpit -v ON_ERROR_STOP=1' <<'SQL'
SELECT 'CREATE DATABASE kokpit_test OWNER kokpit_owner' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname='kokpit_test')
\gexec
SQL
# Migracja używa osobnego połączenia właściciela tylko w tym jednorazowym kontenerze.
docker compose run --rm -T --user root -v "kokpit-dev_owner_credentials:/run/migration:ro" -e APP_ENV=test -e DB_NAME=kokpit_test -e DB_ROLE=kokpit_owner -e DB_OWNER_PASSWORD_FILE=/run/migration/owner-password app bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm -T --user root -v "kokpit-dev_owner_credentials:/run/migration:ro" -e DB_ROLE=kokpit_owner -e DB_OWNER_PASSWORD_FILE=/run/migration/owner-password app bin/console doctrine:migrations:migrate --no-interaction

docker compose exec -T --user root app find /app/var -path /app/var/run/backup -prune -o -exec chown 33:33 {} +
