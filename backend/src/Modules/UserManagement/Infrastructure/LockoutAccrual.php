<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

/**
 * What `LockoutStore::recordFailure()`'s one upsert returned: both counters on
 * the pair's `auth_lockout` row after this attempt, read from `RETURNING` so
 * reading them costs no statement.
 */
final readonly class LockoutAccrual
{
    /**
     * @param int $failureCount     E-22's counter: attempts by anyone, reset by a success or a pause expiry
     * @param int $suppressionCount E-12's counter: today's (UTC) attempts on the branches that write the trail
     */
    public function __construct(
        public int $failureCount,
        public int $suppressionCount,
    ) {
    }
}
