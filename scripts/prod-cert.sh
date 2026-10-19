#!/usr/bin/env bash
# Certyfikat panelu z `tailscale cert` (docs/security.md, „TLS i nazwa”, D-98). Wystawia go scripts/prod-install.sh,
# odnawia cron hosta raz na dobę: tailscale zwraca zapisany certyfikat i prosi Let's Encrypt o nowy dopiero przed końcem
# ważności. Gdy plik się zmienił, proxy przeładowuje nginx. Nazwa panelu pochodzi z PANEL_ORIGIN w pliku wdrożenia.
set -euo pipefail

env_file=${KOKPIT_ENV_FILE:-/etc/kokpit/prod.env}
[ -f "$env_file" ] || { echo "Brak pliku wdrożenia $env_file." >&2; exit 2; }
set -a
# shellcheck source=/dev/null
. "$env_file"
set +a
[ "${KOKPIT_DEPLOYMENT:-}" = prod ] || { echo 'Certyfikat panelu tylko w kokpit-prod.' >&2; exit 2; }
host=${PANEL_ORIGIN:?Brak PANEL_ORIGIN w pliku wdrożenia}
host=${host#https://}
host=${host%%/*}
[[ "$host" =~ ^[a-z0-9.-]+\.ts\.net$ ]] || { echo "PANEL_ORIGIN nie wskazuje nazwy w Tailscale: $host." >&2; exit 2; }

cd "$(dirname "$0")/.."
unset COMPOSE_FILE COMPOSE_PROJECT_NAME
export COMPOSE_PROFILES=prod
compose() { docker compose -f compose.yaml "$@"; }
certificate=/run/kokpit/certificates/panel.crt
key=/run/kokpit/certificates/panel.key

before=$(compose exec -T vpn sh -c "sha256sum $certificate 2>/dev/null || true")
compose exec -T vpn tailscale cert --cert-file "$certificate" --key-file "$key" "$host" > /dev/null
# Proxy (nginx jako root) czyta klucz; aplikacja (www-data) czyta tylko certyfikat dla /health.
compose exec -T vpn sh -ec "chmod 0644 $certificate; chmod 0600 $key"
after=$(compose exec -T vpn sha256sum "$certificate")
if [ "$before" != "$after" ] && [ -n "$(compose ps -q --status running proxy)" ]; then
  compose exec -T proxy nginx -s reload
  echo "Nowy certyfikat panelu dla $host; proxy przeładowane."
else
  echo "Certyfikat panelu dla $host bez zmian."
fi
