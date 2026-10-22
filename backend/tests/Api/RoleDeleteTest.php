<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`DeleteRole` — `DELETE /api/v1/roles/{id}`, a soft delete** (roles slice 4, C3; `docs/session13/roles-s4-design.md` §3.3,
 * §5.1–§5.4, §7; owner D-6 option (1); `SEC` `roles-s4-sec-data.md` D-7.2 as corrected by `roles-s4-sec-data-red.md` item 1,
 * and D-7.4). One rule per test; every refusal's sentence is `UX`'s copy (`docs/session13/roles-s4-ux-copy.md` #1,
 * #16–#20), pinned as a LITERAL; **every
 * refusal writes nothing** (`assertUntouched()` reads `deleted_at`, `updated_at` and the role's codes, and counts
 * `role.deleted`).
 *
 * **Fixture rule (RED F1-D):** roles are seeded as the owner connection (G-10's path, bypassing `RoleCompanions`), so every
 * actor here that is meant to reach the role holds `role.assign` as well as `role.delete` — owner or admin — EXCEPT the
 * backstop's own test, whose actor is deliberately the SQL-composed seat the backstop exists for. The backstop's lock-time
 * read is proved in `PurchaseOrderRowLockTest::aDeleterDemotedWhileWaitingMeetsTheBackstopOnItsLockTimeCodes`, and the
 * positional lock-first case there too.
 */
final class RoleDeleteTest extends ApiTestCase
{
    use RoleGrantFixtures;

    /** 204, no body; `deleted_at` set; the codes STAY; one `role.deleted` `{role_id}` row on the role; the name is free again. */
    #[Test]
    public function anUnusedRoleIsDeletedKeptForTheRecordAndAudited(): void
    {
        $role = $this->composedRole(['goods_receipt.view', 'inventory_item.view']);
        $name = $this->roleName($role);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $before = $this->state($role);

        $this->delete($role);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('', (string) $this->client()->getResponse()->getContent(), 'A 204 carried a body.');
        $after = $this->state($role);
        self::assertTrue($after['deleted'], 'deleted_at was not set.');
        self::assertNotSame($before['updated_at'], $after['updated_at'], 'updated_at did not move.');
        self::assertSame($before['codes'], $after['codes'], 'A deleted role\'s codes must stay (kept for audit, design §3.3).');

        $rows = $this->deletionEvents();
        self::assertCount(1, $rows);
        self::assertSame([(string) $this->actorId, 'role', $role, true, [RoleEventMetadata::ROLE_ID => $role]],
            [$rows[0]['actor'], $rows[0]['subject_type'], $rows[0]['subject'], $rows[0]['has_ip'], $rows[0]['metadata']]);

        $this->send('GET', '/roles/'.$role);
        self::assertSame(200, $this->httpStatus());
        self::assertNotNull($this->json()['archived_at'] ?? null, 'GetRole does not show the role as archived.');

        // `role_tenant_name_live` is partial on live rows: the name is free for a new role.
        $this->send('POST', '/roles', ['name' => $name, 'permission_codes' => ['role.view']], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** §4.2's `Idem = no`: a repeat meets the deleted role — 409 `role.is_archived` — and writes no second row. */
    #[Test]
    public function aRepeatIsRefusedAsAlreadyDeleted(): void
    {
        $role = $this->composedRole(['role.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));

        $this->delete($role);
        self::assertSame(204, $this->httpStatus());
        $before = $this->state($role);
        $this->delete($role);

        self::assertSame([409, 'role.is_archived', 'This role has already been deleted. It\'s listed with the archived roles.'], $this->refusal());
        self::assertSame($before, $this->state($role));
        self::assertCount(1, $this->deletionEvents(), 'A repeated delete wrote a second row.');
    }

    /** **E-15h:** a system role is never deleted — 409 `role.is_system`, even by an owner. */
    #[Test]
    public function aSystemRoleIsNeverDeleted(): void
    {
        $role = $this->systemRole(DefaultRoles::VIEWER);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->state($role);

        $this->delete($role);

        self::assertSame([409, 'role.is_system', 'This is one of the built-in roles, and built-in roles can\'t be deleted.'], $this->refusal());
        $this->assertUntouched($role, $before);
    }

    /** No role, another workspace's role and a non-uuid id are one not-found; a caller without `role.delete` is the gate's 403. */
    #[Test]
    public function anUnknownRoleIs404AndACallerWithoutRoleDeleteIs403(): void
    {
        $other = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_B, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)', [$other, self::TENANT_B, 'Theirs']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));

        foreach ([Uuid::v4()->toRfc4122(), $other, 'not-a-uuid'] as $id) {
            $this->delete($id);
            self::assertSame(404, $this->httpStatus(), $id);
        }
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM role WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$other]));

        $role = $this->composedRole(['role.view']);
        $this->actAs(self::TENANT_A, ['role.view', 'role.assign', 'role.edit']);
        $this->delete($role);
        self::assertSame([403, 'role.delete'], [$this->httpStatus(), $this->json()['required_permission'] ?? null]);
    }

    /**
     * **E-15h holders, `SEC` D-7.4:** ANY `user_role` row refuses — an active holder, a DEACTIVATED one and one whose account
     * is soft-deleted all count (fail closed), so the sentence names 3 where `GetRole`'s `holder_count` excludes the last.
     * Nothing is stripped.
     */
    #[Test]
    public function aRoleStillGivenToAnyoneIsRefusedNamingEveryRow(): void
    {
        $role = $this->composedRole(['inventory_item.view']);
        $this->holderOf([$role]);
        $deactivated = $this->holderOf([$role]);
        $removed = $this->holderOf([$role]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$deactivated]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$removed]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->state($role);

        $this->delete($role);

        self::assertSame([409, 'role.has_holders', 'This role is still given to 3 people, counting anyone whose account is deactivated. It can be deleted once nobody holds it.'], $this->refusal());
        $this->assertUntouched($role, $before);
        self::assertSame(3, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE role_id = ?::uuid', [$role]), 'A holder was stripped.');
    }

    /**
     * **E-15h pending proposals, `SEC` D-7.2:** every `pending` proposal refuses, a STALE one (expired, not yet lapsed)
     * included — fail closed — and the sentence names the count; decided proposals do not count. A role that is both held
     * and proposed meets the holder refusal first (D-7.2's order).
     */
    #[Test]
    public function aRoleWithOpenRequestsIsRefusedCountingExpiredOnes(): void
    {
        $role = $this->composedRole(['inventory_item.view']);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer);
        $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer, ageHours: 400);
        $this->seedDecidedProposal('lapsed', $this->seedPrincipal(self::TENANT_A, []), $role, $proposer, null);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $before = $this->state($role);

        $this->delete($role);

        self::assertSame([409, 'role.has_pending_proposals', '2 role proposals are still open for this role, counting any that are more than 14 days old. It can be deleted once they\'re closed: an owner can reject them, and whoever proposed one can withdraw it. You can find them among the open role proposals.'], $this->refusal());
        $this->assertUntouched($role, $before);

        $this->holderOf([$role]);
        $this->delete($role);
        self::assertSame([409, 'role.has_holders', 'This role is still given to 1 person, counting anyone whose account is deactivated. It can be deleted once nobody holds it.'], $this->refusal(), 'Holders are not checked before proposals.');
    }

    /**
     * **Clearability (Q-5 (a); `SEC` D-7.2 item 5; the tripwire's "re-check before building"):** a stale proposal blocks the
     * delete; an owner rejects it (an expired pending proposal MAY be rejected, §5.2.7); the delete then succeeds.
     */
    #[Test]
    public function aStaleRequestIsClearedByAnOwnerAndTheRoleCanThenBeDeleted(): void
    {
        $role = $this->composedRole(['inventory_item.view']);
        $proposal = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $this->principal(DefaultRoles::ADMIN), ageHours: 400);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));

        $this->delete($role);
        self::assertSame([409, 'role.has_pending_proposals', '1 role proposal is still open for this role, counting any that are more than 14 days old. It can be deleted once they\'re closed: an owner can reject them, and whoever proposed one can withdraw it. You can find them among the open role proposals.'], $this->refusal());

        $this->send('POST', '/role-grant-proposals/'.$proposal.'/rejection', ['reason' => 'Retiring the role']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->delete($role);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **The `role.assign` backstop (`SEC` D-7.2 item 4; RED F1-A):** a seat composed by SQL (G-10 — the API refuses to
     * compose it, owner D-6 (1)) holding `role.delete` without `role.assign` gets ONE body for every id — a held role, a role
     * with a pending proposal, a free role, a system role, a deleted role, another workspace's role, a random uuid and a
     * non-uuid — so nothing about the role, and no pending bit, is disclosed. No denial row (a 409, not a second gate), no
     * write.
     */
    #[Test]
    public function aDeleterWithoutRoleAssignIsRefusedAloneTheSameWayForEveryId(): void
    {
        $held = $this->composedRole(['inventory_item.view']);
        $this->holderOf([$held]);
        $pending = $this->composedRole(['inventory_item.view']);
        $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $pending, $this->principal(DefaultRoles::ADMIN));
        $free = $this->composedRole(['inventory_item.view']);
        $system = $this->systemRole(DefaultRoles::VIEWER);
        $deleted = $this->composedRole(['inventory_item.view']);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$deleted]);
        $other = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_B, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)', [$other, self::TENANT_B, 'Theirs']);
        $before = $this->state($free);
        $this->actAs(self::TENANT_A, ['role.delete', 'role.view']);

        $bodies = [];
        foreach (['held' => $held, 'pending' => $pending, 'free' => $free, 'system' => $system, 'deleted' => $deleted, 'other' => $other,
            'random' => Uuid::v4()->toRfc4122(), 'not a uuid' => 'not-a-uuid'] as $case => $id) {
            $this->delete($id);
            $bodies[$case] = [$this->httpStatus(), (string) $this->client()->getResponse()->getContent()];
        }

        $this->delete($free);
        self::assertSame([409, 'role.delete_needs_role_assign', 'Deleting a role also needs permission to give roles to people and take them away, and you don\'t have it. Ask an owner.'], $this->refusal());
        self::assertSame([$bodies['free']], array_values(array_unique($bodies, \SORT_REGULAR)), 'The backstop answered differently by id: '.json_encode($bodies));
        $this->assertUntouched($free, $before);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::AUTHZ_PERMISSION_DENIED]),
            'The backstop wrote a denial row: it must be a business rule, not a second permission gate.');
    }

    /**
     * **`UX` copy #1 on `DeleteRole` (RED B-L2: no test drove an inactive ACTOR here — `:127`'s deactivates a HOLDER).** An
     * actor holding `role.delete` and `role.assign` whose account is not active is refused before the role is read; nothing
     * written. Until G4-C0 that was the 409 `user_role.actor_is_inactive` after the permission re-check.
     * **G4-C0 (2026-10-22): API-O-22 CLOSED (a)** — `IdentityReader` refuses an inactive principal, so this actor-side
     * request is the constant 401 at the edge (`authentication-failed`). The under-lock `user_role.actor_is_inactive`
     * backstop is kept, reachable only when `is_active` is cleared outside `UpdateUser` (SQL) while the request waits on
     * the lock (G4-C1's race test).
     */
    #[Test]
    public function anInactiveActorCannotDeleteARole(): void
    {
        $role = $this->composedRole(['inventory_item.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$this->actorId]);
        $before = $this->state($role);

        $this->delete($role);

        self::assertSame([401, 'urn:erp:problem:authentication-failed'], [$this->httpStatus(), $this->json()['type'] ?? null]);
        $this->assertUntouched($role, $before);
    }

    private function delete(string $roleId): void
    {
        $this->send('DELETE', '/roles/'.$roleId);
    }

    /** @return array{int, mixed, mixed} */
    private function refusal(): array
    {
        return [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null];
    }

    private function roleName(string $roleId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT name FROM role WHERE id = ?::uuid', [$roleId])[0]['name'];
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
        self::assertSame($before, $this->state($roleId), 'A refused delete changed the role.');
        self::assertSame([], $this->deletionEvents(), 'A refused delete wrote role.deleted.');
    }

    /** @return list<array{actor: string, subject_type: string, subject: string, has_ip: bool, metadata: array<string, mixed>}> */
    private function deletionEvents(): array
    {
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT actor_user_id::text AS actor, subject_type, subject_id::text AS subject, ip_address IS NOT NULL AS has_ip, metadata::text AS metadata
              FROM audit_log WHERE event_type = ? ORDER BY occurred_at, id
            SQL, [AuditEvent::ROLE_DELETED]);

        return array_map(static function (array $row): array {
            $metadata = json_decode((string) $row['metadata'], true);
            \assert(\is_array($metadata));

            return ['actor' => (string) $row['actor'], 'subject_type' => (string) $row['subject_type'], 'subject' => (string) $row['subject'],
                'has_ip' => (bool) $row['has_ip'], 'metadata' => $metadata];
        }, $rows);
    }
}
