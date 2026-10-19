#!/bin/sh
set -eu
# Certyfikat klucza publicznego MF celu `test` dla testów JPK live (D-69, D-97). Plik jest publiczny, ale zasady
# wykluczają certyfikaty z commitów, więc job `live` bierze go ze zmiennej repozytorium MF_TEST_CERT_PEM.
[ -n "${MF_TEST_CERT_PEM:-}" ] || { echo 'Brak zmiennej repozytorium MF_TEST_CERT_PEM z certyfikatem testowym MF (docs/testing.md, job live).' >&2; exit 2; }
file=$(mktemp)
trap 'rm -f "$file"' EXIT HUP INT TERM
printf '%s\n' "$MF_TEST_CERT_PEM" | tr -d '\r' > "$file"
openssl x509 -in "$file" -noout -subject -enddate -fingerprint -sha256 || { echo 'MF_TEST_CERT_PEM nie jest certyfikatem PEM.' >&2; exit 2; }
openssl x509 -in "$file" -noout -checkend 0 > /dev/null || { echo 'Certyfikat testowy MF z MF_TEST_CERT_PEM wygasł.' >&2; exit 2; }
docker compose exec -T app mkdir -p var/jpk
docker compose exec -T app sh -c 'cat > var/jpk/mf-test.crt' < "$file"
