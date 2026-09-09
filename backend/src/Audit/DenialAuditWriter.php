<?php

declare(strict_types=1);

namespace App\Audit;

use App\Tenancy\SchemaManifest;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * E-3 and E-3a — every permission denial that reaches the API is written to
 * `audit_log`, synchronously, and the write is capped.
 *
 * ===========================================================================
 * E-3: what the row carries
 * ===========================================================================
 * *"the actor, the required permission code, the resource type and id, and the
 * source IP."* `API.md` §9.2 puts the write on **`erp_app`**, which holds
 * `SELECT, INSERT` on `audit_log` and no `UPDATE` (S-9) — exactly what the
 * bounded read-back below needs and no more.
 *
 * ===========================================================================
 * E-3a: the cap, and the one statement that implements it
 * ===========================================================================
 * At most **two** rows per `(tenant_id, actor_user_id, permission_code)` per UTC
 * day. The first denial writes E-3's row unchanged. On any later denial for the
 * same key the writer reads back **at most two** matching rows, range-bounded to
 * the current UTC day and stopping at the second match — never a `COUNT(*)`,
 * never unbounded in time. If exactly one exists and its `subject_id` differs
 * from the resource now being denied, one further row is written naming that
 * resource; otherwise nothing.
 *
 * What is kept is *"this actor was denied this permission on at least two
 * distinct resources today"* — the difference between a stale client retrying one
 * bookmark and an actor walking a resource space. What is lost is the exact
 * attempt count and the full resource list, and E-3a says so in terms.
 *
 * ---------------------------------------------------------------------------
 * `tenant_id` is left to RLS, and that is a plan-shape decision
 * ---------------------------------------------------------------------------
 * `READ_BACK` names three of the key's four columns and lets the policy supply
 * `tenant_id`, which is how every read on this connection is written. Writing
 * `tenant_id = ?` here as well is *correct* and produces a plan PostgreSQL folds
 * into a `Result` node carrying `One-Time Filter:` — a substring of `Filter:`,
 * which is what §9.4a's own wording asserts the absence of. The two are different
 * keys on different nodes in `FORMAT JSON`, and
 * `DenialCapStorageTest::theExplicitTenantPredicateFormAddsAOneTimeFilterNode`
 * is where that was measured rather than assumed.
 *
 * ---------------------------------------------------------------------------
 * `event_type` is in the WHERE clause and it is not decoration
 * ---------------------------------------------------------------------------
 * `audit_log_denial_cap_idx` is **partial**. PostgreSQL proves a partial index
 * usable from the query's own restriction clauses and does not consult table
 * `CHECK` constraints to do it, so a read-back that omits the predicate cannot
 * use the index at all and falls back to scanning the actor's whole day. Removing
 * this line is §9.4a's second mutation.
 *
 * ---------------------------------------------------------------------------
 * The id list is never written to `metadata`. This is absolute.
 * ---------------------------------------------------------------------------
 * E-3a: it is unbounded, and it would rebuild inside `metadata` the vector the
 * cap closes — which PC-11's second clause independently forbids. `metadata` is
 * written as a literal empty object and this class has no parameter that could
 * carry anything else.
 *
 * ---------------------------------------------------------------------------
 * A failed audit write is a failed request, not a silent success
 * ---------------------------------------------------------------------------
 * Nothing here is caught. `API.md` §9.2: audit is synchronous and its failure
 * must roll back the change it records. There is no change to roll back on a
 * denial — the request is being refused either way — so the consequence of a
 * failure here is a 500 rather than a 403, and that is the right trade: a 403
 * that was not recorded is E-3 quietly not holding, which is precisely the state
 * this class exists to end.
 */
