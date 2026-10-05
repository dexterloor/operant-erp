<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Platform\Tenancy\DatabaseRoles;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`app:roles:reconcile` — compare the grants with the trail, one workspace at a time** (`RESIDUAL_GAPS.md` G-10
 * condition (1); `docs/session12/roles-e15e-q7-options.md` §4; roles slice 2 design §4.6). Arms **B** and **F** ship with
 * `RevokeRole` (C2, where the `DELETE` lands); arms **A** and **C** with `AssignRole` (C3).
 *
 *  - **A — `unaccounted_grant`:** a `user_role` row whose pair's latest grant-or-revoke event is not `role.assigned`
 *    (none at all, or `role.revoked`) — an `INSERT` nobody audited. Printed with `inserted_by` (H1) and
 *    `owner_process_row` = `inserted_by` is not an application role: an owner-process row (seeding, provisioning, a hand
 *    in `psql`) is told apart from an `erp_app` row, but an `erp_app` row can NOT be told apart from a forged one (G-10).
 *  - **B — `unaccounted_removal`:** the trail's latest grant-or-revoke event for a (user, role) pair is `role.assigned`
 *    and no `user_role` row exists — a `DELETE` nobody audited.
 *  - **C — `event_without_role_key`:** a `role.*` row whose `metadata` lacks `RoleEventMetadata::ROLE_ID` — a row arms
 *    A and B cannot pair with any grant, so it would hide a forged or careless write from both (a writer's key drift,
 *    mutation A6, is what this arm sees). **Its domain is derived (roles slice 3, C1; design §10, RED R2-L5):** every
 *    `AuditEvent::ALL` member with the `role.` prefix, minus {@see self::ARM_C_EXCLUDED} (each entry with its reason;
 *    empty), bound as ONE array parameter into one literal statement — so E-15e's proposal events, and E-15f's future
 *    `role.permissions_changed`, are inside it with no edit here, and leaving one out is a recorded decision.
 *  - **G — `proposal_outcome_unaccounted`** (roles slice 3; design §10 arm G, recommended and taken — `SEC` + `BE-LEAD`'s
 *    call, not one of G-10's conditions). **C2 ships its confirmation half**, one statement, three cases printed with the
 *    proposal id: `decision_event_missing` — a `confirmed` proposal with no `role.assigned` carrying its
 *    `RoleEventMetadata::PROPOSAL_ID` (i); `event_names_unconfirmed_proposal` — a `role.assigned` carrying a proposal id
 *    whose proposal is absent or not `confirmed` (ii); `decider_differs` — a confirmed proposal whose
 *    `decided_by_user_id` is not the actor of its `role.assigned` (iii, a relabelled decider). It catches a careless or
 *    bypassing writer of proposal status — `erp_app` may UPDATE `status` — and, like every arm, proves nothing against a
 *    forger who also writes the event (G-10). C3 adds the rejected / cancelled / lapsed half of (i) and (iv).
 *  - **F — `no_active_owner`:** no user that is active, not deleted, and holds the owner marker through a live role — the
 *    predicate of API-20's floor, the SAME query (`LiveGrantReader::activeMarkerHolderCount()`). **Attribution and
 *    recovery triage, not detection** (spar T-4): a lockout of every owner announces itself.
 *
 * **What an empty result means, and the command prints it on every run:** *"an empty result means no careless writer,
 * never no forgery; this runs only when invoked."* SQL running as `erp_app` can forge the matching `role.*` row as easily
 * as the grant (G-10 re-ruled, *not prevented, not reliably detected*).
 *
 * **Controls, run first, every run** (A-6 (iii)) — a failed control prints no findings:
 *  1. `session_user` is an application role and is `NOBYPASSRLS` — never the owner, which on Neon bypasses RLS (ADR-002
 *     C-1) and would read every workspace;
 *  2. the transaction's tenant setting equals `--tenant`;
 *  3. the workspace has at least one `app_user` — a workspace with no user is a wrong id, not a clean result.
 * Every table reference also carries an explicit `tenant_id` predicate, so the arms do not rest on RLS alone.
 *
 * SQL runs through `TenantContext::run()` on the entity manager's (app) connection; the `audit_log` read is a
 * cross-module SQL read, the residual A-2 records (`PasswordChangeThrottle`'s precedent). No lock is taken: this reads.
 */
final readonly class RoleReconciliation
{
    public const FINDING_UNACCOUNTED_REMOVAL = 'unaccounted_removal';

    public const FINDING_NO_ACTIVE_OWNER = 'no_active_owner';

    public const FINDING_UNACCOUNTED_GRANT = 'unaccounted_grant';

    public const FINDING_EVENT_WITHOUT_ROLE_KEY = 'event_without_role_key';

    public const FINDING_PROPOSAL_OUTCOME_UNACCOUNTED = 'proposal_outcome_unaccounted';

    public const CADENCE = 'An empty result means no careless writer, never no forgery; this runs only when invoked.';

    /** Arms A and B share this prefix: the trail's latest grant-or-revoke event per (user, role) pair. Binds: key, 2 events. */
    private const LATEST_EVENT_PER_PAIR = <<<'SQL'
        WITH t AS (
            SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid AS id
        ),
        ev AS (
            SELECT a.id, a.subject_id AS user_id, a.metadata->>? AS role_id_text, a.event_type, a.occurred_at
              FROM audit_log a JOIN t ON a.tenant_id = t.id
             WHERE a.event_type IN (?, ?) AND a.subject_type = 'app_user'
        ),
        latest AS (
            SELECT DISTINCT ON (user_id, role_id_text) *
              FROM ev
             WHERE role_id_text IS NOT NULL
             ORDER BY user_id, role_id_text, occurred_at DESC, id DESC
        )

        SQL;

    private const ARM_B = self::LATEST_EVENT_PER_PAIR.<<<'SQL'
        SELECT l.user_id::text AS user_id, l.role_id_text AS role_id, l.occurred_at::text AS at
          FROM latest l
         WHERE l.event_type = ?
           AND NOT EXISTS (SELECT 1 FROM user_role ur JOIN t ON ur.tenant_id = t.id
                            WHERE ur.user_id = l.user_id AND ur.role_id::text = l.role_id_text)
         ORDER BY l.occurred_at, l.user_id, l.role_id_text
        SQL;

    /** Arm A. Extra bind after the prefix's: `role.assigned`. */
    private const ARM_A = self::LATEST_EVENT_PER_PAIR.<<<'SQL'
        SELECT ur.user_id::text AS user_id, ur.role_id::text AS role_id, ur.created_at::text AS at, ur.inserted_by
          FROM user_role ur JOIN t ON ur.tenant_id = t.id
          LEFT JOIN latest l ON l.user_id = ur.user_id AND l.role_id_text = ur.role_id::text
         WHERE l.event_type IS DISTINCT FROM ?
         ORDER BY ur.created_at, ur.user_id, ur.role_id
        SQL;

    /**
     * Arm C. Binds: the event domain as a text array ({@see self::armCEvents()}), then the key. `->>` and `IS NULL`, never
     * the `?` operator, which DBAL would read as a placeholder.
     */
    private const ARM_C = <<<'SQL'
        SELECT a.subject_id::text AS user_id, a.occurred_at::text AS at
          FROM audit_log a
         WHERE a.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid
           AND a.event_type = ANY (?::text[]) AND (a.metadata->>?) IS NULL
         ORDER BY a.occurred_at, a.id
        SQL;

    /**
     * Arm G, the confirmation half (C2). Binds: the role key, the proposal key, `role.assigned`, the proposal key. Names
     * `role_grant_proposal` and `audit_log` only — never `user_role` (T2: no statement naming grants names proposals).
     */
    private const ARM_G = <<<'SQL'
        WITH t AS (
            SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid AS id
        ),
        ev AS (
            SELECT a.subject_id, a.actor_user_id, a.occurred_at, a.metadata->>? AS role_id_text, a.metadata->>? AS proposal_id_text
              FROM audit_log a JOIN t ON a.tenant_id = t.id
             WHERE a.event_type = ? AND (a.metadata->>?) IS NOT NULL
        ),
        p AS (
            SELECT rgp.id, rgp.user_id, rgp.role_id, rgp.status, rgp.decided_by_user_id, rgp.decided_at
              FROM role_grant_proposal rgp JOIN t ON rgp.tenant_id = t.id
        )
        SELECT 'decision_event_missing' AS kind, p.id::text AS proposal_id, p.user_id::text AS user_id, p.role_id::text AS role_id, p.decided_at::text AS at
          FROM p
         WHERE p.status = 'confirmed' AND NOT EXISTS (SELECT 1 FROM ev WHERE ev.proposal_id_text = p.id::text)
        UNION ALL
        SELECT 'event_names_unconfirmed_proposal', ev.proposal_id_text, ev.subject_id::text, ev.role_id_text, ev.occurred_at::text
          FROM ev
         WHERE NOT EXISTS (SELECT 1 FROM p WHERE p.id::text = ev.proposal_id_text AND p.status = 'confirmed')
        UNION ALL
        SELECT 'decider_differs', p.id::text, p.user_id::text, p.role_id::text, p.decided_at::text
          FROM p JOIN ev ON ev.proposal_id_text = p.id::text
         WHERE p.status = 'confirmed' AND ev.actor_user_id IS DISTINCT FROM p.decided_by_user_id
         ORDER BY 1, 5, 2
        SQL;

    /**
     * `role.*` events arm C deliberately does not read, each with its reason. **Empty**: every `role.*` event carries
     * `RoleEventMetadata::ROLE_ID` (`RoleAuditWriter` writes it first on every row).
     *
     * @var array<string, string>
     */
    public const ARM_C_EXCLUDED = [];

    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * @return array{refused: ?string, findings: list<array{finding: string, user_id: ?string, role_id: ?string, at: ?string, inserted_by?: string, owner_process_row?: bool, proposal_id?: string, case?: string}>}
     */
    public function run(string $tenantId): array
    {
        if (!Uuid::isValid($tenantId)) {
            return ['refused' => 'the tenant is not a uuid', 'findings' => []];
        }
        $tenantId = Uuid::fromString($tenantId)->toRfc4122();

        return $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId): array {
            $connection = $em->getConnection();
            $principal = $connection->fetchAssociative(
                'SELECT session_user AS name, (SELECT rolbypassrls FROM pg_roles WHERE rolname = session_user) AS bypass',
            );
            \assert(\is_array($principal));
            $refused = self::controlsRefusal(
                (string) $principal['name'],
                true === $principal['bypass'],
                $connection->fetchOne("SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid = ?::uuid", [$tenantId]),
                (int) $connection->fetchOne('SELECT count(*) FROM app_user WHERE tenant_id = ?::uuid', [$tenantId]),
            );
            if (null !== $refused) {
                return ['refused' => $refused, 'findings' => []];
            }

            $findings = array_map(static fn (array $row): array => [
                'finding' => self::FINDING_UNACCOUNTED_GRANT,
                'user_id' => (string) $row['user_id'],
                'role_id' => (string) $row['role_id'],
                'at' => (string) $row['at'],
                'inserted_by' => (string) $row['inserted_by'],
                'owner_process_row' => !\in_array((string) $row['inserted_by'], DatabaseRoles::APPLICATION_ROLES, true),
            ], $connection->fetchAllAssociative(self::ARM_A, [
                RoleEventMetadata::ROLE_ID, AuditEvent::ROLE_ASSIGNED, AuditEvent::ROLE_REVOKED, AuditEvent::ROLE_ASSIGNED,
            ]));

            $findings = [...$findings, ...array_map(static fn (array $row): array => [
                'finding' => self::FINDING_UNACCOUNTED_REMOVAL,
                'user_id' => (string) $row['user_id'],
                'role_id' => (string) $row['role_id'],
                'at' => (string) $row['at'],
            ], $connection->fetchAllAssociative(self::ARM_B, [
                RoleEventMetadata::ROLE_ID, AuditEvent::ROLE_ASSIGNED, AuditEvent::ROLE_REVOKED, AuditEvent::ROLE_ASSIGNED,
            ]))];

            $findings = [...$findings, ...array_map(static fn (array $row): array => [
                'finding' => self::FINDING_EVENT_WITHOUT_ROLE_KEY,
                'user_id' => (string) $row['user_id'],
                'role_id' => null,
                'at' => (string) $row['at'],
            ], $connection->fetchAllAssociative(self::ARM_C, [
                '{'.implode(',', self::armCEvents()).'}', RoleEventMetadata::ROLE_ID,
            ]))];

            $findings = [...$findings, ...array_map(static fn (array $row): array => [
                'finding' => self::FINDING_PROPOSAL_OUTCOME_UNACCOUNTED,
                'user_id' => (string) $row['user_id'],
                'role_id' => null === $row['role_id'] ? null : (string) $row['role_id'],
                'at' => null === $row['at'] ? null : (string) $row['at'],
                'proposal_id' => (string) $row['proposal_id'],
                'case' => (string) $row['kind'],
            ], $connection->fetchAllAssociative(self::ARM_G, [
                RoleEventMetadata::ROLE_ID, RoleEventMetadata::PROPOSAL_ID, AuditEvent::ROLE_ASSIGNED, RoleEventMetadata::PROPOSAL_ID,
            ]))];

            if (0 === LiveGrantReader::activeMarkerHolderCount($em)) {
                $findings[] = ['finding' => self::FINDING_NO_ACTIVE_OWNER, 'user_id' => null, 'role_id' => null, 'at' => null];
            }

            return ['refused' => null, 'findings' => $findings];
        });
    }

    /**
     * Arm C's domain: `AuditEvent::ALL`'s `role.` members, minus {@see self::ARM_C_EXCLUDED}. Event names are
     * `[a-z_.]+` (the catalogue's own shape), so the array literal needs no quoting.
     *
     * @return list<string>
     */
    public static function armCEvents(): array
    {
        return array_values(array_filter(
            AuditEvent::ALL,
            static fn (string $event): bool => str_starts_with($event, 'role.') && !\array_key_exists($event, self::ARM_C_EXCLUDED),
        ));
    }

    /**
     * The three controls as a pure decision, so each refusal is testable without a second principal. Null = all pass.
     */
    public static function controlsRefusal(string $sessionUser, bool $bypassesRls, mixed $settingMatches, int $users): ?string
    {
        if (!\in_array($sessionUser, DatabaseRoles::APPLICATION_ROLES, true) || $bypassesRls) {
            return \sprintf('the session principal "%s" is not an RLS-bound application role', $sessionUser);
        }
        if (true !== $settingMatches) {
            return 'the transaction\'s tenant setting is not the tenant asked for';
        }
        if ($users <= 0) {
            return 'the workspace has no user, so the tenant id is wrong';
        }

        return null;
    }
}
