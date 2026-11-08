SHELL := /bin/sh

COMPOSE := docker/compose/dev.sh
INTEGRATION_COMPOSE := docker/compose/integration.sh
PRODUCTION_COMPOSE := docker/compose/prod.sh
POSTGRES_TUNING_CHECK := docker/scripts/postgres-tuning-check.sh
WORKER_CLI := $(COMPOSE) run --rm --no-deps app bin/app
DATA_HOST_PATH ?= $(CURDIR)/docker/data
LOAD_CHECK_URL ?= http://127.0.0.1:8080/health/live
LOAD_CHECK_REQUESTS ?= 1000
LOAD_CHECK_CONCURRENCY ?= 32
LOAD_CHECK_MAX_FAILURES ?= 0
LOAD_CHECK_MAX_P95_MS ?= 250
ADDRESS_LOAD_BASE_URL ?= http://host.docker.internal:8080
ADDRESS_LOAD_CONTRACT ?= target
ADDRESS_LOAD_VARIANT ?= unspecified
ADDRESS_LOAD_VUS ?= 16
ADDRESS_LOAD_DURATION ?= 30s
ADDRESS_LOAD_TIMEOUT ?= 10s
ADDRESS_LOAD_MAX_P95_MS ?= 750
ADDRESS_LOAD_MAX_P99_MS ?= 1500

export HOST_UID ?= $(shell id -u)
export HOST_GID ?= $(shell id -g)

guard-user:
	@if [ "$$(id -u)" -eq 0 ] || [ -n "$${SUDO_USER:-}" ]; then \
		echo "Run development commands as the host user, without sudo." >&2; \
		exit 1; \
	fi
.PHONY: guard-user

guard-worker-instance:
	@if [ -z "$(WORKER_INSTANCE_ID)" ]; then \
		echo "Set WORKER_INSTANCE_ID to an exact active instance from make worker-status." >&2; \
		exit 1; \
	fi
.PHONY: guard-worker-instance

config: ## Create local configuration when it is missing
	@test -f config/.env || cp config/.env.example config/.env
.PHONY: config

build: ## Build the PHP development image
	$(COMPOSE) build app
.PHONY: build

build-production: guard-user ## Build the self-contained production image from locked Composer packages
	DOCKER_IMAGE_PREFIX="$(DOCKER_IMAGE_PREFIX)" RELEASE_VERSION="$(RELEASE_VERSION)" \
		DOCKER_PLATFORM="$(DOCKER_PLATFORM)" RELEASE_BRANCH="$(RELEASE_BRANCH)" \
		docker/scripts/build-production-image.sh
.PHONY: build-production

freeze-release: guard-user ## Freeze the tracked production release identity for an already built image
	DOCKER_IMAGE_PREFIX="$(DOCKER_IMAGE_PREFIX)" RELEASE_VERSION="$(RELEASE_VERSION)" APP_IMAGE_DIGEST="$(APP_IMAGE_DIGEST)" \
		DOCKER_PLATFORM="$(DOCKER_PLATFORM)" RELEASE_BRANCH="$(RELEASE_BRANCH)" \
		docker/release/freeze-release.sh
.PHONY: freeze-release

release-payload: guard-user ## Save the frozen application image and write its checksum manifest
	DOCKER_PLATFORM="$(DOCKER_PLATFORM)" RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" \
		RELEASE_BRANCH="$(RELEASE_BRANCH)" docker/release/build-offline-payload.sh
.PHONY: release-payload

release-publish: guard-user ## Push the frozen exact application image to its configured registry
	DOCKER_PLATFORM="$(DOCKER_PLATFORM)" RELEASE_BRANCH="$(RELEASE_BRANCH)" \
		docker/release/publish-release-image.sh
.PHONY: release-publish

release-pull: guard-user ## Explicitly pull and verify the frozen exact application image
	DOCKER_PLATFORM="$(DOCKER_PLATFORM)" docker/release/pull-release-image.sh
.PHONY: release-pull

