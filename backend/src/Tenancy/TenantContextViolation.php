<?php

declare(strict_types=1);

namespace App\Tenancy;

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
