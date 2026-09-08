<?php

declare(strict_types=1);

namespace App\Api\Tenancy;

use Symfony\Component\Uid\Uuid;

/**
 * The tenant seam filled by hand.
 *
 * Wired **only** in the test environment (`config/services.yaml`, `when@test`),
 * because there is no authentication stack yet and an HTTP test still has to be
 * able to act as somebody. It is the container that decides, never the request:
 * a test that could pass a tenant in a header would be testing an API that
 * violates API-7.
 */
final class FixedTenant implements CurrentTenant
{
    private ?string $tenantId = null;

    public function actAs(string $tenantId): void
    {
        $this->tenantId = Uuid::fromString($tenantId)->toRfc4122();
    }

    public function tenantId(): string
    {
        return $this->tenantId ?? throw new \LogicException('No tenant has been chosen for this test. Call FixedTenant::actAs().');
    }
}
