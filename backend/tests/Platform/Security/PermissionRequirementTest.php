<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use ApiPlatform\Metadata\Get;
use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **P-0: the declaration forms, one spelling per meaning** (`g3-g7b-final-red.md` F-H1 point 1, F-M2, F-L1).
 *
 * Every accepted form is shown parsing to its kind, and every refused form is shown refusing -- so a parser that quietly
 * widened (a one-element list, an unsorted list, a sentinel without its resolver) is red here before any sweep sees it.
 */
final class PermissionRequirementTest extends TestCase
{
    #[Test]
    public function nullIsNone(): void
    {
        $requirement = PermissionRequirement::fromDeclaration(null);

        self::assertSame(PermissionRequirement::NONE, $requirement->kind());
        self::assertSame('none', $requirement->describe());
    }

    #[Test]
    public function aBareCodeIsAllOfOne(): void
    {
        $requirement = PermissionRequirement::fromDeclaration('inventory_item.view');

        self::assertSame(PermissionRequirement::ALL_OF, $requirement->kind());
        self::assertSame(['inventory_item.view'], $requirement->codes());
        self::assertSame('all_of:inventory_item.view', $requirement->describe());
    }

    #[Test]
    public function aSortedListOfTwoOrMoreIsAllOfEvery(): void
    {
        $requirement = PermissionRequirement::fromDeclaration(['forecast.view', 'inventory_item.view', 'stock_level.view']);

        self::assertSame(PermissionRequirement::ALL_OF, $requirement->kind());
        self::assertSame(['forecast.view', 'inventory_item.view', 'stock_level.view'], $requirement->codes());
    }

    #[Test]
    public function theDerivedSentinelWithItsResolverIsDerived(): void
    {
        $requirement = PermissionRequirement::fromDeclaration(PermissionGate::DERIVED, 'app.some_resolver');

        self::assertSame(PermissionRequirement::DERIVED, $requirement->kind());
        self::assertSame('app.some_resolver', $requirement->resolver());
        self::assertSame('derived:app.some_resolver', $requirement->describe());
    }

    /**
     * The sentinel must never be mistakable for a code: if it were §3-shaped, a principal could be granted it.
     */
    #[Test]
    public function theSentinelIsNotShapedLikeACode(): void
    {
        self::assertDoesNotMatchRegularExpression('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', PermissionGate::DERIVED);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: ?string, 2: string}>
     */
    public static function refusedDeclarations(): iterable
    {
        yield 'one-element list (the bare string spells it)' => [['inventory_item.view'], null, 'list of 1'];
        yield 'empty list (null spells —)' => [[], null, 'list of 0'];
        yield 'unsorted list' => [['stock_level.view', 'forecast.view'], null, 'byte-ascending'];
        yield 'duplicate codes' => [['forecast.view', 'forecast.view'], null, 'byte-ascending'];
        yield 'a non-string in the list' => [['forecast.view', 7], null, 'non-string'];
        yield 'a keyed array' => [['a' => 'forecast.view', 'b' => 'stock_level.view'], null, 'neither a code'];
        yield 'an integer' => [7, null, 'neither a code'];
        yield 'an empty string' => ['', null, 'neither a §3-shaped'];
        yield 'a malformed code' => ['Inventory.View', null, 'neither a §3-shaped'];
        yield 'the sentinel without a resolver' => [PermissionGate::DERIVED, null, 'names no'];
        yield 'a resolver without the sentinel' => ['inventory_item.view', 'app.some_resolver', 'does not declare a derived'];
        yield 'a resolver beside none' => [null, 'app.some_resolver', 'does not declare a derived'];
    }

    #[Test]
    #[DataProvider('refusedDeclarations')]
    public function aDeclarationOutsideTheFormsIsAWiringError(mixed $declared, ?string $resolver, string $because): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($because);

        PermissionRequirement::fromDeclaration($declared, $resolver, 'Planted');
    }

    #[Test]
    public function anUndeclaredOperationKeepsTheGatesText(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Operation "Planted" declares no "required_permission" extra property.');

        PermissionRequirement::of(new Get(name: 'Planted', extraProperties: []));
    }

    #[Test]
    public function ofReadsTheOperationsTwoExtras(): void
    {
        self::assertSame('all_of:a.view,b.view', PermissionRequirement::of(new Get(name: 'P', extraProperties: [
            PermissionGate::OPERATION_PERMISSION => ['a.view', 'b.view'],
        ]))->describe());
        self::assertSame('derived:app.r', PermissionRequirement::of(new Get(name: 'P', extraProperties: [
            PermissionGate::OPERATION_PERMISSION => PermissionGate::DERIVED,
            PermissionGate::DERIVED_PERMISSION => 'app.r',
        ]))->describe());
        self::assertSame('none', PermissionRequirement::of(new Get(name: 'P', extraProperties: [
            PermissionGate::OPERATION_PERMISSION => null,
        ]))->describe());
    }

    /**
     * A consumer cannot treat NONE as "no codes" or DERIVED as static: `codes()` throws on both, and `resolver()` on
     * anything but DERIVED.
     */
    #[Test]
    public function theAccessorsRefuseTheWrongKind(): void
    {
        $refused = 0;
        foreach ([
            static fn () => PermissionRequirement::fromDeclaration(null)->codes(),
            static fn () => PermissionRequirement::fromDeclaration(PermissionGate::DERIVED, 'app.r')->codes(),
            static fn () => PermissionRequirement::fromDeclaration(null)->resolver(),
            static fn () => PermissionRequirement::fromDeclaration('a.view')->resolver(),
        ] as $call) {
            try {
                $call();
            } catch (\LogicException) {
                ++$refused;
            }
        }

        self::assertSame(4, $refused, 'An accessor answered for a kind it does not describe.');
    }
}
