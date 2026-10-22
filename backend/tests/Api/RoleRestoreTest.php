<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`RestoreRole` — `POST /api/v1/roles/{id}/restoration`, `role.delete`** (G2, 2026-10-22;
 * `docs/session13/g2-restore-role-design.md` as corrected by `g2-restore-role-red.md`; `remaining-ops-final-red.md` M-3, L-2;
 * V2-R-6). One rule per test; every refusal's sentence is pinned as a LITERAL (PLACEHOLDER copy, `UX` to confirm); **every
 * refusal writes nothing** (`assertUntouched()` reads `deleted_at`, `updated_at`, the codes, and counts `role.restored`).
 *
 * **Fixture rule** (`RoleDeleteTest`'s): roles are archived by owner SQL (`DeleteRole` itself is `RoleDeleteTest`'s), and
 * every actor meant to reach the role holds `role.assign` as well as `role.delete`, EXCEPT the backstop's own test.
 */
final class RoleRestoreTest extends ApiTestCase
{
    use RoleGrantFixtures;

    /**
     * 200 with `GetRole`'s body in its new state; `deleted_at` cleared, `updated_at` moved, the codes unchanged; one
     * `role.restored` `{role_id}` row on the role, with the request's IP (SECURITY); then the role can be given again.
     */
    #[Test]
    public function anArchivedUnusedRoleIsRestoredAndAudited(): void
    {
        $role = $this->archivedRole(['goods_receipt.view', 'inventory_item.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $before = $this->state($role);

        $this->restore($role);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame([$role, null, ['goods_receipt.view', 'inventory_item.view'], false, 0],
            [$body['id'] ?? null, \array_key_exists('archived_at', $body) ? $body['archived_at'] : 'missing', $body['permission_codes'] ?? null, $body['is_system'] ?? null, $body['holder_count'] ?? null]);
        self::assertArrayHasKey('margin_exposure', $body);
        self::assertNull($this->client()->getResponse()->headers->get('Location'), 'A restore creates no row, so it has no Location.');
        $after = $this->state($role);
        self::assertFalse($after['deleted'], 'deleted_at was not cleared.');
        self::assertNotSame($before['updated_at'], $after['updated_at'], 'updated_at did not move.');
        self::assertSame($before['codes'], $after['codes'], 'A restore changed the codes.');

        $rows = $this->restorationEvents();
        self::assertCount(1, $rows);
        self::assertSame([(string) $this->actorId, 'role', $role, true, [RoleEventMetadata::ROLE_ID => $role]],
            [$rows[0]['actor'], $rows[0]['subject_type'], $rows[0]['subject'], $rows[0]['has_ip'], $rows[0]['metadata']]);

        $this->send('GET', '/roles/'.$role);
        self::assertSame([200, null], [$this->httpStatus(), $this->json()['archived_at'] ?? null]);
    }

    /** §4.2 `guard:role.deleted_at`: a repeat meets a live role — 409 `role.is_not_archived` — and writes no second row. */
    #[Test]
    public function aRepeatMeetsALiveRoleAndIsRefused(): void
    {
        $role = $this->archivedRole(['role.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));

        $this->restore($role);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $before = $this->state($role);
        $this->restore($role);

        self::assertSame([409, 'role.is_not_archived', 'This role is not archived, so there is nothing to restore.'], $this->refusal());
        self::assertSame($before, $this->state($role));
        self::assertCount(1, $this->restorationEvents(), 'A repeated restore wrote a second row.');
    }

    /** No role, another workspace's archived role and a non-uuid are one not-found; a caller without `role.delete` is the gate's 403. */
    #[Test]
    public function anUnknownRoleIs404AndACallerWithoutRoleDeleteIs403(): void
    {
        $other = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_B, 'INSERT INTO role (id, tenant_id, name, deleted_at) VALUES (?::uuid, ?::uuid, ?, now())', [$other, self::TENANT_B, 'Theirs']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));

        foreach ([Uuid::v4()->toRfc4122(), $other, 'not-a-uuid'] as $id) {
            $this->restore($id);
            self::assertSame(404, $this->httpStatus(), $id);
        }
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM role WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$other]));

