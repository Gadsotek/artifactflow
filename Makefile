SHELL := /bin/bash

COMPOSE ?= docker compose
COMPOSE_ALL ?= $(COMPOSE) --profile frontend --profile edge --profile adminer --profile mail --profile realtime --profile test
DOCKER_BUILD ?= docker build
DOCKER_BUILD_CACHE_ARGS ?=
APP_SERVICE ?= app
ARTIFACT_GATEWAY_SERVICE ?= artifact-gateway
export APP_UID ?= $(shell id -u)
export APP_GID ?= $(shell id -g)
UP_BUILD ?= --build
WAIT_TIMEOUT ?= 180
WAIT_INTERVAL ?= 2
WAIT_COMPOSE_PROFILES ?=
TEST_FILTER ?=
TEST_DB_SERVICE ?= db-test
TEST_DB_HOST ?= db-test
TEST_DB_PORT ?= 5432
TEST_DB_DATABASE ?= artifactflow_test
TEST_DB_USERNAME ?= app_user
TEST_DB_PASSWORD ?= app_local_password
TEST_DB_SUPERUSER ?= postgres
TEST_DB_SUPERPASS ?= postgres_test_password
TEST_DB_RUN_ID ?= $(shell uuidgen | tr '[:upper:]' '[:lower:]' | tr -d '-')
TEST_DB_NAME ?= $(TEST_DB_DATABASE)_$(TEST_DB_RUN_ID)
E2E_APP_SERVICE ?= e2e-app
E2E_ARTIFACT_SERVICE ?= e2e-artifact-host
E2E_IMAGE_PARSER_SERVICE ?= e2e-image-parser
E2E_PDF_PROCESSOR_SERVICE ?= e2e-pdf-processor
E2E_ARTIFACT_GATEWAY_SERVICE ?= e2e-artifact-gateway
E2E_EDGE_SERVICE ?= e2e-edge
E2E_APP_PORT ?= 18180
E2E_ARTIFACT_HOST_PORT ?= 18181
E2E_APP_URL ?= http://localhost:$(E2E_APP_PORT)
E2E_ARTIFACT_URL ?= http://127.0.0.1:$(E2E_ARTIFACT_HOST_PORT)
E2E_DB_NAME ?= $(TEST_DB_DATABASE)_e2e_$(TEST_DB_RUN_ID)
E2E_LOCK_DIR ?= storage/framework/testing/e2e.lock
PRODUCTION_IMAGE ?= artifactflow-app:production
IMAGE_PARSER_IMAGE ?= artifactflow-image-parser:production
PDF_PROCESSOR_SPIKE_IMAGE ?= artifactflow-pdf-processor-spike:local
PDF_PROCESSOR_SERVICE_IMAGE ?= artifactflow-pdf-processor-service:local
TRIVY_IMAGE ?= aquasec/trivy:0.72.0@sha256:cffe3f5161a47a6823fbd23d985795b3ed72a4c806da4c4df16266c02accdd6f
TRIVY_CACHE_DIR ?= $(HOME)/.cache/trivy
TRIVY_REPO_SCAN_SKIP_DIRS ?= --skip-dirs /src/vendor --skip-dirs /src/node_modules --skip-dirs /src/public/build --skip-dirs /src/storage --skip-dirs /src/bootstrap/cache --skip-dirs /src/.git
SEMGREP ?= semgrep
TYPE_COVERAGE_MIN ?= 100
TYPE_COVERAGE_REPORT ?= storage/framework/testing/type-coverage.json
COVERAGE_MIN ?= 94

.PHONY: ensure-env ensure-artifact-signing-key compose-config up up-local down down-reset wait shell logs deps run-app-cmd run-e2e-app-cmd fe-deps fe-up fe-down fe-logs edge-up edge-down edge-logs adminer-up adminer-down mail-up mail-down key-generate artifact-signing-key-generate migrate reindex-search backup restore backup-verify ecs ecs-fix stan semgrep publish-guard test-env-up test-env-down test-db-prepare test-db-create test-db-drop test-db-reset test fuzz-capabilities type-coverage coverage audit audit-php audit-js ai-hooks-test verify-reverb-origin verify-runtime-network-isolation reverb-up reverb-down reverb-logs e2e e2e-install build-assets build-prod assert-prod-storage-empty pdf-processor-spike-build pdf-processor-spike-test pdf-processor-service-build pdf-processor-service-test scan-image quality quality-full config-refresh lint-js doctor install

ensure-env:
	@test -f .env || cp .env.example .env
	@mkdir -p vendor node_modules

ensure-artifact-signing-key: ensure-env
	@if command -v php >/dev/null 2>&1 && php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then \
		php scripts/ensure-artifact-signing-key.php; \
	else \
		sh scripts/ensure-artifact-signing-key.sh; \
	fi

