<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\AssignRoleInput;
use App\Modules\UserManagement\ApiResource\RoleResource;
use App\Modules\UserManagement\Application\RoleAssignment;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `AssignRole` — `POST /api/v1/users/{id}/roles`, body `{role_id}`. API-21 branch 4: **201**, and `Location` names the
 * grant, `/api/v1/users/{id}/roles/{roleId}` — the URI `RevokeRole` addresses — filled by `CreatedRowLocation` from the
 * route's `{id}` and the body's `id`.
 *
 * **The body is the granted ROLE in `GetRole`'s shape** (`SEC` S-1 (b), `roles-s2-sec.md` §1): its `id` is the `{roleId}`
 * `Location` names, so the header names a row the response describes. Read after the insert under the lock
 * (`RoleAssignment`), so `holder_count` includes this grant.
 *
 * Declared `read: false`: nothing is read before the role-administration lock is taken. Like `RevokeRoleProcessor`, it
 * hands the application service the two request facts it cannot see — the client IP and the denial subject as the gate
 * resolves it for this very request (`PermissionGate::denialSubjectOf()`, the one resolver).
 *
 * @implements ProcessorInterface<AssignRoleInput, RoleResource>
 */
final readonly class AssignRoleProcessor implements ProcessorInterface
{
    public function __construct(private RoleAssignment $assignment)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RoleResource
    {
        \assert($data instanceof AssignRoleInput);
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('AssignRole is an HTTP operation and runs inside a request.');
        }

        return RoleResource::fromView($this->assignment->assign(
            (string) ($uriVariables['id'] ?? ''),
            $data->roleId,
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
