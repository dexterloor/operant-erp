<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Modules\UserManagement\Domain\GrantConflict;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`AssignRole` — give one user one role, under every rule E-15 writes** (roles slice 2, C3;
 * `docs/session13/roles-s2-design.md` §5.2 as amended by `roles-s2-spar.md` §2 and `roles-s2-sec.md`).
 *
 * One transaction, in this order — the order is the design, and several steps are integrity properties:
 *  1. **`RoleAdministrationLock`**, the first statement after `set_config` (§5.2.5; positional test in
 *     `PurchaseOrderRowLockTest`). Every read below sees what the previous holder committed.
 *  2. **The actor, re-checked under the lock** — {@see RoleAdministrationActor}, the one copy shared with `RevokeRole`:
 *     missing `role.assign` → the denial row inside this transaction, a {@see LockTimeDenial} returned, commit, the gate's
 *     own 403 thrown outside `run()` (S-2); inactive → 409 `user_role.actor_is_inactive`. Before ANY subject read (E-1).
 *  3. **The subject and the role.** `{id}` not a uuid, absent or another workspace's → 404. `role_id` absent → 422
 *     `required`; not a uuid → 422 `not_a_uuid`; no role with that id in this workspace → 422 `unknown_reference` (the
 *     `CreateSalesOrder` body-reference precedent; ST-5: another workspace's id is indistinguishable from none). **V2-R-6**
 *     (ruled 2026-09-22): subject soft-deleted → 409 `user_role.subject_is_deleted`; subject `is_active = false` → 409
 *     `user_role.subject_is_inactive`; role archived → 409 `user_role.role_is_archived`.
 *  4. **E-15a:** nobody grants themselves a role → 409 `user_role.actor_is_subject`.
 *  5. **E-15b / Q5:** a role conferring the owner marker is granted only by an actor holding it → 409
 *     `user_role.marker_needs_owner` — it takes NEITHER route (the proposal route refuses it too), so no route is named.
 *  6. **E-15e routing:** {@see GrantConfirmationPolicy} → 409 `user_role.needs_owner_confirmation`. Until slice 3 the refusal
 *     names no route; slice 3 adds the proposal route to the body without changing the code.
 *  7. **E-15c:** a composed role (`is_system = false`) whose codes are not a subset of the actor's → 409
 *     `user_role.exceeds_actor_permissions`.
 *  8. **C12:** already held → 409 `user_role.already_held` (a race loser against an out-of-band writer gets the same code
 *     from the translated `23505` on `user_role_unique`).
 *  9. **PC-13 / E-18:** {@see GrantConflict::newRows()} over the subject's LIVE roles → 409 `user_role.separation_of_duty`,
 *     naming each SoD row id, never the codes.
 * 10. `INSERT INTO user_role (id, tenant_id, user_id, role_id)` — exactly H1's four columns, the only ones `erp_app` may
 *     insert (`Version20260930000100`); `inserted_by` is the database's `session_user`.
 * 11. `role.assigned`, subject the user, `{role_id}`, the request's IP, `clock_timestamp()`.
 * 12. **The body (`SEC` S-1 (b)):** the granted role in `GetRole`'s shape, read through `RoleReader::get()` AFTER the insert
 *     and the audit write, in the same locked transaction (same tenant ⇒ `run()` joins), so `holder_count` includes this
 *     grant. `CreatedRowLocation` reads its `id` to fill `{roleId}` in `Location`.
 *
 * **The refusal sentences are `UX`'s** (`docs/session13/roles-s2-ux-copy.md` §3.11), verbatim and pinned as literals in
 * `RoleAssignTest`. `actor_is_inactive` is `RoleAdministrationActor`'s, shared with `RevokeRole` and unchanged in C3.
 *
 * Every decision reads `LiveGrantReader` on the locked connection; every caller decision about the marker goes through
 * `OwnerPredicate::holdsMarker()` over that lock-time set. No PHP clock stamps or decides anything.
 */
final readonly class RoleAssignment
{
    /**
     * **`UX`'s clause per SoD row** (`docs/session13/roles-s2-ux-copy.md` §3.10) — copy keyed by row id, naming the work and
     * never a code, so it is not a second copy of `SeparationOfDuty::ROWS`. It can fall behind them, so
     * `RoleAssignTest::everySeparationOfDutyRowHasItsSentence` holds its keys equal to `SeparationOfDuty::rowIds()`: SoD-5
     * (with `CreateSalesReturn` / A2.1b) cannot ship without its sentence, and A2.1b rewords SoD-3's (*"… record their
     * payment, void them or credit them"*).
     */
    public const SOD_CLAUSES = [
        'SoD-1' => 'raise purchase orders and also approve or cancel them',
        'SoD-2' => 'raise or approve purchase orders and also record the goods as received',
        'SoD-3' => 'issue invoices and also record their payment or void them',
        'SoD-4' => 'confirm sales orders or issue invoices and also ship the goods',
    ];

    /** The unique index a concurrent out-of-band grant collides on (`Version20260906000100`). */
    private const UNIQUE_INDEX = 'user_role_unique';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private RoleAuditWriter $audit,
        private RoleAdministrationActor $actor,
        private RoleReader $roles,
    ) {
    }

    /**
     * @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request
     */
    public function assign(string $userId, ?string $roleId, ?string $ipAddress, array $denialSubject): RoleView
    {
        $identity = $this->identities->authenticated();

        try {
            $outcome = $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): RoleView|LockTimeDenial => $this->assignInside($em, $identity, $userId, $roleId, $ipAddress, $denialSubject),
            );
        } catch (UniqueConstraintViolationException $e) {
            // Caught OUTSIDE `run()`: the failed statement aborted the transaction and closed the entity manager. Only an
            // out-of-band writer (the owner process, which never takes the lock) can insert the pair between step 8 and 10.
            if (str_contains($e->getMessage(), self::UNIQUE_INDEX)) {
                throw self::alreadyHeld();
            }

            throw $e;
        }

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function assignInside(EntityManagerInterface $em, Identity $identity, string $userId, ?string $roleId, ?string $ipAddress, array $denialSubject): RoleView|LockTimeDenial
    {
        // 1.
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        // 2. Before any read of the subject (E-1).
        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject);
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        // 3. The subject, then the role named in the body.
        if (!Uuid::isValid($userId)) {
            throw ApiProblem::notFound();
        }
        $userId = Uuid::fromString($userId)->toRfc4122();
        $subject = LiveGrantReader::user($em, $userId) ?? throw ApiProblem::notFound();

        if (null === $roleId) {
            throw self::roleIdRefused('required', 'This field is required.');
        }
        if (!Uuid::isValid($roleId)) {
            throw self::roleIdRefused('not_a_uuid', 'This field must be a UUID naming a role in this workspace.');
        }
        $roleId = Uuid::fromString($roleId)->toRfc4122();
        $role = LiveGrantReader::role($em, $roleId) ?? throw self::roleIdRefused('unknown_reference', 'No role in this workspace has this id.');

        if ($subject['deleted']) {
            throw ApiProblem::businessRuleViolated('user_role.subject_is_deleted', 'This person has been removed from the workspace, so they can\'t be given a role.');
        }
        if (!$subject['is_active']) {
            throw ApiProblem::businessRuleViolated('user_role.subject_is_inactive', 'This person\'s account is not active, so they can\'t be given a role.');
        }
        if (!$role['live']) {
            throw ApiProblem::businessRuleViolated('user_role.role_is_archived', 'This role is archived, so it can\'t be given to anyone. Choose another role.');
        }

        // 4.
        if ($userId === Uuid::fromString($identity->userId)->toRfc4122()) {
            throw ApiProblem::businessRuleViolated('user_role.actor_is_subject', 'Nobody can give themselves a role. Ask someone else who manages roles to do it.');
        }

        // 5.
        if (OwnerPredicate::holdsMarker($role['codes']) && !OwnerPredicate::holdsMarker($actorCodes)) {
            throw ApiProblem::businessRuleViolated('user_role.marker_needs_owner', 'Only an owner can give someone this role, because it makes them an owner.');
        }

        // 6.
        if (GrantConfirmationPolicy::needsConfirmation($actorCodes, $role['codes'])) {
            throw ApiProblem::businessRuleViolated('user_role.needs_owner_confirmation', 'Only an owner can give someone this role, because it includes permissions that only an owner can hand out. Ask an owner to give it.');
        }

        // 7.
        if (!$role['is_system'] && [] !== array_diff($role['codes'], $actorCodes)) {
            throw ApiProblem::businessRuleViolated('user_role.exceeds_actor_permissions', 'This role includes permissions you don\'t have yourself, so you can\'t give it to anyone. Ask an owner, or someone who holds all of its permissions.');
        }

        // 8.
        if (null !== LiveGrantReader::grant($em, $userId, $roleId)) {
            throw self::alreadyHeld();
        }

        // 9.
        $conflicts = GrantConflict::newRows(LiveGrantReader::codesHeldBy($em, $userId), $role['codes']);
        if ([] !== $conflicts) {
            throw ApiProblem::businessRuleViolated('user_role.separation_of_duty', self::separationOfDutyMessage($role['name'], $conflicts));
        }

        // 10.
        $em->getConnection()->executeStatement(
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [Uuid::v4()->toRfc4122(), $identity->tenantId, $userId, $roleId],
        );

        // 11.
        $this->audit->recordAssigned(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            subjectUserId: $userId,
            roleId: $roleId,
            ipAddress: $ipAddress,
        );

        // 12. After the insert and the audit write, under the lock (S-1 (b)).
        return $this->roles->get($roleId) ?? throw new \LogicException('The role just granted is not visible to its own transaction.');
    }

    /**
     * `UX`'s sentence (`roles-s2-ux-copy.md` §3.10): the role by name, one clause per SoD row about the WORK (never a code),
     * the row ids in brackets at the end.
     *
     * @param list<string> $rows
     */
    public static function separationOfDutyMessage(string $roleName, array $rows): string
    {
        $clauses = array_map(static fn (string $row): string => self::SOD_CLAUSES[$row] ?? throw new \LogicException(
            'SoD row '.$row.' has no sentence in RoleAssignment::SOD_CLAUSES.'), $rows);
        $last = array_pop($clauses);
        $joined = [] === $clauses ? (string) $last : implode(', ', $clauses).' and '.$last;

        return \sprintf('%s can\'t be given to this person: with the roles they already have, one person could %s (%s). '
            .'Choose another role or another person.', $roleName, $joined, implode(', ', $rows));
    }

    private static function alreadyHeld(): ApiProblem
    {
        return ApiProblem::businessRuleViolated('user_role.already_held', 'This person already has this role.');
    }

    private static function roleIdRefused(string $code, string $message): ApiProblem
    {
        return ApiProblem::validationFailed([['pointer' => '/role_id', 'code' => $code, 'message' => $message]]);
    }
}