compose-config:
	$(MAKE) ensure-env
	$(COMPOSE) config >/dev/null

up:
	$(MAKE) ensure-env
	$(MAKE) ensure-artifact-signing-key
	$(COMPOSE) up -d $(UP_BUILD) db
	$(MAKE) deps
	$(COMPOSE) --profile realtime up -d $(UP_BUILD) app artifact-host artifact-gateway worker scheduler reverb
	$(MAKE) wait APP_SERVICE=app
	$(MAKE) wait APP_SERVICE=artifact-host
	$(MAKE) wait APP_SERVICE=$(ARTIFACT_GATEWAY_SERVICE)
	$(MAKE) wait APP_SERVICE=reverb WAIT_COMPOSE_PROFILES='--profile realtime'
	$(COMPOSE) --profile frontend up -d vite

up-local: up edge-up adminer-up mail-up

reverb-up:
	$(COMPOSE) --profile realtime up -d $(UP_BUILD) reverb
	$(MAKE) wait APP_SERVICE=reverb WAIT_COMPOSE_PROFILES='--profile realtime'

reverb-down:
	$(COMPOSE) --profile realtime stop reverb

reverb-logs:
	$(COMPOSE) --profile realtime logs -f --tail=100 reverb

down:
	$(COMPOSE_ALL) down --remove-orphans

down-reset:
	$(COMPOSE_ALL) down --remove-orphans --volumes

wait:
	@echo "Waiting for $(APP_SERVICE) healthcheck..."
	@set -euo pipefail; \
		timeout=$(WAIT_TIMEOUT); \
		interval=$(WAIT_INTERVAL); \
		elapsed=0; \
		last_status=""; \
		while true; do \
			cid="$$( $(COMPOSE) $(WAIT_COMPOSE_PROFILES) ps -a -q $(APP_SERVICE) )"; \
			if [ -n "$$cid" ]; then \
				status="$$(docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$$cid" 2>/dev/null || true)"; \
				if [ "$$status" != "$$last_status" ] && [ -n "$$status" ]; then \
					echo "$(APP_SERVICE) status: $$status"; \
					last_status="$$status"; \
				fi; \
				if [ "$$status" = "healthy" ] || [ "$$status" = "running" ]; then \
					break; \
				fi; \
				if [ "$$status" = "unhealthy" ] || [ "$$status" = "exited" ] || [ "$$status" = "dead" ]; then \
					echo "$(APP_SERVICE) container stopped with status $$status"; \
					$(COMPOSE) $(WAIT_COMPOSE_PROFILES) ps; \
					$(COMPOSE) $(WAIT_COMPOSE_PROFILES) logs --tail=100 $(APP_SERVICE) || true; \
					exit 1; \
				fi; \
			fi; \
			if [ "$$elapsed" -ge "$$timeout" ]; then \
				echo "Timed out waiting for $(APP_SERVICE) after $$timeout seconds"; \
				$(COMPOSE) $(WAIT_COMPOSE_PROFILES) ps; \
				$(COMPOSE) $(WAIT_COMPOSE_PROFILES) logs --tail=100 $(APP_SERVICE) || true; \
				exit 1; \
			fi; \
			sleep "$$interval"; \
			elapsed=$$((elapsed + interval)); \
		done

shell:
	$(COMPOSE) exec $(APP_SERVICE) sh

logs:
	$(COMPOSE) logs -f --tail=100 $(APP_SERVICE)

deps:
	$(MAKE) ensure-env
	@set -euo pipefail; \
		cid="$$( $(COMPOSE) ps -q $(APP_SERVICE) )"; \
		if [ -n "$$cid" ] && [ "$$(docker inspect --format='{{.State.Running}}' "$$cid" 2>/dev/null || true)" = "true" ]; then \
			$(COMPOSE) exec $(APP_SERVICE) sh -lc '/var/www/html/docker/ensure-vendor.sh'; \
		else \
			echo "$(APP_SERVICE) is not running, using one-off container for dependency bootstrap..."; \
			$(COMPOSE) run --rm --no-deps $(APP_SERVICE) sh -lc '/var/www/html/docker/ensure-vendor.sh'; \
		fi

run-app-cmd:
	@set -euo pipefail; \
		cmd='$(APP_CMD)'; \
		cid="$$( $(COMPOSE) ps -q $(APP_SERVICE) )"; \
		if [ -n "$$cid" ] && [ "$$(docker inspect --format='{{.State.Running}}' "$$cid" 2>/dev/null || true)" = "true" ]; then \
			$(COMPOSE) exec -T $(APP_SERVICE) sh -lc "$$cmd"; \
		else \
			echo "$(APP_SERVICE) is not running, using one-off container for command: $$cmd"; \
			$(COMPOSE) run --rm --no-deps $(APP_SERVICE) sh -lc "$$cmd"; \
		fi

