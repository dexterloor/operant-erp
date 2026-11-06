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

.PHONY: env up down reset migrate test tenancy deptrac verify worker worker-loop contracts demo-seed demo-populate serve refuse-demo-database require-demo-database

env:                ## create .env, .env.infra, .env.test, .env.worker and .env.demo from the templates (then fill in every CHANGE_ME)
	@test -f .env || cp .env.example .env
	@test -f .env.infra || cp .env.infra.example .env.infra
	@test -f .env.test || cp .env.test.example .env.test
	@test -f .env.worker || cp .env.worker.example .env.worker
	@test -f .env.demo || cp .env.demo.example .env.demo
	@for f in .env .env.infra .env.test .env.worker; do grep -q CHANGE_ME_ $$f && echo "  $$f still contains placeholders -- edit it before continuing" || true; done
	@grep -q CHANGE_ME_ .env.demo && echo "  .env.demo still contains its placeholder -- only needed if you will run the demo" || true

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

test: refuse-demo-database
	cd backend && php bin/phpunit

tenancy: refuse-demo-database  ## just the isolation suite
	cd backend && php bin/phpunit --testsuite "Project Test Suite" --filter Tenancy

# contracts/openapi.json and contracts/api-surface.json are what the web app builds from; ContractArtefactTest holds them
# equal to the API. The first run rewrites both files and fails on purpose (an update never passes); the second must pass.
# No cache to delete first: every test run reads API metadata from its own fresh cache directory (tests/bootstrap.php).
contracts:          ## regenerate contracts/*.json after an API change, then check them
	-cd backend && CONTRACT_ARTEFACT_UPDATE=1 php bin/phpunit --filter ContractArtefactTest
	cd backend && php bin/phpunit --filter ContractArtefactTest

deptrac:            ## ARCHITECTURE.md §5's module boundaries (also gated by DeptracRulesetTest in `make test`)
	cd backend && php tools/deptrac/vendor/bin/deptrac analyse

verify: refuse-demo-database up migrate test

worker:             ## the mail worker: consumes `async` for an hour or 128 MB, then exits (Mail-C1 onward)
	cd backend && $(WORKER_RUNTIME) php -d zend.exception_ignore_args=1 bin/console messenger:consume async --time-limit=3600 --memory-limit=128M

# One refused queue entry stops `messenger:consume` (RESIDUAL_GAPS.md G-1.3, mail-c0-red.md M-3); this restarts it.
worker-loop:        ## `make worker`, restarted whenever it exits (Ctrl-C twice to stop)
	@while true; do $(MAKE) worker; echo "worker exited ($$?); restarting in 5 s"; sleep 5; done

# What the database is for (2a): the purpose compose writes as the database comment when the volume is created, read the
# way compose reads it -- the environment's DB_PURPOSE when it is set (an empty value means gate), else the LAST
# DB_PURPOSE= line of .env.infra (quotes stripped; an empty value means gate), else gate (`${DB_PURPOSE:-gate}` in
# infra/docker-compose.yml; a shell variable beats --env-file). A value that is not ^[a-z][a-z0-9-]{0,30}$ is refused, as
# infra/postgres/init/10-roles.sh refuses it. These are accident guards on a local file. The enforcement is on the server:
# bin/demo-seed.php refuses a database whose comment is not erp-local-demo, and the suite refuses one whose comment is not
# erp-local-gate. A changed purpose takes effect only at the next `make reset`, because the comment is written once.
PURPOSE = if [ -n "$${DB_PURPOSE+set}" ]; then p="$$DB_PURPOSE"; \
	elif [ -f .env.infra ]; then p="$$(sed -n 's/^DB_PURPOSE=//p' .env.infra | tail -n 1 | tr -d "\"' \t\r")"; else p=""; fi; \
	p="$${p:-gate}"; \
	if ! printf '%s' "$$p" | grep -Eq '^[a-z][a-z0-9-]{0,30}$$'; then echo "refused: DB_PURPOSE must be lower-case letters, digits and hyphens (^[a-z][a-z0-9-]{0,30}\$$)."; exit 2; fi

refuse-demo-database:
	@$(PURPOSE); if [ "$$p" = demo ]; then echo "refused: DB_PURPOSE says this is the demo database, and the tests would wipe it. Set DB_PURPOSE=gate in .env.infra (or remove the line), then make reset."; exit 2; fi

require-demo-database:
	@$(PURPOSE); if [ "$$p" != demo ]; then echo "refused: the demo commands run only on a demo database. Set DB_PURPOSE=demo in .env.infra, then make reset (this replaces the test data)."; exit 2; fi

# The API on this machine only, through PHP's built-in server (one request at a time). It reads the root .env, as the
# front controller does, and the serving guard refuses to start it if that environment can see an owner or worker login.
API_PORT ?= 8000
serve:              ## the API on http://127.0.0.1:$(API_PORT) (PHP's built-in server; Ctrl-C to stop)
	cd backend && php -d zend.exception_ignore_args=1 -S 127.0.0.1:$(API_PORT) -t public public/index.php

# The local demo workspace (W-3): its eight built-in roles and one owner (owner@example.com), written as the schema owner by
# bin/demo-seed.php. It runs only on a demo database, one created with DB_PURPOSE=demo (see above); the seeder checks that on
# the server too and refuses any other. The password comes from DEMO_PASSWORD in the gitignored root .env.demo (template
# .env.demo.example), or from stdin with SEED_ARGS=--password-stdin; it is never printed. A second run for the same
# WORKSPACE refuses (exit 3): reset to start again.
WORKSPACE ?= demo
SEED_ARGS ?=
demo-seed: require-demo-database  ## seed the local demo workspace (needs DB_PURPOSE=demo and a make reset)
	@echo "demo-seed: target database port $$(grep -E '^POSTGRES_DIRECT_PORT=' .env.infra | cut -d= -f2) (DOCKER_CONTEXT=$(DOCKER_CONTEXT))"
	cd backend && php bin/demo-seed.php --workspace=$(WORKSPACE) $(SEED_ARGS)

# Fill the seeded demo workspace through the API (2a): seven more people, each given a password through their invitation
# (read from Mailpit, never printed), their roles, and warehouses, items, suppliers, customers, orders and an invoice --
# every one made by the shipped operation, as the person who would do it. Needs `make serve` and `make worker-loop`
# running. Talks only to 127.0.0.1. The password is the same DEMO_PASSWORD (or DEMO_ARGS=--password-stdin). It refuses a
# workspace that already holds anyone but its owner (exit 3): reset and seed again to start over.
DEMO_ARGS ?=
demo-populate: require-demo-database  ## fill the demo workspace through the API (needs make serve and make worker-loop)
	cd backend && php bin/demo-walkthrough.php populate --api=http://127.0.0.1:$(API_PORT) --mailbox=http://127.0.0.1:$$(sed -n 's/^MAILPIT_UI_PORT=//p' ../.env.infra | tail -n 1) --workspace=$(WORKSPACE) $(DEMO_ARGS)
