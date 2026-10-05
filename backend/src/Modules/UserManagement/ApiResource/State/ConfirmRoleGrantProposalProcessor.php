<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\RoleGrantProposalResource;
use App\Modules\UserManagement\Application\RoleGrantProposalConfirmation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `ConfirmRoleGrantProposal` — `POST /api/v1/role-grant-proposals/{id}/confirmation`, no body (roles slice 3, C2).
 * API-21 branch 5: **200**, the proposal in its new state, no `Location`.
 *
 * Nothing here but the delegation, for `ConfirmSalesOrderProcessor`'s reason: the confirmation is one ordered sequence in
 * one locked transaction, and a processor that did any part of it would put a step outside it. Like
 * `CreateRoleGrantProposalProcessor`, it hands the application service the two request facts it cannot see — the client
 * IP and the denial subject as the gate resolves it for this very request (`PermissionGate::denialSubjectOf()`).
 *
 * @implements ProcessorInterface<mixed, RoleGrantProposalResource>
 */
final readonly class ConfirmRoleGrantProposalProcessor implements ProcessorInterface
{
    public function __construct(private RoleGrantProposalConfirmation $confirmation)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RoleGrantProposalResource
    {
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('ConfirmRoleGrantProposal is an HTTP operation and runs inside a request.');
        }

        return RoleGrantProposalResource::fromView($this->confirmation->confirm(
            (string) ($uriVariables['id'] ?? ''),
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        ));
    }
}
