<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\GrantConfirmationPolicy;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use PHPUnit\Framework\Attributes\Test;

/**
 * **T3 — the proposal route admits exactly the grants that need an owner's confirmation, and its rules run in the
 * ruled order** (roles slice 3, C1: the CREATE side; `docs/session13/roles-s3-design.md` §4.7, §15 T3, RED R2-L7, R2-M2,
 * RED-3 L-2/L-5). Its own home, so `RoleAssignTest`'s T3 oracle stays byte-identical (it encodes the slice-2 order, correct
 * over its own domain). C2 adds the `AssignRole` side under the routing-last order and Confirm's boundaries.
 *
 * **The domain is derived and WIDENED** (R2-M2): seats {`owner`, `admin`, a composed `role.assign` seat WITH an `X(admin)`
 * code, one WITHOUT} × roles {every default role as a system role, a composed role without an `X(admin)` code, and a
 * composed role WITH one}. Each proposal is for a fresh user, so C12, the pending pair and PC-13 have nothing to say, and
 * the oracle is: the marker role by a non-owner → `marker_needs_owner`; a composed role outside the seat's set →
 * `exceeds_actor_permissions` (E-15c, before routing — the "neither route" case for `admin` and a composed `X(admin)`
 * role); no confirmation needed → `grant_directly`; else 201.
 *
 * **The order is pinned at every boundary whose outcome carries meaning (§4.7, C1's four):** E-15c + inverse routing →
 * `exceeds_actor_permissions`; C12 + inverse routing → `already_held`; a live pending pair + PC-13 → `already_pending`;
 * PC-13 + inverse routing → `separation_of_duty`. Phases 3 ↔ 4 are not pinned, by construction ("new conflict only"
 * cannot tell them apart). Mutations: M2c (drop inverse routing) → red; inverse routing moved before E-15c, C12 or PC-13 →
 * the matching boundary red; slot B moved after PC-13 → red.
 *
 * **C2 adds** both HTTP routes together over the widened domain (`AssignRole` with routing LAST: R-4's exclusion and the
 * truth of each route's sentence about the other), `AssignRole`'s three C2 boundaries (E-15c, C12, PC-13 each before
 * routing), and Confirm's (status guard before C15; C15 before C4; C8/C9 before phase 1; phase 1 before C14; C14 before
 * phase 2). **Not constructible, so not pinned: C4 ↔ C8/C9** — the proposer who is the confirmer has just passed the
 * lock-time `role.assign` and activity re-check, so C8/C9 cannot fail for them. The owner-check boundaries
 * (`decider_not_owner` before 404, C4 and C15) are `RoleGrantProposalConfirmTest::aNonOwnerIsRefusedTheSameWayForAnyId`.
 */
final class GrantRoutingTest extends ApiTestCase
{
    use RoleGrantFixtures;

