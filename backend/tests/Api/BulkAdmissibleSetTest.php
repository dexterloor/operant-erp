<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Parameters;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Platform\Http\Bulk\BulkArchivalPermission;
use App\Platform\Http\Bulk\BulkArchiveMembers;
use App\Platform\Http\Bulk\BulkExclusions;
use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirement;
use PHPUnit\Framework\Attributes\Test;

/**
 * **What can be archived in bulk is DERIVED, never listed** (G5-C2; design §5.0, RO-B2; R1 L-8).
 *
 * **Naming rule, stated (RED L-6):** `List<X>s` — a resource with an irregular plural would silently fail rule (2); the
 * seven today are regular, and the hand-check `theSetIsDerivedAndEveryDerivedResourceHasAMember` pins the seven by literal,
 * so such a resource is red there rather than missing.
 *
 * A table `T` is admissible iff (1) there is a built `Delete<X>` and a built `Restore<X>`, each gated on exactly
 * `T.delete`; (2) the `List<X>s` operation declares an `archived` query parameter; and (3) `T` is not in
 * {@see BulkExclusions::RULED}. Two-way: the derived set equals the tagged {@see BulkArchiveMembers}' keys — a resource that
 * becomes derivable without a member is red, and so is a member for an underivable resource. Each exclusion is LIVE (it
 * would be derived without it) or not yet derivable with its trigger named, never dead.
 *
 * *Mutations:* register a member for `supplier_product` → red (its Delete is gated on `supplier_product.delete` but it has
 * no Restore, and its member would also fail `BulkArchivalPermission`); drop the `role` exclusion → red (role is derivable
 * since G2 `RestoreRole`); drop the `app_user` exclusion → the not-yet-derivable branch names G4's `DeleteUser` trigger.
 */
final class BulkAdmissibleSetTest extends ApiTestCase
{
    /** Each exclusion that is not yet derivable names what makes it derivable (so it cannot sit dead unnoticed). */
    private const NOT_YET_DERIVABLE_TRIGGERS = [
        'app_user' => 'G4 C1–C3 build `DeleteUser` and `RestoreUser` at `app_user.delete` over `ListUsers?archived`',
    ];

    #[Test]
    public function theSetIsDerivedAndEveryDerivedResourceHasAMember(): void
    {
        $derived = self::derive(TransactionBoundaryTest::contractOperations());
        self::assertContains('customer', $derived, 'Positive control: customer is not derived, so the derivation is blind.');

        $members = array_keys($this->members()->all());
        self::assertSame($derived, $members,
            'The bulk-archivable resources (derived from the registry less RO-B2\'s exclusions) and the BulkArchiveMember registry differ. A derivable resource needs a member (parity, D-12); a member for an underivable one is a wiring fault.');
        self::assertSame(['customer', 'inventory_item', 'purchase_order', 'sales_order', 'storage_location', 'supplier', 'warehouse'], $derived,
            'Re-derived 2026-10-22 at G5-C2 (seven). A change here is a new member or a ruling, and the design §5.0 table moves with it.');
    }

    #[Test]
    public function eachExclusionIsLiveOrNamesItsTrigger(): void
    {
        $registry = TransactionBoundaryTest::contractOperations();
        $withoutExclusions = self::derive($registry, false);

        foreach (array_keys(BulkExclusions::RULED) as $resource) {
            if (\in_array($resource, $withoutExclusions, true)) {
                continue; // live: the ruling is the only thing keeping it out
            }
            self::assertArrayHasKey($resource, self::NOT_YET_DERIVABLE_TRIGGERS,
                $resource.' is excluded but not derivable, and names no trigger: the exclusion would sit dead.');
        }

        self::assertContains('role', $withoutExclusions, 'role became derivable with G2 RestoreRole; its RO-B2 exclusion must be live.');
        foreach (array_keys(self::NOT_YET_DERIVABLE_TRIGGERS) as $resource) {
            self::assertNotContains($resource, $withoutExclusions, $resource.' is derivable now: move it out of NOT_YET_DERIVABLE_TRIGGERS (its exclusion is live).');
        }
    }

