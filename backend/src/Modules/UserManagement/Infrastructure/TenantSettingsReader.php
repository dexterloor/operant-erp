<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use App\Modules\UserManagement\Application\Contract\WorkspaceNotConfigured;
use App\Modules\UserManagement\Application\Contract\WorkspaceSettings;
use App\Modules\UserManagement\Infrastructure\Entity\TenantSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one implementation of `WorkspaceSettings`.
 *
 * ---------------------------------------------------------------------------
 * Why it reads through the entity manager and not through a `Connection`
 * ---------------------------------------------------------------------------
 * Two reasons, and the second is the load-bearing one.
 *
 * **A-10.** Only `Platform\Tenancy` may depend on `Doctrine\DBAL\Connection`.
 * The ten existing edges that do are a registered deviation, and the register's
 * own rule is that it holds deviations pre-dating the ruleset rather than new
 * ones.
 *
 * **The transaction.** `set_config('app.tenant_id', …, true)` is
 * transaction-local and the Doctrine tenant filter's parameter lives on the
 * entity manager for the duration of `TenantContext::run()`. This class holds
 * the *same* `EntityManagerInterface` instance that `TenantContext` wraps, so
 * a read issued from inside a caller's work block is inside that transaction
 * and sees exactly the caller's tenant. An injected `Connection` would be a
 * second connection with no context — under PgBouncer's `pool_mode=transaction`
 * not even the same backend — and RLS would answer with nothing. The symptom
 * would be every workspace suddenly reading as unprovisioned, which is a
 * confusing way to discover a wiring mistake.
 *
 * ---------------------------------------------------------------------------
 * `IDENTITY()`, and no tenant predicate written by hand
 * ---------------------------------------------------------------------------
 * `IDENTITY(s.defaultCurrency)` reads the foreign-key column without joining
 * `currency`, so the read is one row from one table. The tenant predicate is
 * not written here: `TenantFilter` injects it for any entity carrying a
 * `tenantId` field, RLS enforces it underneath whatever the filter does, and
 * `UNIQUE (tenant_id)` makes "at most one row" a property of the schema rather
 * than of a `LIMIT`. With no context established the filter emits `1 = 0` and
 * the policy matches nothing: no rows, and the throw below. Fails closed.
 */
final readonly class TenantSettingsReader implements WorkspaceSettings
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function defaultCurrencyCode(): string
    {
        $rows = $this->em
            ->createQuery('SELECT IDENTITY(s.defaultCurrency) AS code FROM '.TenantSettings::class.' s')
            ->getScalarResult();

        if ([] === $rows) {
            throw WorkspaceNotConfigured::noSettingsRow();
        }

        return (string) $rows[0]['code'];
    }
}