run-e2e-app-cmd:
	@set -euo pipefail; \
		cmd='$(APP_CMD)'; \
		$(COMPOSE) --profile test --profile e2e --env-file docker/e2e.env exec -T $(E2E_APP_SERVICE) sh -lc "$$cmd"

fe-deps:
	$(COMPOSE) --profile frontend run --rm --no-deps vite sh -lc '/var/www/html/docker/ensure-node-modules.sh'

fe-down:
	$(COMPOSE) --profile frontend stop vite

fe-logs:
	$(COMPOSE) --profile frontend logs -f --tail=100 vite

edge-up:
	$(COMPOSE) --profile edge up -d edge

edge-down:
	$(COMPOSE) --profile edge stop edge

edge-logs:
	$(COMPOSE) --profile edge logs -f --tail=100 edge

adminer-up:
	$(COMPOSE) --profile adminer up -d adminer

adminer-down:
	$(COMPOSE) --profile adminer stop adminer

mail-up:
	$(COMPOSE) --profile mail up -d mailpit

mail-down:
	$(COMPOSE) --profile mail stop mailpit

key-generate:
	$(MAKE) deps
	$(MAKE) run-app-cmd APP_CMD='php artisan key:generate'

artifact-signing-key-generate:
	@printf 'ARTIFACT_URL_SIGNING_KEY=base64:%s\n' "$$(openssl rand -base64 32)"
	@printf 'After updating .env or your secret manager, run: make config-refresh\n'

migrate:
	$(MAKE) deps
	$(MAKE) run-app-cmd APP_CMD='php artisan migrate --force'

REINDEX_ARGS ?=
reindex-search:
	$(MAKE) deps
	$(MAKE) run-app-cmd APP_CMD='php artisan artifactflow:reindex-search $(REINDEX_ARGS)'

BACKUP_ARGS ?=
backup:
	$(MAKE) ensure-env
	bash scripts/backup.sh $(BACKUP_ARGS)

RESTORE_ARGS ?=
restore:
	$(MAKE) ensure-env
	bash scripts/restore.sh $(RESTORE_ARGS)

backup-verify:
	$(MAKE) deps
	$(MAKE) run-app-cmd APP_CMD='php artisan artifactflow:verify-artifacts --sample=25'

ecs:
	$(MAKE) deps
	$(MAKE) run-app-cmd APP_CMD='./vendor/bin/ecs check'

ecs-fix:
	$(MAKE) deps
	$(MAKE) run-app-cmd APP_CMD='./vendor/bin/ecs check --fix'

stan:
	$(MAKE) deps
	$(MAKE) run-app-cmd APP_CMD='mkdir -p storage/phpstan/cache/nette.configurator && ./vendor/bin/phpstan analyse --level=max --memory-limit=1G'

semgrep:
	$(SEMGREP) scan --config .semgrep/artifactflow.yml --config p/php --config p/security-audit --error --metrics=off

publish-guard:
	bash scripts/publish-guard.sh

audit-php:
	$(MAKE) deps
	$(MAKE) run-app-cmd APP_CMD='if [ -f composer.lock ]; then composer audit --locked; else composer audit; fi'

audit-js:
	$(COMPOSE) --profile frontend run --rm --no-deps vite sh -lc '/var/www/html/docker/ensure-node-modules.sh && node scripts/verify-dompurify.mjs && npm audit --audit-level=moderate'

audit: audit-php audit-js

ai-hooks-test:
	@command -v python3 >/dev/null 2>&1 || { echo 'ArtifactFlow AI safety hook runner requires python3; refusing to run the gate.' >&2; exit 2; }
	python3 scripts/ai-hooks/run_harness.py

