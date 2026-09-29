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
 * **`app:roles:reconcile`, arms B and F** (`RESIDUAL_GAPS.md` G-10 condition (1): *"a test that plants each finding; a
 * positive control; the tenant predicate and principal controls"*; roles slice 2, C2). Findings are planted as the
 * owner connection, because the point of the command is to see what the API did not do.
 *
 * In-kernel (`CommandTester`), so `bin/console`'s credential guard is not what is tested; what is asserted instead is
 * that the command's run issues nothing on the schema owner's connection (`test.sql_recorder.owner`).
 */
final class ReconcileRolesCommandTest extends ApiTestCase
{
    /** Positive control: a staffed workspace with an active owner and a trail the table agrees with → 0 and the sentence. */
    #[Test]
    public function aCleanWorkspaceReportsNothingAndSaysWhatThatMeans(): void
    {
        $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
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
        $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        [$gone, $goneRole] = [Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()];
        $this->plantEvent('role.assigned', $gone, $goneRole, '2026-09-29T10:00:00Z');
        [$undone, $undoneRole] = [Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()];
        $this->plantEvent('role.assigned', $undone, $undoneRole, '2026-09-29T10:00:00Z');
        $this->plantEvent('role.revoked', $undone, $undoneRole, '2026-09-29T11:00:00Z');

        // The real writer's half: assigned (planted, as an owner-process grant would record), then revoked by the API.
        $subject = $this->seedPrincipal(self::TENANT_A, ['role.view']);
        $role = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$subject])[0]['r'];
        $this->plantEvent('role.assigned', $subject, $role, '2026-09-29T09:00:00Z');
        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN)));
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
        $owner = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        [$code] = $this->reconcile(self::TENANT_A);
        self::assertSame(0, $code, 'Control: a workspace with an active owner was reported.');

        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$owner]);
        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertSame(ReconcileRolesCommand::EXIT_FINDINGS, $code, $out);
        self::assertStringContainsString(RoleReconciliation::FINDING_NO_ACTIVE_OWNER.',,, (attribution and recovery triage, not detection)', $out);
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

        $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
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

    private function plantEvent(string $event, string $user, string $role, string $at): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
            VALUES (gen_random_uuid(), ?::uuid, ?::timestamptz, ?, NULL, 'Owner process', 'app_user', ?::uuid, NULL, jsonb_build_object('role_id', ?::text))
            SQL, [self::TENANT_A, $at, $event, $user, $role]);
    }
}
