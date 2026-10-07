<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\Application\RoleDeletion;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `DeleteRole` — `DELETE /api/v1/roles/{id}` (roles slice 4, C3). API-21 branch 3: **204, and no body**.
 *
 * A SOFT delete (`role` is `SchemaManifest::SOFT`): `role.deleted_at` is set, an `UPDATE`. Declared `read: false`: the role
 * is read under the role-administration lock, never by a provider before it opens (E-1), so neither a 404 nor any refusal
 * about the role can precede the lock-time 403 or the `role.assign` backstop. Like `RevokeRoleProcessor`, it hands the
 * service the client IP and the denial subject as the gate resolves it for this request (`PermissionGate::denialSubjectOf()`).
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteRoleProcessor implements ProcessorInterface
{
    public function __construct(private RoleDeletion $deletion)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('DeleteRole is an HTTP operation and runs inside a request.');
        }

        $this->deletion->delete(
            (string) ($uriVariables['id'] ?? ''),
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        );

        return null;
    }
}