verify-reverb-origin:
	$(MAKE) ensure-env
	@set -euo pipefail; \
		port="$${REVERB_ORIGIN_PROBE_PORT:-18082}"; \
		probe_container="artifactflow-reverb-origin-probe-$$(openssl rand -hex 6)"; \
		export APP_ENV=production; \
		export APP_DEBUG=false; \
		smoke_reverb_key="$$(openssl rand -hex 24)"; \
		export APP_KEY="base64:$$(openssl rand -base64 32)"; \
		export APP_RUNTIME_ROLE=worker; \
		export APP_URL=https://app.example.test; \
		export ARTIFACT_URL=https://artifacts.example.test; \
		export ARTIFACT_FRAME_ANCESTORS=https://app.example.test; \
		export ARTIFACT_URL_SIGNING_KEY="base64:$$(openssl rand -base64 32)"; \
		export DB_SSLMODE=verify-full; \
		export DB_SSLROOTCERT=/etc/ssl/certs/ca-certificates.crt; \
		export SESSION_DRIVER=database; \
		export SESSION_DOMAIN=app.example.test; \
		export SESSION_SECURE_COOKIE=true; \
		export SESSION_ENCRYPT=true; \
		export SESSION_HTTP_ONLY=true; \
		export SESSION_SAME_SITE=lax; \
		export TRUSTED_PROXIES=REMOTE_ADDR; \
		export MAIL_MAILER=smtp; \
		export REVERB_CACHE_STORE=array; \
		export CACHE_LIMITER=database_limiter; \
		export ARTIFACT_CACHE_LIMITER=database_artifact_limiter; \
		export REVERB_SMOKE_DB_PASSWORD="$$(openssl rand -hex 32)"; \
		export BROADCAST_CONNECTION=reverb; \
		export REVERB_APP_ID=artifactflow-smoke-test; \
		export REVERB_APP_KEY="$$smoke_reverb_key"; \
		export REVERB_APP_SECRET="$$(openssl rand -hex 32)"; \
		export REVERB_PUBLIC_URL=https://app.example.test; \
		export REVERB_ALLOWED_ORIGINS=https://app.example.test; \
		export REVERB_APP_MAX_CONNECTIONS=1000; \
		export REVERB_APP_RATE_LIMITING_ENABLED=true; \
		export REVERB_PORT="$$port"; \
		cleanup() { docker rm -f "$$probe_container" >/dev/null 2>&1 || true; }; \
		trap cleanup EXIT; \
		$(COMPOSE) build app; \
		$(COMPOSE) --profile realtime run -d --service-ports --name "$$probe_container" --no-deps reverb >/dev/null; \
		if ! REVERB_PROBE_HOST=127.0.0.1 \
			REVERB_PROBE_PORT="$$port" \
			REVERB_APP_KEY="$$smoke_reverb_key" \
			REVERB_ALLOWED_ORIGIN=https://app.example.test \
			REVERB_REJECTED_ORIGIN=https://evil.example.test \
				node scripts/verify-reverb-origin-handshake.mjs; then \
			docker logs --tail=100 "$$probe_container" || true; \
			exit 1; \
		fi

test-env-up:
	$(COMPOSE) --profile test up -d $(TEST_DB_SERVICE)
	@echo "Waiting for $(TEST_DB_SERVICE) healthcheck..."
	@set -euo pipefail; \
		timeout=$(WAIT_TIMEOUT); \
		interval=$(WAIT_INTERVAL); \
		elapsed=0; \
		last_status=""; \
		while true; do \
			cid="$$( $(COMPOSE) --profile test ps -a -q $(TEST_DB_SERVICE) )"; \
			if [ -n "$$cid" ]; then \
				status="$$(docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$$cid" 2>/dev/null || true)"; \
				if [ "$$status" != "$$last_status" ] && [ -n "$$status" ]; then \
					echo "$(TEST_DB_SERVICE) status: $$status"; \
					last_status="$$status"; \
				fi; \
				if [ "$$status" = "healthy" ] || [ "$$status" = "running" ]; then \
					break; \
				fi; \
				if [ "$$status" = "unhealthy" ] || [ "$$status" = "exited" ] || [ "$$status" = "dead" ]; then \
					echo "$(TEST_DB_SERVICE) container stopped with status $$status"; \
					$(COMPOSE) --profile test ps; \
					$(COMPOSE) --profile test logs --tail=100 $(TEST_DB_SERVICE) || true; \
					exit 1; \
				fi; \
			fi; \
			if [ "$$elapsed" -ge "$$timeout" ]; then \
				echo "Timed out waiting for $(TEST_DB_SERVICE) after $$timeout seconds"; \
				$(COMPOSE) --profile test ps; \
				$(COMPOSE) --profile test logs --tail=100 $(TEST_DB_SERVICE) || true; \
				exit 1; \
			fi; \
			sleep "$$interval"; \
			elapsed=$$((elapsed + interval)); \
		done

test-env-down:
	-$(COMPOSE) --profile test stop $(TEST_DB_SERVICE)

