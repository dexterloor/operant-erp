<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`GetRoleGrantProposal` / `ListRoleGrantProposals`, and the body every proposal write returns** (roles slice 3, C1;
 * `docs/session13/roles-s3-design.md` §3.3, §4.6). Gated on `role.assign` by the caller (E-1); `viewer` and every
 * `role.view`-only holder never reach it (IA §6.8; PC-16 condition 1).
 *
 * **No deletion predicate on the `app_user` or `role` joins** (RED L-2; the `PurchaseOrderRow` precedent): a proposal
 * for or by a removed user, or for an archived role, stays listed and gettable, or nobody could find it to reject or
 * withdraw it. The display name of a removed actor is shown as stored (the house `created_by` precedent, M-17).
 *
 * **`is_expired` is derived for display only** — `status = 'pending' AND expires_at <= now()` (R-13, *"shown as
 * expired"*). It is the one use of `now()` in E-15e and it decides nothing; every decision compares `expires_at` with the
 * post-lock `clock_timestamp()` (§5.2.6).
 *
 * **The keyset cursor carries `proposed_at` as PostgreSQL renders it** (`DATA` §1.4; `PurchaseOrderReader`'s precedent)
 * and is cast back in ONE row comparison `(proposed_at, id)`, so two proposals in one millisecond are neither skipped
 * nor repeated; the wire form of `proposed_at` (RFC 3339, milliseconds) is never the cursor.
 *
 * This statement names `role_grant_proposal` and never `user_role`: no reader of grants reads proposals, and no reader
 * of proposals reads grants (`PermissionDerivationTableSetTest`, T2). Scoping is forced RLS inside
 * `TenantContext::run()` on the `erp_app` entity manager; never a constructor `Connection` (the schema owner). No
 * `Infrastructure\Entity` import.
 */
final readonly class RoleGrantProposalReader
{
    private const SELECT = <<<'SQL'
        SELECT rgp.id, rgp.permission_codes::text AS permission_codes, rgp.status,
               (rgp.status = 'pending' AND rgp.expires_at <= now()) AS is_expired,
               rgp.proposed_at, rgp.proposed_at::text AS proposed_at_key, rgp.expires_at, rgp.decided_at,
               rgp.reason, rgp.lapse_reason,
               s.id AS user_id, s.display_name AS user_display_name,
               r.id AS role_id, r.name AS role_name,
               p.id AS proposed_by_id, p.display_name AS proposed_by_display_name,
               d.id AS decided_by_id, d.display_name AS decided_by_display_name
          FROM role_grant_proposal rgp
          JOIN app_user s ON s.tenant_id = rgp.tenant_id AND s.id = rgp.user_id
          JOIN role r ON r.tenant_id = rgp.tenant_id AND r.id = rgp.role_id
          JOIN app_user p ON p.tenant_id = rgp.tenant_id AND p.id = rgp.proposed_by_user_id
          LEFT JOIN app_user d ON d.tenant_id = rgp.tenant_id AND d.id = rgp.decided_by_user_id
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /** Another workspace's id, an absent one and a malformed one are all null (RLS; ST-5). */
    public function get(string $id): ?RoleGrantProposalView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($id): ?RoleGrantProposalView {
            $row = $em->getConnection()->fetchAssociative(self::SELECT.' WHERE rgp.id = ?::uuid', [Uuid::fromString($id)->toRfc4122()]);

            return false === $row ? null : self::view($row);
        });
    }

    /**
     * Newest first, `(proposed_at, id)` descending; filters `status`, `user`, `role` (an unknown uuid is an empty page,
     * never a 404). API-22: `tenant_has_rows` is probed only on an empty page, with no filter predicate.
     */
    public function list(RoleGrantProposalListCriteria $criteria): RoleGrantProposalPage
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($criteria): RoleGrantProposalPage {
            $connection = $em->getConnection();
            $where = [];
            $parameters = [];

            if (null !== $criteria->status) {
                $where[] = 'rgp.status = ?';
                $parameters[] = $criteria->status;
            }
            foreach ([['rgp.user_id', $criteria->userId], ['rgp.role_id', $criteria->roleId]] as [$column, $value]) {
                if (null !== $value) {
                    $where[] = $column.' = ?::uuid';
                    $parameters[] = $value;
                }
            }
            $cursor = $criteria->after ?? $criteria->before;
            if (null !== $cursor) {
                // API-18: (sort key, id) as ONE row comparison; newest first, so forward continues BELOW the cursor.
                $where[] = \sprintf('(rgp.proposed_at, rgp.id) %s (?::timestamptz, ?::uuid)', $criteria->isBackwards() ? '>' : '<');
                $parameters[] = $cursor->sortKey;
                $parameters[] = $cursor->id;
            }

            $direction = $criteria->isBackwards() ? 'ASC' : 'DESC';
            $sql = self::SELECT
                .([] === $where ? '' : ' WHERE '.implode(' AND ', $where))
                .\sprintf(' ORDER BY rgp.proposed_at %s, rgp.id %s LIMIT ?', $direction, $direction);
            $parameters[] = $criteria->limit + 1;

            $rows = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }
            if ($criteria->isBackwards()) {
                $rows = array_reverse($rows);
            }

            $next = $previous = null;
            if ([] !== $rows) {
                $first = new Cursor((string) $rows[0]['proposed_at_key'], (string) $rows[0]['id']);
                $lastRow = $rows[array_key_last($rows)];
                $last = new Cursor((string) $lastRow['proposed_at_key'], (string) $lastRow['id']);
                [$next, $previous] = $criteria->isBackwards()
                    ? [$last->encode(), $hasMore ? $first->encode() : null]
                    : [$hasMore ? $last->encode() : null, null !== $criteria->after ? $first->encode() : null];
            }

            return new RoleGrantProposalPage(
                items: array_map(self::view(...), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM role_grant_proposal)') : true,
            );
        });
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): RoleGrantProposalView
    {
        $codes = json_decode((string) $row['permission_codes'], true, 4, \JSON_THROW_ON_ERROR);
        \assert(\is_array($codes));

        return new RoleGrantProposalView(
            id: (string) $row['id'],
            userId: (string) $row['user_id'],
            userDisplayName: (string) $row['user_display_name'],
            roleId: (string) $row['role_id'],
            roleName: (string) $row['role_name'],
            permissionCodes: array_map(strval(...), array_values($codes)),
            status: (string) $row['status'],
            isExpired: (bool) $row['is_expired'],
            proposedById: (string) $row['proposed_by_id'],
            proposedByDisplayName: (string) $row['proposed_by_display_name'],
            proposedAt: (string) self::timestamp($row['proposed_at']),
            expiresAt: (string) self::timestamp($row['expires_at']),
            decidedById: null === $row['decided_by_id'] ? null : (string) $row['decided_by_id'],
            decidedByDisplayName: null === $row['decided_by_display_name'] ? null : (string) $row['decided_by_display_name'],
            decidedAt: self::timestamp($row['decided_at']),
            reason: null === $row['reason'] ? null : (string) $row['reason'],
            lapseReason: null === $row['lapse_reason'] ? null : (string) $row['lapse_reason'],
        );
    }

    /** RFC 3339 with milliseconds, the form every other timestamp on this API uses. */
    private static function timestamp(mixed $value): ?string
    {
        return null === $value ? null : new \DateTimeImmutable((string) $value)->format(\DateTimeInterface::RFC3339_EXTENDED);
    }
}