release-check: guard-user ## Validate release, backup and production wrapper shell syntax
	sh -n \
		docker/release/release-lib.sh \
		docker/release/freeze-release.sh \
		docker/release/build-offline-payload.sh \
		docker/release/build-offline-archive.sh \
		docker/release/publish-release-image.sh \
		docker/release/pull-release-image.sh \
		docker/release/change-release.sh \
		docker/release/extract-release-data.sh \
		docker/release/init-deployment.sh \
		docker/release/initialize-deployment-data.sh \
		docker/release/create-deployment-backup.sh \
		docker/release/deployment-data-status.sh \
		docker/release/deployment-http-binding.sh \
		docker/release/deployment-port-preflight.sh \
		docker/release/deployment-smoke.sh \
		docker/release/prepare-deployment-image.sh \
		docker/release/restore-deployment-data.sh \
		docker/release/update-deployment.sh \
		docker/scripts/download-gar.sh \
		docker/backup/backup-lib.sh \
		docker/backup/create-postgres-backup.sh \
		docker/backup/verify-postgres-backup.sh \
		docker/backup/restore-postgres-shadow.sh \
		docker/scripts/build-production-image.sh \
		docker/scripts/run-release-smoke.sh \
		docker/compose/prod.sh
	sh -n \
		docker/release/tests/backup-progress-test.sh \
		docker/release/tests/change-release-offline-test.sh \
		docker/release/tests/deployment-data-status-test.sh \
		docker/release/tests/deployment-backup-test.sh \
		docker/release/tests/deployment-port-preflight-test.sh \
		docker/release/tests/update-deployment-test.sh \
		docker/release/tests/init-deployment-test.sh \
		docker/release/tests/restore-progress-test.sh \
		docker/release/tests/fixtures/docker \
		docker/release/tests/fixtures/ss
	docker/release/tests/backup-progress-test.sh
	docker/release/tests/change-release-offline-test.sh
	docker/release/tests/deployment-data-status-test.sh
	docker/release/tests/deployment-backup-test.sh
	docker/release/tests/deployment-port-preflight-test.sh
	docker/release/tests/init-deployment-test.sh
	docker/release/tests/restore-progress-test.sh
	docker/release/tests/update-deployment-test.sh
	test -f docs/deployment/offline-release.md
	test -f docs/deployment/deploy-from-sources.md
	test -f docs/deployment/deploy-with-backup.md
	test -f docs/deployment/network-access.md
	test -f docs/deployment/release-deploy.md
	test -f docs/deployment/troubleshooting.md
.PHONY: release-check

release-offline: release-payload ## Assemble the flat tagged offline transport archive
	DOCKER_PLATFORM="$(DOCKER_PLATFORM)" RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" \
		OFFLINE_ARCHIVE_PATH="$(OFFLINE_ARCHIVE_PATH)" \
		RELEASE_BRANCH="$(RELEASE_BRANCH)" \
		RELEASE_INCLUDE_APPLICATION_DATA="$(RELEASE_INCLUDE_APPLICATION_DATA)" \
		RELEASE_DATA_BACKUP_DIR="$(RELEASE_DATA_BACKUP_DIR)" \
		RELEASE_DATA_CONTAINER="$(RELEASE_DATA_CONTAINER)" \
		RELEASE_DATA_RESERVE_GB="$(RELEASE_DATA_RESERVE_GB)" \
		docker/release/build-offline-archive.sh
.PHONY: release-offline

extract-release-data: guard-user ## Verify and extract the optional PostgreSQL backup from an unpacked release
	RELEASE_EXTRACT_DIR="$(RELEASE_EXTRACT_DIR)" BACKUP_ROOT="$(BACKUP_ROOT)" \
		RELEASE_DATA_DRY_RUN="$(RELEASE_DATA_DRY_RUN)" docker/release/extract-release-data.sh
.PHONY: extract-release-data

deploy-init: guard-user ## Create one protected production config with generated managed PostgreSQL passwords
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" docker/release/init-deployment.sh
.PHONY: deploy-init

deploy-image: guard-user ## Pull or load and verify the frozen application image automatically
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" DOCKER_PLATFORM="$(DOCKER_PLATFORM)" \
		RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" docker/release/prepare-deployment-image.sh
.PHONY: deploy-image

deploy-port-preflight: guard-user ## Fail early when the configured production HTTP host port is unavailable
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" docker/release/deployment-port-preflight.sh
.PHONY: deploy-port-preflight

deploy-smoke: guard-user ## Probe the production HTTP binding resolved from Compose YAML
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" docker/release/deployment-smoke.sh
.PHONY: deploy-smoke

