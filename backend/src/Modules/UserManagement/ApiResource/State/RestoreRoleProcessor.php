<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\RoleResource;
use App\Modules\UserManagement\Application\RoleRestoration;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `RestoreRole` — `POST /api/v1/roles/{id}/restoration` (G2). API-21 branch 5: **200** with the role in its new state, no
 * `Location` (`RestoreWarehouseProcessor`'s shape). Declared `read: false`: the role is read under the role-administration
 * lock (E-1), as `DeleteRoleProcessor`; it hands the service the client IP and the denial subject as the gate resolves it.
 *
 * @implements ProcessorInterface<mixed, RoleResource>
 */
final readonly class RestoreRoleProcessor implements ProcessorInterface
{
    public function __construct(private RoleRestoration $restoration)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RoleResource
    {
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('RestoreRole is an HTTP operation and runs inside a request.');
        }

        return RoleResource::fromView($this->restoration->restore(
            (string) ($uriVariables['id'] ?? ''),
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
