<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\RoleAdministrationActor;
use App\Modules\UserManagement\Application\UserLiveness;
use App\Modules\UserManagement\Application\UserUpdate;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`UpdateUser` — `PATCH /api/v1/users/{id}`, `app_user.edit`** (G4-C1; `docs/session13/g4-users-design.md` §5, Revision 1
 * FINAL R1.2 G4-C1; `g4-users-final-red.md` L-1, L-2, L-7; owner ruling RO-U1 (a); E-15g).
 *
 * One rule per test, every one able to go red (the mutation that turns it is named on it):
 *  - T-1-1 a deactivation strips EVERY grant (archived role too) and records each; the flush writes exactly the granted
 *    columns (the ORM changeset, measured from the statement — H0's method);
 *  - T-1-2 the person's own token stops at once (V2-S-7's literal wording);
 *  - T-1-5 a reactivation restores no role;
 *  - T-1-6 E-15a, owners included, a self-rename allowed;
 *  - T-1-7 E-15b (an admin cannot switch off an owner; an owner can) and L-7 (a refused combined patch renames nothing);
 *  - T-1-8 API-20: two owners → one may switch the other off; the floor itself driven directly, because no HTTP path reaches
 *    it in a correct build (E-15b makes the actor an active marker holder — `LastOwnerFloor`'s docblock, as for `RevokeRole`);
 *  - T-1-9 the lapse: pending for / by the person lapse with their reasons, a decided one is untouched, a planted
 *    future-dated one lapses at its own `proposed_at` (D-8);
 *  - T-1-11 a rename records field names only and moves no earlier `actor_label`; a no-change patch writes nothing;
 *  - T-1-15 a person hydrated earlier in the kernel is reloaded under the lock (final RED L-1);
 *  - T-1-16 E-15b on the return-side strip (an admin cannot reactivate an SQL-deactivated marker holder; an owner can);
 *  - the 404s / 409 deleted / 422s / 403, and the placeholder copy pinned literally.
 * The clock and token half (T-1-3, T-1-4, T-1-17) is `App\Tests\Auth\UserSessionStampTest`; the concurrency half (T-1-12,
 * T-1-13, T-1-14, M-1-12's race) is `PurchaseOrderRowLockTest`'s; arms B/F/G (T-1-10) are `ReconcileRolesCommandTest`'s.
 */
final class UserUpdateTest extends ApiTestCase
{
    /** The placeholder copy (`UX` to confirm, V2-C-7), verbatim, so a wording change is a visible edit. */
    private const COPY = [
        'app_user.is_archived' => 'This person\'s account has been deleted, so it can\'t be changed.',
        'app_user.actor_is_subject' => 'Nobody can switch off their own account. Ask someone else who manages people to do it.',
        'app_user.marker_needs_owner' => 'Only an owner can do this, because this person holds a role that makes them an owner.',
        'app_user.last_owner' => 'This would leave the workspace with no owner. Make someone else an owner first.',
    ];

    /**
     * **T-1-1.** The person holds two grants — the viewer role and an ARCHIVED role (seeded by SQL as the owner). After the
     * call: no `user_role` row, two `role.revoked` rows (`{role_id, revoke_reason: subject_removed}`, the request's IP), one
     * `user.deactivated` (`{}`) written BEFORE them, the body `is_active: false` and `roles: []`. The flush is one
     * `UPDATE app_user` naming exactly `is_active`, `updated_at` and `sessions_valid_after` (the column grant plus H0's).
     * Mutation M-1-2 (strip without `role.revoked`) → red here and in arm B.
     */
    #[Test]
    public function aDeactivationStripsEveryRoleAndRecordsEachOne(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $viewerRole = $this->onlyRoleOf($subject);
        $archived = $this->composedRole(['inventory_item.view']);
        $this->grant($subject, $archived);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->recorder()->start();
        $this->patch($subject, ['is_active' => false]);
        $this->recorder()->stop();

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([false, []], [$this->json()['is_active'] ?? null, $this->json()['roles'] ?? null]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$subject]));

        $expected = [$viewerRole, $archived];
        sort($expected);
        $revoked = $this->rows(AuditEvent::ROLE_REVOKED);
        self::assertSame(array_map(static fn (string $role): array => [
            'subject_id' => $subject,
            'metadata' => [RoleEventMetadata::ROLE_ID => $role, RoleEventMetadata::REVOKE_REASON => RoleEventMetadata::REVOKE_REASON_SUBJECT_REMOVED],
            'ip' => '127.0.0.1',
        ], $expected), array_map(static fn (array $row): array => ['subject_id' => $row['subject_id'], 'metadata' => $row['metadata'], 'ip' => $row['ip']], $revoked),
            'Not one role.revoked per stripped grant with revoke_reason: subject_removed.');
        $deactivated = $this->rows(AuditEvent::USER_DEACTIVATED);
        self::assertCount(1, $deactivated);
        self::assertSame([$subject, [], '127.0.0.1'], [$deactivated[0]['subject_id'], $deactivated[0]['metadata'], $deactivated[0]['ip']]);
        self::assertSame([AuditEvent::USER_DEACTIVATED, AuditEvent::ROLE_REVOKED, AuditEvent::ROLE_REVOKED], array_column($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT event_type FROM audit_log WHERE subject_id = ?::uuid ORDER BY occurred_at, id', [$subject]), 'event_type'),
            'The cause row (user.deactivated) is not written before the role.revoked rows.');

        $updates = $this->recorder()->matching('/^\s*UPDATE\s+app_user\b/i');
        self::assertCount(1, $updates, implode("\n", $updates));
        preg_match('/\bSET\s+(.*?)\s+WHERE\b/is', $updates[0], $set);
        $columns = array_map(static fn (string $assignment): string => trim(explode('=', $assignment)[0]), explode(',', $set[1] ?? ''));
        sort($columns);
        self::assertSame(['is_active', 'sessions_valid_after', 'updated_at'], $columns, 'The ORM changeset is not exactly the columns the deactivation assigns.');
    }

    /** **T-1-2** (V2-S-7's wording): the person's live token reads `/me`; deactivated through `UpdateUser`, the same token is 401. */
    #[Test]
    public function aDeactivatedUsersTokenStopsAtOnce(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $token = $this->tokenFor(self::TENANT_A, $subject);
        $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$token]);
        self::assertSame(200, $this->httpStatus(), 'Control: the live token did not read /me.');

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->patch($subject, ['is_active' => false]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$token]);
        self::assertSame([401, 'urn:erp:problem:authentication-failed'], [$this->httpStatus(), $this->json()['type'] ?? null]);
    }

    /** **T-1-5.** Switched off then on: `user.reactivated` (`{}`), no role back, no `role.revoked` from the reactivation. */
    #[Test]
    public function aReactivationRestoresNoRole(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->patch($subject, ['is_active' => false]);
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $this->rows(AuditEvent::ROLE_REVOKED));

        $this->patch($subject, ['is_active' => true]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([true, []], [$this->json()['is_active'] ?? null, $this->json()['roles'] ?? null]);
        $reactivated = $this->rows(AuditEvent::USER_REACTIVATED);
        self::assertCount(1, $reactivated);
        self::assertSame([$subject, []], [$reactivated[0]['subject_id'], $reactivated[0]['metadata']]);
        self::assertCount(1, $this->rows(AuditEvent::ROLE_REVOKED), 'The reactivation wrote a role.revoked though it stripped nothing.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$subject]));

        // A repeat is no transition: no row, no write.
        $this->patch($subject, ['is_active' => true]);
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $this->rows(AuditEvent::USER_REACTIVATED));
    }

    /** **T-1-6** E-15a: nobody switches themselves off, owners included (mutation M-1-8 → red). A self-rename is allowed. */
    #[Test]
    public function nobodySwitchesThemselvesOff(): void
    {
        foreach ([DefaultRoles::OWNER, DefaultRoles::ADMIN] as $role) {
            $self = $this->principal($role);
            $this->principal(DefaultRoles::OWNER); // another owner, so the floor is never the reason
            $this->actAsUser(self::TENANT_A, $self);
            $this->patch($self, ['is_active' => false]);
            $this->assertRefused('app_user.actor_is_subject', $role);
            self::assertTrue($this->isActive($self), $role.': the refused self-deactivation switched the person off.');
            self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$self]));
        }

        $this->patch((string) $this->actorId, ['display_name' => 'My Own Name']);
        self::assertSame([200, 'My Own Name'], [$this->httpStatus(), $this->json()['display_name'] ?? null]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log WHERE event_type LIKE ?', ['role.%']));
    }

    /**
     * **T-1-7** E-15b: an admin cannot switch off an owner (409, nothing changes) — **and a combined patch is refused as a
     * whole** (final RED L-7: the name is unchanged too). An owner can switch off another owner. Mutation M-1-5 → red.
     */
    #[Test]
    public function onlyAnOwnerSwitchesOffAnOwner(): void
    {
        $owner = $this->principal(DefaultRoles::OWNER);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->patch($owner, ['is_active' => false]);
        $this->assertRefused('app_user.marker_needs_owner', 'admin deactivating an owner');
        $this->patch($owner, ['display_name' => 'Renamed Owner', 'is_active' => false]);
        $this->assertRefused('app_user.marker_needs_owner', 'admin renaming and deactivating an owner');
        self::assertTrue($this->isActive($owner));
        self::assertSame('Fixture Actor', $this->displayName($owner), 'A refused combined patch still renamed the person (L-7).');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'user.%' OR event_type LIKE 'role.%'"));

        // A rename alone is not a privilege: an admin may rename an owner (L-11a, owner notice 6).
        $this->patch($owner, ['display_name' => 'Renamed Owner']);
        self::assertSame([200, 'Renamed Owner'], [$this->httpStatus(), $this->json()['display_name'] ?? null]);

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->patch($owner, ['is_active' => false]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertFalse($this->isActive($owner));
    }

    /**
     * **T-1-8** API-20. Over HTTP: with two owners, one switches the other off (the floor counts the person, not a grant).
     * The floor itself: **no HTTP path reaches it in a correct build** — E-15b makes the actor an active marker holder — so it
     * is driven directly, as `LastOwnerFloor`'s docblock records for `RevokeRole`: the only active owner → refused; with a
     * second → not. Mutation M-1-4 (`refuseLastOwner` a no-op) → red here.
     */
    #[Test]
    public function theLastOwnerIsNeverSwitchedOff(): void
    {
        $only = $this->principal(DefaultRoles::OWNER);
        $refusal = $this->inLockedTransaction(static function (EntityManagerInterface $em) use ($only): ?string {
            try {
                UserLiveness::refuseLastOwner($em, $only);
            } catch (ApiProblem $e) {
                return $e->getRule();
            }

            return null;
        });
        self::assertSame('app_user.last_owner', $refusal, 'The floor let the only active owner be switched off.');

        $second = $this->principal(DefaultRoles::OWNER);
        self::assertNull($this->inLockedTransaction(static function (EntityManagerInterface $em) use ($only): ?string {
            UserLiveness::refuseLastOwner($em, $only);

            return null;
        }));

        $this->actAsUser(self::TENANT_A, $second);
        $this->patch($only, ['is_active' => false]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->activeMarkerHolders());
    }

    /**
     * **T-1-9** the lapse (E-15g, D-8): a pending proposal FOR the person → `subject_removed`; one BY the person →
     * `proposer_removed`; a planted one dated two minutes in the FUTURE still lapses, its `decided_at` = its `proposed_at`;
     * a DECIDED one is untouched. One `role.assignment_lapsed` per lapse, subject the proposal's person. Mutations M-1-3
     * (drop `AND status = 'pending'` → the decided-is-final trigger, 500) and M-1-9 (refuse a > 60 s gap) → red.
     */
    #[Test]
    public function aDeactivationLapsesEveryPendingProposalForAndByThePerson(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $other = $this->principal(DefaultRoles::VIEWER);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $role = $this->composedRole(['inventory_item.view']);
        $for = $this->proposal($subject, $role, $proposer, '0 seconds');
        $by = $this->proposal($other, $role, $subject, '0 seconds');
        $future = $this->proposal($subject, $this->composedRole(['warehouse.view']), $proposer, '-2 minutes');
        $decided = $this->proposal($subject, $this->composedRole(['customer.view']), $proposer, '0 seconds', cancelled: true);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->patch($subject, ['is_active' => false]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $state = fn (string $id): array => $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, lapse_reason, decided_at = proposed_at AS at_proposed FROM role_grant_proposal WHERE id = ?::uuid', [$id])[0];
        self::assertSame(['status' => 'lapsed', 'lapse_reason' => 'subject_removed'], array_slice($state($for), 0, 2));
        self::assertSame(['status' => 'lapsed', 'lapse_reason' => 'proposer_removed'], array_slice($state($by), 0, 2));
        self::assertSame(['status' => 'lapsed', 'lapse_reason' => 'subject_removed', 'at_proposed' => true], $state($future),
            'A planted future-dated proposal did not lapse at its own proposed_at (D-8).');
        self::assertSame('cancelled', $state($decided)['status'], 'A decided proposal was touched.');

        $lapses = $this->rows(AuditEvent::ROLE_ASSIGNMENT_LAPSED);
        $seen = array_map(static fn (array $row): array => [$row['subject_id'], $row['metadata'][RoleEventMetadata::PROPOSAL_ID] ?? null,
            $row['metadata'][RoleEventMetadata::LAPSE_REASON] ?? null], $lapses);
        usort($seen, static fn (array $a, array $b): int => strcmp((string) $a[1], (string) $b[1]));
        $want = [[$subject, $for, 'subject_removed'], [$other, $by, 'proposer_removed'], [$subject, $future, 'subject_removed']];
        usort($want, static fn (array $a, array $b): int => strcmp((string) $a[1], (string) $b[1]));
        self::assertSame($want, $seen, 'Not one role.assignment_lapsed per lapsed proposal, keyed as the reconciliation reads it.');
    }

    /**
     * **T-1-11** a rename: `user.updated` `{fields: ["display_name"]}` and NEITHER name in the metadata (mutation M-1-10 →
     * red); the person's own earlier row keeps the label it was written with; a no-change patch (the same name, padded) writes
     * nothing and leaves `updated_at` alone.
     */
    #[Test]
    public function aRenameRecordsTheFieldNameOnlyAndAnUnchangedPatchWritesNothing(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE app_user SET display_name = 'Ada Old' WHERE id = ?::uuid", [$subject]);
        // An earlier row the person wrote themselves: a denial, labelled with the name they then had.
        $this->actAsUser(self::TENANT_A, $subject);
        $this->send('POST', '/roles', ['name' => 'x', 'permission_codes' => []]);
        self::assertSame(403, $this->httpStatus(), 'Control: the earlier row was not written.');

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->patch($subject, ['display_name' => "  Ada New\u{3000}"]);
        self::assertSame([200, 'Ada New'], [$this->httpStatus(), $this->json()['display_name'] ?? null], (string) $this->client()->getResponse()->getContent());

        $renamed = $this->rows(AuditEvent::USER_UPDATED);
        self::assertCount(1, $renamed);
        self::assertSame([$subject, ['fields' => ['display_name']]], [$renamed[0]['subject_id'], $renamed[0]['metadata']]);
        self::assertStringNotContainsString('Ada', json_encode($renamed[0]['metadata'], \JSON_THROW_ON_ERROR), 'A name reached user.updated\'s metadata (D-9).');
        self::assertSame(['Ada Old'], array_column($this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT actor_label FROM audit_log WHERE actor_user_id = ?::uuid AND event_type = 'authz.permission.denied'", [$subject]), 'actor_label'));

        $updatedAt = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS u FROM app_user WHERE id = ?::uuid', [$subject])[0]['u'];
        foreach ([['display_name' => 'Ada New '], [], ['is_active' => true]] as $noChange) {
            $this->patch($subject, $noChange);
            self::assertSame([200, 'Ada New'], [$this->httpStatus(), $this->json()['display_name'] ?? null]);
        }
        self::assertCount(1, $this->rows(AuditEvent::USER_UPDATED), 'A no-change patch wrote a user.updated row.');
        self::assertSame($updatedAt, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS u FROM app_user WHERE id = ?::uuid', [$subject])[0]['u'],
            'A no-change patch moved updated_at.');
    }

    /**
     * **T-1-15** (final RED L-1, deterministic): the person is already a MANAGED, now-stale entity in the entity manager the
     * update runs on — hydrated earlier in the same unit of work, then switched off by owner SQL. The reactivation must read
     * the row UNDER THE LOCK. Without the unconditional `refresh()` the stale entity reads "active", the patch is a no-op and
     * the person stays off — mutation M-1-12b (drop the refresh) → red.
     *
     * **Driven through the service, not over HTTP — measured, not assumed:** the test client resets the container's services
     * between requests (the entity manager's identity map does not survive from one request to the next), so a hydration in
     * an earlier request — `LoginRecorder` on sign-in, which final RED L-1 named — cannot make a later request stale, and an
     * HTTP version of this test stayed green with the refresh removed. Inside one request the hazard is real (a provider,
     * voter or earlier step that loads the person before the lock), and this is that shape. Recorded as deviation D-4.
     */
    #[Test]
    public function aUserLoadedBeforeTheLockIsReloadedUnderIt(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $admin = $this->principal(DefaultRoles::ADMIN);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'DELETE FROM user_role WHERE user_id = ?::uuid', [$subject]);
        $this->actAsUser(self::TENANT_A, $admin, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $hydrated = $this->inLockedTransaction(static fn (EntityManagerInterface $em): ?object => $em->find(\App\Modules\UserManagement\Infrastructure\Entity\AppUser::class, $subject));
        self::assertNotNull($hydrated, 'Control: the person was not hydrated, so this proves nothing.');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$subject]);

        $update = self::getContainer()->get(UserUpdate::class);
        \assert($update instanceof UserUpdate);
        $view = $update->update($subject, ['is_active' => true], '203.0.113.5', ['app_user', $subject]);

        self::assertTrue($view->isActive, 'The reactivation decided on a stale entity.');
        self::assertTrue($this->isActive($subject), 'The reactivation decided on a stale entity: the person is still switched off.');
        self::assertCount(1, $this->rows(AuditEvent::USER_REACTIVATED));
    }

    /**
     * **T-1-16** (R1.3 D-4, first RED M-4): a person switched off BY SQL still holding an owner role. An admin's reactivation
     * would strip that role — a revocation under E-15b — so it is refused, and nothing changes. An owner's succeeds: the
     * leftover is stripped and recorded with `revoke_reason`, and the person comes back with no role. Mutation M-1-13 (no
     * E-15b on the return-side strip) → red.
     */
    #[Test]
    public function onlyAnOwnerCanBringBackAPersonStillHoldingAnOwnerRole(): void
    {
        $leftover = $this->principal(DefaultRoles::OWNER);
        $role = $this->onlyRoleOf($leftover);
        $this->principal(DefaultRoles::OWNER);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$leftover]);

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->patch($leftover, ['is_active' => true]);
        $this->assertRefused('app_user.marker_needs_owner', 'admin reactivating a leftover owner');
        self::assertFalse($this->isActive($leftover));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$leftover]));
        // The same for a repeat switch-off that would strip the leftover (the other return-side path).
        $this->patch($leftover, ['is_active' => false]);
        $this->assertRefused('app_user.marker_needs_owner', 'admin repeating a switch-off on a leftover owner');

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->patch($leftover, ['is_active' => true]);
        self::assertSame([200, true, []], [$this->httpStatus(), $this->json()['is_active'] ?? null, $this->json()['roles'] ?? null],
            (string) $this->client()->getResponse()->getContent());
        self::assertSame([[RoleEventMetadata::ROLE_ID => $role, RoleEventMetadata::REVOKE_REASON => RoleEventMetadata::REVOKE_REASON_SUBJECT_REMOVED]],
            array_column($this->rows(AuditEvent::ROLE_REVOKED), 'metadata'));
        self::assertCount(1, $this->rows(AuditEvent::USER_REACTIVATED));
    }

    /**
     * D-16: a repeat switch-off of a person switched off by SQL who still holds a (non-owner) role strips it — `role.revoked`
     * rows only, with no `user.*` row, because there was no transition.
     */
    #[Test]
    public function aRepeatSwitchOffStripsLeftoversWithoutACauseRow(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$subject]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->patch($subject, ['is_active' => false]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertCount(1, $this->rows(AuditEvent::ROLE_REVOKED));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'user.%'"));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$subject]));
    }

    /** The 404s (non-uuid, absent, another workspace's — byte-equal), the deleted person's 409, the gate's 403. */
    #[Test]
    public function theRefusalsBeforeAnyDecision(): void
    {
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $bodies = [];
        foreach (['not-a-uuid', Uuid::v4()->toRfc4122(), $this->seedPrincipal(self::TENANT_B, [])] as $id) {
            $this->patch($id, ['display_name' => 'Nobody']);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertCount(1, array_unique($bodies), 'The 404s are not byte-equal.');

        $deleted = $this->principal(DefaultRoles::VIEWER);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$deleted]);
        $this->patch($deleted, ['display_name' => 'Ghost']);
        $this->assertRefused('app_user.is_archived', 'a deleted person');

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::VIEWER));
        $this->patch($this->principal(DefaultRoles::VIEWER), ['display_name' => 'Nope']);
        self::assertSame([403, 'app_user.edit'], [$this->httpStatus(), $this->json()['required_permission'] ?? null]);
    }

    /**
     * Member validation, 422, nothing written.
     *
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidBodies')]
    public function aMalformedBodyIs422AndWritesNothing(array $body, string $pointer, string $code): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->patch($subject, $body);
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$pointer, $code], [$this->json()['errors'][0]['pointer'] ?? null, $this->json()['errors'][0]['code'] ?? null]);
        self::assertTrue($this->isActive($subject));
        self::assertSame('Fixture Actor', $this->displayName($subject));
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'name null' => [['display_name' => null], '/display_name', 'required'];
        yield 'name blank' => [['display_name' => " \u{3000}"], '/display_name', 'blank'];
        yield 'name too long' => [['display_name' => str_repeat('n', 101)], '/display_name', 'too_long'];
        yield 'name zero-width' => [['display_name' => "Ad\u{200B}a"], '/display_name', 'invalid_character'];
        yield 'name a number' => [['display_name' => 5], '/display_name', 'not_a_string'];
        yield 'is_active null' => [['is_active' => null], '/is_active', 'required'];
        yield 'is_active a string' => [['is_active' => 'false'], '/is_active', 'not_a_boolean'];
    }

    /** Unaccepted members (`email`, `roles`) are refused, and the email never moves. */
    #[Test]
    public function aBodyCannotChangeTheEmail(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->patch($subject, ['email' => 'someone-else@example.test']);
        self::assertContains($this->httpStatus(), [400, 422], (string) $this->client()->getResponse()->getContent());
        self::assertSame('actor-'.$subject.'@example.test', $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT email FROM app_user WHERE id = ?::uuid', [$subject])[0]['email']);
    }

    /** `RoleAdministrationActor::inactiveRefusal()` has an `app_user.*` arm (no default arm, so C1 must add one). */
    #[Test]
    public function theInactiveActorSentenceHasAnArmForTheUserFamily(): void
    {
        foreach ([UserUpdate::REQUIRED_PERMISSION, 'app_user.delete'] as $code) {
            $refusal = RoleAdministrationActor::inactiveRefusal($code);
            self::assertSame(['user_role.actor_is_inactive', 'Your account is not active, so you can\'t change, switch off, delete or restore anyone\'s account.'],
                [$refusal->getRule(), $refusal->getDetail()], $code);
        }
    }

    /**
     * **T-U14 (U-C1b, spar M-1):** `CreateUser`'s code has its own arm, pinned literally (a `UX` placeholder). Mutation MU-20
     * (drop the arm) → `\UnhandledMatchError` here, and a 500 in `PurchaseOrderRowLockTest::anActorDeactivatedWhileWaitingCannotCreateAUser`.
     */
    #[Test]
    public function theInactiveActorSentenceHasAnArmForCreatingAUser(): void
    {
        $refusal = RoleAdministrationActor::inactiveRefusal('app_user.create');
        self::assertSame(['user_role.actor_is_inactive', 'Your account is not active, so you can\'t add anyone.'],
            [$refusal->getRule(), $refusal->getDetail()]);
    }

    // ---------------------------------------------------------------------

    private function patch(string $userId, array $body): void
    {
        $this->send('PATCH', '/users/'.$userId, $body);
    }

    private function principal(string $role): string
    {
        return $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor($role));
    }

    private function onlyRoleOf(string $userId): string
    {
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$userId]);
        self::assertCount(1, $rows);

        return (string) $rows[0]['r'];
    }

    /** @param list<string> $codes */
    private function composedRole(array $codes): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$role, self::TENANT_A, 'Composed '.substr($role, 0, 8)]);
        foreach ($codes as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $role, $code]);
        }

        return $role;
    }

    private function grant(string $userId, string $roleId): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)',
            [self::TENANT_A, $userId, $roleId]);
    }

    /** A proposal planted by the owner: pending, or cancelled by its proposer an hour later. `$ago` dates it (`'-2 minutes'` = in the future). */
    private function proposal(string $userId, string $roleId, string $proposerId, string $ago, bool $cancelled = false): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, status, proposed_by_user_id,
                                             proposed_at, expires_at, decided_by_user_id, decided_at)
            SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid,
                   (SELECT jsonb_agg(rp.permission_code ORDER BY rp.permission_code) FROM role_permission rp WHERE rp.role_id = ?::uuid),
                   CASE WHEN ? THEN 'cancelled' ELSE 'pending' END, ?::uuid, c.t, c.t + interval '336 hours',
                   CASE WHEN ? THEN ?::uuid END, CASE WHEN ? THEN c.t + interval '1 hour' END
              FROM (SELECT clock_timestamp() - ?::interval AS t) c
            SQL, [$id, self::TENANT_A, $userId, $roleId, $roleId, $cancelled ? 'true' : 'false', $proposerId,
                $cancelled ? 'true' : 'false', $proposerId, $cancelled ? 'true' : 'false', $ago]);

        return $id;
    }

    /** @return list<array{subject_id: string, metadata: array<string, mixed>, ip: ?string}> */
    private function rows(string $event): array
    {
        return array_map(static fn (array $row): array => [
            'subject_id' => (string) $row['subject_id'],
            'metadata' => json_decode((string) $row['metadata'], true, 512, \JSON_THROW_ON_ERROR),
            'ip' => null === $row['ip'] ? null : (string) $row['ip'],
        ], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT subject_id::text AS subject_id, metadata::text AS metadata, host(ip_address) AS ip
              FROM audit_log WHERE event_type = ? ORDER BY occurred_at, metadata->>'role_id'
            SQL, [$event]));
    }

    private function isActive(string $userId): bool
    {
        return (bool) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT is_active FROM app_user WHERE id = ?::uuid', [$userId])[0]['is_active'];
    }

    private function displayName(string $userId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT display_name FROM app_user WHERE id = ?::uuid', [$userId])[0]['display_name'];
    }

    private function activeMarkerHolders(): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT count(DISTINCT u.id) FROM app_user u
              JOIN user_role ur ON ur.user_id = u.id JOIN role r ON r.id = ur.role_id AND r.deleted_at IS NULL
              JOIN role_permission rp ON rp.role_id = r.id
             WHERE u.deleted_at IS NULL AND u.is_active AND rp.permission_code = 'tenant_settings.edit'
            SQL);
    }

    private function inLockedTransaction(callable $work): mixed
    {
        $tenants = self::getContainer()->get(TenantContext::class);
        \assert($tenants instanceof TenantContext);

        return $tenants->run(self::TENANT_A, $work);
    }

    private function assertRefused(string $rule, string $case): void
    {
        self::assertSame([409, $rule, self::COPY[$rule]], [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null],
            $case.': '.$this->client()->getResponse()->getContent());
    }
}
