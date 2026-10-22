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
 * **`app:roles:reconcile`, arms A, B, C, F and G (G's confirmation half since roles slice 3 C2)** (`RESIDUAL_GAPS.md` G-10 condition (1): *"a test that plants each finding; a
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

    /**
     * **T-1-10 (G4-C1): a switch-off through `UpdateUser` leaves the reconciliation nothing to report.** The person holds an
     * accounted grant and has one pending proposal FOR them and one BY them; an admin switches them off. Arm B finds no
     * `unaccounted_removal` (the strip wrote one `role.revoked` per grant, keyed on `role_id`); arm G finds no proposal
     * outcome without its decision event (each lapse wrote `role.assignment_lapsed` with `proposal_id`); arm F finds an
     * active owner (another owner remains). The `role.assigned` rows are planted an hour and more in the PAST (design §5.7
     * T-1-10, risk 4: a backward clock step could otherwise order the strip's row before them). Mutation M-1-2 (strip without
     * `role.revoked`) → arm B reports the person, red.
     */
    #[Test]
    public function aSwitchOffThroughTheApiLeavesNothingToReport(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $subject = $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $other = $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $admin = $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $role = $this->roleWith('inventory_item.view');
        $this->plantProposal('pending', $subject, $role, $admin, null);
        $this->plantProposal('pending', $other, $role, $subject, null);

        $this->actAsUser(self::TENANT_A, $admin);
        $this->send('PATCH', '/users/'.$subject, ['is_active' => false]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM role_grant_proposal WHERE status = 'lapsed'"),
            'Control: the two proposals did not lapse, so arm G below proves nothing.');

        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertSame(0, $code, $out);
        self::assertSame(RoleReconciliation::CADENCE, trim($out));
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

    /**
     * **Arm C's domain is derived (roles slice 3 C1; design §10, RED R2-L5):** a keyless `role.assignment_proposed` row is
     * reported exactly as a keyless `role.assigned` is — the arm reads every `AuditEvent::ALL` member with the `role.`
     * prefix (minus a reasoned, empty exclusion list), never a hand list. Controls in the same run: the rows an API proposal
     * writes carry the key, so they are not reported; and the derived domain names both proposal events. Mutation: arm C
     * back to the two-event `IN (?, ?)` list → the planted row unreported, red.
     */
    #[Test]
    public function armCReadsEveryRoleEventIncludingTheProposalEvents(): void
    {
        self::assertContains('role.assignment_proposed', RoleReconciliation::armCEvents());
        self::assertContains('role.assignment_lapsed', RoleReconciliation::armCEvents());
        self::assertSame([], RoleReconciliation::ARM_C_EXCLUDED, 'An arm C exclusion was added; each needs its reason reviewed here.');

        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $keyless = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
            VALUES (gen_random_uuid(), ?::uuid, '2026-09-29T10:00:00Z', 'role.assignment_proposed', NULL, 'Owner process', 'app_user', ?::uuid, NULL, jsonb_build_object('proposal_id', ?::text))
            SQL, [self::TENANT_A, $keyless, Uuid::v4()->toRfc4122()]);

        $finance = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name, is_system) VALUES (?::uuid, ?::uuid, ?, true)',
            [$finance, self::TENANT_A, 'finance '.substr($finance, 0, 8)]);
        foreach (DefaultRoles::codesFor(DefaultRoles::FINANCE) as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $finance, $code]);
        }
        $this->actAsUser(self::TENANT_A, $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('POST', '/users/'.$this->seedPrincipal(self::TENANT_A, []).'/role-grant-proposals', ['role_id' => $finance], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertSame(ReconcileRolesCommand::EXIT_FINDINGS, $code, $out);
        $lines = array_values(array_filter(explode("\n", trim($out)), static fn (string $l): bool => str_starts_with($l, RoleReconciliation::FINDING_EVENT_WITHOUT_ROLE_KEY)));
        self::assertCount(1, $lines, 'Not exactly the planted keyless proposal event: '.$out);
        self::assertStringStartsWith(RoleReconciliation::FINDING_EVENT_WITHOUT_ROLE_KEY.','.$keyless.',,', $lines[0]);
    }

    /**
     * **Arm C over a ROLE-subject event (roles slice 4 C1; design §7, RED F-13):** a `role.permissions_changed` without
     * `role_id` is reported with the role in the ROLE column, never mislabelled as a user.
     */
    #[Test]
    public function armCReportsARoleSubjectEventWithoutTheRoleKeyUnderTheRole(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
            VALUES (gen_random_uuid(), ?::uuid, '2026-10-07T10:00:00Z', 'role.permissions_changed', NULL, 'Owner process', 'role', ?::uuid, NULL,
                    jsonb_build_object('added', '[]'::jsonb, 'removed', '[]'::jsonb))
            SQL, [self::TENANT_A, $role]);

        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertSame(ReconcileRolesCommand::EXIT_FINDINGS, $code, $out);
        $lines = array_values(array_filter(explode("\n", trim($out)), static fn (string $l): bool => str_starts_with($l, RoleReconciliation::FINDING_EVENT_WITHOUT_ROLE_KEY)));
        self::assertCount(1, $lines, $out);
        self::assertStringStartsWith(RoleReconciliation::FINDING_EVENT_WITHOUT_ROLE_KEY.',,'.$role.',', $lines[0]);
    }

    /**
     * **Arm D (roles slice 4 C1; G-10 condition (1); design §7), planted.** Four roles, each in its own state:
     *  - a role whose rows match the fold of its events (one added two codes, a later one removed one) — NOT reported;
     *  - a role with a row no event added (`unaccounted_permission`, naming the code);
     *  - a role whose event added a code with no row (`phantom_permission`);
     *  - a role created before the baseline whose rows differ from the (empty) fold — reported ONCE as `pre_event_role`;
     *  - a SYSTEM role with any code row — the interim `system_role_has_permission_rows` (no seeder writes one today).
     * **Control in the same run:** a role composed THROUGH THE API (`CreateRole`) is not reported — which is what turns the
     * writer's `added` drift (mutation: `added` empty on Create) red here.
     */
    #[Test]
    public function armDReportsRoleCodesTheTrailDoesNotAccountFor(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $folded = $this->roleWith('role.view');
        $this->plantDefinitionEvent($folded, ['role.view', 'invoice.view'], [], '2026-10-07T08:00:00Z');
        $this->plantDefinitionEvent($folded, [], ['invoice.view'], '2026-10-07T09:00:00Z');
        $unaccounted = $this->roleWith('role.view');
        $this->plantDefinitionEvent($unaccounted, [], [], '2026-10-07T08:00:00Z');
        $phantom = $this->roleWith('role.view');
        $this->plantDefinitionEvent($phantom, ['role.view', 'invoice.view'], [], '2026-10-07T08:00:00Z');
        $legacy = $this->roleWith('role.view');
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE role SET created_at = '2026-09-01T00:00:00Z' WHERE id = ?::uuid", [$legacy]);
        $system = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name, is_system) VALUES (?::uuid, ?::uuid, ?, true)',
            [$system, self::TENANT_A, 'viewer '.substr($system, 0, 8)]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, 'role.view')",
            [self::TENANT_A, $system]);

        $this->actAsUser(self::TENANT_A, $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('POST', '/roles', ['name' => 'Composed through the API', 'permission_codes' => ['role.view', 'invoice.view']], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $viaApi = (string) $this->json()['id'];

        [$code, $out] = $this->reconcile(self::TENANT_A, [$unaccounted, $legacy, $viaApi]);
        self::assertSame(ReconcileRolesCommand::EXIT_FINDINGS, $code, $out);
        $armD = [RoleReconciliation::FINDING_UNACCOUNTED_PERMISSION, RoleReconciliation::FINDING_PHANTOM_PERMISSION,
            RoleReconciliation::FINDING_PRE_EVENT_ROLE, RoleReconciliation::FINDING_SYSTEM_ROLE_HAS_PERMISSION_ROWS];
        $lines = array_values(array_filter(explode("\n", trim($out)), static fn (string $l): bool => \in_array(explode(',', $l)[0], $armD, true)));
        $seen = array_map(static fn (string $l): string => preg_replace('/^([a-z_]+),,([0-9a-f-]+),[^,]*(,permission_code=[a-z_.]+)?$/', '$1 $2$3', $l), $lines);
        sort($seen);
        $expected = [
            RoleReconciliation::FINDING_UNACCOUNTED_PERMISSION.' '.$unaccounted.',permission_code=role.view',
            RoleReconciliation::FINDING_PHANTOM_PERMISSION.' '.$phantom.',permission_code=invoice.view',
            RoleReconciliation::FINDING_PRE_EVENT_ROLE.' '.$legacy,
            RoleReconciliation::FINDING_SYSTEM_ROLE_HAS_PERMISSION_ROWS.' '.$system,
        ];
        sort($expected);
        self::assertSame($expected, $seen, 'Arm D did not report exactly the four planted states: '.$out);
    }

    /**
     * **Arm G, the confirmation half (roles slice 3 C2; design §10):** three plants, each reported once with its case and
     * the proposal's id — (i) a `confirmed` proposal with no `role.assigned` naming it (`decision_event_missing`); (ii) a
     * `role.assigned` naming a proposal that is still pending (`event_names_proposal_in_another_state`); (iii) a confirmed
     * proposal whose event's actor is not its recorded decider (`decider_differs`). **Control in the same run:** a proposal
     * confirmed THROUGH THE API — whose `role.assigned` carries `proposal_id` and whose actor is the decider — is not
     * reported, which is what turns a writer's key drift (`PROPOSAL_ID` missing from the confirmation's metadata) red here.
     */
    #[Test]
    public function armGReportsAConfirmationTheTrailDoesNotAccountFor(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $finance = $this->roleWith('invoice.pay');
        $proposer = $this->accountedPrincipal(['role.assign', 'role.view', 'invoice.pay']);
        $decider = $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));

        $missing = $this->plantProposal('confirmed', $this->seedPrincipal(self::TENANT_A, []), $finance, $proposer, $decider);
        $pending = $this->plantProposal('pending', $this->seedPrincipal(self::TENANT_A, []), $finance, $proposer, null);
        $this->plantProposalEvent($pending, $decider, $finance);
        $relabelled = $this->plantProposal('confirmed', $this->seedPrincipal(self::TENANT_A, []), $finance, $proposer, $decider);
        $this->plantProposalEvent($relabelled, $proposer, $finance);

        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $viaApi = $this->plantProposal('pending', $subject, $finance, $proposer, null);
        $this->actAsUser(self::TENANT_A, $decider);
        $this->send('POST', '/role-grant-proposals/'.$viaApi.'/confirmation');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertSame(ReconcileRolesCommand::EXIT_FINDINGS, $code, $out);
        $lines = array_values(array_filter(explode("\n", trim($out)), static fn (string $l): bool => str_starts_with($l, RoleReconciliation::FINDING_PROPOSAL_OUTCOME_UNACCOUNTED)));
        self::assertCount(3, $lines, 'Not exactly the three planted proposal findings: '.$out);
        $cases = [];
        foreach ($lines as $line) {
            self::assertSame(1, preg_match('/,proposal_id=([0-9a-f-]{36}),case=([a-z_]+)$/', $line, $m), $line);
            $cases[$m[2]] = $m[1];
        }
        ksort($cases);
        self::assertSame([
            'decider_differs' => $relabelled,
            'decision_event_missing' => $missing,
            'event_names_proposal_in_another_state' => $pending,
        ], $cases);
        self::assertStringNotContainsString($viaApi, $out, 'The API confirmation was reported: its role.assigned does not account for it.');
    }

    /**
     * **Arm G, every decision (roles slice 3 C3):** (i) a `rejected` and a `lapsed` proposal with no event naming them; (ii)
     * a `role.assignment_cancelled` naming a proposal that is still pending; (iii) a `rejected` proposal whose event's actor
     * is not its decider; (iv) a PENDING proposal on a pair the subject already holds (report-only). **Controls in the same
     * run:** a proposal rejected and one withdrawn THROUGH THE API are not reported.
     */
    #[Test]
    public function armGReportsEveryDecisionTheTrailDoesNotAccountFor(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $role = $this->roleWith('invoice.pay');
        $proposer = $this->accountedPrincipal(['role.assign', 'role.view', 'invoice.pay']);
        $owner = $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));

        $rejectedSilently = $this->plantProposal('rejected', $this->seedPrincipal(self::TENANT_A, []), $role, $proposer, $owner);
        $lapsedSilently = $this->plantProposal('lapsed', $this->seedPrincipal(self::TENANT_A, []), $role, $proposer, null);
        $stillPending = $this->plantProposal('pending', $this->seedPrincipal(self::TENANT_A, []), $role, $proposer, null);
        $this->plantProposalEvent($stillPending, $proposer, $role, 'role.assignment_cancelled');
        $relabelled = $this->plantProposal('rejected', $this->seedPrincipal(self::TENANT_A, []), $role, $proposer, $owner);
        $this->plantProposalEvent($relabelled, $proposer, $role, 'role.assignment_rejected');
        $holder = $this->accountedPrincipal(['role.view']);
        $onHeld = $this->plantProposal('pending', $holder, $this->onlyRoleOf($holder), $proposer, null);

        $viaApiRejected = $this->plantProposal('pending', $this->seedPrincipal(self::TENANT_A, []), $role, $proposer, null);
        $this->actAsUser(self::TENANT_A, $owner);
        $this->send('POST', '/role-grant-proposals/'.$viaApiRejected.'/rejection', []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $viaApiWithdrawn = $this->plantProposal('pending', $this->seedPrincipal(self::TENANT_A, []), $role, $proposer, null);
        $this->actAsUser(self::TENANT_A, $proposer);
        $this->send('POST', '/role-grant-proposals/'.$viaApiWithdrawn.'/cancellation');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertSame(ReconcileRolesCommand::EXIT_FINDINGS, $code, $out);
        $found = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (1 === preg_match('/^'.RoleReconciliation::FINDING_PROPOSAL_OUTCOME_UNACCOUNTED.',.*,proposal_id=([0-9a-f-]{36}),case=([a-z_]+)$/', $line, $m)) {
                $found[] = $m[2].' '.$m[1];
            }
        }
        sort($found);
        $expected = [
            'decider_differs '.$relabelled,
            'decision_event_missing '.$lapsedSilently,
            'decision_event_missing '.$rejectedSilently,
            'event_names_proposal_in_another_state '.$stillPending,
            'pending_on_a_held_pair '.$onHeld,
        ];
        sort($expected);
        self::assertSame($expected, $found, $out);
        self::assertStringNotContainsString($viaApiRejected, $out, 'The API rejection was reported.');
        self::assertStringNotContainsString($viaApiWithdrawn, $out, 'The API withdrawal was reported.');
    }

    /**
     * **T-R — a backward clock step must not make the command report a removal that was audited** (`Version20261022004000`;
     * `docs/session13/audit-order-clock-design.md` §1.4 item 2, R1.3). A role is granted through the API; the owner plants a
     * second `role.assigned` for the pair 5 s in the database's future — what the grant's row looks like to the revoke after a
     * backward step — keyed as `RoleAuditWriter` keys it, so arm C is quiet; the grant is revoked through the API. The revoke's
     * row is raised to just after the planted one, so the pair's latest event is the revoke and arm B is silent. Red without
     * the trigger (M1): the latest event read "assigned" with no grant held — a false `unaccounted_removal`.
     */
    #[Test]
    public function aClockStepBetweenAGrantAndItsRevokeIsNotReportedAsAnUnauditedRemoval(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $role = $this->roleWith('role.view');
        $this->actAsUser(self::TENANT_A, $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $role], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
            VALUES (gen_random_uuid(), ?::uuid, clock_timestamp() + interval '5 seconds', 'role.assigned', NULL, 'Ahead', 'app_user', ?::uuid, NULL,
                    jsonb_build_object('role_id', ?::text))
            SQL, [self::TENANT_A, $subject, $role]);

        $this->send('DELETE', '/users/'.$subject.'/roles/'.$role);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertStringNotContainsString($subject, $out, 'A grant revoked through the API, after a clock step, was reported: '.$out);
        self::assertSame(0, $code, $out);
    }

    /**
     * **T-R′ — the same for arm D's fold.** A role composed through the API with two codes; the owner plants a
     * `role.permissions_changed` adding one of them 5 s in the database's future; the code is then removed through the API.
     * The removal's row is raised to just after the planted one, so the fold (in `occurred_at` order) ends without the code
     * and arm D is silent. Red without the trigger (M1): the fold ran remove-then-add and reported a false `phantom_permission`.
     */
    #[Test]
    public function aClockStepBetweenTwoDefinitionChangesIsNotReportedAsAPhantomPermission(): void
    {
        $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->actAsUser(self::TENANT_A, $this->accountedPrincipal(DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('POST', '/roles', ['name' => 'Stepped role', 'permission_codes' => ['role.view', 'invoice.view']], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $role = (string) $this->json()['id'];

        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
            VALUES (gen_random_uuid(), ?::uuid, clock_timestamp() + interval '5 seconds', 'role.permissions_changed', NULL, 'Ahead', 'role', ?::uuid, NULL,
                    jsonb_build_object('role_id', ?::text, 'added', '["invoice.view"]'::jsonb, 'removed', '[]'::jsonb))
            SQL, [self::TENANT_A, $role, $role]);

        $this->send('PATCH', '/roles/'.$role, ['permission_codes' => ['role.view']]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        [$code, $out] = $this->reconcile(self::TENANT_A);
        self::assertStringNotContainsString($role, $out, 'A code removed through the API, after a clock step, was reported: '.$out);
        self::assertSame(0, $code, $out);
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
        $this->accountRoleDefinitions([]); // arm D's fixture accounting is an owner write: done before the recorders start
        $owner->start();
        $app->start();
        [$code] = $this->reconcile(self::TENANT_A, null);
        $owner->stop();
        $app->stop();
        self::assertSame(0, $code);
        self::assertSame([], $owner->statements(), 'The reconciliation issued statements on the schema owner\'s connection.');
        self::assertNotSame([], $app->statements(), 'Control: the app recorder saw nothing, so the owner\'s silence proves nothing.');
    }

    /**
     * **Since arm D (roles slice 4 C1), every composed role the rig seeds is itself a finding** — an owner-written
     * `role_permission` row with no `role.permissions_changed` behind it is exactly what arm D reports. So before each run
     * the composed roles that carry no definition event get the event `CreateRole` would have written (their current codes
     * as `added`), except those in `$leaveUnaccounted` — which is how arm D's own tests plant a finding.
     *
     * @param list<string>|null $leaveUnaccounted null = account nothing (the caller already did, outside a recorder)
     *
     * @return array{0: int, 1: string}
     */
    private function reconcile(string $tenant, ?array $leaveUnaccounted = []): array
    {
        if (self::TENANT_A === $tenant && null !== $leaveUnaccounted) {
            $this->accountRoleDefinitions($leaveUnaccounted);
        }

        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $tester = new CommandTester($application->find('app:roles:reconcile'));
        $code = $tester->execute('' === $tenant ? [] : ['--tenant' => $tenant]);

        return [$code, $tester->getDisplay()];
    }

    /** @param list<string> $leaveUnaccounted */
    private function accountRoleDefinitions(array $leaveUnaccounted): void
    {
        {
            $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
                SELECT gen_random_uuid(), r.tenant_id, r.created_at, 'role.permissions_changed', NULL, 'Owner process', 'role', r.id, NULL,
                       jsonb_build_object('role_id', r.id::text,
                                          'added', COALESCE((SELECT jsonb_agg(rp.permission_code ORDER BY rp.permission_code) FROM role_permission rp WHERE rp.role_id = r.id), '[]'::jsonb),
                                          'removed', '[]'::jsonb)
                  FROM role r
                 WHERE r.tenant_id = ?::uuid AND NOT r.is_system AND NOT (r.id::text = ANY (?::text[]))
                   AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.event_type = 'role.permissions_changed' AND a.subject_id = r.id)
                SQL, [self::TENANT_A, '{'.implode(',', $leaveUnaccounted).'}']);
        }
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

    /** A `role.permissions_changed` for `$role`, as `CreateRole` writes it, at `$at`. */
    private function plantDefinitionEvent(string $role, array $added, array $removed, string $at): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
            VALUES (gen_random_uuid(), ?::uuid, ?::timestamptz, 'role.permissions_changed', NULL, 'Owner process', 'role', ?::uuid, NULL,
                    jsonb_build_object('role_id', ?::text, 'added', ?::jsonb, 'removed', ?::jsonb))
            SQL, [self::TENANT_A, $at, $role, $role, json_encode($added), json_encode($removed)]);
    }

    /** A proposal planted by owner INSERT: `pending`, or `confirmed` by `$decider` (every shape CHECK satisfied). */
    private function plantProposal(string $status, string $user, string $role, string $proposer, ?string $decider): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, status, proposed_by_user_id,
                                             proposed_at, expires_at, decided_by_user_id, decided_at, lapse_reason)
            SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid, '["invoice.pay"]'::jsonb, ?, ?::uuid, c.t, c.t + interval '336 hours',
                   ?::uuid, CASE WHEN ? = 'pending' THEN NULL ELSE c.t + interval '1 hour' END, CASE WHEN ? = 'lapsed' THEN 'expired' END
              FROM (SELECT clock_timestamp() - interval '2 hours' AS t) c
            SQL, [$id, self::TENANT_A, $user, $role, $status, $proposer, $decider, $status, $status]);

        return $id;
    }

    /** A decision event (default a confirmation's `role.assigned`) for `$proposal`, by `$actor`, keyed as `RoleAuditWriter` keys it. */
    private function plantProposalEvent(string $proposal, string $actor, string $role, string $event = 'role.assigned'): void
    {
        $user = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT user_id::text AS u FROM role_grant_proposal WHERE id = ?::uuid', [$proposal])[0]['u'];
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
            VALUES (gen_random_uuid(), ?::uuid, clock_timestamp(), ?, ?::uuid, 'Planted', 'app_user', ?::uuid, NULL,
                    jsonb_build_object('role_id', ?::text, 'proposal_id', ?::text))
            SQL, [self::TENANT_A, $event, $actor, $user, $role, $proposal]);
    }

    private function plantEvent(string $event, string $user, string $role, string $at): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
            VALUES (gen_random_uuid(), ?::uuid, ?::timestamptz, ?, NULL, 'Owner process', 'app_user', ?::uuid, NULL, jsonb_build_object('role_id', ?::text))
            SQL, [self::TENANT_A, $at, $event, $user, $role]);
    }
}
