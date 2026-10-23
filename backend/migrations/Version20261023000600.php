<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Cred-C1 "Seal every password, and refuse one that was copied" — `app_user.password_mac`, its shape, its two column
 * grants, and the seal of every existing credential** (`docs/session13/cred-mail-user-design.md` §3.2 + Revision 1 FINAL
 * R1.3; `RESIDUAL_GAPS.md` G-12's closing work `(e)-cred`).
 *
 * - **`password_mac text NULL`** with `app_user_password_mac_shape`: NULL or `v1.<kid8>.<64 hex>`. Nullable on purpose
 *   (design D-C3, an owner notice): NULL is **refused** at sign-in and at `ChangePassword`, never skipped, so writing NULL
 *   buys a lockout that writing a bad `password_hash` already could.
 * - **`GRANT SELECT (password_mac) … TO erp_auth`** — sign-in step 4 reads it in its one statement (a column privilege is
 *   needed in the select list). **`GRANT UPDATE (password_mac) … TO erp_app`** — `ChangePassword` step 7 writes it with the
 *   hash (A4.1: the grant ships with its caller). `erp_app` reads it through its table-level SELECT. No INSERT anywhere.
 * - **The backfill seals every row that holds a hash and no seal, in this same transaction** (`postUp()`, which Doctrine
 *   runs after `up()`'s SQL and before the commit of a transactional migration). **It seals whatever hash a row holds when
 *   it runs**: a transplant made before this migration ran is blessed by it (`RESIDUAL_GAPS.md`, residual of Cred-C1).
 *   Locally `make reset` leaves `app_user` empty, so the backfill seals nothing on any local run (measured at build).
 * - **No per-tenant loop, and no tenant context at all** (Cred-C1 RED D-1, a deviation from the design's "per tenant"):
 *   `app_user` is FORCE ROW LEVEL SECURITY and the owner is NOBYPASSRLS, so the owner sees nothing without context. The
 *   per-tenant `set_config` the design proposed is forbidden here — `TenantContextContainmentTest` allows no tenant-context
 *   writer in `migrations/`, and switching tenants inside one transaction is the case `TenantContext` refuses. The design
 *   cited `Version20260910000100` as a loop precedent; it is not one (it backfills before `CREATE POLICY`). The precedent
 *   followed is `Version20261022020000` (G7b): `NO FORCE ROW LEVEL SECURITY`, backfill as the table owner, `FORCE` again,
 *   all in one transaction, so nothing outside it ever sees the table unforced.
 * - **If rows need sealing and `AUTH_CREDENTIAL_MAC_KEY` is not set, the migration throws** and the transaction rolls back
 *   (FORCE included). It never seals with a missing key and never skips. The key is read from `$_SERVER` / `$_ENV` (the
 *   Symfony runtime loads `.env` without `putenv`, so `getenv()` would not see it), and it is never echoed.
 *
 * `sealAll()` is static so `CredentialBackfillTest` can drive the same code on rows of two tenants (T-C10).
 */
final class Version20261023000600 extends AbstractMigration
{
    public const KEY_NAME = 'AUTH_CREDENTIAL_MAC_KEY';

    public function getDescription(): string
    {
        return 'Cred-C1: app_user.password_mac (the keyed seal over the stored password), its shape CHECK, erp_auth SELECT and erp_app UPDATE on it, and the seal of every existing credential';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD COLUMN password_mac text');
        $this->addSql(<<<'SQL'
            ALTER TABLE app_user ADD CONSTRAINT app_user_password_mac_shape
                CHECK (password_mac IS NULL OR password_mac ~ '^v1\.[0-9a-f]{8}\.[0-9a-f]{64}$')
            SQL);
        $this->addSql('GRANT SELECT (password_mac) ON app_user TO erp_auth');
        $this->addSql('GRANT UPDATE (password_mac) ON app_user TO erp_app');
        // Undone in postUp() once every row is sealed; a throw rolls this back with everything else.
        $this->addSql('ALTER TABLE app_user NO FORCE ROW LEVEL SECURITY');
    }

    public function postUp(Schema $schema): void
    {
        // Under --dry-run up()'s SQL never ran, so the column is not there to seal.
        if (!(bool) $this->connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'app_user'::regclass AND attname = 'password_mac' AND NOT attisdropped)")) {
            return;
        }

        $key = $_SERVER[self::KEY_NAME] ?? $_ENV[self::KEY_NAME] ?? null;
        $sealed = self::sealAll($this->connection, \is_string($key) ? $key : null);
        $this->connection->executeStatement('ALTER TABLE app_user FORCE ROW LEVEL SECURITY');
        $this->write(\sprintf('Sealed %d existing credential(s).', $sealed));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (password_mac) ON app_user FROM erp_app');
        $this->addSql('REVOKE SELECT (password_mac) ON app_user FROM erp_auth');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT app_user_password_mac_shape');
        $this->addSql('ALTER TABLE app_user DROP COLUMN password_mac');
    }

    /**
     * Seal every row that holds a hash and no seal, as the table owner, on a table whose RLS is not forced for that owner.
     * Returns how many rows it sealed. Throws, naming the count and never the key, when rows need sealing and no key is set.
     */
    public static function sealAll(Connection $owner, ?string $key): int
    {
        $rows = $owner->fetchAllAssociative(
            'SELECT id, tenant_id, password_hash FROM app_user WHERE password_mac IS NULL ORDER BY tenant_id, id');
        if ([] === $rows) {
            return 0;
        }
        if (null === $key || '' === $key) {
            throw new \RuntimeException(\sprintf(
                '%d app_user row(s) hold a password and no seal, and %s is not set. Set the key and migrate again: '
                .'sealing with no key, or skipping the rows, would leave them unable to sign in or able to without a seal.',
                \count($rows),
                self::KEY_NAME,
            ));
        }

        $seal = new CredentialSeal($key);
        foreach ($rows as $row) {
            $owner->executeStatement(
                'UPDATE app_user SET password_mac = ? WHERE id = ?::uuid AND tenant_id = ?::uuid AND password_mac IS NULL',
                [$seal->seal((string) $row['tenant_id'], (string) $row['id'], (string) $row['password_hash']), $row['id'], $row['tenant_id']],
            );
        }

        return \count($rows);
    }
}
