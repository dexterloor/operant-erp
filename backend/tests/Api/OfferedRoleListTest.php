<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\ApiResource\OfferedRoleResource;
use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Users slice 1 C3 — `ListOfferedRoles`, the F-7 picker's data** (`docs/session13/users-s1-design.md` rev 3 §2.2, §8;
 * `SEC` `users-s1-sec.md` §1.4, R1-1…R1-4, V-1…V-5; owner rulings Q-U2 and Q-U3, 2026-10-08).
 *
 * What is pinned, and the design mutation each answers:
 *  - the body, whole, on a seeded workspace: order `name, id`, `conflicts_with` (pairwise PC-13 with the SoD row),
 *    `margin_exposure_with` (PC-12 clause 4), `refusal`, `requires_owner_confirmation` (false whenever `refusal` is set,
 *    mutation 14);
 *  - **picker ≡ server by differential** (mutation 2a): each fixture on a fresh subject, the picker read first, then
 *    `AssignRole` driven for real — 201 ⇔ no refusal and no confirmation; 409 `needs_owner_confirmation` ⇔ no refusal and
 *    confirmation; 409 `<code>` ⇔ `refusal = <code>`; the inactive actor 409s on both paths; **2b**: a live or an expired
 *    pending proposal is the chosen divergence (`SEC` V-5);
 *  - conflicts come from codes, never names (mutation 3);
 *  - `archived` and `q` select items only (RED-3 F-3): an item is byte-identical with and without `q`;
 *  - the gate (mutation 7): `viewer` 403 byte-equal for a real and a random id, on this path and `GetUser`'s (`SEC` R1-1);
 *    an `app_user.view` + `role.assign` seat without `role.view` reads it (V-2); `role.assign` revoked mid-token → 403 (V-3);
 *  - pending invisibility (mutation 5 / V-4): the reader touches only its four tables, and a pending proposal leaves the
 *    body byte-identical;
 *  - 404s and the inactive actor's whole-request 409.
 */
final class OfferedRoleListTest extends ApiTestCase
{
    use RoleGrantFixtures;

    /** The gate's 403 for `role.assign`, byte for byte. */
    private const ROLE_ASSIGN_DENIED = '{"required_permission":"role.assign","type":"urn:erp:problem:permission-denied","title":"Permission denied","status":403}';

