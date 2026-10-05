<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\RejectRoleGrantProposalInput;
use App\Modules\UserManagement\ApiResource\RoleGrantProposalResource;
use App\Modules\UserManagement\Application\RoleGrantProposalRejection;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `RejectRoleGrantProposal` — `POST /api/v1/role-grant-proposals/{id}/rejection`, body `{reason?}` (roles slice 3, C3).
 * API-21 branch 5: **200**, the proposal in its new state. Only the delegation, for `ConfirmRoleGrantProposalProcessor`'s
 * reason; it hands over the client IP and the denial subject the gate resolves for this request.
 *
 * @implements ProcessorInterface<RejectRoleGrantProposalInput, RoleGrantProposalResource>
 */
final readonly class RejectRoleGrantProposalProcessor implements ProcessorInterface
{
    public function __construct(private RoleGrantProposalRejection $rejection)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RoleGrantProposalResource
    {
        \assert($data instanceof RejectRoleGrantProposalInput);
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('RejectRoleGrantProposal is an HTTP operation and runs inside a request.');
        }

        return RoleGrantProposalResource::fromView($this->rejection->reject(
            (string) ($uriVariables['id'] ?? ''),
            $data->reason,
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