deploy-restore: guard-user ## Discover, verify and restore release PostgreSQL data through production Compose
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" docker/release/restore-deployment-data.sh $(DEPLOY_RESTORE_FLAGS)
.PHONY: deploy-restore

data-restore: deploy-restore ## Restore the canonical PostgreSQL backup through the linear production workflow
.PHONY: data-restore

data-restore-status: guard-user ## Show managed PostgreSQL restore state and the safe next action
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" docker/release/deployment-data-status.sh
.PHONY: data-restore-status

data-init: guard-user ## Create a fresh managed PostgreSQL database and apply migrations
	@echo "==> Initializing an empty managed PostgreSQL database"
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" docker/release/initialize-deployment-data.sh
.PHONY: data-init

data-backup-preflight: guard-user ## Verify capacity and source of the managed production database
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" BACKUP_ROOT="$(BACKUP_ROOT)" \
		DEPLOY_BACKUP_DRY_RUN=1 docker/release/create-deployment-backup.sh
.PHONY: data-backup-preflight

data-backup: guard-user ## Create a verified backup of the managed production database
	@echo "==> Creating a verified PostgreSQL backup; periodic progress follows"
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" BACKUP_ROOT="$(BACKUP_ROOT)" \
		docker/release/create-deployment-backup.sh
.PHONY: data-backup

data-gar-releases: guard-user ## Show official GAR releases and full XML URLs
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance bin/app gar:releases --json
.PHONY: data-gar-releases

data-gar-download: guard-user ## Download and atomically extract one official GAR full XML archive
	@test -n "$(GAR_URL)" || { echo "Set GAR_URL to the full_xml_url returned by make data-gar-releases." >&2; exit 1; }
	@echo "==> Downloading and verifying the selected official GAR release"
	$(PRODUCTION_COMPOSE) run --rm --no-deps \
		--env GAR_URL="$(GAR_URL)" --env GAR_SHA256="$(GAR_SHA256)" \
		maintenance /usr/local/bin/download-gar
.PHONY: data-gar-download

data-gar-import: guard-user ## Import GAR regions 50/77 through COPY workers
	@echo "==> Importing GAR regions 50 and 77 with four COPY workers"
	@echo "==> For a live checkpoint snapshot, run 'make data-status' in a second terminal"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance \
		bin/app gar:import /data/gar/full --regions=50,77 --batch-size=50000 --workers=4
.PHONY: data-gar-import

data-address-rebuild: guard-user ## Build and validate the partitioned PostgreSQL address search model
	@echo "==> [1/3] Building the partitioned PostgreSQL address search projection"
	@echo "==> For a live checkpoint snapshot, run 'make data-status' in a second terminal"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance \
		bin/app gar:address-search:rebuild --batch-size=50000
	@echo "==> [2/3] Running deep integrity checks for regions 50 and 77"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance \
		bin/app gar:integrity --regions=50,77 --deep --only-failed
	@echo "==> [3/3] Checking exact regional projection parity"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance \
		bin/app gar:status --regions=50,77 --exact
.PHONY: data-address-rebuild

data-datamos-download: guard-user ## Download the official Moscow address snapshot into the maintenance data directory
	@echo "==> Downloading the official Data.Mos address snapshot"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance \
		bin/app datamos:address-registry:download /data/datamos/addressreestr.jsonl.gz
.PHONY: data-datamos-download

data-datamos-import: guard-user ## Import and enrich the address model from the downloaded Data.Mos snapshot
	@echo "==> Importing Data.Mos rows and enriching address search"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance \
		bin/app datamos:address-registry:import /data/datamos/addressreestr.jsonl.gz \
		--require-manifest --batch-size=5000 --truncate --enrich
.PHONY: data-datamos-import

data-opensearch: guard-user ## Start OpenSearch and rebuild its projection from PostgreSQL
	@echo "==> [1/3] Starting OpenSearch and waiting for health"
	$(PRODUCTION_COMPOSE) --profile opensearch up --detach --wait opensearch
	@echo "==> [2/3] Building a new versioned OpenSearch index from PostgreSQL"
	$(PRODUCTION_COMPOSE) run --rm --no-deps --env OPENSEARCH_ENABLED=1 maintenance \
		bin/app opensearch:reindex --batch-size=10000 --concurrency=8
	@echo "==> [3/3] Checking index, alias and source/target parity"
	$(PRODUCTION_COMPOSE) run --rm --no-deps --env OPENSEARCH_ENABLED=1 \
		maintenance bin/app opensearch:status
