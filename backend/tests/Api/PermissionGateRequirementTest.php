<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Security\Identity;
use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirement;
use App\Platform\Security\PermissionRequirements;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Support\PlantedDerivedPermission;
use PHPUnit\Framework\Attributes\Test;

/**
 * **P-0's positive controls for the gate's arms** (`g3-g7b-final-red.md` F-H1, F-L1, F-L7).
 *
 * No shipped operation declares an all-of list or a derived permission yet (G3-C1 and G5-C2 add them), so the HTTP sweeps
 * cannot reach those arms. This drives the REAL gate -- its real identity, denial writer and requirement service from the
 * container -- against a synthetic operation, so each arm is shown deciding:
 *
 *  - all-of: holding nothing names the FIRST code in byte order; holding the first names the second; holding both passes;
 *    the denial row records the code the 403 names;
 *  - derived with an unwired resolver: fails closed (LogicException); the wired arm is `DerivedPermissionTest` (G5-D0);
 *  - none: passes with no identity at all;
 *  - undeclared: the gate's long-standing LogicException text.
 */
final class PermissionGateRequirementTest extends ApiTestCase
{
    use PlantedOperationGate;

    #[Test]
    public function anAllOfRequirementNamesTheFirstMissingCodeAndPassesWhenEveryCodeIsHeld(): void
    {
        $declared = [PermissionGate::OPERATION_PERMISSION => ['forecast.view', 'stock_level.view']];

        self::assertSame('forecast.view', $this->refusalFor($declared, []));
        self::assertSame('stock_level.view', $this->refusalFor($declared, ['forecast.view']));
        self::assertSame('forecast.view', $this->refusalFor($declared, ['stock_level.view']));
        $refusedActor = $this->actorId;
        self::assertNull($this->refusalFor($declared, ['forecast.view', 'stock_level.view']));

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT permission_code FROM audit_log WHERE actor_user_id = ?::uuid AND event_type = ? ORDER BY occurred_at, id',
            [$refusedActor, AuditEvent::AUTHZ_PERMISSION_DENIED]);
        self::assertSame([['permission_code' => 'forecast.view']], $rows,
            'The refused call\'s denial row does not name the code its 403 named.');
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT permission_code FROM audit_log WHERE actor_user_id = ?::uuid AND event_type = ?',
            [$this->actorId, AuditEvent::AUTHZ_PERMISSION_DENIED]), 'A caller holding every code was recorded as denied.');
    }

    #[Test]
    public function aSingleCodeStillDecidesExactlyAsBefore(): void
    {
        $declared = [PermissionGate::OPERATION_PERMISSION => 'inventory_item.view'];

        self::assertSame('inventory_item.view', $this->refusalFor($declared, ['inventory_item.edit']));
        self::assertNull($this->refusalFor($declared, ['inventory_item.view']));
    }

    /**
     * G5-D0: a derived operation whose resolver id no tagged service provides is still a wiring error -- fails closed,
     * never a pass and never a 403 that implies somebody was asked. (The wired arm: `DerivedPermissionTest`.)
     */
    #[Test]
    public function aDerivedRequirementWithAnUnwiredResolverFailsClosed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('which no service tagged "app.derived_permission" provides');

        $this->refusalFor([
            PermissionGate::OPERATION_PERMISSION => PermissionGate::DERIVED,
            PermissionGate::DERIVED_PERMISSION => 'app.unwired_resolver',
        ], ['forecast.view', 'stock_level.view', 'inventory_item.view']);
    }

    #[Test]
    public function noneRequiresNoIdentity(): void
    {
        $this->actAsNobody();

        self::assertNull($this->decide([PermissionGate::OPERATION_PERMISSION => null]));
    }

    #[Test]
    public function anUndeclaredOperationIsStillAWiringError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares no "required_permission" extra property');

        $this->decide([]);
    }

    /**
     * F-H1 point 3: the container-aware half. NONE has no codes, ALL_OF its own, DERIVED its resolver's values -- unique and
     * byte-sorted, a DISJUNCTION (G5-D0) -- so `everyDeclaredPermission()` and the catalogue check cannot silently drop a
     * derived operation; an unwired resolver still throws.
     */
    #[Test]
    public function thePossibleCodesOfEachKind(): void
    {
        $requirements = self::getContainer()->get(PermissionRequirements::class);
        \assert($requirements instanceof PermissionRequirements);

        self::assertSame([], $requirements->possibleCodes(PermissionRequirement::fromDeclaration(null)));
        self::assertSame(['a.view'], $requirements->possibleCodes(PermissionRequirement::fromDeclaration('a.view')));
        self::assertSame(['a.view', 'b.view'], $requirements->possibleCodes(PermissionRequirement::fromDeclaration(['a.view', 'b.view'])));

        $planted = PlantedDerivedPermission::requirements(new PlantedDerivedPermission([
            'zeta' => 'zeta.delete', 'credit_note' => 'invoice.view', 'invoice' => 'invoice.view', 'alpha' => 'alpha.delete',
        ]));
        $derived = PermissionRequirement::fromDeclaration(PermissionGate::DERIVED, PlantedDerivedPermission::ID);
        self::assertSame(['alpha.delete', 'invoice.view', 'zeta.delete'], $planted->possibleCodes($derived),
            'A derived requirement\'s possible codes are its resolver\'s values, de-duplicated and byte-sorted.');
        self::assertSame(['alpha', 'credit_note', 'invoice', 'zeta'], array_keys($planted->codesByResource($derived)),
            'The map is served keys byte-sorted, so "every key, in order" is defined (L-4).');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('which no service tagged "app.derived_permission" provides');
        $requirements->possibleCodes(PermissionRequirement::fromDeclaration(PermissionGate::DERIVED, 'app.planted_resolver'));
    }

    /** G5-D0: a resolver's map is checked against the contract, not trusted. */
    #[Test]
    public function aResolverOutsideTheContractIsAWiringError(): void
    {
        $derived = PermissionRequirement::fromDeclaration(PermissionGate::DERIVED, PlantedDerivedPermission::ID);
        $violations = [
            'empty' => [],
            'sentinel' => ['item' => PermissionGate::DERIVED],
            'not a code' => ['item' => 'Item.Delete'],
            'empty resource' => ['' => 'a.delete'],
            'integer resource' => [7 => 'a.delete'],
            'non-string code' => ['item' => ['a.delete']],
        ];
        foreach ($violations as $label => $map) {
            try {
                PlantedDerivedPermission::requirements(new PlantedDerivedPermission($map))->possibleCodes($derived);
                self::fail('A resolver map that is '.$label.' was accepted.');
            } catch (\LogicException $e) {
                self::assertStringContainsString(PlantedDerivedPermission::ID, $e->getMessage(), $label);
            }
        }

        $notAResolver = new PermissionRequirements(new \Symfony\Component\DependencyInjection\ServiceLocator([
            PlantedDerivedPermission::ID => static fn (): object => new \stdClass(),
        ]));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not a');
        $notAResolver->possibleCodes($derived);
    }

    /**
     * @param array<string, mixed> $extra
     * @param list<string>         $held
     *
     * @return string|null the refused code, or null when the gate let the request through
     */
    private function refusalFor(array $extra, array $held): ?string
    {
        $this->actAs(self::TENANT_A, $held);
        // The gate reads the request-scoped identity; actAs() sets it with exactly these codes.
        $this->identities()->set(new Identity(self::TENANT_A, (string) $this->actorId, 'Fixture Actor', $held));

        return $this->decide($extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function decide(array $extra): ?string
    {
        return $this->plantedDecision($extra)?->getRequiredPermission();
    }
}
