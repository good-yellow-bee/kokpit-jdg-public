.DEFAULT_GOAL := help
.PHONY: help deploy rollback verify-images update-check

# Kanał aktualizacji (D-93): CI publikuje obrazy każdego commita `main` w rejestrze i podpisuje je bez klucza (Sigstore, OIDC GitHub Actions).
KOKPIT_REGISTRY ?= ghcr.io/good-yellow-bee/kokpit-jdg-public
KOKPIT_SIGNER ?= https://github.com/good-yellow-bee/kokpit-jdg-public/.github/workflows/ci.yml@refs/heads/main
# verify_image <app|proxy>: obraz commita KOKPIT_COMMIT z rejestru, z podpisem przebiegu push tego commita; wypisuje odwołanie przypięte do digestu.
VERIFY_IMAGE = verify_image() { \
	ref=; \
	for candidate in $$(docker image inspect "$(KOKPIT_REGISTRY)/$$1:$$KOKPIT_COMMIT" --format '{{join .RepoDigests " "}}'); do \
		case "$$candidate" in "$(KOKPIT_REGISTRY)/$$1@sha256:"*) ref=$$candidate;; esac; \
	done; \
	[ -n "$$ref" ] || { echo "Obraz $$1 commita $$KOKPIT_COMMIT nie pochodzi z $(KOKPIT_REGISTRY)." >&2; return 1; }; \
	cosign verify --certificate-identity '$(KOKPIT_SIGNER)' --certificate-oidc-issuer https://token.actions.githubusercontent.com --certificate-github-workflow-sha "$$KOKPIT_COMMIT" --certificate-github-workflow-trigger push "$$ref" > /dev/null \
		|| { echo "Podpis obrazu $$1 commita $$KOKPIT_COMMIT nie przeszedł weryfikacji." >&2; return 1; }; \
	printf '%s\n' "$$ref"; \
}
# Plik wdrożenia na VPS (D-98): adres panelu, hosty powiadomień, katalog plików hosta, cele i CONNECTORS_ENABLED.
# Zapisuje go scripts/prod-install.sh; wartości z pliku mają pierwszeństwo przed zmiennymi powłoki.
KOKPIT_ENV_FILE ?= /etc/kokpit/prod.env
LOAD_ENV_FILE = if [ -f '$(KOKPIT_ENV_FILE)' ]; then set -a; . '$(KOKPIT_ENV_FILE)'; set +a; fi
# Sprawdzenie /health po starcie: pierwsze wdrożenie czeka na pierwszy przebieg harmonogramu (co 5 min).
HEALTH_ATTEMPTS ?= 28
HEALTH_INTERVAL ?= 15
REQUIRE_COSIGN = command -v cosign > /dev/null || { echo 'Brak cosign: podpisu obrazów nie da się sprawdzić (docs/operations.md).' >&2; exit 2; }
PULL_IMAGES = for image in app proxy; do \
		docker pull --quiet "$(KOKPIT_REGISTRY)/$$image:$$KOKPIT_COMMIT" \
			|| { echo "Obrazu $$image commita $$KOKPIT_COMMIT nie da się pobrać: CI jeszcze go nie opublikował, przebieg jest czerwony albo host nie jest zalogowany do rejestru." >&2; exit 1; }; \
	done

help:
	@printf '%s\n' 'deploy: wdrożenie kokpit-prod z podpisanych obrazów commita main' 'rollback: obrazy commita KOKPIT_COMMIT z poprzedniego ops.deployed w kokpit-prod' 'verify-images: podpis lokalnych obrazów commita KOKPIT_COMMIT' 'update-check: nowe commity kanału main i ich podpisane obrazy'

