<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\CreateUserInput;
use App\Modules\UserManagement\ApiResource\UserResource;
use App\Modules\UserManagement\Application\UserCreation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/** `CreateUser` (U-C1b): the request's members, IP and denial subject to {@see UserCreation}; 201 + `GetUser`'s body. */
final readonly class CreateUserProcessor implements ProcessorInterface
{
    public function __construct(private UserCreation $creation)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserResource
    {
        \assert($data instanceof CreateUserInput);
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('CreateUser is an HTTP operation and runs inside a request.');
        }

        return UserResource::fromView($this->creation->create(
            $data->submitted(),
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
