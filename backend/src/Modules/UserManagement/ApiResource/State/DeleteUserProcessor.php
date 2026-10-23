<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\Application\UserDeletion;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `DeleteUser` — `DELETE /api/v1/users/{id}` (G4-C2). **204**, no body (API-21 branch 3).
 *
 * Declared `read: false` (final RED L-2): with the default `read`, `GetUser`'s provider would run before the processor — a read
 * before the role lock, and it would answer a deleted person's repeat with the provider's 200 path instead of 409
 * `app_user.is_archived` (D-5, T-2-4).
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteUserProcessor implements ProcessorInterface
{
    public function __construct(private UserDeletion $deletion)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('DeleteUser is an HTTP operation and runs inside a request.');
        }

        $this->deletion->delete(
            (string) ($uriVariables['id'] ?? ''),
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        );

        return null;
    }
}
