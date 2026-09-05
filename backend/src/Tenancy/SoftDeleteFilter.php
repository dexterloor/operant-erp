<?php

declare(strict_types=1);

namespace App\Tenancy;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * ADR-007 §2 — ergonomics, and **not** a second security boundary.
 *
 * It sits next to TenantFilter and looks like it, which is the risk ADR-007
 * names explicitly: "do not let the two filters be described as the same kind
 * of thing". They are not.
 *
 *   TenantFilter        decorates a boundary that Postgres enforces. Forgetting
 *                       it shows a user their own data unfiltered by nothing;
 *                       RLS still refuses another tenant's rows.
 *   SoftDeleteFilter    has no such backstop. Forgetting it shows a user a row
 *                       they deleted. That is a bug, not a breach — and the
 *                       distinction matters, because the moment the two are
 *                       described together someone concludes the tenant filter
 *                       is load-bearing after all.
 *
 * There is deliberately no RLS policy for `deleted_at`. A soft-deleted row is
 * still tenant-scoped, and cross-tenant isolation is unaffected by deletion
 * state; adding a second predicate to the policy would make every history
 * query -- the entire reason soft deletion exists -- impossible to write.
 *
 * The predicate it emits is also what makes the partial indexes usable: a
 * `WHERE deleted_at IS NULL` index cannot be chosen for a query that does not
 * carry the predicate, so without this filter the live-row indexes would be
 * dead weight. SchemaQueryPlanTest proves the planner uses both together.
 */
final class SoftDeleteFilter extends SQLFilter
{
    public const NAME = 'soft_delete';

    /** @var array<string, true>|null */
    private static ?array $softDeleted = null;

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        self::$softDeleted ??= array_fill_keys(SchemaManifest::softDeletedTables(), true);

        // Only the tables ADR-007 classifies as soft-deleted carry the column,
        // and that classification is declared once in SchemaManifest rather than
        // inferred here, so "has a deletedAt field" cannot drift away from "is
        // declared soft-deleted".
        if (!isset(self::$softDeleted[$targetEntity->getTableName()])) {
            return '';
        }

        // No parameter, so unlike TenantFilter there is no unset-state to fail
        // closed about: the predicate is constant.
        return sprintf('%s.deleted_at IS NULL', $targetTableAlias);
    }
}
