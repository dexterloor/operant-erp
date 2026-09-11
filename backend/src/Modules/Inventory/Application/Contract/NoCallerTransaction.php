<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * ARCHITECTURE §5.5 W-2 — a cross-module command was called with no
 * transaction open.
 *
 * A `\LogicException` because the calling code is wrong, not the data: the
 * contract is "call me inside your own tenancy transaction". Without this
 * refusal `TenantContext::run()` would open a transaction of its own and commit
 * it, so the stock would land whether or not the caller's document (the goods
 * receipt) ever committed — exactly the independence W-2 forbids.
 *
 * Deliberately **not** something the caller translates into a 4xx: no request
 * the client could change would fix it. It surfaces as a 500.
 */
final class NoCallerTransaction extends \LogicException
{
    public static function for(string $command): self
    {
        return new self(\sprintf(
            '%s must be called inside the caller\'s tenancy transaction (ARCHITECTURE §5.5 W-2) and none is '
            .'open. It opens none of its own, so that the caller\'s write and this one commit or roll back '
            .'together. Call it from inside TenantContext::run().',
            $command,
        ));
    }
}
