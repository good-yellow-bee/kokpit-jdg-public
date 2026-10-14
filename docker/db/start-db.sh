#!/bin/bash
set -euo pipefail
if [ "${KOKPIT_DEPLOYMENT}" = dev ]; then
  for target in /run/owner/postgres-password /run/owner/owner-password /run/app/app-password; do
    if [ ! -s "$target" ]; then
      umask 077
      openssl rand -hex 32 > "$target"
    fi
  done
fi
chown postgres:33 /run/app/app-password
chmod 0440 /run/app/app-password
chown postgres:postgres /run/owner/*
chmod 0400 /run/owner/*
exec /usr/local/bin/docker-entrypoint.sh postgres
