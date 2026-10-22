<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirement;
use App\Platform\Security\PermissionRequirements;
use PHPUnit\Framework\Attributes\Test;

/**
 * **G5-D0 / final RED H-1 fix 2: the tagged-resolver locator is the single wiring, held equal to the operations both ways.**
 *
 * Every operation whose requirement is DERIVED names a resolver id the locator holds, and the locator holds no resolver no
 * operation names. A resolver nothing names is a code set feeding `possibleCodes()` for nobody; an operation naming an id
 * nothing provides is a 500 on its first request. Both are red here, from the registry and the live container, and the
 * comparison is shown able to fail by planted inputs in each direction.
 *
 * Today both sides are empty (no derived operation ships until G5-C2, no resolver either). That is the true state, not a
 * vacuous pass: the plants are the evidence the comparison works, and the first resolver or derived operation to land
 * without its other half reddens this.
 */
final class DerivedPermissionWiringTest extends ApiTestCase
{
    #[Test]
    public function theLocatorHoldsExactlyTheResolversTheOperationsName(): void
    {
        $requirements = self::getContainer()->get(PermissionRequirements::class);
        \assert($requirements instanceof PermissionRequirements);

        $byOperation = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $byOperation[(string) $name] = PermissionRequirement::of($operation);
        }
        self::assertGreaterThan(100, \count($byOperation), 'Control: the registry was not read.');

        self::assertSame([], self::mismatches($byOperation, $requirements->resolverIds()),
            'The derived-permission locator and the operations disagree.');

        // Every resolver the locator holds is a DerivedPermission whose map meets the contract (codesByResource() throws
        // otherwise), reached through the operations that name it.
        foreach ($byOperation as $requirement) {
            if (PermissionRequirement::DERIVED === $requirement->kind()) {
                self::assertNotSame([], $requirements->possibleCodes($requirement));
            }
        }
    }

    #[Test]
    public function theComparisonCanFailBothWays(): void
    {
        $derived = PermissionRequirement::fromDeclaration(PermissionGate::DERIVED, 'app.resolver_a');
        $static = PermissionRequirement::fromDeclaration('inventory_item.view');

        self::assertSame([], self::mismatches(['A' => $derived, 'G' => $static], ['app.resolver_a']));
        self::assertSame(['A names resolver "app.resolver_a", which the locator does not hold'],
            self::mismatches(['A' => $derived, 'G' => $static], []));
        self::assertSame(['the locator holds "app.resolver_b", which no operation names'],
            self::mismatches(['A' => $derived], ['app.resolver_a', 'app.resolver_b']));
        self::assertSame(['the locator holds "app.resolver_a", which no operation names'],
            self::mismatches(['G' => $static], ['app.resolver_a']));
    }

    /**
     * @param array<string, PermissionRequirement> $byOperation
     * @param list<string>                         $resolverIds
     *
     * @return list<string>
     */
    private static function mismatches(array $byOperation, array $resolverIds): array
    {
        $named = [];
        $problems = [];
        foreach ($byOperation as $name => $requirement) {
            if (PermissionRequirement::DERIVED !== $requirement->kind()) {
                continue;
            }
            $named[] = $requirement->resolver();
            if (!\in_array($requirement->resolver(), $resolverIds, true)) {
                $problems[] = \sprintf('%s names resolver "%s", which the locator does not hold', $name, $requirement->resolver());
            }
        }
        foreach ($resolverIds as $id) {
            if (!\in_array($id, $named, true)) {
                $problems[] = \sprintf('the locator holds "%s", which no operation names', $id);
            }
        }

        return $problems;
    }
}
