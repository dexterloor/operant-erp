# Operant ERP

An AI-powered, multi-tenant ERP backend for small businesses, served as a versioned JSON API. Its AI
assistant is designed, not built. Solo portfolio project. Source-available, not open source (see
[Licence](#licence)).

## For recruiters and hiring managers

**What it is.** The backend of a business system for small companies: inventory, warehouses and storage
locations, purchasing and goods receipts, sales orders, shipments, invoices, returns and credit notes,
customers, suppliers, reorder suggestions, data exports, an audit trail, and user and role management. Each
business gets its own workspace, and PostgreSQL itself keeps one workspace's rows out of another's queries.
It is built in PHP on Symfony 7.4, API Platform 4 and PostgreSQL 17.

**What works today.** The API serves 131 operations, and one more, the AI assistant, is designed but not
built. The built operations include signing in, inviting a new user by email, password reset by emailed
link, roles and permissions with separation-of-duty rules, and the order-to-invoice and purchase-to-receipt
flows. Email is sent by a background worker through a queue. Locally, every message lands in a mail catcher,
not a real inbox.

**What is not built.**

- **The AI assistant.** A read-only assistant that answers questions about a workspace's data. It would use
  its own database login, which can only read, and only inside one workspace. The design exists; the code
  does not.
- **A frontend.** This repository is the backend only.
- **A way to create a workspace.** No command or endpoint creates a workspace or its first owner yet, so a
  server you start has nobody who can sign in. The test suite creates its own workspaces. A demo seed is
  planned.
- **CI and hosting.** There is no CI pipeline and no public deployment.

**How the work was done.** Most features followed the same loop:

1. A written design covering architecture, security, data and user-facing behaviour.
2. An adversarial review whose only aim was to break that design.
3. A decision on anything the review left open, with its reason recorded.
4. The build. New checks are mutation-tested: the code is deliberately broken to prove the check fails.
5. A fresh run of the full test suite and the module-boundary check from a wiped database before the change
   is pushed.

Small fixes to code already shipped took a shorter path.

The designs, reviews and decisions are kept in a private design record. Code comments cite it by path (for
example `docs/API.md`) and by identifier (for example `G-12`); those documents are not in this repository.
Comments also use short labels such as ARCH, SEC, DATA, UX, PO, RED, LEAD and BE-LEAD, and words such as reviewer and
judge. They name the angle a design or review took (architecture, security, data, user experience, product, adversarial
review, build, final ruling), not people.

**Where to look first:**

| What | Path |
|---|---|
| The row-level security policy every workspace table gets | `backend/migrations/Version20260905000100.php` |
| How code is bound to one workspace inside the database | `backend/src/Platform/Tenancy/TenantContext.php` |
| The database logins and exactly what each may do | `infra/postgres/init/10-roles.sh`, `backend/src/Platform/Tenancy/SchemaManifest.php` |
| Tests that try to read or write across workspaces | `backend/tests/Tenancy/` |
| Module boundaries, enforced by a check | `backend/deptrac.yaml`, `backend/tests/Architecture/` |
| Permissions and separation of duties | `backend/src/Modules/UserManagement/Domain/PermissionCatalogue.php`, `SeparationOfDuty.php` |
| Safe retries for create requests | `backend/src/Platform/Http/Idempotency/` |
| Queued email: workspace stamping and the invitation sender | `backend/src/Platform/Messaging/`, `backend/src/Modules/UserManagement/Infrastructure/Messaging/` |

## For engineers

### Stack

| Layer | Choice (versions as locked in `backend/composer.lock` and `infra/docker-compose.yml`) |
|---|---|
| Language | PHP 8.4.1 or later |
| Framework | Symfony 7.4, API Platform 4.3, Doctrine ORM 3, Doctrine Migrations |
| Database | PostgreSQL 17, behind PgBouncer 1.23 in transaction pooling mode |
| Queue and mail | Symfony Messenger on Redis 7.4; Symfony Mailer; Mailpit as the local mail catcher |
| Auth | Signed access tokens (`firebase/php-jwt`), Argon2id password hashing |
| Tests | PHPUnit 13 against a real database; Deptrac for module boundaries |
| Local infrastructure | Docker Compose: `postgres`, `pgbouncer`, `redis`, `mailpit` |

### Layout

The backend is a modular monolith. Each business area is a module under `backend/src/Modules/`:

```
backend/src/
  Modules/
    Analytics  AuditLog  Customers  Inventory  Purchasing
    Sales  Suppliers  UserManagement  Warehouses
  Platform/       tenancy, security, HTTP conventions, messaging, runtime guards
  SharedKernel/   value objects and display helpers any module may use
backend/migrations/   Doctrine migrations (136 so far), run by the schema owner only
backend/tests/        Api, Tenancy, Auth, Architecture, Security, Messaging, Runtime, ...
infra/                Compose file, PgBouncer config, database role bootstrap
```

A module is split into layers: `ApiResource`, `Application`, `Infrastructure`, and where needed `Domain`,
plus `Contract` and `Port` (under `Application/`). `backend/deptrac.yaml` lists every allowed edge between
layers and modules, and the check rejects anything it does not list. Modules call each other only through
another module's `Contract` or `Port` layer. The AI module's layers are declared in `deptrac.yaml` and have no
code yet.

### Run it locally

**You need:**

- PHP 8.4.1 or later. The locked PHPUnit 13 and Doctrine Migrations bundle refuse older versions.
- PHP built with Argon2 support: `php -r 'var_dump(defined("PASSWORD_ARGON2ID"));'` must print `true`.
- PHP extensions: `pdo_pgsql`, `redis`, `bcmath`, `ctype`, `iconv`, plus the common ones Symfony and PHPUnit
  need (`mbstring`, `dom`, `xml`, `xmlwriter`, `tokenizer`). `pdo_pgsql` and `redis` are the ones most often
  missing; check with `php -m`.
- Composer.
- Docker with Compose.
- Free host ports for Postgres, PgBouncer, Redis and Mailpit. The defaults are in `.env.infra.example`.

The `Makefile` uses the Docker context `colima`. If yours differs, run `docker context ls` and pass it on
every command, for example `make DOCKER_CONTEXT=default up`.

Redis and Mailpit listen on `127.0.0.1` only. The Postgres and PgBouncer ports are published on all network
interfaces, so run the stack on a network you trust.

**1. Create the settings files.**

```sh
make env    # copies the four .env*.example templates to .env, .env.infra, .env.test and .env.worker
```

The four files are split by who may read them. `.env.infra` is read only by Docker Compose and holds every
password the database needs to set itself up. `.env` is read by the application. `.env.test` holds the schema
owner's connection, used only by the test suite and migrations. `.env.worker` is read only by the mail worker.
The web application refuses to serve requests if it can see the owner's or the worker's database login.

**2. Replace every `CHANGE_ME_` placeholder.** Never commit the filled-in files; they are gitignored.

- **Passwords.** Each password placeholder (`CHANGE_ME_APP`, `CHANGE_ME_AI`, `CHANGE_ME_AUTH`,
  `CHANGE_ME_AUTHLOG`, `CHANGE_ME_RATELIMIT`, `CHANGE_ME_WORKER`, `CHANGE_ME_OWNER`, `CHANGE_ME_REDIS`,
  `CHANGE_ME_SUPERUSER`) appears in `.env.infra` and in every connection URL that uses it. Generate one value
  per placeholder with `openssl rand -hex 24` (hex is safe inside a URL) and replace **every** occurrence of
  that placeholder with it. As a cross-check:
  - the five logins the web application uses → `APP_DATABASE_URL` and `APP_DIRECT_DATABASE_URL`,
    `AI_DATABASE_URL`, `AUTH_DATABASE_URL`, `AUTHLOG_DATABASE_URL`, `RATELIMIT_DATABASE_URL` in `.env`;
  - the schema owner → `DATABASE_URL` in `.env.test`;
  - the mail worker → `WORKER_DATABASE_URL` in both `.env.test` and `.env.worker`;
  - Redis → `MESSENGER_TRANSPORT_DSN` in `.env`;
  - the bootstrap superuser → `.env.infra` only.
- **Keys in `.env`.** Generate each of `AUTH_TOKEN_SIGNING_KEY`, `AUTH_EMAIL_HASH_KEY` and
  `AUTH_CREDENTIAL_MAC_KEY` with `openssl rand -base64 32`, and `APP_SECRET` with `openssl rand -hex 32`.
  `AUTH_CREDENTIAL_MAC_KEY` must be base64 of at least 32 bytes; with a shorter or invalid key nobody can sign
  in. Leave `AUTH_CREDENTIAL_MAC_KEY_ID` and `AUTH_CREDENTIAL_MAC_KEY_PREVIOUS` empty locally.

`make env` prints which files still contain placeholders.

**3. Install, build the database, run the checks.**

```sh
cd backend && composer install && cd ..
cd backend/tools/deptrac && composer install && cd ../../..   # Deptrac is a separate project; the suite runs it too
make reset      # destroys the volumes, starts the containers, creates the logins, runs every migration
make test       # the full PHPUnit suite; it can take tens of minutes on a laptop
make deptrac    # module boundary check
```

`make reset` should end by printing `N migrations executed`. If it prints "Already at the latest version"
instead, the database it reset is not the one the suite uses. Stop and check your Docker context.

**4. Optional: the mail worker.**

```sh
make worker        # consumes the mail queue for up to an hour, then exits
make worker-loop   # the same, restarted whenever it exits
```

Mail the worker sends goes to Mailpit. Its inbox is at `http://127.0.0.1:58025` with the default ports. Links
in that mail point at a frontend that is not built yet.

### Checks

A change is pushed only when both of these pass, run from a wiped database:

- `make test`: the full PHPUnit suite, run against real PostgreSQL through PgBouncer.
- `make deptrac`: zero violations against the declared module edges.

Contract tests that compare the code against the private design record are skipped without it, so a clone of
this repository runs fewer checks than the project is held to.

### Tenancy model

All workspaces share one schema. Every workspace table has a `tenant_id` column, has row-level security
enabled and forced, and carries the policy
`tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid` for both reads and writes. If the
setting is missing, the comparison is null and no rows match.

Code that reads or writes workspace data runs inside `TenantContext` (sign-in's credential lookup does the same on its own login). It opens a transaction and sets
`app.tenant_id` with `set_config(..., true)`, which scopes the setting to that transaction. It refuses to
switch workspace inside a transaction and refuses to join a transaction it did not open. Because the setting
dies with the transaction, PgBouncer's shared connections cannot carry one workspace into the next request.
Locally, PgBouncer runs with one server connection per login, so consecutive requests share a database
connection and a leak would show up in the tests.

Once someone is signed in, every request takes its workspace from the signed access token, never from a
header, query string or request body. Signing in and asking for a password link name the workspace in the
request body, since no token exists yet.

Mail queued by a signed-in user (an invitation) carries its workspace in a stamp the worker checks, not in the
message body. A password-reset request is queued before anyone has signed in, so it names the workspace in its
payload and the worker looks the workspace up again.

Apart from the bootstrap superuser, which only runs the container's setup script, every database login is
created `NOSUPERUSER NOBYPASSRLS`, including the schema owner. Migrations run as that separate owner login,
which never serves a request.

Isolation **between** workspaces is enforced by the database. Rules **inside** a workspace (permissions,
separation of duties, confirmation by the owner) are enforced by the application and its tests.

## Licence

Copyright 2026 Dexter Loor.

This repository is **source-available, not open source.** It is licensed under the
[PolyForm Strict License 1.0.0](LICENSE), together with a separate
[evaluation permission](EVALUATION-PERMISSION.md) that lets an individual build and run it solely
to assess the author's skills and work. No business, production or commercial use, and no
redistribution, is permitted.

This project is **not accepting contributions** (issues and pull requests may be closed without review).

## About this repository's history

This repository is an export of a private repository. The licence text has been normalised across all
commits to the current licence; no version of this code was published before this export. Author email
addresses have been mapped to a GitHub no-reply address. The first commit was created by GitHub when the
repository was set up and still names GitHub as its committer.

Commit IDs quoted in code comments refer to the original private repository; commit IDs quoted in commit
messages have been mapped to this repository's IDs.
