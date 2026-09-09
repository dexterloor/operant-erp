<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Tenancy\SchemaManifest;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Turns a token's two claims into the caller, on **`erp_app`** inside one
 * tenancy transaction.
 *
 * ---------------------------------------------------------------------------
 * The permission set is DERIVED, per request
 * ---------------------------------------------------------------------------
 * `role_permission`, joined through the caller's own `user_role` rows, with the
 * `role.deleted_at IS NULL` predicate written out because this is DBAL and
 * `SoftDeleteFilter` is an ORM filter. It is the same query
 * `App\Auth\Application\CurrentUserReader` runs for `/me`, and that is the point:
 * PC-6 makes the sidebar a *rendering* of the enforced set, so the two must be
 * one derivation and not two.
 *
 * **Why the query is not shared with `CurrentUserReader` as a method call.**
 * `/me` needs `roles[]` as well and returns a response DTO; this needs neither.
 * They are pinned to each other by a test that drives both over one request pair
 * (`PermissionEnforcementTest::theSetTheApiEnforcesIsTheSetMeAdvertises`), which
 * is a stronger guarantee than a shared private method: it compares the two at
 * the boundary, where a divergence would actually be visible.
 *
 * ---------------------------------------------------------------------------
 * Every failure returns null, and the caller turns every null into the same 401
 * ---------------------------------------------------------------------------
 * A claim that is not a UUID, a tenant that no longer exists, a user that never
 * existed in this tenant, a soft-deleted principal — all of them are `null`.
 * E-7's argument generalises here: a client that could tell *"expired"* from
 * *"deleted user"* would eventually be told which users exist.
 *
 * **`deleted_at IS NULL` and nothing more, matching `CurrentUserReader`.**
 * `app_user.is_active` is the sign-in path's branch (`API.md` §7.6) and adding
 * it here would change `/me`'s behaviour, which is not this slice's to change.
 * Named rather than left implicit: a live token held by a **deactivated but not
 * deleted** principal still authenticates — probed, not inferred: with
 * `is_active` set false and read back, an authenticated request answers 200,
 * while setting `deleted_at` answers 401.
 *
 * Whether §7.6's sign-in ruling is meant to extend to a session that already
 * exists is **`API.md` §14.1 API-O-22**, owner `SEC`. This docblock previously
 * said the question was *"raised as a §14.1 item"* when §14.1 ended at API-O-19
 * and no such item had been filed — the citation named nothing, which is the
 * failure mode this project records as error #1. It names the row now.
 */
final readonly class IdentityReader
{
    public function __construct(private TenantContext $tenants, private Connection $app)
    {
    }

    public function read(string $tenantId, string $userId): ?Identity
    {
        // The claims arrive as strings off a signed token and reach `::uuid`
        // casts and `TenantContext::run` below, both of which raise on anything
        // else. A forged-but-well-signed token is not reachable, but a malformed
        // claim must be a 401 and never a 500.
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($userId)) {
            return null;
        }

        /** @var Identity|null */
        return $this->tenants->run($tenantId, function () use ($tenantId, $userId): ?Identity {
            // RLS supplies the tenant predicate. `deleted_at IS NULL` is the
            // application's: a soft-deleted principal holding a live token is
            // not a principal.
            $user = $this->app->fetchAssociative(
                'SELECT id, display_name FROM app_user WHERE id = ?::uuid AND deleted_at IS NULL',
                [$userId],
            );

            if (!\is_array($user)) {
                return null;
            }

            $codes = $this->app->fetchFirstColumn(<<<'SQL'
                SELECT DISTINCT rp.permission_code
                FROM user_role ur
                JOIN role r ON r.id = ur.role_id AND r.deleted_at IS NULL
                JOIN role_permission rp ON rp.role_id = r.id
                WHERE ur.user_id = ?::uuid
                ORDER BY 1
                SQL, [$userId]);

            return new Identity(
                tenantId: $tenantId,
                userId: (string) $user['id'],
                actorLabel: (string) $user['display_name'],
                permissionCodes: array_map(strval(...), $codes),
            );
        });
    }

    /**
     * The two joins above carry no `deleted_at` predicate of their own, and that
     * is correct only while both tables stay deletion class `hard`.
     *
     * Named so the manifest has a reader in this file, exactly as
     * `CurrentUserReader::joinedTablesRequiringNoDeletionPredicate` does for the
     * same two joins.
     *
     * @return array<string, string>
     */
    public static function joinedTablesRequiringNoDeletionPredicate(): array
    {
        return [
            'user_role' => SchemaManifest::TABLES['user_role']['deletion'],
            'role_permission' => SchemaManifest::TABLES['role_permission']['deletion'],
        ];
    }
}
