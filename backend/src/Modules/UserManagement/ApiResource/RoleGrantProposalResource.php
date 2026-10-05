<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\CreateRoleGrantProposalProcessor;
use App\Modules\UserManagement\ApiResource\State\RoleGrantProposalProvider;
use App\Modules\UserManagement\Application\RoleGrantProposalView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **A grant proposal — E-15e's pending grant of a role, waiting for an owner's confirmation** (roles slice 3, C1;
 * `docs/session13/roles-s3-design.md` §3; `SECURITY_MODEL.md` §5.2).
 *
 * The shape is §4.4.3's `RoleGrantProposal` row, closed: `id`, `user = {id, display_name}`, `role = {id, name}`,
 * `permission_codes` (the proposal-time snapshot, sorted), `status`, `is_expired` (derived, display only), `proposed_by =
 * {id, display_name}`, `proposed_at`, `expires_at`, `decided_by = {id, display_name}|null`, `decided_at`, `reason`,
 * `lapse_reason`. **The three `app_user` references are PC-16's closed list**, served only under `role.assign` — every
 * operation of this resource is gated on it, so `viewer` and every `role.view`-only holder see nothing pending (IA §6.8).
 * No PC-13 conflict courtesy and no holder count (design §3.3).
 *
 * `GetRoleGrantProposal` is declared FIRST: API Platform's `Location` names a resource's first item operation, and that
 * is the URI `CreateRoleGrantProposal`'s 201 must carry (API-21 branch 4; the `CreditNoteResource` precedent).
 * `CreatedRowLocation`'s template is not declared: on `/users/{id}/role-grant-proposals` the request's own `{id}` is the
 * USER's.
 */
#[ApiResource(
    shortName: 'RoleGrantProposal',
    operations: [
        new Get(
            uriTemplate: '/role-grant-proposals/{id}',
            name: 'GetRoleGrantProposal',
            openapi: new OpenApiOperation(operationId: 'GetRoleGrantProposal'),
            provider: RoleGrantProposalProvider::class,
            extraProperties: [
                // §4.2's `Perm` cell — deliberately not `role.view` (§5.2.3: a pending proposal is privilege history).
                PermissionGate::OPERATION_PERMISSION => 'role.assign',
                // F-4: the code's resource half is `role`, but `{id}` addresses a proposal.
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['role_grant_proposal', 'id'],
            ],
        ),
        // API-21 branch 4: 201, `Location` = GetRoleGrantProposal's URI. §4.2's `Idem` cell is
        // `unique:role_grant_proposal_pending_unique`: the header is demanded (428 without it) and the natural pair is what
        // collides. `read: false`: nothing is read before the role-administration lock.
        new Post(
            uriTemplate: '/users/{id}/role-grant-proposals',
            uriVariables: [
                'id' => new Link(fromClass: RoleGrantProposalResource::class, identifiers: [], parameterName: 'id'),
            ],
            name: 'CreateRoleGrantProposal',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateRoleGrantProposal'),
            input: CreateRoleGrantProposalInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            read: false,
            processor: CreateRoleGrantProposalProcessor::class,
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'role.assign',
                // F-4: `{id}` addresses the USER whose privileges would change, not a role.
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['app_user', 'id'],
            ],
        ),
    ],
)]
final class RoleGrantProposalResource
{
    /**
     * @param list<string> $permissionCodes codes, sorted ascending
     */
    public function __construct(
        public string $id,
        /** PC-16 field 1: the person the role would be given to. */
        public UserDisplayReference $user,
        public RoleReference $role,
        #[SerializedName('permission_codes')]
        public array $permissionCodes,
        public string $status,
        /** Derived, display only: pending and past `expires_at` (R-13). Never a decision. */
        #[SerializedName('is_expired')]
        public bool $isExpired,
        /** PC-16 field 2. */
        #[SerializedName('proposed_by')]
        public UserDisplayReference $proposedBy,
        #[SerializedName('proposed_at')]
        public string $proposedAt,
        #[SerializedName('expires_at')]
        public string $expiresAt,
        /** PC-16 field 3; null on `pending` and `lapsed`. */
        #[SerializedName('decided_by')]
        public ?UserDisplayReference $decidedBy,
        #[SerializedName('decided_at')]
        public ?string $decidedAt,
        /** Only on `rejected`. */
        public ?string $reason,
        #[SerializedName('lapse_reason')]
        public ?string $lapseReason,
    ) {
    }

    public static function fromView(RoleGrantProposalView $view): self
    {
        return new self(
            id: $view->id,
            user: new UserDisplayReference($view->userId, $view->userDisplayName),
            role: new RoleReference($view->roleId, $view->roleName),
            permissionCodes: $view->permissionCodes,
            status: $view->status,
            isExpired: $view->isExpired,
            proposedBy: new UserDisplayReference($view->proposedById, $view->proposedByDisplayName),
            proposedAt: $view->proposedAt,
            expiresAt: $view->expiresAt,
            decidedBy: null === $view->decidedById ? null : new UserDisplayReference($view->decidedById, (string) $view->decidedByDisplayName),
            decidedAt: $view->decidedAt,
            reason: $view->reason,
            lapseReason: $view->lapseReason,
        );
    }
}
