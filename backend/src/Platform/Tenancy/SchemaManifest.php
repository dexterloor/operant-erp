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
 * The grants are the load-bearing part. ALTER DEFAULT PRIVILEGES (A1.1) is what
 * stops R-34, and it is also what makes S-9's `REVOKE` fragile and what hands
 * every future non-tenant table to erp_app. The resolution is the same shape
 * S-9 already needed and generalised: default privileges still fire, then the
 * migration revokes everything on every table and re-grants exactly what is
 * declared below, last. Nothing is inherited by accident, and the assertion is
 * exhaustive rather than spot-check.
 *
 * @see ADR-002 §1, §5, §6, Amendment 1 §A1.1/§A1.3
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

    /** The default footprint on a tenant-scoped table. Overridden per table below. */
    private const CRUD = [DatabaseRoles::APP => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], DatabaseRoles::AI => ['SELECT']];

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
     * to CRUD for a tenant-scoped one.
     *
     * @var array<string, array{module: string, tenant: bool, deletion: string, why: string, grants?: array<string, list<string>>, column_grants?: array<string, array<string, list<string>>>, rls_exempt?: true}>
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
            'grants' => [DatabaseRoles::RATE_LIMIT => ['SELECT', 'INSERT', 'UPDATE']],
        ],
        'auth_ip_budget' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => false,
            'deletion' => self::NA,
            'why' => 'SEC-O-16, ruled 2026-09-09. E-21\'s second pre-auth budget — 300 attempts per fixed 15-minute window per `ip_prefix` — had nowhere to live. It cannot live in `auth_attempt`: E-9 and `API.md` §7.1 step 2 both consume it *before* the `auth_attempt` upsert, so it would be read after the write it exists to authorise, and every row of that table names a workspace because `slug_normalised` leads its primary key. A reserved sentinel slug was rejected on a fact about the column rather than on taste — `auth_attempt.slug_normalised` is client-supplied and deliberately shape-free (that is what makes a slug scrape loud, ADR-006 §2.3), so a client could send the reserved value and choose whether its request was metered at 100 or at 300. Keyed `(ip_prefix, window_start)` and nothing else, so no client-supplied value selects, shares or displaces the row that meters it. Partitioned like `auth_attempt` so retention is DROP PARTITION by the owner: an unpartitioned counter table is SEC-O-1 filed again on the day it is created, and it is what would force a DELETE grant onto the one principal whose narrowness is the point of it existing.',
            'grants' => [DatabaseRoles::RATE_LIMIT => ['SELECT', 'INSERT', 'UPDATE']],
        ],
        'auth_lockout' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => false,
            'deletion' => self::NA,
            'why' => 'ADR-006 §6.6 / ARCHITECTURE S-14. Lockout state lives here, keyed (slug_normalised, email_hash), and never on app_user: a pre-auth UPDATE on a tenant-owned table from an attacker-chosen context is O-9 with a DoS attached.',
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
            'why' => 'API-O-1 / E9-2. The storage `Idempotency-Key` never had: API.md §6.1 records the header as required by P-5 and ROADMAP.md with nowhere in the schema to keep one, so IdempotencyKeyGuard demands it and its own docblock says it "does not deduplicate a replay". Keyed UNIQUE (tenant_id, operation_id, key) per §6.1, holding the request-body digest, the response status, the response-body digest and created_at. **It does not satisfy §6.2\'s byte-for-byte replay clause** — a digest is not a body, nothing stores the body, and adding a response_body column is a contract decision (size cap, PII at rest, retention) that is not DATA\'s; recorded as an open item rather than answered in a migration. Deletion class n/a rather than none, and it is the first tenant-scoped table to take it: n/a is "infrastructure tables nothing in the domain deletes", the domain never loads a row from here, and so the table carries no ORM entity — which is exactly the correlation SchemaOwnershipTest::everyTableTheDomainOwnsCarriesAMappedEntity derives (its expected set is every table whose class is not n/a). Classing it none would demand an entity, and an entity here would hand the domain a handle on transport plumbing. Retention is designed WITH SEC-O-3 rather than beside it, which means refusing both mechanisms §6.1 suggests: no partition (ADR-007 §A1.1; SCHEMA.md §13 records auth_attempt\'s as "structurally present and operationally inert"), no maintenance principal (SEC-O-1: none exists or can be created; ADR-007 §A1.4 rejects a purge-only one), and therefore no dated purge and no retention_column (PO\'s ruling of 2026-09-07; ROADMAP.md F-18 — a retention_column on a table with no authorised age purge is a declaration with no catalogue referent). The only authorised purge is the lifecycle purge predicated on tenant_id at tenant closure, on ADR-007 §A1.3\'s per-tenant loop under FORCE ROW LEVEL SECURITY, and idempotency_key_tenant_operation_key leads with tenant_id so it is index-supported with no second index. The residual is named, not solved: rows on a live tenant are never removed and this table has no audit value at all (§6.1\'s own words), so its growth is waste rather than a trade — an age window needs a PO ruling and does not get invented here.',
            // SELECT and INSERT, and each omission is a decision.
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
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT']],
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
            'why' => 'ADR-002 §6 shared reference data. ISO 3166-1 alpha-2, referenced by warehouse and customer_address.',
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
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT', 'UPDATE', 'DELETE']],
            'column_grants' => [DatabaseRoles::AUTH => ['SELECT' => ['id', 'tenant_id', 'email', 'password_hash', 'is_active']]],
        ],
        'role' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Audit entries name the role that granted an action; deleting the role outright would orphan that history.',
        ],
        'role_permission' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'A join row. Revoking a permission from a role is a removal, and the fact that it happened lives in audit_log.',
        ],
        'user_role' => [
            'module' => 'Modules\\UserManagement',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'A join row. Removing a role from a user is a removal; that it happened lives in audit_log.',
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
        ],
        'storage_location' => [
            'module' => 'Modules\\Warehouses',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'stock_movement records where stock physically moved; the bin can be retired but the history cannot.',
        ],

        // ------------------------------------------------------------------
        // Modules\Inventory
        // ------------------------------------------------------------------
        'inventory_item' => [
            'module' => 'Modules\\Inventory',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'ADR-007 names it directly: a product appearing on a historical order line cannot be hard-deleted.',
        ],
        'stock_level' => [
            'module' => 'Modules\\Inventory',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'A live aggregate of stock_movement, not a record of anything. Nothing references it and re-deriving it is always possible.',
        ],
        'stock_movement' => [
            'module' => 'Modules\\Inventory',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'An immutable fact: stock moved. UPDATE is revoked as well as DELETE — a corrected movement is a compensating movement, not an edit.',
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT'], DatabaseRoles::AI => ['SELECT']],
        ],

        // ------------------------------------------------------------------
        // Modules\Suppliers
        // ------------------------------------------------------------------
        'supplier' => [
            'module' => 'Modules\\Suppliers',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'ADR-007: a supplier holding purchase orders cannot be hard-deleted.',
        ],
        'supplier_product' => [
            'module' => 'Modules\\Suppliers',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'The supplier-side terms for one product. A purchase order line snapshots lead time and cost at order time, so removing the current terms strands nothing.',
        ],

        // ------------------------------------------------------------------
        // Modules\Purchasing
        // ------------------------------------------------------------------
        'purchase_order' => [
            'module' => 'Modules\\Purchasing',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Goods receipts reference it. A cancelled order is a status; a discarded draft is a soft delete, and both must survive in the audit trail.',
        ],
        'purchase_order_line' => [
            'module' => 'Modules\\Purchasing',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Soft, and it was hard until RED pointed out what that cost: a soft-deleted order left live lines, and purchase_order_line_tenant_item_idx exists to answer "how much of this item is already on order" -- so the obvious query counted supply that would never arrive and suppressed a legitimate reorder. A database trigger cascades the parent\'s deletion, so the aggregate is consistent whatever writes it.',
        ],
        'goods_receipt' => [
            'module' => 'Modules\\Purchasing',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'The event that created stock movements. It is corrected by a further receipt, never deleted.',
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT', 'UPDATE'], DatabaseRoles::AI => ['SELECT']],
        ],
        'goods_receipt_line' => [
            'module' => 'Modules\\Purchasing',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'Shares its parent\'s class: deleting a receipt line would unbalance the stock movements it produced.',
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT', 'UPDATE'], DatabaseRoles::AI => ['SELECT']],
        ],

        // ------------------------------------------------------------------
        // Modules\Customers
        // ------------------------------------------------------------------
        'customer' => [
            'module' => 'Modules\\Customers',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'ADR-007: a customer holding invoices cannot be hard-deleted.',
        ],
        'customer_contact' => [
            'module' => 'Modules\\Customers',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'A person at the account. Orders record the ordering user, not the contact row, so nothing references it.',
        ],
        'customer_address' => [
            'module' => 'Modules\\Customers',
            'tenant' => true,
            'deletion' => self::HARD,
            'why' => 'Deliberately hard, and it is the reason sales_order carries ship-to snapshot columns: an order pointing at a live address row would silently rewrite where a past shipment went every time the customer moves.',
        ],

        // ------------------------------------------------------------------
        // Modules\Sales
        // ------------------------------------------------------------------
        'sales_order' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Invoices and fulfilments reference it, and a cancelled order is a status rather than a removal; only an unissued draft is ever discarded.',
        ],
        'sales_order_line' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::SOFT,
            'why' => 'Soft for the same reason as purchase_order_line: sales_order_line_tenant_item_idx is ADR-004\'s demand-history input, and demand from a discarded order is not demand. Cascaded from the order by trigger.',
        ],
        'fulfilment' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'The event that depleted stock (ARCHITECTURE W-3). Corrected by a return, never deleted.',
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT', 'UPDATE'], DatabaseRoles::AI => ['SELECT']],
        ],
        'fulfilment_line' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'Shares its parent\'s class: deleting a fulfilment line would unbalance the stock movements it produced.',
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT', 'UPDATE'], DatabaseRoles::AI => ['SELECT']],
        ],
        'invoice' => [
            'module' => 'Modules\\Sales',
            'tenant' => true,
            'deletion' => self::NONE,
            'why' => 'An invoice is voided, never deleted — in most jurisdictions deleting one is unlawful. UPDATE is retained because status and payment state change.',
            'grants' => [DatabaseRoles::APP => ['SELECT', 'INSERT', 'UPDATE'], DatabaseRoles::AI => ['SELECT']],
        ],
    ];

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

        $grants = $spec['grants'] ?? ($spec['tenant'] ? self::CRUD : []);

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
