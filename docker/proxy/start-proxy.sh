#!/bin/sh
set -eu
case "${KOKPIT_DEPLOYMENT:-}" in
  dev|restore) cp /kokpit/local.conf /etc/nginx/conf.d/default.conf ;;
  prod)
    address=$(ip -4 -o addr show tailscale0 | awk '{split($4,a,"/");print a[1]}')
    [ -n "$address" ] || { echo 'Brak interfejsu VPN.' >&2; exit 2; }
    [ -s /run/kokpit/certificates/panel.crt ] && [ -s /run/kokpit/certificates/panel.key ] || { echo 'Brak certyfikatu panelu.' >&2; exit 2; }
    sed "s/VPN_ADDRESS/$address/" /kokpit/prod.conf > /etc/nginx/conf.d/default.conf
    ;;
  *) echo 'Niepoprawne wdrożenie.' >&2; exit 2 ;;
esac
exec nginx -g 'daemon off;'