    #[Test]
    public function theProposalRouteAdmitsExactlyTheGrantsNeedingConfirmation(): void
    {
        $roles = [];
        foreach (DefaultRoles::names() as $name) {
            $roles[$name] = ['id' => $this->systemRole($name), 'codes' => DefaultRoles::codesFor($name), 'system' => true];
        }
        $roles['composed without X(admin)'] = ['id' => $this->composedRole(['role.view', 'inventory_item.view']), 'codes' => ['inventory_item.view', 'role.view'], 'system' => false];
        $roles['composed with X(admin)'] = ['id' => $this->composedRole(['invoice.pay', 'role.view']), 'codes' => ['invoice.pay', 'role.view'], 'system' => false];
        $seats = [
            DefaultRoles::OWNER => DefaultRoles::codesFor(DefaultRoles::OWNER),
            DefaultRoles::ADMIN => DefaultRoles::codesFor(DefaultRoles::ADMIN),
            'composed with X(admin)' => ['role.assign', 'role.view', 'invoice.pay'],
            'composed without' => ['role.assign', 'role.view'],
        ];

        $outcomes = [];
        $expected = [];
        foreach ($seats as $seat => $seatCodes) {
            $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, $seatCodes));
            foreach ($roles as $name => $role) {
                $this->propose($this->seedPrincipal(self::TENANT_A, []), $role['id']);
                $outcomes[$seat.' proposes '.$name] = 201 === $this->httpStatus() ? 201 : ($this->json()['rule'] ?? $this->httpStatus());

                $expected[$seat.' proposes '.$name] = match (true) {
                    \in_array(OwnerMarker::CODE, $role['codes'], true) && !\in_array(OwnerMarker::CODE, $seatCodes, true) => 'user_role.marker_needs_owner',
                    !$role['system'] && [] !== array_diff($role['codes'], $seatCodes) => 'user_role.exceeds_actor_permissions',
                    !GrantConfirmationPolicy::needsConfirmation($seatCodes, $role['codes']) => 'role_grant_proposal.grant_directly',
                    default => 201,
                };
            }
        }

        // Literal controls, so the oracle cannot drift with the code.
        self::assertSame(201, $outcomes['admin proposes finance']);
        self::assertSame('role_grant_proposal.grant_directly', $outcomes['admin proposes buyer']);
        self::assertSame('role_grant_proposal.grant_directly', $outcomes['owner proposes finance'], 'An owner never proposes.');
        self::assertSame('user_role.marker_needs_owner', $outcomes['admin proposes owner']);
        self::assertSame('user_role.exceeds_actor_permissions', $outcomes['admin proposes composed with X(admin)'],
            'The "neither route" case (Q-4 (e)): admin holds no X(admin) code, so a composed X(admin) role is beyond it.');
        // RED-3 L-5's one-route control: a seat holding every code of a composed X(admin) role proposes it.
        self::assertSame(201, $outcomes['composed with X(admin) proposes composed with X(admin)'],
            'Refusing every composed X(admin) role everywhere would pass the rest of this test; this seat must be admitted.');
        self::assertContains(201, $outcomes, 'Anti-vacuity: nothing was proposed.');
        self::assertContains('role_grant_proposal.grant_directly', $outcomes, 'Anti-vacuity: nothing was routed to AssignRole.');
        self::assertSame($expected, $outcomes);
    }

    /** E-15c + inverse routing: a composed role beyond the seat that needs no confirmation → E-15c answers, not routing. */
    #[Test]
    public function beyondTheProposerOutranksGrantDirectly(): void
    {
        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.assign', 'role.view']));
        $this->propose($this->seedPrincipal(self::TENANT_A, []), $this->composedRole(['inventory_item.view']));

        $this->assertRule('user_role.exceeds_actor_permissions');
    }

    /** C12 + inverse routing: the subject already holds a role needing no confirmation → `already_held`, not routing. */
    #[Test]
    public function alreadyHeldOutranksGrantDirectly(): void
    {
        $buyer = $this->systemRole(DefaultRoles::BUYER);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($this->holderOf([$buyer]), $buyer);

        $this->assertRule('user_role.already_held');
    }

    /**
     * A live pending pair + PC-13: the pair is already pending AND the grant would split SoD-3 → `already_pending` (slot B
     * runs before phase 4).
     */
    #[Test]
    public function aLivePendingPairOutranksSeparationOfDuty(): void
    {
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->holderOf([$this->systemRole(DefaultRoles::SALES_REP)]);
        $this->seedPendingProposal($subject, $finance, $this->principal(DefaultRoles::ADMIN));
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($subject, $finance);

        $this->assertRule('role_grant_proposal.already_pending');
    }

    /**
     * PC-13 + inverse routing: `buyer` (no `X(admin)` code, so it needs no confirmation) to a holder of a composed
     * `purchase_order.approve` role newly spans SoD-1 → `separation_of_duty`, not `grant_directly` (routing last). (Not a
     * `purchasing_manager` holder: that role already spans SoD-1 alone, so "new conflict only" finds nothing new.)
     */
    #[Test]
    public function separationOfDutyOutranksGrantDirectly(): void
    {
        $subject = $this->holderOf([$this->composedRole(['purchase_order.approve'])]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($subject, $this->systemRole(DefaultRoles::BUYER));

        $this->assertRule('user_role.separation_of_duty');
        self::assertStringContainsString('(SoD-1', (string) ($this->json()['detail'] ?? ''));
    }

    // =======================================================================
    // Roles slice 3 C2 — `AssignRole` with routing LAST (slot R), both HTTP routes together (R-4), and Confirm's order.
    // =======================================================================

    /**
     * **T3, both HTTP routes, over the widened domain (R-4, R2-M2; design §4.7, §5 change 3).** For every seat × role,
     * each on a fresh user, `AssignRole` and `CreateRoleGrantProposal` are both driven, and:
     *  - `AssignRole`'s outcome is the routing-LAST oracle: the marker role by a non-owner → `marker_needs_owner`; a
     *    composed role beyond the seat → `exceeds_actor_permissions` (E-15c now answers BEFORE routing); a grant needing
     *    confirmation → `needs_owner_confirmation`; else 201;
     *  - **the routes exclude each other:** never both 201;
     *  - **"you can propose it" is true:** wherever `AssignRole` says `needs_owner_confirmation`, the proposal is 201;
     *  - **"give it directly" is true:** wherever Create says `grant_directly`, `AssignRole` is 201.
     * Literal controls: `admin` + a composed `X(admin)` role → `exceeds_actor_permissions` (it was
     * `needs_owner_confirmation` until C2 — shipped behaviour changed, owner told); the RED-3 L-5 seat granting the
     * composed `X(admin)` role it fully holds → `needs_owner_confirmation`, and proposing it → 201. Mutations M2c (drop
     * routing from `AssignRole`) and M2d (routing back before E-15c) → red.
     */
    #[Test]
    public function bothRoutesTogetherAdmitEachGrantByExactlyOneRouteAndSayOnlyWhatIsTrue(): void
    {
        $roles = [];
        foreach (DefaultRoles::names() as $name) {
            $roles[$name] = ['id' => $this->systemRole($name), 'codes' => DefaultRoles::codesFor($name), 'system' => true];
        }
        $roles['composed without X(admin)'] = ['id' => $this->composedRole(['role.view', 'inventory_item.view']), 'codes' => ['inventory_item.view', 'role.view'], 'system' => false];
        $roles['composed with X(admin)'] = ['id' => $this->composedRole(['invoice.pay', 'role.view']), 'codes' => ['invoice.pay', 'role.view'], 'system' => false];
        $seats = [
            DefaultRoles::OWNER => DefaultRoles::codesFor(DefaultRoles::OWNER),
            DefaultRoles::ADMIN => DefaultRoles::codesFor(DefaultRoles::ADMIN),
            'composed with X(admin)' => ['role.assign', 'role.view', 'invoice.pay'],
            'composed without' => ['role.assign', 'role.view'],
        ];

        $assigned = [];
        $proposed = [];
        $expected = [];
        foreach ($seats as $seat => $seatCodes) {
            $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, $seatCodes));
            foreach ($roles as $name => $role) {
                $key = $seat.' / '.$name;
                $this->send('POST', '/users/'.$this->seedPrincipal(self::TENANT_A, []).'/roles', ['role_id' => $role['id']], $this->key());
                $assigned[$key] = 201 === $this->httpStatus() ? 201 : ($this->json()['rule'] ?? $this->httpStatus());
                $this->propose($this->seedPrincipal(self::TENANT_A, []), $role['id']);
                $proposed[$key] = 201 === $this->httpStatus() ? 201 : ($this->json()['rule'] ?? $this->httpStatus());

                $expected[$key] = match (true) {
                    \in_array(OwnerMarker::CODE, $role['codes'], true) && !\in_array(OwnerMarker::CODE, $seatCodes, true) => 'user_role.marker_needs_owner',
                    !$role['system'] && [] !== array_diff($role['codes'], $seatCodes) => 'user_role.exceeds_actor_permissions',
                    GrantConfirmationPolicy::needsConfirmation($seatCodes, $role['codes']) => 'user_role.needs_owner_confirmation',
                    default => 201,
                };
            }
        }

        self::assertSame('user_role.exceeds_actor_permissions', $assigned['admin / composed with X(admin)'],
            'Routing still answers before E-15c on AssignRole (it said "you can propose it", and Create refuses it).');
        self::assertSame('user_role.needs_owner_confirmation', $assigned['composed with X(admin) / composed with X(admin)']);
        self::assertSame(201, $proposed['composed with X(admin) / composed with X(admin)']);
        self::assertSame('user_role.needs_owner_confirmation', $assigned['admin / finance']);
        self::assertSame(201, $assigned['owner / finance']);
        self::assertContains(201, $assigned, 'Anti-vacuity: nothing was granted.');
        self::assertContains('user_role.needs_owner_confirmation', $assigned, 'Anti-vacuity: nothing was routed to the proposal route.');
        self::assertSame($expected, $assigned);

        foreach ($assigned as $key => $outcome) {
            self::assertFalse(201 === $outcome && 201 === $proposed[$key], $key.': both routes admitted the same grant.');
            if ('user_role.needs_owner_confirmation' === $outcome) {
                self::assertSame(201, $proposed[$key], $key.': AssignRole said "you can propose it", and the proposal was refused.');
            }
            if ('role_grant_proposal.grant_directly' === $proposed[$key]) {
                self::assertSame(201, $outcome, $key.': Create said "give it directly", and AssignRole refused it.');
            }
        }
    }

    /** `AssignRole` (C2): E-15c + routing → E-15c answers (`admin` granting a composed role carrying an `X(admin)` code). */
    #[Test]
    public function onAssignRoleBeyondTheGrantorOutranksRouting(): void
    {
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('POST', '/users/'.$this->seedPrincipal(self::TENANT_A, []).'/roles', ['role_id' => $this->composedRole(['invoice.pay', 'role.view'])], $this->key());

        $this->assertRule('user_role.exceeds_actor_permissions');
    }

    /** `AssignRole` (C2): C12 + routing → `already_held` (`admin` granting `finance` to a `finance` holder). */
    #[Test]
    public function onAssignRoleAlreadyHeldOutranksRouting(): void
    {
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('POST', '/users/'.$this->holderOf([$finance]).'/roles', ['role_id' => $finance], $this->key());

        $this->assertRule('user_role.already_held');
    }

    /** `AssignRole` (C2): PC-13 + routing → `separation_of_duty` (`admin` granting `finance` to a `sales_rep` holder: SoD-3). */
    #[Test]
    public function onAssignRoleSeparationOfDutyOutranksRouting(): void
    {
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('POST', '/users/'.$this->holderOf([$this->systemRole(DefaultRoles::SALES_REP)]).'/roles', ['role_id' => $this->systemRole(DefaultRoles::FINANCE)], $this->key());

        $this->assertRule('user_role.separation_of_duty');
        self::assertStringEndsWith('(SoD-3). Choose another role or another person.', (string) ($this->json()['detail'] ?? ''),
            'AssignRole\'s SoD sentence lost its shipped tail.');
    }

    /** Confirm (C2): the status guard + C15 → the status refusal (a decided proposal far past its window). */
    #[Test]
    public function onConfirmTheStatusGuardOutranksExpiry(): void
    {
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $id = $this->seedDecidedProposal('rejected', $this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $proposer, $this->principal(DefaultRoles::OWNER));
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->confirm($id);

        $this->assertRule('role_grant_proposal.status_transition_is_legal');
    }

    /** Confirm (C2): C15 + C4 → `expired` (an owner confirming their own proposal, a day past its window). */
    #[Test]
    public function onConfirmExpiryOutranksTheProposersOwnConfirmation(): void
    {
        $owner = $this->principal(DefaultRoles::OWNER);
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $owner, ageHours: 360);
        $this->actAsUser(self::TENANT_A, $owner);
        $this->confirm($id);

        $this->assertRule('role_grant_proposal.expired');
    }

    /** Confirm (C2): C8/C9 + phase 1 → `proposer_is_inactive` (the proposer AND the subject removed). */
    #[Test]
    public function onConfirmTheProposerOutranksTheSubject(): void
    {
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $id = $this->seedPendingProposal($subject, $this->systemRole(DefaultRoles::FINANCE), $proposer);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ANY (?::uuid[])', ['{'.$proposer.','.$subject.'}']);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->confirm($id);

        $this->assertRule('role_grant_proposal.proposer_is_inactive');
    }

    /** Confirm (C2): phase 1 + C14 → `role_is_archived` (an archived role whose codes ALSO changed). */
    #[Test]
    public function onConfirmAnArchivedRoleOutranksAChangedOne(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $this->principal(DefaultRoles::ADMIN));
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, 'inventory_item.view')",
            [self::TENANT_A, $role]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->confirm($id);

        $this->assertRule('user_role.role_is_archived');
    }

    /**
     * Confirm (C2): C14 + phase 2 → `role_changed` (a custom role that changed AND now holds a code the confirming seat
     * lacks).
     */
    #[Test]
    public function onConfirmAChangedRoleOutranksBeyondTheConfirmer(): void
    {
        $role = $this->composedRole(['role.view']);
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $this->principal(DefaultRoles::ADMIN));
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, 'invoice.pay')",
            [self::TENANT_A, $role]);
        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.assign', 'role.view', OwnerMarker::CODE]));
        $this->confirm($id);

        $this->assertRule('role_grant_proposal.role_changed');
    }

    private function assertRule(string $rule): void
    {
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule'] ?? null);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'role.%'"));
    }
}
