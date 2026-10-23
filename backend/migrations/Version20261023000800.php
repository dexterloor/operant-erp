<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Mail-C1 "Let anyone ask for a password link without learning who has an account" — the mail worker's footprint, the
 * reset-token store and the identity seal** (`docs/session13/mail-c1-build-plan.md` Revision 1 FINAL R1.2; design §4.2;
 * `RESIDUAL_GAPS.md` G-1.3, G-12, G-54, G-75).
 *
 * - **`erp_worker`** (owner Q-2, Move 671) is created by `infra/postgres/init/10-roles.sh`, never here (ADR-002 C-1). This
 *   migration only grants, column by column, exactly what its one caller issues (ADR-002 A4.1):
 *     `tenant (id, slug, status)` SELECT — the handler's first read; `app_user (id, tenant_id, email, is_active,
 *     deleted_at, identity_mac)` SELECT — never `password_hash`, `password_mac` or `display_name` (FA-6; the invitation
 *     check's `password_mac` is granted with its caller at U-C1); `password_reset_token` SELECT (the cap count) and
 *     column INSERT (no `used_at`, no UPDATE, no DELETE); `audit_log` INSERT (table level, like `erp_authlog`) plus
 *     column SELECT `(tenant_id, actor_user_id, event_type, occurred_at)` for the once-a-day identity-mismatch read-back.
 *     `erp_app` and `erp_auth` get nothing on the token table here: their redeem grants ship with Mail-C2.
 * - **`password_reset_token`** — one row per mailed link. `verifier_mac` is a keyed MAC (`k_tok`) over the token, never
 *   the token, so a row read or written by SQL without the key cannot become a working link. `purpose` admits
 *   `invitation` for U-C1, which will write it; nothing writes it yet. Deletion class n/a (SchemaManifest): never deleted; growth is a residual.
 *   **No CHECK names `used_at`** (RESIDUAL_GAPS "Instants ordered across transactions"): it is written at redeem, in
 *   another transaction, from another clock. `expires_at > created_at` compares two values written by one statement.
 * - **`app_user.identity_mac`** — the keyed seal over `(tenant_id, id, email)` (`k_ident`). No principal is granted
 *   UPDATE on it: rewriting a sealed user's email by SQL makes the worker refuse to mail the new address.
 * - **The worker may insert only its two events** (RED L-1): a RESTRICTIVE INSERT policy, ANDed with `tenant_isolation`.
 *   `SchemaGuardTest` registers it by name (`RESTRICTIVE_POLICIES`).
 * - **Backfill, Cred-C1's shape** (FA-1; `Version20261023000600`): `NO FORCE ROW LEVEL SECURITY` in `up()`, every
 *   unsealed row sealed as the table owner in `postUp()` — soft-deleted and inactive rows included, they are real — and
 *   `FORCE` again, all in one transaction. It refuses (throws, rolling everything back): rows to seal and no key;
 *   `AUTH_CREDENTIAL_MAC_KEY_ID` set and not this key's id (T-G75-2); password seals present and none made with this key
 *   (T-G75-3). The PREVIOUS key plays no part. It never echoes the key, only the count. Locally `make reset` leaves
 *   `app_user` empty, so the backfill seals nothing.
 *
 * `sealIdentities()` is static so `IdentityBackfillTest` can drive the same code (T-B1).
 */
final class Version20261023000800 extends AbstractMigration
{
    public const KEY_NAME = 'AUTH_CREDENTIAL_MAC_KEY';

    public const KEY_ID_NAME = 'AUTH_CREDENTIAL_MAC_KEY_ID';

    private const POLICY = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    private const UUID_V4 = "substring(id::text, 15, 1) = '4' AND substring(id::text, 20, 1) IN ('8', '9', 'a', 'b')";

    public function getDescription(): string
    {
        return 'Mail-C1: erp_worker grants, password_reset_token (RLS, keyed verifier, worker-only INSERT), app_user.identity_mac and its backfill, the worker audit INSERT policy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT USAGE ON SCHEMA public TO erp_worker');

        $this->addSql(<<<'SQL'
            CREATE TABLE password_reset_token (
                id            uuid        NOT NULL,
                tenant_id     uuid        NOT NULL,
                user_id       uuid        NOT NULL,
                purpose       text        NOT NULL,
                selector      text        NOT NULL,
                verifier_mac  text        NOT NULL,
                message_id    uuid        NOT NULL,
                created_at    timestamptz NOT NULL,
                expires_at    timestamptz NOT NULL,
                used_at       timestamptz,
                CONSTRAINT password_reset_token_pkey                PRIMARY KEY (id),
                CONSTRAINT password_reset_token_tenant_id_id_key    UNIQUE (tenant_id, id),
                CONSTRAINT password_reset_token_selector_key        UNIQUE (tenant_id, selector),
                CONSTRAINT password_reset_token_message_key         UNIQUE (tenant_id, message_id),
                CONSTRAINT password_reset_token_user_fk             FOREIGN KEY (tenant_id, user_id) REFERENCES app_user (tenant_id, id),
                CONSTRAINT password_reset_token_purpose_values      CHECK (purpose IN ('reset', 'invitation')),
                CONSTRAINT password_reset_token_selector_shape      CHECK (selector ~ '^[0-9a-f]{32}$'),
                CONSTRAINT password_reset_token_verifier_mac_shape  CHECK (verifier_mac ~ '^v1\.[0-9a-f]{8}\.[0-9a-f]{64}$'),
                CONSTRAINT password_reset_token_window              CHECK (expires_at > created_at)
            )
            SQL);
        // The worker's cap: "links this user was sent in the trailing 24 hours".
        $this->addSql('CREATE INDEX password_reset_token_user_recent_idx ON password_reset_token (tenant_id, user_id, created_at)');

        // ENABLE alone leaves the owner unfiltered (ADR-002 §C-2), and migrations run as the owner.
        $this->addSql('ALTER TABLE password_reset_token ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE password_reset_token FORCE  ROW LEVEL SECURITY');
        $policy = self::POLICY;
        $this->addSql(<<<SQL
            CREATE POLICY tenant_isolation ON password_reset_token
                USING      ($policy)
                WITH CHECK ($policy)
            SQL);
        // Every tenant-scoped row names a tenant that exists (ARCHITECTURE O-8).
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT password_reset_token_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        // D-12.
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT password_reset_token_id_is_uuid_v4 CHECK ('.self::UUID_V4.')');

        // REVOKE first, then GRANT: the footprint is independent of whatever default privileges stand.
        $this->addSql('REVOKE ALL ON password_reset_token FROM erp_app, erp_ai, erp_auth, erp_authlog, erp_ratelimit, erp_worker');
        $this->addSql('GRANT SELECT ON password_reset_token TO erp_worker');
        $this->addSql(<<<'SQL'
            GRANT INSERT (id, tenant_id, user_id, purpose, selector, verifier_mac, message_id, created_at, expires_at)
               ON password_reset_token TO erp_worker
            SQL);

        $this->addSql('GRANT SELECT (id, slug, status) ON tenant TO erp_worker');

        $this->addSql('ALTER TABLE app_user ADD COLUMN identity_mac text');
        $this->addSql(<<<'SQL'
            ALTER TABLE app_user ADD CONSTRAINT app_user_identity_mac_shape
                CHECK (identity_mac IS NULL OR identity_mac ~ '^v1\.[0-9a-f]{8}\.[0-9a-f]{64}$')
            SQL);
        $this->addSql('GRANT SELECT (id, tenant_id, email, is_active, deleted_at, identity_mac) ON app_user TO erp_worker');

        $this->addSql('GRANT INSERT ON audit_log TO erp_worker');
        $this->addSql('GRANT SELECT (tenant_id, actor_user_id, event_type, occurred_at) ON audit_log TO erp_worker');
        // RED L-1: ANDed with tenant_isolation, so the worker inserts only these two events, only in the context it set.
        $this->addSql(<<<'SQL'
            CREATE POLICY audit_log_worker_events ON audit_log AS RESTRICTIVE FOR INSERT TO erp_worker
                WITH CHECK (event_type IN ('auth.password_reset.issued', 'auth.credential.identity_mismatch'))
            SQL);

        // Undone in postUp() once every row is sealed; a throw rolls this back with everything else.
        $this->addSql('ALTER TABLE app_user NO FORCE ROW LEVEL SECURITY');
    }

    public function postUp(Schema $schema): void
    {
        // Under --dry-run up()'s SQL never ran, so the column is not there to seal.
        if (!(bool) $this->connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'app_user'::regclass AND attname = 'identity_mac' AND NOT attisdropped)")) {
            return;
        }

        $key = $_SERVER[self::KEY_NAME] ?? $_ENV[self::KEY_NAME] ?? null;
        $declared = $_SERVER[self::KEY_ID_NAME] ?? $_ENV[self::KEY_ID_NAME] ?? null;
        $sealed = self::sealIdentities($this->connection, \is_string($key) ? $key : null, \is_string($declared) ? $declared : null);
        $this->connection->executeStatement('ALTER TABLE app_user FORCE ROW LEVEL SECURITY');
        $this->write(\sprintf('Sealed %d existing identit(y/ies).', $sealed));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY audit_log_worker_events ON audit_log');
        $this->addSql('REVOKE SELECT (tenant_id, actor_user_id, event_type, occurred_at) ON audit_log FROM erp_worker');
        $this->addSql('REVOKE INSERT ON audit_log FROM erp_worker');
        $this->addSql('REVOKE SELECT (id, tenant_id, email, is_active, deleted_at, identity_mac) ON app_user FROM erp_worker');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT app_user_identity_mac_shape');
        $this->addSql('ALTER TABLE app_user DROP COLUMN identity_mac');
        $this->addSql('REVOKE SELECT (id, slug, status) ON tenant FROM erp_worker');
        $this->addSql('DROP TABLE password_reset_token');
        $this->addSql('REVOKE USAGE ON SCHEMA public FROM erp_worker');
    }

    /**
     * Seal every row with no identity seal, as the table owner, on a table whose RLS is not forced for that owner. Returns
     * the count. Throws — naming counts and key ids, never the key — on each refusal the class docblock lists.
     */
    public static function sealIdentities(Connection $owner, ?string $key, ?string $declaredKeyId): int
    {
        $rows = $owner->fetchAllAssociative(
            'SELECT id, tenant_id, email FROM app_user WHERE identity_mac IS NULL ORDER BY tenant_id, id');
        if ([] === $rows) {
            return 0;
        }
        if (null === $key || '' === $key) {
            throw new \RuntimeException(\sprintf(
                '%d app_user row(s) have no identity seal, and %s is not set. Set the key and migrate again: without a seal '
                .'the mail worker refuses to send that person a reset link.',
                \count($rows),
                self::KEY_NAME,
            ));
        }

        // T-G75-2: a declared id that is not this key's id throws here.
        $seal = new CredentialSeal($key, $declaredKeyId);

        // T-G75-3: if passwords are sealed and none was sealed with this key, this is the wrong key — never bless it.
        $sealed = (int) $owner->fetchOne('SELECT count(*) FROM app_user WHERE password_mac IS NOT NULL');
        $underThisKey = (int) $owner->fetchOne('SELECT count(*) FROM app_user WHERE substring(password_mac, 4, 8) = ?', [$seal->keyId()]);
        if ($sealed > 0 && 0 === $underThisKey) {
            throw new \RuntimeException(\sprintf(
                '%d password seal(s) exist and none was made with this %s (key id %s): sealing identities with it would bless '
                .'the wrong key. Fix the key and migrate again.',
                $sealed,
                self::KEY_NAME,
                $seal->keyId(),
            ));
        }

        foreach ($rows as $row) {
            $owner->executeStatement(
                'UPDATE app_user SET identity_mac = ? WHERE id = ?::uuid AND tenant_id = ?::uuid AND identity_mac IS NULL',
                [$seal->sealIdentity((string) $row['tenant_id'], (string) $row['id'], (string) $row['email']), $row['id'], $row['tenant_id']],
            );
        }

        return \count($rows);
    }
}
