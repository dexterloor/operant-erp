<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Application\ExportPermission;
use App\Modules\AuditLog\Application\ExportSources;
use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirement;
use App\Platform\Security\PermissionRequirements;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;

/**
 * **`CreateExport`'s derived permission (G6-C0; design §6.1 D-20; G5-D0 carry (2)).**
 *
 * The export of a resource is gated on the code its `List*` operation is DECLARED with — never a concatenation. The map is
 * pinned by name here (the review copy), and each entry is held equal to its List's own pin in
 * `PermissionEnforcementContractTest::PINNED_PERMISSIONS` (the registry copy), so a resolver that drifts from its List is
 * red twice. `credit_note → invoice.view` (G6-C1) is the case concatenation gets wrong; G5-D0's planted map already proves
 * the gate carries such a code through.
 */
final class ExportPermissionTest extends ApiTestCase
{
    use ExportTestSupport;

    /** The review copy of the export map at G6-C0 (the Inventory family). G6-C1 adds the remaining flat families. */
    private const PINNED_MAP = [
        'inventory_item' => 'inventory_item.view',
        'stock_level' => 'stock_level.view',
        'stock_movement' => 'stock_movement.view',
    ];

    #[Test]
    public function eachExportableResourceResolvesToItsListsDeclaredCode(): void
    {
        $requirement = PermissionEnforcementContractTest::pinnedRequirement('CreateExport');
        self::assertSame(PermissionRequirement::DERIVED, $requirement->kind());
        self::assertSame(ExportPermission::class, $requirement->resolver());

        $requirements = self::getContainer()->get(PermissionRequirements::class);
        $sources = self::getContainer()->get(ExportSources::class);
        \assert($requirements instanceof PermissionRequirements && $sources instanceof ExportSources);

        $map = $requirements->codesByResource($requirement);
        self::assertSame(self::PINNED_MAP, $map, 'The export map is not the reviewed one.');

        foreach ($map as $resource => $code) {
            $list = $sources->get($resource)->listOperationName();
            self::assertSame($code, PermissionEnforcementContractTest::PINNED_PERMISSIONS[$list] ?? null,
                \sprintf('%s exports under %s, and its List %s is pinned on something else.', $resource, $code, $list));
        }
        self::assertSame(array_values(array_unique(array_values(self::PINNED_MAP))), $requirements->possibleCodes($requirement));
        self::assertSame(PermissionGate::DERIVED, PermissionEnforcementContractTest::PINNED_PERMISSIONS['CreateExport']);
    }

    /**
     * One decision per request, on the resource it names (E-1, E-14): a composed role holding `inventory_item.view` and not
     * `stock_movement.view` exports items and is refused movements -- 403 naming the resolved code, one E-3 row
     * `(null, null)` + that code, and no export row.
     */
    #[Test]
    public function aCallerIsGatedOnTheResourceItNames(): void
    {
        $this->actAs(self::TENANT_A, ['inventory_item.view']);
        $this->seedItem(self::TENANT_A, 'SKU-1');

        $this->export(['resource' => 'inventory_item']);
        self::assertCount(2, $this->exportRows());

        $this->export(['resource' => 'stock_movement']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('stock_movement.view', $this->json()['required_permission']);

        $denials = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT subject_type, subject_id::text AS subject_id, permission_code FROM audit_log WHERE actor_user_id = ?::uuid AND event_type = ?',
            [$this->actorId, AuditEvent::AUTHZ_PERMISSION_DENIED]);
        self::assertSame([['subject_type' => null, 'subject_id' => null, 'permission_code' => 'stock_movement.view']], $denials);
        self::assertCount(1, $this->auditRowsOf('data.export.completed'), 'Only the allowed export is recorded.');
    }
}