test-db-prepare:
	$(COMPOSE) --profile test exec -T $(TEST_DB_SERVICE) sh -lc 'PGPASSWORD="$(TEST_DB_SUPERPASS)" psql -X -v ON_ERROR_STOP=1 -U "$(TEST_DB_SUPERUSER)" -d postgres -c "ALTER ROLE \"$(TEST_DB_USERNAME)\" CREATEDB;"'
	$(COMPOSE) --profile test exec -T $(TEST_DB_SERVICE) sh -lc 'PGPASSWORD="$(TEST_DB_SUPERPASS)" psql -X -U "$(TEST_DB_SUPERUSER)" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '\''$(TEST_DB_DATABASE)'\''" | grep -q 1 || PGPASSWORD="$(TEST_DB_SUPERPASS)" psql -X -v ON_ERROR_STOP=1 -U "$(TEST_DB_SUPERUSER)" -d postgres -c "CREATE DATABASE \"$(TEST_DB_DATABASE)\" OWNER \"$(TEST_DB_USERNAME)\";"'

test-db-create: test-db-prepare
	$(COMPOSE) --profile test exec -T $(TEST_DB_SERVICE) sh -lc 'PGPASSWORD="$(TEST_DB_SUPERPASS)" psql -X -v ON_ERROR_STOP=1 -U "$(TEST_DB_SUPERUSER)" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '\''$(TEST_DB_NAME)'\'' AND pid <> pg_backend_pid();"'
	$(COMPOSE) --profile test exec -T $(TEST_DB_SERVICE) sh -lc 'PGPASSWORD="$(TEST_DB_SUPERPASS)" dropdb -U "$(TEST_DB_SUPERUSER)" --if-exists "$(TEST_DB_NAME)"'
	$(COMPOSE) --profile test exec -T $(TEST_DB_SERVICE) sh -lc 'PGPASSWORD="$(TEST_DB_SUPERPASS)" createdb -U "$(TEST_DB_SUPERUSER)" -O "$(TEST_DB_USERNAME)" "$(TEST_DB_NAME)"'

test-db-drop:
	-$(COMPOSE) --profile test exec -T $(TEST_DB_SERVICE) sh -lc 'PGPASSWORD="$(TEST_DB_SUPERPASS)" psql -X -v ON_ERROR_STOP=1 -U "$(TEST_DB_SUPERUSER)" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '\''$(TEST_DB_NAME)'\'' AND pid <> pg_backend_pid();"'
	-$(COMPOSE) --profile test exec -T $(TEST_DB_SERVICE) sh -lc 'PGPASSWORD="$(TEST_DB_SUPERPASS)" dropdb -U "$(TEST_DB_SUPERUSER)" --if-exists "$(TEST_DB_NAME)"'

test-db-reset: TEST_DB_NAME=$(TEST_DB_DATABASE)
test-db-reset: test-db-create

test:
	$(MAKE) deps
	$(MAKE) test-env-up
	@set -euo pipefail; \
		db_name="$(TEST_DB_NAME)"; \
		echo "Using isolated test database $$db_name"; \
		$(MAKE) test-db-create TEST_DB_NAME="$$db_name"; \
		cleanup() { $(MAKE) test-db-drop TEST_DB_NAME="$$db_name"; }; \
		trap cleanup EXIT; \
		test_cmd='php artisan route:clear >/dev/null 2>&1; php artisan config:clear >/dev/null 2>&1; $(if $(COVERAGE),php -m | grep -qi "^pcov$$" || { echo "PCOV is required for line coverage." >&2; exit 2; }; ,)APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array DB_CONNECTION=pgsql DB_HOST=$(TEST_DB_HOST) DB_PORT=$(TEST_DB_PORT) DB_DATABASE='"$$db_name"' DB_USERNAME=$(TEST_DB_USERNAME) DB_PASSWORD=$(TEST_DB_PASSWORD) DB_SSLMODE=disable BROADCAST_CONNECTION=log XDEBUG_MODE=off php -d pcov.enabled=$(if $(COVERAGE),1,0) -d memory_limit=$(if $(COVERAGE),2G,512M) artisan test$(if $(COVERAGE), --coverage,)$(if $(TYPE_COVERAGE), --type-coverage --min=$(TYPE_COVERAGE_MIN),)$(if $(TYPE_COVERAGE_JSON), --type-coverage-json=$(TYPE_COVERAGE_JSON),)$(if $(COVERAGE),$(if $(COVERAGE_MIN), --min=$(COVERAGE_MIN),),)$(if $(TEST_FILTER), --filter=$(TEST_FILTER),)'; \
		cid="$$( $(COMPOSE) ps -q $(APP_SERVICE) )"; \
		if [ -n "$$cid" ] && [ "$$(docker inspect --format='{{.State.Running}}' "$$cid" 2>/dev/null || true)" = "true" ]; then \
			$(COMPOSE) exec -T $(APP_SERVICE) sh -lc "$$test_cmd"; \
		else \
			echo "$(APP_SERVICE) is not running, using one-off container for tests"; \
			$(COMPOSE) run --rm --no-deps $(APP_SERVICE) sh -lc "$$test_cmd"; \
		fi

