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
 * excluded pending `SEC` ({@see self::PENDING_SEC}). Rule (2) is what keeps `ListReorderSuggestions` (all-of several codes)
 * out; it is built (G3-C1), so the test now asserts it is not derived.
 *
 * Two-way: the derived set equals the export sources' Lists, exactly. G6-C0 shipped the Inventory family and listed the
 * other flat Lists as owed to G6-C1; G6-C1 (2026-10-23) shipped all of them and deleted that list, so a NEW flat
 * single-code List is red here until it is given an `ExportSource` — TB-7's "every table" cannot rot silently.
 * *Mutation:* remove `CustomerExportSource` (or its tag) → `ListCustomers` is derived and unserved → red (design §6.1).
 */
final class ExportAdmissibleSetTest extends ApiTestCase
{
    /** Final RED (remaining ops) + design §6.1: excluded until `SEC` rules. Named, so the exclusion is visible. */
    public const PENDING_SEC = [
        'ListAuditLogEntries' => 'the whole trail; until SEC rules',
        'ListAuditRecords' => 'a record\'s History; until SEC rules',
        'ListRoleGrantProposals' => 'privilege history gated on role.assign (§5.2.3), Departure 2; SEC to confirm or strike',
    ];

    #[Test]
    public function theExportableListsAreExactlyTheDerivedSet(): void
    {
        $derived = self::derive(TransactionBoundaryTest::contractOperations());
        self::assertContains('ListItems', $derived, 'Positive control: ListItems is not derived, so the derivation is blind.');
        self::assertArrayHasKey('ListReorderSuggestions', TransactionBoundaryTest::contractOperations(), 'Control: ListReorderSuggestions is not in the registry, so its exclusion below holds over nothing.');
        self::assertNotContains('ListReorderSuggestions', $derived, 'Rule (2): ListReorderSuggestions is gated on several codes (all-of), so it is not exportable.');

        $served = [];
        foreach ($this->sources()->all() as $source) {
            $served[] = $source->listOperationName();
        }
        sort($served);

        self::assertSame($served, $derived, 'The exportable Lists (derived from the registry) and the export sources differ. A new flat single-code List needs an ExportSource (TB-7); a source must name a List the rules admit.');
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
