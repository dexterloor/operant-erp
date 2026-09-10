# W0-a tenancy slice. Everything here runs locally; no cloud account is needed.
COMPOSE = docker compose --env-file .env -f infra/docker-compose.yml

.PHONY: env up down reset migrate test tenancy deptrac verify

env:                ## create .env from the template (then fill in every CHANGE_ME)
	@test -f .env || cp .env.example .env
	@grep -q CHANGE_ME_ .env && echo "  .env still contains placeholders -- edit it before continuing" || true

up:                 ## postgres + pgbouncer (pool_mode=transaction, default_pool_size=1)
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

reset:              ## destroy the database volume and rebuild it from the roles script
	$(COMPOSE) down -v
	$(COMPOSE) up -d
	@until $(COMPOSE) exec -T postgres pg_isready -q; do sleep 1; done
	$(MAKE) migrate

migrate:            ## runs as the owner role; erp_app has no rights to migrate
	cd backend && php bin/console doctrine:migrations:migrate --no-interaction

test:
	cd backend && php bin/phpunit

tenancy:            ## just the isolation suite
	cd backend && php bin/phpunit --testsuite "Project Test Suite" --filter Tenancy

deptrac:            ## ARCHITECTURE.md §5's module boundaries (also gated by DeptracRulesetTest in `make test`)
	cd backend && php tools/deptrac/vendor/bin/deptrac analyse

verify: up migrate test