final readonly class DenialAuditWriter
{
    /**
     * §9.4a's read-back, in the form whose plan shape was proved on this server:
     * `Index Scan using audit_log_denial_cap_idx`, all four key columns in
     * `Index Cond`, and no `Filter` on the scan node.
     *
     * `DenialCapStorageTest` proves that shape against a 10,000-row same-actor,
     * same-day fixture, and `PermissionDenialCapTest` runs **this constant** — not
     * a transcription of it — through the same measurement, so the statement the
     * application issues and the statement whose plan was proved are one object.
     */
    public const READ_BACK = <<<'SQL'
        SELECT id, subject_id FROM audit_log
        WHERE actor_user_id = ?::uuid
          AND permission_code = ?
          AND event_type = ?
          AND occurred_at >= date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'
          AND occurred_at <  date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' + interval '1 day'
        LIMIT 2
        SQL;

    /** E-3a's ceiling. Read by the writer *and* by the probe's `LIMIT`, so the two cannot drift. */
    public const DAILY_ROWS_PER_KEY = 2;

    public function __construct(private TenantContext $tenants, private Connection $app)
    {
    }

    /**
     * Scalars rather than an `Identity`, so `App\Audit` does not acquire a
     * dependency on `App\Api`. The trail is written for several callers and the
     * HTTP one is only the first.
     *
     * `$subjectId` is **caller-supplied and unresolved** — E-1 puts the
     * permission check before the row is loaded, which is exactly why E-3a
     * refuses to key its cap on it. It is written to the row and never to the key.
     */
    public function record(
        string $tenantId,
        string $actorUserId,
        string $actorLabel,
        string $permissionCode,
        ?string $subjectId,
        ?string $ipAddress,
    ): void {
        $subjectType = $this->subjectTypeFor($permissionCode);
        // `audit_log_subject_pair`: both or neither. A collection operation has
        // no id in its path, so a denial on `ListItems` names no row — which is
        // honest rather than lossy, and is what makes the second row's
        // "a *different* resource was tried" signal mean something.
        $subjectId = null === $subjectType ? null : $this->canonicalUuid($subjectId);
        $subjectType = null === $subjectId ? null : $subjectType;

        $this->tenants->run($tenantId, function () use (
            $tenantId, $actorUserId, $actorLabel, $permissionCode, $subjectType, $subjectId, $ipAddress
        ): void {
            $seen = $this->app->fetchAllAssociative(
                self::READ_BACK,
                [$actorUserId, $permissionCode, AuditEvent::AUTHZ_PERMISSION_DENIED],
            );

            if (self::DAILY_ROWS_PER_KEY <= \count($seen)) {
                return;
            }

            if ([] !== $seen && $this->canonicalUuid((string) ($seen[0]['subject_id'] ?? '')) === $subjectId) {
                // One row already stands for this key and it names the same
                // resource. The second row exists to record that a *second*
                // resource was tried, and this is not one.
                return;
            }

            $this->app->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, permission_code, metadata)
                VALUES
                    (gen_random_uuid(), ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, ?::inet, ?, '{}'::jsonb)
                SQL, [
                    $tenantId,
                    AuditEvent::AUTHZ_PERMISSION_DENIED,
                    $actorUserId,
                    $actorLabel,
                    $subjectType,
                    $subjectId,
                    $ipAddress,
                    $permissionCode,
                ]);
        });
    }

    /**
     * `AuditEvent::SUBJECT_RESOLVED_AT_WRITE` puts `authz.permission.denied`'s
     * subject outside the catalogue: *"it is whatever resource the request was
     * denied, resolved at write time."* This is that resolution.
     *
     * E-3a's own rejection of `subject_type` as a cap key states the derivation:
     * *"a permission code names its resource type very nearly one-to-one
     * (`purchase_order.approve` → `purchase_order`)"*. The prefix is taken and
     * then **checked against `SchemaManifest::TABLES`**, because *very nearly* is
     * not *always*: `ai_assistant.use`, `forecast.view` and `tenant_settings.*`
     * name no table, and `subject_type` is documented as a table name
     * (`AuditEvent`, and `audit_log_tenant_subject_idx` is keyed on it). A code
     * whose prefix names no table writes no subject pair rather than a value that
     * would make the index lie.
     */
    private function subjectTypeFor(string $permissionCode): ?string
    {
        $resource = strstr($permissionCode, '.', true);

        return \is_string($resource) && \array_key_exists($resource, SchemaManifest::TABLES) ? $resource : null;
    }

    /** Null for anything that is not a UUID, so two spellings of one id never read as two resources. */
    private function canonicalUuid(?string $candidate): ?string
    {
        return \is_string($candidate) && Uuid::isValid($candidate)
            ? Uuid::fromString($candidate)->toRfc4122()
            : null;
    }
}