.PHONY: data-opensearch

data-status: guard-user ## Show live GAR import/rebuild checkpoints and active PostgreSQL work
	@echo "==> GAR operation checkpoints for regions 50 and 77"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance \
		bin/app gar:status --regions=50,77
	@echo "==> Active PostgreSQL statements, operations and GAR workers"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance bin/app gar:activity
.PHONY: data-status

deploy-start: guard-user ## Verify restored data, activate the immutable application and run health smoke
	@echo "==> [1/8] Checking the configured HTTP host port before expensive data gates"
	$(MAKE) deploy-port-preflight
	@echo "==> [2/8] Checking that PostgreSQL restore is inactive and physically valid"
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" DEPLOY_DATA_REQUIRE_READY=1 \
		docker/release/deployment-data-status.sh
	@echo "==> [3/8] Verifying the frozen application image"
	$(MAKE) verify-release DOCKER_PLATFORM="$${DOCKER_PLATFORM:-linux/amd64}"
	@echo "==> [4/8] Reading migration history from the restored database"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance bin/app migrate:history
	@echo "==> [5/8] Running deep GAR integrity gates for regions 50 and 77"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance bin/app gar:integrity --regions=50,77 --deep --only-failed
	@echo "==> [6/8] Checking exact regional status and projection parity"
	$(PRODUCTION_COMPOSE) run --rm --no-deps maintenance bin/app gar:status --regions=50,77 --exact
	@echo "==> [7/8] Activating application and worker processes"
	$(MAKE) change-release DOCKER_PLATFORM="$${DOCKER_PLATFORM:-linux/amd64}"
	@echo "==> [8/8] Running HTTP smoke checks against the Compose host binding"
	$(MAKE) deploy-smoke
	@echo "==> Deployment start completed"
.PHONY: deploy-start

deploy-update-preflight: guard-user ## Verify an existing managed deployment without changing it
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" DEPLOY_UPDATE_DRY_RUN=1 \
		DOCKER_PLATFORM="$(DOCKER_PLATFORM)" RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" \
		docker/release/update-deployment.sh
.PHONY: deploy-update-preflight

deploy-update: guard-user ## Quiesce and update an existing managed deployment in place
	COMPOSE_ENV_FILE="$(COMPOSE_ENV_FILE)" DOCKER_PLATFORM="$(DOCKER_PLATFORM)" \
		RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" \
		docker/release/update-deployment.sh $(DEPLOY_UPDATE_FLAGS)
	$(MAKE) deploy-smoke
.PHONY: deploy-update

change-release: guard-user ## Verify/load the frozen image and recreate application processes only
	DOCKER_PLATFORM="$(DOCKER_PLATFORM)" RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" \
		docker/release/change-release.sh
.PHONY: change-release

verify-release: guard-user ## Verify the frozen release and payload without changing containers
	RELEASE_DRY_RUN=1 DOCKER_PLATFORM="$(DOCKER_PLATFORM)" \
		RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" docker/release/change-release.sh
.PHONY: verify-release

backup-data-preflight: guard-user ## Check PostgreSQL/Nominatim source and backup capacity without writing a dump
	BACKUP_DRY_RUN=1 BACKUP_COMPONENT="$(BACKUP_COMPONENT)" BACKUP_ROOT="$(BACKUP_ROOT)" \
		BACKUP_CONTAINER="$(BACKUP_CONTAINER)" BACKUP_DATABASE_NAME="$(BACKUP_DATABASE_NAME)" \
		BACKUP_DATABASE_USER="$(BACKUP_DATABASE_USER)" BACKUP_APPLICATION_REVISION="$(BACKUP_APPLICATION_REVISION)" \
		BACKUP_PROVENANCE_NAME="$(BACKUP_PROVENANCE_NAME)" \
		BACKUP_PROVENANCE_SHA256="$(BACKUP_PROVENANCE_SHA256)" \
		docker/backup/create-postgres-backup.sh
.PHONY: backup-data-preflight

