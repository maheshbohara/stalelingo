# Translation Drift – development tasks. Everything runs in Docker; the host needs Docker, Compose v2 and make.
#
#   make up && make setup      bilingual Polylang site at http://localhost:8080 (admin / password)
#   make test                  all test suites
#   make help                  list targets

-include .env
export

PROVIDER ?= polylang
PHP_VERSION ?= 8.3
WP_VERSION ?= latest
N ?= 5000
TYPES ?= post,page,tdrift_book

DC      := docker compose
RUN     := $(DC) run --rm -T
PHP     := $(RUN) php
NODE    := $(RUN) node
WPCLI   := $(RUN) wpcli
PLUGIN  := wp-content/plugins/translation-drift

.DEFAULT_GOAL := help
.PHONY: help up down reset setup logs shell composer npm deps build watch \
	lint lint-php lint-js phpstan phpcompat plugin-check readme-validate \
	test test-unit test-integration test-integration-ms test-js test-e2e coverage \
	cron-run seed perf pot zip zip-smoke ci

help: ## List targets
	@grep -hE '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------------------------------
# Environment
# ---------------------------------------------------------------------------

up: ## Start db, WordPress (:8080) and Mailpit (:8025)
	$(DC) up -d --wait db wordpress mailpit

down: ## Stop the stack
	$(DC) down

reset: ## Destroy all volumes and rebuild the site from scratch
	$(DC) down -v --remove-orphans
	$(MAKE) up
	$(MAKE) setup

setup: deps up ## Install WordPress, plugins, languages, users and seed content (PROVIDER=polylang|wpml)
	$(WPCLI) bash $(PLUGIN)/bin/setup.sh

logs: ## Follow container logs
	$(DC) logs -f wordpress db mailpit

shell: ## Shell in the PHP tools container
	$(DC) run --rm php bash

composer: ## Run composer, e.g. make composer ARGS="update"
	$(PHP) composer $(ARGS)

npm: ## Run npm, e.g. make npm ARGS="install foo"
	$(NODE) npm $(ARGS)

vendor/autoload.php: composer.json
	$(PHP) composer install --no-interaction
	@touch $@

node_modules/.package-lock.json: package.json
	$(NODE) npm ci --no-audit --no-fund

deps: vendor/autoload.php node_modules/.package-lock.json build/dashboard/index.js

build/dashboard/index.js: node_modules/.package-lock.json $(shell find src -type f 2>/dev/null)
	$(NODE) npm run build

build: node_modules/.package-lock.json ## Build JS/CSS into build/
	$(NODE) npm run build

watch: node_modules/.package-lock.json ## Rebuild JS/CSS on change
	$(DC) run --rm node npm run start

# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

lint: lint-php lint-js ## phpcs + eslint (with tsc) + stylelint

lint-php: vendor/autoload.php
	$(PHP) vendor/bin/phpcs

lint-js: node_modules/.package-lock.json
	$(NODE) npm run lint

phpstan: vendor/autoload.php ## PHPStan level 8
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G --no-progress

phpcompat: vendor/autoload.php ## PHPCompatibilityWP for PHP 8.1 and newer
	$(PHP) composer phpcompat

plugin-check: zip ## Official Plugin Check against the packaged plugin
	$(DC) up -d --wait db wordpress
	$(WPCLI) bash $(PLUGIN)/bin/plugin-check.sh $(ARGS)

readme-validate: ## Validate readme.txt and version agreement
	$(PHP) php bin/readme-validate.php

# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

test: test-unit test-integration test-integration-ms test-js test-e2e ## Run every suite

test-unit: vendor/autoload.php ## PHPUnit unit tests (Brain Monkey, no WordPress)
	$(PHP) vendor/bin/phpunit -c phpunit.xml.dist

test-integration: vendor/autoload.php build/dashboard/index.js ## PHPUnit integration tests against wordpress_test
	$(PHP) bash -c 'bin/install-wp-tests.sh && vendor/bin/phpunit -c phpunit-integration.xml.dist'

test-integration-ms: vendor/autoload.php build/dashboard/index.js ## Multisite integration tests (network activation, new and deleted sites)
	$(PHP) bash -c 'bin/install-wp-tests.sh && WP_MULTISITE=1 vendor/bin/phpunit -c phpunit-integration-ms.xml.dist'

test-js: node_modules/.package-lock.json ## Jest + Testing Library + jest-axe
	$(NODE) npm run test:js

test-e2e: deps up ## Playwright end-to-end tests
	$(RUN) playwright npx playwright test $(ARGS)

coverage: vendor/autoload.php build/dashboard/index.js ## Coverage (pcov) with thresholds: 70% overall, 85% domain/services
	$(PHP) bash -c 'rm -rf coverage && mkdir -p coverage/parts \
		&& vendor/bin/phpunit -c phpunit.xml.dist --coverage-php coverage/parts/unit.cov \
		&& bin/install-wp-tests.sh \
		&& vendor/bin/phpunit -c phpunit-integration.xml.dist --coverage-php coverage/parts/integration.cov \
		&& vendor/bin/phpcov merge --clover coverage/clover.xml --text php://stdout coverage/parts \
		&& php bin/coverage-check.php coverage/clover.xml'

# ---------------------------------------------------------------------------
# Utilities
# ---------------------------------------------------------------------------

cron-run: ## Run due WP-Cron events and pending Action Scheduler actions
	$(WPCLI) bash -c 'wp cron event run --due-now; if wp cli has-command "action-scheduler run"; then wp action-scheduler run; fi'

seed: ## Seed N translation groups per post type (default 5000; TYPES=post limits the types)
	$(WPCLI) wp eval-file $(PLUGIN)/bin/dev/seed.php $(PROVIDER) $(N) $(TYPES)

perf: ## Performance run: seeds 5000 posts x 3 languages, then measures baseline, dashboard REST, list table and EXPLAIN
	$(MAKE) seed N=$(N) TYPES=post
	$(WPCLI) php -d memory_limit=1G /usr/local/bin/wp eval-file $(PLUGIN)/bin/dev/perf.php
	@echo "The dev site now holds the performance data; 'make reset' restores the small seed."

pot: ## Generate languages/translation-drift.pot
	# The dashboard bundle (DataViews) is large; the JS parser needs more than the default 128 MB.
	$(WPCLI) php -d memory_limit=1G /usr/local/bin/wp i18n make-pot $(PLUGIN) $(PLUGIN)/languages/translation-drift.pot \
		--slug=translation-drift --domain=translation-drift \
		--exclude=node_modules,vendor,tests,dist,docker,bin,coverage,test-results,src

zip: deps ## Build the WordPress.org zip in dist/
	$(NODE) npm run build
	$(PHP) bash bin/zip.sh

screenshots: deps up ## Capture the readme screenshots into .wordpress-org/ (on the seeded dev site)
	$(RUN) playwright npx playwright test --config playwright.screenshots.config.ts

zip-smoke: zip ## Install the release zip on a clean WordPress (:8081) and run the smoke e2e test against it
	$(DC) --profile zip up -d --wait db wordpress-zip
	$(DC) --profile zip run --rm -T wpcli-zip bash /tools/zip-smoke.sh
	$(DC) --profile zip run --rm -T playwright-zip npx playwright test smoke.spec.ts
	$(DC) --profile zip stop wordpress-zip

ci: lint phpstan phpcompat test-unit test-integration test-js readme-validate plugin-check ## What CI runs before e2e