    /** L-8: the derivation takes the registry as a parameter, so a planted registry proves each rule can bite. */
    #[Test]
    public function aPlantedRegistryShowsEachRuleBites(): void
    {
        $archived = static fn (): Parameters => new Parameters(['archived' => new QueryParameter(key: 'archived')]);

        $planted = [
            // admissible
            'DeleteThing' => new Delete(uriTemplate: '/things/{id}', name: 'DeleteThing', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'thing.delete']),
            'RestoreThing' => new Post(uriTemplate: '/things/{id}/restoration', name: 'RestoreThing', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'thing.delete']),
            'ListThings' => new Get(uriTemplate: '/things', name: 'ListThings', parameters: $archived(), extraProperties: [PermissionGate::OPERATION_PERMISSION => 'thing.view']),
            // no Restore
            'DeleteWidget' => new Delete(uriTemplate: '/widgets/{id}', name: 'DeleteWidget', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'widget.delete']),
            'ListWidgets' => new Get(uriTemplate: '/widgets', name: 'ListWidgets', parameters: $archived(), extraProperties: [PermissionGate::OPERATION_PERMISSION => 'widget.view']),
            // gated on the parent's .edit (a line)
            'DeleteThingLine' => new Delete(uriTemplate: '/things/{id}/lines/{lineId}', name: 'DeleteThingLine', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'thing.edit']),
            'RestoreThingLine' => new Post(uriTemplate: '/things/{id}/lines/{lineId}/restoration', name: 'RestoreThingLine', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'thing.edit']),
            // no archived view
            'DeleteGadget' => new Delete(uriTemplate: '/gadgets/{id}', name: 'DeleteGadget', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'gadget.delete']),
            'RestoreGadget' => new Post(uriTemplate: '/gadgets/{id}/restoration', name: 'RestoreGadget', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'gadget.delete']),
            'ListGadgets' => new Get(uriTemplate: '/gadgets', name: 'ListGadgets', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'gadget.view']),
            // ruled out (RO-B2), though otherwise admissible
            'DeleteRole' => new Delete(uriTemplate: '/roles/{id}', name: 'DeleteRole', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'role.delete']),
            'RestoreRole' => new Post(uriTemplate: '/roles/{id}/restoration', name: 'RestoreRole', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'role.delete']),
            'ListRoles' => new Get(uriTemplate: '/roles', name: 'ListRoles', parameters: $archived(), extraProperties: [PermissionGate::OPERATION_PERMISSION => 'role.view']),
        ];

        self::assertSame(['thing'], self::derive($planted));
        self::assertSame(['role', 'thing'], self::derive($planted, false), 'Without RO-B2 the planted role is admitted: the exclusion is live.');
    }

    /** `PINNED_RESOLVERS`' map, by name: each resource resolves to its single Delete's declared code, `<resource>.delete`. */
    #[Test]
    public function eachResourceResolvesToItsSingleDeletesDeclaredCode(): void
    {
        $permission = self::getContainer()->get(BulkArchivalPermission::class);
        \assert($permission instanceof BulkArchivalPermission);
        $map = $permission->codesByResource();
        ksort($map);

        self::assertSame([
            'customer' => 'customer.delete',
            'inventory_item' => 'inventory_item.delete',
            'purchase_order' => 'purchase_order.delete',
            'sales_order' => 'sales_order.delete',
            'storage_location' => 'storage_location.delete',
            'supplier' => 'supplier.delete',
            'warehouse' => 'warehouse.delete',
        ], $map);

        $registry = TransactionBoundaryTest::contractOperations();
        foreach ($this->members()->all() as $resource => $member) {
            self::assertSame([$map[$resource]], PermissionRequirement::of($registry[$member->deleteOperationName()])->codes(),
                $resource.': the resolver and the single Delete\'s own cell disagree.');
        }
    }

    /**
     * @param array<string, HttpOperation> $registry
     *
     * @return list<string>
     */
    public static function derive(array $registry, bool $applyExclusions = true): array
    {
        $derived = [];
        foreach ($registry as $name => $delete) {
            if (!str_starts_with((string) $name, 'Delete') || 'DELETE' !== $delete->getMethod()) {
                continue;
            }
            $code = self::singleCode($delete);
            if (null === $code || !str_ends_with($code, '.delete')) {
                continue;
            }
            $x = substr((string) $name, \strlen('Delete'));
            $restore = $registry['Restore'.$x] ?? null;
            $list = $registry['List'.$x.'s'] ?? null;
            if (null === $restore || $code !== self::singleCode($restore) || null === $list || !self::declaresArchived($list)) {
                continue;
            }
            $table = substr($code, 0, -\strlen('.delete'));
            if ($applyExclusions && \array_key_exists($table, BulkExclusions::RULED)) {
                continue;
            }
            $derived[] = $table;
        }
        $derived = array_values(array_unique($derived));
        sort($derived);

        return $derived;
    }

    private static function singleCode(HttpOperation $operation): ?string
    {
        $requirement = PermissionRequirement::of($operation);

        return PermissionRequirement::ALL_OF === $requirement->kind() && 1 === \count($requirement->codes()) ? $requirement->codes()[0] : null;
    }

    private static function declaresArchived(HttpOperation $list): bool
    {
        foreach ($list->getParameters() ?? [] as $key => $parameter) {
            if ('archived' === (string) ($parameter->getKey() ?? $key)) {
                return true;
            }
        }

        return false;
    }

    private function members(): BulkArchiveMembers
    {
        $members = self::getContainer()->get(BulkArchiveMembers::class);
        \assert($members instanceof BulkArchiveMembers);

        return $members;
    }
}
