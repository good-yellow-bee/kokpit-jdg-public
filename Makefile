.DEFAULT_GOAL := help
.PHONY: help deploy rollback

help:
	@printf '%s\n' 'deploy: wdrożenie kokpit-prod' 'rollback: poprzednie APP_IMAGE i PROXY_IMAGE w kokpit-prod'

deploy:
	@set -eu; \
	[ "$${KOKPIT_DEPLOYMENT:-}" = prod ] || { echo 'Wdrożenie działa tylko w kokpit-prod.' >&2; exit 2; }; \
	: "$${PANEL_ORIGIN:?Ustaw PANEL_ORIGIN panelu w VPN}"; \
	export APP_ENV=prod APP_DEBUG=0 COMPOSE_PROFILES=prod; \
	unset COMPOSE_PROJECT_NAME; \
	git pull --ff-only; \
	KOKPIT_COMMIT=$$(git rev-parse HEAD); \
	APP_IMAGE="kokpit-app:$$KOKPIT_COMMIT"; \
	PROXY_IMAGE="kokpit-proxy:$$KOKPIT_COMMIT"; \
	export KOKPIT_COMMIT APP_IMAGE PROXY_IMAGE; \
	docker build --target production -t "$$APP_IMAGE" .; \
	docker build --target proxy -t "$$PROXY_IMAGE" .; \
	KOKPIT_IMAGE_DIGEST=$$(docker image inspect "$$APP_IMAGE" --format '{{.Id}}'); \
	export KOKPIT_IMAGE_DIGEST; \
	docker compose -f compose.yaml stop scheduler worker_official_writes worker_reads worker_notifications; \
	docker compose -f compose.yaml run --rm app bin/console app:approval idle; \
	umask 077; \
	mkdir -p backups; \
	dump=$$(mktemp "backups/deploy-$$KOKPIT_COMMIT-XXXXXX"); \
	mv "$$dump" "$$dump.partial"; \
	docker compose -f compose.yaml exec -T --user postgres db sh -ec 'PGPASSWORD="$$(cat /run/owner/backup-password)" exec pg_dump -h localhost -U kokpit_backup -d kokpit -Fc' > "$$dump.partial"; \
	mv "$$dump.partial" "$$dump"; \
	printf 'Zrzut przed migracją: %s\n' "$$dump"; \
	docker compose -f compose.yaml run --rm --user root -v kokpit-prod_owner_credentials:/run/migration:ro -e DB_ROLE=kokpit_owner app sh -ec 'credential=$$(mktemp); install -o 33 -g 33 -m 0400 /run/migration/owner-password "$$credential"; export DB_OWNER_PASSWORD_FILE="$$credential"; exec su -s /bin/sh www-data -c "bin/console doctrine:migrations:migrate --no-interaction"'; \
	docker compose -f compose.yaml run --rm app bin/console cache:clear; \
	docker compose -f compose.yaml up -d; \
	status=$$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 10 --max-time 30 "$${PANEL_ORIGIN%/}/health"); \
	[ "$$status" = 200 ] || { echo "Sprawdzenie /health zwróciło HTTP $$status." >&2; exit 1; }; \
	docker compose -f compose.yaml run --rm app bin/console app:ops:record-deploy

rollback:
	@set -eu; \
	[ "$${KOKPIT_DEPLOYMENT:-}" = prod ] || { echo 'Wycofanie działa tylko w kokpit-prod.' >&2; exit 2; }; \
	: "$${APP_IMAGE:?Ustaw poprzedni APP_IMAGE}" "$${PROXY_IMAGE:?Ustaw poprzedni PROXY_IMAGE}"; \
	export APP_ENV=prod APP_DEBUG=0 COMPOSE_PROFILES=prod; \
	unset COMPOSE_PROJECT_NAME; \
	KOKPIT_COMMIT=$${APP_IMAGE##*:}; \
	KOKPIT_IMAGE_DIGEST=$$(docker image inspect "$$APP_IMAGE" --format '{{.Id}}'); \
	docker image inspect "$$PROXY_IMAGE" > /dev/null; \
	export KOKPIT_COMMIT KOKPIT_IMAGE_DIGEST APP_IMAGE PROXY_IMAGE; \
	docker compose -f compose.yaml run --rm app bin/console cache:clear; \
	docker compose -f compose.yaml up -d --no-build --pull never
