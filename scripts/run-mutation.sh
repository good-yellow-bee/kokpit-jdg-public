#!/bin/sh
set -eu
has_code=false
for directory in src/Tax src/Ledger src/Calendar src/Approval; do
  if [ -d "$directory" ] && find "$directory" -name '*.php' -print -quit | grep -q .; then
    has_code=true
  fi
done
if [ "$has_code" = false ]; then
  echo 'brak kodu w zakresie'
  exit 0
fi
# Wątek N używa bazy `kokpit_test_N` z `scripts/mutation-databases.sh` i katalogu `var/test-files-N`;
# ubity mutant mógł zostawić zmieniony plik, więc katalogi wątków powstają od nowa (D-104).
rm -rf var/test-files-*
# Główny proces Infection trzyma pokrycie każdego testu; limit 512M z `kokpit.ini` kończył go kodem 255.
set -- -d memory_limit=2G vendor/bin/infection --configuration=infection.json5 --threads="${MUTATION_THREADS:-1}" --only-covering-test-cases --no-progress
if [ -d src/Tax ] && find src/Tax -name '*.php' -print -quit | grep -q .; then
  php "$@" --min-msi=85 --min-covered-msi=95
else
  php "$@" --min-msi=0 --min-covered-msi=0
fi
