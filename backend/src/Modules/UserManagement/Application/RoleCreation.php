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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
 *     `user_role.actor_is_inactive` with the SHARED sentence (*"… you can't change anyone's roles"*) — a per-operation
 *     sentence (design F-2) would need a sixth argument `RoleAdministrationActorCallerTest` reads as the code; owed to `UX`. The returned set is the **lock-time** set every later
 *     decision reads (D-4: the ceiling is never the gate-time set).
 *  3. Field validation (422, every field error at once, in body order): `name` required / string / not blank after
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
 *     `role.exceeds_actor_permissions`, naming the codes the actor lacks. No owner exemption is needed: `owner` holds the
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
 * **The refusal sentences are `BE-LEAD` PLACEHOLDERS** in `UX`'s register (work-words; design §9) — `UX` owes the final
 * copy; each constant below is marked. No sentence names a holder or any other role's codes.
 */
final readonly class RoleCreation
{
    public const NAME_MAX = 100;

    public const DESCRIPTION_MAX = 500;

    /** PLACEHOLDER (`UX` §9 item 8): the caller's own name typed back, never "reserved". */
    public const NAME_TAKEN = 'A role called "%s" already exists in this workspace (names are compared ignoring capitals, spaces, dashes and underscores). Choose another name.';

    /** PLACEHOLDER: names only codes the actor lacks — its own set, which it already knows. */
    public const EXCEEDS = 'You can only put permissions you hold yourself into a role, and you don\'t hold %s.';

    /** PLACEHOLDER, built like `RoleAssignment::separationOfDutyMessage()`: the work, then the row ids; never a code. */
    public const SEPARATION_OF_DUTY = 'A role can\'t hold both sides of a separation of duty: anyone given it could %s (%s). Remove the permissions for one side.';

    /** PLACEHOLDER: one clause per missing companion (D-5a, D-5b, D-6). */
    public const MISSING_COMPANION = 'This role needs more permissions to be usable safely: %s. Add them, or remove the permission that needs them.';

    /** The Unicode `White_Space` set the name is trimmed of (the CHECK's `btrim` covers ASCII space only). */
    private const EDGE_SPACE = '/^[\s\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+|[\s\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+$/u';

    /** `role_name_shape`'s two character classes (`DATA` D-10.1), as one PCRE class. Mirrors the CHECK exactly. */
    private const NAME_FORBIDDEN = '/[\x{0}-\x{1F}\x{7F}-\x{9F}\x{AD}\x{61C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}]/u';

    /** `role_description_shape`: controls but tab, newline, carriage return. */
    private const DESCRIPTION_FORBIDDEN = '/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}-\x{9F}]/u';

    private const NAME_UNIQUE = 'role_tenant_name_live';

    /** Step 4. One statement; the key is the database's (one engine, so it cannot drift between PHP and SQL). */
    private const LIVE_NAME_COLLISION = <<<'SQL'
        SELECT 1
          FROM role
         WHERE deleted_at IS NULL
           AND btrim(regexp_replace(lower(normalize(name, NFKC)), '[ _\-]+', ' ', 'g'))
             = btrim(regexp_replace(lower(normalize(?::text, NFKC)), '[ _\-]+', ' ', 'g'))
         LIMIT 1
        SQL;

    /** Step 5: the codes the FK admits, read from the table in this transaction. */
    private const KNOWN_CODES = 'SELECT code FROM permission WHERE code = ANY (?::text[])';

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
        } catch (UniqueConstraintViolationException $e) {
            // Caught OUTSIDE `run()`: the failed statement aborted the transaction and closed the entity manager.
            if (str_contains($e->getMessage(), self::NAME_UNIQUE)) {
                throw ApiProblem::businessRuleViolated('role.name_taken', sprintf(self::NAME_TAKEN, \is_string($name) ? self::trimmed($name) : ''));
            }

            throw $e;
        } catch (DriverException $e) {
            if ('23514' === $e->getSQLState() && str_contains($e->getMessage(), 'role_name_shape')) {
                throw ApiProblem::validationFailed([self::error('/name', 'invalid_character', 'A role name can\'t contain invisible or control characters.')]);
            }
            if ('23514' === $e->getSQLState() && str_contains($e->getMessage(), 'role_description_shape')) {
                throw ApiProblem::validationFailed([self::error('/description', 'invalid_character', 'A description can\'t contain control characters other than tabs and line breaks.')]);
            }

            throw $e;
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

        // 3.
        [$name, $description, $codes] = self::validated($name, $description, $permissionCodes);

        $connection = $em->getConnection();

        // 4.
        if (false !== $connection->fetchOne(self::LIVE_NAME_COLLISION, [$name])) {
            throw ApiProblem::businessRuleViolated('role.name_taken', sprintf(self::NAME_TAKEN, $name));
        }

        // 5.
        // Only code-shaped strings reach the database (a NUL byte would be a 22021, not a refusal); the rest are unknown.
        $shaped = array_values(array_filter($codes, static fn (string $code): bool => 1 === preg_match('/^[a-z_]+\.[a-z_]+$/', $code)));
        $known = [] === $shaped ? [] : $connection->fetchFirstColumn(self::KNOWN_CODES, ['{'.implode(',', $shaped).'}']);
        $unknown = [];
        foreach ($codes as $i => $code) {
            if (!\in_array($code, $known, true)) {
                $unknown[] = self::error('/permission_codes/'.$i, 'unknown_reference', 'There is no permission with this code.');
            }
        }
        if ([] !== $unknown) {
            throw ApiProblem::validationFailed($unknown);
        }

        $sorted = $codes;
        sort($sorted);

        // 6. E-15f's ceiling on the LOCK-time set (D-4).
        $beyond = RoleDefinitionRules::beyondCeiling($sorted, $actorCodes);
        if ([] !== $beyond) {
            throw ApiProblem::businessRuleViolated('role.exceeds_actor_permissions', sprintf(self::EXCEEDS, implode(', ', $beyond)));
        }

        // 7. PC-4, no exemption.
        $rows = RoleDefinitionRules::separationOfDutyRowsSpanned($sorted);
        if ([] !== $rows) {
            throw ApiProblem::businessRuleViolated('role.separation_of_duty', self::separationOfDutyMessage($rows));
        }

        // 8. D-5a, D-5b, D-6 (1).
        $missing = RoleCompanions::missingFrom($sorted);
        if ([] !== $missing) {
            throw ApiProblem::businessRuleViolated('role.missing_companion_view', sprintf(self::MISSING_COMPANION, implode('; ', array_map(
                static fn (array $row): string => sprintf('%s needs %s', $row['holding'], $row['requires']),
                $missing,
            ))));
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
     * Step 3, every field error at once in body order.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private static function validated(mixed $name, mixed $description, mixed $permissionCodes): array
    {
        $errors = [];

        if (null === $name) {
            $errors[] = self::error('/name', 'required', 'This field is required.');
        } elseif (!\is_string($name)) {
            $errors[] = self::error('/name', 'not_a_string', 'This field must be a string.');
        } else {
            $name = self::trimmed($name);
            if ('' === $name) {
                $errors[] = self::error('/name', 'blank', 'This field may not be blank.');
            } elseif (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
                $errors[] = self::error('/name', 'too_long', sprintf('A role name can be at most %d characters.', self::NAME_MAX));
            } elseif (1 === preg_match(self::NAME_FORBIDDEN, $name)) {
                $errors[] = self::error('/name', 'invalid_character', 'A role name can\'t contain invisible or control characters.');
            }
        }

        if (null === $description) {
            $description = '';
        } elseif (!\is_string($description)) {
            $errors[] = self::error('/description', 'not_a_string', 'This field must be a string.');
        } elseif (mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX) {
            $errors[] = self::error('/description', 'too_long', sprintf('A description can be at most %d characters.', self::DESCRIPTION_MAX));
        } elseif (1 === preg_match(self::DESCRIPTION_FORBIDDEN, $description)) {
            $errors[] = self::error('/description', 'invalid_character', 'A description can\'t contain control characters other than tabs and line breaks.');
        }

        $codes = [];
        if (null === $permissionCodes) {
            $errors[] = self::error('/permission_codes', 'required', 'This field is required. Send an empty list for a role with no permissions yet.');
        } elseif (!\is_array($permissionCodes) || !array_is_list($permissionCodes)) {
            $errors[] = self::error('/permission_codes', 'not_a_list', 'This field must be a list of permission codes.');
        } else {
            foreach ($permissionCodes as $i => $code) {
                if (!\is_string($code)) {
                    $errors[] = self::error('/permission_codes/'.$i, 'not_a_string', 'Each permission code must be a string.');
                } elseif (\in_array($code, $codes, true)) {
                    $errors[] = self::error('/permission_codes/'.$i, 'duplicate_value', 'This permission code is already in the list.');
                } else {
                    $codes[] = $code;
                }
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
        \assert(\is_string($name) && \is_string($description));

        return [$name, $description, $codes];
    }

    /** @param list<string> $rows */
    private static function separationOfDutyMessage(array $rows): string
    {
        $clauses = array_map(static fn (string $row): string => RoleAssignment::SOD_CLAUSES[$row] ?? throw new \LogicException(
            'SoD row '.$row.' has no sentence in RoleAssignment::SOD_CLAUSES.'), $rows);
        $last = array_pop($clauses);
        $joined = [] === $clauses ? (string) $last : implode(', ', $clauses).' and '.$last;

        return sprintf(self::SEPARATION_OF_DUTY, $joined, implode(', ', $rows));
    }

    private static function trimmed(string $value): string
    {
        return (string) preg_replace(self::EDGE_SPACE, '', $value);
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $pointer, string $code, string $message): array
    {
        return ['pointer' => $pointer, 'code' => $code, 'message' => $message];
    }
}
