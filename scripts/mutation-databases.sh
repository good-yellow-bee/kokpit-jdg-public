#!/bin/sh
set -eu
# Kopie fikcyjnej bazy testowej dla wątków Infection (`TEST_TOKEN`, D-104), tylko w projekcie dev.
[ "${KOKPIT_DEPLOYMENT:-dev}" = dev ] || exit 2
threads="${1:?liczba wątków}"
i=1
while [ "$i" -le "$threads" ]; do
  docker compose exec -T db bash -c 'PGPASSWORD="$(cat /run/owner/postgres-password)" psql -U postgres -h localhost -d kokpit -v ON_ERROR_STOP=1' <<SQL
DROP DATABASE IF EXISTS kokpit_test_$i;
CREATE DATABASE kokpit_test_$i TEMPLATE kokpit_test OWNER kokpit_owner;
SQL
  i=$((i + 1))
done
