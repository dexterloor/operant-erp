<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\UserResource;
use App\Modules\UserManagement\Application\UserRestoration;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `RestoreUser` — `POST /api/v1/users/{id}/restoration`, no body (G4-C3). **200**, the person as restored (`GetUser`'s
 * shape; API-21 branch 5). `read: false` (final RED L-2): nothing reads the person before the role lock.
 *
 * @implements ProcessorInterface<mixed, UserResource>
 */
final readonly class RestoreUserProcessor implements ProcessorInterface
{
    public function __construct(private UserRestoration $restoration)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserResource
    {
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('RestoreUser is an HTTP operation and runs inside a request.');
        }

        return UserResource::fromView($this->restoration->restore(
            (string) ($uriVariables['id'] ?? ''),
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
