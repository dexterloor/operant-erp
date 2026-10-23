<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\RoleAssignment;
use App\Modules\UserManagement\Application\RoleGrantProposalConfirmation;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`UpdateRole` — `PATCH /api/v1/roles/{id}`, any subset of `{name, description, permission_codes}`** (roles slice 4, C2;
 * `docs/session13/roles-s4-design.md` §3.2, §4.1, §4.3, §4.5, §4.7, §7; **Q-A** `roles-s4-c2-decisions.md` FINAL +
 * `roles-s4-c2-decisions-red.md` §5 item 6 / §6 (a); `SEC` D-7.1, D-7.3, D-7.4, D-7.5). One rule per test; every refusal's
 * sentence is `UX`'s copy (`docs/session13/roles-s4-ux-copy.md` #1, #2, #4–#12), pinned as a LITERAL; **every refusal writes nothing** (`assertUnchanged()` reads
 * the role's codes, name and `updated_at`, and counts the two definition events).
 *
 * Roles are seeded as the owner connection (`RoleGrantFixtures`) — G-10's path, so a seeded role may hold what the API would
 * refuse to compose (that is how PC-4-on-the-result and the pre-existing spans are reached). The concurrency half (the
 * positional lock-first case; Q-A's demotion twin) lives in `PurchaseOrderRowLockTest`.
 */
final class RoleUpdateTest extends ApiTestCase
{
    use RoleGrantFixtures;

    private const MARKER = OwnerMarker::CODE;

    /** 200; the body IS `GetRole`'s body after the writes; one `role.permissions_changed` row, cell by cell; `updated_at` moves. */
    #[Test]
    public function aRoleIsEditedReadBackAndAudited(): void
    {
        $role = $this->composedRole(['audit_log.view_record', 'inventory_item.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->updatedAt($role);

        $this->patch($role, ['name' => 'Stock clerks', 'permission_codes' => ['inventory_item.view', 'goods_receipt.view']]);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame([
            'id' => $role,
            'name' => 'Stock clerks',
            'description' => '',
            'permission_codes' => ['goods_receipt.view', 'inventory_item.view'],
            'is_system' => false,
            'holder_count' => 0,
            'margin_exposure' => false,
            'archived_at' => null,
        ], $body);
        $this->send('GET', '/roles/'.$role);
        self::assertSame($body, $this->json(), 'The 200 body is not GetRole\'s body.');
        self::assertNotSame($before, $this->updatedAt($role), 'updated_at did not move (RED F-14).');

        $rows = $this->roleEvents();
        self::assertCount(1, $rows, 'Not exactly one role.* row for one edit (E-15f: never per holder).');
        self::assertSame([AuditEvent::ROLE_PERMISSIONS_CHANGED, (string) $this->actorId, 'role', $role, true],
            [$rows[0]['event_type'], $rows[0]['actor'], $rows[0]['subject_type'], $rows[0]['subject'], $rows[0]['has_ip']]);
        self::assertSame([
            RoleEventMetadata::ADDED => ['goods_receipt.view'],
            RoleEventMetadata::FIELDS => ['name'],
            RoleEventMetadata::REMOVED => ['audit_log.view_record'],
            RoleEventMetadata::ROLE_ID => $role,
        ], $rows[0]['metadata'], 'D-7.3: a code-set edit writes role.permissions_changed alone, naming the changed fields.');
    }

    /**
     * **`SEC` D-7.3:** an edit that changes only the name and/or description writes `role.updated` `{role_id, fields}` —
     * field NAMES, never values; a codes-only edit names no fields. A rename to a spelling of the role's own name is not a
     * collision.
     */
    #[Test]
    public function aRenameOrRedescriptionWritesRoleUpdatedWithFieldNamesOnly(): void
    {
        $role = $this->composedRole(['inventory_item.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->patch($role, ['name' => 'Night shift', 'description' => 'Counts at night']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->patch($role, ['name' => 'NIGHT SHIFT']);
        self::assertSame([200, 'NIGHT SHIFT'], [$this->httpStatus(), $this->json()['name'] ?? null], 'A rename to its own spelling collided with itself.');
        $this->patch($role, ['permission_codes' => ['inventory_item.view', 'goods_receipt.view']]);
        self::assertSame(200, $this->httpStatus());

        $rows = $this->roleEvents();
        self::assertSame([AuditEvent::ROLE_UPDATED, AuditEvent::ROLE_UPDATED, AuditEvent::ROLE_PERMISSIONS_CHANGED], array_column($rows, 'event_type'));
        self::assertSame([RoleEventMetadata::FIELDS => ['name', 'description'], RoleEventMetadata::ROLE_ID => $role], $rows[0]['metadata']);
        self::assertSame([RoleEventMetadata::FIELDS => ['name'], RoleEventMetadata::ROLE_ID => $role], $rows[1]['metadata']);
        self::assertSame([RoleEventMetadata::ADDED => ['goods_receipt.view'], RoleEventMetadata::REMOVED => [], RoleEventMetadata::ROLE_ID => $role], $rows[2]['metadata']);
        self::assertSame(['role', $role, true], [$rows[0]['subject_type'], $rows[0]['subject'], $rows[0]['has_ip']]);
        self::assertStringNotContainsString('Night', json_encode($rows, \JSON_THROW_ON_ERROR), 'A value reached the metadata.');
    }

    /**
     * **Design step 7:** a request that changes nothing — `{}`, the same set in another order, the stored name — is 200 with
     * the role and writes NOTHING (no audit row, `updated_at` unmoved). `Idem = no` is sound because of this: no
     * `Idempotency-Key` is demanded, and a repeat of a real edit is a no-op.
     */
    #[Test]
    public function anEditThatChangesNothingWritesNothing(): void
    {
        $role = $this->composedRole(['goods_receipt.view', 'inventory_item.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $before = $this->snapshot($role);

        foreach ([[], ['permission_codes' => ['inventory_item.view', 'goods_receipt.view']], ['name' => $before['name'], 'description' => '']] as $body) {
            $this->patch($role, $body);
            self::assertSame(200, $this->httpStatus(), json_encode($body).' '.(string) $this->client()->getResponse()->getContent());
            self::assertSame($role, $this->json()['id']);
        }
        self::assertSame($before, $this->snapshot($role));
        self::assertSame([], $this->roleEvents());

        $this->patch($role, ['description' => 'Once']);
        $this->patch($role, ['description' => 'Once']);
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $this->roleEvents(), 'A repeated PATCH wrote a second row: the edit is not a set.');
    }

    /** **E-15h:** a system role is never edited — even a request that changes nothing — 409 `role.is_system`. */
    #[Test]
    public function aSystemRoleIsNeverEdited(): void
    {
        $role = $this->systemRole(DefaultRoles::VIEWER);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->snapshot($role);

        foreach ([[], ['name' => 'Renamed viewer']] as $body) {
            $this->patch($role, $body);
            self::assertSame([409, 'role.is_system', 'This is one of the built-in roles, and built-in roles can\'t be changed. A new role can be created instead.'], [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        }
        $this->assertUnchanged($role, $before);
    }

    /** An archived role cannot be edited (restore it first — `RestoreRole`, G2) — 409 `role.is_archived`, before Q-A (an admin on an archived marker role). */
    #[Test]
    public function anArchivedRoleIsRefused(): void
    {
        $role = $this->composedRole([self::MARKER, 'role.view']);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $before = $this->snapshot($role);

        $this->patch($role, ['name' => 'Back again']);
        self::assertSame([409, 'role.is_archived', 'This role is archived, so it can\'t be changed. It can be changed once it\'s restored, or a new role can be created in its place.'], [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        $this->assertUnchanged($role, $before);
    }

    /** No role, another workspace's role and a non-uuid id are one not-found; a caller without `role.edit` is the gate's 403. */
    #[Test]
    public function anUnknownRoleIs404AndACallerWithoutRoleEditIs403(): void
    {
        $other = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_B, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)', [$other, self::TENANT_B, 'Theirs']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));

        foreach ([Uuid::v4()->toRfc4122(), $other, 'not-a-uuid'] as $id) {
            $this->patch($id, ['name' => 'Mine now']);
            self::assertSame(404, $this->httpStatus(), $id);
        }
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, "SELECT count(*) FROM role WHERE name = 'Theirs'"));

        $role = $this->composedRole(['role.view']);
        $this->actAs(self::TENANT_A, ['role.view', 'role.create']);
        $this->patch($role, ['name' => 'Nope']);
        self::assertSame([403, 'role.edit'], [$this->httpStatus(), $this->json()['required_permission'] ?? null]);
    }

    /**
     * The shared field rules (`RoleDefinitionFields`, `CreateRole`'s), applied to the members PRESENT; 422, nothing written.
     *
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidBodies')]
    public function aMalformedBodyIs422AndWritesNothing(array $body, string $pointer, string $code): void
    {
        $role = $this->composedRole(['role.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->snapshot($role);

        $this->patch($role, $body);
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$pointer, $code], [$this->json()['errors'][0]['pointer'] ?? null, $this->json()['errors'][0]['code'] ?? null]);
        $this->assertUnchanged($role, $before);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'name null' => [['name' => null], '/name', 'required'];
        yield 'name blank' => [['name' => " \u{3000}"], '/name', 'blank'];
        yield 'name zero-width' => [['name' => "Own\u{200B}er"], '/name', 'invalid_character'];
        yield 'description too long' => [['description' => str_repeat('d', 501)], '/description', 'too_long'];
        yield 'codes null' => [['permission_codes' => null], '/permission_codes', 'required'];
        yield 'codes duplicated' => [['permission_codes' => ['role.view', 'role.view']], '/permission_codes/1', 'duplicate_value'];
        yield 'code unknown' => [['permission_codes' => ['role.view', 'role.fly']], '/permission_codes/1', 'unknown_reference'];
    }

    /** Unaccepted members (`is_system`, `id`, …) are refused — `is_system` can never be set here (E-15h). */
    #[Test]
    public function aBodyCannotSetIsSystem(): void
    {
        $role = $this->composedRole(['role.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->snapshot($role);

        $this->patch($role, ['is_system' => true]);
        self::assertContains($this->httpStatus(), [400, 422], (string) $this->client()->getResponse()->getContent());
        $this->assertUnchanged($role, $before);
    }

    /** Design §3.1 step 4 on an edit: another LIVE role's name, ignoring case and separators → 409; the role's own is free. */
    #[Test]
    public function aRenameCannotTakeAnotherLiveRolesName(): void
    {
        $role = $this->composedRole(['role.view']);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name, is_system) VALUES (?::uuid, ?::uuid, ?, true)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, 'purchasing_manager']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $before = $this->snapshot($role);

        $this->patch($role, ['name' => 'Purchasing Manager']);
        self::assertSame([409, 'role.name_taken', 'There\'s already a role named "Purchasing Manager", or one that differs from it only in capitals, spaces, dashes or underscores. Choose a different name.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        $this->assertUnchanged($role, $before);
    }

    /**
     * **E-15f's ceiling binds every ADDED code, held role or unheld** (owner D-3, reading A): a composed editor lacking
     * `invoice.issue` cannot add it, even to an unheld role; it may remove codes it lacks.
     */
    #[Test]
    public function anEditCannotAddACodeTheEditorLacks(): void
    {
        $role = $this->composedRole(['invoice.view', 'invoice.pay']);
        $this->actAs(self::TENANT_A, ['role.edit', 'role.view', 'invoice.view']);
        $before = $this->snapshot($role);

        $this->patch($role, ['permission_codes' => ['invoice.view', 'invoice.pay', 'invoice.issue']]);
        self::assertSame([409, 'role.exceeds_actor_permissions', 'Some of the permissions you\'re adding are ones you don\'t have yourself, and you can only add permissions you hold. Leave those out, or ask an owner to add them.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        $this->assertUnchanged($role, $before);

        $this->patch($role, ['permission_codes' => ['invoice.view']]);
        self::assertSame(200, $this->httpStatus(), 'Removing a code the editor lacks is not bounded by the ceiling. '.(string) $this->client()->getResponse()->getContent());
    }

    /**
     * **Q-A — a role whose STORED or RESULTING codes confer the owner marker is changed only by an owner, held or unheld**
     * (`roles-s4-c2-decisions-red.md` §5 item 6). Every non-owner case answers `role.marker_needs_owner` — including ADDING the
     * marker, where the rule code (not the ceiling's) is asserted so a dropped "resulting set" disjunct shows as a code change,
     * and REMOVING the marker, the only case the "stored set" disjunct alone decides (a first mutation run of that disjunct
     * stayed green without it — every other case's result still confers the marker).
     * The owner does each of the same edits → 200. N-3's exact seat is the held case: an `admin` taking
     * `credit_note.adjust` out of a held `{marker, credit_note.issue, credit_note.adjust, invoice.view}`.
     *
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('markerRoleEdits')]
    public function onlyAnOwnerChangesARoleThatMakesItsHoldersOwners(bool $held, array $body, bool $markerStored): void
    {
        $codes = $markerStored ? [self::MARKER, 'credit_note.adjust', 'credit_note.issue', 'invoice.view'] : ['role.view'];
        $role = $this->composedRole($codes);
        if ($held) {
            $this->holderOf([$role]);
        }
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $before = $this->snapshot($role);

        $this->patch($role, $body);
        self::assertSame([409, 'role.marker_needs_owner', 'Only an owner can change this role, because it makes, or would make, the people given it owners.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null], (string) $this->client()->getResponse()->getContent());
        $this->assertUnchanged($role, $before);

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->patch($role, $body);
        self::assertSame(200, $this->httpStatus(), 'An owner may make the same edit. '.(string) $this->client()->getResponse()->getContent());
    }

    /** @return iterable<string, array{bool, array<string, mixed>, bool}> */
    public static function markerRoleEdits(): iterable
    {
        $withoutAdjust = ['permission_codes' => [self::MARKER, 'credit_note.issue', 'invoice.view']];
        yield 'held marker role, a non-marker X(admin) code removed (N-3)' => [true, $withoutAdjust, true];
        yield 'unheld marker role, a non-marker X(admin) code removed' => [false, $withoutAdjust, true];
        // The one case only the STORED-set disjunct decides: the result no longer confers the marker (mutation M2).
        yield 'unheld marker role, the marker itself removed' => [false, ['permission_codes' => ['credit_note.adjust', 'credit_note.issue', 'invoice.view']], true];
        yield 'unheld marker role renamed' => [false, ['name' => 'Finance lead'], true];
        yield 'held marker role re-described' => [true, ['description' => 'Signs things'], true];
        yield 'the marker added to an unheld role (rule code, not the ceiling\'s)' => [false, ['permission_codes' => ['role.view', self::MARKER]], false];
    }

    /** Q-A sits AFTER the empty-delta step: a non-owner's `{}` on a marker role is 200 and writes nothing. */
    #[Test]
    public function aNonOwnersEmptyEditOfAMarkerRoleIsANoOp(): void
    {
        $role = $this->composedRole([self::MARKER, 'role.view']);
        $this->holderOf([$role]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $before = $this->snapshot($role);

        $this->patch($role, []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->assertUnchanged($role, $before);
    }

    /**
     * **E-15f's 2026-09-22 clause, as amended 2026-10-08:** a non-owner may not ADD an `X(admin)` code to a role anyone holds
     * (any `user_role` row, `SEC` D-7.4 — a deactivated holder counts) → 409 `role.owner_needed_for_held_role`, naming the
     * holder count and NO code (`UX` copy #10; RED B-H1). Once the codes left the body this test is the only API-level
     * evidence of WHICH added codes trigger the rule, so it adds one `X(admin)` code (`invoice.void`) AND one that is not
     * (`inventory_item.view`), with the refusal pinned literally. The same edit to an unheld role is 200 (its grant still
     * routes through E-15e).
     */
    #[Test]
    public function aNonOwnerCannotAddAnAdminExcludedCodeToAHeldRole(): void
    {
        $held = $this->composedRole(['invoice.view']);
        $holder = $this->holderOf([$held]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$holder]);
        $unheld = $this->composedRole(['invoice.view']);
        $this->actAs(self::TENANT_A, ['role.edit', 'role.view', 'invoice.view', 'invoice.void', 'inventory_item.view']);
        $before = $this->snapshot($held);

        $this->patch($held, ['permission_codes' => ['invoice.view', 'invoice.void', 'inventory_item.view']]);
        self::assertSame([409, 'role.owner_needed_for_held_role', 'This role is already given to 1 person, and some of the permissions you\'re adding can only reach people with an owner\'s confirmation. Ask an owner to add them, or they can go in a new role instead.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null], 'A deactivated holder must count (D-7.4).');
        $this->assertUnchanged($held, $before);

        $this->patch($unheld, ['permission_codes' => ['invoice.view', 'invoice.void', 'inventory_item.view']]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **The other half of #10's evidence (RED B-H1):** a non-owner adding ONLY codes outside `X(admin)` to a held role is
     * not routed to an owner — 200, the codes added. Together with the case above this pins that the rule keys on
     * `added ∩ X(admin)`, not on `added`.
     */
    #[Test]
    public function aNonOwnerMayAddCodesOutsideTheAdminExclusionToAHeldRole(): void
    {
        $held = $this->composedRole(['invoice.view']);
        $this->holderOf([$held]);
        $this->actAs(self::TENANT_A, ['role.edit', 'role.view', 'invoice.view', 'inventory_item.view', 'warehouse.view']);

        $this->patch($held, ['permission_codes' => ['invoice.view', 'inventory_item.view', 'warehouse.view']]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['inventory_item.view', 'invoice.view', 'warehouse.view'], $this->json()['permission_codes']);
    }

    /**
     * **`UX` copy #1 on `UpdateRole` (RED B-L2: no test drove an inactive ACTOR here — `:326`'s deactivates a HOLDER).** An
     * actor holding `role.edit` whose account is not active is refused; the role is unchanged. Until G4-C0 that was the 409
     * `user_role.actor_is_inactive` with the role-definition sentence.
     * **G4-C0 (2026-10-22): API-O-22 CLOSED (a)** — `IdentityReader` refuses an inactive principal, so this actor-side
     * request is the constant 401 at the edge (`authentication-failed`). The under-lock `user_role.actor_is_inactive`
     * backstop is kept, reachable only when `is_active` is cleared outside `UpdateUser` (SQL) while the request waits on
     * the lock (G4-C1's race test).
     */
    #[Test]
    public function anInactiveActorCannotEditARole(): void
    {
        $role = $this->composedRole(['inventory_item.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$this->actorId]);
        $before = $this->snapshot($role);

        $this->patch($role, ['name' => 'Renamed']);
        self::assertSame([401, 'urn:erp:problem:authentication-failed'], [$this->httpStatus(), $this->json()['type'] ?? null]);
        $this->assertUnchanged($role, $before);
    }

    /**
     * **E-15f's PC-13 re-run over every holder, edge grain, new conflicts only (`SEC` D-7.1).** A holder whose OTHER role
     * holds `purchase_order.create`:
     *  - gaining `purchase_order.approve` through this role → refused, naming the count and SoD-1's work, never the holder;
     *  - **edge grain**: a holder already spanning SoD-1 (create + approve, seeded) gaining `purchase_order.cancel` → refused
     *    (row grain would see SoD-1 as already spanned and let it through);
     *  - **tolerated**: a holder already carrying a cross-role pair (create elsewhere, approve here) gaining an unrelated code
     *    → 200 (the `edges(H ∪ O)` subtraction).
     */
    #[Test]
    public function anEditMayNotGiveAHolderANewConflictingPair(): void
    {
        $role = $this->composedRole(['purchase_order.view']);
        $this->holderOf([$role, $this->composedRole(['purchase_order.view', 'purchase_order.create'])]);
        $this->holderOf([$role]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->snapshot($role);

        $this->patch($role, ['permission_codes' => ['purchase_order.view', 'purchase_order.approve']]);
        self::assertSame([409, 'role.holder_would_conflict', 'With the other roles they have, 1 person given this role would then be able to raise purchase orders and also approve or cancel them (SoD-1). Leave these permissions out, or the conflicting roles have to be taken away from them first.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        $this->assertUnchanged($role, $before);

        $edge = $this->composedRole(['purchase_order.view']);
        $this->holderOf([$edge, $this->composedRole(['purchase_order.view', 'purchase_order.create', 'purchase_order.approve'])]);
        $this->patch($edge, ['permission_codes' => ['purchase_order.view', 'purchase_order.cancel']]);
        self::assertSame([409, 'role.holder_would_conflict'], [$this->httpStatus(), $this->json()['rule'] ?? null],
            'A new pair inside a row the holder already spans slipped through (row grain).');

        $tolerated = $this->composedRole(['purchase_order.view', 'purchase_order.approve']);
        $this->holderOf([$tolerated, $this->composedRole(['purchase_order.view', 'purchase_order.create'])]);
        $this->patch($tolerated, ['permission_codes' => ['purchase_order.view', 'purchase_order.approve', 'inventory_item.view']]);
        self::assertSame(200, $this->httpStatus(), 'A span the holder already carried froze the role. '.(string) $this->client()->getResponse()->getContent());
    }

    /**
     * **API-20 (design §4.3 step 9b):** removing the marker from a role is refused when it would leave no active owner —
     * the sole owner holds it only through this composed role → 409 `role.last_owner`. With a second owner → 200.
     */
    #[Test]
    public function removingTheMarkerMayNotLeaveTheWorkspaceWithoutAnOwner(): void
    {
        $this->actAs(self::TENANT_A, [self::MARKER, 'role.edit', 'role.view']);
        $role = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT role_id::text AS id FROM user_role WHERE user_id = ?::uuid', [$this->actorId])[0]['id'];
        $before = $this->snapshot($role);

        $this->patch($role, ['permission_codes' => ['role.edit', 'role.view']]);
        self::assertSame([409, 'role.last_owner', 'This change would leave the workspace with no owner. Make someone else an owner first.'], [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        $this->assertUnchanged($role, $before);

        $this->seedPrincipal(self::TENANT_A, [self::MARKER]);
        $this->patch($role, ['permission_codes' => ['role.edit', 'role.view']]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **PC-4 on the RESULT, unconditionally (`SEC` D-7.1 item 4):** a rename of a role whose stored codes span a split
     * (reachable only by SQL) is refused, even to an owner, with no code added.
     */
    #[Test]
    public function aRoleSpanningASplitCannotBeEditedWithoutRemovingTheSplit(): void
    {
        $role = $this->composedRole(['purchase_order.view', 'purchase_order.create', 'purchase_order.approve']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->snapshot($role);

        $this->patch($role, ['name' => 'Buyer-approver']);
        self::assertSame([409, 'role.separation_of_duty', 'One role can\'t cover both sides of a job that has to be split: anyone given it could raise purchase orders and also approve or cancel them (SoD-1). Leave out the permissions for one side.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        $this->assertUnchanged($role, $before);

        $this->patch($role, ['permission_codes' => ['purchase_order.view', 'purchase_order.create']]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** **Companions on the RESULT** (D-5a): removing `invoice.view` from a role keeping `credit_note.issue` is refused. */
    #[Test]
    public function anEditMayNotLeaveAPermissionWithoutTheViewItNeeds(): void
    {
        $role = $this->composedRole(['credit_note.issue', 'invoice.view']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->snapshot($role);

        $this->patch($role, ['permission_codes' => ['credit_note.issue']]);
        self::assertSame([409, 'role.missing_companion_view'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame('Some permissions in this role only work alongside others it doesn\'t have: crediting invoices for returned goods needs seeing invoices. Add the missing ones, or leave out the ones that need them.', $this->json()['detail']);
        $this->assertUnchanged($role, $before);
    }

    /**
     * **U-C0 (2026-10-23, V2-R-25): a composed role that can add people must be able to see them — and a role made
     * before the row is not rewritten.** A role written by SQL holding `app_user.create` without `app_user.view` (the
     * shape the row now refuses at composition) is refused on ANY edit that keeps the shape, a rename included — the
     * shipped behaviour that changes for such a legacy role (owner notice) — and the edit that adds the view is accepted.
     * Mutation: drop the `app_user.create` row from `RoleCompanions` → the rename is a 200, red.
     */
    #[Test]
    public function aRoleThatCanAddPeopleButNotSeeThemCannotBeKeptThatWay(): void
    {
        $role = $this->composedRole(['app_user.create']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->snapshot($role);

        $this->patch($role, ['name' => 'Recruiter']);
        self::assertSame([409, 'role.missing_companion_view', 'Some permissions in this role only work alongside others it doesn\'t have: adding people needs seeing people. Add the missing ones, or leave out the ones that need them.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        $this->assertUnchanged($role, $before);

        $this->patch($role, ['permission_codes' => ['app_user.create', 'app_user.view']]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **Pending proposals are NOT written to (E-15f; formerly carried by the proposal-handling tripwire, retired at G4-C2).** A role
     * with a pending proposal LOSES its `X(admin)` code: the proposal keeps its snapshot and stays pending; an owner's
     * confirmation is then refused `role_changed`; and a non-owner who could now give the role directly meets AssignRole's
     * gap B, `user_role.proposal_pending` (design §5 change 2, RED L-8).
     */
    #[Test]
    public function aRoleThatLosesAnAdminExcludedCodeLeavesItsPendingProposalUntouched(): void
    {
        $role = $this->composedRole(['invoice.view', 'invoice.void']);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $admin = $this->principal(DefaultRoles::ADMIN);
        $proposal = $this->seedPendingProposal($subject, $role, $admin);
        $snapshot = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT permission_codes::text AS codes, status FROM role_grant_proposal WHERE id = ?::uuid', [$proposal]);

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->patch($role, ['permission_codes' => ['invoice.view']]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($snapshot, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT permission_codes::text AS codes, status FROM role_grant_proposal WHERE id = ?::uuid', [$proposal]),
            'UpdateRole wrote to a pending proposal.');

        $this->confirm($proposal);
        self::assertSame([409, 'role_grant_proposal.role_changed', RoleGrantProposalConfirmation::ROLE_CHANGED],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);

        $this->actAsUser(self::TENANT_A, $admin);
        $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $role], $this->key());
        self::assertSame([409, 'user_role.proposal_pending', RoleAssignment::PROPOSAL_PENDING_LIVE],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
    }

    /**
     * **R-1, pinned (`roles-s4-c2-decisions-red.md` Q3/§5 item 7; G-32):** Q-A does not close every non-owner path to G-32's
     * Medium seat. A marker holder with `finance` (`credit_note.issue`) and a NON-marker role carrying `credit_note.adjust`:
     * an `admin` may remove `credit_note.adjust` from that role (D-7.5) — or, shipped since C1, revoke it — leaving the seat
     * `{marker, credit_note.issue}` without adjust, whose `distinct` is pinned reachable by
     * `AdjustmentDecisionTest::aComposedMarkerSeatWithoutAdjustRecordsDifferentGoods`. No money power grows. A later "fix"
     * that refuses either act turns this red, visibly.
     */
    #[Test]
    public function aNonOwnerCanStillTakeAdjustmentPowerOffAMarkerHoldersOtherRole(): void
    {
        $marker = $this->composedRole([self::MARKER, 'invoice.view']);
        $adjustEdited = $this->composedRole(['credit_note.adjust', 'invoice.view']);
        $adjustRevoked = $this->composedRole(['credit_note.adjust', 'invoice.view']);
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $seatA = $this->holderOf([$marker, $adjustEdited, $finance]);
        $seatB = $this->holderOf([$marker, $adjustRevoked, $finance]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->patch($adjustEdited, ['permission_codes' => ['invoice.view']]);
        self::assertSame(200, $this->httpStatus(), 'D-7.5: a non-owner may remove a non-marker X(admin) code from a held non-marker role. '
            .(string) $this->client()->getResponse()->getContent());
        $this->send('DELETE', '/users/'.$seatB.'/roles/'.$adjustRevoked);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        foreach ([$seatA, $seatB] as $seat) {
            $codes = array_column($this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                SELECT DISTINCT rp.permission_code AS code FROM user_role ur JOIN role_permission rp ON rp.role_id = ur.role_id
                 WHERE ur.user_id = ?::uuid AND rp.permission_code IN ('tenant_settings.edit', 'credit_note.issue', 'credit_note.adjust')
                 ORDER BY 1
                SQL, [$seat]), 'code');
            self::assertSame(['credit_note.issue', self::MARKER], $codes, 'The seat is not N-1\'s {marker, issue, no adjust}.');
        }
    }

    /** @param array<string, mixed> $body */
    private function patch(string $roleId, array $body): void
    {
        $this->send('PATCH', '/roles/'.$roleId, $body);
    }

    private function updatedAt(string $roleId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS t FROM role WHERE id = ?::uuid', [$roleId])[0]['t'];
    }

    /** @return array{name: string, description: string, updated_at: string, codes: string} */
    private function snapshot(string $roleId): array
    {
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT r.name, r.description, r.updated_at::text AS updated_at,
                   COALESCE((SELECT string_agg(rp.permission_code, ',' ORDER BY rp.permission_code) FROM role_permission rp WHERE rp.role_id = r.id), '') AS codes
              FROM role r WHERE r.id = ?::uuid
            SQL, [$roleId])[0];

        return ['name' => (string) $row['name'], 'description' => (string) $row['description'], 'updated_at' => (string) $row['updated_at'], 'codes' => (string) $row['codes']];
    }

    /** @param array{name: string, description: string, updated_at: string, codes: string} $before */
    private function assertUnchanged(string $roleId, array $before): void
    {
        self::assertSame($before, $this->snapshot($roleId), 'A refused edit changed the role.');
        self::assertSame([], $this->roleEvents(), 'A refused edit wrote a role definition event.');
    }

    /** @return list<array{event_type: string, actor: string, subject_type: string, subject: string, has_ip: bool, metadata: array<string, mixed>}> */
    private function roleEvents(): array
    {
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor, subject_type, subject_id::text AS subject, ip_address IS NOT NULL AS has_ip, metadata::text AS metadata
              FROM audit_log WHERE event_type IN (?, ?) ORDER BY occurred_at, id
            SQL, [AuditEvent::ROLE_PERMISSIONS_CHANGED, AuditEvent::ROLE_UPDATED]);

        return array_map(static function (array $row): array {
            $metadata = json_decode((string) $row['metadata'], true);
            \assert(\is_array($metadata));
            ksort($metadata); // jsonb stores keys in its own order

            return ['event_type' => (string) $row['event_type'], 'actor' => (string) $row['actor'], 'subject_type' => (string) $row['subject_type'],
                'subject' => (string) $row['subject'], 'has_ip' => (bool) $row['has_ip'], 'metadata' => $metadata];
        }, $rows);
    }
}
