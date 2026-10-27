<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;

/**
 * **Mail-C2 step 3 — the redeem's one `erp_auth` transaction** (`docs/session13/mail-c2-build-plan.md` §4.2; the
 * `CredentialLookup` shape). `BEGIN` → `set_config` (the tenant from the token's first 16 bytes, or the sentinel) → **one**
 * `SELECT` → `COMMIT`, identical on every path: a malformed token, an unknown selector and a real row issue the same four
 * statements, so the statement sequence says nothing (T-R2b).
 *
 * The tenant is read only to set the RLS context; the row is then found by `(tenant from the RLS context, selector)` —
 * `password_reset_token_selector_key UNIQUE (tenant_id, selector)`. A forged tenant prefix sets a context that reads
 * nothing, and it could not verify anyway: the verifier MAC binds the tenant (`CredentialSeal::macVerifier()`).
 *
 * `uncredentialed` (U-C1a): whether the person has never had a sealed credential (`password_mac IS NULL`) — an
 * `invitation` link is redeemable only then. Same one statement; `erp_auth` already reads `password_mac`.
 *
 * The join to `app_user` keeps only a live (not soft-deleted) person, so a removed person's link joins nothing and is the
 * one constant refusal. `expires_at` and `created_at` come back as unix seconds (what the MAC encodes; the worker stores
 * whole seconds), and the session floor as epoch seconds, possibly fractional (`IdentityReader`'s comparison).
 *
 * On `erp_auth` (`services.yaml`): the pre-authentication read principal, which holds SELECT only on the nine token
 * columns, `tenant (id, slug, status)` and the `app_user` columns named here, and can write nothing in the schema.
 */
final readonly class PasswordResetTokenLookup
{
    private const SQL = <<<'SQL'
        SELECT t.id, t.user_id, t.purpose, t.selector, t.verifier_mac,
               extract(epoch FROM t.expires_at)::bigint AS expires_unix,
               t.used_at IS NOT NULL AS used,
               extract(epoch FROM t.created_at)::bigint AS created_unix,
               u.email, u.is_active, u.identity_mac, u.password_mac IS NULL AS uncredentialed,
               extract(epoch FROM u.sessions_valid_after) AS floor_epoch,
               ten.slug, ten.status
          FROM password_reset_token t
          JOIN app_user u ON u.tenant_id = t.tenant_id AND u.id = t.user_id AND u.deleted_at IS NULL
          JOIN tenant ten ON ten.id = t.tenant_id
         WHERE t.selector = ?
        SQL;

    public function __construct(private Connection $auth, private TenantContext $tenants)
    {
    }

    public function find(string $tenantId, string $selectorHex): ?PasswordResetTokenRow
    {
        $this->auth->beginTransaction();

        try {
            $this->tenants->establishOn($this->auth, $tenantId);
            $row = $this->auth->fetchAssociative(self::SQL, [$selectorHex]);
            $this->auth->commit();
        } catch (\Throwable $e) {
            $this->auth->rollBack();

            throw $e;
        }

        if (!\is_array($row)) {
            return null;
        }

        return new PasswordResetTokenRow(
            id: (string) $row['id'],
            tenantId: $tenantId,
            userId: (string) $row['user_id'],
            purpose: (string) $row['purpose'],
            selector: (string) $row['selector'],
            verifierMac: (string) $row['verifier_mac'],
            expiresUnix: (int) $row['expires_unix'],
            used: (bool) $row['used'],
            createdUnix: (int) $row['created_unix'],
            storedEmail: (string) $row['email'],
            userIsActive: (bool) $row['is_active'],
            uncredentialed: (bool) $row['uncredentialed'],
            identityMac: null === $row['identity_mac'] ? null : (string) $row['identity_mac'],
            sessionFloorEpoch: null === $row['floor_epoch'] ? null : (float) $row['floor_epoch'],
            tenantSlug: (string) $row['slug'],
            tenantStatus: (string) $row['status'],
        );
    }
}