deploy:
	@set -eu; \
	$(LOAD_ENV_FILE); \
	[ "$${KOKPIT_DEPLOYMENT:-}" = prod ] || { echo 'Wdrożenie działa tylko w kokpit-prod.' >&2; exit 2; }; \
	: "$${PANEL_ORIGIN:?Ustaw PANEL_ORIGIN panelu w VPN}"; \
	$(REQUIRE_COSIGN); \
	export APP_ENV=prod APP_DEBUG=0 COMPOSE_PROFILES=prod; \
	unset COMPOSE_PROJECT_NAME; \
	git pull --ff-only; \
	KOKPIT_COMMIT=$$(git rev-parse HEAD); \
	export KOKPIT_COMMIT; \
	$(PULL_IMAGES); \
	$(VERIFY_IMAGE); \
	APP_IMAGE=$$(verify_image app); \
	PROXY_IMAGE=$$(verify_image proxy); \
	KOKPIT_IMAGE_DIGEST=$${APP_IMAGE#*@}; \
	export APP_IMAGE PROXY_IMAGE KOKPIT_IMAGE_DIGEST; \
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
	docker compose -f compose.yaml exec -T backup sh -c '[ -e /state/last_success ] || exec run-backup'; \
	panel_host=$${PANEL_ORIGIN#https://}; \
	panel_host=$${panel_host%%/*}; \
	vpn_address=$$(docker compose -f compose.yaml exec -T vpn tailscale ip -4); \
	attempt=0; \
	while :; do \
		attempt=$$((attempt + 1)); \
		status=$$(docker compose -f compose.yaml exec -T app curl --silent --output /dev/null --write-out '%{http_code}' --connect-timeout 10 --max-time 30 --resolve "$$panel_host:443:$$vpn_address" "$${PANEL_ORIGIN%/}/health") || status=000; \
		[ "$$status" != 200 ] || break; \
		[ "$$attempt" -lt $(HEALTH_ATTEMPTS) ] || { echo "Sprawdzenie /health zwróciło HTTP $$status." >&2; exit 1; }; \
		sleep $(HEALTH_INTERVAL); \
	done; \
	docker compose -f compose.yaml run --rm app bin/console app:ops:record-deploy

rollback:
	@set -eu; \
	$(LOAD_ENV_FILE); \
	[ "$${KOKPIT_DEPLOYMENT:-}" = prod ] || { echo 'Wycofanie działa tylko w kokpit-prod.' >&2; exit 2; }; \
	: "$${KOKPIT_COMMIT:?Ustaw KOKPIT_COMMIT z poprzedniego wpisu ops.deployed}"; \
	$(REQUIRE_COSIGN); \
	export APP_ENV=prod APP_DEBUG=0 COMPOSE_PROFILES=prod KOKPIT_COMMIT; \
	unset COMPOSE_PROJECT_NAME; \
	$(VERIFY_IMAGE); \
	APP_IMAGE=$$(verify_image app); \
	PROXY_IMAGE=$$(verify_image proxy); \
	KOKPIT_IMAGE_DIGEST=$${APP_IMAGE#*@}; \
	export APP_IMAGE PROXY_IMAGE KOKPIT_IMAGE_DIGEST; \
	docker compose -f compose.yaml run --rm app bin/console cache:clear; \
	docker compose -f compose.yaml up -d --no-build --pull never

verify-images:
	@set -eu; \
	: "$${KOKPIT_COMMIT:?Ustaw KOKPIT_COMMIT}"; \
	$(REQUIRE_COSIGN); \
	$(VERIFY_IMAGE); \
	app=$$(verify_image app); \
	proxy=$$(verify_image proxy); \
	printf 'APP_IMAGE=%s\nPROXY_IMAGE=%s\n' "$$app" "$$proxy"

update-check:
	@set -eu; \
	$(REQUIRE_COSIGN); \
	git fetch --quiet origin main; \
	current=$$(git rev-parse HEAD); \
	KOKPIT_COMMIT=$$(git rev-parse origin/main); \
	[ "$$current" != "$$KOKPIT_COMMIT" ] || { printf 'Bez aktualizacji: %s to najnowszy commit kanału main.\n' "$$current"; exit 0; }; \
	git log --oneline "$$current..$$KOKPIT_COMMIT"; \
	$(PULL_IMAGES); \
	$(VERIFY_IMAGE); \
	verify_image app > /dev/null; \
	verify_image proxy > /dev/null; \
	printf 'Commit %s ma podpisane obrazy; wdrożysz go przez make deploy.\n' "$$KOKPIT_COMMIT"
