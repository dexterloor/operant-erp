<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\RoleReconciliation;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Infrastructure\Console\ReconcileRolesCommand;
use App\Tests\Support\SqlRecorder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * **`app:roles:reconcile`, arms A, B, C and F** (`RESIDUAL_GAPS.md` G-10 condition (1): *"a test that plants each finding; a
 * positive control; the tenant predicate and principal controls"*; roles slice 2 — B and F in C2, A and C in C3). Findings
 * are planted as the owner connection, because the point of the command is to see what the API did not do.
 *
 * **Since arm A (C3), a principal seeded by the rig is itself a finding** — an owner-process `user_role` row with no
 * `role.assigned` behind it is exactly what arm A reports. So the controls seed their principals through
 * {@see self::accountedPrincipal()}, which also plants the `role.assigned` row an audited grant would have left.
 *
 * In-kernel (`CommandTester`), so `bin/console`'s credential guard is not what is tested; what is asserted instead is
 * that the command's run issues nothing on the schema owner's connection (`test.sql_recorder.owner`).
 */
final class ReconcileRolesCommandTest extends ApiTestCase
{
    /**
     * The rig's own actor (`ApiTestCase::setUp()` → `actAs()`) is an owner-seeded grant with no trail, which arm A reports
     * by design. Its `role.assigned` is planted here, so each test's findings are only the ones it plants.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->plantEvent('role.assigned', (string) $this->actorId, $this->onlyRoleOf((string) $this->actorId), '2026-09-29T07:00:00Z');
    }

    /** Positive control: a staffed workspace with an active owner and a trail the table agrees with → 0 and the sentence. */
    #[Test]
    public function aCleanWorkspaceReportsNothingAndSaysWhatThatMeans(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        [$code, $out] = $this->reconcile(self::TENANT_A);

        self::assertSame(0, $code, $out);
        self::assertSame(RoleReconciliation::CADENCE, trim($out));
        self::assertStringContainsString('never no forgery', $out);
    }

    /**
     * **Arm B, planted:** a `role.assigned` row whose grant is gone (an unaudited `DELETE`) is reported. Controls in the
     * same test: a pair whose latest event is `role.revoked` is not; and a grant revoked THROUGH THE API after its
     * `role.assigned` is not either — which is what turns a writer's metadata-key drift (mutation R6) red here.
     */
    #[Test]
    public function armBReportsAGrantTheTrailSaysIsHeldAndTheTableSaysIsNot(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        [$gone, $goneRole] = [Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()];
        $this->plantEvent('role.assigned', $gone, $goneRole, '2026-09-29T10:00:00Z');
        [$undone, $undoneRole] = [Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()];
        $this->plantEvent('role.assigned', $undone, $undoneRole, '2026-09-29T10:00:00Z');
        $this->plantEvent('role.revoked', $undone, $undoneRole, '2026-09-29T11:00:00Z');

        // The real writer's half: assigned (planted, as an owner-process grant would record), then revoked by the API.
        $subject = $this->seedPrincipal(self::TENANT_A, ['role.view']);
        $role = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$subject])[0]['r'];
        $this->plantEvent('role.assigned', $subject, $role, '2026-09-29T09:00:00Z');
        $this->actAsUser(self::TENANT_A, $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('DELETE', '/users/'.$subject.'/roles/'.$role);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertSame(ReconcileRolesCommand::EXIT_FINDINGS, $code, $out);
        $lines = array_values(array_filter(explode("\n", trim($out)), static fn (string $l): bool => str_starts_with($l, RoleReconciliation::FINDING_UNACCOUNTED_REMOVAL)));
        self::assertCount(1, $lines, $out);
        self::assertStringStartsWith(RoleReconciliation::FINDING_UNACCOUNTED_REMOVAL.','.$gone.','.$goneRole.',', $lines[0]);
        self::assertStringEndsWith(RoleReconciliation::CADENCE, trim($out));
    }

    /** **Arm F, planted:** the only owner is inactive → `no_active_owner`, labelled triage. Control: an active owner → none. */
    #[Test]
    public function armFReportsAWorkspaceWithNoActiveOwner(): void
    {
        $owner = $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        [$code] = $this->reconcile(self::TENANT_A);
        self::assertSame(0, $code, 'Control: a workspace with an active owner was reported.');

        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$owner]);
        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertSame(ReconcileRolesCommand::EXIT_FINDINGS, $code, $out);
        self::assertStringContainsString(RoleReconciliation::FINDING_NO_ACTIVE_OWNER.',,, (attribution and recovery triage, not detection)', $out);
    }

    /**
     * **Arm A, planted (C3, needs H1):** a `user_role` row whose pair has no `role.assigned` behind it is reported with its
     * `inserted_by` and whether that is an owner process. Three plants, three controls, one run:
     *  - an owner-process grant with no event → reported, `owner_process_row=yes`, `inserted_by` = the owner's login;
     *  - a grant whose latest event is `role.revoked` → reported;
     *  - a row inserted AS `erp_app` with no event — a forged grant — → reported, `owner_process_row=no`: H1 cannot tell it
     *    from an API grant, which is its stated limit (G-10), and the missing event is what gives it away;
     *  - controls: an accounted grant (event planted), and a grant made THROUGH THE API (its own `role.assigned`), are not.
     */
    #[Test]
    public function armAReportsAGrantTheTrailDoesNotAccountFor(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $ownerLogin = (string) $this->owner()->fetchOne('SELECT session_user');

        $silent = $this->seedPrincipal(self::TENANT_A, ['role.view']);
        $silentRole = $this->onlyRoleOf($silent);

        $undone = $this->seedPrincipal(self::TENANT_A, ['role.view']);
        $undoneRole = $this->onlyRoleOf($undone);
        $this->plantEvent('role.assigned', $undone, $undoneRole, '2026-09-29T10:00:00Z');
        $this->plantEvent('role.revoked', $undone, $undoneRole, '2026-09-29T11:00:00Z');

        $forged = $this->seedPrincipal(self::TENANT_A, []);
        $forgedRole = $this->roleWith('role.view');
        $tenants = self::getContainer()->get(\App\Platform\Tenancy\TenantContext::class);
        \assert($tenants instanceof \App\Platform\Tenancy\TenantContext);
        $tenants->run(self::TENANT_A, static fn (\Doctrine\ORM\EntityManagerInterface $em): int => (int) $em->getConnection()->executeStatement(
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)',
            [self::TENANT_A, $forged, $forgedRole]));

        // The API's half: a grant through AssignRole writes its own role.assigned, so arm A must be silent about it.
        $viaApi = $this->seedPrincipal(self::TENANT_A, []);
        $apiRole = $this->roleWith('role.view');
        $this->actAsUser(self::TENANT_A, $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('POST', '/users/'.$viaApi.'/roles', ['role_id' => $apiRole], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertSame(ReconcileRolesCommand::EXIT_FINDINGS, $code, $out);
        $lines = array_values(array_filter(explode("\n", trim($out)), static fn (string $l): bool => str_starts_with($l, RoleReconciliation::FINDING_UNACCOUNTED_GRANT)));
        $byUser = [];
        foreach ($lines as $line) {
            $fields = explode(',', $line);
            $byUser[$fields[1]] = [$fields[2], $fields[4], $fields[5]];
        }
        ksort($byUser);
        $expected = [
            $silent => [$silentRole, $ownerLogin, 'owner_process_row=yes'],
            $undone => [$undoneRole, $ownerLogin, 'owner_process_row=yes'],
            $forged => [$forgedRole, 'erp_app', 'owner_process_row=no'],
        ];
        ksort($expected);
        self::assertSame($expected, $byUser, $out);
        self::assertNotSame('erp_app', $ownerLogin, 'Control: the owner\'s login is the application\'s, so owner_process_row proves nothing.');
    }

    /**
     * **Arm C, planted (C3):** a `role.*` row whose `metadata` lacks `RoleEventMetadata::ROLE_ID` is reported — arms A and
     * B cannot pair it with any grant. Controls in the same run: the rows the API writes for a grant and a revoke carry
     * the key, so neither is reported (this is what turns a writer's key drift, mutation A6, red here).
     */
    #[Test]
    public function armCReportsARoleEventWithoutTheRoleKey(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $keyless = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
            VALUES (gen_random_uuid(), ?::uuid, '2026-09-29T10:00:00Z', 'role.assigned', NULL, 'Owner process', 'app_user', ?::uuid, NULL, jsonb_build_object('role', ?::text))
            SQL, [self::TENANT_A, $keyless, Uuid::v4()->toRfc4122()]);

        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $role = $this->roleWith('role.view');
        $this->actAsUser(self::TENANT_A, $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $role], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('DELETE', '/users/'.$subject.'/roles/'.$role);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertSame(ReconcileRolesCommand::EXIT_FINDINGS, $code, $out);
        $lines = array_values(array_filter(explode("\n", trim($out)), static fn (string $l): bool => str_starts_with($l, RoleReconciliation::FINDING_EVENT_WITHOUT_ROLE_KEY)));
        self::assertCount(1, $lines, $out);
        self::assertStringStartsWith(RoleReconciliation::FINDING_EVENT_WITHOUT_ROLE_KEY.','.$keyless.',,', $lines[0]);
        self::assertSame([], array_values(array_filter(explode("\n", trim($out)), static fn (string $l): bool => !str_starts_with($l, RoleReconciliation::FINDING_EVENT_WITHOUT_ROLE_KEY)
            && RoleReconciliation::CADENCE !== $l)), 'The API grant and revoke produced a finding: '.$out);
    }

    /** The controls refuse with exit 2 and print no finding: a tenant with no user (a wrong id) and a non-uuid. */
    #[Test]
    public function aFailedControlPrintsNoFindings(): void
    {
        $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER)); // tenant A would report F
        foreach (['a tenant with no user' => '33333333-3333-4333-8333-333333333333', 'not a uuid' => 'tenant-a', 'nothing' => ''] as $case => $tenant) {
            [$code, $out] = $this->reconcile($tenant);
            self::assertSame(ReconcileRolesCommand::EXIT_REFUSED, $code, $case.': '.$out);
            self::assertStringNotContainsString(RoleReconciliation::FINDING_NO_ACTIVE_OWNER, $out, $case);
            self::assertStringStartsWith('Refused', trim($out), $case);
        }
    }

    /**
     * The principal and setting controls, as a pure decision (a second principal cannot be driven in-kernel), plus the
     * live fact: the command's connection is `erp_app` and the owner's connection issues nothing during a run.
     */
    #[Test]
    public function thePrincipalAndSettingControlsRefuseAndTheRunNeverTouchesTheOwner(): void
    {
        self::assertNull(RoleReconciliation::controlsRefusal('erp_app', false, true, 1));
        self::assertNotNull(RoleReconciliation::controlsRefusal('postgres', false, true, 1), 'the owner');
        self::assertNotNull(RoleReconciliation::controlsRefusal('erp_app', true, true, 1), 'a BYPASSRLS application login');
        self::assertNotNull(RoleReconciliation::controlsRefusal('erp_app', false, false, 1), 'a mismatched setting');
        self::assertNotNull(RoleReconciliation::controlsRefusal('erp_app', false, null, 1), 'an empty setting');
        self::assertNotNull(RoleReconciliation::controlsRefusal('erp_app', false, true, 0), 'no user');

        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $owner = self::getContainer()->get('test.sql_recorder.owner');
        $app = self::getContainer()->get('test.sql_recorder.app');
        \assert($owner instanceof SqlRecorder && $app instanceof SqlRecorder);
        $owner->start();
        $app->start();
        [$code] = $this->reconcile(self::TENANT_A);
        $owner->stop();
        $app->stop();
        self::assertSame(0, $code);
        self::assertSame([], $owner->statements(), 'The reconciliation issued statements on the schema owner\'s connection.');
        self::assertNotSame([], $app->statements(), 'Control: the app recorder saw nothing, so the owner\'s silence proves nothing.');
    }

    /** @return array{0: int, 1: string} */
    private function reconcile(string $tenant): array
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $tester = new CommandTester($application->find('app:roles:reconcile'));
        $code = $tester->execute('' === $tenant ? [] : ['--tenant' => $tenant]);

        return [$code, $tester->getDisplay()];
    }

    /**
     * A principal whose grant the trail accounts for: seeded as the owner (as every rig principal is), plus the
     * `role.assigned` row an audited grant leaves — so arm A has nothing to say about it.
     *
     * @param list<string> $codes
     */
    private function accountedPrincipal(array $codes): string
    {
        $user = $this->seedPrincipal(self::TENANT_A, $codes);
        $this->plantEvent('role.assigned', $user, $this->onlyRoleOf($user), '2026-09-29T08:00:00Z');

        return $user;
    }

    private function onlyRoleOf(string $user): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$user])[0]['r'];
    }

    /** A composed role holding `$code` alone. */
    private function roleWith(string $code): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$role, self::TENANT_A, 'Reconcile '.substr($role, 0, 8)]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
            [self::TENANT_A, $role, $code]);

        return $role;
    }

    private function plantEvent(string $event, string $user, string $role, string $at): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
            VALUES (gen_random_uuid(), ?::uuid, ?::timestamptz, ?, NULL, 'Owner process', 'app_user', ?::uuid, NULL, jsonb_build_object('role_id', ?::text))
            SQL, [self::TENANT_A, $at, $event, $user, $role]);
    }
}
