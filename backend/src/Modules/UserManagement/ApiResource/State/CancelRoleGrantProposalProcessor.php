<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\RoleGrantProposalResource;
use App\Modules\UserManagement\Application\RoleGrantProposalCancellation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CancelRoleGrantProposal` — `POST /api/v1/role-grant-proposals/{id}/cancellation`, no body (roles slice 3, C3; the copy
 * says "withdraw"). API-21 branch 5: **200**, the proposal in its new state. Only the delegation.
 *
 * @implements ProcessorInterface<mixed, RoleGrantProposalResource>
 */
final readonly class CancelRoleGrantProposalProcessor implements ProcessorInterface
{
    public function __construct(private RoleGrantProposalCancellation $cancellation)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RoleGrantProposalResource
    {
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('CancelRoleGrantProposal is an HTTP operation and runs inside a request.');
        }

        return RoleGrantProposalResource::fromView($this->cancellation->cancel(
            (string) ($uriVariables['id'] ?? ''),
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
