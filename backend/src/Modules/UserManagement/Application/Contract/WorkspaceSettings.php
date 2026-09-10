<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application\Contract;

/**
 * The workspace's own configuration, as other modules may see it.
 *
 * ---------------------------------------------------------------------------
 * The first `Application\Contract` in this tree, and it is A-2's shape
 * ---------------------------------------------------------------------------
 * `ARCHITECTURE.md` §4 makes `Application/Contract` *"the ONLY namespace other
 * modules may import"*, and the deptrac ruleset has carried a
 * `UserManagementContract` layer since Output 5 with nothing in it. This is the
 * first class in it. It exists because `Inventory`'s item writer needs the
 * tenant's functional currency and the table that holds it is owned by this
 * module — the alternatives were `Inventory` reaching into
 * `UserManagement\Infrastructure` (A-2) or a `Platform` class reading a
 * module's table by name (A-1 in spirit, invisible to deptrac because raw SQL
 * is a string).
 *
 * Deliberately **not** a getter over an entity. Returning `TenantSettings`
 * would put an `Infrastructure` type in a contract and hand every caller the
 * whole row; a caller needs one value.
 */
interface WorkspaceSettings
{
    /**
     * The workspace's functional currency — an ISO 4217 code that resolves in
     * the `currency` reference table, guaranteed by a foreign key.
     *
     * Must be called inside the caller's tenant transaction: the read is bound
     * by RLS and by the Doctrine tenant filter, both of which are established
     * by `TenantContext::run()` and both of which are transaction-scoped.
     * Called outside one it does not return another tenant's value — it finds
     * nothing and throws, which is the failing-closed half of the same design.
     *
     * @throws WorkspaceNotConfigured when the workspace has no settings row. There is no
     *                                fallback here and none anywhere else; see that class
     */
    public function defaultCurrencyCode(): string;
}
