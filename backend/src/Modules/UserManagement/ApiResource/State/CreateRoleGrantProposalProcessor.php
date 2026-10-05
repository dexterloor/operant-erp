<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\CreateRoleGrantProposalInput;
use App\Modules\UserManagement\ApiResource\RoleGrantProposalResource;
use App\Modules\UserManagement\Application\RoleGrantProposalCreation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateRoleGrantProposal` — `POST /api/v1/users/{id}/role-grant-proposals`, body `{role_id}` (roles slice 3, C1).
 * API-21 branch 4: **201**, the proposal as the body, `Location` = `GetRoleGrantProposal`'s URI.
 *
 * Declared `read: false`: nothing is read before the role-administration lock. Like `AssignRoleProcessor`, it hands the
 * application service the two request facts it cannot see — the client IP and the denial subject as the gate resolves it
 * for this very request (`PermissionGate::denialSubjectOf()`, the one resolver).
 *
 * @implements ProcessorInterface<CreateRoleGrantProposalInput, RoleGrantProposalResource>
 */
final readonly class CreateRoleGrantProposalProcessor implements ProcessorInterface
{
    public function __construct(private RoleGrantProposalCreation $creation)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RoleGrantProposalResource
    {
        \assert($data instanceof CreateRoleGrantProposalInput);
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('CreateRoleGrantProposal is an HTTP operation and runs inside a request.');
        }

        return RoleGrantProposalResource::fromView($this->creation->propose(
            (string) ($uriVariables['id'] ?? ''),
            $data->roleId,
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
