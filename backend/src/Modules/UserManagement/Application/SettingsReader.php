<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Application\Contract\WorkspaceNotConfigured;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `GetSettings` (G7a, 2026-10-21) — the caller's workspace: `display_name` and `workspace` from the registry row
 * (`tenant.name`, `tenant.slug`), `default_currency_code` from `tenant_settings`.
 *
 * **`tenant` has no RLS** (`SchemaManifest`: `'tenant' => false`; final RED L-3), so this statement's `WHERE t.id = ?` IS the
 * isolation for those two fields: the id is the caller's tenant, from the signed token through {@see CurrentTenant} — no
 * request member can name one (the operation declares no path variable and no query parameter, so API-19 refuses a
 * `?tenant_id`). `tenant_settings` is RLS'd as well as joined on the same id. `SettingsReadTest::
 * anotherWorkspacesSettingsNeverAppear` is the evidence, with the predicate's mutation recorded in the G7a progress note.
 *
 * **`display_name` reads `tenant.name` until `UpdateSettings` (G7b)**, which moves it to `tenant_settings.display_name`
 * (owner ruling RO-S1, `docs/session13/remaining-ops-ruling.md`; never write `tenant`). `OwedAmendmentTripwireTest` keys the
 * move on the `operationId` `UpdateSettings`, so G7b cannot ship without it.
 *
 * **A workspace with no settings row is a server fault** ({@see WorkspaceNotConfigured}, ledger E4-3), as for every other
 * reader of the currency: a registered workspace has none until its owner provisions it (`TenantSettingsStorageTest::
 * registeringAWorkspaceDoesNotCreateItsSettingsRow`), so its Settings screen answers 500 until then — recorded, not defaulted.
 */
final readonly class SettingsReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /** @return SettingsView|null null when the caller's tenant row is gone (the gate resolved it, so only a race) */
    public function read(): ?SettingsView
    {
        $tenantId = $this->tenant->tenantId();

        return $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId): ?SettingsView {
            $row = $em->getConnection()->fetchAssociative(<<<'SQL'
                SELECT t.name, t.slug, s.default_currency_code
                  FROM tenant t
                  LEFT JOIN tenant_settings s ON s.tenant_id = t.id
                 WHERE t.id = ?::uuid
                SQL, [$tenantId]);

            if (false === $row) {
                return null;
            }
            if (null === $row['default_currency_code']) {
                throw WorkspaceNotConfigured::noSettingsRow();
            }

            return new SettingsView((string) $row['name'], (string) $row['slug'], (string) $row['default_currency_code']);
        });
    }
}
