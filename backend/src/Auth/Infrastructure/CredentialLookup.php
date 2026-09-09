<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Tenancy\SchemaManifest;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;

/**
 * `API.md` §7.1 step 4 — the one transaction on **`erp_auth`**.
 *
 * `BEGIN` → `SELECT id, status FROM tenant WHERE slug = ?` → on a miss the
 * **sentinel** row → `set_config('app.tenant_id', ?, true)` →
 * `SELECT … FROM app_user WHERE email = ?` → `COMMIT`. Identical statement
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
 * `App\Auth\Application\SignIn` never asks a status question about it.
 *
 * ---------------------------------------------------------------------------
 * Read-only by grant, and the two columns that are not granted
 * ---------------------------------------------------------------------------
 * ADR-006 §2: `GRANT SELECT (id, tenant_id, email, password_hash, is_active) ON
 * app_user TO erp_auth`, and nothing else anywhere. A write here is not
 * code-gated, it is `permission denied`.
 *
 * **`deleted_at` is not among them, and a Postgres column privilege is required
 * for a reference in `WHERE` as much as in the select list.** So this query
 * cannot exclude a soft-deleted user, and `app_user_tenant_email_live` is
 * *partial* — unique only over live rows — so a re-issued address can legitimately
 * produce two rows. Both halves of that are stated rather than papered over, and
 * the guard that keeps it unreachable is
 * `PreAuthPrincipalTest::noShippedOperationCanSoftDeleteAnAppUserWhileTheAuthPrincipalCannotReadDeletedAt`:
 * no operation in the registry addresses `/users`, so nothing this wave ships can
 * create the state. The fix is a grant plus a predicate and is `SEC` + `DATA`'s,
 * not something to take inside a login path — routed in `API.md` §14.2.
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

            // ORDER BY id so the row this returns is a function of the data and
            // not of the plan. LIMIT 1 rather than a uniqueness assumption: the
            // live-email uniqueness is partial and this principal cannot see the
            // predicate it is partial on (class docblock).
            $user = $this->auth->fetchAssociative(
                'SELECT id, password_hash, is_active, email FROM app_user WHERE email = ? ORDER BY id LIMIT 1',
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
