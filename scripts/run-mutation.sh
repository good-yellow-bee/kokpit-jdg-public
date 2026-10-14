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
if [ -d src/Tax ] && find src/Tax -name '*.php' -print -quit | grep -q .; then
  vendor/bin/infection --configuration=infection.json5 --min-msi=85 --min-covered-msi=95
else
  vendor/bin/infection --configuration=infection.json5 --min-msi=0 --min-covered-msi=0
fi
