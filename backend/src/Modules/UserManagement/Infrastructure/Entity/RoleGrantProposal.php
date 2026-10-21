<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Entity;

use App\Platform\Domain\GuardsStatusTransitions;
use App\Platform\Persistence\Type\TimestampTzMicrosecondsImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * **A proposed grant of a role — E-15e's pending state, outside `user_role`, so it confers nothing by construction**
 * (roles slice 3, C1; `docs/session13/roles-s3-design.md` §6; `SECURITY_MODEL.md` §5.2.2; DDL reviewed by `DATA`,
 * `roles-s3-data.md`).
 *
 * **Never persisted new.** Rows come from `RoleGrantProposalCreation`'s one DBAL `INSERT … SELECT`, which names exactly
 * the eight column-granted columns, so `status` takes `DEFAULT 'pending'` and `proposed_at` / `expires_at` come from one
 * post-lock `clock_timestamp()` (S-11). The ORM only ever UPDATEs a loaded row through the four transitions below, and
 * nothing here writes `permission_codes`, `user_id`, `role_id`, `proposed_by_user_id`, `proposed_at` or `expires_at`
 * after insert — the column grant makes that a database fact (`erp_app` may UPDATE only the decision columns).
 *
 * **Four transitions, all present from C1** (`TransitionGuardTest` clause (b): every `STATUS_*` reachable by a declared
 * method), whose callers arrive over C1–C3: `lapse()` (C1, Create's lapse-on-touch), `confirm()` (C2), `reject()`,
 * `cancel()` (C3). Each FIRST refuses anything but `pending`, then sets `status` and `updatedAt` from `$at` — the
 * post-lock database instant (S-11) — `decidedAt` from `decisionInstant($at)`, and the decider where a human decides.
 * **Transitions read status
 * only** (RED R2-M5): confirmer ≠ proposer, canceller = proposer, E-15a and the `reason` length are the handlers' rules
 * (and CHECKs), never these methods'.
 *
 * `decided_at` is written at MICROSECOND precision (`DATA` §2): `role_grant_proposal_decided_not_before_proposed`
 * compares it with `proposed_at`, which the database writes with microseconds, and the default type drops the fraction —
 * a decision in the proposal's own second would land before it (`Invoice::$paidAt`'s precedent). The clamp below relies
 * on it too: `max($at, $proposedAt)` must land EXACTLY on `proposed_at`, not a fraction below it.
 *
 * **The decision is never dated before the proposal, even when the database clock steps back** (proposal clock fix,
 * 2026-10-21). `proposed_at` and `$at` are both `clock_timestamp()`, but read in two transactions, and that is a wall
 * clock: a backward step between them (the Lima host agent pulls a fast VM guest back ~0.1 s every few seconds; NTP can
 * step a server) made `$at` earlier than the proposal, and `role_grant_proposal_decided_not_before_proposed` answered
 * 23514 as a 500. `decisionInstant()` stores `max($at, $proposedAt)` — within `CLOCK_STEP_TOLERANCE_SECONDS`. A proposal
 * further ahead than that is no clock step: it was written outside the application (G-10's residual — SQL as `erp_app`
 * chooses `proposed_at`), and it is refused rather than silently decided in the future. Expiry is still decided on the
 * unclamped `$at` (the handlers' SQL), so the clamp moves only the stored decision instant.
 *
 * The four associations are single-column `ManyToOne`s — never a composite association (the composite FKs are database
 * constraints; `DoctrineMappingTest`). Handlers read the subject and the role by id through DBAL, never through these
 * proxies: `SoftDeleteFilter` is on for soft tables (RED L-2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'role_grant_proposal')]
#[ORM\UniqueConstraint(name: 'role_grant_proposal_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// One pending proposal per (user, role). The `where` is the catalogue's normalised spelling (M-3).
#[ORM\UniqueConstraint(name: 'role_grant_proposal_pending_unique', columns: ['tenant_id', 'user_id', 'role_id'], options: ['where' => "(status = 'pending'::text)"])]
#[ORM\Index(name: 'role_grant_proposal_pending_by_role', columns: ['tenant_id', 'role_id'], options: ['where' => "(status = 'pending'::text)"])]
// ListRoleGrantProposals' keyset, newest first, scanned backwards (no DESC: `SCHEMA.md` §9).
#[ORM\Index(name: 'role_grant_proposal_tenant_proposed', columns: ['tenant_id', 'proposed_at', 'id'])]
class RoleGrantProposal
{
    use GuardsStatusTransitions;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_LAPSED = 'lapsed';

