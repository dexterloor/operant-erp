<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use App\Platform\Tenancy\SchemaManifest;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;

/**
 * `API.md` §7.1 step 4 — the one transaction on **`erp_auth`**.
 *
 * `BEGIN` → `SELECT id, status FROM tenant WHERE slug = ?` → on a miss the
 * **sentinel** row → `set_config('app.tenant_id', ?, true)` →
 * `SELECT … FROM app_user WHERE email = ? AND deleted_at IS NULL` → `COMMIT`. Identical statement
 * sequence on every path, which is ADR-006 V-8 and the half of E-7 no response
 * assertion can see.
 *
 * ---------------------------------------------------------------------------
 * The sentinel is decided **here**, and that is a rule rather than a placement
 * ---------------------------------------------------------------------------
 * ST-7.3: *"if the login response ever distinguishes any status, `sentinel` must
 * be routed to the unresolved-slug answer and not to the status answer."*
 * `Tenant::permitsLogin()` has no `sentinel` branch — verified, and deliberately
 * so — therefore the routing has to be in the caller, and the caller is this
 * class: an unresolved slug produces `resolved: false` and the sentinel id, and
 * `App\Modules\UserManagement\Application\SignIn` never asks a status question about it.
 *
 * ---------------------------------------------------------------------------
 * Read-only by grant, and a deleted person is not a principal
 * ---------------------------------------------------------------------------
 * ADR-006 §2: `GRANT SELECT (id, tenant_id, email, password_hash, is_active,
 * deleted_at) ON app_user TO erp_auth`, and nothing else anywhere. A write here
 * is not code-gated, it is `permission denied`.
 *
 * **G4-C0a (2026-10-22, `Version20261022006000`): `deleted_at` joined the grant
 * and the statement excludes a soft-deleted user.** A Postgres column privilege
 * is required for a reference in `WHERE` as much as in the select list, so the
 * grant and the predicate shipped together. A deleted person's email is now the
 * **unknown-email miss path**: the KDF runs on the dummy hash, no
 * `auth.login.failure` row is written (ADR-006 §2.2, *"the principal does not
 * exist"* — `SEC` accepted the lost signal, `SECURITY_MODEL.md` pre-auth), the
 * lockout pair still accrues, and the client sees the same constant 401.
 *
 * `app_user_tenant_email_live` is partial on `deleted_at IS NULL`, so with the
 * predicate at most one row can match. `ORDER BY id LIMIT 1` is now redundant
 * against that unique and is **kept**: it costs nothing and defends a future
 * case-variant unique. Pinned by
 * `TokenEndpointIndistinguishabilityTest::aDeletedUsersPasswordNoLongerSignsIn`
 * and `::aLiveUserWithADeletedNamesakeSignsInAsThemselves`.
 */
final readonly class CredentialLookup
{
    public function __construct(private Connection $auth, private TenantContext $tenants)
    {
    }

    public function resolve(string $slug, string $email): CredentialRow
    {
        // The tenant read happens *before* the context is established, because
        // the context is what the tenant read produces. It is inside the same
        // transaction as the credential read for ADR-006 V-8's reason: one
        // transaction, one statement sequence, identical on every miss path.
        $this->auth->beginTransaction();

        try {
            $tenant = $this->auth->fetchAssociative('SELECT id, status FROM tenant WHERE slug = ?', [$slug]);

            $tenantId = \is_array($tenant) ? (string) $tenant['id'] : SchemaManifest::SENTINEL_TENANT_ID;
            $status = \is_array($tenant) ? (string) $tenant['status'] : 'sentinel';

            $this->tenants->establishOn($this->auth, $tenantId);

            // A deleted person is not a principal (G4-C0a). ORDER BY id so the row
            // this returns is a function of the data and not of the plan; LIMIT 1
            // is redundant against the live-only email unique and kept on purpose
            // (class docblock).
            $user = $this->auth->fetchAssociative(
                'SELECT id, password_hash, is_active, email FROM app_user WHERE email = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
                [$email],
            );

            $this->auth->commit();
        } catch (\Throwable $e) {
            $this->auth->rollBack();

            throw $e;
        }

        return new CredentialRow(
            tenantId: $tenantId,
            tenantStatus: $status,
            tenantResolved: \is_array($tenant),
            userId: \is_array($user) ? (string) $user['id'] : null,
            passwordHash: \is_array($user) ? (string) $user['password_hash'] : null,
            userIsActive: \is_array($user) ? (bool) $user['is_active'] : false,
            storedEmail: \is_array($user) ? (string) $user['email'] : null,
        );
    }
}
