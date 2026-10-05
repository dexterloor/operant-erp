<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\UserManagement\ApiResource\RoleGrantProposalResource;
use App\Modules\UserManagement\Application\RoleGrantProposalReader;
use App\Platform\Http\Problem\ApiProblem;

/** `GetRoleGrantProposal` (roles slice 3, C1): a read, no lock; another workspace's id is not-found exactly as a random one. */
final readonly class RoleGrantProposalProvider implements ProviderInterface
{
    public function __construct(private RoleGrantProposalReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RoleGrantProposalResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return RoleGrantProposalResource::fromView($view);
    }
}
