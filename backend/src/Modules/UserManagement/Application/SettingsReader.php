<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Application\Contract\WorkspaceNotConfigured;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `GetSettings` (G7a, 2026-10-21; source moved by G7b, 2026-10-22) — the caller's workspace: `workspace` from the registry
 * row (`tenant.slug`), `display_name` and `default_currency_code` from `tenant_settings`.
 *
 * **`tenant` has no RLS** (`SchemaManifest`: `'tenant' => false`; final RED L-3), so this statement's `WHERE t.id = ?` IS the
 * isolation for `workspace` (and, since G7b, for which settings row is joined): the id is the caller's tenant, from the signed token through {@see CurrentTenant} — no
 * request member can name one (the operation declares no path variable and no query parameter, so API-19 refuses a
 * `?tenant_id`). `tenant_settings` is RLS'd as well as joined on the same id. `SettingsReadTest::
 * anotherWorkspacesSettingsNeverAppear` is the evidence, with the predicate's mutation recorded in the G7a progress note.
 *
 * **`display_name` reads `tenant_settings.display_name` since G7b** (owner ruling RO-S1, `docs/session13/
 * remaining-ops-ruling.md`): `UpdateSettings` writes it and never writes `tenant`, and `Version20261022020000` backfilled it
 * from `btrim(tenant.name)` — the value served here until then — so the name shown did not change on the deploy. The
 * `OwedAmendmentTripwireTest` entry that held this move (keyed on `UpdateSettings`) was retired with it.
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

        return $this->tenants->run($tenantId, static fn (EntityManagerInterface $em): ?SettingsView => self::readInside($em, $tenantId));
    }

    /**
     * The one statement, inside a run the caller already opened (`SettingsWriter` reads its 200 body back through it, inside
     * the rename's transaction). `$tenantId` is always the caller's own, from the token.
     */
    public static function readInside(EntityManagerInterface $em, string $tenantId): ?SettingsView
    {
        $row = $em->getConnection()->fetchAssociative(<<<'SQL'
            SELECT t.slug, s.display_name, s.default_currency_code
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

        return new SettingsView((string) $row['display_name'], (string) $row['slug'], (string) $row['default_currency_code']);
    }
}
