<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

/**
 * One declaration of every table in the schema, and the single input to every
 * catalogue-derived guard in tests/Tenancy.
 *
 * -------------------------------------------------------------------------
 * Why a manifest at all, when W0-a's guards read the catalogue directly
 * -------------------------------------------------------------------------
 * Because the catalogue can only answer "what is", never "what was intended".
 * The two questions that matter here are both of the second kind:
 *
 *   ADR-006 V-10 / ARCHITECTURE S-13
 *     A table with no tenant_id carries no RLS and inherits the blanket grants
 *     plus ALTER DEFAULT PRIVILEGES. `tenant` and `messenger_messages` are in
 *     that set, so an unauthenticated request reaches a principal that can
 *     mutate the tenant registry and inject into the message queue. A check
 *     derived only from the catalogue cannot distinguish "this table has no
 *     tenant_id because it is the registry" from "this table has no tenant_id
 *     because someone forgot".
 *
 *   ARCHITECTURE S-7(a)
 *     Exactly one module writes each table. Ownership is not a database fact.
 *
 * So: this file states intent, and the tests assert the catalogue matches it
 * **in both directions**. A table created by a migration and not declared here
 * fails. A table declared here and not created fails. A grant wider than the
 * one declared fails; so does a grant narrower than declared.
 *
 * **What a grant here means (ADR-002 Amendment 4, 2026-09-16).** `erp_app`'s
 * write privileges on a table are exactly the writes production code under
 * `backend/src/` issues on it, counting the implied ones: an `ON CONFLICT DO
 * UPDATE` needs UPDATE; a locking clause needs UPDATE on every table it locks
 * (every table in FROM when `OF` is absent, sub-selects, CTEs, `FOR KEY SHARE`
 * included); a role holding UPDATE on a `cascade_deletion_to_lines` parent needs
 * UPDATE and SELECT on the child. A tenant table declared without `grants` is
 * therefore **read-only** by default ({@see self::TENANT_READ_DEFAULT}), and a
 * table that keeps a write says so explicitly below. The migration that ships
 * the first caller of a write grants it and edits this file in the same commit;
 * a `42501` from a new writer is fixed by granting that writer's privilege,
 * never by restoring a CRUD default. Until 2026-09-16 the default was CRUD, and
 * `GrantFootprintTest`'s exact equality would have pushed every new tenant table
 * to grant writes ahead of any caller.
 *
 * The grants are the load-bearing part. ALTER DEFAULT PRIVILEGES (A1.1) is what
 * stops R-34, and it is also what makes S-9's `REVOKE` fragile and what hands
 * every future non-tenant table to erp_app. The resolution is the same shape
 * S-9 already needed and generalised: default privileges still fire, then the
 * migration revokes everything on every table and re-grants exactly what is
 * declared below, last. Nothing is inherited by accident, and the assertion is
 * exhaustive rather than spot-check.
 *
 * @see ADR-002 §1, §5, §6, Amendment 1 §A1.1/§A1.3, Amendment 4
 * @see ADR-006 §2, §6.3, V-1…V-3, V-10
 * @see ADR-007 (deletion policy — the `deletion` key)
 */
final class SchemaManifest
{
    /**
     * ADR-007: the row carries `deleted_at timestamptz NULL`, its business
     * uniqueness is partial on `WHERE deleted_at IS NULL`, and
     * `UNIQUE (tenant_id, id)` stays total.
     */
    public const SOFT = 'soft';

    /** No `deleted_at`. The row is removed outright, and nothing may reference it afterwards. */
    public const HARD = 'hard';

    /**
     * No `deleted_at` **and no DELETE grant**. ADR-007 offers two classes; a
     * third is needed, because "hard delete" and "never deleted" have different
     * grant footprints and collapsing them is how `audit_log` ends up with a
     * DELETE privilege nobody intended (S-9).
     */
    public const NONE = 'none';

    /** Not applicable — infrastructure tables nothing in the domain deletes. */
    public const NA = 'n/a';

    /**
     * Table-level privileges asserted for every application role on every table.
     * Anything not listed for a role is asserted **false**.
     *
     * `RED` found the hole this comment now exists for: a list here defines the
     * *coverage* of the exhaustive grant check, and nothing was checking the
     * list. Shrinking it to three privileges and granting `DELETE, TRUNCATE ON
     * invoice TO erp_app` left the whole suite green. So
     * GrantFootprintTest::theCoverageConstantsNameEveryPrivilegePostgresHas
     * derives the real set from the server -- `GRANT ALL` on a probe table, then
     * `aclexplode` -- and fails if this constant is short of it. That also means
     * a PostgreSQL upgrade adding a privilege type (17 added `MAINTAIN`) fails
     * the build rather than quietly widening what nobody looks at.
     *
     * @var list<string>
     */
    public const TABLE_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER', 'MAINTAIN'];