fuzz-capabilities:
	$(MAKE) test TEST_FILTER=ArtifactDraftPreviewCapabilitiesFuzzTest

type-coverage:
	@mkdir -p "$(dir $(TYPE_COVERAGE_REPORT))"
	$(MAKE) test TYPE_COVERAGE=1 TYPE_COVERAGE_MIN=$(TYPE_COVERAGE_MIN) TYPE_COVERAGE_JSON=$(TYPE_COVERAGE_REPORT)
	$(MAKE) run-app-cmd APP_CMD='php scripts/type-coverage-guard.php "$(TYPE_COVERAGE_REPORT)" "$(TYPE_COVERAGE_MIN)"'

coverage:
	$(MAKE) test COVERAGE=1 COVERAGE_MIN=$(COVERAGE_MIN)

build-assets:
	$(MAKE) fe-deps
	$(COMPOSE) --profile frontend run --rm --no-deps vite sh -lc '/var/www/html/docker/ensure-node-modules.sh && npm run build'

e2e:
	@if [ ! -f public/build/manifest.json ]; then \
		  echo "public/build/manifest.json is missing. Run 'make build-assets' before 'make e2e'."; \
		  exit 1; \
	fi
	@if find resources/js resources/css -type f -newer public/build/manifest.json 2>/dev/null | grep -q .; then \
		  echo "Frontend sources are newer than public/build. Run 'make build-assets' before 'make e2e' so the browser tests exercise current assets."; \
		  exit 1; \
	fi
	$(MAKE) ensure-env
	$(MAKE) test-env-up
	@set -euo pipefail; \
		db_name="$(E2E_DB_NAME)"; \
		lock_dir="$(abspath $(E2E_LOCK_DIR))"; \
		mkdir -p "$$(dirname "$$lock_dir")"; \
		if ! mkdir "$$lock_dir" 2>/dev/null; then \
			echo "Another make e2e run is already using the shared e2e services and ports."; \
			echo "If no run is active, remove the stale lock with: rmdir $$lock_dir"; \
			exit 1; \
		fi; \
		cleanup() { \
			$(COMPOSE) --profile test --profile e2e --env-file docker/e2e.env stop $(E2E_EDGE_SERVICE) $(E2E_ARTIFACT_GATEWAY_SERVICE) $(E2E_APP_SERVICE) $(E2E_ARTIFACT_SERVICE) $(E2E_IMAGE_PARSER_SERVICE) $(E2E_PDF_PROCESSOR_SERVICE) >/dev/null 2>&1 || true; \
			$(MAKE) test-db-drop TEST_DB_NAME="$$db_name"; \
			rmdir "$$lock_dir" >/dev/null 2>&1 || true; \
		}; \
		trap cleanup EXIT; \
		echo "Using isolated e2e database $$db_name"; \
		$(MAKE) test-db-create TEST_DB_NAME="$$db_name"; \
		E2E_DB_DATABASE="$$db_name" \
		E2E_APP_PORT="$(E2E_APP_PORT)" \
		E2E_ARTIFACT_HOST_PORT="$(E2E_ARTIFACT_HOST_PORT)" \
		E2E_APP_URL="$(E2E_APP_URL)" \
		E2E_ARTIFACT_URL="$(E2E_ARTIFACT_URL)" \
		E2E_ARTIFACT_FRAME_ANCESTORS="$(E2E_APP_URL)" \
			$(COMPOSE) --profile test --profile e2e --env-file docker/e2e.env run --rm --no-deps $(E2E_APP_SERVICE) sh -lc '/var/www/html/docker/ensure-vendor.sh && php artisan migrate --force'; \
		E2E_DB_DATABASE="$$db_name" \
		E2E_APP_PORT="$(E2E_APP_PORT)" \
		E2E_ARTIFACT_HOST_PORT="$(E2E_ARTIFACT_HOST_PORT)" \
		E2E_APP_URL="$(E2E_APP_URL)" \
		E2E_ARTIFACT_URL="$(E2E_ARTIFACT_URL)" \
		E2E_ARTIFACT_FRAME_ANCESTORS="$(E2E_APP_URL)" \
			$(COMPOSE) --profile test --profile e2e --env-file docker/e2e.env up -d $(UP_BUILD) --force-recreate $(E2E_IMAGE_PARSER_SERVICE) $(E2E_PDF_PROCESSOR_SERVICE) $(E2E_APP_SERVICE) $(E2E_ARTIFACT_SERVICE) $(E2E_ARTIFACT_GATEWAY_SERVICE) $(E2E_EDGE_SERVICE); \
		$(MAKE) wait APP_SERVICE=$(E2E_IMAGE_PARSER_SERVICE) WAIT_COMPOSE_PROFILES='--profile test --profile e2e'; \
		$(MAKE) wait APP_SERVICE=$(E2E_PDF_PROCESSOR_SERVICE) WAIT_COMPOSE_PROFILES='--profile test --profile e2e'; \
		$(MAKE) wait APP_SERVICE=$(E2E_APP_SERVICE) WAIT_COMPOSE_PROFILES='--profile test --profile e2e'; \
		$(MAKE) wait APP_SERVICE=$(E2E_ARTIFACT_SERVICE) WAIT_COMPOSE_PROFILES='--profile test --profile e2e'; \
		$(MAKE) wait APP_SERVICE=$(E2E_ARTIFACT_GATEWAY_SERVICE) WAIT_COMPOSE_PROFILES='--profile test --profile e2e'; \
		$(MAKE) wait APP_SERVICE=$(E2E_EDGE_SERVICE) WAIT_COMPOSE_PROFILES='--profile test --profile e2e'; \
		$(MAKE) verify-runtime-network-isolation; \
		E2E_DB_DATABASE="$$db_name" \
		E2E_APP_PORT="$(E2E_APP_PORT)" \
		E2E_ARTIFACT_HOST_PORT="$(E2E_ARTIFACT_HOST_PORT)" \
		E2E_APP_URL="$(E2E_APP_URL)" \
		E2E_ARTIFACT_URL="$(E2E_ARTIFACT_URL)" \
		PLAYWRIGHT_BASE_URL="$(E2E_APP_URL)" \
		E2E_APP_COMMAND_TARGET=run-e2e-app-cmd \
			npx playwright test

