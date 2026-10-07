<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\DenialAuditWriter;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **The actor's re-check under the role-administration lock — ONE copy, shared by every role-administration operation**
 * (roles slice 2; design §5.1 steps 2–3, §5.2 step 2; `SEC` S-2 as acknowledged, `roles-s2-sec.md` §Acknowledgement A;
 * mechanism `roles-s2-spar.md` P13). Extracted in C3 from `RoleRevocation` so the grant does not carry a second copy of the
 * S-2 mechanism (`SEC`'s C2 acknowledgement §3: *"`RoleAssignment` must reuse `LockTimeDenial` and `denialSubjectOf()`, not
 * copy them"*).
 *
 * Called immediately after `RoleAdministrationLock::holdInside()`, before ANY read of the subject (E-1: otherwise a demoted
 * actor would get 404 for an absent user and 403 for a real one — an existence oracle):
 *  1. The actor's codes are re-derived under the lock (`LiveGrantReader`, S-6). **Missing the operation's own code** → the
 *     `authz.permission.denied` row is written HERE, through the gate's own writer, with the pair the gate's own resolver
 *     gave the processor (`PermissionGate::denialSubjectOf()`), and a {@see LockTimeDenial} is RETURNED. The caller returns
 *     it out of `TenantContext::run()`, the transaction commits having written nothing else (A.3), and the 403 is thrown
 *     outside. Never thrown across `run()` (the EM closes and the row is lost — S2-M1′).
 *  2. **An inactive actor** → 409 `user_role.actor_is_inactive`, no row — AFTER the permission re-check, so an actor both
 *     inactive and without the code gets the 403 and its row (`SEC` §2.4). When API-O-22 is ruled this takes its status.
 *
 * Returns the actor's lock-time codes on success — the set every later decision of the operation reads.
 *
 * **The required code is the caller's, not this class's (roles slice 4 C0, design F-1).** Until C0 the class hard-coded
 * `role.assign`, which was right only while every caller was gated on it. `CreateRole`/`UpdateRole`/`DeleteRole` are gated on
 * `role.create`/`role.edit`/`role.delete`; re-checked against `role.assign` they would be refused for the wrong code, or
 * admitted on the wrong one. So each caller passes its OWN §4.2 `Perm` code — the same string its `PermissionGate`
 * declaration carries — and the denial row and the {@see LockTimeDenial} name that code.
 * `RoleAdministrationActorCallerTest` holds every caller to the code it passed when C0 landed.
 */
final readonly class RoleAdministrationActor
{
    public function __construct(private DenialAuditWriter $denials)
    {
    }

    /**
     * @param array{0: ?string, 1: ?string} $denialSubject      what `PermissionGate::denialSubjectOf()` resolved for this request
     * @param string                         $requiredPermission the operation's own §4.2 `Perm` code — the gate's, re-derived here
     *
     * @return list<string>|LockTimeDenial the actor's codes under the lock, or the refusal to return out of `run()`
     */
    public function reCheckUnderTheLock(EntityManagerInterface $em, Identity $identity, ?string $ipAddress, array $denialSubject, string $requiredPermission): array|LockTimeDenial
    {
        $actorCodes = LiveGrantReader::codesHeldBy($em, $identity->userId);
        if (!\in_array($requiredPermission, $actorCodes, true)) {
            $this->denials->record(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                permissionCode: $requiredPermission,
                subjectId: $denialSubject[1],
                ipAddress: $ipAddress,
                subjectType: $denialSubject[0],
            );

            return new LockTimeDenial($requiredPermission);
        }

        $actor = LiveGrantReader::user($em, $identity->userId);
        if (null === $actor || !$actor['is_active']) {
            throw ApiProblem::businessRuleViolated('user_role.actor_is_inactive', 'Your account is not active, so you can\'t change anyone\'s roles.');
        }

        return $actorCodes;
    }
}
