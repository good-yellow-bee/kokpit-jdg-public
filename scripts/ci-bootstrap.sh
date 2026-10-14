#!/bin/sh
set -eu
docker compose build app proxy
docker compose up -d --wait db app proxy
docker compose exec -T --user root app chown -R 33:33 /app/vendor /run/kokpit/vault
docker compose exec -T --user root app find /app/var -path /app/var/run/backup -prune -o -exec chown 33:33 {} +
docker compose exec -T app composer install --no-interaction --prefer-dist --no-progress
sh scripts/ci-setup.sh
docker compose exec -T app bin/console cache:clear
docker compose exec -T -e APP_ENV=test -e DB_NAME=kokpit_test app bin/console cache:clear
docker compose config --format json | docker compose exec -T app php scripts/check-compose.php dev
KOKPIT_DEPLOYMENT=prod APP_ENV=prod COMPOSE_PROFILES=prod docker compose -f compose.yaml config --format json | docker compose exec -T app php scripts/check-compose.php prod
docker compose exec -T app sh scripts/check-fpm.sh
docker compose exec -T app php scripts/check-assets.php
