<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\HttpOperation;
use App\Modules\AuditLog\Application\ExportSources;
use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirement;
use PHPUnit\Framework\Attributes\Test;

/**
 * **What can be exported is DERIVED, never listed (G6-C0; design §6.1 rules (1)–(4), D-18).**
 *
 * An operation is exportable iff it is (1) a GET named `List*`, (2) declaring exactly one permission code, (3) on a path
 * with no path parameter (a nested List is a section of a parent page — Departure 8, a recorded Low gap), and (4) not
 * excluded pending `SEC` ({@see self::PENDING_SEC}). Rule (2) is what keeps `ListReorderSuggestions` (all-of five codes)
 * out, once it is built.
 *
 * Two-way: the derived set equals the export sources' Lists plus {@see self::OWED_TO_G6_C1}, the flat Lists this commit
 * deliberately leaves to G6-C1 (G6-C0 ships the Inventory family). The owed list shrinks to empty in G6-C1, which then
 * deletes it; until then a NEW flat List is red here — it is neither a source nor owed — so TB-7's "every table" cannot rot
 * silently. *Mutation:* remove `ItemExportSource` (or its tag) → `ListItems` is derived and unserved → red.
 */
final class ExportAdmissibleSetTest extends ApiTestCase
{
    /** Final RED (remaining ops) + design §6.1: excluded until `SEC` rules. Named, so the exclusion is visible. */
    public const PENDING_SEC = [
        'ListAuditLogEntries' => 'the whole trail; until SEC rules',
        'ListAuditRecords' => 'a record\'s History; until SEC rules',
        'ListRoleGrantProposals' => 'privilege history gated on role.assign (§5.2.3), Departure 2; SEC to confirm or strike',
    ];

    /** The flat single-code Lists G6-C1 owes a source for (design R1.1: "remaining flat families"). Derived each run. */
    public const OWED_TO_G6_C1 = [
        'ListCreditNotes', 'ListCustomers', 'ListFulfilments', 'ListGoodsReceipts', 'ListInvoices', 'ListPermissions',
        'ListPurchaseOrders', 'ListRoles', 'ListSalesOrders', 'ListSalesReturns', 'ListSuppliers', 'ListUsers', 'ListWarehouses',
    ];

    #[Test]
    public function theExportableListsAreExactlyTheDerivedSet(): void
    {
        $derived = self::derive(TransactionBoundaryTest::contractOperations());
        self::assertContains('ListItems', $derived, 'Positive control: ListItems is not derived, so the derivation is blind.');

        $served = [];
        foreach ($this->sources()->all() as $source) {
            $served[] = $source->listOperationName();
        }
        sort($served);

        self::assertSame([], array_values(array_intersect($served, self::OWED_TO_G6_C1)), 'A List is both served and listed as owed: delete it from the owed list.');

        $expected = [...$served, ...self::OWED_TO_G6_C1];
        sort($expected);

        self::assertSame($expected, $derived, 'The exportable Lists (derived from the registry) and the export sources plus the owed G6-C1 list differ. A new flat single-code List needs an ExportSource (TB-7); a served one must leave the owed list.');
    }

    /** Each exclusion is LIVE: the excluded List exists and would otherwise be derived — or it is not an exclusion at all. */
    #[Test]
    public function eachExclusionHoldsOverABuiltListTheRulesWouldAdmit(): void
    {
        $registry = TransactionBoundaryTest::contractOperations();
        foreach (array_keys(self::PENDING_SEC) as $name) {
            self::assertArrayHasKey($name, $registry, $name.' is excluded but not built: the exclusion holds over nothing.');
            self::assertTrue(self::passesRulesOneToThree($registry[$name]), $name.' is excluded by name but rules (1)-(3) already exclude it; the name is not the reason.');
        }
    }

    /** L-8's shape: the derivation takes the registry as a parameter, so a planted registry proves each rule can bite. */
    #[Test]
    public function aPlantedRegistryShowsEachRuleBites(): void
    {
        $planted = [
            'ListThings' => new Get(uriTemplate: '/things', name: 'ListThings', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'thing.view']),
            'GetThing' => new Get(uriTemplate: '/things/{id}', name: 'GetThing', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'thing.view']),
            'ListThingLines' => new Get(uriTemplate: '/things/{id}/lines', name: 'ListThingLines', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'thing.view']),
            'ListSuggestions' => new Get(uriTemplate: '/suggestions', name: 'ListSuggestions', extraProperties: [PermissionGate::OPERATION_PERMISSION => ['a.view', 'b.view']]),
            'ListOpen' => new Get(uriTemplate: '/open', name: 'ListOpen', extraProperties: [PermissionGate::OPERATION_PERMISSION => null]),
            'ListAuditRecords' => new Get(uriTemplate: '/audit-records', name: 'ListAuditRecords', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'audit_log.view_record']),
        ];

        self::assertSame(['ListThings'], self::derive($planted));
    }

    /**
     * @param array<string, HttpOperation> $registry
     *
     * @return list<string>
     */
    public static function derive(array $registry): array
    {
        $derived = [];
        foreach ($registry as $name => $operation) {
            if (self::passesRulesOneToThree($operation) && !\array_key_exists($name, self::PENDING_SEC)) {
                $derived[] = $name;
            }
        }
        sort($derived);

        return $derived;
    }

    private static function passesRulesOneToThree(HttpOperation $operation): bool
    {
        $name = (string) $operation->getName();
        $path = str_replace('{._format}', '', (string) $operation->getUriTemplate());
        $requirement = PermissionRequirement::of($operation);

        return 'GET' === $operation->getMethod()
            && str_starts_with($name, 'List')
            && PermissionRequirement::ALL_OF === $requirement->kind() && 1 === \count($requirement->codes())
            && !str_contains($path, '{');
    }

    private function sources(): ExportSources
    {
        $sources = self::getContainer()->get(ExportSources::class);
        \assert($sources instanceof ExportSources);

        return $sources;
    }
}
