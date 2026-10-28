<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Modules\UserManagement\Domain\GrantConflict;
use App\Modules\UserManagement\Domain\HeldSpanConflict;
use App\Modules\UserManagement\Domain\SeparationOfDuty;
use App\Platform\Http\Problem\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The grant rules — ONE copy, as five ordered phases, shared by every route that can make or propose a grant**
 * (roles slice 3, C1; `docs/session13/roles-s3-design.md` §4.7, revised after RED H-1 and RED-3 M-1/L-2/L-3). Since C2
 * every route calls the phases in order with its routing LAST (slot R): `AssignRole`, Create (inverse routing) and
 * Confirm (routing not re-tested: the confirmer is an owner, checked under the lock).
 *
 * Confirmation re-runs every grant rule *"as if the confirmer were granting now"* (`SECURITY_MODEL.md` §5.2.4), and the
 * proposal route applies the same rules to the proposer. Two copies would be the design's first drift point — slice 2
 * extracted `RoleAdministrationActor` for the same reason. One call could not keep `AssignRole`'s order (routing sat
 * between E-15b and E-15c until C2, and Create needs its own step between C12 and PC-13), so the unit is five public phases,
 * each taking the grantor's lock-time codes and id, and **each route calls them in order and puts its own steps in named
 * slots** (the table in design §4.7):
 *
 *  1. {@see self::refuseSubjectRoleAndMarker()} — V2-R-6 (subject deleted / inactive, role archived), E-15a (grantor =
 *     subject), E-15b / Q5 (a role conferring the owner marker, by a grantor without it);
 *  2. {@see self::refuseBeyondGrantor()} — E-15c: a composed role whose codes are not a subset of the grantor's;
 *  3. {@see self::refuseAlreadyHeld()} — C12;
 *  4. {@see self::refuseConflict()} — PC-13 / E-18, "new conflict only" over the subject's live roles, in two clauses:
 *     a row the grant newly spans, and (G-91) a new pair on a row the subject already spans;
 *  5. {@see self::insert()} — the one `INSERT INTO user_role`, H1's four columns.
 *
 * **Since users slice 1 C1a, phases 1–4 are also VERDICTS** ({@see self::firstVerdict()} and the four `*Verdict()`
 * functions): pure functions of their inputs returning a {@see GrantVerdict} or null. The throwing phases are those
 * verdicts plus {@see self::problem()}, so the picker read (`ListOfferedRoles`, C3) evaluates the same rules without a
 * second copy. Behaviour-neutral: `GrantRulesCharacterisationTest` (C0) is its proof, unedited.
 *
 * **The slots are a convention, held by tests, not by this class** (RED-3 L-3): the phases are separate methods, so
 * nothing mechanical stops a step being placed elsewhere. The order is pinned by the two-failure boundary cases
 * (`GrantRoutingTest`; `RoleAssignTest::onlyAnOwnerGrantsARoleConferringTheMarker` for E-15b before routing).
 *
 * **One rule, one code (design D-5); one sentence per route where the shared sentence would be advice into a wall**
 * (RED R2-M1; `UX` `roles-s3-ux-copy.md` §1, §3, §4.1): the route is a parameter. `actor_is_subject` and
 * `exceeds_actor_permissions` carry a per-route sentence (Create's #7 and #9 since C1; Confirm's #22 and #25 since C2),
 * and since C2 `role_is_archived` does too (Confirm's #21) and PC-13's sentence takes its last clause by route (Confirm's
 * #27 — `UX` flag F-2, ruled 2026-10-05: one map keyed by route constant, a missing entry throws). On `AssignRole` and
 * Create every sentence is the shipped one, byte-identical.
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

    /** `ConfirmRoleGrantProposal` (C2): an owner confirms a proposal, re-running every rule as if granting now (§5.2.4). */
    public const ROUTE_CONFIRM = 'confirm';

    /**
     * The sentences that differ by route (`UX`: slice-2 copy §3.11 for `AssignRole`, `roles-s3-ux-copy.md` §3 #6/#7/#9
     * for Create, §4.1 #21/#22/#25 for Confirm), verbatim and pinned per route in each route's test. Confirm's texts are
     * the C3 ones since roles slice 3 C3 (`UX` rule 4: they say "reject it" only once Reject exists).
     */
    private const PER_ROUTE = [
        'user_role.role_is_archived' => [
            self::ROUTE_ASSIGN => 'This role is archived, so it can\'t be given to anyone. Choose another role.',
            self::ROUTE_PROPOSE => 'This role is archived, so it can\'t be given to anyone. Choose another role.',
            // #21: "Choose another role" is a wall for a confirmer, who cannot change a proposal's role (R2-M1; F-2).
            self::ROUTE_CONFIRM => 'This role is archived, so it can\'t be given to anyone, and this proposal can\'t be confirmed. Reject it to close it.',
        ],
        'user_role.actor_is_subject' => [
            self::ROUTE_ASSIGN => 'Nobody can give themselves a role. Ask someone else who manages roles to do it.',
            self::ROUTE_PROPOSE => 'Nobody can propose a role for themselves. Ask someone else who manages roles to do it.',
            self::ROUTE_CONFIRM => 'You can\'t confirm a role proposed for yourself. Another owner has to confirm it. You can reject it if you don\'t want it.',
        ],
        'user_role.exceeds_actor_permissions' => [
            self::ROUTE_ASSIGN => 'This role includes permissions you don\'t have yourself, so you can\'t give it to anyone. Ask an owner, or someone who holds all of its permissions.',
            self::ROUTE_PROPOSE => 'This role includes permissions you don\'t have yourself, so you can\'t propose it. Ask an owner, or someone who holds all of its permissions.',
            self::ROUTE_CONFIRM => 'This role includes permissions you don\'t have yourself, so you can\'t confirm it. An owner who holds all of its permissions can.',
        ],
    ];

    /**
     * PC-13's sentence ends with what the reader can do, by route (`UX` flag F-2, ruled 2026-10-05, #27): the granter and
     * the proposer can choose another role or person — the shipped tail, so `AssignRole`'s and Create's sentences are
     * byte-identical — and a confirmer cannot, so Confirm's tail says the proposal can't be confirmed and how to close it
     * (C3 text; C2 read only "This proposal can't be confirmed."). A route with no entry throws, never a silent default.
     */
    private const SOD_TAILS = [
        self::ROUTE_ASSIGN => RoleAssignment::SOD_SHIPPED_TAIL,
        self::ROUTE_PROPOSE => RoleAssignment::SOD_SHIPPED_TAIL,
        self::ROUTE_CONFIRM => 'This proposal can\'t be confirmed. Reject it to close it.',
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
     * Phase 1 — V2-R-6, E-15a, E-15b, in that order. Throws {@see self::subjectRoleAndMarkerVerdict()}'s verdict.
     *
     * @param array{deleted: bool, is_active: bool}                                    $subject
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     * @param list<string>                                                             $grantorCodes lock-time
     */
    public static function refuseSubjectRoleAndMarker(string $route, array $subject, array $role, string $subjectId, string $grantorId, array $grantorCodes): void
    {
        self::throwIf(self::subjectRoleAndMarkerVerdict($subject, $role, $subjectId, $grantorId, $grantorCodes), $route);
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
        self::throwIf(self::beyondGrantorVerdict($role, $grantorCodes), $route);
    }

    /** Phase 3 — C12: the subject already holds this role row (live or archived role; `LiveGrantReader::grant()`). */
    public static function refuseAlreadyHeld(EntityManagerInterface $em, string $subjectId, string $roleId): void
    {
        if (null !== self::alreadyHeldVerdict(null !== LiveGrantReader::grant($em, $subjectId, $roleId))) {
            throw self::alreadyHeld();
        }
    }

    /**
     * Phase 4 — PC-13 / E-18, "new conflict only" over the subject's LIVE roles as they stand under the lock, in two
     * clauses ({@see self::conflictVerdict()}): {@see GrantConflict::newRows()} (a row the grant newly spans) and, since
     * G-91 (BK-11), {@see HeldSpanConflict::newEdgeRows()} (a new pair on a row the subject already spans and the role does
     * not span alone). Each SoD row id is named in `UX`'s sentence, never the codes; the sentence's last clause is the
     * route's ({@see self::SOD_TAILS}).
     *
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     */
    public static function refuseConflict(string $route, EntityManagerInterface $em, string $subjectId, array $role): void
    {
        self::SOD_TAILS[$route] ?? throw new \LogicException(\sprintf('No separation-of-duty tail for route %s.', $route));
        self::throwIf(self::conflictVerdict(LiveGrantReader::codesHeldBy($em, $subjectId), $role), $route);
    }

    // -----------------------------------------------------------------------
    // The phases as VERDICTS (users slice 1 C1a; `docs/session13/users-s1-design.md` rev 3 §6 C1a). Pure: every input is
    // passed in, nothing is read, nothing is thrown. The throwing phases above are these plus {@see self::problem()}, so
    // there is ONE copy of each rule — the picker (`ListOfferedRoles`, C3) evaluates the same functions from its one
    // statement. A verdict is `{code, data}`, not a bare code: PC-13's sentence needs the rows and the role's name, and
    // the per-route sentences need the route, which {@see self::problem()} takes.
    //
    // **Two held sets, never derived from each other** (RED-3 H-1/F-1): phase 3's input is whether ANY grant row exists
    // for the pair (`LiveGrantReader::grant()`: live OR archived role), phase 4's is the union of the codes of the
    // subject's grants on LIVE roles only (`LiveGrantReader::codesHeldBy()`). Feeding phase 4 the archived-inclusive set
    // would refuse grants that ship as 201 (`GrantRulesCharacterisationTest::anArchivedHeldRoleDoesNotCountTowardsSeparationOfDuty`).
    // -----------------------------------------------------------------------

    /**
     * Phase 1's verdict: the first of `subject_is_deleted`, `subject_is_inactive`, `role_is_archived`, `actor_is_subject`,
     * `marker_needs_owner` that applies, or null.
     *
     * @param array{deleted: bool, is_active: bool}                                    $subject
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     * @param list<string>                                                             $grantorCodes
     */
    public static function subjectRoleAndMarkerVerdict(array $subject, array $role, string $subjectId, string $grantorId, array $grantorCodes): ?GrantVerdict
    {
        if ($subject['deleted']) {
            return new GrantVerdict('user_role.subject_is_deleted');
        }
        if (!$subject['is_active']) {
            return new GrantVerdict('user_role.subject_is_inactive');
        }
        $archived = self::archivedVerdict($role);
        if (null !== $archived) {
            return $archived;
        }

        if ($subjectId === Uuid::fromString($grantorId)->toRfc4122()) {
            return new GrantVerdict('user_role.actor_is_subject');
        }

        return self::markerVerdict($role, $grantorCodes);
    }

    /**
     * **The rules that depend on (grantor, role) ALONE, in phase order** (G5-C1, `CreateBulkRoleAssignment`; design
     * `g5-g6-bulk-export-design.md` §4.2 step 5: *"split the marker half out of `subjectRoleAndMarkerVerdict`, or call the
     * role-only parts"*): `role_is_archived`, `marker_needs_owner`, then phase 2's `exceeds_actor_permissions`. A bulk
     * request applies these ONCE, before any member id is read (E-14), because each answers the same for every member;
     * each member then re-runs {@see self::firstVerdict()} in full, where these three pass again. Pure, like the others.
     *
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     * @param list<string>                                                             $grantorCodes
     */
    public static function roleVerdict(array $role, array $grantorCodes): ?GrantVerdict
    {
        return self::archivedVerdict($role) ?? self::markerVerdict($role, $grantorCodes) ?? self::beyondGrantorVerdict($role, $grantorCodes);
    }

    /**
     * V2-R-6's role half: an archived role is given to nobody. ONE copy (G5-C1, `g5-c1-decisions-red.md` L-2), shared by
     * phase 1 and {@see self::roleVerdict()}.
     *
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     */
    private static function archivedVerdict(array $role): ?GrantVerdict
    {
        return $role['live'] ? null : new GrantVerdict('user_role.role_is_archived');
    }

    /**
     * E-15b / Q5: the same sentence on every route, and it names no route, ever (`roles-s2-ux-copy.md` §3.4). The ONE
     * place this class asks {@see OwnerPredicate} about a role (G5-C1 extracted it so the bulk route's wholesale check and
     * phase 1 share it).
     *
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     * @param list<string>                                                             $grantorCodes
     */
    private static function markerVerdict(array $role, array $grantorCodes): ?GrantVerdict
    {
        return OwnerPredicate::holdsMarker($role['codes']) && !OwnerPredicate::holdsMarker($grantorCodes)
            ? new GrantVerdict('user_role.marker_needs_owner')
            : null;
    }

    /**
     * Phase 2's verdict: `exceeds_actor_permissions` for a composed role beyond the grantor's codes, else null.
     *
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     * @param list<string>                                                             $grantorCodes
     */
    public static function beyondGrantorVerdict(array $role, array $grantorCodes): ?GrantVerdict
    {
        return !$role['is_system'] && [] !== array_diff($role['codes'], $grantorCodes)
            ? new GrantVerdict('user_role.exceeds_actor_permissions')
            : null;
    }

    /** Phase 3's verdict: `already_held` when a grant row for the pair exists (live or archived role), else null. */
    public static function alreadyHeldVerdict(bool $grantExists): ?GrantVerdict
    {
        return $grantExists ? new GrantVerdict('user_role.already_held') : null;
    }

    /**
     * Phase 4's verdict: `separation_of_duty` with `{rows, role_name}` when the grant newly spans a SoD row over the
     * subject's LIVE held codes, or (G-91, BK-11; `SEC` `sec-held-conflict.md` R2.1) adds a new preparing × controlled pair on
     * a row the subject already spans and the role does not span alone; else null. The rows are the union of the two clauses,
     * de-duplicated, in §2's order. Keeping `newRows` in the union means no refusal it gives can be lost to a defect in the
     * held clause; for anyone who combines nothing the two agree (`HeldSpanConflictTest`), so the held clause only adds
     * refusals for people who already combine two duties. `GrantConflict::newRows` itself is unchanged: its pairwise callers
     * (the picker's `conflicts_with`, PC-12's margin partners) must not move.
     *
     * @param list<string>                                                             $heldLiveCodes the union of the codes of the subject's grants on LIVE roles
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     */
    public static function conflictVerdict(array $heldLiveCodes, array $role): ?GrantVerdict
    {
        $found = [...GrantConflict::newRows($heldLiveCodes, $role['codes']), ...HeldSpanConflict::newEdgeRows($heldLiveCodes, $role['codes'])];
        $conflicts = array_values(array_filter(SeparationOfDuty::rowIds(), static fn (string $id): bool => \in_array($id, $found, true)));

        return [] === $conflicts ? null : new GrantVerdict('user_role.separation_of_duty', ['rows' => $conflicts, 'role_name' => $role['name']]);
    }

    /**
     * Phases 1–4 in order with NO slot — the picker's walk (C3: it omits slot B by owner ruling Q-1 and runs no routing;
     * its `requires_owner_confirmation` is {@see GrantConfirmationPolicy}, read only when this returns null).
     *
     * @param array{deleted: bool, is_active: bool}                                    $subject
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     * @param list<string>                                                             $grantorCodes
     * @param list<string>                                                             $heldLiveCodes
     */
    public static function firstVerdict(array $subject, array $role, string $subjectId, string $grantorId, array $grantorCodes, bool $grantExists, array $heldLiveCodes): ?GrantVerdict
    {
        return self::subjectRoleAndMarkerVerdict($subject, $role, $subjectId, $grantorId, $grantorCodes)
            ?? self::beyondGrantorVerdict($role, $grantorCodes)
            ?? self::alreadyHeldVerdict($grantExists)
            ?? self::conflictVerdict($heldLiveCodes, $role);
    }

    /**
     * The refusal a verdict is on `$route` — every sentence the shipped throwing phases answered, byte-identical.
     */
    public static function problem(GrantVerdict $verdict, string $route): ApiProblem
    {
        return match ($verdict->code) {
            'user_role.subject_is_deleted' => ApiProblem::businessRuleViolated($verdict->code, 'This person has been removed from the workspace, so they can\'t be given a role.'),
            'user_role.subject_is_inactive' => ApiProblem::businessRuleViolated($verdict->code, 'This person\'s account is not active, so they can\'t be given a role.'),
            'user_role.marker_needs_owner' => ApiProblem::businessRuleViolated($verdict->code, 'Only an owner can give someone this role, because it makes them an owner.'),
            'user_role.already_held' => self::alreadyHeld(),
            'user_role.separation_of_duty' => ApiProblem::businessRuleViolated($verdict->code, RoleAssignment::separationOfDutyMessage(
                (string) $verdict->data['role_name'],
                $verdict->data['rows'],
                self::SOD_TAILS[$route] ?? throw new \LogicException(\sprintf('No separation-of-duty tail for route %s.', $route)),
            )),
            default => self::refused($verdict->code, $route),
        };
    }

    /**
     * Phase 5 — `INSERT INTO user_role (id, tenant_id, user_id, role_id)`: exactly H1's four columns, the only ones
     * `erp_app` may insert (`Version20260930000100`); `inserted_by` is the database's `session_user`. The one statement
     * every grant goes through (`AssignRole`, and confirmation since C2).
     */
    public static function insert(EntityManagerInterface $em, string $tenantId, string $subjectId, string $roleId): void
    {
        $em->getConnection()->executeStatement(
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [Uuid::v4()->toRfc4122(), $tenantId, $subjectId, $roleId],
        );
    }

    private static function throwIf(?GrantVerdict $verdict, string $route): void
    {
        if (null !== $verdict) {
            throw self::problem($verdict, $route);
        }
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
