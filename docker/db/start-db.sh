#!/bin/bash
set -euo pipefail
# Hasła generowane w dev i w jednorazowym kokpit-restore (D-89); na PROD pliki hosta.
if [ "${KOKPIT_DEPLOYMENT}" = dev ] || [ "${KOKPIT_DEPLOYMENT}" = restore ]; then
  for target in /run/owner/postgres-password /run/owner/owner-password /run/app/app-password /run/app/chat-ro-password; do
    if [ ! -s "$target" ]; then
      umask 077
      openssl rand -hex 32 > "$target"
    fi
  done
fi
chown postgres:33 /run/app/app-password
chmod 0440 /run/app/app-password
# Hasło chat_ro (F6.1): w dev generowane wyżej; na PROD plik hosta dochodzi przy go-live czatu.
if [ -e /run/app/chat-ro-password ]; then
  chown postgres:33 /run/app/chat-ro-password
  chmod 0440 /run/app/chat-ro-password
fi
chown postgres:postgres /run/owner/*
chmod 0400 /run/owner/*
exec /usr/local/bin/docker-entrypoint.sh postgres
