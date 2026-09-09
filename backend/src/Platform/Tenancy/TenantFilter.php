<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * ADR-002 §4 -- ergonomics, not security. Demoted on purpose so that nobody
 * mistakes it for the boundary.
 *
 * It only touches conditional clauses, so it does nothing for INSERT; it applies
 * to the root entity and misses entities pulled in through a JOIN
 * (doctrine/orm#6343); and nothing prevents a caller from setting a different
 * tenant on it. RLS covers all three because it binds at table level.
 *
 * When no tenant has been set it emits a contradiction rather than an empty
 * string. An empty string means "no constraint", which would fail open -- the
 * same mistake ADR-002 §2 warns about in the policy itself.
 */
final class TenantFilter extends SQLFilter
{
    public const NAME = 'tenant';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->hasField('tenantId')) {
            return '';
        }

        try {
            $tenantId = $this->getParameter('tenant_id');
        } catch (\InvalidArgumentException) {
            return '1 = 0';
        }

        return sprintf('%s.tenant_id = %s', $targetTableAlias, $tenantId);
    }
}
