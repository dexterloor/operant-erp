<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Modules\UserManagement\Domain\RoleCompanions;
use App\Modules\UserManagement\Domain\RoleDefinitionRules;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateRole` — compose a role from permission codes** (roles slice 4, C1; `docs/session13/roles-s4-design.md` §3.1,
 * §4.1, §4.4, §4.5, §4.6, §7, §8.1; owner ruling `session22-owner-ruling.md`; `SEC`/`DATA` rulings
 * `roles-s4-sec-data.md` D-4, D-10.1 as corrected by `roles-s4-sec-data-red.md`).
 *
 * One transaction, in this order — every refusal before any write:
 *  1. `RoleAdministrationLock` — the FIRST statement (`SECURITY_MODEL.md` §5.2.5; every writer of `role`,
 *     `role_permission`, `user_role` and `role_grant_proposal` takes it, so step 4's case-insensitive name rule is
 *     race-free against every API writer).
 *  2. The actor re-checked under the lock with **`role.create`** (`RoleAdministrationActor`, C0's parameter): a missing code
 *     writes the gate's denial row and returns a {@see LockTimeDenial} (403 outside `run()`); an inactive actor → 409
 *     `user_role.actor_is_inactive` with the role-definition sentence (`UX` copy #1), keyed on the code this call already
 *     passes — no sixth argument (2026-10-09; until then the shared grant sentence). The returned set is the **lock-time** set every later
 *     decision reads (D-4: the ceiling is never the gate-time set).
 *  3. Field validation ({@see RoleDefinitionFields}, shared with `UpdateRole` since C2; 422, every field error at once, in body order): `name` required / string / not blank after
 *     trimming Unicode white space / ≤ 100 characters / no control or listed format character; `description` optional
 *     (absent or null → `''`), string, ≤ 500, no control character but tab, newline, carriage return; `permission_codes`
 *     required, a list (may be empty — a drafted role confers nothing), each element a string, no duplicate.
 *  4. **Name** (design §3.1 step 4, `DATA` D-10.1 item 2): no LIVE role whose collision key equals this name's →
 *     409 `role.name_taken`. The key is computed by the DATABASE in this one statement —
 *     `btrim(regexp_replace(lower(normalize(name, NFKC)), '[ _\-]+', ' ', 'g'))` — so `Owner`, `OWNER`, `owner` and
 *     `purchasing manager` / `Purchasing-Manager` / `purchasing_manager` collide. Homoglyphs beyond NFKC are R-6; `lower()`
 *     folds by the cluster's `LC_CTYPE` (measured `en_US.utf8` locally; Neon unproven).
 *  5. Codes read from the `permission` TABLE (the FK's domain), never `PermissionCatalogue` → unknown 422
 *     `unknown_reference` at `/permission_codes/{i}`.
 *  6. **E-15f's ceiling (`SEC` D-4, V2-R-7 ruled):** every code ⊆ the actor's lock-time codes → else 409
 *     `role.exceeds_actor_permissions` — the sentence names no code (`UX` R-1; the screen marks them). No owner exemption is needed: `owner` holds the
 *     whole catalogue.
 *  7. **PC-4 (ADR-008 §2):** the set spans an SoD row → 409 `role.separation_of_duty`, naming each row by its work
 *     (`RoleAssignment::SOD_CLAUSES`) and id, never a code. **No owner exemption** (design §4.5): the exceptions PC-4
 *     records are default roles, not people.
 *  8. **Companions (`RoleCompanions`; owner D-5a, D-5b, D-6 option (1))** → 409 `role.missing_companion_view`.
 *  9. `INSERT INTO role (id, tenant_id, name, description)` — exactly the column grant; then one multi-row
 *     `INSERT INTO role_permission (id, tenant_id, role_id, permission_code)`. Never `ON CONFLICT DO UPDATE` (A4.3 constraint
 *     1). Raw SQL, never the ORM: a `Role` flush writes `is_system`/`created_at`/`updated_at`, which no grant allows.
 *  10. `role.permissions_changed` — subject `('role', id)`, metadata `{role_id, added: <the full set, sorted>, removed: []}`
 *     (E-15f: *"the same event at creation carrying the full set"*), the request's IP, `clock_timestamp()`.
 *  11. The body: `RoleReader::get()` after the writes, in this transaction — `holder_count` 0, `is_system` false,
 *     `margin_exposure` (PC-12 clause 3: a composed role is WARNED, never refused).
 *
 * **Idempotency (§4.2 `Idem` = `unique:role_tenant_name_live`):** the header is demanded (428 without it) and not stored;
 * the natural key is `name`, so a retry meets step 4 and is told `role.name_taken` — the `AssignRole` precedent. A `23505`
 * on `role_tenant_name_live` (only an out-of-band writer, which never takes the lock, can race past step 4) is caught OUTSIDE
 * `run()` and answered the same. A `23514` on `role_name_shape` / `role_description_shape` (unreachable while step 3 refuses
 * the same set) is answered as step 3's 422, never a 500.
 *
 * **The refusal sentences are `UX`'s** (`docs/session13/roles-s4-ux-copy.md`, signed after RED, applied 2026-10-09; each
 * constant names its number). **No sentence names a permission code** (R-1) — not even the caller's own — nor a holder or any
 * other role's codes. Each is pinned as a LITERAL in `RoleCreateTest`, so a wording change is a visible edit.
 */
final readonly class RoleCreation
{
    public const NAME_MAX = RoleDefinitionFields::NAME_MAX;

    public const DESCRIPTION_MAX = RoleDefinitionFields::DESCRIPTION_MAX;

    /** `UX` `roles-s4-ux-copy.md` #2. Shared with `UpdateRole`. */
    public const NAME_TAKEN = RoleDefinitionFields::NAME_TAKEN;

    /**
     * `UX` `roles-s4-ux-copy.md` #3 — `CreateRole` only (`UpdateRole`'s ceiling binds additions alone and has its own, #9).
     * Names no code (R-1): an owner holds the whole catalogue, so "ask an owner" is always possible.
     */
    public const EXCEEDS = 'This role would include permissions you don\'t have yourself, and a role can only be given permissions its creator holds. Leave those out, or ask an owner to create it.';

    /** `UX` `roles-s4-ux-copy.md` #4 (shared with `UpdateRole`, #14): the work, then the row ids; never a code. */
    public const SEPARATION_OF_DUTY = 'One role can\'t cover both sides of a job that has to be split: anyone given it could %s (%s). Leave out the permissions for one side.';

    /** `UX` `roles-s4-ux-copy.md` #5 (shared with `UpdateRole`, #15): one {@see self::COMPANION_CLAUSES} clause per broken row, joined with `; `. */
    public const MISSING_COMPANION = 'Some permissions in this role only work alongside others it doesn\'t have: %s. Add the missing ones, or leave out the ones that need them.';

    /**
     * `UX` `roles-s4-ux-copy.md` §3.1's companion clauses — one per `RoleCompanions::rows()` entry, keyed holding → requires,
     * in work-words (R-1: never a code). `RoleCreateTest::everyCompanionRowHasItsClause` holds this map EQUAL to the table, the
     * `RoleAssignment::SOD_CLAUSES` precedent, so a new companion row cannot ship without its clause.
     */
    public const COMPANION_CLAUSES = [
        'credit_note.issue' => ['invoice.view' => 'crediting invoices for returned goods needs seeing invoices'],
        'credit_note.adjust' => ['invoice.view' => 'crediting invoices without a return needs seeing invoices'],
        'role.create' => ['role.view' => 'creating roles needs seeing roles'],
        'role.edit' => ['role.view' => 'changing roles needs seeing roles'],
        'role.delete' => [
            'role.view' => 'deleting roles needs seeing roles',
            'role.assign' => 'deleting roles needs giving roles to people and taking them away',
        ],
        'role.assign' => ['app_user.view' => 'giving roles to people and taking them away needs seeing people'],
    ];

    /** Step 9 — the column list IS the column INSERT grant (`Version20261007000100`). */
    private const INSERT_ROLE = 'INSERT INTO role (id, tenant_id, name, description) VALUES (?::uuid, ?::uuid, ?, ?)';

    /** Step 9 — one multi-row statement; the column list IS the column INSERT grant. */
    private const INSERT_CODES = <<<'SQL'
        INSERT INTO role_permission (id, tenant_id, role_id, permission_code)
        SELECT x.id, ?::uuid, ?::uuid, x.code
          FROM jsonb_to_recordset(?::jsonb) AS x(id uuid, code text)
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private RoleAuditWriter $audit,
        private RoleAdministrationActor $actor,
        private RoleReader $roles,
    ) {
    }

    /**
     * @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request
     */
    public function create(mixed $name, mixed $description, mixed $permissionCodes, ?string $ipAddress, array $denialSubject): RoleView
    {
        $identity = $this->identities->authenticated();

        try {
            $outcome = $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): RoleView|LockTimeDenial => $this->createInside($em, $identity, $name, $description, $permissionCodes, $ipAddress, $denialSubject),
            );
        } catch (DriverException $e) {
            // Caught OUTSIDE `run()`: the failed statement aborted the transaction and closed the entity manager.
            throw RoleDefinitionFields::translated($e, $name) ?? $e;
        }

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function createInside(EntityManagerInterface $em, Identity $identity, mixed $name, mixed $description, mixed $permissionCodes, ?string $ipAddress, array $denialSubject): RoleView|LockTimeDenial
    {
        // 1.
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        // 2. Before any other read (E-1). The lock-time set every later decision reads.
        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject, 'role.create');
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        // 3. All three keys: at creation an absent member and a null one are the same case.
        $fields = RoleDefinitionFields::validated(['name' => $name, 'description' => $description, 'permission_codes' => $permissionCodes]);
        \assert(isset($fields['name'], $fields['description'], $fields['permission_codes']));
        [$name, $description, $codes] = [$fields['name'], $fields['description'], $fields['permission_codes']];

        $connection = $em->getConnection();

        // 4.
        if (RoleDefinitionFields::nameIsTaken($em, $name, null)) {
            throw RoleDefinitionFields::nameTaken($name);
        }

        // 5.
        $unknown = RoleDefinitionFields::unknownCodeErrors($em, $codes);
        if ([] !== $unknown) {
            throw ApiProblem::validationFailed($unknown);
        }

        $sorted = $codes;
        sort($sorted);

        // 6. E-15f's ceiling on the LOCK-time set (D-4).
        $beyond = RoleDefinitionRules::beyondCeiling($sorted, $actorCodes);
        if ([] !== $beyond) {
            throw ApiProblem::businessRuleViolated('role.exceeds_actor_permissions', self::EXCEEDS);
        }

        // 7. PC-4, no exemption.
        $rows = RoleDefinitionRules::separationOfDutyRowsSpanned($sorted);
        if ([] !== $rows) {
            throw ApiProblem::businessRuleViolated('role.separation_of_duty', self::separationOfDutyMessage($rows));
        }

        // 8. D-5a, D-5b, D-6 (1).
        $missing = RoleCompanions::missingFrom($sorted);
        if ([] !== $missing) {
            throw ApiProblem::businessRuleViolated('role.missing_companion_view', self::missingCompanionMessage($missing));
        }

        // 9.
        $roleId = Uuid::v4()->toRfc4122();
        $connection->executeStatement(self::INSERT_ROLE, [$roleId, $identity->tenantId, $name, $description]);
        if ([] !== $sorted) {
            $connection->executeStatement(self::INSERT_CODES, [
                $identity->tenantId,
                $roleId,
                json_encode(array_map(static fn (string $code): array => ['id' => Uuid::v4()->toRfc4122(), 'code' => $code], $sorted), \JSON_THROW_ON_ERROR),
            ]);
        }

        // 10.
        $this->audit->recordPermissionsChanged(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            roleId: $roleId,
            added: $sorted,
            removed: [],
            ipAddress: $ipAddress,
        );

        // 11. Under the lock, in this transaction (same tenant ⇒ `run()` joins).
        return $this->roles->get($roleId) ?? throw new \LogicException('The role just created is not visible to its own transaction.');
    }

    /**
     * Step 7's sentence — shared with `UpdateRole` (C2), whose PC-4 on the result refuses with the same words.
     *
     * @param list<string> $rows
     */
    public static function separationOfDutyMessage(array $rows): string
    {
        $clauses = array_map(static fn (string $row): string => RoleAssignment::SOD_CLAUSES[$row] ?? throw new \LogicException(
            'SoD row '.$row.' has no sentence in RoleAssignment::SOD_CLAUSES.'), $rows);
        $last = array_pop($clauses);
        $joined = [] === $clauses ? (string) $last : implode(', ', $clauses).' and '.$last;

        return sprintf(self::SEPARATION_OF_DUTY, $joined, implode(', ', $rows));
    }

    /**
     * Step 8's sentence — shared with `UpdateRole` (C2), whose companions run on the result.
     *
     * @param list<array{holding: string, requires: string}> $missing
     */
    public static function missingCompanionMessage(array $missing): string
    {
        return sprintf(self::MISSING_COMPANION, implode('; ', array_map(
            static fn (array $row): string => self::COMPANION_CLAUSES[$row['holding']][$row['requires']] ?? throw new \LogicException(
                'Companion row '.$row['holding'].' -> '.$row['requires'].' has no clause in RoleCreation::COMPANION_CLAUSES.'),
            $missing,
        )));
    }
}
