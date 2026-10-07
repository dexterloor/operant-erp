<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Domain\SeparationOfDuty;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `ListPermissions`' read — the global permission vocabulary, **from the table**.
 *
 * The source is `permission`, not `PermissionCatalogue`: the wire should say what the database's
 * foreign key from `role_permission` will actually accept. `PermissionCatalogueSeedTest` holds the two
 * equal; `PermissionListTest::theListIsReadFromTheTableNotTheCatalogue` is what fails if this reads
 * the catalogue instead.
 *
 * **Not tenant-scoped, by design** (IA §6.2): `permission` has no `tenant_id` and no RLS, so every
 * tenant sees the same rows. It still runs through `TenantContext::run()` on the `erp_app` entity
 * manager — the same seam and the same principal every tenant read uses — so a reader copied from
 * this one inherits the safe form. **Never** take a `Doctrine\DBAL\Connection` in the constructor: an
 * unbound autowired `Connection` is the schema owner (`ApiTestCase::tearDown()`'s `OwnerRequestWindow`
 * check is what reddens if one is used on a request path).
 *
 * Rows come back in `code` order, the primary key's — the one order a bounded set is loaded in, not a
 * `sort` control.
 *
 * **`sod_rows` is derived, not stored** (V2-C-5, roles slice 4 C0): `SeparationOfDuty::placementsOf()` per row — the
 * committed SoD home the server refuses a split from, so the composition screen and the refusal cannot disagree. A code the
 * table does not name (a planted row, or any code on no split) carries `[]`.
 */
final readonly class PermissionReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /** @return array{items: list<PermissionView>, tenantHasRows: bool} */
    public function list(): array
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em): array {
            $connection = $em->getConnection();
            $rows = $connection->fetchAllAssociative('SELECT code, module, description FROM permission ORDER BY code');

            // API-22: the probe runs only on an empty page. This page is never empty — the catalogue is
            // global and seeded — so the branch below is implemented for uniformity and is unreachable;
            // no per-operation `theEmptyProbeRunsOnlyOnAnEmptyPage` test can be written for it.
            $tenantHasRows = [] !== $rows || (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM permission)');

            return [
                'items' => array_map(static fn (array $row): PermissionView => new PermissionView(
                    (string) $row['code'],
                    (string) $row['module'],
                    (string) $row['description'],
                    SeparationOfDuty::placementsOf((string) $row['code']),
                ), $rows),
                'tenantHasRows' => $tenantHasRows,
            ];
        });
    }
}
