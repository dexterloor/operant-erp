<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Modules\UserManagement\Domain\GrantConflict;
use App\Platform\Http\Problem\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The grant rules — ONE copy, as five ordered phases, shared by every route that can make or propose a grant**
 * (roles slice 3, C1; `docs/session13/roles-s3-design.md` §4.7, revised after RED H-1 and RED-3 M-1/L-2/L-3).
 *
 * Confirmation re-runs every grant rule *"as if the confirmer were granting now"* (`SECURITY_MODEL.md` §5.2.4), and the
 * proposal route applies the same rules to the proposer. Two copies would be the design's first drift point — slice 2
 * extracted `RoleAdministrationActor` for the same reason. One call could not keep `AssignRole`'s order (routing sits
 * between E-15b and E-15c today, and Create needs its own step between C12 and PC-13), so the unit is five public phases,
 * each taking the grantor's lock-time codes and id, and **each route calls them in order and puts its own steps in named
 * slots** (the table in design §4.7):
 *
 *  1. {@see self::refuseSubjectRoleAndMarker()} — V2-R-6 (subject deleted / inactive, role archived), E-15a (grantor =
 *     subject), E-15b / Q5 (a role conferring the owner marker, by a grantor without it);
 *  2. {@see self::refuseBeyondGrantor()} — E-15c: a composed role whose codes are not a subset of the grantor's;
 *  3. {@see self::refuseAlreadyHeld()} — C12;
 *  4. {@see self::refuseConflict()} — PC-13 / E-18, "new conflict only" over the subject's live roles;
 *  5. {@see self::insert()} — the one `INSERT INTO user_role`, H1's four columns.
 *
 * **The slots are a convention, held by tests, not by this class** (RED-3 L-3): the phases are separate methods, so
 * nothing mechanical stops a step being placed elsewhere. The order is pinned by the two-failure boundary cases
 * (`GrantRoutingTest`; `RoleAssignTest::onlyAnOwnerGrantsARoleConferringTheMarker` for E-15b before routing).
 *
 * **One rule, one code (design D-5); one sentence per route where the shared sentence would be advice into a wall**
 * (RED R2-M1; `UX` `roles-s3-ux-copy.md` §1, §3): the route is a parameter, and only `actor_is_subject` and
 * `exceeds_actor_permissions` carry a per-route sentence in C1 (Create's #7 and #9). Every other sentence is the shipped
 * one, byte-identical on both routes. Confirm's sentences arrive with Confirm (C2).
 *
 * **Not here, deliberately:** routing (each route's own, in its own slot), the audit row (callers differ in `metadata`,
 * design §4.7), the lock and the actor re-check (`RoleAdministrationLock`, `RoleAdministrationActor`, always first).
 *
 * Every read is `LiveGrantReader` on the locked connection; every marker decision is `OwnerPredicate::holdsMarker()`
 * over lock-time sets. Home: `Application`, beside `RoleAssignment`, because it needs `OwnerPredicate`
 * (`GrantConfirmationPolicy`'s deviation, design §4.7).
 */
final class GrantRules
{
    /** `AssignRole`: the grantor gives the role now. */
    public const ROUTE_ASSIGN = 'assign';

    /** `CreateRoleGrantProposal`: the grantor proposes it, for an owner to confirm. */
    public const ROUTE_PROPOSE = 'propose';

    /**
     * The sentences that differ by route (`UX`: slice-2 copy §3.11 for `AssignRole`, `roles-s3-ux-copy.md` §3 #7 and #9
     * for Create), verbatim and pinned per route in each route's test.
     */
    private const PER_ROUTE = [
        'user_role.actor_is_subject' => [
            self::ROUTE_ASSIGN => 'Nobody can give themselves a role. Ask someone else who manages roles to do it.',
            self::ROUTE_PROPOSE => 'Nobody can propose a role for themselves. Ask someone else who manages roles to do it.',
        ],
        'user_role.exceeds_actor_permissions' => [
            self::ROUTE_ASSIGN => 'This role includes permissions you don\'t have yourself, so you can\'t give it to anyone. Ask an owner, or someone who holds all of its permissions.',
            self::ROUTE_PROPOSE => 'This role includes permissions you don\'t have yourself, so you can\'t propose it. Ask an owner, or someone who holds all of its permissions.',
        ],
    ];

    /**
     * The path's subject and the body's role, as the lock sees them — the shared reading both routes do before phase 1
     * (design §4.2 steps 1–2 = `AssignRole`'s step 3): `{id}` not a uuid, absent or another workspace's → 404; `role_id`
     * absent → 422 `required`, not a uuid → `not_a_uuid`, no such role here → `unknown_reference` (archived roles ARE
     * found, so they reach phase 1's `role_is_archived`, never this 422).
     *
     * @return array{user_id: string, subject: array{deleted: bool, is_active: bool}, role_id: string, role: array{name: string, live: bool, is_system: bool, codes: list<string>}}
     */
    public static function subjectAndRole(EntityManagerInterface $em, string $userId, ?string $roleId): array
    {
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

        return ['user_id' => $userId, 'subject' => $subject, 'role_id' => $roleId, 'role' => $role];
    }

    /**
     * Phase 1 — V2-R-6, E-15a, E-15b, in that order.
     *
     * @param array{deleted: bool, is_active: bool}                                    $subject
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     * @param list<string>                                                             $grantorCodes lock-time
     */
    public static function refuseSubjectRoleAndMarker(string $route, array $subject, array $role, string $subjectId, string $grantorId, array $grantorCodes): void
    {
        if ($subject['deleted']) {
            throw ApiProblem::businessRuleViolated('user_role.subject_is_deleted', 'This person has been removed from the workspace, so they can\'t be given a role.');
        }
        if (!$subject['is_active']) {
            throw ApiProblem::businessRuleViolated('user_role.subject_is_inactive', 'This person\'s account is not active, so they can\'t be given a role.');
        }
        if (!$role['live']) {
            throw ApiProblem::businessRuleViolated('user_role.role_is_archived', 'This role is archived, so it can\'t be given to anyone. Choose another role.');
        }

        if ($subjectId === Uuid::fromString($grantorId)->toRfc4122()) {
            throw self::refused('user_role.actor_is_subject', $route);
        }

        // E-15b / Q5: the same sentence on every route, and it names no route, ever (`roles-s2-ux-copy.md` §3.4).
        if (OwnerPredicate::holdsMarker($role['codes']) && !OwnerPredicate::holdsMarker($grantorCodes)) {
            throw ApiProblem::businessRuleViolated('user_role.marker_needs_owner', 'Only an owner can give someone this role, because it makes them an owner.');
        }
    }

    /**
     * Phase 2 — E-15c: a composed role (`is_system = false`) whose codes are not a subset of the grantor's. System roles
     * are not judged (`RoleAssignment.php`'s shipped `!$role['is_system'] && …`).
     *
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     * @param list<string>                                                             $grantorCodes
     */
    public static function refuseBeyondGrantor(string $route, array $role, array $grantorCodes): void
    {
        if (!$role['is_system'] && [] !== array_diff($role['codes'], $grantorCodes)) {
            throw self::refused('user_role.exceeds_actor_permissions', $route);
        }
    }

    /** Phase 3 — C12: the subject already holds this role row (live or archived role; `LiveGrantReader::grant()`). */
    public static function refuseAlreadyHeld(EntityManagerInterface $em, string $subjectId, string $roleId): void
    {
        if (null !== LiveGrantReader::grant($em, $subjectId, $roleId)) {
            throw self::alreadyHeld();
        }
    }

    /**
     * Phase 4 — PC-13 / E-18, "new conflict only" ({@see GrantConflict::newRows()}) over the subject's LIVE roles as they
     * stand under the lock, naming each SoD row id in `UX`'s sentence, never the codes.
     *
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     */
    public static function refuseConflict(EntityManagerInterface $em, string $subjectId, array $role): void
    {
        $conflicts = GrantConflict::newRows(LiveGrantReader::codesHeldBy($em, $subjectId), $role['codes']);
        if ([] !== $conflicts) {
            throw ApiProblem::businessRuleViolated('user_role.separation_of_duty', RoleAssignment::separationOfDutyMessage($role['name'], $conflicts));
        }
    }

    /**
     * Phase 5 — `INSERT INTO user_role (id, tenant_id, user_id, role_id)`: exactly H1's four columns, the only ones
     * `erp_app` may insert (`Version20260930000100`); `inserted_by` is the database's `session_user`. The one statement
     * every grant goes through (`AssignRole` now, confirmation at C2).
     */
    public static function insert(EntityManagerInterface $em, string $tenantId, string $subjectId, string $roleId): void
    {
        $em->getConnection()->executeStatement(
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [Uuid::v4()->toRfc4122(), $tenantId, $subjectId, $roleId],
        );
    }

    /** C12's refusal, also what a translated `23505` on `user_role_unique` answers (`RoleAssignment::assign()`). */
    public static function alreadyHeld(): ApiProblem
    {
        return ApiProblem::businessRuleViolated('user_role.already_held', 'This person already has this role.');
    }

    private static function refused(string $rule, string $route): ApiProblem
    {
        $sentence = self::PER_ROUTE[$rule][$route] ?? throw new \LogicException(\sprintf('No sentence for %s on route %s.', $rule, $route));

        return ApiProblem::businessRuleViolated($rule, $sentence);
    }

    private static function roleIdRefused(string $code, string $message): ApiProblem
    {
        return ApiProblem::validationFailed([['pointer' => '/role_id', 'code' => $code, 'message' => $message]]);
    }

    /** Not instantiable: pure phases over the locked transaction's own connection. */
    private function __construct()
    {
    }
}
