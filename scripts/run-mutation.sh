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
threads="${MUTATION_THREADS:-1}"
# Wątek N używa bazy `kokpit_test_N` z `scripts/mutation-databases.sh`; brak albo stara kopia dawałaby fałszywe zabicia (D-104).
i=1
while [ "$i" -le "$threads" ]; do
  APP_ENV=test DB_NAME="kokpit_test_$i" bin/console doctrine:migrations:up-to-date >/dev/null 2>&1 || { echo "Brak aktualnej bazy kokpit_test_$i: uruchom scripts/mutation-databases.sh $threads." >&2; exit 2; }
  i=$((i + 1))
done
# Katalog plików wątku `var/test-files-N` powstaje od nowa: mutant ubity w poprzednim przebiegu mógł zostawić zmieniony plik.
rm -rf var/test-files-*
# Główny proces Infection trzyma pokrycie każdego testu; limit 512M z `kokpit.ini` kończył go kodem 255.
php -d memory_limit=2G vendor/bin/infection --configuration=infection.json5 --threads="$threads" --only-covering-test-cases --no-progress --min-msi=85 --min-covered-msi=95
