<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The workspace a {@see TenantMessage} was dispatched in (ARCHITECTURE.md §6.4 M-1's name). Written ONLY by
 * {@see TenantStampMiddleware} from the transaction's own `app.tenant_id` setting; a stamp the dispatcher attached itself
 * is replaced, never trusted. Who can forge one on the queue is `RESIDUAL_GAPS.md`'s queue-credential entry (Mail-C0).
 */
final readonly class TenantIdStamp implements StampInterface
{
    public string $tenantId;

    public function __construct(string $tenantId)
    {
        if (!Uuid::isValid($tenantId)) {
            throw new \InvalidArgumentException('A tenant stamp must carry a UUID.');
        }
        $this->tenantId = Uuid::fromString($tenantId)->toRfc4122();
    }
}
