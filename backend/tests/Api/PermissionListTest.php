<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\ApiResource\PermissionResource;
use App\Modules\UserManagement\Domain\PermissionCatalogue;
use App\Modules\UserManagement\Domain\SeparationOfDuty;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `ListPermissions` — `GET /api/v1/permissions`, `role.view`, *(bounded)*. Roles slice 1, C2
 * (`docs/session13/roles-s1-design.md` §2.1, owner rulings Q2 and Q3+Q4).
 *
 * The permission vocabulary is **global** (IA §6.2): `permission` has no `tenant_id` and no RLS, so
 * every tenant sees the same rows. The wire shape is `{code, module, description, sod_rows}` (`sod_rows` since roles
 * slice 4 C0) — no `id`, because
 * the table has none (its primary key is `code`), ruled 2026-09-23 and pinned in
 * `ResponseShapeTest::NO_ID_RESOURCES`; `module` is the stored, namespace-shaped value.
 *
 * The registry-wide machinery covers the envelope, the undeclared-parameter refusal, the permission gate
 * and the tenant probe. What is here is what those cannot see: that the list is the **table**, not the
 * PHP catalogue; that it is the same for every tenant; and the V2-C-5 carry's tripwire.
 */
final class PermissionListTest extends ApiTestCase
{
    /**
     * The operations shipped under `/permissions`, joined into
     * `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_PERMISSION_OPERATIONS = [
        'ListPermissions' => ['method' => 'GET', 'path' => '/permissions', 'idem' => false],
    ];

    /** The one row `theListIsReadFromTheTableNotTheCatalogue` plants, under the `zz.` prefix `setUp()` sweeps. */
    private const PROBE_CODE = 'zz.roles_s1_probe';

    protected function setUp(): void
    {
        parent::setUp();

        // `permission` is not a tenant table, so the rig's truncation never clears it. A run that crashed
        // between the plant and its `finally` would otherwise leave the probe row for every later suite on
        // this volume — and `PermissionCatalogueSeedTest` would go red on a row nobody can see the cause of.
        $this->owner()->executeStatement("DELETE FROM permission WHERE code LIKE 'zz.%'");
    }

