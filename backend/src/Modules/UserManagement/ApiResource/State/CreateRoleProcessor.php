<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\CreateRoleInput;
use App\Modules\UserManagement\ApiResource\RoleResource;
use App\Modules\UserManagement\Application\RoleCreation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateRole` — `POST /api/v1/roles`, body `{name, description, permission_codes}` (roles slice 4, C1). API-21 branch 4:
 * **201**, the role as the body (`GetRole`'s shape), `Location` = `GetRole`'s URI.
 *
 * Declared `read: false`: nothing is read before the role-administration lock. Like `AssignRoleProcessor`, it hands the
 * application service the two request facts it cannot see — the client IP and the denial subject as the gate resolves it
 * for this very request (`PermissionGate::denialSubjectOf()`, the one resolver).
 *
 * @implements ProcessorInterface<CreateRoleInput, RoleResource>
 */
final readonly class CreateRoleProcessor implements ProcessorInterface
{
    public function __construct(private RoleCreation $creation)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RoleResource
    {
        \assert($data instanceof CreateRoleInput);
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('CreateRole is an HTTP operation and runs inside a request.');
        }

        return RoleResource::fromView($this->creation->create(
            $data->name,
            $data->description,
            $data->permissionCodes,
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
