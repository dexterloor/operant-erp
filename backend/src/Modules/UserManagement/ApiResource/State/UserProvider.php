<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\UserManagement\ApiResource\UserResource;
use App\Modules\UserManagement\Application\UserReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetUser`. The gate has already refused a caller without `app_user.view` (E-1). A non-uuid, absent or another
 * workspace's id is the same 404 (RLS); an archived user is returned.
 *
 * @implements ProviderInterface<UserResource>
 */
final readonly class UserProvider implements ProviderInterface
{
    public function __construct(private UserReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return UserResource::fromView($view);
    }
}