    /** The registry's `/permissions` operations are exactly the pin, method and path included. */
    #[Test]
    public function thePermissionPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if (str_starts_with((string) $operation->getUriTemplate(), '/permissions')) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $operation->getUriTemplate()];
            }
        }

        self::assertSame(
            array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_PERMISSION_OPERATIONS),
            $declared,
        );
    }

    /**
     * The domain is the **server's** `permission` table, read as the owner — never a list in this file.
     * Equal to `items` row for row in `code` order; as many rows as `PermissionCatalogue::codes()` (which
     * `PermissionCatalogueSeedTest` holds at its pinned count, so the number has one home); and each
     * row's `module` is the catalogue's `moduleOf(code)` — not `SchemaManifest`'s module set, which has
     * no `Modules\Ai` or `Modules\Analytics` because neither owns a table (RED F3).
     *
     * Plant-free, so a correct implementation is never red here on a planted row.
     */
    #[Test]
    public function theListIsTheWholeCatalogueFromTheServer(): void
    {
        $this->send('GET', '/permissions');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();

        $table = $this->owner()->fetchAllAssociative('SELECT code, module, description FROM permission ORDER BY code');
        self::assertNotSame([], $table, 'The permission table is empty, so the comparison has no subject.');
        // The three stored columns, row for row; `sod_rows` is derived, not stored, and is held by its own test below.
        self::assertSame($table, array_map(static fn (array $item): array => array_diff_key($item, ['sod_rows' => true]), $body['items']));
        self::assertCount(\count(PermissionCatalogue::codes()), $body['items'], 'The list is not the whole seeded catalogue.');
        foreach ($body['items'] as $row) {
            self::assertSame(PermissionCatalogue::moduleOf($row['code']), $row['module'], $row['code'].'\'s module is not the catalogue\'s.');
        }
        self::assertNull($body['next_cursor']);
        self::assertNull($body['previous_cursor']);
        self::assertTrue($body['tenant_has_rows']);
    }

    /** IA §6.2: the vocabulary is global. Tenant A's and tenant B's bodies are byte-identical. */
    #[Test]
    public function everyTenantSeesTheSameCatalogue(): void
    {
        $this->send('GET', '/permissions');
        self::assertSame(200, $this->httpStatus());
        $a = (string) $this->client()->getResponse()->getContent();

        $this->actAs(self::TENANT_B);
        $this->send('GET', '/permissions');
        self::assertSame(200, $this->httpStatus());

        self::assertSame($a, (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **The only method that plants** (RED-2, R2-2). A row committed to `permission` as the owner must
     * appear in the list, which a list built from `PermissionCatalogue` cannot do. Committed, not
     * rolled back: the request runs on the `erp_app` connection and the rig isolates by truncation, so an
     * uncommitted row on the owner connection would be invisible to it (RED F4). Removed in `finally`,
     * and swept in `setUp()` in case a crash skipped the `finally`.
     *
     * Mutation M6: read `PermissionCatalogue` instead of the table → red here, and only here.
     */
    #[Test]
    public function theListIsReadFromTheTableNotTheCatalogue(): void
    {
        $this->owner()->executeStatement(
            'INSERT INTO permission (code, module, description) VALUES (?, ?, ?)',
            [self::PROBE_CODE, 'Modules\\Ai', 'probe'],
        );

        try {
            $this->send('GET', '/permissions');
            self::assertSame(200, $this->httpStatus());
            self::assertContains(self::PROBE_CODE, array_column($this->json()['items'], 'code'),
                'A row in the permission table is missing from the list, so the list is not read from the table.');
        } finally {
            $this->owner()->executeStatement('DELETE FROM permission WHERE code = ?', [self::PROBE_CODE]);
        }
    }

    #[Test]
    public function aCallerWithoutRoleViewIsRefused(): void
    {
        $this->actAs(self::TENANT_A, array_values(array_diff(self::everyDeclaredPermission(), ['role.view'])));
        $this->send('GET', '/permissions');

        self::assertSame(403, $this->httpStatus());
        self::assertSame('role.view', $this->json()['required_permission'] ?? null, (string) $this->client()->getResponse()->getContent());
    }

    /** *(bounded)*: no `limit`, `after` or `sort` — each refused rather than ignored (API-19). */
    #[Test]
    public function anyQueryParameterIsRefused(): void
    {
        foreach (['limit=10', 'after=abc', 'sort=code'] as $query) {
            $this->send('GET', '/permissions?'.$query);
            self::assertSame(422, $this->httpStatus(), $query.': '.(string) $this->client()->getResponse()->getContent());
        }
    }

    /**
     * **V2-C-5's `sod_rows[]`, equal to `SeparationOfDuty` for every code** (roles slice 4 C0 — what the tripwire below
     * demanded). The expectation is rebuilt here from `rowIds()`/`row()`, not from `placementsOf()`, so a defect in the
     * function the reader calls is a red diff, not an agreement. Controls: every governed code appears on the wire, a code on
     * two rows carries both, and a code on no split carries `[]`.
     */
    #[Test]
    public function sodRowsAreTheSeparationOfDutyTableForEveryCode(): void
    {
        $this->send('GET', '/permissions');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $served = array_column($this->json()['items'], 'sod_rows', 'code');
        self::assertCount(\count(PermissionCatalogue::codes()), $served);
        foreach ($served as $code => $rows) {
            $expected = [];
            foreach (SeparationOfDuty::rowIds() as $id) {
                foreach (['preparing', 'controlled'] as $half) {
                    if (\in_array($code, SeparationOfDuty::row($id)[$half], true)) {
                        $expected[] = ['id' => $id, 'half' => $half];
                    }
                }
            }
            self::assertSame($expected, $rows, $code.'\'s sod_rows are not the SoD table\'s.');
        }

        foreach (SeparationOfDuty::governedCodes() as $code) {
            self::assertNotSame([], $served[$code] ?? [], $code.' is on a split but carries no sod_rows.');
        }
        self::assertSame([['id' => 'SoD-1', 'half' => 'controlled'], ['id' => 'SoD-2', 'half' => 'preparing']], $served['purchase_order.approve']);
        self::assertSame([], $served['warehouse.view']);
    }

    /**
     * `PermissionResource` is a plain DTO, not an ApiResource, so API Platform mints no `Permission`
     * component and the envelope's `items` carries an **inline** item schema (a `$ref` would dangle —
     * `OpenApiContractTest::everyReferenceInTheSpecResolves`). This holds the published inline schema's
     * property names equal to the DTO's serialized fields, read off the generated spec.
     */
    #[Test]
    public function theInlineItemSchemaIsTheDtosShape(): void
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $schemas = $spec['components']['schemas'] ?? [];

        $published = null;
        foreach ($schemas as $name => $schema) {
            if (str_starts_with((string) $name, 'PermissionCollection')) {
                $published = $schema['properties']['items']['items']['properties'] ?? null;
            }
        }
        self::assertIsArray($published, 'No PermissionCollection component publishes an inline item schema.');

        $fields = self::serializedFields(PermissionResource::class);
        $names = array_keys($published);
        sort($names);
        sort($fields);
        self::assertNotSame([], $fields);
        self::assertSame($fields, $names);
    }

    /**
     * **V2-C-5, deferred by owner ruling Q2 (2026-09-23) — with a tripwire, not prose.** `sod_rows[]`
     * (which SoD row, and which half, each code sits on, so a role-composition screen can refuse a split at
     * selection time) was not in slice 1. Its consumer is the role-composition flow (IA-O-9) and the refusal
     * it mirrors (PC-4 on `role_permission` writes) ships with `CreateRole`. **Satisfied since roles slice 4 C0
     * (2026-10-07), one commit ahead of `CreateRole`:** `PermissionResource` carries `sod_rows`, and
     * `sodRowsAreTheSeparationOfDutyTableForEveryCode` holds it equal to the table. The pin stays, unedited, so
     * removing the member while a composer exists goes red again.
     *
     * Red the moment `CreateRole` or `UpdateRole` appears in the generated spec — keyed on **operationId**,
     * never a path (`1076ba3`'s lesson) — while `PermissionResource` has no `sod_rows` member.
     *
     * Controls inside the test: a planted `CreateRole` with no `sod_rows` is a defect; with `sod_rows` it
     * is not; and the check's domain is not empty.
     */
    #[Test]
    public function noRoleCompositionShipsWithoutSodRows(): void
    {
        self::assertNotSame([], self::sodRowsDefects(['CreateRole', 'GetRole'], ['code', 'module', 'description']), 'The tripwire accepted a planted CreateRole with no sod_rows.');
        self::assertSame([], self::sodRowsDefects(['UpdateRole'], ['code', 'module', 'description', 'sod_rows']), 'The tripwire refused a Permission that carries sod_rows.');

        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $operationIds = [];
        foreach (GeneratedSpec::operations($spec) as $operation) {
            $operationIds[] = (string) ($operation['operation']['operationId'] ?? '');
        }
        self::assertContains('ListPermissions', $operationIds, 'The spec does not carry ListPermissions, so the domain is not the one this pin was written for.');

        self::assertSame([], self::sodRowsDefects($operationIds, self::serializedFields(PermissionResource::class)));
    }

    /**
     * @param list<string> $operationIds
     * @param list<string> $permissionFields
     *
     * @return list<string>
     */
    private static function sodRowsDefects(array $operationIds, array $permissionFields): array
    {
        $defects = [];
        foreach (array_intersect(['CreateRole', 'UpdateRole'], $operationIds) as $composer) {
            if (!\in_array('sod_rows', $permissionFields, true)) {
                $defects[] = $composer.' ships while Permission has no sod_rows[]. V2-C-5 (BE-LEAD + UX), deferred to this '
                    .'slice by owner ruling Q2 of 2026-09-23: add `sod_rows = [{id: "SoD-n", half: …}]` to PermissionResource, '
                    .'derived from SeparationOfDuty (the committed SoD home), so the role-composition checklist (IA-O-9) refuses a '
                    .'split at selection time; add it to §4.4.3\'s Permission row and test it equals SeparationOfDuty for every code.';
            }
        }

        return $defects;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function serializedFields(string $class): array
    {
        $fields = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $renamed = $property->getAttributes(SerializedName::class)[0] ?? null;
            $fields[] = null === $renamed ? $property->getName() : (string) $renamed->newInstance()->serializedName;
        }

        return $fields;
    }
}