verify-runtime-network-isolation:
	bash scripts/verify-runtime-network-isolation.sh

e2e-install:
	if [ -f package-lock.json ]; then npm ci; else npm install; fi
	npx playwright install --with-deps chromium firefox webkit

build-prod:
	$(DOCKER_BUILD) --pull --target production --tag $(PRODUCTION_IMAGE) $(DOCKER_BUILD_CACHE_ARGS) .
	$(DOCKER_BUILD) --pull --target image-parser --tag $(IMAGE_PARSER_IMAGE) $(DOCKER_BUILD_CACHE_ARGS) .
	$(DOCKER_BUILD) --pull -f pdf-processor-spike/Dockerfile --target pdf-processor-service \
		--tag $(PDF_PROCESSOR_SERVICE_IMAGE) $(DOCKER_BUILD_CACHE_ARGS) pdf-processor-spike
	$(MAKE) assert-prod-storage-empty

assert-prod-storage-empty:
	docker run --rm $(PRODUCTION_IMAGE) sh -lc 'if find /var/www/html/storage/app -type f -print -quit | grep -q .; then echo "Production image must not contain baked runtime storage files."; exit 1; fi'

pdf-processor-spike-build:
	$(DOCKER_BUILD) -f pdf-processor-spike/Dockerfile --target pdf-processor-spike \
		--tag $(PDF_PROCESSOR_SPIKE_IMAGE) $(DOCKER_BUILD_CACHE_ARGS) pdf-processor-spike

pdf-processor-spike-test: pdf-processor-spike-build
	docker run --rm --network none --read-only --cap-drop ALL \
		--security-opt no-new-privileges --pids-limit 32 --memory 512m --cpus 1 \
		--tmpfs /tmp:rw,noexec,nosuid,size=32m \
		$(PDF_PROCESSOR_SPIKE_IMAGE) self-test
	docker run --rm --network none --read-only --cap-drop ALL \
		--security-opt no-new-privileges --pids-limit 32 --memory 512m --cpus 1 \
		--tmpfs /tmp:rw,noexec,nosuid,size=32m --entrypoint /bin/sh \
		$(PDF_PROCESSOR_SPIKE_IMAGE) -ec 'for target in http://127.0.0.1:9 http://169.254.169.254 http://example.com; do if wget -q -T 2 -O /dev/null "$$target"; then echo "network probe unexpectedly succeeded: $$target" >&2; exit 1; fi; done; echo "pdf-processor-spike network probes denied"'
	bash pdf-processor-spike/timeout-harness.sh "$(PDF_PROCESSOR_SPIKE_IMAGE)"

pdf-processor-service-build:
	$(DOCKER_BUILD) -f pdf-processor-spike/Dockerfile --target pdf-processor-service \
		--tag $(PDF_PROCESSOR_SERVICE_IMAGE) $(DOCKER_BUILD_CACHE_ARGS) pdf-processor-spike

