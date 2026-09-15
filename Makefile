# W0-a tenancy slice. Everything here runs locally; no cloud account is needed.
#
# DOCKER_CONTEXT is pinned deliberately. On 2026-09-10 this machine had Colima
# and Docker Desktop both running this stack: Colima published the ports on
# IPv4, Docker Desktop on IPv6, and `127.0.0.1` resolves IPv4 first -- so
# `make reset` destroyed one database while the suite tested another for a
# whole session. Override it if your engine differs; do not remove it.
DOCKER_CONTEXT ?= colima
# Three env files, split by who may read them (V3-1(i), V4 batch B2b-1, 2026-09-16):
#   .env.infra  compose bootstrap passwords (owner, superuser, roles) -- read by compose ONLY
#   .env        application DSNs and secrets -- read by every Symfony kernel
#   .env.test   the schema owner's DSN -- read by the suite, `migrate` and `reset` only
COMPOSE = DOCKER_CONTEXT=$(DOCKER_CONTEXT) docker compose --env-file .env.infra -f infra/docker-compose.yml

# `.env.test` is loaded through PHP Dotenv by the runtime for this one command,
# never shell-sourced: `set -a; . ./.env.test` would parse it as shell, where the
# unquoted `&` in `?serverVersion=17&charset=utf8` backgrounds a command and a `$`
# in a password expands. With the owner DSN present, bin/console refuses every
# command except ServingCredentialGuard::CONSOLE_OWNER_COMMANDS (hygiene), so
# `doctrine:migrations:generate` / `dump-schema` are refused under OWNER_RUNTIME
# too, and `list` / `help` are refused on purpose.
OWNER_RUNTIME = APP_RUNTIME_OPTIONS='{"dotenv_extra_paths":["../.env.test"]}'

.PHONY: env up down reset migrate test tenancy deptrac verify

env:                ## create .env, .env.infra and .env.test from the templates (then fill in every CHANGE_ME)
	@test -f .env || cp .env.example .env
	@test -f .env.infra || cp .env.infra.example .env.infra
	@test -f .env.test || cp .env.test.example .env.test
	@for f in .env .env.infra .env.test; do grep -q CHANGE_ME_ $$f && echo "  $$f still contains placeholders -- edit it before continuing" || true; done

up:                 ## postgres + pgbouncer (pool_mode=transaction, default_pool_size=1)
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

reset:              ## destroy the database volume and rebuild it from the roles script
	$(COMPOSE) down -v
	$(COMPOSE) up -d
	@until $(COMPOSE) exec -T postgres pg_isready -q; do sleep 1; done
	@cd backend && php bin/assert-clean-database.php
	$(MAKE) migrate

migrate:            ## runs as the owner role; erp_app has no rights to migrate
	cd backend && $(OWNER_RUNTIME) php bin/console doctrine:migrations:migrate --no-interaction

test:
	cd backend && php bin/phpunit

tenancy:            ## just the isolation suite
	cd backend && php bin/phpunit --testsuite "Project Test Suite" --filter Tenancy

deptrac:            ## ARCHITECTURE.md §5's module boundaries (also gated by DeptracRulesetTest in `make test`)
	cd backend && php tools/deptrac/vendor/bin/deptrac analyse

verify: up migrate test