    /** The item schema published inline is exactly the DTO's members. */
    #[Test]
    public function theWireSchemaIsTheResourcesOwnMembers(): void
    {
        $members = [];
        foreach ((new \ReflectionClass(OfferedRoleResource::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $renamed = $property->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $renamed ? $property->getName() : (string) $renamed->newInstance()->serializedName;
        }
        self::assertSame(['id', 'name', 'conflicts_with', 'margin_exposure_with', 'refusal', 'requires_owner_confirmation'], $members);
        self::assertSame($members, array_keys(OfferedRoleResource::WIRE_SCHEMA['properties']));
        self::assertSame($members, OfferedRoleResource::WIRE_SCHEMA['required']);
    }

    /**
     * The whole body for an admin offering roles to a subject holding a composed `purchase_order.create` role:
     *  - `buyer` conflicts with nothing in the workspace pairwise but is margin-exposed with `viewer` (clause 4);
     *  - the composed `goods_receipt.create` role conflicts with the subject's held role under SoD-2 (`refusal`
     *    `separation_of_duty`) and, pairwise, with the held role (`conflicts_with`);
     *  - `finance` needs an owner's confirmation (no refusal);
     *  - the held role is `already_held`;
     *  - `owner` is `marker_needs_owner`.
     */
    #[Test]
    public function thePickerServesEachRolesVerdictAndPairs(): void
    {
        $held = $this->namedComposedRole('Held PO create', ['purchase_order.create']);
        $receive = $this->namedComposedRole('Receive goods', ['goods_receipt.create']);
        // A SYSTEM role, so E-15c (composed roles only) does not answer first for an admin who lacks the code.
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET is_system = true WHERE id = ?::uuid', [$receive]);
        $buyer = $this->namedSystemRole('buyer', DefaultRoles::BUYER);
        $viewer = $this->namedSystemRole('viewer', DefaultRoles::VIEWER);
        $finance = $this->namedSystemRole('finance', DefaultRoles::FINANCE);
        $owner = $this->namedSystemRole('owner', DefaultRoles::OWNER);
        $subject = $this->holderOf([$held]);
        $admin = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $admin);

        $items = $this->offered($subject, 200);
        // The admin's own fixture role is in the workspace too; read only the seeded ones.
        $byName = array_column($items, null, 'name');

        self::assertSame(['id' => $held, 'name' => 'Held PO create',
            'conflicts_with' => [['role' => ['id' => $receive, 'name' => 'Receive goods'], 'sod_row' => 'SoD-2']],
            // `purchase_order.create` is a cost source (its 201 returns money, PC-17), so the held role pairs with each
            // sale-side default that is not exposed alone.
            'margin_exposure_with' => [['id' => $finance, 'name' => 'finance'], ['id' => $viewer, 'name' => 'viewer']],
            'refusal' => 'user_role.already_held', 'requires_owner_confirmation' => false],
            $byName['Held PO create']);
        self::assertSame('user_role.separation_of_duty', $byName['Receive goods']['refusal']);
        // Pairwise against EVERY live role: the held one, `buyer` (`purchase_order.create`) and the admin's own fixture role.
        self::assertContains(['role' => ['id' => $held, 'name' => 'Held PO create'], 'sod_row' => 'SoD-2'], $byName['Receive goods']['conflicts_with']);
        self::assertContains(['role' => ['id' => $buyer, 'name' => 'buyer'], 'sod_row' => 'SoD-2'], $byName['Receive goods']['conflicts_with']);
        self::assertNotContains(['role' => ['id' => $receive, 'name' => 'Receive goods'], 'sod_row' => 'SoD-2'], $byName['Receive goods']['conflicts_with'], 'A role conflicts with itself.');
        self::assertFalse($byName['Receive goods']['requires_owner_confirmation'], 'requires_owner_confirmation is true while refusal is set.');
        self::assertContains(['id' => $viewer, 'name' => 'viewer'], $byName['buyer']['margin_exposure_with'], 'PC-12 clause 4: buyer + viewer is not warned.');
        self::assertContains(['id' => $buyer, 'name' => 'buyer'], $byName['viewer']['margin_exposure_with']);
        self::assertSame([null, true], [$byName['finance']['refusal'], $byName['finance']['requires_owner_confirmation']]);
        self::assertSame('user_role.marker_needs_owner', $byName['owner']['refusal']);
        self::assertSame($owner, $byName['owner']['id']);

        self::assertSame(array_column($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id::text AS id FROM role WHERE deleted_at IS NULL ORDER BY name, id'), 'id'),
            array_column($items, 'id'), 'The items are not every live role, ordered by name, then id.');
    }

    /**
     * Mutation 2a — picker ≡ `AssignRole`, by differential. Each fixture runs on its own fresh subject; the picker is read
     * first, then `AssignRole` is driven for real, and the two are compared by the three arms.
     */
    #[Test]
    public function thePickerAgreesWithAssignRoleOnEveryBoundary(): void
    {
        $admin = $this->principal(DefaultRoles::ADMIN);
        $owner = $this->principal(DefaultRoles::OWNER);
        $fixtures = [
            'clean' => fn (): array => [$admin, $this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::BUYER)],
            'needs confirmation' => fn (): array => [$admin, $this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE)],
            'marker + exceeds' => fn (): array => [$admin, $this->seedPrincipal(self::TENANT_A, []), $this->composedRole(['tenant_settings.edit', 'invoice.pay'])],
            'exceeds + already held' => function (): array {
                $role = $this->composedRole(['invoice.pay']);

                return [$this->principal(DefaultRoles::ADMIN), $this->holderOf([$role]), $role];
            },
            'already held' => function (): array {
                $role = $this->systemRole(DefaultRoles::BUYER);

                return [$this->principal(DefaultRoles::ADMIN), $this->holderOf([$role]), $role];
            },
            'separation of duty' => fn (): array => [$owner, $this->holderOf([$this->composedRole(['invoice.issue'])]), $this->systemRole(DefaultRoles::FINANCE)],
            'archived held role does not conflict' => fn (): array => [$owner, $this->holderOfArchived(['invoice.issue']), $this->systemRole(DefaultRoles::FINANCE)],
            'subject deleted' => function (): array {
                $subject = $this->seedPrincipal(self::TENANT_A, []);
                $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now(), is_active = false WHERE id = ?::uuid', [$subject]);

                return [$this->principal(DefaultRoles::ADMIN), $subject, $this->systemRole(DefaultRoles::BUYER)];
            },
            'subject inactive' => function (): array {
                $subject = $this->seedPrincipal(self::TENANT_A, []);
                $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$subject]);

                return [$this->principal(DefaultRoles::ADMIN), $subject, $this->systemRole(DefaultRoles::BUYER)];
            },
            'actor is subject' => function (): array {
                $actor = $this->principal(DefaultRoles::ADMIN);

                return [$actor, $actor, $this->systemRole(DefaultRoles::BUYER)];
            },
            // Phase 1's adjacent pair `role_is_archived` / `actor_is_subject`, both applicable at once (C1a m1, C3 mutation
            // 2): the first in `AssignRole`'s order answers. Read through `archived=true`, where the picker offers it.
            'actor is subject, role archived' => function (): array {
                $actor = $this->principal(DefaultRoles::ADMIN);
                $role = $this->composedRole(['role.view']);
                $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);