    /** The house clock slack (`InvoicePayment::CLOCK_SKEW_SECONDS`): how far a proposal may lie ahead of the decision's clock. */
    public const CLOCK_STEP_TOLERANCE_SECONDS = 60;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field (A1.6), shared with no association below. */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    /** The subject: the person who would be given the role (named `user_id` for `pathBoundColumns()`, response R-7). */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private AppUser $user;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)]
    private Role $role;

    /**
     * The role's live codes at proposal time, sorted (C14's snapshot). Compared as a SET at confirmation, both sides
     * decoded and sorted in PHP (RED L-6; `DATA` D-5: the local cluster cannot reveal a collation bug).
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'permission_codes', type: Types::JSON, options: ['jsonb' => true])]
    private array $permissionCodes = [];

    #[ORM\Column(type: Types::TEXT, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'proposed_by_user_id', referencedColumnName: 'id', nullable: false)]
    private AppUser $proposedBy;

    #[ORM\Column(name: 'proposed_at', type: Types::DATETIMETZ_IMMUTABLE, insertable: false, updatable: false)]
    private \DateTimeImmutable $proposedAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE, insertable: false, updatable: false)]
    private \DateTimeImmutable $expiresAt;

    /** NULL on `pending` and `lapsed` (`role_grant_proposal_decider_iff_human`). Written by Confirm (C2), Reject, Cancel (C3). */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'decided_by_user_id', referencedColumnName: 'id', nullable: true)]
    private ?AppUser $decidedBy = null;

    #[ORM\Column(name: 'decided_at', type: TimestampTzMicrosecondsImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Only on `rejected`, 1–500 characters (`role_grant_proposal_reason_only_on_reject`). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    /** `expired` | `subject_removed` | `proposer_removed`, exactly when `lapsed`. */
    #[ORM\Column(name: 'lapse_reason', type: Types::TEXT, nullable: true)]
    private ?string $lapseReason = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, insertable: false, updatable: false, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    /** Never constructed: rows are inserted by DBAL (see the class docblock). */
    private function __construct()
    {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return list<string> */
    public function getPermissionCodes(): array
    {
        return $this->permissionCodes;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    /** §8.3: `pending → confirmed`. The confirmer is the granter of record (Q1). Caller: Confirm (C2). */
    public function confirm(\DateTimeImmutable $at, AppUser $by): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_PENDING]);
        $this->decidedAt = $this->decisionInstant($at); // first: a refused instant changes nothing

        $this->status = self::STATUS_CONFIRMED;
        $this->decidedBy = $by;
        $this->updatedAt = $at;
    }

    /** §8.3: `pending → rejected`, with an optional reason (the handler bounds it). Caller: Reject (C3). */
    public function reject(\DateTimeImmutable $at, AppUser $by, ?string $reason): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_PENDING]);
        $this->decidedAt = $this->decisionInstant($at); // first: a refused instant changes nothing

        $this->status = self::STATUS_REJECTED;
        $this->decidedBy = $by;
        $this->updatedAt = $at;
        $this->reason = $reason;
    }

    /** §8.3: `pending → cancelled` (the copy says "withdraw"). Caller: Cancel (C3). */
    public function cancel(\DateTimeImmutable $at, AppUser $by): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_PENDING]);
        $this->decidedAt = $this->decisionInstant($at); // first: a refused instant changes nothing

        $this->status = self::STATUS_CANCELLED;
        $this->decidedBy = $by;
        $this->updatedAt = $at;
    }

    /**
     * §8.3: `pending → lapsed`, no human decider. Writes exactly `status`, `decided_at`, `lapse_reason` and `updated_at` —
     * the four C1 column grants (M-26: a fifth column in this changeset is a `42501`; fix the entity, never the grant).
     * Callers: Create's lapse-on-touch (`expired`, C1); E-15g's paths later (`subject_removed`, `proposer_removed`).
     */
    public function lapse(string $reason, \DateTimeImmutable $at): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_PENDING]);
        $this->decidedAt = $this->decisionInstant($at); // first: a refused instant changes nothing

        $this->status = self::STATUS_LAPSED;
        $this->lapseReason = $reason;
        $this->updatedAt = $at;
    }

    /**
     * The stored decision instant: `$at`, or the proposal's own instant when the clock has stepped back past it (never
     * before what it decides — the CHECK is kept). Beyond the tolerance the row did not come from the application.
     */
    private function decisionInstant(\DateTimeImmutable $at): \DateTimeImmutable
    {
        if ($this->proposedAt > $at->modify('+'.self::CLOCK_STEP_TOLERANCE_SECONDS.' seconds')) {
            throw new \RuntimeException(\sprintf(
                'Role grant proposal %s was proposed at %s, more than %d seconds after the database clock now reads (%s). No '
                .'clock step explains that: the row was written outside the application (RESIDUAL_GAPS.md G-10). Nothing was decided.',
                $this->id->toRfc4122(), $this->proposedAt->format('Y-m-d H:i:s.uP'), self::CLOCK_STEP_TOLERANCE_SECONDS, $at->format('Y-m-d H:i:s.uP'),
            ));
        }

        return max($at, $this->proposedAt);
    }
}
