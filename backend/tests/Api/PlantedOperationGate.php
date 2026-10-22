<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use App\Modules\AuditLog\Infrastructure\DenialAuditWriter;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\CurrentOperation;
use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirements;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * **The REAL `PermissionGate` against a planted operation** (P-0's `PermissionGateRequirementTest` shape, shared by G5-D0).
 *
 * The gate is built with the container's identity, denial writer and -- unless a test passes its own, as the derived tests
 * do -- requirement service, and decides on a synthetic `POST /planted` whose extra properties the test writes. Only the
 * operation metadata and (for DERIVED) the locator are planted; the decision, the 403/422 and the denial row are the
 * shipped code's.
 *
 * @phpstan-require-extends ApiTestCase
 */
trait PlantedOperationGate
{
    /**
     * @param array<string, mixed> $extra
     *
     * @return ApiProblem|null what the gate threw, or null when it let the request through
     */
    private function plantedDecision(array $extra, ?Request $request = null, ?PermissionRequirements $requirements = null): ?ApiProblem
    {
        $operation = new Post(name: 'PlantedOperation', extraProperties: $extra);
        $factory = new class($operation) implements ResourceMetadataCollectionFactoryInterface {
            public function __construct(private readonly Post $operation)
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
        $requirements ??= $container->get(PermissionRequirements::class);
        \assert($denials instanceof DenialAuditWriter && $requirements instanceof PermissionRequirements);

        $gate = new PermissionGate(new CurrentOperation($factory), $this->identities(), $denials, $requirements);

        $request ??= Request::create('/planted', 'POST');
        $request->attributes->set('_api_resource_class', self::class);
        $request->attributes->set('_api_operation_name', 'PlantedOperation');
        $request->attributes->set('_route_params', []);
        $kernel = self::$kernel;
        \assert($kernel instanceof HttpKernelInterface);

        try {
            $gate(new ControllerEvent($kernel, static fn (): null => null, $request, HttpKernelInterface::MAIN_REQUEST));
        } catch (ApiProblem $problem) {
            return $problem;
        }

        return null;
    }

    /** A planted `POST` carrying exactly these body bytes. */
    private static function plantedRequest(string $body): Request
    {
        return Request::create('/planted', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
    }
}