    /**
     * has_any_column_privilege() raises `unrecognized privilege type` for
     * DELETE, TRUNCATE and TRIGGER — measured, and named in ADR-006 V-1. The two
     * lists must differ or the check errors in CI instead of failing.
     *
     * @var list<string>
     */
    public const COLUMN_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'REFERENCES'];

    /**
     * The default footprint on a tenant-scoped table: **read-only** (ADR-002
     * Amendment 4). Writes are declared per table, and only for a write a `src/`
     * caller issues. Replaced `CRUD` (SELECT, INSERT, UPDATE, DELETE for
     * `erp_app`) on 2026-09-16, which granted writes no code used -- including on
     * `role`, `role_permission` and `user_role`. `erp_ai` keeps SELECT pending
     * V3-5.
     */
    private const TENANT_READ_DEFAULT = [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']];

    /** A tenant table `erp_app` inserts into and updates, from `src/` callers named at the table. */
    private const APP_INSERT_UPDATE = [DatabaseRoles::APP => ['SELECT', 'INSERT', 'UPDATE'], DatabaseRoles::AI => ['SELECT']];

    // ⚠ **`APP_INSERT` lived here from 2026-09-19 until `ConfirmSalesOrder` the same day, and is DELETED
    // rather than kept.** It was minted for `CreateSalesOrder` under ruling R-B (`SELECT, INSERT`, no
    // `UPDATE`) and both its users — `sales_order` and `sales_order_line` — moved to `APP_INSERT_UPDATE`
    // when the confirm's `UPDATE` landed, leaving a footprint constant with no table. A shared constant
    // nothing shares is the next table's easiest wrong answer, and R-B's reasoning survives where it was
    // argued: `Version20260919000200`'s docblock, which is committed and unchanged.

    /** Readable by the application and the query agent; written only by the owner. */
    private const READ_ONLY_REFERENCE = [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']];

    /** Nothing. The owner is the only principal that touches it. */
    private const OWNER_ONLY = [];

    /**
     * ADR-006 §2.1 step 2: a slug that does not resolve still runs the
     * `app_user` SELECT, against a tenant that exists in the registry and owns
     * no users, so the statement sequence is identical on every miss path (V-8).
     * The value is a valid UUIDv4 because D-12's CHECK applies to `tenant.id`.
     */
    public const SENTINEL_TENANT_ID = '00000000-0000-4000-8000-000000000000';

    /**
     * Every table in `public`, its owning module, whether it is tenant-scoped,
     * its ADR-007 deletion class, and the exact privileges each application role
     * is expected to hold.
     *
     * `grants` is mandatory for a non-tenant table (that is V-10) and defaults
     * to read-only (`TENANT_READ_DEFAULT`) for a tenant-scoped one.
     *
     * `retention_column` (ADR-007 §A1.3) names the `timestamptz NOT NULL` column an
 * owner purge would predicate on, and `retention_why` says why it is declared.
 * Optional, and **present only where an age purge is AUTHORISED** — absent
 * everywhere else, asserting nothing. Every one of them is a **FORWARD
 * declaration**: no purge, no principal and no job runner exists for any.
 * `SchemaManifestTest` pins the column, its type and the refusals;
 * `GrantFootprintTest` separately proves from the server that no application role
 * can DELETE from the declaring tables. **Neither key closes SEC-O-1.**
 *
 * @var array<string, array{module: string, tenant: bool, deletion: string, why: string, retention_column?: string, retention_why?: string, grants?: array<string, list<string>>, column_grants?: array<string, array<string, list<string>>>, rls_exempt?: true}>
     */
    public const TABLES = [
        // ------------------------------------------------------------------
        // Platform — no tenant_id, therefore no RLS, therefore every one of
        // these is a V-10 entry with an explicit grant list.
        // ------------------------------------------------------------------
        'tenant' => [
            'module' => 'Platform\\Tenancy',
            'tenant' => false,
            'deletion' => self::NONE,
            'why' => 'The registry. It is the table the tenant_id in every other table points at, so it cannot itself be tenant-scoped. Closure is tenant.status, never a row delete: a deleted tenant strands queue payloads (ARCHITECTURE O-7) and makes replay silently no-op (O-8), and a status leaves both queryable.',
            // S-10 grants SELECT to erp_app and the owner, never erp_ai. The
            // writes are the V-10 half: nothing that serves an unauthenticated
            // request may mutate the registry, so no application role holds one.
            'grants' => [DatabaseRoles::APP => ['SELECT']],
            'column_grants' => [DatabaseRoles::AUTH => ['SELECT' => ['id', 'slug', 'status']]],
        ],
        'auth_attempt' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => false,
            'deletion' => self::NA,
            'why' => 'ADR-006 §2.3. Pre-authentication rate-limit counters, keyed on the client-supplied slug, so there is no tenant to own the row. Retention is DROP PARTITION by the owner, which is why no application role holds DELETE.',
            'retention_column' => 'window_start',
            'retention_why' => 'ADR-007 A1.3. Purge shape is DROP PARTITION by the owner. FORWARD DECLARATION: only the DEFAULT partition exists (SCHEMA.md calls the mechanism structurally present and operationally inert), so this column serves as the partition key and no statement evaluates it as an age predicate. An age purge is authorised by ADR-006 2.3 and unbuilt.',
            'grants' => [DatabaseRoles::RATE_LIMIT => ['SELECT', 'INSERT', 'UPDATE']],
        ],
        'auth_ip_budget' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => false,
            'deletion' => self::NA,
            'why' => 'SEC-O-16, ruled 2026-09-09. E-21\'s second pre-auth budget — 300 attempts per fixed 15-minute window per `ip_prefix` — had nowhere to live. It cannot live in `auth_attempt`: E-9 and `API.md` §7.1 step 2 both consume it *before* the `auth_attempt` upsert, so it would be read after the write it exists to authorise, and every row of that table names a workspace because `slug_normalised` leads its primary key. A reserved sentinel slug was rejected on a fact about the column rather than on taste — `auth_attempt.slug_normalised` is client-supplied and deliberately shape-free (that is what makes a slug scrape loud, ADR-006 §2.3), so a client could send the reserved value and choose whether its request was metered at 100 or at 300. Keyed `(ip_prefix, window_start)` and nothing else, so no client-supplied value selects, shares or displaces the row that meters it. Partitioned like `auth_attempt` so retention is DROP PARTITION by the owner: an unpartitioned counter table is SEC-O-1 filed again on the day it is created, and it is what would force a DELETE grant onto the one principal whose narrowness is the point of it existing.',
            'retention_column' => 'window_start',
            'retention_why' => 'ADR-007 A1.3 by extension, on the authority in this entry own why cell: SEC-O-16, ruled 2026-09-09, made this table partitioned like auth_attempt so that retention is DROP PARTITION by the owner. It is ABSENT from A1.3 three-row table, which predates it by two days. GrantFootprintTest already corrected the same staleness in itself, where the counter-table list read auth_attempt plus auth_lockout until SEC-O-16 was found. Declared here so the manifest does not sit behind its own test; ARCH still owes A1.3 the row, alongside F-18.',
            'grants' => [DatabaseRoles::RATE_LIMIT => ['SELECT', 'INSERT', 'UPDATE']],
        ],
        'auth_lockout' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => false,
            'deletion' => self::NA,
            'why' => 'ADR-006 §6.6 / ARCHITECTURE S-14. Lockout state lives here, keyed (slug_normalised, email_hash), and never on app_user: a pre-auth UPDATE on a tenant-owned table from an attacker-chosen context is O-9 with a DoS attached. **Also carries E-12\'s daily login-failure trail cap (E4-4 as amended, Version20260914000100):** suppression_count and suppression_day, advanced inside the one step-7 upsert and only on the branches that write the trail, separate from failure_count and reset by nothing but a new UTC day (ADR-006 A1.3, C = 20). No new grant: the table-level grant below covers both columns, so they are reachable by erp_ratelimit alone (GrantFootprintTest::theLoginFailureCapStorageIsReachableByTheRateLimiterAlone). No E-11 column: its half moved to the output that builds the email.',
            'retention_column' => 'last_failure_at',
            'retention_why' => 'ADR-007 A1.3, and the weakest of the three. SEC-O-1 measured that partition-and-drop is the WRONG treatment here: it widens the primary key and destroys the ON CONFLICT (slug_normalised, email_hash) identity that ADR-006 6.6 fixes, turning a persistent lockout into a windowed one an attacker outlasts. The right treatment is an owner-run bounded purge needing no schema change, and NO SUCH PRINCIPAL EXISTS OR CAN BE CREATED. Nothing yet says that purge predicates on this column rather than on locked_until. SEC-O-1 STAYS OPEN: this declaration closes E16-1 clause 1 and does NOT close the unbounded growth. RESIDUAL_GAPS.md carries the purge with an owner and a hard trigger.',
            'grants' => [DatabaseRoles::RATE_LIMIT => ['SELECT', 'INSERT', 'UPDATE']],
        ],
        'messenger_messages' => [
            'module' => 'Platform\\Messaging',
            'tenant' => false,
            'deletion' => self::NA,
            'why' => 'ARCHITECTURE S-8. A worker must select the next message before it knows the tenant, so a policy would make the queue unreadable. Tenancy travels as a TenantIdStamp. This is the one place isolation rests on a code rule (M-2) rather than on Postgres — which is exactly why no pre-authentication principal is allowed to reach it. B-1: erp_app holds SELECT and no write. An INSERT here is worker code execution by another name, and symfony/messenger is not installed, so the write half of this grant served nothing and was withdrawn by Version20260907000100 rather than left to ship by default the moment it is. The remaining SELECT does NOT preserve consumption — the transport consumes with UPDATE and DELETE (DELIVERY_LEDGER.md:113) — it is a declared residual: a cross-tenant read of serialized payloads on a table with no RLS, pinned here so it cannot drift, and left to B-1 action 4 where the worker principal is decided.',
            'grants' => [DatabaseRoles::APP => ['SELECT']],
        ],
        'doctrine_migration_versions' => [
            'module' => 'Platform',
            'tenant' => false,
            'deletion' => self::NA,
            'why' => 'Created by the migrations bundle before the first migration runs, so ALTER DEFAULT PRIVILEGES never covered it — but ADR-002 §1\'s GRANT … ON ALL TABLES did, handing erp_app full DML over the migration ledger. Revoked.',
            'grants' => self::OWNER_ONLY,
        ],

        // ------------------------------------------------------------------
        // The workspace's own configuration. Grouped with UserManagement
        // because that is who owns it; see the `why` for why that is not the
        // obvious answer and what settled it.
        // ------------------------------------------------------------------
        'tenant_settings' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'E4-3 / ROADMAP F-11 / E9-1. The workspace\'s own configuration, and today exactly one field: the functional currency the item writer stamps on a standard cost. It exists because `app.inventory.default_currency_code` was an INSTANCE-WIDE container parameter supplying a PER-TENANT business value -- every tenant on the deployment priced in euros by construction, and unreachable by every check this project has, because a PHP constant never touches the database and so sits above the isolation boundary. It also defeated a guarantee the schema had deliberately established: Version20260906000100 dropped inventory_item.currency_code\'s default precisely so a row could not be created "with no stated currency and be silently priced in euros", and a constant one layer up did exactly that. The column therefore carries NO default, and there is no trigger provisioning the row, because either one would relocate the same defect rather than remove it. Ownership was NOT obvious and the ruleset settled it: ARCHITECTURE Section 4 names no owner for tenant-wide configuration, and Platform\\Tenancy -- semantically the best fit, sitting beside the registry -- is refused by Section 5.1, because an L0 entity may not reference SharedKernel\\Reference\\Currency and the foreign key into the seeded currency list is worth more than the placement. Modules\\UserManagement is where IA.md routes Administration > Settings, beside Users and Roles, and is the module tenant_settings.edit already belongs to through SECURITY_MODEL E-15b. Inventory reads the value through UserManagement\\Application\\Contract, which is A-2\'s sanctioned cross-module channel. Deletion class none: a settings row is never removed while its tenant exists, and removing one would silently restore the "no stated currency" state.',
            // SELECT and nothing else, and each omission is a decision.
            // INSERT: the registry's own reasoning (S-10 / V-10) -- erp_app
            // cannot create a workspace, so it must not create a workspace's
            // settings. UPDATE: `UpdateSettings` is not shippable (API.md
            // Section 4.3b, API-O-14), and a privilege with no user is the
            // grant-side of an index with no query; the pass that ships the
            // operation issues the grant and edits this line in the same
            // commit. DELETE/TRUNCATE: excluded by the deletion class above,
            // and asserted by theDeletionClassDeterminesTheGrantFootprint.
            // erp_ai: no NL query needs the functional currency today.
            'grants' => [DatabaseRoles::APP => ['SELECT']],
        ],

        // ------------------------------------------------------------------
        // Platform\Http — transport plumbing that is nevertheless tenant-owned.
        // ------------------------------------------------------------------
        'idempotency_key' => [
            'module' => 'Platform\\Http\\Idempotency',
            'tenant' => true,
            'deletion' => self::NA,
            'why' => 'API-O-1 / E9-2. The storage `Idempotency-Key` never had: API.md §6.1 records the header as required by P-5 and ROADMAP.md with nowhere in the schema to keep one, so IdempotencyKeyGuard demands it and its own docblock says it "does not deduplicate a replay". **SCOPED 2026-09-11 (API-14 as amended, §6.1/§6.2): this table serves the THREE BULK OPERATIONS and nothing else** -- CreateBulkArchival, CreateBulkRestoration, CreateBulkRoleAssignment, the operations whose Idem cell reads `store`. The reason is §2.2 and it is what makes the no-UPDATE grant sufficient rather than merely tolerable: a bulk response is {requested, applied}, known BEFORE commit, so the row can be written once inside the request\'s own transaction with its response already in hand. Every operation that creates an addressable row is out of scope by construction -- its response carries a server-minted id and a Location, neither of which exists until the insert has happened, so it would need the claim-then-fill shape the missing UPDATE forbids. Those operations take a client-supplied domain-column key on a live-row unique instead (`unique:<index>`), and the EIGHT that have neither mechanism today are `⛔ none` and blocked by E9-2 -- nine until 2026-09-11, when Version20260911000100 gave purchase_order the first such column and CreatePurchaseOrder left the list. This scoping narrows what the table is FOR; it changes no column, no grant and no policy, and no migration accompanies it. Keyed UNIQUE (tenant_id, operation_id, key) per §6.1, holding the request-body digest, the response status, the response-body digest and created_at. **It does not satisfy §6.2\'s byte-for-byte replay clause** — a digest is not a body, nothing stores the body, and adding a response_body column is a contract decision (size cap, PII at rest, retention) that is not DATA\'s; recorded as an open item rather than answered in a migration. Deletion class n/a rather than none, and it is the first tenant-scoped table to take it: n/a is "infrastructure tables nothing in the domain deletes", the domain never loads a row from here, and so the table carries no ORM entity — which is exactly the correlation SchemaOwnershipTest::everyTableTheDomainOwnsCarriesAMappedEntity derives (its expected set is every table whose class is not n/a). Classing it none would demand an entity, and an entity here would hand the domain a handle on transport plumbing. Retention is designed WITH SEC-O-3 rather than beside it, which means refusing both mechanisms §6.1 suggests: no partition (ADR-007 §A1.1; SCHEMA.md §13 records auth_attempt\'s as "structurally present and operationally inert"), no maintenance principal (SEC-O-1: none exists or can be created; ADR-007 §A1.4 rejects a purge-only one), and therefore no dated purge and no retention_column (PO\'s ruling of 2026-09-07; ROADMAP.md F-18 — a retention_column on a table with no authorised age purge is a declaration with no catalogue referent). The only authorised purge is the lifecycle purge predicated on tenant_id at tenant closure, on ADR-007 §A1.3\'s per-tenant loop under FORCE ROW LEVEL SECURITY, and idempotency_key_tenant_operation_key leads with tenant_id so it is index-supported with no second index. The residual is named, not solved: rows on a live tenant are never removed and this table has no audit value at all (§6.1\'s own words), so its growth is waste rather than a trade — an age window needs a PO ruling and does not get invented here.',
            // SELECT and nothing else, and each omission is a decision.
            // INSERT: revoked by ADR-002 Amendment 4 (2026-09-16) -- the store
            //         has no `src/` writer. IdempotencyKeyGuard requires and
            //         discards the header, and every built retry key is a
            //         domain column on its business table. The build that
            //         writes this table (the first bulk operation) grants INSERT
            //         in its own migration and edits this line.
            // UPDATE: §6.1 -- a replayed key is answered from the stored row,
            //         never rewritten. That also chooses the protocol: the row
            //         is written once with its response already known, so the
            //         claim-then-fill shape is unavailable and a concurrent
            //         duplicate is refused by 23505 plus rollback.
            // DELETE/TRUNCATE: a principal that can delete its own idempotency
            //         records can replay anything by deleting the row that
            //         refuses it -- the control removed by its holder. The
            //         purge is the owner's per-tenant loop.
            // erp_ai: no natural-language question is answered by request
            //         digests.
            // the pre-auth roles: no operation marked `Idem` is reachable
            //         before a JWT exists (API.md §7).
            'grants' => [DatabaseRoles::APP => ['SELECT']],
        ],

        // ------------------------------------------------------------------
        // SharedKernel\Reference — ADR-002 §6: no tenant_id, no RLS. Read-only
        // to the application; the owner maintains them.
        // ------------------------------------------------------------------
        'currency' => [
            'module' => 'SharedKernel\\Reference',
            'tenant' => false,
            'deletion' => self::NONE,
            'why' => 'ADR-002 §6 shared reference data. Never the tenant side of a composite foreign key.',
            'grants' => self::READ_ONLY_REFERENCE,
        ],
        'country' => [
            'module' => 'SharedKernel\\Reference',
            'tenant' => false,
            'deletion' => self::NONE,
            'why' => 'ADR-002 §6 shared reference data. ISO 3166-1 alpha-2, referenced by warehouse, customer, customer_address, sales_order (ship_to_country_code) and supplier.',
            'grants' => self::READ_ONLY_REFERENCE,
        ],
        'unit_of_measure' => [
            'module' => 'SharedKernel\\Reference',
            'tenant' => false,
            'deletion' => self::NONE,
            'why' => 'ADR-002 §6 shared reference data. Referenced by inventory_item; a tenant-local unit list would let two tenants disagree about what a kilogram is.',
            'grants' => self::READ_ONLY_REFERENCE,
        ],
        'permission' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => false,
            'deletion' => self::NONE,
            'why' => 'A permission is a code constant, identical for every tenant — duplicating the catalogue per tenant would let two tenants disagree about what `purchase_order.approve` means. Tenant-specific RBAC is role_permission, which is tenant-scoped.',
            'grants' => self::READ_ONLY_REFERENCE,
        ],

        // ------------------------------------------------------------------
        // Modules\UserManagement
        // ------------------------------------------------------------------
        'app_user' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'ADR-007: audit_log names actors, and an audit trail pointing at a vanished user is not an audit trail.',
            // erp_ai holds nothing: the query agent has no business reading a
            // credential table, and a column-scoped grant would still put
            // password_hash one widening away from a model-driven query.
            // erp_app: table-level SELECT (ORM hydration reads every column) and
            // COLUMN-level UPDATE only (H0, ADR-002 A4.0, 2026-09-22,
            // Version20260922000300). The columns are the union of the two ORM
            // flushes that write this table:
            //   LoginRecorder  -> recordLogin()         last_login_at, updated_at
            //   ChangePassword -> replacePasswordHash() password_hash, updated_at
            // Nothing else in `src/` writes a user, and nothing creates or deletes
            // one -- CreateUser and provisioning grant INSERT when they are built.
            // A NEW COLUMN HERE INHERITS SELECT BUT NO UPDATE: the table-level ACL
            // no longer carries UPDATE. So a writer grants its own columns with
            // itself (A4.4 duty 1): E-15g's deactivate/delete add
            // UPDATE (is_active, deleted_at), (e)-cred adds UPDATE (password_mac),
            // each to this list and to its own migration. A 42501 from such a
            // writer is fixed by granting that column, never by restoring
            // table-level UPDATE. password_hash stays writable, so H0 does not
            // close lockout or takeover through it (RESIDUAL_GAPS G-12).
            'grants' => [DatabaseRoles::APP => ['SELECT']],
            'column_grants' => [
                DatabaseRoles::AUTH => ['SELECT' => ['id', 'tenant_id', 'email', 'password_hash', 'is_active']],
                DatabaseRoles::APP => ['UPDATE' => ['password_hash', 'last_login_at', 'updated_at']],
            ],
        ],
        'role' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Audit entries name the role that granted an action; deleting the role outright would orphan that history.',
            // Roles slice 4, C1 (2026-10-07, Version20261007000100): CreateRole's one INSERT, COLUMN-level, exactly its four
            // columns. is_system stays unwritable by erp_app, so E-15h's "is_system set by any path other than provisioning"
            // is closed at the privilege level. Declared explicitly so erp_ai keeps exactly its inherited SELECT.
            // Roles slice 4, C2 (Version20261007000300): UpdateRole's one UPDATE, COLUMN-level, exactly its three SET columns
            // (name, description, updated_at). is_system stays un-updatable.
            // Roles slice 4, C3 (Version20261007000500): DeleteRole's soft delete adds deleted_at to the column UPDATE list
            // (`RoleDeletion::DELETE_ROLE` sets deleted_at and updated_at). No DELETE on role: the delete is soft.
            'grants' => [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']],
            'column_grants' => [DatabaseRoles::APP => [
                'INSERT' => ['id', 'tenant_id', 'name', 'description'],
                'UPDATE' => ['name', 'description', 'updated_at', 'deleted_at'],
            ]],
        ],
        'role_permission' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'A join row. Revoking a permission from a role is a removal, and the fact that it happened lives in audit_log.',
            // Roles slice 4, C1 (Version20261007000100): CreateRole's one multi-row INSERT, COLUMN-level. Never UPDATE (no
            // ON CONFLICT DO UPDATE); erp_ai keeps exactly its inherited SELECT. C2 (Version20261007000300): table DELETE for
            // UpdateRole's removed codes (`RoleUpdate::DELETE_CODES`) — a changed code is a delete and an insert, never UPDATE.
            'grants' => [DatabaseRoles::APP => ['SELECT', 'DELETE'], DatabaseRoles::AI => ['SELECT']],
            'column_grants' => [DatabaseRoles::APP => ['INSERT' => ['id', 'tenant_id', 'role_id', 'permission_code']]],
        ],
        'user_role' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'A join row. Removing a role from a user is a removal; that it happened lives in audit_log.',
            // Roles slice 2, C2 (2026-09-29, Version20260929000300): erp_app gains DELETE -- the project's FIRST
            // DELETE grant -- for its one caller, RoleRevocation (`DELETE FROM user_role WHERE id = ?`, under the
            // role-administration lock). Declared explicitly so erp_ai keeps exactly its inherited SELECT
            // (TENANT_READ_DEFAULT) and nothing moves with it. INSERT arrives column-level with AssignRole (C3).
            'grants' => [DatabaseRoles::APP => ['SELECT', 'DELETE'], DatabaseRoles::AI => ['SELECT']],
            // Roles slice 2, C3 (2026-09-30, Version20260930000100): INSERT arrives COLUMN-level, exactly the four
            // columns RoleAssignment's one INSERT names. Table-level INSERT stays false, so inserted_by (H1: DEFAULT
            // session_user, the database's record of which login wrote the grant) cannot be written by erp_app at all.
            // H1's limit (G-10): it tells an owner-process row from an erp_app row, never an API grant from a forged one.
            'column_grants' => [DatabaseRoles::APP => ['INSERT' => ['id', 'tenant_id', 'user_id', 'role_id']]],
        ],
        'role_grant_proposal' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'E-15e\'s pending state (roles slice 3 C1, `Version20261004000100`; SECURITY_MODEL.md §5.2.2; DDL reviewed by DATA, docs/session13/roles-s3-data.md): a proposed grant lives here, never in user_role, so no permission reader can confer it. A record of a security decision, never removed; no retention_column (SEC-O-1 / F-18 refuse the key) and growth is unbounded by design, which is why the list pages. **Only the decision columns are ever updatable, and only once:** the column UPDATE grant names decision columns only (subject, role, proposer, expiry and the code snapshot are immutable to the application after insert), and the invoker-rights trigger `role_grant_proposal_decided_is_final` refuses ANY update of a row that is no longer pending, so SQL as erp_app cannot reopen or relabel a decision (it does not narrow G-10). **Disclosed residual (DATA §1.1), inside G-10\'s bound:** proposed_at and expires_at are in the INSERT grant with no default, because both must come from one post-lock clock_timestamp() (S-11) — so SQL as erp_app can insert a proposal with a chosen proposed_at, i.e. one that never expires or expires at once (the window CHECK ties expires_at to it). Not worse than G-10: erp_app can insert user_role directly.',
            // ADR-002 A4.1 / RED M-3: exactly what C1's callers issue. SELECT: Get, List, Create's lock-time reads.
            // Declared explicitly so erp_ai gets NOTHING (§5.2.2) -- TENANT_READ_DEFAULT would give it SELECT.
            'grants' => [DatabaseRoles::APP => ['SELECT']],
            // INSERT: Create's one INSERT … SELECT, column for column (`status` excluded, so every inserted row is pending).
            // UPDATE: Create's lapse-on-touch flush (lapse()), C1; decided_by_user_id with Confirm's flush (confirm()), C2
            // (`Version20261005000100`); reason with Reject's flush (reject()), C3 (`Version20261005000200`). Never DELETE.
            'column_grants' => [
                DatabaseRoles::APP => [
                    'INSERT' => ['id', 'tenant_id', 'user_id', 'role_id', 'permission_codes', 'proposed_by_user_id', 'proposed_at', 'expires_at'],
                    'UPDATE' => ['status', 'decided_at', 'lapse_reason', 'updated_at', 'decided_by_user_id', 'reason'],
                ],
            ],
        ],

        // ------------------------------------------------------------------
        // Modules\AuditLog
        // ------------------------------------------------------------------
        'audit_log' => [
            'module' => 'Modules\\AuditLog',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'ARCHITECTURE S-9: append-only at the database level. Append-only enforced by a grant is a control; enforced by a convention is a hope.',
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT'], DatabaseRoles::AUTH_LOG => ['INSERT']],
        ],

        // ------------------------------------------------------------------
        // Modules\Warehouses
        // ------------------------------------------------------------------
        'warehouse' => [
            'module' => 'Modules\\Warehouses',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Purchase orders and fulfilments name a warehouse and must keep naming it after the site closes.',
            // Warehouses slice 1 C2 (2026-10-13, Version20261013000100): WarehouseWriter's one INSERT and one UPDATE, both
            // COLUMN-level, exactly the columns they name (ADR-002 A4.4's "exactly"). Not table-level: that would let SQL as
            // erp_app archive a warehouse (deleted_at) or set address_line/city/postal_code/is_active, which no operation
            // writes (Q-W4, RESIDUAL_GAPS.md G-36). The UPDATE grant also makes UpdateWarehouse's first-read FOR NO KEY UPDATE
            // and C3's parent FOR SHARE legal (A4.3 constraint 1). No deleted_at and no DELETE: DeleteWarehouse is out of
            // slice 1 (API-O-34, G-7). Declared explicitly so erp_ai keeps exactly its inherited SELECT.
            'grants' => [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']],
            'column_grants' => [DatabaseRoles::APP => [
                'INSERT' => ['id', 'tenant_id', 'code', 'name', 'country_code'],
                'UPDATE' => ['code', 'name', 'country_code', 'updated_at'],
            ]],
        ],
        'storage_location' => [
            'module' => 'Modules\\Warehouses',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'stock_movement records where stock physically moved; the bin can be retired but the history cannot.',
            // Warehouses slice 1 C3 (2026-10-13, Version20261013000300): StorageLocationWriter's one INSERT and one UPDATE, both
            // COLUMN-level, exactly the columns they name (ADR-002 A4.4's "exactly"). Not table-level: that would let SQL as
            // erp_app move a location to another warehouse (warehouse_id -- its stock would move with no movement) or archive
            // it (deleted_at), which no operation writes. No DELETE: DeleteStorageLocation is not built (G-27). The UPDATE
            // grant also makes UpdateStorageLocation's FOR UPDATE / FOR NO KEY UPDATE and StockDepleter's FOR KEY SHARE on the
            // locations it picks from legal (A4.3 constraint 1; 42501 without it, measured). Declared explicitly so erp_ai
            // keeps exactly its inherited SELECT.
            'grants' => [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']],
            'column_grants' => [DatabaseRoles::APP => [
                'INSERT' => ['id', 'tenant_id', 'warehouse_id', 'code', 'kind'],
                'UPDATE' => ['code', 'kind', 'updated_at'],
            ]],
        ],

        // ------------------------------------------------------------------
        // Modules\Inventory
        // ------------------------------------------------------------------
        'inventory_item' => [
            'module' => 'Modules\\Inventory',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'ADR-007 names it directly: a product appearing on a historical order line cannot be hard-deleted.',
            // ADR-002 A4: INSERT and UPDATE from ItemWriter (create, update; archive and restore are UPDATEs of
            // deleted_at). No DELETE: the row is soft-deleted, never removed.
            'grants' => self::APP_INSERT_UPDATE,
        ],
        'stock_level' => [
            'module' => 'Modules\\Inventory',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'A live aggregate of stock_movement, not a record of anything. Nothing references it and re-deriving it is always possible. **qty_reserved is a FORWARD DECLARATION (V2-R-22 Half 2, model A, ruled 2026-09-19 and applied by ConfirmSalesOrder).** The column exists and NOTHING IN src/ WRITES IT: `new StockLevel`, `StockLevel::class`, `adjustTo` and `getQtyReserved` each resolve to their declaration and to nothing else, measured five ways without an alternation. So every row carries 0, available-to-promise EQUALS qty_on_hand today, and any read that prints qty_on_hand - qty_reserved is printing qty_on_hand. Confirming a sales order writes no reservation. **Overselling is REACHABLE since CreateFulfilment shipped (2026-09-22), and model A was RENEWED on that premise by owner ruling R1 of sales step 5:** a shortage is refused at the pick, in domain terms (StockDepleter\'s conditional decrement, `stock_level.on_hand_covers_the_depletion`), never as negative stock and never as a 500, with no priority -- first to pick wins; the pick read shows each bin\'s qty_on_hand. It was revisited at ListStockLevels and RENEWED AGAIN on 2026-10-14 (owner ruling Q1): stock is visible per bin as on-hand, and no available or reserved figure is served anywhere. It is revisited at the first non-demo tenant, at DeleteWarehouse and at the first response serving an available or reserved figure (RESIDUAL_GAPS.md G-7). *(Until 2026-09-22 this read "ACCEPTED for now, unreachable until CreateFulfilment and ListStockLevels ship" -- true then, false from that commit.)* **The successor is DECIDED (owner ruling R4, 2026-09-22): model D at WAREHOUSE grain (derive demand from sales_order_line.quantity - quantity_fulfilled), over model E, and not built yet. "V2-C-12 first" is STRUCK -- measured false: sales_order.warehouse_id and storage_location.warehouse_id are both NOT NULL. Model D AS WRITTEN IN THE V2-R-22 DESIGN (bin grain) stays NOT RATIFIED: it subtracts warehouse-grain demand from bin-grain qty_on_hand, which is wrong per bin and, summed across bins, subtracts the demand once per bin.** ⚠ **The accidental backstop, measured 2026-09-19 (probe U-4b/U-3) and written down because it is load-bearing: this table carries exactly TWO checks, stock_level_reserved_sane (qty_reserved >= 0 AND qty_reserved <= qty_on_hand) and a uuid-v4 shape check. THERE IS NO STANDALONE CHECK (qty_on_hand >= 0).** With qty_reserved pinned at 0, `0 <= qty_on_hand` IS the non-negativity rule: (-1, 0) is refused 23514, with (5, 0) accepted as the positive control in the same probe. Two consequences follow. (1) **CreateFulfilment pre-checks on-hand and refuses in domain terms** — built 2026-09-22 as StockDepleter\'s conditional decrement (`... AND qty_on_hand >= ?`), whose row count is the answer, so an oversell reaches the user as a 409 and never as a raw 23514 from a constraint whose name is about reservations; that predicate assumes qty_reserved = 0 and must change in the commit that first writes the column. (2) **NOBODY MAY RELAX OR DROP stock_level_reserved_sane on the grounds that qty_reserved is unused**: doing so removes the only rule refusing negative stock in the whole schema. The residual, with its event triggers and its owner, is docs/RESIDUAL_GAPS.md G-7.',
            // ADR-002 A4: StockReceiver's INSERT … ON CONFLICT DO UPDATE needs both verbs. No DELETE: no `src/` path
            // removes a level, although the deletion class would allow one; the build that does grants it.
            'grants' => self::APP_INSERT_UPDATE,
        ],
        'stock_movement' => [
            'module' => 'Modules\\Inventory',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'An immutable fact: stock moved. UPDATE is revoked as well as DELETE — a corrected movement is a compensating movement, not an edit. The stock count\'s retry key (idempotency_key, request_digest; stock count C0) is NULLABLE because the table already holds receipt, shipment and return rows (85c08fb\'s warning) — the new columns inherit the table-level grants, so no grant changed.',
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT'], DatabaseRoles::AI => ['SELECT']],
        ],

        // ------------------------------------------------------------------
        // Modules\Suppliers
        // ------------------------------------------------------------------
        'supplier' => [
            'module' => 'Modules\\Suppliers',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'ADR-007: a supplier holding purchase orders cannot be hard-deleted. country_code laid at suppliers slice 1 C0 (NOT NULL, no default, FK country).',
            // Suppliers slice 1 C2 (2026-10-12, Version20261012000200): SupplierWriter's one INSERT and one UPDATE, both
            // COLUMN-level, exactly the columns they name (ADR-002 A4.4's "exactly"). Not table-level: that would let SQL as
            // erp_app archive a supplier (deleted_at) or set email/phone/is_active, which no operation writes (owner Q-S6,
            // RESIDUAL_GAPS.md G-36). The UPDATE grant also makes UpdateSupplier's first-read FOR NO KEY UPDATE legal (A4.3
            // constraint 1). C5 C2a (Version20261013000500): UPDATE (deleted_at) for RestoreSupplier; DeleteSupplier (C5 C2b)
            // reuses it — archiving is an UPDATE of deleted_at, and its FOR UPDATE row lock rests on the same column UPDATE
            // grant. No DELETE: the table is soft. Declared explicitly so erp_ai keeps exactly its inherited SELECT.
            // C5 C1 (owner Q-S2): Purchasing's supplier liveness read (PurchaseOrderWriter) takes FOR KEY SHARE, which is
            // legal only while erp_app holds UPDATE on at least one supplier column — narrowing this UPDATE grant to no
            // column 42501s every purchase-order create (GrantFootprintTest::theApplicationMayTakeThePartyRowLocksOnSupplier).
            'grants' => [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']],
            'column_grants' => [DatabaseRoles::APP => [
                'INSERT' => ['id', 'tenant_id', 'code', 'name', 'country_code', 'currency_code', 'payment_terms_days'],
                'UPDATE' => ['code', 'name', 'country_code', 'currency_code', 'payment_terms_days', 'updated_at', 'deleted_at'],
            ]],
        ],
        'supplier_product' => [
            'module' => 'Modules\\Suppliers',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'The supplier-side terms for one product. A purchase order line snapshots lead time and cost at order time, so removing the current terms strands nothing. currency_code is copied from the supplier when an entry is added (owner Q-S4 (a), suppliers slice 1 C3) and never rewritten; the supplier\'s currency cannot change while entries exist (Q-S4 (ii)).',
            // Suppliers slice 1 C3 (2026-10-12, Version20261012000500): SupplierProductWriter's one INSERT and one UPDATE, both
            // COLUMN-level, exactly the columns they name (ADR-002 A4.4's "exactly"). supplier_id, inventory_item_id and
            // currency_code are INSERT-only: SQL as erp_app cannot re-point an entry or relabel its currency. Declared
            // explicitly so erp_ai keeps exactly its inherited SELECT.
            // Suppliers slice 1 C4 (Version20261012000600): table DELETE for DeleteSupplierProduct's one statement
            // (SupplierProductWriter::DELETE; Postgres has no column DELETE) -- the third DELETE grant, after user_role and
            // role_permission. Hard delete: nothing references the table, and a placed order line keeps its snapshot.
            'grants' => [DatabaseRoles::APP => ['SELECT', 'DELETE'], DatabaseRoles::AI => ['SELECT']],
            'column_grants' => [DatabaseRoles::APP => [
                'INSERT' => ['id', 'tenant_id', 'supplier_id', 'inventory_item_id', 'supplier_sku', 'unit_cost', 'lead_time_days', 'min_order_quantity', 'currency_code'],
                'UPDATE' => ['supplier_sku', 'unit_cost', 'lead_time_days', 'min_order_quantity', 'updated_at'],
            ]],
        ],

        // ------------------------------------------------------------------
        // Modules\Purchasing
        // ------------------------------------------------------------------
        'purchase_order' => [
            'module' => 'Modules\\Purchasing',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Goods receipts reference it. A cancelled order is a status; a discarded draft is a soft delete, and both must survive in the audit trail. **Carries the first domain-column retry key (API-O-31 step 5, Version20260911000100):** idempotency_key, nullable, on the live-row unique purchase_order_tenant_idem_live. It is the mechanism CreatePurchaseOrder\'s §4.2 cell names, and it is client-supplied -- which order_number is not, being server-generated, which is why the document-number unique refuses no replay and this one will. Nullable is a statement about HISTORY: rows predating that migration have no key. The non-null demand is owed and deferred BY NAME to step 6 as purchase_order_idempotency_key_required, a BEFORE INSERT trigger -- not a CHECK, which would refuse every UPDATE of a historical row, and not NOT VALID, which exempts existing rows from the scan but not from later writes. Nothing wrote the column until step 6 (CreatePurchaseOrder, and Version20260911000200 installed that trigger): a column that exists is not a column that deduplicates. **Since D-B (Version20260911000300) the column holds the lower-case hex SHA-256 of the header, never the header** (CHECK purchase_order_idempotency_key_sha256), and request_digest beside it holds RequestDigest::of() of the request that created the row -- what a same-key replay is compared against, a different digest being idempotency-key-conflict (409). The two are both-or-neither (purchase_order_request_digest_with_key). erp_ai reads both by table-level inheritance and that is the accepted state (API-O-32 closed): neither value can be sent back as a key. ARCHITECTURE.md AI-4 forbids either in an AI tool DTO; no AI tool exists yet, and step 9 wrote the check (AiToolDtoTest) with the vacancy pinned in both directions rather than enjoyed -- it also found that AI-4 did not name idempotency_key.response_digest, which it now does.',
            // ADR-002 A4: INSERT from PurchaseOrderWriter; UPDATE from its raw updates, the ORM flushes of
            // PurchaseOrderApproval, PurchaseOrderCancellation and OrmPurchaseOrderReceiptStatus, **and
            // PurchaseOrderRowLock::SQL's FOR NO KEY UPDATE, which needs UPDATE even with no UPDATE statement** (constraint 1).
            // No DELETE: archival is a soft delete.
            'grants' => self::APP_INSERT_UPDATE,
        ],
        'purchase_order_line' => [
            'module' => 'Modules\\Purchasing',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Soft, and it was hard until RED pointed out what that cost: a soft-deleted order left live lines, and purchase_order_line_tenant_item_idx exists to answer "how much of this item is already on order" -- so the obvious query counted supply that would never arrive and suppressed a legitimate reorder. A database trigger cascades the parent\'s deletion, so the aggregate is consistent whatever writes it.',
            // ADR-002 A4: INSERT and UPDATE from PurchaseOrderWriter and GoodsReceiptWriter (quantity_received), **and
            // UPDATE is required by purchase_order_cascade_deletion**, which runs cascade_deletion_to_lines as the
            // invoker: a role with UPDATE on purchase_order must hold UPDATE and SELECT here (constraint 2,
            // GrantFootprintTest::aRoleThatCanUpdateACascadeParentCanUpdateAndReadItsChild). No DELETE.
            'grants' => self::APP_INSERT_UPDATE,
        ],
        'goods_receipt' => [
            'module' => 'Modules\\Purchasing',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'The event that created stock movements. It is corrected by a further receipt, never deleted. **Carries CreateGoodsReceipt\'s retry key (step 8-i, Version20260911000400):** idempotency_key holds the hex SHA-256 of the Idempotency-Key header and request_digest the RequestDigest::of() of the request that created the row, both NOT NULL (no receipt predates the columns, so unlike purchase_order there is no history for a NULL to describe), each CHECKed to 64 lower-case hex. The unique goods_receipt_idem_unique (tenant_id, idempotency_key) is TOTAL, because this table has no deleted_at: a receipt is never archived, so no archived row needs to release its key. erp_ai reads both by table-level inheritance, the state API-O-32 accepted for purchase_order.',
            // ADR-002 A4: SELECT and INSERT (GoodsReceiptWriter). UPDATE was declared for a void/correction path that is
            // not built; no `src/` path updates a receipt, so it is not granted. Its build grants it.
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT'], DatabaseRoles::AI => ['SELECT']],
        ],
        'goods_receipt_line' => [
            'module' => 'Modules\\Purchasing',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'Shares its parent\'s class: deleting a receipt line would unbalance the stock movements it produced.',
            // ADR-002 A4: SELECT and INSERT (GoodsReceiptWriter); no `src/` path updates a receipt line.
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT'], DatabaseRoles::AI => ['SELECT']],
        ],

        // ------------------------------------------------------------------
        // Modules\Customers
        // ------------------------------------------------------------------
        'customer' => [
            'module' => 'Modules\\Customers',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'ADR-007: a customer holding invoices cannot be hard-deleted.',
            // Customers slice 1 C2 (2026-10-09, Version20261009000300): CustomerWriter's one INSERT and one UPDATE, both
            // COLUMN-level, exactly the columns they name (ADR-002 A4.4's "exactly"). Not table-level: that would let SQL as
            // erp_app set credit_limit/is_active/email/phone, which no operation writes
            // (owner Q-C2, RESIDUAL_GAPS.md G-36). The UPDATE grant also makes UpdateCustomer's first-read FOR NO KEY UPDATE
            // legal (A4.3 constraint 1, SalesOrderRowLock's precedent). Narrowing it to no columns turns that lock into a
            // 42501. C5 C4a (Version20261014000200): UPDATE (deleted_at) for RestoreCustomer; DeleteCustomer (C5 C4b) reuses
            // it — archiving is an UPDATE of deleted_at, and its FOR UPDATE row lock (and the address writers' FOR KEY SHARE,
            // C4b) rests on the same column UPDATE grant. No DELETE: the table is soft. Declared explicitly so erp_ai keeps exactly its inherited SELECT.
            // C5 C3 (owner Q-2): Sales' customer liveness read (SalesOrderWriter::PARENT_LIVENESS_READS) takes FOR KEY SHARE,
            // which is legal only while erp_app holds UPDATE on at least one customer column — narrowing this UPDATE grant to
            // no column 42501s every sales-order create (GrantFootprintTest::theApplicationMayTakeThePartyRowLocksOnCustomer).
            'grants' => [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']],
            'column_grants' => [DatabaseRoles::APP => [
                'INSERT' => ['id', 'tenant_id', 'code', 'name', 'country_code', 'currency_code', 'payment_terms_days'],
                'UPDATE' => ['code', 'name', 'country_code', 'currency_code', 'payment_terms_days', 'updated_at', 'deleted_at'],
            ]],
        ],
        'customer_contact' => [
            'module' => 'Modules\\Customers',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'A person at the account. Orders record the ordering user, not the contact row, so nothing references it. **Carries CreateCustomerContact\'s retry key (customers slice 2 C0, Version20261019000100), on customer_address\'s reasoning:** idempotency_key and request_digest are NOT NULL, CHECKed to 64 lower-case hex, and customer_contact_idem_unique (tenant_id, idempotency_key) is TOTAL because the table has no deleted_at. The partial unique customer_contact_one_primary (tenant_id, customer_id) WHERE is_primary holds the one-primary rule (owner Q-1 (a)). That migration grants erp_app nothing; the writer\'s column INSERT lands with it (C1).',
            // Declared explicitly so erp_ai gets NOTHING (customers slice 2 S-2) -- TENANT_READ_DEFAULT would give it SELECT.
            // Version20261019000100 REVOKEs the SELECT the Output 2 migration granted; GrantFootprintTest holds the two together.
            'grants' => [DatabaseRoles::APP => ['SELECT']],
        ],
        'customer_address' => [
            'module' => 'Modules\\Customers',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'Deliberately hard, and it is the reason sales_order carries ship-to snapshot columns: an order pointing at a live address row would silently rewrite where a past shipment went every time the customer moves. **Carries CreateCustomerAddress\'s retry key (customers slice 1 C0, Version20261009000200), on fulfilment\'s reasoning:** idempotency_key and request_digest are NOT NULL, CHECKed to 64 lower-case hex, and customer_address_idem_unique (tenant_id, idempotency_key) is TOTAL because the table has no deleted_at. That migration grants nothing; the writer\'s column INSERT lands with it (C3).',
            // Customers slice 1 C3 (2026-10-09, Version20261009000500): CustomerAddressWriter's one INSERT and one UPDATE, both
            // COLUMN-level, exactly the columns they name (ADR-002 A4.4's "exactly"). region is in neither (not accepted, not
            // served; defaulted). idempotency_key and request_digest are INSERT-only, so SQL as erp_app cannot re-point a stored
            // key at another request. No DELETE: DeleteCustomerAddress is not built. Declared explicitly so erp_ai keeps exactly
            // its inherited SELECT.
            'grants' => [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']],
            'column_grants' => [DatabaseRoles::APP => [
                'INSERT' => ['id', 'tenant_id', 'customer_id', 'kind', 'address_line', 'city', 'postal_code', 'country_code', 'idempotency_key', 'request_digest'],
                'UPDATE' => ['kind', 'address_line', 'city', 'postal_code', 'country_code', 'updated_at'],
            ]],
        ],

        // ------------------------------------------------------------------
        // Modules\Sales
        // ------------------------------------------------------------------
        'sales_order' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Invoices and fulfilments reference it, and a cancelled order is a status rather than a removal; only an unissued draft is ever discarded. **Carries CreateSalesOrder\'s retry key (V2-R-11, Version20260919000100):** idempotency_key holds the hex SHA-256 of the Idempotency-Key header and request_digest the RequestDigest::of() of the request that created the row, each CHECKed to 64 lower-case hex. Both are NOT NULL, on goods_receipt\'s reasoning rather than purchase_order\'s: no sales order predates the columns -- nothing in src/ has ever written one -- so there is no history for a NULL to describe, and a nullable column plus a deferred BEFORE INSERT trigger would have been a promise carried by a sentence in a design document. NOT NULL also makes the unique a real constraint on every live row, where two NULL keys would not collide. The unique sales_order_tenant_idem_live (tenant_id, idempotency_key) is PARTIAL on deleted_at IS NULL, unlike goods_receipt\'s total one, because this table soft-deletes and a discarded draft must stop occupying its key. It is what CreateSalesOrder\'s §4.2 cell names, and it is client-supplied -- which order_number is not, being server-minted, which is why sales_order_tenant_number_live refuses no replay and this one will. erp_ai reads both by table-level inheritance, the state API-O-32 accepted for purchase_order. **This migration grants nothing:** a new column inherits the table-level ACL (measured 2026-09-19, positive control SELECT=false), so the columns arrive readable and not writable, and the INSERT is owed by the commit that ships the writer (ADR-002 A4.1).',
            // ADR-002 A4: INSERT from SalesOrderWriter::create (Version20260919000200, ruling R-B), and UPDATE from
            // SalesOrderConfirmation::confirm, which writes status, ordered_at and updated_at, and from the
            // SalesOrderRowLock FOR NO KEY UPDATE it takes first (A4.3 constraint 1) -- Version20260919000300.
            // No DELETE: archival is a soft delete, and nothing archives yet.
            // **The standing note this entry carried since 2026-09-19 has now FIRED, and it is kept as the record of
            // what fired it:** *the first build that grants ANY UPDATE on sales_order -- a confirm or cancel, not only
            // a soft delete -- grants UPDATE on sales_order_line in the same migration*, because
            // sales_order_cascade_deletion runs as the invoker and a table-level UPDATE makes SET deleted_at
            // reachable. ConfirmSalesOrder is that build. It was MEASURED rather than taken on the sentence's word:
            // the migration was written parent-only first and
            // GrantFootprintTest::aRoleThatCanUpdateACascadeParentCanUpdateAndReadItsChild answered with exactly one
            // violation, 'erp_app can UPDATE sales_order but lacks UPDATE on sales_order_line'.
            'grants' => self::APP_INSERT_UPDATE,
        ],
        'sales_order_line' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Soft for the same reason as purchase_order_line: sales_order_line_tenant_item_idx is ADR-004\'s demand-history input, and demand from a discarded order is not demand. Cascaded from the order by trigger. **Carries CreateSalesOrderLine\'s retry key (V2-R-12, Version20260921000100):** idempotency_key holds the hex SHA-256 of the Idempotency-Key header and request_digest the RequestDigest::of() of the single-line request that created the row, each CHECKed to 64 lower-case hex. Both are NULLABLE, which INVERTS sales_order\'s decision two entries up and is forced rather than preferred: SalesOrderWriter::insertLineInside() has written this table since c761ba8 as CreateSalesOrder\'s nested lines[], and N lines of one request share ONE Idempotency-Key, so NOT NULL plus (tenant_id, idempotency_key) would make the second line of every multi-line create collide with the first. purchase_order_line is the worked example here, not sales_order. A third CHECK, sales_order_line_request_digest_with_key, carries both-or-neither, which NOT NULL said one table over and nothing would say here. The unique sales_order_line_tenant_idem_live (tenant_id, idempotency_key) is PARTIAL on deleted_at IS NULL because this table soft-deletes; that partiality is also what makes V2-R-12b\'s hazard real on this side -- an archived line releases its key, a live line may take it, and a restore then collides (23505, measured). RestoreSalesOrderLine, which owns its statement, raises it and translates it. RestoreSalesOrder\'s cascade trigger no longer sets every line live: since Version20260921000200 it revives only the lines archived WITH the order (the owner\'s Q3 ruling), so a replacement line on the same order cannot collide -- but the key is tenant-wide, so a key reused on ANOTHER order\'s line still can. **This migration grants nothing:** a new column inherits the table-level ACL (M-1, re-measured 2026-09-21 with a SELECT=false positive control and a NULL attacl), and the writer\'s INSERT is already held.',
            // ADR-002 A4: INSERT from SalesOrderWriter::create, which writes the nested lines in the order's own
            // transaction (Version20260919000200), and UPDATE -- granted first for A4.4 duty 3 (the SECURITY INVOKER
            // sales_order_cascade_deletion fires an UPDATE of this table as whoever updates the parent, and
            // ConfirmSalesOrder's Version20260919000300 gave erp_app that parent UPDATE) and now ALSO issued by src/.
            // ⚠ This comment said "No sales writer issues an UPDATE of this table" until 2026-09-22, and that was
            // already false (RED R-9): SalesOrderWriter issues three -- line edit, archive and restore -- and
            // FulfilmentWriter adds a fourth (quantity_fulfilled). No DELETE: the class is soft and nothing removes a
            // line.
            'grants' => self::APP_INSERT_UPDATE,
        ],
        'fulfilment' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'The event that depleted stock (ARCHITECTURE W-3). Corrected by a return, never deleted. **Carries CreateFulfilment\'s retry key (Version20260922000100, sales step 5 C2):** idempotency_key holds the hex SHA-256 of the Idempotency-Key header and request_digest the RequestDigest::of() of the request that created the row, each CHECKed to 64 lower-case hex. Both are NOT NULL, on goods_receipt\'s reasoning and not purchase_order_line\'s: no fulfilment predates the columns -- nothing has ever been able to write one -- and each request writes ONE header row, its lines carrying no key. The unique fulfilment_idem_unique (tenant_id, idempotency_key) is TOTAL, like goods_receipt\'s, because this table has no deleted_at. **And shipped_by_user_id, NOT NULL (owner ruling R6, V2-R-23):** who declared the goods shipped, the authenticated caller, never a request field; the attribution a read shows; since B-4a (2026-09-29) fulfilment.created names the shipper on the trail too. **The migration grants nothing:** a new column inherits the table-level ACL (M-1), so the columns arrive readable and not writable.',
            // ADR-002 A4: INSERT from FulfilmentWriter (CreateFulfilment), granted by Version20260922000200 in the
            // commit that ships the writer. No UPDATE: the writer never updates or row-locks a fulfilment (A4.4 duty
            // 1's "exactly"). Never DELETE (deletion class none).
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT'], DatabaseRoles::AI => ['SELECT']],
        ],
        'fulfilment_line' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'Shares its parent\'s class: deleting a fulfilment line would unbalance the stock movements it produced.',
            // ADR-002 A4: INSERT from FulfilmentWriter, one row per request line (Version20260922000200). No UPDATE,
            // never DELETE.
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT'], DatabaseRoles::AI => ['SELECT']],
        ],
        'invoice' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'An invoice is voided, never deleted — in most jurisdictions deleting one is unlawful. It bills exactly one fulfilment (`fulfilment_id`, D-A) and names its issuer (`issued_by_user_id`, owner ruling Q5), and at most one LIVE invoice exists per fulfilment (`invoice_tenant_fulfilment_not_void`, partial on `status <> \'void\'`, so a void releases the shipment for a corrected re-issue) — sales step 6 C2. IssueInvoice (sales step 6 C3, `Version20260928000200`) holds a COLUMN-level INSERT on exactly the ten columns its raw-SQL writer names — never `status`, `paid_at`, `issued_at`, `created_at` or `updated_at`, which default — and table-level INSERT stays false; a 42501 from that writer is fixed by removing the column from its statement, never by widening the grant. Status and payment state change by UPDATE: PayInvoice (sales step 6 C5, `Version20260928000400`) holds a COLUMN-level UPDATE on exactly `status`, `paid_at`, `paid_by_user_id` and `updated_at` — its ORM flush\'s SET list — and table-level UPDATE stays false; ReverseInvoicePayment (C6, `Version20260928000600`) adds `payment_reversed_at` — B′\'s "ever reversed", set once by `COALESCE(payment_reversed_at, now())` — and the void grants only what it adds, in its own migration (ADR-002 Amendment 4). `paid_by_user_id` (C5) names who recorded the payment, NULL unless paid (`invoice_paid_by_matches_status`).',
            'grants' => [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']],
            // InvoiceIssuer's one INSERT, column for column; and (C5, Version20260928000400) the SET list of
            // PayInvoice's flush. GrantFootprintTest holds hand-typed copies of both (INVOICE_COLUMNS_THE_ISSUER_INSERTS,
            // INVOICE_COLUMNS_THE_CALLERS_WRITE) and runs each statement as erp_app, so a joint widening of this list
            // and a migration still fails.
            'column_grants' => [
                DatabaseRoles::APP => [
                    'INSERT' => ['id', 'tenant_id', 'fulfilment_id', 'sales_order_id', 'customer_id', 'invoice_number', 'currency_code', 'total_amount', 'due_at', 'issued_by_user_id'],
                    'UPDATE' => ['status', 'paid_at', 'paid_by_user_id', 'updated_at', 'payment_reversed_at'],
                ],
            ],
        ],
        'sales_return' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'A customer return is a document, never edited and never deleted (OI-2 R3: deletion class none); a return recorded in error is not undone (owner ruling Q-K, G-21). Sales step 7 R1 (`Version20261001000100`). **Carries CreateSalesReturn\'s retry key on fulfilment\'s reasoning:** each request writes ONE header row, so idempotency_key and request_digest are NOT NULL, CHECKed to 64 lower-case hex, and sales_return_idem_unique (tenant_id, idempotency_key) is TOTAL because the table has no deleted_at. `invoice_id` stamps the fulfilment\'s LIVE invoice when the return was recorded (design F-2: no clock decides "before issue"); `deducts` is owner ruling Q-L (a′), decided once at record time, and `sales_return_deducts_only_before_invoice` holds that a deducting return names no invoice; `recorded_by_user_id` is SoD-5\'s evidence (the `shipped_by_user_id` precedent).',
            // ADR-002 A4: SELECT for the readers (GetSalesReturn, the 201 read-back, IssueInvoice's deduction) and, since
            // R2 (`Version20261001000300`), INSERT from SalesReturnWriter -- TABLE-level, `fulfilment`'s precedent: the
            // writer names every column but the two timestamps. erp_ai SELECT: SEC S-1, 2026-09-30 (output9-sales-8-sec.md
            // §1) -- declared explicitly here, never left to TENANT_READ_DEFAULT. Never UPDATE, never DELETE.
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT'], DatabaseRoles::AI => ['SELECT']],
        ],
        'sales_return_line' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'Shares its parent\'s class: a return line is the record of stock that came back (ReturnStock, W-3\'s third edge, R2), and deleting it would unbalance the movement it produced. One line per fulfilment line per return (sales_return_line_once_per_line). That the line\'s fulfilment line belongs to the return\'s fulfilment is an application rule, not a constraint: a three-column FK is refused (DoctrineMappingTest), so G-15 widens to name its forgery by SQL.',
            // ADR-002 A4: as sales_return -- INSERT from SalesReturnWriter since R2, one row per request line. erp_ai
            // SELECT: SEC S-1, 2026-09-30. Never UPDATE, never DELETE.
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT'], DatabaseRoles::AI => ['SELECT']],
        ],
        'credit_note' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'A credit note is the only reduction of an issued invoice (OI-2), never edited and never deleted; one issued in error is not undone (owner ruling Q-K, G-21). Sales step 7 C0 (`Version20261001000500`). **Carries IssueCreditNote\'s retry key on fulfilment\'s reasoning:** one header row per request, so idempotency_key and request_digest are NOT NULL, CHECKed to 64 lower-case hex, and credit_note_idem_unique (tenant_id, idempotency_key) is TOTAL because the table has no deleted_at. `credit_note_total_positive` (total_amount > 0) is load-bearing beyond hygiene: with no UPDATE/DELETE/TRUNCATE for any application role, no write can LOWER an invoice\'s credited sum, which is what keeps TB-4\'s Credited segment monotonic (`IA.md` §5.4 ³; `CreditedSegmentLapseTest`). `sales_return_id` is NULL only for an adjustment (owner ruling Q-A = B′), tied to the reason by `credit_note_return_iff_goods_returned`.',
            // ADR-002 A4: SELECT for C0's readers (GetInvoice/ListInvoices' amount_credited/amount_due/credited_in_full,
            // PayInvoice's invoice.has_an_amount_due, ListInvoiceLines, SalesReturnReader's credited terms). erp_ai SELECT:
            // SEC S-1, 2026-09-30 (PC-8 maps it to invoice.view) -- declared explicitly here, never left to
            // TENANT_READ_DEFAULT. Never UPDATE, never DELETE (CreditedSegmentLapseTest).
            'grants' => [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']],
            // C1 (`Version20261001000700`): CreditNoteWriter's one INSERT … SELECT, column for column -- never `issued_at`,
            // `created_at` or `updated_at`, which default, so SQL as erp_app cannot backdate an issue. Table-level INSERT
            // stays false (IssueInvoice's precedent).
            'column_grants' => [
                DatabaseRoles::APP => [
                    'INSERT' => ['id', 'tenant_id', 'invoice_id', 'sales_return_id', 'credit_note_number', 'reason', 'currency_code', 'total_amount', 'issued_by_user_id', 'idempotency_key', 'request_digest'],
                ],
            ],
        ],
        'credit_note_line' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'Shares its parent\'s class: a credit-note line is what was credited against one shipped line, and the invoice\'s amount credited is their sum. One line per shipped line per note (credit_note_line_once_per_line); `amount >= 0` because a telescoped line amount may be 0 (design §4.4 step 8b). That the shipped line belongs to the invoice\'s fulfilment, the quantity bounds and total = Σ amount are application rules, not constraints (a three-column FK is refused), so G-15 widens to name their forgery by SQL.',
            // ADR-002 A4: SELECT since C0; since C1 (`Version20261001000700`) a TABLE-level INSERT from CreditNoteWriter --
            // it names every column but the two timestamps (sales_return_line's precedent). erp_ai SELECT: SEC S-1.
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT'], DatabaseRoles::AI => ['SELECT']],
        ],
        'adjustment_decision' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'Which returned units an adjustment credit (a credit without a return) already covered: `same` with a quantity, `distinct`, or the owner\'s `reversal` of one row (G-29 / G-30 part 3, `Version20261005000400`; owner ruling 2026-10-05, D1 (a), D1b (s-iii), D2 (b)). Append-only, never edited and never deleted: a wrong decision is undone by appending a reversal, never by an UPDATE or DELETE (`SEC` §1.3 condition 4). The key columns are nullable with a both-or-neither CHECK (the V2-R-12 form): the adjust path\'s rows ride the credit note\'s key. One live `distinct` per pair per `decision_round` (a partial unique; the round is `1 + reversed distincts`, read under InvoiceRowLock). Same shipped line, an adjustment line, and the return stamped for the adjustment\'s invoice are application rules (three-column FKs are refused), so G-15 widens to name their forgery by SQL.',
            // ADR-002 A4: SELECT for the readers (SalesReturnReader's named terms, the decision members, CreditNoteReader's
            // `return_decisions`). erp_ai SELECT: SEC S-3, 2026-10-05 (PC-8 maps it to invoice.view; `recorded_by_user_id`
            // joins M-15's list) -- declared explicitly here, never left to TENANT_READ_DEFAULT. Never UPDATE, never DELETE.
            'grants' => [DatabaseRoles::APP => ['SELECT'], DatabaseRoles::AI => ['SELECT']],
            // `Version20261005000400`: the union of the two writers' INSERTs (AdjustmentDecisionWriter names all eleven;
            // CreditNoteWriter::adjust()'s `distinct` rows, C2, name nine) -- never `created_at`, which defaults.
            'column_grants' => [
                DatabaseRoles::APP => [
                    'INSERT' => ['id', 'tenant_id', 'sales_return_line_id', 'credit_note_line_id', 'kind', 'quantity', 'reverses_id', 'decision_round', 'recorded_by_user_id', 'idempotency_key', 'request_digest'],
                ],
            ],
        ],
    ];

    /**
     * The declaring set, table => column. Deliberately a map and not a list: the
     * column name is the half a check must resolve against the server.
     *
     * @return array<string, string>
     */
    public static function retentionColumns(): array
    {
        $declared = [];
        foreach (self::TABLES as $name => $spec) {
            if (isset($spec['retention_column'])) {
                $declared[$name] = $spec['retention_column'];
            }
        }
        ksort($declared);

        return $declared;
    }

    /** @return list<string> */
    public static function tenantScopedTables(): array
    {
        return self::filterByTenancy(true);
    }

    /** @return list<string> */
    public static function nonTenantTables(): array
    {
        return self::filterByTenancy(false);
    }

    /** @return list<string> */
    public static function softDeletedTables(): array
    {
        $tables = [];
        foreach (self::TABLES as $name => $spec) {
            if (self::SOFT === $spec['deletion']) {
                $tables[] = $name;
            }
        }
        sort($tables);

        return $tables;
    }

    /** @return list<string> */
    public static function allTables(): array
    {
        $tables = array_keys(self::TABLES);
        sort($tables);

        return $tables;
    }

    /**
     * The privileges $role is expected to hold on $table at table level.
     *
     * @return list<string>
     */
    public static function grantsFor(string $table, string $role): array
    {
        $spec = self::TABLES[$table] ?? throw new \InvalidArgumentException(
            "Table '$table' is not declared in SchemaManifest::TABLES.");

        $grants = $spec['grants'] ?? ($spec['tenant'] ? self::TENANT_READ_DEFAULT : []);

        return $grants[$role] ?? [];
    }

    /**
     * Columns of $table on which $role is expected to hold $privilege, over and
     * above any table-level grant.
     *
     * @return list<string>
     */
    public static function columnGrantsFor(string $table, string $role, string $privilege): array
    {
        return self::TABLES[$table]['column_grants'][$role][$privilege] ?? [];
    }

    /** @return list<string> */
    private static function filterByTenancy(bool $tenant): array
    {
        $tables = [];
        foreach (self::TABLES as $name => $spec) {
            if ($spec['tenant'] === $tenant) {
                $tables[] = $name;
            }
        }
        sort($tables);

        return $tables;
    }
}