backup-data: guard-user ## Create a verified application PostgreSQL or Nominatim logical backup
	BACKUP_COMPONENT="$(BACKUP_COMPONENT)" BACKUP_ROOT="$(BACKUP_ROOT)" \
		BACKUP_CONTAINER="$(BACKUP_CONTAINER)" BACKUP_DATABASE_NAME="$(BACKUP_DATABASE_NAME)" \
		BACKUP_DATABASE_USER="$(BACKUP_DATABASE_USER)" BACKUP_APPLICATION_REVISION="$(BACKUP_APPLICATION_REVISION)" \
		BACKUP_PROVENANCE_NAME="$(BACKUP_PROVENANCE_NAME)" \
		BACKUP_PROVENANCE_SHA256="$(BACKUP_PROVENANCE_SHA256)" \
		docker/backup/create-postgres-backup.sh
.PHONY: backup-data

verify-data-backup: guard-user ## Verify backup bytes, checksum and pg_restore table of contents read-only
	BACKUP_DIR="$(BACKUP_DIR)" VERIFY_CONTAINER="$(VERIFY_CONTAINER)" \
		docker/backup/verify-postgres-backup.sh
.PHONY: verify-data-backup

restore-data-preflight: guard-user ## Validate a shadow restore target without creating a database
	RESTORE_DRY_RUN=1 BACKUP_DIR="$(BACKUP_DIR)" RESTORE_CONTAINER="$(RESTORE_CONTAINER)" \
		RESTORE_DATABASE="$(RESTORE_DATABASE)" RESTORE_DATABASE_OWNER="$(RESTORE_DATABASE_OWNER)" \
		RESTORE_ADMIN_USER="$(RESTORE_ADMIN_USER)" docker/backup/restore-postgres-shadow.sh
.PHONY: restore-data-preflight

restore-data-shadow: guard-user ## Restore a backup only into an explicitly confirmed absent shadow database
	BACKUP_DIR="$(BACKUP_DIR)" RESTORE_CONTAINER="$(RESTORE_CONTAINER)" \
		RESTORE_DATABASE="$(RESTORE_DATABASE)" RESTORE_DATABASE_OWNER="$(RESTORE_DATABASE_OWNER)" \
		RESTORE_ADMIN_USER="$(RESTORE_ADMIN_USER)" RESTORE_CONFIRM="$(RESTORE_CONFIRM)" \
		docker/backup/restore-postgres-shadow.sh
.PHONY: restore-data-shadow

install: guard-user ## Install Composer dependencies
	$(COMPOSE) run --rm --no-deps app composer install
.PHONY: install

up: guard-user ## Start all services without rebuilding images
	$(COMPOSE) up --detach --no-build --remove-orphans
.PHONY: up

down: ## Stop services without deleting persistent data
	$(COMPOSE) down --remove-orphans
.PHONY: down

restart: down up ## Restart the development stack
.PHONY: restart

migrate: guard-user ## Apply application database migrations
	$(COMPOSE) run --rm --no-deps app bin/app migrate:up --force-yes
.PHONY: migrate

datamos-download: guard-user ## Download an official Data.Mos snapshot and provenance manifest
	$(COMPOSE) run --rm --no-deps --env DATAMOS_API_KEY \
		--volume "$(DATA_HOST_PATH):/data:rw" app \
		bin/app datamos:address-registry:download \
		/data/datamos/addressreestr.jsonl.gz $(DATAMOS_DOWNLOAD_FLAGS)
.PHONY: datamos-download

worker-up: guard-user ## Migrate and start both durable worker services
	$(COMPOSE) up --detach --no-build postgres
	$(MAKE) migrate
	$(COMPOSE) --profile worker up --detach --no-build worker-address worker-runtime
.PHONY: worker-up

worker-down: ## Stop both durable worker services
	$(COMPOSE) --profile worker stop worker-address worker-runtime
.PHONY: worker-down

worker-logs: ## Follow both durable worker service logs
	$(COMPOSE) --profile worker logs --follow worker-address worker-runtime
.PHONY: worker-logs

worker-status: guard-user ## Show durable worker and child status
	$(WORKER_CLI) worker:status --group=geo-registry --children
.PHONY: worker-status

worker-pause: guard-user ## Pause new claims for both workers
	$(WORKER_CLI) worker:pause --worker-name=geo-registry-address --reason="make worker-pause"
	$(WORKER_CLI) worker:pause --worker-name=geo-registry-runtime --reason="make worker-pause"
.PHONY: worker-pause

