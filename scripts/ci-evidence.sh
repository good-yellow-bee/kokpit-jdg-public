#!/bin/sh
set -eu
phase=${DISPATCH_PHASE:-${SOURCE_BRANCH%%-*}}
case "$phase" in f0a|f0b|f1|f2|f3|f4|f5|f6|f7) ;; *) echo 'Niepoprawna faza gałęzi lub wejścia.' >&2; exit 2;; esac
image_digest=$(docker image inspect "${APP_IMAGE:-kokpit-app:local}" --format '{{.Id}}')
result=0
docker compose exec -T -e KOKPIT_IMAGE_DIGEST="$image_digest" -e GITHUB_RUN_ID -e GITHUB_SERVER_URL -e GITHUB_REPOSITORY -e CHAT_LIVE_RUN_BUDGET_USD app bin/console kokpit:evidence "$phase" --only="${EVIDENCE_RUNNER:-ci}" || result=$?
# Runtime jest wolumenem, więc artefakty wymagają jawnego skopiowania do runnera.
# Docker zakłada katalog var/ jako punkt montowania z właścicielem root.
[ -w var ] || sudo chown "$(id -u):$(id -g)" var
mkdir -p var/evidence
docker compose cp app:/app/var/evidence/. var/evidence/
exit "$result"
