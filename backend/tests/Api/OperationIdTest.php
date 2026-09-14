<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ContractDocument;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\String\Inflector\EnglishInflector;

/**
 * `docs/API.md` §12.3 — operationIds. The per-module halves
 * (`ItemOperationContractTest`, `PurchaseOrderOperationContractTest`,
 * `AuthOperationContractTest`) each carry `::everyOperationIdIsItsOwnVerbAndResource`
 * over their own pin; this class holds the rule that spans every operation.
 */
final class OperationIdTest extends ApiTestCase
{
    /**
     * §12.3's one exception: `Settings` is a singleton resource whose name is a
     * mass noun. Declared here so that adding a second is an edit somebody makes
     * on purpose — the `PINNED_ALLOWLIST` mechanism.
     */
    public const PINNED_SINGLETON_RESOURCES = ['Settings'];

    /**
     * §12.3 — a `List` operation names a plural resource and every other verb a
     * singular one, outside the pinned exception. **Collection-ness is decided
     * by the verb, never by the resource string**; plurality is decided by
     * {@see self::isPlural()}.
     *
     * Domain: every operationId in the **generated spec** (phase 2), split into
     * its leading verb word and the rest; and, wherever the document exists,
     * every §4.2 row by its own `Verb / Resource` columns (phase 1), with the
     * spec's split held equal to the row's so the two halves agree on what the
     * verb is.
     *
     * Mutation 1 (code): `ListItems` → name and operationId `ListItem` → RED,
     * naming `ListItem`. Mutation 2 (document, API.md's own "third mutation"):
     * `ListAuditLogEntries` → `ListAuditLog` / `AuditLog` → RED naming it, while
     * the concatenation checks stay green. Reverts → GREEN.
     */
    #[Test]
    public function everyListOperationNamesAPluralResource(): void
    {
        // Positive and negative controls for the predicate, inside this test.
        foreach (['Items', 'PurchaseOrderLines', 'AuditLogEntries', 'CustomerAddresses', 'Warehouses'] as $plural) {
            self::assertTrue(self::isPlural($plural), 'The plurality predicate reads '.$plural.' as singular.');
        }
        foreach (['Item', 'PurchaseOrderLine', 'AuditLog', 'CustomerAddress', 'Password', 'Token', 'CurrentUser'] as $singular) {
            self::assertFalse(self::isPlural($singular), 'The plurality predicate reads '.$singular.' as plural.');
        }

        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $split = [];
        foreach (GeneratedSpec::operations($spec) as $label => $operation) {
            $operationId = (string) ($operation['operation']['operationId'] ?? '');
            self::assertSame(1, preg_match('/^([A-Z][a-z]+)([A-Z][A-Za-z]*)$/', $operationId, $m), $label.'\'s operationId "'.$operationId.'" is not Verb ++ Resource.');
            $split[$operationId] = [$m[1], $m[2]];
        }

        self::assertNotSame([], $split, 'The generated spec declares no operation, so there is no domain.');
        self::assertCount(\count(TransactionBoundaryTest::contractOperations()), $split, 'The spec does not carry one operationId per registry operation.');

        self::assertSame([], self::offenders($split), '§12.3: an operation names a resource of the wrong number.');

        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent, so §4.2\'s unbuilt rows cannot be read. The generated spec above was checked.');
        }

        $rows = [];
        foreach (ContractDocument::operationRows() as $operationId => $row) {
            $rows[$operationId] = array_map(trim(...), explode('/', $row['verbResource'], 2)) + [1 => ''];
        }
        self::assertNotSame([], $rows, '§4.2 yielded no rows, so the document half would be vacuous.');

        foreach ($split as $operationId => $verbAndResource) {
            self::assertArrayHasKey($operationId, $rows, $operationId.' is built and has no §4.2 row.');
            self::assertSame($rows[$operationId], $verbAndResource, $operationId.': the spec\'s verb split is not §4.2\'s Verb / Resource.');
        }

        $used = array_intersect(self::PINNED_SINGLETON_RESOURCES, array_column($rows, 1));
        self::assertSame(self::PINNED_SINGLETON_RESOURCES, array_values($used), 'A pinned singleton resource no longer names any §4.2 row, so the exception is stale.');

        self::assertSame([], self::offenders($rows), '§12.3: a §4.2 row names a resource of the wrong number.');
    }

    /**
     * @param array<string, array{0: string, 1: string}> $verbAndResource
     *
     * @return list<string>
     */
    private static function offenders(array $verbAndResource): array
    {
        $offenders = [];

        foreach ($verbAndResource as $operationId => [$verb, $resource]) {
            if (\in_array($resource, self::PINNED_SINGLETON_RESOURCES, true)) {
                continue;
            }
            if (('List' === $verb) !== self::isPlural($resource)) {
                $offenders[] = $operationId.' ('.$verb.' / '.$resource.')';
            }
        }

        return $offenders;
    }

    /**
     * A PascalCase resource is plural iff its last word is: the word singularizes
     * to a different word that pluralizes back to it, and it does not end in a
     * double `s` — a regular English plural never does, and without that clause
     * the inflector reads `Address` as the plural of `Addres` (measured).
     */
    private static function isPlural(string $resource): bool
    {
        if (1 !== preg_match('/[A-Z][a-z]*$/', $resource, $m) || str_ends_with($m[0], 'ss')) {
            return false;
        }

        $inflector = new EnglishInflector();
        foreach ($inflector->singularize($m[0]) as $singular) {
            if ($singular !== $m[0] && \in_array($m[0], $inflector->pluralize($singular), true)) {
                return true;
            }
        }

        return false;
    }
}
