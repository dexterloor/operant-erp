<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The per-tenant role-administration lock — the first statement of every role-administration write**
 * (`SECURITY_MODEL.md` §5.2.5; `ARCHITECTURE.md` W-5's role-administration amendment; roles slice 2, C2, from
 * `docs/session13/roles-s2-spar.md` §2 item 2, which overrides design §4.1).
 *
 * **Why one tenant-wide lock and not row locks:** API-20's last-owner count races across DIFFERENT rows (two owners
 * revoking each other touch two `user_role` rows), PC-13 races on one subject across two roles, and E-15f's holder re-run
 * races every grant of an edited role. The row locks that would serve need `UPDATE` grants no writer has (ADR-002 A4.3
 * constraint 1). Role administration is rare, so serialising it per tenant costs nothing measurable.
 *
 * **Domain — who must take it, first, unconditionally:** every writer of `user_role`, `role_permission`,
 * `role_grant_proposal` (slice 3) and non-seed `role` rows, and every writer of `app_user.is_active` / `deleted_at` (the
 * E-15g paths). In slice 2: `RevokeRole` (C2) and `AssignRole` (C3). **Since U-C1b also `CreateUser`** (spar M-1, D-U4′),
 * which writes no role table: it serialises against `RestoreUser`'s email check and against a concurrent deactivation of
 * the actor (T-U13), and the positional test matches it on its `app_user` INSERT. `PurchaseOrderRowLockTest`'s role scan
 * derives the writers from `src/` and is red on one that is not accounted for.
 *
 * ---------------------------------------------------------------------------
 * The statement, and the three refusals it makes itself (spar S-4, P2)
 * ---------------------------------------------------------------------------
 * {@see self::SQL} reads the tenant setting AND takes the lock in ONE statement, so the lock is still the first statement
 * of the writing transaction after `set_config` and the positional test keeps its strict form. The lock is taken only
 * inside a `CASE` that compares the setting with the explicit tenant id: the spar MEASURED that the unguarded form (a plain
 * select list) took the lock on a mismatched setting — another tenant's key, which could make this request wait on that
 * tenant's holder before refusing. Guarded, a mismatch or an empty setting takes nothing (`locked` is NULL, no advisory
 * lock in `pg_locks`), and the short-circuit holds as a prepared statement too.
 *
 *  1. **Outside a transaction it refuses before sending anything** — an autocommitted `pg_advisory_xact_lock` is taken and
 *     released in one statement: *"an autocommitted lock is no lock"*.
 *  2. **On an empty or mismatched tenant setting** it refuses: the lock was not taken, so nothing it would protect is.
 *  3. **Unless `locked` is exactly `true`** — which is stronger than comparing the setting: it proves the lock was taken.
 *
 * Every refusal is a `\LogicException` (a programming error, a 500) — `PurchaseOrderRowLock::holdInside()`'s precedent.
 * The rollback the exception causes releases anything taken.
 *
 * **The key is canonical, twice.** `RED` measured (R2-8) that `hashtext()` of the upper- and lower-case spellings of one
 * uuid differ while the uuids compare equal, so two callers spelling one tenant two ways would pass the setting check and
 * take DIFFERENT locks. PHP canonicalises (`toRfc4122()`), and the SQL casts `?::uuid::text`, so a future caller that
 * skipped the PHP step still could not split the lock. Keyed on the EXPLICIT tenant id, never on `current_setting()`, so an
 * empty setting cannot key every caller to one value (spar S-4).
 *
 * **Correct only under READ COMMITTED** (the cluster default, measured): each statement after the lock sees what the
 * previous holder committed. The race tests assert the request connection's isolation rather than assume it.
 *
 * **Takes the entity manager `TenantContext::run()` hands its callback**, as `PurchaseOrderRowLock` and `InvoiceRowLock`
 * do, and issues on its connection — the app connection by construction, one connection for lock, reads, writes and
 * audit (H4's intent). *Deviation, recorded:* the design's H4 spelling (non-binding) was a `Connection` parameter; a
 * `Connection` type in `Application` is an A-10 deptrac edge, and A-10 is the register for ADR-006's explicit per-principal
 * BINDINGS, which this is not. There is no container binding either way, so the "unbound autowired `Connection` is the
 * schema owner" trap cannot arise.
 *
 * **Honest limit (spar A-2):** out-of-band owner-connection writers — provisioning under `ARCHITECTURE.md` S-10 and the
 * deferred demo seeder — do not take it. Acceptable only because provisioning precedes a live tenant and the seeder
 * targets demo tenants. Nothing else in `src/` or `tests/` uses an advisory lock, so `CLASS_ID` collides with nothing.
 */
final class RoleAdministrationLock
{
    /** The advisory lock's class id (the first `int` of the two-key form). Nothing else in the project takes one. */
    public const CLASS_ID = 7001;

    /**
     * Verbatim from `roles-s2-spar.md` §2 item 2. The positional test pins THIS constant, not a transcription of it.
     * Parameters: the canonical tenant id (compared), the class id, the canonical tenant id (hashed).
     */
    public const SQL = "SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid AS guc, "
        ."CASE WHEN NULLIF(current_setting('app.tenant_id', true), '')::uuid = ?::uuid "
        .'THEN pg_advisory_xact_lock(?, hashtext(?::uuid::text)) IS NOT NULL END AS locked';

    public static function holdInside(EntityManagerInterface $em, string $tenantId): void
    {
        $connection = $em->getConnection();

        if (0 === $connection->getTransactionNestingLevel()) {
            throw new \LogicException(
                'RoleAdministrationLock::holdInside() needs an open transaction: outside one the advisory lock is taken '
                .'and released in the same statement, which protects nothing.'
            );
        }

        if (!Uuid::isValid($tenantId)) {
            throw new \LogicException('RoleAdministrationLock::holdInside() was given a tenant id that is not a uuid.');
        }

        $key = Uuid::fromString($tenantId)->toRfc4122();

        $row = $connection->fetchAssociative(
            self::SQL,
            [$key, self::CLASS_ID, $key],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );

        if (!\is_array($row) || true !== $row['locked']) {
            throw new \LogicException(\sprintf(
                'The role-administration lock was not taken: the transaction\'s tenant setting is %s, not the tenant this '
                .'write is for. Nothing is written without the lock.',
                \is_array($row) && \is_string($row['guc'] ?? null) ? '"'.$row['guc'].'"' : 'empty',
            ));
        }
    }

    /** Not instantiable: one statement and its refusals. */
    private function __construct()
    {
    }
}
