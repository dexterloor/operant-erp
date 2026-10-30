<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\HeldSpanConflict;
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
 *    call, not one of G-10's conditions). One statement, three cases printed with the proposal id (C2 shipped the
 *    confirmation half): `decision_event_missing` — a decided proposal with no decision event carrying its
 *    `RoleEventMetadata::PROPOSAL_ID` (i); `event_names_proposal_in_another_state` — a decision event carrying a proposal
 *    id whose proposal is absent or not in that decision's status (ii); `decider_differs` — a human decision whose
 *    `decided_by_user_id` is not the actor of its event (iii, a relabelled decider). **C3 widens (i)–(iii) to every
 *    decision** (`role.assignment_rejected`, `…_cancelled`, `…_lapsed` beside `role.assigned`) and adds (iv)
 *    `pending_on_a_held_pair` (report-only). It catches a careless or bypassing writer of proposal status — `erp_app` may
 *    UPDATE `status` — and, like every arm, proves nothing against a forger who also writes the event (G-10).
 *  - **D — `unaccounted_permission` / `phantom_permission` / `pre_event_role`** (G-10 condition (1): *"`role_permission`
 *    against the edit event"*; roles slice 4 C1, design §7 — shipped with the first writer of `role_permission`, a
 *    detection one commit later than its writer being a window). For every NON-system role, the set of `role_permission` rows
 *    must equal the fold, in `occurred_at` order, of that role's `role.permissions_changed` events (`added` − `removed`) —
 *    an order a backward clock step cannot reverse, because the trigger `audit_log_monotonic_occurred_at`
 *    (`Version20261022004000`) raises each in-app row to just after the tenant's newest visible row, within 60 s (arms A/B's
 *    "latest event" relies on the same; `RESIDUAL_GAPS.md` G-54 bounds it). A row
 *    the fold lacks is `unaccounted_permission` (an INSERT nobody audited); a fold code with no row is `phantom_permission`
 *    (an audited code that is gone, or an event that lies). A role created before {@see self::ARM_D_BASELINE} (the arm's
 *    commit date; no event could exist for it) whose rows differ from the fold is reported ONCE as `pre_event_role`, never
 *    per code. **Baseline by date only** (final RED M-1): an SQL-created role after it is not "legacy". `role_permission`
 *    has no `inserted_by` column, so arm A's owner-process marker cannot be printed here (deviation, recorded).
 *    **Interim `system_role_has_permission_rows`** (final2 RED M-2; `DATA` D-10.2): no migration or `src/` path writes a
 *    system role's codes today, so ANY `role_permission` row on an `is_system` role is anomalous with no baseline — reported
 *    once per role. **Arm D-system (W-3, the local demo seed's commit; D-10.2 reduced; ADR-008 D2's "run against the role
 *    seeder"):** a LIVE system role carrying `role.default_key` is compared with `DefaultRoles::codesFor(key)` — the one
 *    source of the built-in roles' codes — and every extra or missing code is `system_role_permission_differs`, one line
 *    each with `permission_code=` and `difference=extra|missing`; a key that is not a `DefaultRoles::names()` member is
 *    `unknown_default_key`, once. The interim finding is KEPT for KEYLESS system roles only (D-10.2 deviation (ii), DATA +
 *    SEC to confirm: `role_default_key_only_on_system_roles` is one-way, so keyless system roles can exist); a soft-deleted
 *    keyed system role is not compared. **Limit:** with one catalogue in code, a keyed role made from an older catalogue
 *    reads as tampered — `seed_catalogue_version` / `catalogue_skew` arrive with the populate commit (deviation (iii)).
 *    Reads under no lock (final RED L-2): a
 *    concurrent `CreateRole` can print a transient finding — re-run before acting on one. Proves nothing against a forger
 *    who also writes the event (G-10).
 *  - **F — `no_active_owner`:** no user that is active, not deleted, and holds the owner marker through a live role — the
 *    predicate of API-20's floor, the SAME query (`LiveGrantReader::activeMarkerHolderCount()`). **Attribution and
 *    recovery triage, not detection** (spar T-4): a lockout of every owner announces itself.
 *  - **H — `held_separation_of_duty` / `masked_separation_of_duty`** (`RESIDUAL_GAPS.md` G-91, BK-11; `SEC`
 *    `sec-held-conflict.md` R2.4). **Report-only: it revokes nothing.** One statement over `user_role ⋈ role (live) ⋈
 *    role_permission`, grouped per person and role in PHP: a person whose live roles together span a SoD row that no
 *    single one spans alone is `held_separation_of_duty` with the rows (PC-13's predicate,
 *    {@see HeldSpanConflict::violatedRows()}); a row hidden from that predicate by a role spanning it alone and not needed
 *    for the union to span it (`owner` given to a violator) is `masked_separation_of_duty`, once per masking role
 *    ({@see HeldSpanConflict::maskedRows()}). The grant routes refuse a NEW combination for such people; arm H lists those
 *    who already have one — from SQL, owner-process seeding, or a change to `SeparationOfDuty::ROWS` or a default role
 *    (`OwedAmendmentTripwireTest` pins a fingerprint of both and requires this arm to be run when it moves). **Not seen:** a
 *    custom role spanning a row alone without every code on it (G-91). Letter E stays reserved for G-10's owner-process arm.
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

    public const FINDING_UNACCOUNTED_PERMISSION = 'unaccounted_permission';

    public const FINDING_PHANTOM_PERMISSION = 'phantom_permission';

    public const FINDING_PRE_EVENT_ROLE = 'pre_event_role';

    public const FINDING_SYSTEM_ROLE_HAS_PERMISSION_ROWS = 'system_role_has_permission_rows';

    /** Arm D-system (W-3): a live keyed system role's codes differ from `DefaultRoles::codesFor(key)`, one line per code. */
    public const FINDING_SYSTEM_ROLE_PERMISSION_DIFFERS = 'system_role_permission_differs';

    /** Arm D-system (W-3): a live system role whose `default_key` is not a `DefaultRoles::names()` member. */
    public const FINDING_UNKNOWN_DEFAULT_KEY = 'unknown_default_key';

    public const FINDING_HELD_SEPARATION_OF_DUTY = 'held_separation_of_duty';

    public const FINDING_MASKED_SEPARATION_OF_DUTY = 'masked_separation_of_duty';

    /**
     * Arm D's date baseline: the day `role.permissions_changed` was minted (roles slice 4 C1, `Version20261007000200`). A
     * role created before it cannot have a creation event. A constant, not `doctrine_migration_versions`, which `erp_app`
     * cannot read.
     */
    public const ARM_D_BASELINE = '2026-10-07T00:00:00+00:00';

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
        SELECT CASE WHEN a.subject_type = 'app_user' THEN a.subject_id::text END AS user_id,
               CASE WHEN a.subject_type = 'role' THEN a.subject_id::text END AS role_id, a.occurred_at::text AS at
          FROM audit_log a
         WHERE a.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid
           AND a.event_type = ANY (?::text[]) AND (a.metadata->>?) IS NULL
         ORDER BY a.occurred_at, a.id
        SQL;

    /**
     * Arm G (C2: the confirmation half; C3: every decision). Binds: the four decision events in `m`'s order (confirmed,
     * rejected, cancelled, lapsed), the role key, the proposal key, the proposal key. Names `role_grant_proposal` and
     * `audit_log` only — never `user_role` (T2: no statement naming grants names proposals); case (iv) is computed in PHP
     * from two separate statements for that reason ({@see self::PENDING_PAIRS}, {@see self::HELD_PAIRS}).
     */
    private const ARM_G = <<<'SQL'
        WITH t AS (
            SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid AS id
        ),
        m (status, event_type, human) AS (
            VALUES ('confirmed', ?::text, true), ('rejected', ?::text, true), ('cancelled', ?::text, true), ('lapsed', ?::text, false)
        ),
        ev AS (
            SELECT a.subject_id, a.actor_user_id, a.occurred_at, a.event_type, a.metadata->>? AS role_id_text, a.metadata->>? AS proposal_id_text
              FROM audit_log a JOIN t ON a.tenant_id = t.id JOIN m ON m.event_type = a.event_type
             WHERE (a.metadata->>?) IS NOT NULL
        ),
        p AS (
            SELECT rgp.id, rgp.user_id, rgp.role_id, rgp.status, rgp.decided_by_user_id, rgp.decided_at
              FROM role_grant_proposal rgp JOIN t ON rgp.tenant_id = t.id
        )
        SELECT 'decision_event_missing' AS kind, p.id::text AS proposal_id, p.user_id::text AS user_id, p.role_id::text AS role_id, p.decided_at::text AS at
          FROM p JOIN m ON m.status = p.status
         WHERE NOT EXISTS (SELECT 1 FROM ev WHERE ev.proposal_id_text = p.id::text AND ev.event_type = m.event_type)
        UNION ALL
        SELECT 'event_names_proposal_in_another_state', ev.proposal_id_text, ev.subject_id::text, ev.role_id_text, ev.occurred_at::text
          FROM ev JOIN m ON m.event_type = ev.event_type
         WHERE NOT EXISTS (SELECT 1 FROM p WHERE p.id::text = ev.proposal_id_text AND p.status = m.status)
        UNION ALL
        SELECT 'decider_differs', p.id::text, p.user_id::text, p.role_id::text, p.decided_at::text
          FROM p JOIN m ON m.status = p.status AND m.human
          JOIN ev ON ev.proposal_id_text = p.id::text AND ev.event_type = m.event_type
         WHERE ev.actor_user_id IS DISTINCT FROM p.decided_by_user_id
         ORDER BY 1, 5, 2
        SQL;

    /** Arm D: every role, system or not, with whether it predates the baseline. Names `role` only. */
    private const ARM_D_ROLES = <<<'SQL'
        SELECT r.id::text AS role_id, r.is_system, r.default_key, r.deleted_at IS NULL AS live,
               r.created_at < ?::timestamptz AS pre_event, r.created_at::text AS at
          FROM role r
         WHERE r.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid
         ORDER BY r.created_at, r.id
        SQL;

    /** Arm D: the stored codes. */
    private const ARM_D_ROWS = <<<'SQL'
        SELECT rp.role_id::text AS role_id, rp.permission_code AS code
          FROM role_permission rp
         WHERE rp.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid
        SQL;

    /** Arm D: the definition events, in the order they are folded. Binds: the event, the two list keys. */
    private const ARM_D_EVENTS = <<<'SQL'
        SELECT a.subject_id::text AS role_id, (a.metadata->?)::text AS added, (a.metadata->?)::text AS removed, a.occurred_at::text AS at
          FROM audit_log a
         WHERE a.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid
           AND a.event_type = ? AND a.subject_type = 'role'
         ORDER BY a.occurred_at, a.id
        SQL;

    /** Arm H: every live grant's codes, per person and role. Names `user_role`, `role` and `role_permission` only. */
    private const ARM_H = <<<'SQL'
        SELECT ur.user_id::text AS user_id, r.id::text AS role_id, rp.permission_code AS code
          FROM user_role ur
          JOIN role r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.deleted_at IS NULL
          JOIN role_permission rp ON rp.tenant_id = r.tenant_id AND rp.role_id = r.id
         WHERE ur.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid
         ORDER BY ur.user_id, r.id, rp.permission_code
        SQL;

    /** Arm G (iv), half one: the pending pairs — names `role_grant_proposal` only (T2). */
    private const PENDING_PAIRS = <<<'SQL'
        SELECT rgp.id::text AS proposal_id, rgp.user_id::text AS user_id, rgp.role_id::text AS role_id, rgp.proposed_at::text AS at
          FROM role_grant_proposal rgp
         WHERE rgp.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid AND rgp.status = 'pending'
         ORDER BY rgp.proposed_at, rgp.id
        SQL;

    /** Arm G (iv), half two: the held pairs — names `user_role` only (T2). */
    private const HELD_PAIRS = <<<'SQL'
        SELECT ur.user_id::text AS user_id, ur.role_id::text AS role_id
          FROM user_role ur
         WHERE ur.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid
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
     * @return array{refused: ?string, findings: list<array{finding: string, user_id: ?string, role_id: ?string, at: ?string, inserted_by?: string, owner_process_row?: bool, proposal_id?: string, case?: string, permission_code?: string, difference?: string, default_key?: string, rows?: list<string>}>}
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
                'user_id' => null === $row['user_id'] ? null : (string) $row['user_id'],
                'role_id' => null === $row['role_id'] ? null : (string) $row['role_id'],
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
                AuditEvent::ROLE_ASSIGNED, AuditEvent::ROLE_ASSIGNMENT_REJECTED, AuditEvent::ROLE_ASSIGNMENT_CANCELLED, AuditEvent::ROLE_ASSIGNMENT_LAPSED,
                RoleEventMetadata::ROLE_ID, RoleEventMetadata::PROPOSAL_ID, RoleEventMetadata::PROPOSAL_ID,
            ]))];

            // Arm G (iv), report-only: a PENDING proposal on a pair the subject already holds (C3). Through the API only an
            // out-of-band writer can make one now that `AssignRole` refuses a pending pair; such a row can be rejected or
            // withdrawn.
            $held = [];
            foreach ($connection->fetchAllAssociative(self::HELD_PAIRS) as $row) {
                $held[$row['user_id'].'|'.$row['role_id']] = true;
            }
            foreach ($connection->fetchAllAssociative(self::PENDING_PAIRS) as $row) {
                if (isset($held[$row['user_id'].'|'.$row['role_id']])) {
                    $findings[] = [
                        'finding' => self::FINDING_PROPOSAL_OUTCOME_UNACCOUNTED,
                        'user_id' => (string) $row['user_id'],
                        'role_id' => (string) $row['role_id'],
                        'at' => (string) $row['at'],
                        'proposal_id' => (string) $row['proposal_id'],
                        'case' => 'pending_on_a_held_pair',
                    ];
                }
            }

            $findings = [...$findings, ...self::armD($em)];
            $findings = [...$findings, ...self::armH($em)];

            if (0 === LiveGrantReader::activeMarkerHolderCount($em)) {
                $findings[] = ['finding' => self::FINDING_NO_ACTIVE_OWNER, 'user_id' => null, 'role_id' => null, 'at' => null];
            }

            return ['refused' => null, 'findings' => $findings];
        });
    }

    /**
     * Arm D and its interim system-role finding (see the class docblock). Three reads, folded in PHP.
     *
     * @return list<array{finding: string, user_id: null, role_id: string, at: ?string, permission_code?: string, difference?: string, default_key?: string}>
     */
    private static function armD(EntityManagerInterface $em): array
    {
        $connection = $em->getConnection();

        $stored = [];
        foreach ($connection->fetchAllAssociative(self::ARM_D_ROWS) as $row) {
            $stored[(string) $row['role_id']][] = (string) $row['code'];
        }

        $folded = [];
        foreach ($connection->fetchAllAssociative(self::ARM_D_EVENTS, [RoleEventMetadata::ADDED, RoleEventMetadata::REMOVED, AuditEvent::ROLE_PERMISSIONS_CHANGED]) as $event) {
            $role = (string) $event['role_id'];
            $set = $folded[$role] ?? [];
            $added = json_decode((string) ($event['added'] ?? 'null'), true);
            $removed = json_decode((string) ($event['removed'] ?? 'null'), true);
            foreach (\is_array($added) ? $added : [] as $code) {
                $set[(string) $code] = true;
            }
            foreach (\is_array($removed) ? $removed : [] as $code) {
                unset($set[(string) $code]);
            }
            $folded[$role] = $set;
        }

        $findings = [];
        $known = [];
        foreach ($connection->fetchAllAssociative(self::ARM_D_ROLES, [self::ARM_D_BASELINE]) as $role) {
            $id = (string) $role['role_id'];
            $known[$id] = true;
            $rows = $stored[$id] ?? [];
            sort($rows);
            if (true === $role['is_system']) {
                if (null !== $role['default_key']) {
                    if (true === $role['live']) {
                        $findings = [...$findings, ...self::armDSystem($id, (string) $role['default_key'], $rows, (string) $role['at'])];
                    }
                    continue;
                }
                if ([] !== $rows) {
                    $findings[] = ['finding' => self::FINDING_SYSTEM_ROLE_HAS_PERMISSION_ROWS, 'user_id' => null, 'role_id' => $id, 'at' => (string) $role['at']];
                }
                continue;
            }
            $fold = array_keys($folded[$id] ?? []);
            sort($fold);
            if ($rows === $fold) {
                continue;
            }
            if (true === $role['pre_event']) {
                $findings[] = ['finding' => self::FINDING_PRE_EVENT_ROLE, 'user_id' => null, 'role_id' => $id, 'at' => (string) $role['at']];
                continue;
            }
            foreach (array_diff($rows, $fold) as $code) {
                $findings[] = ['finding' => self::FINDING_UNACCOUNTED_PERMISSION, 'user_id' => null, 'role_id' => $id, 'at' => (string) $role['at'], 'permission_code' => $code];
            }
            foreach (array_diff($fold, $rows) as $code) {
                $findings[] = ['finding' => self::FINDING_PHANTOM_PERMISSION, 'user_id' => null, 'role_id' => $id, 'at' => (string) $role['at'], 'permission_code' => $code];
            }
        }
        // An event naming a role that does not exist: every code it folds to is phantom.
        foreach ($folded as $id => $set) {
            if (!isset($known[$id])) {
                foreach (array_keys($set) as $code) {
                    $findings[] = ['finding' => self::FINDING_PHANTOM_PERMISSION, 'user_id' => null, 'role_id' => (string) $id, 'at' => null, 'permission_code' => (string) $code];
                }
            }
        }

        return $findings;
    }

    /**
     * Arm D-system (W-3; see the class docblock): one live keyed system role against the catalogue's definition.
     *
     * @param list<string> $rows the role's stored codes, sorted
     *
     * @return list<array{finding: string, user_id: null, role_id: string, at: string, permission_code?: string, difference?: string, default_key?: string}>
     */
    private static function armDSystem(string $roleId, string $key, array $rows, string $at): array
    {
        if (!DefaultRoles::isDefault($key)) {
            return [['finding' => self::FINDING_UNKNOWN_DEFAULT_KEY, 'user_id' => null, 'role_id' => $roleId, 'at' => $at, 'default_key' => $key]];
        }
        $expected = DefaultRoles::codesFor($key);
        sort($expected);

        $findings = [];
        foreach (array_diff($rows, $expected) as $code) {
            $findings[] = ['finding' => self::FINDING_SYSTEM_ROLE_PERMISSION_DIFFERS, 'user_id' => null, 'role_id' => $roleId, 'at' => $at, 'permission_code' => $code, 'difference' => 'extra'];
        }
        foreach (array_diff($expected, $rows) as $code) {
            $findings[] = ['finding' => self::FINDING_SYSTEM_ROLE_PERMISSION_DIFFERS, 'user_id' => null, 'role_id' => $roleId, 'at' => $at, 'permission_code' => $code, 'difference' => 'missing'];
        }

        return $findings;
    }

    /**
     * Arm H (see the class docblock): one read, folded in PHP. Report-only.
     *
     * @return list<array{finding: string, user_id: string, role_id: ?string, at: null, rows: list<string>}>
     */
    private static function armH(EntityManagerInterface $em): array
    {
        $people = [];
        foreach ($em->getConnection()->fetchAllAssociative(self::ARM_H) as $row) {
            $people[(string) $row['user_id']][(string) $row['role_id']][] = (string) $row['code'];
        }

        $findings = [];
        foreach ($people as $userId => $roles) {
            $violated = HeldSpanConflict::violatedRows($roles);
            if ([] !== $violated) {
                $findings[] = ['finding' => self::FINDING_HELD_SEPARATION_OF_DUTY, 'user_id' => (string) $userId, 'role_id' => null, 'at' => null, 'rows' => $violated];
            }
            foreach (HeldSpanConflict::maskedRows($roles) as $roleId => $rows) {
                $findings[] = ['finding' => self::FINDING_MASKED_SEPARATION_OF_DUTY, 'user_id' => (string) $userId, 'role_id' => (string) $roleId, 'at' => null, 'rows' => $rows];
            }
        }

        return $findings;
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
