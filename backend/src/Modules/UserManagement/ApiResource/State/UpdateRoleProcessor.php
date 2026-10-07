<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\RoleResource;
use App\Modules\UserManagement\ApiResource\UpdateRoleInput;
use App\Modules\UserManagement\Application\RoleUpdate;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `UpdateRole` — `PATCH /api/v1/roles/{id}`, body any subset of `{name, description, permission_codes}` (roles slice 4, C2).
 * **200**, the role in its new state (`GetRole`'s shape); a request that changes nothing is 200 with the role and no write.
 *
 * Declared `read: false`: nothing is read before the role-administration lock (the role is read under it, so a 404 cannot
 * precede the lock-time 403 — E-1). Like `CreateRoleProcessor`, it hands the service the client IP and the denial subject
 * as the gate resolves it for this request (`PermissionGate::denialSubjectOf()`).
 *
 * @implements ProcessorInterface<UpdateRoleInput, RoleResource>
 */
final readonly class UpdateRoleProcessor implements ProcessorInterface
{
    public function __construct(private RoleUpdate $update)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RoleResource
    {
        \assert($data instanceof UpdateRoleInput);
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('UpdateRole is an HTTP operation and runs inside a request.');
        }

        return RoleResource::fromView($this->update->update(
            (string) ($uriVariables['id'] ?? ''),
            $data->submitted(),
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
