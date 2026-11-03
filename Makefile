SHELL := /bin/sh

COMPOSE := docker/compose/dev.sh
INTEGRATION_COMPOSE := docker/compose/integration.sh

export HOST_UID ?= $(shell id -u)
export HOST_GID ?= $(shell id -g)

guard-user:
	@if [ "$$(id -u)" -eq 0 ] || [ -n "$${SUDO_USER:-}" ]; then \
		echo "Run development commands as the host user, without sudo." >&2; \
		exit 1; \
	fi
.PHONY: guard-user

config: ## Create local configuration when it is missing
	@test -f config/.env || cp config/.env.example config/.env
.PHONY: config

build: ## Build the PHP development image
	$(COMPOSE) build app
.PHONY: build

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

logs: ## Follow logs; set SERVICE=app to filter
	$(COMPOSE) logs --follow $(SERVICE)
.PHONY: logs

ps: ## Show container and health status
	$(COMPOSE) ps
.PHONY: ps

check: guard-user ## Run formatting, strict PHPStan, and Testo
	$(COMPOSE) run --rm --no-deps app composer check
.PHONY: check

architecture-check: guard-user ## Verify application and module dependency directions
	$(COMPOSE) run --rm --no-deps app composer deptrac
.PHONY: architecture-check

fix: guard-user ## Apply PHP formatting
	$(COMPOSE) run --rm --no-deps app composer cs-fix
.PHONY: fix

doctor: ## Validate the resolved Compose configuration
	$(COMPOSE) config --quiet
	$(INTEGRATION_COMPOSE) config --quiet
.PHONY: doctor

integration-up: guard-user ## Start isolated PostgreSQL and OpenSearch test resources
	$(INTEGRATION_COMPOSE) up --detach --wait --no-build --remove-orphans
.PHONY: integration-up

integration-down: guard-user ## Stop and remove isolated integration resources
	$(INTEGRATION_COMPOSE) down --remove-orphans
.PHONY: integration-down

smoke: ## Probe the running HTTP service and its integrations
	APP_BASE_URL=$${APP_BASE_URL:-http://127.0.0.1:$${APP_PORT:-8080}} docker/scripts/smoke-check.sh
.PHONY: smoke

help:
	@awk 'BEGIN {FS=":.*?## "} /^[a-zA-Z0-9_-]+:.*## / {printf "%-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
.PHONY: help

.DEFAULT_GOAL := help
