<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\UserManagement\ApiResource\RoleResource;
use App\Modules\UserManagement\Application\RoleReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetRole`. A non-uuid id, another tenant's role and no role at all are the same not-found (§5.3 clause 2);
 * RLS decides the other-tenant case. A soft-deleted role in the caller's tenant is returned, not refused.
 *
 * @implements ProviderInterface<RoleResource>
 */
final readonly class RoleProvider implements ProviderInterface
{
    public function __construct(private RoleReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RoleResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return RoleResource::fromView($view);
    }
}
