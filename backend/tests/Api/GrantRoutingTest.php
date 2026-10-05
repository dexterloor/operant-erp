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

    private function assertRule(string $rule): void
    {
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule'] ?? null);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'role.%'"));
    }
}
