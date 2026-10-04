# digitalSmart — common tasks. Run `make help` for the list.

SHELL := /bin/bash
.DEFAULT_GOAL := help

BACKEND  := backend
FRONTEND := frontend

.PHONY: help setup up down dev dev-api dev-queue dev-realtime dev-web \
        verify test lint analyse fix migrate fresh e2e docs clean

help: ## Show available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

setup: ## Install dependencies and prepare both applications
	cd $(BACKEND) && composer install
	cd $(BACKEND) && [ -f .env ] || cp .env.example .env
	cd $(BACKEND) && php artisan key:generate --ansi
	cd $(BACKEND) && php artisan migrate --force
	cd $(FRONTEND) && npm install
	cd $(FRONTEND) && [ -f .env.local ] || cp .env.example .env.local

up: ## Start PostgreSQL and Redis containers
	docker compose up -d

down: ## Stop the datastore containers
	docker compose down

dev: ## Run API, Horizon, Reverb and the SPA together
	npx concurrently -c "#93c5fd,#c4b5fd,#fdba74,#86efac" \
		"cd $(BACKEND) && php artisan serve --port=8001" \
		"cd $(BACKEND) && php artisan horizon" \
		"cd $(BACKEND) && php artisan reverb:start" \
		"cd $(FRONTEND) && npm run dev" \
		--names=api,queue,realtime,web --kill-others

dev-api: ## Run the API only
	cd $(BACKEND) && php artisan serve --port=8001

dev-queue: ## Run Horizon only
	cd $(BACKEND) && php artisan horizon

dev-realtime: ## Run Reverb only
	cd $(BACKEND) && php artisan reverb:start

dev-web: ## Run the SPA dev server only
	cd $(FRONTEND) && npm run dev

verify: ## Everything CI runs, except end-to-end
	cd $(BACKEND) && composer verify
	cd $(FRONTEND) && npm run verify && npm run format:check

test: ## Run both test suites
	cd $(BACKEND) && composer test
	cd $(FRONTEND) && npm run test

lint: ## Check formatting and lint rules
	cd $(BACKEND) && composer lint
	cd $(FRONTEND) && npm run lint

analyse: ## Static analysis (PHPStan level 8 + tsc strict)
	cd $(BACKEND) && composer analyse
	cd $(FRONTEND) && npm run typecheck

fix: ## Auto-fix formatting and lint issues
	cd $(BACKEND) && composer lint:fix
	cd $(FRONTEND) && npm run lint:fix && npm run format

migrate: ## Run database migrations
	cd $(BACKEND) && php artisan migrate

fresh: ## Drop and rebuild the development database
	cd $(BACKEND) && php artisan migrate:fresh --seed

e2e: ## Playwright end-to-end tests against an isolated stack and database
	./scripts/e2e.sh

docs: ## Export the OpenAPI document
	cd $(BACKEND) && php artisan scramble:export --path=../docs/openapi.json

clean: ## Remove build output and caches
	rm -rf $(FRONTEND)/dist $(FRONTEND)/coverage $(FRONTEND)/playwright-report $(FRONTEND)/test-results
	cd $(BACKEND) && php artisan optimize:clear
