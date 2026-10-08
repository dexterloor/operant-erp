<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **C0 of users slice 1 — characterisation tests of the shipped grant rules, green on the code as it stood at `9a41e8c`**
 * (`docs/session13/users-s1-design.md` rev 3 §6 C0; RED-2 H-2 fix item 3; RED-3 F-1, F-4, F-5).
 *
 * They are **C1a's named proof**: C1a turns `GrantRules`' phases 1–4 into verdicts the picker (`ListOfferedRoles`, C3) can
 * evaluate from its one statement, and must keep every test here green with zero edits. What they pin, and why each one:
 *
 *  - **The inactive actor** is refused for the whole request, before any subject or proposal is read (E-1): on Assign and
 *    Propose with an ABSENT subject id (a 404 here would mean the actor check moved after `subjectAndRole`), on Confirm
 *    with an absent proposal id, and on Confirm an inactive NON-owner gets `actor_is_inactive`, not `decider_not_owner`
 *    (`RoleAdministrationActor` runs before Confirm's owner check).
 *  - **Phase 1's order, one two-failure case per adjacent pair** (deleted + inactive, inactive + archived, archived + self,
 *    self + marker), on Assign and Propose: any permutation inverts at least one adjacent pair (RED-3 §3).
 *  - **Marker + exceeds** with a COMPOSED marker role: E-15b (phase 1) outranks E-15c (phase 2).
 *  - **An archived held role does not count towards separation of duty** (RED-3 H-1/F-1), on Assign, Propose and Confirm:
 *    phase 4 reads the subject's LIVE roles (`codesHeldBy`), phase 3 reads `grant()` (live or archived). A pure phase fed
 *    one held set for both would refuse these grants; the live-role control shows the fixture can express that failure.
 *  - **Propose: `already_held` + a live pending proposal → `already_held`** (RED-3 F-4: phase 3 before slot B; only
 *    Assign's twin was pinned, `GrantRoutingTest`).
 */
final class GrantRulesCharacterisationTest extends ApiTestCase
{
    use RoleGrantFixtures;

    private const INACTIVE_ACTOR = 'Your account is not active, so you can\'t change anyone\'s roles.';

    /** An inactive actor holding `role.assign`: the whole request is refused before the (absent) subject is read. */
    #[Test]
    public function anInactiveActorIsRefusedBeforeAnAbsentSubjectOrProposalIsRead(): void
    {
        $role = $this->systemRole(DefaultRoles::BUYER);
        $absent = Uuid::v4()->toRfc4122();

        $cases = [
            'assign, absent subject' => [DefaultRoles::ADMIN, fn () => $this->send('POST', '/users/'.$absent.'/roles', ['role_id' => $role], $this->key())],
            'propose, absent subject' => [DefaultRoles::ADMIN, fn () => $this->propose($absent, $role)],
            'confirm, absent proposal, inactive owner' => [DefaultRoles::OWNER, fn () => $this->confirm($absent)],
            'confirm, absent proposal, inactive non-owner' => [DefaultRoles::ADMIN, fn () => $this->confirm($absent)],
        ];
        foreach ($cases as $case => [$seat, $request]) {
            $actor = $this->principal($seat);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$actor]);
            $this->actAsUser(self::TENANT_A, $actor);
            $request();

            $this->assertRefused('user_role.actor_is_inactive', $case);
            self::assertSame(self::INACTIVE_ACTOR, $this->json()['detail'] ?? null, $case);
        }
    }

    /**
     * Phase 1, one two-failure case per adjacent pair (V2-R-6 deleted → inactive → archived, then E-15a, then E-15b), on
     * Assign and on Propose. The subject of "self" cases is the actor; "marker" is the owner role given by an admin.
     */
    #[Test]
    public function phaseOneAnswersTheFirstOfEveryAdjacentPair(): void
    {
        foreach (['assign', 'propose'] as $route) {
            $admin = $this->principal(DefaultRoles::ADMIN);
            $finance = $this->systemRole(DefaultRoles::FINANCE);

            // deleted + inactive → subject_is_deleted
            $subject = $this->seedPrincipal(self::TENANT_A, []);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now(), is_active = false WHERE id = ?::uuid', [$subject]);
            $this->actAsUser(self::TENANT_A, $admin);
            $this->request($route, $subject, $finance);
            $this->assertRefused('user_role.subject_is_deleted', $route.': deleted + inactive');

            // inactive + archived → subject_is_inactive
            $subject = $this->seedPrincipal(self::TENANT_A, []);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$subject]);
            $archived = $this->systemRole(DefaultRoles::FINANCE);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
            $this->actAsUser(self::TENANT_A, $admin);
            $this->request($route, $subject, $archived);
            $this->assertRefused('user_role.subject_is_inactive', $route.': inactive + archived');

            // archived + self → role_is_archived
            $this->actAsUser(self::TENANT_A, $admin);
            $this->request($route, $admin, $archived);
            $this->assertRefused('user_role.role_is_archived', $route.': archived + self');

            // self + marker → actor_is_subject
            $owner = $this->systemRole(DefaultRoles::OWNER);
            $this->actAsUser(self::TENANT_A, $admin);
            $this->request($route, $admin, $owner);
            $this->assertRefused('user_role.actor_is_subject', $route.': self + marker');
        }
    }

    /** E-15b (phase 1) before E-15c (phase 2): a COMPOSED marker role an admin also lacks the codes for. */
    #[Test]
    public function theMarkerRefusalOutranksBeyondTheGrantor(): void
    {
        $composedMarker = $this->composedRole(['tenant_settings.edit', 'invoice.pay']);
        foreach (['assign', 'propose'] as $route) {
            $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
            $this->request($route, $this->seedPrincipal(self::TENANT_A, []), $composedMarker);
            $this->assertRefused('user_role.marker_needs_owner', $route.': marker + exceeds');
        }
    }

    /**
     * An archived held role does not count towards PC-13 (RED-3 H-1): the subject holds a composed `invoice.issue` role,
     * which is then archived; `finance` (`invoice.pay`, SoD-3's controlled half) is granted. Assign (by an owner) → 201;
     * Propose (by an admin; `finance` needs confirmation) → 201; Confirm (by another owner) → 200. The live control shows
     * the same fixture refuses `separation_of_duty` while the held role is live.
     */
    #[Test]
    public function anArchivedHeldRoleDoesNotCountTowardsSeparationOfDuty(): void
    {
        // Control: the held role LIVE → separation_of_duty, so the fixture can express the failure.
        $live = $this->holderOf([$this->composedRole(['invoice.issue'])]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->request('assign', $live, $this->systemRole(DefaultRoles::FINANCE));
        $this->assertRefused('user_role.separation_of_duty', 'control: the held role is live');

        // Assign.
        $subject = $this->holderOfArchived(['invoice.issue']);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->request('assign', $subject, $this->systemRole(DefaultRoles::FINANCE));
        self::assertSame(201, $this->httpStatus(), 'assign: '.$this->client()->getResponse()->getContent());

        // Propose, then Confirm.
        $subject = $this->holderOfArchived(['invoice.issue']);
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($subject, $finance);
        self::assertSame(201, $this->httpStatus(), 'propose: '.$this->client()->getResponse()->getContent());
        $proposal = (string) $this->json()['id'];

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->confirm($proposal);
        self::assertSame(200, $this->httpStatus(), 'confirm: '.$this->client()->getResponse()->getContent());
    }

    /** RED-3 F-4: on Propose, phase 3 (C12) answers before slot B — a held role with a live pending proposal → `already_held`. */
    #[Test]
    public function onProposeAlreadyHeldOutranksALivePendingProposal(): void
    {
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->holderOf([$finance]);
        $this->seedPendingProposal($subject, $finance, $this->principal(DefaultRoles::ADMIN));
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($subject, $finance);

        $this->assertRefused('user_role.already_held', 'propose: held + live pending');
    }

    // -----------------------------------------------------------------------

    private function request(string $route, string $userId, string $roleId): void
    {
        'assign' === $route
            ? $this->send('POST', '/users/'.$userId.'/roles', ['role_id' => $roleId], $this->key())
            : $this->propose($userId, $roleId);
    }

    /**
     * A fresh user granted a composed role with `$codes`, the role archived AFTER the grant (the shipped order: a grant is
     * made on a live role and the role is archived later).
     *
     * @param list<string> $codes
     */
    private function holderOfArchived(array $codes): string
    {
        $role = $this->composedRole($codes);
        $user = $this->holderOf([$role]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);

        return $user;
    }

    private function assertRefused(string $rule, string $case): void
    {
        self::assertSame(409, $this->httpStatus(), $case.': '.$this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule'] ?? null, $case);
    }
}
