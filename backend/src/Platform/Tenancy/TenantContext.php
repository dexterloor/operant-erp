<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-002 §3 -- the only supported way to establish tenant context.
 *
 * Three things are load-bearing, each matching a documented way of getting this
 * wrong (ADR-002 §C-3):
 *
 *   explicit transaction   PgBouncer in pool_mode=transaction hands the backend
 *                          back at COMMIT. Outside a transaction there is no unit
 *                          of work to scope the setting to.
 *   set_config(..., true)  the third argument is is_local. `SET LOCAL` cannot bind
 *                          a parameter; this is the injection-safe equivalent. A
 *                          bare `SET` persists on the pooled backend and leaks
 *                          into the next tenant's request.
 *   parameter binding      the tenant id never reaches the SQL text.
 *
 * ---------------------------------------------------------------------------
 * Re-entrancy
 * ---------------------------------------------------------------------------
 * set_config(..., true) is scoped to the OUTERMOST transaction, not to the
 * innermost block. A nested run() for a different tenant therefore does not end
 * when its block returns -- it repoints the remainder of the outer block, and
 * an UPDATE or DELETE with no tenant predicate (the normal shape here, because
 * RLS is supposed to supply the predicate) then rewrites the other tenant's rows
 * and reports success.
 *
 * The rule is: re-entrant for the same tenant, refused for a different one.
 *
 *   same tenant       joins the outer context. No transaction, no set_config, no
 *                     filter change. This is the ordinary shape once services
 *                     compose, and it stays free.
 *   different tenant  refused before any statement is issued. A tenant switch
 *                     inside one transaction is never legitimate under
 *                     one-transaction-one-tenant, and it is exactly the
 *                     dangerous case.
 *   foreign
 *   transaction       refused. If something else opened the transaction, the
 *                     setting binds to it and outlives this block just as a
 *                     nested switch would, and no counter inside this class can
 *                     see that. The connection's nesting level can.
 *
 * Restoring the previous value after an inner block was considered and rejected:
 * the restore has to happen in a finally, and if the inner block failed on a
 * database error the transaction is aborted, so the restore statement itself
 * fails with 25P02 -- measured:
 *   ERROR: current transaction is aborted, commands ignored until end of transaction block
 * The restore is unavailable in precisely the case that needs it most.
 */
final class TenantContext
{
    private ?string $currentTenantId = null;

    private int $depth = 0;

    private ?string $lastCompletedTenantId = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @template T
     *
     * @param callable(EntityManagerInterface): T $work
     *
     * @return T
     */
    public function run(string $tenantId, callable $work): mixed
    {
        // The value is parameter-bound below, so this is not what stops
        // injection. It enforces ADR-002's assumption that tenant_id is a UUID,
        // and it fails here rather than inside the ::uuid cast in the policy,
        // where the error would be far less obvious.
        if (!Uuid::isValid($tenantId)) {
            throw new \InvalidArgumentException('Tenant id must be a UUID.');
        }

        // Canonicalised before any comparison. Two spellings of one UUID must
        // not read as a tenant switch, and no spelling of two different UUIDs
        // may ever read as a match.
        $tenantId = Uuid::fromString($tenantId)->toRfc4122();

        if (null !== $this->currentTenantId) {
            if ($this->currentTenantId !== $tenantId) {
                throw TenantContextViolation::tenantSwitchInsideOneTransaction($this->currentTenantId, $tenantId);
            }

            return $this->joinCurrentContext($tenantId, $work);
        }

        $nestingLevel = $this->em->getConnection()->getTransactionNestingLevel();
        if ($nestingLevel > 0) {
            throw TenantContextViolation::foreignTransactionAlreadyOpen($nestingLevel);
        }

        return $this->openContext($tenantId, $work);
    }

