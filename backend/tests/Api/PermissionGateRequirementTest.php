<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use App\Modules\AuditLog\Infrastructure\DenialAuditWriter;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\CurrentOperation;
use App\Platform\Security\Identity;
use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirement;
use App\Platform\Security\PermissionRequirements;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * **P-0's positive controls for the gate's arms** (`g3-g7b-final-red.md` F-H1, F-L1, F-L7).
 *
 * No shipped operation declares an all-of list or a derived permission yet (G3-C1 and G5-D0 add them), so the HTTP sweeps
 * cannot reach those arms. This drives the REAL gate -- its real identity, denial writer and requirement service from the
 * container -- against a synthetic operation, so each arm is shown deciding:
 *
 *  - all-of: holding nothing names the FIRST code in byte order; holding the first names the second; holding both passes;
 *    the denial row records the code the 403 names;
 *  - derived: fails closed (LogicException), never a pass and never a 403 that implies somebody was asked;
 *  - none: passes with no identity at all;
 *  - undeclared: the gate's long-standing LogicException text.
 */
final class PermissionGateRequirementTest extends ApiTestCase
{
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

    #[Test]
    public function aDerivedRequirementFailsClosedUntilG5D0WiresIt(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('G5-D0 adds the DERIVED arm');

        $this->refusalFor([
            PermissionGate::OPERATION_PERMISSION => PermissionGate::DERIVED,
            PermissionGate::DERIVED_PERMISSION => 'app.planted_resolver',
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
     * F-H1 point 3: the container-aware half. NONE has no codes, ALL_OF its own, and DERIVED throws until G5-D0 adds the
     * arm -- so `everyDeclaredPermission()` and the catalogue check cannot silently drop a derived operation.
     */
    #[Test]
    public function thePossibleCodesOfEachKind(): void
    {
        $requirements = self::getContainer()->get(PermissionRequirements::class);
        \assert($requirements instanceof PermissionRequirements);

        self::assertSame([], $requirements->possibleCodes(PermissionRequirement::fromDeclaration(null)));
        self::assertSame(['a.view'], $requirements->possibleCodes(PermissionRequirement::fromDeclaration('a.view')));
        self::assertSame(['a.view', 'b.view'], $requirements->possibleCodes(PermissionRequirement::fromDeclaration(['a.view', 'b.view'])));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('which no tagged service provides');
        $requirements->possibleCodes(PermissionRequirement::fromDeclaration(PermissionGate::DERIVED, 'app.planted_resolver'));
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
        $operation = new Get(name: 'PlantedOperation', extraProperties: $extra);
        $factory = new class($operation) implements ResourceMetadataCollectionFactoryInterface {
            public function __construct(private readonly Get $operation)
            {
            }

            public function create(string $resourceClass): ResourceMetadataCollection
            {
                return new ResourceMetadataCollection($resourceClass, [
                    (new ApiResource())->withOperations(new Operations(['PlantedOperation' => $this->operation])),
                ]);
            }
        };

        $container = self::getContainer();
        $denials = $container->get(DenialAuditWriter::class);
        $requirements = $container->get(PermissionRequirements::class);
        \assert($denials instanceof DenialAuditWriter && $requirements instanceof PermissionRequirements);

        $gate = new PermissionGate(new CurrentOperation($factory), $this->identities(), $denials, $requirements);

        $request = Request::create('/planted');
        $request->attributes->set('_api_resource_class', self::class);
        $request->attributes->set('_api_operation_name', 'PlantedOperation');
        $request->attributes->set('_route_params', []);
        $kernel = self::$kernel;
        \assert($kernel instanceof HttpKernelInterface);

        try {
            $gate(new ControllerEvent($kernel, static fn (): null => null, $request, HttpKernelInterface::MAIN_REQUEST));
        } catch (ApiProblem $problem) {
            return $problem->getRequiredPermission();
        }

        return null;
    }
}
