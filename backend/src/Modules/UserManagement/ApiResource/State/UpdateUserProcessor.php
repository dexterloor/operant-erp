<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\UpdateUserInput;
use App\Modules\UserManagement\ApiResource\UserResource;
use App\Modules\UserManagement\Application\UserUpdate;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `UpdateUser` — `PATCH /api/v1/users/{id}`, body any subset of `{display_name, is_active}` (G4-C1). **200**, the person in
 * their new state (`GetUser`'s shape); a request that changes nothing is 200 with the person and no write.
 *
 * Declared `read: false` (final RED L-2): nothing reads the person before the role-administration lock — with the default
 * `read`, API Platform would call `GetUser`'s provider first, which is a read before the lock in E-15e's terms and would
 * 404 rather than 409 a deleted person. Like `UpdateRoleProcessor`, it hands the service the client IP and the denial subject
 * as the gate resolves it for this request (`PermissionGate::denialSubjectOf()`).
 *
 * @implements ProcessorInterface<UpdateUserInput, UserResource>
 */
final readonly class UpdateUserProcessor implements ProcessorInterface
{
    public function __construct(private UserUpdate $update)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserResource
    {
        \assert($data instanceof UpdateUserInput);
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('UpdateUser is an HTTP operation and runs inside a request.');
        }

        return UserResource::fromView($this->update->update(
            (string) ($uriVariables['id'] ?? ''),
            $data->submitted(),
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
