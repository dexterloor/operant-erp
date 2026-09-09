<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

/**
 * Raised when tenant context is asked for in a shape TenantContext cannot make
 * safe. Always thrown before any statement is issued, so the caller's
 * transaction is never left aborted by the refusal itself.
 */
final class TenantContextViolation extends \LogicException
{
    public static function tenantSwitchInsideOneTransaction(string $outer, string $inner): self
    {
        return new self(sprintf(
            'Refusing to switch tenant inside one transaction: already running as %s, asked for %s. '
            .'set_config(..., true) is scoped to the outermost transaction, so the inner tenant would '
            .'outlive this block and every subsequent statement -- including an UPDATE or DELETE with no '
            .'tenant predicate -- would silently target it. Close the outer unit of work first.',
            $outer,
            $inner,
        ));
    }

    public static function foreignTransactionAlreadyOpen(int $nestingLevel): self
    {
        return new self(sprintf(
            'Refusing to establish tenant context inside a transaction this class did not open '
            .'(nesting level %d). set_config(..., true) would bind to that outer transaction and survive '
            .'this block, which is the same leak as a nested tenant switch. Open the unit of work through '
            .'TenantContext::run() instead.',
            $nestingLevel,
        ));
    }

    /**
     * `establishOn()`'s refusal, and the failure it prevents is a silent one.
     *
     * `set_config(..., true)` is transaction-local. With no transaction open the
     * statement is its own implicit transaction, so the setting is gone before
     * the next statement runs — every RLS-filtered read that followed would
     * return zero rows, and under PgBouncer's `pool_mode=transaction` the
     * connection would go back to the pool in between. The read fails closed,
     * which is why nobody would notice it as a security defect; they would
     * notice it as "the login stopped working" and reach for the wrong fix.
     */
    public static function noTransactionToBindTo(): self
    {
        return new self(
            'Refusing to establish tenant context outside a transaction. set_config(..., true) is '
            .'transaction-local: with no transaction open it binds to the implicit one this statement '
            .'creates and is discarded immediately, so every RLS-filtered read that follows returns '
            .'nothing. Open the transaction before calling TenantContext::establishOn().'
        );
    }

    public static function contextChangedUnderneathUs(string $expected, ?string $actual): self
    {
        return new self(sprintf(
            'Tenant context was modified outside TenantContext: expected %s, found %s. '
            .'Something issued its own SET or set_config on this connection.',
            $expected,
            null === $actual ? 'NULL' : var_export($actual, true),
        ));
    }
}