    /**
     * The same rule, on a connection this class does not own.
     *
     * -----------------------------------------------------------------------
     * Why this is here and not in the pre-authentication code that needs it
     * -----------------------------------------------------------------------
     * `API.md` §7.1 establishes tenant context twice on connections that have no
     * entity manager: step 4 on **`erp_auth`**, and step 8 on **`erp_authlog`**.
     * `run()` cannot serve either — it goes through `$this->em`, which is pinned
     * to `app` — so without this method the pre-authentication path would issue
     * `set_config` by hand, in two files, outside `src/Platform/Tenancy/`.
     *
     * `TenantContextContainmentTest` states the rule that forbids that in terms:
     * *"Exactly one production file may write tenant context"*, and *"no
     * allow-list, because an allow-list here is the thing that erodes."* This
     * method is the alternative to eroding it — the sanctioned writer grows a
     * second entry point rather than the rule growing an exception, and the
     * containment test needed no edit.
     *
     * -----------------------------------------------------------------------
     * The caller owns the transaction, and that is the one real difference
     * -----------------------------------------------------------------------
     * `run()` opens its own, because a unit of work over mapped entities has no
     * other natural boundary. Step 4's does not: ADR-006 §2.1 step 2 is a
     * **single** transaction reading `tenant`, then setting the context the
     * *first read produced*, then reading `app_user` — so the context cannot be
     * established before the transaction opens, and splitting it in two would
     * change the statement sequence V-8 requires to be identical on every miss
     * path.
     *
     * What is enforced instead is the part that actually protects anything: a
     * transaction **must** already be open, or `set_config(..., true)` binds to
     * an implicit single-statement transaction and is gone before the next
     * statement — the failure mode is silent, and under PgBouncer's transaction
     * pooling the connection goes back to the pool between the two reads. The
     * value is bound, never interpolated, exactly as in `openContext()`.
     *
     * No re-entrancy tracking, no identity map, no `TenantFilter`: those three
     * exist for the ORM, and a pre-authentication read is DBAL against a
     * principal that holds column grants and no mapping.
     */
    public function establishOn(Connection $connection, string $tenantId): void
    {
        // Parameter-bound below, so this is not what stops injection. It enforces
        // ADR-002's assumption that a tenant id is a UUID, and it fails here
        // rather than inside the ::uuid cast in the policy.
        if (!Uuid::isValid($tenantId)) {
            throw new \InvalidArgumentException('Tenant id must be a UUID.');
        }

        if (0 === $connection->getTransactionNestingLevel()) {
            throw TenantContextViolation::noTransactionToBindTo();
        }

        $connection->executeStatement(
            "SELECT set_config('app.tenant_id', ?, true)",
            [Uuid::fromString($tenantId)->toRfc4122()],
        );
    }

    /**
     * @template T
     *
     * @param callable(EntityManagerInterface): T $work
     *
     * @return T
     */
    private function openContext(string $tenantId, callable $work): mixed
    {
        $filters = $this->em->getFilters();
        $filterWasEnabled = $filters->isEnabled(TenantFilter::NAME);

        // Doctrine's identity map is keyed on the entity id alone, because the
        // ORM identifier is scalar. Managed entities belonging to the previous
        // tenant must not survive into this unit of work: flushing one under a
        // new context would be rejected by WITH CHECK, and a find() that hits the
        // identity map would not reach the database at all, so RLS would never
        // see the question.
        if (null !== $this->lastCompletedTenantId && $this->lastCompletedTenantId !== $tenantId) {
            $this->em->clear();
        }

        $this->currentTenantId = $tenantId;
        $this->depth = 1;

        try {
            return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($tenantId, $work, $filters, $filterWasEnabled): mixed {
                $em->getConnection()->executeStatement(
                    "SELECT set_config('app.tenant_id', ?, true)",
                    [$tenantId],
                );

                if ($filterWasEnabled) {
                    $filters->getFilter(TenantFilter::NAME)->setParameter('tenant_id', $tenantId);
                }

                return $work($em);
            });
        } finally {
            $this->depth = 0;
            $this->currentTenantId = null;
            $this->lastCompletedTenantId = $tenantId;

            // The setting is transaction-local and dies with the COMMIT. The
            // filter parameter is not: it lives on the EntityManager, so without
            // this it would keep answering for a tenant whose transaction has
            // ended. Recreating the filter drops its parameters.
            if ($filterWasEnabled) {
                $filters->disable(TenantFilter::NAME);
                $filters->enable(TenantFilter::NAME);
            }
        }
    }

    /**
     * @template T
     *
     * @param callable(EntityManagerInterface): T $work
     *
     * @return T
     */
    private function joinCurrentContext(string $tenantId, callable $work): mixed
    {
        // Joining means trusting state this call did not set. One cheap round
        // trip turns that into something checked: if anything issued its own SET
        // or set_config on this connection, the join refuses rather than
        // inheriting a tenant nobody chose.
        $actual = $this->em->getConnection()->fetchOne("SELECT current_setting('app.tenant_id', true)");
        if ($actual !== $tenantId) {
            throw TenantContextViolation::contextChangedUnderneathUs(
                $tenantId,
                \is_string($actual) ? $actual : null,
            );
        }

        ++$this->depth;

        try {
            // Deliberately not wrapInTransaction: this block shares the outer
            // transaction and its fate. Doctrine nests without savepoints by
            // default anyway, so a savepoint here would be a promise the
            // configuration does not keep.
            return $work($this->em);
        } finally {
            --$this->depth;
        }
    }
}