worker-resume: guard-user ## Resume claims for both workers
	$(WORKER_CLI) worker:resume --worker-name=geo-registry-address --reason="make worker-resume"
	$(WORKER_CLI) worker:resume --worker-name=geo-registry-runtime --reason="make worker-resume"
.PHONY: worker-resume

worker-drain: guard-user guard-worker-instance ## Drain one exact worker instance after its current job
	$(WORKER_CLI) worker:drain --worker-instance-id="$(WORKER_INSTANCE_ID)" --reason="make worker-drain"
.PHONY: worker-drain

worker-restart: guard-user guard-worker-instance ## Restart one exact worker instance under its supervisor
	$(WORKER_CLI) worker:restart --worker-instance-id="$(WORKER_INSTANCE_ID)" --reason="make worker-restart"
.PHONY: worker-restart

logs: ## Follow logs; set SERVICE=app to filter
	$(COMPOSE) logs --follow $(SERVICE)
.PHONY: logs

ps: ## Show container and health status
	$(COMPOSE) ps
.PHONY: ps

check: guard-user ## Run formatting, strict PHPStan, and Testo
	$(COMPOSE) run --rm --no-deps app composer check
.PHONY: check

registry-check: guard-user ## Compile and validate the explicit MessageBus registry
	$(COMPOSE) run --rm --no-deps app composer registry:check
.PHONY: registry-check

architecture-check: guard-user ## Verify application and module dependency directions
	$(COMPOSE) run --rm --no-deps app composer deptrac
.PHONY: architecture-check

contract: guard-user ## Validate OpenAPI and Postman contract artifacts
	$(COMPOSE) run --rm --no-deps app composer test:contract
.PHONY: contract

fix: guard-user ## Apply PHP formatting
	$(COMPOSE) run --rm --no-deps app composer cs-fix
.PHONY: fix

doctor: ## Validate the resolved Compose configuration
	POSTGRES_CAPACITY_CHECK=0 POSTGRES_TUNING_PROFILE=dev \
		POSTGRES_PROFILE_FILE=config/postgres/dev.env COMPOSE_ENV_FILE=/dev/null \
		SERVER_MIN_FREE_GB= SERVER_MIN_MEMORY_GB= $(POSTGRES_TUNING_CHECK)
	POSTGRES_CAPACITY_CHECK=0 POSTGRES_TUNING_PROFILE=prod \
		POSTGRES_PROFILE_FILE=config/postgres/prod.env COMPOSE_ENV_FILE=/dev/null \
		SERVER_MIN_FREE_GB= SERVER_MIN_MEMORY_GB= $(POSTGRES_TUNING_CHECK)
	$(COMPOSE) config --quiet
	$(INTEGRATION_COMPOSE) config --quiet
	COMPOSE_ENV_FILE=config/prod.secrets.env.example \
		RELEASE_ENV_FILE=docker/compose/prod/release.env \
		$(PRODUCTION_COMPOSE) config --quiet
	docker compose --env-file config/prod.secrets.env.example config --quiet
	docker compose --env-file config/prod.secrets.env.example \
		--file compose.yaml --file compose.override.yaml.example \
		--profile maintenance --profile opensearch config --quiet
.PHONY: doctor

prod-config: guard-user ## Validate production Compose without printing interpolated secrets
	$(PRODUCTION_COMPOSE) config --quiet
.PHONY: prod-config

prod-up: guard-user ## Start the production PostgreSQL and immutable application processes
	$(PRODUCTION_COMPOSE) up --detach
.PHONY: prod-up

prod-down: guard-user ## Stop production application processes without stopping managed data services
	$(PRODUCTION_COMPOSE) stop app worker-address worker-runtime
.PHONY: prod-down

prod-ps: guard-user ## Show production application process status
	$(PRODUCTION_COMPOSE) ps
.PHONY: prod-ps

postgres-doctor: guard-user ## Validate the selected PostgreSQL profile and host capacity
	$(POSTGRES_TUNING_CHECK)
.PHONY: postgres-doctor

integration-up: guard-user ## Start isolated PostgreSQL and OpenSearch test resources
	$(INTEGRATION_COMPOSE) up --detach --wait --no-build --remove-orphans
.PHONY: integration-up

integration-down: guard-user ## Stop and remove isolated integration resources
	$(INTEGRATION_COMPOSE) down --remove-orphans
