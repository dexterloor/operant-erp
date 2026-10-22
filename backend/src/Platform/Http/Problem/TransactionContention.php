<?php

declare(strict_types=1);

namespace App\Platform\Http\Problem;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;

/**
 * **A transaction that lost to another at the same moment** (G-65's closing work, 2026-10-23; `docs/session13/g65-design.md`).
 *
 * PostgreSQL breaks a lock cycle by aborting one side with `40P01` (DBAL: {@see DeadlockException}, which DBAL 4 also uses
 * for `40001`), and refuses a lock it cannot take within `lock_timeout` with `55P03`. Either way the WHOLE transaction is
 * aborted and rolled back — nothing it wrote survives — so the honest answer is "nothing was saved; try again", never a 500.
 *
 * One predicate, two answers: {@see \App\Platform\Http\Bulk\BulkArchival} translates its own contention to
 * `bulk.contention` (G5-C2) before any listener sees it; every other API request's contention reaches
 * {@see FrameworkProblemTranslator}, which answers 409 {@see self::RULE}. **Measured 2026-10-22 (G5-C2 F-1):** DBAL 4's
 * PostgreSQL converter maps `40P01`/`40001` to `DeadlockException` but does NOT map `55P03` to `LockWaitTimeoutException` —
 * it arrives as a plain `DriverException` — so the lock-timeout arm is keyed on the SQLSTATE, not the class.
 *
 * Keyed on those states ONLY: a constraint violation, an aborted-transaction `25P02` or any other driver failure is not
 * contention and still reaches the 500 path unchanged (`TransactionContentionTest`'s `P0001` control).
 */
final class TransactionContention
{
    public const RULE = 'transaction.contention';

    /** Placeholder copy, `UX` to confirm (with `bulk.contention`'s). */
    public const MESSAGE = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';

    /** `40P01` deadlock_detected, `40001` serialization_failure (unreachable: no SERIALIZABLE / REPEATABLE READ anywhere), `55P03` lock_not_available. */
    public const SQLSTATES = ['40P01', '40001', '55P03'];

    private function __construct()
    {
    }

    public static function is(DriverException $failure): bool
    {
        return $failure instanceof DeadlockException || $failure instanceof LockWaitTimeoutException
            || \in_array($failure->getSQLState(), self::SQLSTATES, true);
    }
}
