<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Platform\Tenancy\SchemaManifest;
use App\Platform\Tenancy\TenantContext;
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
 * `App\Modules\UserManagement\Application\CurrentUserReader` runs for `/me`, and that is the point:
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
 * ---------------------------------------------------------------------------
 * Liveness is decided HERE, for every authenticated request (G4-C0, 2026-10-22)
 * ---------------------------------------------------------------------------
 * The `app_user` statement refuses, in one predicate and so as the same `null`:
 * a soft-deleted principal; an **inactive** one (`AND is_active` — closes
 * `API.md` §14.1 API-O-22 option (a): a deactivated person's live token stops
 * working on their next request instead of living out E-20's eight hours); and a
 * token minted **at or before** `sessions_valid_after` (`?::numeric >
 * extract(epoch FROM sessions_valid_after)`, strict). NULL = no restriction.
 *
 * - **Strict, and `floor` is unnecessary:** for an integer `iat` and a real `s`,
 *   `iat > s` holds exactly when `iat > floor(s)`, so a mutation adding or
 *   removing `floor` cannot go red — a review check, not evidence.
 * - **No database clock appears.** Both operands are PHP values: the token's `iat`
 *   (the host PSR clock at sign-in, `SignIn`) and the stamp the writers bind from
 *   the same injected clock. Never `now()` here — the erp2 guest DB clock is
 *   stepped by the Lima host agent. Named residual: with more than one app host
 *   the hosts' PHP clocks could differ; there is one host today.
 * - **Whole-second stamps:** written through the ORM the stamp loses its
 *   sub-second part (`AppUser::$sessionsValidAfter`); by the equivalence above
 *   that changes nothing here.
 * - The stamp is written at every liveness transition from G4-C1 on; until then
 *   only the owner sets it. The named cost: a sign-in in the same wall-clock second
 *   as a re-enable gets a token refused on its first use (200, then 401 once).
 *
 * **`CurrentUserReader` does not repeat this predicate, and is not meant to:** the
 * gate refuses an inactive or stamped-out principal before `/me` is reached. This
 * reader is the liveness check; that one is the profile read.
 * `PermissionEnforcementTest::theSetTheApiEnforcesIsTheSetMeAdvertises` pins the
 * two readers' **permission derivation**, not the `app_user` predicate.
 */
final readonly class IdentityReader
{
    public function __construct(private TenantContext $tenants, private Connection $app)
    {
    }

    public function read(string $tenantId, string $userId, int $issuedAt): ?Identity
    {
        // The claims arrive as strings off a signed token and reach `::uuid`
        // casts and `TenantContext::run` below, both of which raise on anything
        // else. A forged-but-well-signed token is not reachable, but a malformed
        // claim must be a 401 and never a 500.
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($userId)) {
            return null;
        }

        /** @var Identity|null */
        return $this->tenants->run($tenantId, function () use ($tenantId, $userId, $issuedAt): ?Identity {
            // RLS supplies the tenant predicate. The rest is the application's
            // liveness rule (class docblock): deleted, inactive, or a token minted
            // at or before sessions_valid_after is not a principal.
            $user = $this->app->fetchAssociative(<<<'SQL'
                SELECT id, display_name FROM app_user
                 WHERE id = ?::uuid AND deleted_at IS NULL AND is_active
                   AND (sessions_valid_after IS NULL OR ?::numeric > extract(epoch FROM sessions_valid_after))
                SQL,
                [$userId, $issuedAt],
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
