<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application\Contract;

/**
 * Raised when a workspace is missing a setting that has no default and cannot
 * be invented.
 *
 * A `\RuntimeException` rather than a `\LogicException`: nothing about the
 * calling code is wrong. The workspace was provisioned without the row, which
 * is a state of the data, and the correct response is to fail rather than to
 * substitute a value — substituting one is the defect `tenant_settings` exists
 * to remove (ledger E4-3), and it is why `inventory_item.currency_code`'s
 * database default was dropped in the first place.
 *
 * Deliberately **not** an `ApiProblem`. This is not something a client did or
 * can fix: a 4xx would tell the caller to change its request and there is no
 * request that works. It surfaces as a 500, which is what an unprovisioned
 * workspace is.
 */
final class WorkspaceNotConfigured extends \RuntimeException
{
    public static function noSettingsRow(): self
    {
        return new self(
            'This workspace has no tenant_settings row, so it has no stated functional currency. '
            .'The value is not defaulted anywhere on purpose: a container parameter used to supply it '
            .'for every tenant at once, and a database DEFAULT would be the same defect one layer '
            .'down. Provision the workspace\'s settings row before writing rows denominated in its '
            .'currency.'
        );
    }
}