pdf-processor-service-test: pdf-processor-service-build
	@set -euo pipefail; \
		processor_container="artifactflow-pdf-processor-probe-$$(openssl rand -hex 6)"; \
		cleanup() { docker rm -f "$$processor_container" >/dev/null 2>&1 || true; }; \
		trap cleanup EXIT; \
		docker run -d --name "$$processor_container" --network none --read-only --cap-drop ALL \
			--security-opt no-new-privileges --pids-limit 32 --memory 512m --cpus 1 \
			--tmpfs /tmp:rw,noexec,nosuid,size=32m \
			--tmpfs /run/artifactflow/pdf-processor:rw,noexec,nosuid,size=1m,uid=10002,gid=10002,mode=0755 \
			--health-interval 1s --health-timeout 15s --health-start-period 1s --health-retries 15 \
			--env PDF_PROCESSOR_SHARED_SECRET=artifactflow-local-pdf-processor-secret-not-for-production \
			$(PDF_PROCESSOR_SERVICE_IMAGE) >/dev/null; \
		for attempt in $$(seq 1 45); do \
			status="$$(docker inspect --format='{{.State.Health.Status}}' "$$processor_container" 2>/dev/null || true)"; \
			if [ "$$status" = healthy ]; then \
				echo "PDF processor UDS health probe passed."; \
				break; \
			fi; \
			if [ "$$status" = unhealthy ] || [ "$$attempt" -eq 45 ]; then \
				docker logs "$$processor_container" 2>&1 || true; \
				echo "PDF processor did not become healthy (status: $$status)." >&2; \
				exit 1; \
			fi; \
			sleep 1; \
		done
	@if docker run --rm --network none --read-only --cap-drop ALL \
		--security-opt no-new-privileges --pids-limit 32 --memory 512m --cpus 1 \
		--tmpfs /tmp:rw,noexec,nosuid,size=32m \
		--env PDF_PROCESSOR_SHARED_SECRET=artifactflow-local-pdf-processor-secret-not-for-production \
		--env PHP_CLI_SERVER_WORKERS=2 $(PDF_PROCESSOR_SERVICE_IMAGE); then \
		echo "PDF processor accepted more than one HTTP worker." >&2; exit 1; \
	fi

scan-image:
	@mkdir -p "$(TRIVY_CACHE_DIR)"
	docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
		-v "$(TRIVY_CACHE_DIR):/root/.cache/trivy" \
		$(TRIVY_IMAGE) image --scanners vuln,secret,misconfig --severity HIGH,CRITICAL --exit-code 1 $(PRODUCTION_IMAGE)
	docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
		-v "$(TRIVY_CACHE_DIR):/root/.cache/trivy" \
		$(TRIVY_IMAGE) image --scanners vuln,secret,misconfig --severity HIGH,CRITICAL --exit-code 1 $(IMAGE_PARSER_IMAGE)
	docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
		-v "$(TRIVY_CACHE_DIR):/root/.cache/trivy" \
		$(TRIVY_IMAGE) image --scanners vuln,secret,misconfig --severity HIGH,CRITICAL --exit-code 1 $(PDF_PROCESSOR_SERVICE_IMAGE)
	docker run --rm \
		-v "$(TRIVY_CACHE_DIR):/root/.cache/trivy" \
		-v "$(PWD):/src:ro" \
		$(TRIVY_IMAGE) fs $(TRIVY_REPO_SCAN_SKIP_DIRS) --scanners secret,misconfig --severity MEDIUM,HIGH,CRITICAL --exit-code 1 /src

doctor:
	@$(MAKE) run-app-cmd APP_CMD='php artisan artifactflow:doctor'

install:
	docker compose exec app php artisan artifactflow:install $(INSTALL_ARGS)

gitleaks:
	gitleaks git --no-banner --redact -c .gitleaks.toml .

quality:
	$(MAKE) publish-guard
	$(MAKE) ai-hooks-test
	$(MAKE) ecs
	$(MAKE) stan
	$(MAKE) lint-js
	$(MAKE) semgrep
	$(MAKE) gitleaks
	$(MAKE) test
	$(MAKE) type-coverage
	$(MAKE) coverage

quality-full:
	$(MAKE) quality
	$(MAKE) audit
	$(MAKE) build-assets
	$(MAKE) verify-reverb-origin
	$(MAKE) e2e
	$(MAKE) build-prod
	$(MAKE) scan-image

config-refresh:
	$(MAKE) deps
	$(MAKE) run-app-cmd APP_CMD='php artisan config:clear'

lint-js:
	$(COMPOSE) --profile frontend run --rm --no-deps vite sh -lc '/var/www/html/docker/ensure-node-modules.sh && npm run lint:js && npm run format:check'