.PHONY: integration-down

test-integration: guard-user ## Run real PostgreSQL and OpenSearch integration tests
	docker/scripts/run-integration-tests.sh
.PHONY: test-integration

server-source-bundle: guard-user ## Package the clean application source and locked package identity for transfer
	SERVER_SOURCE_OUTPUT="$(SERVER_SOURCE_OUTPUT)" docker/scripts/create-server-source-bundle.sh
.PHONY: server-source-bundle

server-preflight: guard-user ## Validate a data server without starting or changing services
	GAR_SOURCE="$(GAR_SOURCE)" \
		SERVER_STORAGE_PATH="$(SERVER_STORAGE_PATH)" \
		SERVER_MIN_FREE_GB="$(SERVER_MIN_FREE_GB)" \
		SERVER_MIN_MEMORY_GB="$(SERVER_MIN_MEMORY_GB)" \
		docker/scripts/server-preflight.sh
.PHONY: server-preflight

smoke: ## Probe the running HTTP service and its integrations
	APP_BASE_URL=$${APP_BASE_URL:-http://127.0.0.1:$${APP_PORT:-8080}} docker/scripts/smoke-check.sh
.PHONY: smoke

load-check: guard-user ## Run the bounded HTTP load gate inside the running app container
	$(COMPOSE) exec app bin/app server:load-check "$(LOAD_CHECK_URL)" \
		--requests="$(LOAD_CHECK_REQUESTS)" \
		--concurrency="$(LOAD_CHECK_CONCURRENCY)" \
		--max-failures="$(LOAD_CHECK_MAX_FAILURES)" \
		--max-p95-ms="$(LOAD_CHECK_MAX_P95_MS)" $(LOAD_CHECK_FLAGS)
.PHONY: load-check

load-search: guard-user ## Run the external mixed regional address-search load gate with k6
	ADDRESS_LOAD_BASE_URL="$(ADDRESS_LOAD_BASE_URL)" \
		ADDRESS_LOAD_CONTRACT="$(ADDRESS_LOAD_CONTRACT)" \
		ADDRESS_LOAD_VARIANT="$(ADDRESS_LOAD_VARIANT)" \
		ADDRESS_LOAD_VUS="$(ADDRESS_LOAD_VUS)" \
		ADDRESS_LOAD_DURATION="$(ADDRESS_LOAD_DURATION)" \
		ADDRESS_LOAD_TIMEOUT="$(ADDRESS_LOAD_TIMEOUT)" \
		ADDRESS_LOAD_MAX_P95_MS="$(ADDRESS_LOAD_MAX_P95_MS)" \
		ADDRESS_LOAD_MAX_P99_MS="$(ADDRESS_LOAD_MAX_P99_MS)" \
		docker/scripts/run-address-search-load.sh
.PHONY: load-search

smoke-release: registry-check ## Run strict executable Postman parity against prepared release data
	POSTMAN_COLLECTION="$(POSTMAN_COLLECTION)" \
		POSTMAN_ENVIRONMENT="$(POSTMAN_ENVIRONMENT)" \
		RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" \
		RELEASE_RUN_NAME="$(RELEASE_RUN_NAME)" \
		POSTMAN_BASE_URL=$${POSTMAN_BASE_URL:-http://host.docker.internal:$${APP_PORT:-8080}} \
		docker/scripts/run-release-smoke.sh
.PHONY: smoke-release

smoke-release-prod: guard-user ## Run registry and strict Postman gates against the exact production image
	$(PRODUCTION_COMPOSE) run --rm --no-deps app \
		bin/app message-bus:registry:check --no-interaction
	POSTMAN_COLLECTION="$(POSTMAN_COLLECTION)" \
		POSTMAN_ENVIRONMENT="$(POSTMAN_ENVIRONMENT)" \
		RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" \
		RELEASE_RUN_NAME="$(RELEASE_RUN_NAME)" \
		POSTMAN_BASE_URL=$${POSTMAN_BASE_URL:-http://host.docker.internal:$${APP_PORT:-8080}} \
		docker/scripts/run-release-smoke.sh
.PHONY: smoke-release-prod

help:
	@awk 'BEGIN {FS=":.*?## "} /^[a-zA-Z0-9_-]+:.*## / {printf "%-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
.PHONY: help

.DEFAULT_GOAL := help
