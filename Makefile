SHELL := /bin/sh

COMPOSE := docker/compose/dev.sh
INTEGRATION_COMPOSE := docker/compose/integration.sh
PRODUCTION_COMPOSE := docker/compose/prod.sh
POSTGRES_TUNING_CHECK := docker/scripts/postgres-tuning-check.sh
WORKER_CLI := $(COMPOSE) run --rm --no-deps app bin/app

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

build-production: guard-user ## Build the self-contained production image from the three-repository workspace
	DOCKER_IMAGE_PREFIX="$(DOCKER_IMAGE_PREFIX)" RELEASE_VERSION="$(RELEASE_VERSION)" \
		DOCKER_PLATFORM="$(DOCKER_PLATFORM)" docker/scripts/build-production-image.sh
.PHONY: build-production

freeze-release: guard-user ## Freeze the tracked production release identity for an already built image
	RELEASE_VERSION="$(RELEASE_VERSION)" APP_IMAGE_DIGEST="$(APP_IMAGE_DIGEST)" \
		DOCKER_PLATFORM="$(DOCKER_PLATFORM)" docker/release/freeze-release.sh
.PHONY: freeze-release

release-payload: guard-user ## Save the frozen application image and write its checksum manifest
	DOCKER_PLATFORM="$(DOCKER_PLATFORM)" RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" \
		docker/release/build-offline-payload.sh
.PHONY: release-payload

release-offline: release-payload ## Assemble the flat tagged offline transport archive
	DOCKER_PLATFORM="$(DOCKER_PLATFORM)" RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" \
		OFFLINE_ARCHIVE_PATH="$(OFFLINE_ARCHIVE_PATH)" docker/release/build-offline-archive.sh
.PHONY: release-offline

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
		BACKUP_PROVENANCE_NAME="$(BACKUP_PROVENANCE_NAME)" \
		BACKUP_PROVENANCE_SHA256="$(BACKUP_PROVENANCE_SHA256)" \
		docker/backup/create-postgres-backup.sh
.PHONY: backup-data-preflight

backup-data: guard-user ## Create a verified application PostgreSQL or Nominatim logical backup
	BACKUP_COMPONENT="$(BACKUP_COMPONENT)" BACKUP_ROOT="$(BACKUP_ROOT)" \
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
	$(WORKER_CLI) worker:pause --group=geo-registry --reason="make worker-pause"
.PHONY: worker-pause

worker-resume: guard-user ## Resume claims for both workers
	$(WORKER_CLI) worker:resume --group=geo-registry --reason="make worker-resume"
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
	COMPOSE_ENV_FILE=config/prod.env.example \
		RELEASE_ENV_FILE=docker/compose/prod/release.env \
		$(PRODUCTION_COMPOSE) config --quiet
.PHONY: doctor

prod-config: guard-user ## Render and validate production Compose with explicit operator/release files
	$(PRODUCTION_COMPOSE) config
.PHONY: prod-config

prod-up: guard-user ## Start the immutable production application processes only
	$(PRODUCTION_COMPOSE) up --detach --remove-orphans
.PHONY: prod-up

prod-down: guard-user ## Stop production application processes without deleting data
	$(PRODUCTION_COMPOSE) down --remove-orphans
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

server-source-bundle: guard-user ## Package the clean three-repository source workspace for transfer
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

smoke-release: registry-check ## Run strict executable Postman parity against prepared release data
	POSTMAN_COLLECTION="$(POSTMAN_COLLECTION)" \
		POSTMAN_ENVIRONMENT="$(POSTMAN_ENVIRONMENT)" \
		RELEASE_ARTIFACT_DIR="$(RELEASE_ARTIFACT_DIR)" \
		RELEASE_RUN_NAME="$(RELEASE_RUN_NAME)" \
		POSTMAN_BASE_URL=$${POSTMAN_BASE_URL:-http://host.docker.internal:$${APP_PORT:-8080}} \
		docker/scripts/run-release-smoke.sh
.PHONY: smoke-release

help:
	@awk 'BEGIN {FS=":.*?## "} /^[a-zA-Z0-9_-]+:.*## / {printf "%-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
.PHONY: help

.DEFAULT_GOAL := help
