<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * API-O-1 / `DELIVERY_LEDGER.md` E9-2 — `idempotency_key`, the storage
 * `Idempotency-Key` never had.
 *
 * ---------------------------------------------------------------------------
 * What was actually missing
 * ---------------------------------------------------------------------------
 * `API.md` §6.1 states it as a finding rather than a feature: `ROADMAP.md` and
 * P-5 both require the header, and the committed schema had nowhere to keep
 * one. `Platform\Http\Idempotency\IdempotencyKeyGuard` requires the header,
 * bounds it at 255 bytes, and its own docblock says it *"does not deduplicate a
 * replay"* — so every operation marked `Idem` in §4.2 advertised a contract the
 * system could not honour. §6.1's interim rule kept the shipped slice honest by
 * leaning on preconditions (a repeat of `CreateItem` hits
 * `inventory_item_tenant_sku_live`), and it named the operations that have no
 * precondition to lean on: the six create-a-child operations and the three bulk
 * ones. E9-2 blocks them until this table exists.
 *
 * ---------------------------------------------------------------------------
 * The shape is §6.1's, and one part of it does not do what §6.2 asks
 * ---------------------------------------------------------------------------
 * §6.1 asks `DATA` for: a tenant-scoped table, RLS'd like every other, keyed
 * `UNIQUE (tenant_id, operation_id, key)`, holding the **request-body digest,
 * the response status, the response-body digest and `created_at`**, with
 * `SELECT, INSERT` to `erp_app` and no `UPDATE`. That is what is built here,
 * column for column.
 *
 * **It cannot satisfy §6.2's second bullet.** §6.2 says a replay with the same
 * key and the same body *"returns the stored response, **byte for byte**, with
 * the original status"*. A digest is not a body. What this table supports is:
 * recognising a replay, refusing a same-key-different-body replay
 * (`idempotency-key-conflict`, 409), and returning the original **status**. It
 * cannot reproduce the original response body, because nothing stores it.
 *
 * That is left as it is rather than closed by inventing a `response_body`
 * column, and the reason is not tidiness: storing response bodies at rest is a
 * contract decision with a size cap, a PII question and a retention consequence
 * attached, and none of the three is `DATA`'s to make. It is recorded as an
 * open item against §6.2 rather than answered here. **A pass that ships the
 * replay path owes either that column or an amended §6.2 — this migration is
 * not evidence that the byte-for-byte clause is implementable.**
 *
 * ---------------------------------------------------------------------------
 * No `UPDATE`, and what that silently chooses
 * ---------------------------------------------------------------------------
 * §6.1's *"no `UPDATE` — a replayed key is answered from the stored row, never
 * rewritten"* is a grant, and it is also a protocol decision that is worth
 * stating because a reader will otherwise discover it while implementing:
 *
 *   - **Unavailable:** claim the key with a placeholder row on the way in, then
 *     fill in the response on the way out. That needs `UPDATE`.
 *   - **What is left:** the row is written **once, with its response already
 *     known**, inside the request's own transaction (§2.2). Two concurrent
 *     requests carrying one key therefore both execute, and the loser's
 *     `INSERT` raises `23505` at commit — which aborts its whole transaction,
 *     so the second child document is never committed either. The duplicate is
 *     refused by rollback rather than by a lock, and re-reading the winner's
 *     row afterwards is a separate transaction.
 *
 * Correctness survives that; throughput and latency under a genuine concurrent
 * retry do not, and this is the place that says so.
 *
 * ---------------------------------------------------------------------------
 * Retention — designed **with** SEC-O-3, which means both of §6.1's suggestions
 * are refused
 * ---------------------------------------------------------------------------
 * §6.1 says retention *"is a `DROP PARTITION` or a dated purge by the
 * maintenance principal"*. Both halves of that sentence have since been ruled
 * against, on this project, with evidence:
 *
 *   - **No partition.** ADR-007 Amendment 1 §A1.1 rejects partitioning for
 *     `audit_log` and records why the guard that should police the resulting
 *     wide primary key cannot see it. `SCHEMA.md` §13 records `auth_attempt`'s
 *     partitioning as *"structurally present and operationally inert"*:
 *     partitioning bounds nothing without an operator, and none is assigned.
 *   - **No maintenance principal.** `SECURITY_MODEL.md` Appendix A, SEC-O-1:
 *     no such principal exists or can be created — rotation needs *ownership*,
 *     which no grant confers, and an owner-equivalent seventh role is refused
 *     by `SchemaGuardTest::theApplicationRolesHoldNoRoleMembershipsAtAll`.
 *     ADR-007 §A1.4 additionally rejects a purge-only principal: a non-owner is
 *     bound by `ENABLE ROW LEVEL SECURITY` regardless of `FORCE`, so it runs
 *     the same per-tenant loop for no benefit.
 *   - **No dated purge, and therefore no `retention_column`.** SEC-O-3 is
 *     `CLOSED-BY-EVENTS` on `PO`'s ruling of 2026-09-07: no age-based purge, no
 *     window, no `retention_column`, no partition drop. The only authorised
 *     purge is a **lifecycle purge predicated on `tenant_id`** at tenant
 *     closure, on ADR-007 §A1.3's per-tenant loop under `FORCE ROW LEVEL
 *     SECURITY`. `ROADMAP.md` F-18 states the general form: a `retention_column`
 *     on a table with no authorised age purge is a declaration with no
 *     catalogue referent, which is the defect class §A1.3 exists to refuse.
 *
 * So: **this table joins the `tenant_id` lifecycle purge and declares nothing.**
 * `idempotency_key_tenant_operation_key` leads with `tenant_id`, so the purge's
 * `DELETE ... WHERE tenant_id = ?` is index-supported without a second index.
 * Zero DDL beyond the table; no manifest key; nothing that has no consumer.
 *
 * **The residual, named rather than solved.** Rows on a *live* tenant are never
 * removed, and unlike `audit_log` this table has no audit value at all (§6.1's
 * own words) — so its growth is pure waste rather than a trade. Matching the
 * ruling is the instruction; inventing a second retention model beside it is
 * what this project calls drift. An age window here would need `PO` to rule it,
 * and it is left open, not closed by omission.
 *
 * ---------------------------------------------------------------------------
 * Deletion class `n/a`, and it is the first tenant-scoped table to take it
 * ---------------------------------------------------------------------------
 * `n/a` is *"infrastructure tables nothing in the domain deletes"* — until now
 * the migration ledger, the queue and the three pre-authentication counters,
 * all of them non-tenant. This table is tenant-scoped and still infrastructure:
 * it records that an HTTP request was seen. The domain never loads a row from
 * it, so it carries **no ORM entity**, which is exactly the correlation
 * `SchemaOwnershipTest::everyTableTheDomainOwnsCarriesAMappedEntity` derives —
 * its expected set is every table whose class is not `n/a`. Classing it `none`
 * would put it in that set and demand an entity, and an entity here would hand
 * the domain a handle on transport plumbing.
 *
 * The absence of `DELETE`/`TRUNCATE` is therefore **not** asserted by
 * `GrantFootprintTest::theDeletionClassDeterminesTheGrantFootprint`, which
 * covers class `none`. It is asserted by
 * `::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares`, which is
 * bidirectional over `grants` and covers every table, and behaviourally by
 * `IdempotencyKeyStorageTest`.
 *
 * ---------------------------------------------------------------------------
 * Grants: `SELECT, INSERT` to `erp_app`, and nothing anywhere else
 * ---------------------------------------------------------------------------
 *   no UPDATE           — §6.1, and the protocol note above.
 *   no DELETE/TRUNCATE  — the purge is the owner's per-tenant loop. An
 *                         application principal that can delete its own
 *                         idempotency records can replay any request it likes
 *                         by deleting the row that refuses it, which is the
 *                         whole control removed by its holder.
 *   erp_ai holds nothing — the NL query agent has no business reading request
 *                         digests, and there is no natural-language question
 *                         this table answers.
 *   the pre-auth roles hold nothing — no operation marked `Idem` is reachable
 *                         before a JWT exists (§7).
 *
 * @see docs/API.md §6.1, §6.2 — the requested shape and the key semantics
 * @see docs/DELIVERY_LEDGER.md E9-2 — the gate this closes
 * @see docs/adr/ADR-007-deletion-policy.md Amendment 1 §A1.1, §A1.3, §A1.4
 * @see docs/SECURITY_MODEL.md Appendix A — SEC-O-1, SEC-O-3
 */
final class Version20260910000200 extends AbstractMigration
{
    /** ADR-002 §2 as corrected by A1.2 — the empty string, not NULL, is what `DISCARD ALL` leaves. */
    private const POLICY = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    /**
     * D-12, copied in shape from `Version20260906000100::UUID_V4` rather than
     * imported: a migration that reaches into another migration's constant
     * changes meaning when that file is edited, and a recorded migration must
     * mean today what it meant when it ran.
     */
    private const UUID_V4 = "substring(id::text, 15, 1) = '4' AND substring(id::text, 20, 1) IN ('8', '9', 'a', 'b')";

    public function getDescription(): string
    {
        return 'API-O-1 / E9-2: idempotency_key — the storage Idempotency-Key never had';
    }

    public function up(Schema $schema): void
    {
        $this->createTable();
        $this->applyRowLevelSecurity();
        $this->applyGrants();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS idempotency_key CASCADE');
    }

    /**
     * The column list, and the two things about it that are not obvious.
     *
     * **`id` is a surrogate this table does not need.** `(tenant_id,
     * operation_id, key)` keys it. It is here because every tenant-scoped table
     * in this schema carries one and three catalogue-derived guards assume it:
     * the composite-FK target `UNIQUE (tenant_id, id)`
     * (`SchemaManifestTest::theCompositeForeignKeyTargetIsTotalOnEveryTenantScopedTable`),
     * the UUIDv4 `CHECK`, and the single-column opaque primary key
     * (`SchemaGuardTest::theOnlyUniquenessNotScopedByTenantIsTheOpaqueSurrogatePrimaryKey`).
     * §6.2's fourth bullet — *"the key is not a row id and is never used as
     * one"* — is what this arrangement buys: the client's string is a plain
     * column, and the row's identity is a value the client never sees.
     *
     * **The digests are `bytea`, fixed at 32 octets, not `text`.** A hex or
     * base64 digest is a string that compares equal to nothing in particular
     * and whose length constraint documents an encoding rather than a hash. The
     * `CHECK` on octet length is what makes "this column holds a SHA-256" a
     * statement the database enforces rather than a convention the writer
     * follows; a truncated or mis-encoded digest fails at the `INSERT` instead
     * of silently weakening the conflict test in §6.2's third bullet.
     *
     * `key` is `text` with an octet-length ceiling rather than `varchar(255)`:
     * §6.2's cap is **255 bytes**, and `varchar(n)` counts characters, so a
     * 255-character multibyte key would be accepted here and refused by
     * `IdempotencyKeyGuard::MAXIMUM_KEY_BYTES` — two limits disagreeing about
     * what they measure. The lower bound is 1 because the guard already treats
     * the empty string as a missing header, and a store that accepts what the
     * guard refuses is a gap in the same rule.
     */
    private function createTable(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE idempotency_key (
                id              uuid        NOT NULL,
                tenant_id       uuid        NOT NULL,
                operation_id    text        NOT NULL,
                key             text        NOT NULL,
                request_digest  bytea       NOT NULL,
                response_status smallint    NOT NULL,
                response_digest bytea       NOT NULL,
                created_at      timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT idempotency_key_pkey              PRIMARY KEY (id),
                CONSTRAINT idempotency_key_tenant_id_id_key  UNIQUE (tenant_id, id),
                CONSTRAINT idempotency_key_tenant_operation_key UNIQUE (tenant_id, operation_id, key),
                CONSTRAINT idempotency_key_key_bytes         CHECK (octet_length(key) BETWEEN 1 AND 255),
                CONSTRAINT idempotency_key_operation_id_set  CHECK (octet_length(operation_id) BETWEEN 1 AND 128),
                CONSTRAINT idempotency_key_request_sha256    CHECK (octet_length(request_digest) = 32),
                CONSTRAINT idempotency_key_response_sha256   CHECK (octet_length(response_digest) = 32),
                CONSTRAINT idempotency_key_status_is_http    CHECK (response_status BETWEEN 100 AND 599)
            )
            SQL);
    }

    /**
     * The same four statements every tenant-scoped table gets.
     *
     * There is no backfill: the table records requests, and no request that
     * predates it can be replayed.
     */
    private function applyRowLevelSecurity(): void
    {
        // ENABLE alone leaves the owner unfiltered (ADR-002 §C-2), and
        // migrations run as the owner. FORCE is also what makes the tenant
        // lifecycle purge a per-tenant loop rather than one global DELETE.
        $this->addSql('ALTER TABLE idempotency_key ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE idempotency_key FORCE  ROW LEVEL SECURITY');

        $policy = self::POLICY;
        $this->addSql(<<<SQL
            CREATE POLICY tenant_isolation ON idempotency_key
                USING      ($policy)
                WITH CHECK ($policy)
            SQL);

        // Without it, set_config with any well-formed UUID records keys against
        // a workspace that was never provisioned -- and the row would then
        // never be reached by the per-tenant purge loop, which iterates the
        // registry.
        $this->addSql('ALTER TABLE idempotency_key ADD CONSTRAINT idempotency_key_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // D-12. Type `uuid` is not enough: a UUIDv7 is also type uuid and is
        // time-ordered, which would reopen the enumeration channel while
        // passing a type check.
        $this->addSql('ALTER TABLE idempotency_key ADD CONSTRAINT idempotency_key_id_is_uuid_v4 CHECK ('.self::UUID_V4.')');
    }

    private function applyGrants(): void
    {
        // REVOKE first, then GRANT. `ALTER DEFAULT PRIVILEGES` is withdrawn
        // today but `Version20260906000100::down()` restores it, and a table
        // created while it stands is granted to erp_app before anyone decides
        // whether it should be. This makes the footprint of a new table
        // independent of what was standing when it was created.
        $this->addSql('REVOKE ALL ON idempotency_key FROM erp_app, erp_ai, erp_auth, erp_authlog, erp_ratelimit');

        // The whole footprint. See the class docblock for what each omission
        // buys; `GrantFootprintTest::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares`
        // asserts this is exact in both directions.
        $this->addSql('GRANT SELECT, INSERT ON idempotency_key TO erp_app');
    }
}