                return [$actor, $actor, $role, '?archived=true'];
            },
        ];

        $outcomes = [];
        foreach ($fixtures as $case => $fixture) {
            [$caller, $subject, $role, $query] = $fixture() + [3 => ''];
            $this->actAsUser(self::TENANT_A, $caller);
            $item = $this->itemFor($subject, $role, $case, $query);

            $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $role], $this->key());
            $assign = 201 === $this->httpStatus() ? 201 : (string) ($this->json()['rule'] ?? $this->httpStatus());
            $picker = match (true) {
                null !== $item['refusal'] => $item['refusal'],
                $item['requires_owner_confirmation'] => 'user_role.needs_owner_confirmation',
                default => 201,
            };
            self::assertSame($assign, $picker, $case.': the picker says '.json_encode($item).', AssignRole answered '.json_encode($assign));
            $outcomes[$case] = $assign;
        }

        // Literal controls, so the oracle cannot drift with the code.
        self::assertSame(201, $outcomes['clean']);
        self::assertSame('user_role.needs_owner_confirmation', $outcomes['needs confirmation']);
        self::assertSame('user_role.marker_needs_owner', $outcomes['marker + exceeds']);
        self::assertSame('user_role.exceeds_actor_permissions', $outcomes['exceeds + already held']);
        self::assertSame('user_role.already_held', $outcomes['already held']);
        self::assertSame('user_role.separation_of_duty', $outcomes['separation of duty']);
        self::assertSame(201, $outcomes['archived held role does not conflict'], 'RED-3 F-1: an archived held role counted towards PC-13.');
        self::assertSame('user_role.subject_is_deleted', $outcomes['subject deleted']);
        self::assertSame('user_role.subject_is_inactive', $outcomes['subject inactive']);
        self::assertSame('user_role.actor_is_subject', $outcomes['actor is subject']);
        // The picker and `AssignRole` share phase 1, so a reorder moves both together and the differential alone stays
        // green; this literal is what reddens it.
        self::assertSame('user_role.role_is_archived', $outcomes['actor is subject, role archived']);

        // An archived role, through `archived=true`: the first applicable code, pair lists empty, no notice.
        $archived = $this->composedRole(['role.view']);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $admin);
        $item = $this->itemFor($subject, $archived, 'archived role', '?archived=true');
        self::assertSame(['user_role.role_is_archived', [], [], false],
            [$item['refusal'], $item['conflicts_with'], $item['margin_exposure_with'], $item['requires_owner_confirmation']]);
        $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $archived], $this->key());
        self::assertSame('user_role.role_is_archived', $this->json()['rule'] ?? null);
    }

    /**
     * Mutation 2b (`SEC` V-5) — the chosen divergence, pinned: a pair with a pending proposal, live or expired-but-still-
     * `pending`, reads clean in the picker (owner ruling Q-1: it never says a grant is pending), while `AssignRole` refuses
     * it `proposal_pending`. `AssignRole` re-decides under the lock.
     */
    #[Test]
    public function aPendingPairIsTheChosenDivergence(): void
    {
        foreach (['live' => 0, 'expired' => 400] as $case => $ageHours) {
            $role = $this->systemRole(DefaultRoles::FINANCE);
            $subject = $this->seedPrincipal(self::TENANT_A, []);
            $this->seedPendingProposal($subject, $role, $this->principal(DefaultRoles::ADMIN), $ageHours);
            $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

            $item = $this->itemFor($subject, $role, $case);
            self::assertSame([null, true], [$item['refusal'], $item['requires_owner_confirmation']], $case.': the picker said the grant is pending.');

            $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $role], $this->key());
            self::assertSame('user_role.proposal_pending', $this->json()['rule'] ?? null, $case);
        }
    }

    /** The inactive actor (RED-2 M-1): 409 `actor_is_inactive` on both paths, the same body for an absent and a real id. */
    #[Test]
    public function anInactiveActorIsRefusedForTheWholeRequest(): void
    {
        $actor = $this->principal(DefaultRoles::ADMIN);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$actor]);
        $this->actAsUser(self::TENANT_A, $actor);
        $role = $this->systemRole(DefaultRoles::BUYER);

        $bodies = [];
        foreach (['absent' => Uuid::v4()->toRfc4122(), 'real' => $this->seedPrincipal(self::TENANT_A, [])] as $case => $id) {
            $this->send('GET', '/users/'.$id.'/offered-roles');
            self::assertSame(409, $this->httpStatus(), $case);
            self::assertSame('user_role.actor_is_inactive', $this->json()['rule'] ?? null, $case);
            $bodies[] = (string) $this->client()->getResponse()->getContent();

            $this->send('POST', '/users/'.$id.'/roles', ['role_id' => $role], $this->key());
            self::assertSame('user_role.actor_is_inactive', $this->json()['rule'] ?? null, $case.' (AssignRole)');
        }
        self::assertSame($bodies[0], $bodies[1], 'The inactive actor\'s refusal differs for an absent and a real id.');
    }

    /** A non-uuid, an absent and another workspace's id are the same 404. */
    #[Test]
    public function anUnknownSubjectIsNotFound(): void
    {
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $bodies = [];
        foreach ([Uuid::v4()->toRfc4122(), $this->seedPrincipal(self::TENANT_B, []), 'not-a-uuid'] as $id) {
            $this->send('GET', '/users/'.$id.'/offered-roles');
            self::assertSame(404, $this->httpStatus());
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1], 'Another workspace\'s user answers differently from a random id.');
    }

    /**
     * Mutation 7 — the gate is `role.assign`. `viewer` gets byte-equal 403s for a real and a random id, here and on
     * `GetUser` without `app_user.view` (`SEC` R1-1); a composed `app_user.view` + `role.assign` seat without `role.view`
     * reads it (V-2); `role.assign` revoked from the caller mid-token → 403 (V-3: the live codes, never the token's).
     */
    #[Test]
    public function theGateIsRoleAssignOnTheLiveCodes(): void
    {
        $real = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::VIEWER));
        $bodies = [];
        foreach ([$real, Uuid::v4()->toRfc4122()] as $id) {
            $this->send('GET', '/users/'.$id.'/offered-roles');
            self::assertSame(403, $this->httpStatus());
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame([self::ROLE_ASSIGN_DENIED, self::ROLE_ASSIGN_DENIED], $bodies, 'viewer\'s 403 differs for a real and a random id.');

        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.assign']));
        $bodies = [];
        foreach ([$real, Uuid::v4()->toRfc4122()] as $id) {
            $this->send('GET', '/users/'.$id);
            self::assertSame(403, $this->httpStatus());
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1], 'GetUser\'s 403 differs for a real and a random id.');

        $seat = $this->seedPrincipal(self::TENANT_A, ['app_user.view', 'role.assign']);
        $this->actAsUser(self::TENANT_A, $seat);
        $this->send('GET', '/users/'.$real.'/offered-roles');
        self::assertSame(200, $this->httpStatus(), 'V-2: an app_user.view + role.assign seat without role.view is refused.');
        self::assertNotSame([], $this->json()['items']);

        $this->executeAsOwnerForTenant(self::TENANT_A,
            "DELETE FROM role_permission WHERE permission_code = 'role.assign' AND role_id IN (SELECT role_id FROM user_role WHERE user_id = ?::uuid)", [$seat]);
        $this->send('GET', '/users/'.$real.'/offered-roles');
        self::assertSame(403, $this->httpStatus(), 'V-3: role.assign revoked mid-token still reads the picker.');
    }

    /** Mutation 3 — conflicts come from codes: renaming a role changes no pair list but the renamed reference's name. */
    #[Test]
    public function conflictsComeFromCodesNotNames(): void
    {
        $create = $this->namedComposedRole('Raise POs', ['purchase_order.create']);
        $receive = $this->namedComposedRole('Receive goods', ['goods_receipt.create']);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $before = $this->itemFor($subject, $create, 'before');
        self::assertSame([['role' => ['id' => $receive, 'name' => 'Receive goods'], 'sod_row' => 'SoD-2']], $before['conflicts_with']);

        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE role SET name = 'buyer' WHERE id = ?::uuid", [$receive]);
        $after = $this->itemFor($subject, $create, 'after');
        self::assertSame([['role' => ['id' => $receive, 'name' => 'buyer'], 'sod_row' => 'SoD-2']], $after['conflicts_with'],
            'A rename changed the conflict set: a conflict is decided by name.');
    }

    /**
     * RED-3 F-3 — `archived` and `q` select which items are returned, nothing else: one live role's item under
     * `q=<its own prefix>` is byte-identical to its item under no `q`; `q` is a case-insensitive prefix of the name only.
     */
    #[Test]
    public function aSearchSelectsItemsAndChangesNoItem(): void
    {
        $finance = $this->namedSystemRole('Finance desk', DefaultRoles::FINANCE);
        $this->namedComposedRole('Issue invoices', ['invoice.issue']);
        $this->namedSystemRole('Buyer desk', DefaultRoles::BUYER);
        $subject = $this->holderOf([$this->namedComposedRole('Held sales', ['sales_order.view'])]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));

        $plain = $this->itemFor($subject, $finance, 'no q');
        $searched = $this->offered($subject, 200, '?q=FINANCE');
        self::assertSame([$plain], $searched, 'An item under q differs from the same item without it, or q matched another name.');
        self::assertNotSame([], $plain['conflicts_with'], 'Fixture: finance conflicts with nothing, so a filtered partner list would pass.');
        self::assertNotSame([], $plain['margin_exposure_with'], 'Fixture: finance has no margin partner, so a filtered partner list would pass.');

        self::assertSame([], $this->offered($subject, 200, '?q=desk'), 'q matched inside a name: it is not a prefix.');
    }

    /**
     * Mutation 5 / `SEC` V-4 — a pending proposal for the subject leaves the body byte-identical (the structural half is
     * {@see UserReadTest::theUserReadsTouchOnlyTheirOwnTables()}, which drives this path too).
     */
    #[Test]
    public function aPendingProposalLeavesThePickerByteIdentical(): void
    {
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->send('GET', '/users/'.$subject.'/offered-roles');
        $before = (string) $this->client()->getResponse()->getContent();
        $this->seedPendingProposal($subject, $finance, $proposer);
        $this->send('GET', '/users/'.$subject.'/offered-roles');

        self::assertSame($before, (string) $this->client()->getResponse()->getContent(), 'A pending proposal changed the picker: it serves pending state.');
    }

    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function offered(string $subject, int $status, string $query = ''): array
    {
        $this->send('GET', '/users/'.$subject.'/offered-roles'.$query);
        self::assertSame($status, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return $this->json()['items'];
    }

    /** @return array<string, mixed> */
    private function itemFor(string $subject, string $role, string $case, string $query = ''): array
    {
        foreach ($this->offered($subject, 200, $query) as $item) {
            if ($item['id'] === $role) {
                return $item;
            }
        }
        self::fail($case.': the picker does not offer the role.');
    }

    /** @param list<string> $codes */
    private function namedComposedRole(string $name, array $codes): string
    {
        $role = $this->composedRole($codes);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET name = ? WHERE id = ?::uuid', [$name, $role]);

        return $role;
    }

    private function namedSystemRole(string $name, string $default): string
    {
        $role = $this->systemRole($default);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET name = ? WHERE id = ?::uuid', [$name, $role]);

        return $role;
    }

    /** @param list<string> $codes */
    private function holderOfArchived(array $codes): string
    {
        $role = $this->composedRole($codes);
        $user = $this->holderOf([$role]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);

        return $user;
    }
}
