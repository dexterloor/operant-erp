# W0-a tenancy slice. Everything here runs locally; no cloud account is needed.
#
# DOCKER_CONTEXT is pinned deliberately. On 2026-09-10 this machine had Colima
# and Docker Desktop both running this stack: Colima published the ports on
# IPv4, Docker Desktop on IPv6, and `127.0.0.1` resolves IPv4 first -- so
# `make reset` destroyed one database while the suite tested another for a
# whole session. Override it if your engine differs; do not remove it.
DOCKER_CONTEXT ?= colima
# Four env files, split by who may read them (V3-1(i), V4 batch B2b-1, 2026-09-16; .env.worker from Mail-C0, 2026-10-23):
#   .env.infra  compose bootstrap passwords (owner, superuser, roles) and local ports -- read by compose ONLY
#   .env        application DSNs and secrets, and (Mail-C1) the mail queue DSN -- read by every Symfony kernel
#   .env.test   the schema owner's DSN, and (Mail-C1) the mail worker's WORKER_DATABASE_URL -- read by the suite, `migrate` and `reset` only
#   .env.worker the mail worker's mailer settings, WORKER_DATABASE_URL and APP_PUBLIC_URL -- read by `make worker` only,
#               never by the web process (which refuses to serve while it can see the worker's login)
COMPOSE = DOCKER_CONTEXT=$(DOCKER_CONTEXT) docker compose --env-file .env.infra -f infra/docker-compose.yml

# `.env.test` is loaded through PHP Dotenv by the runtime for this one command,
# never shell-sourced: `set -a; . ./.env.test` would parse it as shell, where the
# unquoted `&` in `?serverVersion=17&charset=utf8` backgrounds a command and a `$`
# in a password expands. With the owner DSN present, bin/console refuses every
# command except ServingCredentialGuard::CONSOLE_OWNER_COMMANDS (hygiene), so
# `doctrine:migrations:generate` / `dump-schema` are refused under OWNER_RUNTIME
# too, and `list` / `help` are refused on purpose.
OWNER_RUNTIME = APP_RUNTIME_OPTIONS='{"dotenv_extra_paths":["../.env.test"]}'
# The worker's own settings, loaded the same way for its one command. It consumes `async` ONLY: `failed` is parked mail
# an operator inspects, never re-run automatically (mail-c0-red.md M-2).
WORKER_RUNTIME = APP_RUNTIME_OPTIONS='{"dotenv_extra_paths":["../.env.worker"]}'

.PHONY: env up down reset migrate test tenancy deptrac verify worker worker-loop contracts

env:                ## create .env, .env.infra, .env.test and .env.worker from the templates (then fill in every CHANGE_ME)
	@test -f .env || cp .env.example .env
	@test -f .env.infra || cp .env.infra.example .env.infra
	@test -f .env.test || cp .env.test.example .env.test
	@test -f .env.worker || cp .env.worker.example .env.worker
	@for f in .env .env.infra .env.test .env.worker; do grep -q CHANGE_ME_ $$f && echo "  $$f still contains placeholders -- edit it before continuing" || true; done

up:                 ## postgres + pgbouncer (pool_mode=transaction, default_pool_size=1) + the local mail catcher
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

# contracts/openapi.json and contracts/api-surface.json are what the web app builds from; ContractArtefactTest holds them
# equal to the API. The first run rewrites both files and fails on purpose (an update never passes); the second must pass.
# No cache to delete first: every test run reads API metadata from its own fresh cache directory (tests/bootstrap.php).
contracts:          ## regenerate contracts/*.json after an API change, then check them
	-cd backend && CONTRACT_ARTEFACT_UPDATE=1 php bin/phpunit --filter ContractArtefactTest
	cd backend && php bin/phpunit --filter ContractArtefactTest

deptrac:            ## ARCHITECTURE.md §5's module boundaries (also gated by DeptracRulesetTest in `make test`)
	cd backend && php tools/deptrac/vendor/bin/deptrac analyse

verify: up migrate test

worker:             ## the mail worker: consumes `async` for an hour or 128 MB, then exits (Mail-C1 onward)
	cd backend && $(WORKER_RUNTIME) php -d zend.exception_ignore_args=1 bin/console messenger:consume async --time-limit=3600 --memory-limit=128M

# One refused queue entry stops `messenger:consume` (RESIDUAL_GAPS.md G-1.3, mail-c0-red.md M-3); this restarts it.
worker-loop:        ## `make worker`, restarted whenever it exits (Ctrl-C twice to stop)
	@while true; do $(MAKE) worker; echo "worker exited ($$?); restarting in 5 s"; sleep 5; done