        $role = $this->archivedRole(['role.view']);
        $before = $this->state($role);
        $this->actAs(self::TENANT_A, ['role.view', 'role.assign', 'role.edit']);
        $this->restore($role);
        self::assertSame([403, 'role.delete'], [$this->httpStatus(), $this->json()['required_permission'] ?? null]);
        $this->assertUntouched($role, $before);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            "SELECT count(*) FROM audit_log WHERE event_type = ? AND subject_type = 'role' AND subject_id = ?::uuid", [AuditEvent::AUTHZ_PERMISSION_DENIED, $role]),
            'The 403 did not write its denial row on the role.');
    }

    /** **E-15h:** a built-in role archived by SQL is not restored through the API, even by an owner. */
    #[Test]
    public function aSystemRoleIsNeverRestored(): void
    {
        $role = $this->systemRole(DefaultRoles::VIEWER);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->state($role);

        $this->restore($role);

        self::assertSame([409, 'role.is_system', 'This is one of the built-in roles, and built-in roles can\'t be restored or changed.'], $this->refusal());
        $this->assertUntouched($role, $before);

        // A LIVE built-in role meets the same rule first (RED L-6), not `role.is_not_archived`.
        $live = $this->systemRole(DefaultRoles::VIEWER);
        $this->restore($live);
        self::assertSame([409, 'role.is_system'], \array_slice($this->refusal(), 0, 2));
    }

    /**
     * **L-2 / V2-R-6:** a grant planted on an archived role by SQL (G-10; `RoleRevokeTest`'s shape) — an active holder, a
     * deactivated one and a soft-deleted one all count — refuses; nothing is stripped, and the role stays archived.
     */
    #[Test]
    public function aRoleStillGivenToAnyoneIsNotRestored(): void
    {
        $role = $this->archivedRole(['inventory_item.view']);
        $this->holderOf([$role]);
        $deactivated = $this->holderOf([$role]);
        $removed = $this->holderOf([$role]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$deactivated]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$removed]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->state($role);

        $this->restore($role);

        self::assertSame([409, 'role.has_holders', 'This role is still given to 3 people, counting anyone whose account is deactivated or removed. It can be restored once nobody holds it.'], $this->refusal());
        $this->assertUntouched($role, $before);
        self::assertSame(3, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE role_id = ?::uuid', [$role]), 'A holder was stripped.');
    }

    /**
     * A pending proposal planted on an archived role (stale included) would become confirmable on restore — refused; a held
     * and proposed role meets the holder refusal first. Clearable: an owner rejects it, and the restore then succeeds.
     */
    #[Test]
    public function aRoleWithOpenRequestsIsNotRestoredUntilTheyAreClosed(): void
    {
        $role = $this->archivedRole(['inventory_item.view']);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $fresh = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer);
        $stale = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer, ageHours: 400);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->state($role);

        $this->restore($role);
        self::assertSame([409, 'role.has_pending_proposals', '2 role proposals are still open for this role, counting any that are more than 14 days old. It can be restored once they\'re closed: an owner can reject them, and whoever proposed one can withdraw it.'], $this->refusal());
        $this->assertUntouched($role, $before);

        $holder = $this->holderOf([$role]);
        $this->restore($role);
        self::assertSame('role.has_holders', $this->refusal()[1], 'Holders are not checked before proposals.');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'DELETE FROM user_role WHERE user_id = ?::uuid', [$holder]);

        foreach ([$fresh, $stale] as $proposal) {
            $this->send('POST', '/role-grant-proposals/'.$proposal.'/rejection', ['reason' => 'Role retired']);
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        }
        $this->restore($role);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** **Q-A symmetry:** a role whose codes make its holders owners is restored only by an owner. */
    #[Test]
    public function aRoleThatMakesOwnersIsRestoredOnlyByAnOwner(): void
    {
        $role = $this->archivedRole(['tenant_settings.edit', 'tenant_settings.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $before = $this->state($role);

        $this->restore($role);
        self::assertSame([409, 'role.marker_needs_owner', 'Only an owner can restore this role, because it makes the people given it owners.'], $this->refusal());
        $this->assertUntouched($role, $before);

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->restore($role);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** **E-15f's ceiling (M-3, `SEC`):** every code of the role must be one the restorer holds; an owner holds them all. */
    #[Test]
    public function aRoleBeyondTheRestorersPermissionsIsNotRestored(): void
    {
        $role = $this->archivedRole(['invoice.view', 'invoice.void']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        self::assertNotContains('invoice.void', DefaultRoles::codesFor(DefaultRoles::ADMIN), 'The fixture needs a code admin lacks.');
        $before = $this->state($role);

        $this->restore($role);
        self::assertSame([409, 'role.exceeds_actor_permissions', 'This role includes permissions you don\'t have yourself, and a role can only be restored by someone who holds all of its permissions. Ask an owner to restore it.'], $this->refusal());
        $this->assertUntouched($role, $before);

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->restore($role);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** **PC-4 (M-3):** a definition spanning an SoD row in force now (written by SQL) is refused — owner too, no exemption. */
    #[Test]
    public function aRoleSpanningASeparationOfDutyRowIsNotRestored(): void
    {
        $role = $this->archivedRole(['purchase_order.create', 'purchase_order.approve', 'purchase_order.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->state($role);

        $this->restore($role);

        self::assertSame([409, 'role.separation_of_duty', 'This role covers both sides of a job that has to be split: anyone given it could raise purchase orders and also approve or cancel them (SoD-1). It can\'t be restored, but a new role can be created with the permissions for one side.'], $this->refusal());
        $this->assertUntouched($role, $before);
    }

    /** **Companions:** a definition missing a companion now required (written by SQL) is refused — owner too. */
    #[Test]
    public function aRoleMissingACompanionIsNotRestored(): void
    {
        $role = $this->archivedRole(['stock_movement.adjust']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->state($role);

        $this->restore($role);

        self::assertSame([409, 'role.missing_companion_view', 'Some permissions in this role only work alongside others it doesn\'t have: counting stock needs seeing stock levels. It can\'t be restored, but a new role can be created with the missing ones added.'], $this->refusal());
        $this->assertUntouched($role, $before);
    }

    /** A live role took the name (case and separator variant of it) since the archive → 409 `role.name_taken`; renaming frees it. */
    #[Test]
    public function aRoleWhoseNameIsNowTakenIsNotRestored(): void
    {
        $role = $this->archivedRole(['role.view']);
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE role SET name = 'Stock Counter' WHERE id = ?::uuid", [$role]);
        $live = $this->composedRole(['role.view']);
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE role SET name = 'stock-COUNTER' WHERE id = ?::uuid", [$live]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->state($role);

        $this->restore($role);
        self::assertSame([409, 'role.name_taken', 'Another role now uses this role\'s name, so it can\'t be restored. It can be restored once that role is renamed.'], $this->refusal());
        $this->assertUntouched($role, $before);

        $this->send('PATCH', '/roles/'.$live, ['name' => 'Stock Counter (new)']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->restore($role);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **The `role.assign` backstop:** a seat composed by SQL holding `role.delete` without `role.assign` gets ONE body for every
     * id — held, proposed, free, system, live, another workspace's, random, non-uuid — before the role is read; no denial row,
     * no write.
     */
    #[Test]
    public function aRestorerWithoutRoleAssignIsRefusedTheSameWayForEveryId(): void
    {
        $held = $this->archivedRole(['inventory_item.view']);
        $this->holderOf([$held]);
        $pending = $this->archivedRole(['inventory_item.view']);
        $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $pending, $this->principal(DefaultRoles::ADMIN));
        $free = $this->archivedRole(['inventory_item.view']);
        $system = $this->systemRole(DefaultRoles::VIEWER);
        $live = $this->composedRole(['inventory_item.view']);
        $other = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_B, 'INSERT INTO role (id, tenant_id, name, deleted_at) VALUES (?::uuid, ?::uuid, ?, now())', [$other, self::TENANT_B, 'Theirs']);
        $before = $this->state($free);
        $this->actAs(self::TENANT_A, ['role.delete', 'role.view']);

        $bodies = [];
        foreach (['held' => $held, 'pending' => $pending, 'free' => $free, 'system' => $system, 'live' => $live, 'other' => $other,
            'random' => Uuid::v4()->toRfc4122(), 'not a uuid' => 'not-a-uuid'] as $case => $id) {
            $this->restore($id);
            $bodies[$case] = [$this->httpStatus(), (string) $this->client()->getResponse()->getContent()];
        }

        $this->restore($free);
        self::assertSame([409, 'role.delete_needs_role_assign', 'Restoring a role also needs permission to give roles to people and take them away, and you don\'t have it. Ask an owner.'], $this->refusal());
        self::assertSame([$bodies['free']], array_values(array_unique($bodies, \SORT_REGULAR)), 'The backstop answered differently by id: '.json_encode($bodies));
        $this->assertUntouched($free, $before);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::AUTHZ_PERMISSION_DENIED]),
            'The backstop wrote a denial row: it must be a business rule, not a second permission gate.');
    }

    /**
     * An inactive actor is refused before the role is read (until G4-C0: 409 `user_role.actor_is_inactive`).
     * **G4-C0 (2026-10-22): API-O-22 CLOSED (a)** — `IdentityReader` refuses an inactive principal, so this actor-side
     * request is the constant 401 at the edge (`authentication-failed`). The under-lock `user_role.actor_is_inactive`
     * backstop is kept, reachable only when `is_active` is cleared outside `UpdateUser` (SQL) while the request waits on
     * the lock (G4-C1's race test).
     */
    #[Test]
    public function anInactiveActorCannotRestoreARole(): void
    {
        $role = $this->archivedRole(['inventory_item.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$this->actorId]);
        $before = $this->state($role);

        $this->restore($role);

        self::assertSame([401, 'urn:erp:problem:authentication-failed'], [$this->httpStatus(), $this->json()['type'] ?? null]);
        $this->assertUntouched($role, $before);
    }

    /**
     * **V2-R-6's restore-side test** (`API.md` §15.1, response R-12): restoring a role changes no user's `/me` permission set —
     * not the restorer's, not a bystander's; and a REFUSED restore of a role carrying a planted grant leaves that holder's
     * `/me` as it was (the planted grant confers nothing while the role is archived, and still nothing after).
     */
    #[Test]
    public function restoringARoleChangesNoUsersPermissions(): void
    {
        $bystander = $this->holderOf([$this->composedRole(['warehouse.view', 'inventory_item.view'])]);
        $planted = $this->archivedRole(['supplier.view']);
        $plantedHolder = $this->holderOf([$this->composedRole(['inventory_item.view']), $planted]);
        $role = $this->archivedRole(['supplier.view', 'inventory_item.view']);
        $owner = $this->principal(DefaultRoles::OWNER);

        $before = [$this->meCodes($bystander), $this->meCodes($plantedHolder), $this->meCodes($owner)];
        self::assertNotContains('supplier.view', $before[1], 'An archived role conferred its code: the fixture proves nothing.');

        $this->actAsUser(self::TENANT_A, $owner);
        $this->restore($role);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsUser(self::TENANT_A, $owner);
        $this->restore($planted);
        $refusal = $this->refusal();

        // Judged on the effect first, whatever came back (RED M-3): with the holder refusal removed this is what reddens.
        self::assertSame($before, [$this->meCodes($bystander), $this->meCodes($plantedHolder), $this->meCodes($owner)], 'A restore changed someone\'s permissions.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM role WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$planted]),
            'The role carrying a planted grant came back.');
        self::assertSame('role.has_holders', $refusal[1]);
    }

    /** @param list<string> $codes */
    private function archivedRole(array $codes): string
    {
        $role = $this->composedRole($codes);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() - interval \'1 minute\' WHERE id = ?::uuid', [$role]);

        return $role;
    }

    /** @return list<string> the user's `/me` permission set, sorted */
    private function meCodes(string $userId): array
    {
        $this->actAsUser(self::TENANT_A, $userId);
        $this->send('GET', '/me');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $codes = $this->json()['permission_codes'] ?? null;
        self::assertIsArray($codes, '/me serves no permissions list.');
        sort($codes);

        return array_values($codes);
    }

    private function restore(string $roleId): void
    {
        $this->send('POST', '/roles/'.$roleId.'/restoration');
    }

    /** @return array{int, mixed, mixed} */
    private function refusal(): array
    {
        return [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null];
    }

    /** @return array{deleted: bool, updated_at: string, codes: string} */
    private function state(string $roleId): array
    {
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT r.deleted_at IS NOT NULL AS deleted, r.updated_at::text AS updated_at,
                   COALESCE((SELECT string_agg(rp.permission_code, ',' ORDER BY rp.permission_code) FROM role_permission rp WHERE rp.role_id = r.id), '') AS codes
              FROM role r WHERE r.id = ?::uuid
            SQL, [$roleId])[0];

        return ['deleted' => (bool) $row['deleted'], 'updated_at' => (string) $row['updated_at'], 'codes' => (string) $row['codes']];
    }

    /** @param array{deleted: bool, updated_at: string, codes: string} $before */
    private function assertUntouched(string $roleId, array $before): void
    {
        self::assertSame($before, $this->state($roleId), 'A refused restore changed the role.');
        self::assertSame([], $this->restorationEvents(), 'A refused restore wrote role.restored.');
    }

    /** @return list<array{actor: string, subject_type: string, subject: string, has_ip: bool, metadata: array<string, mixed>}> */
    private function restorationEvents(): array
    {
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT actor_user_id::text AS actor, subject_type, subject_id::text AS subject, ip_address IS NOT NULL AS has_ip, metadata::text AS metadata
              FROM audit_log WHERE event_type = 'role.restored' ORDER BY occurred_at, id
            SQL);

        return array_map(static function (array $row): array {
            $metadata = json_decode((string) $row['metadata'], true);
            \assert(\is_array($metadata));

            return ['actor' => (string) $row['actor'], 'subject_type' => (string) $row['subject_type'], 'subject' => (string) $row['subject'],
                'has_ip' => (bool) $row['has_ip'], 'metadata' => $metadata];
        }, $rows);
    }
}
