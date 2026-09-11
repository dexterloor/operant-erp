<?php
// Out-of-process holder for `PurchaseOrderRowLockTest`: connection A of a
// two-connection race, where connection B is the real HTTP request.
//
// It connects to the UNPOOLED endpoint on purpose. The pool holds one backend
// (`PooledConnectionTest` measures that), so a holder on the pooled endpoint
// would make B wait for PgBouncer rather than for the row -- and the test
// would prove something about the pool, not about the lock.
//
// Sequence, every step decided by the database rather than by a clock:
//   1. BEGIN; set the tenant; take the order's row lock; run HOLD_BEFORE.
//   2. Write this backend's pid to HOLD_READY_FILE. The parent sends B now.
//   3. Wait until Postgres reports some backend blocked BY THIS ONE
//      (`pg_blocking_pids`). That is the moment B is provably inside its
//      transaction and waiting on a lock this transaction holds.
//   4. Run HOLD_AFTER, then COMMIT.
//   5. Print one JSON line: whether a waiter was seen, whether the commit
//      happened, and the SQLSTATE of anything that failed.
//
// The deadline in step 3 exists only for the failing case -- a B that never
// touches the order row -- so the parent is never left hanging. It is not a
// timing assumption: no passing run depends on how long anything takes.
require dirname(__DIR__, 3).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;

$params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse(getenv('APP_DIRECT_DATABASE_URL'));
$conn = DriverManager::getConnection($params);

/** @var list<array{0: string, 1: list<string>}> $before */
$before = json_decode((string) getenv('HOLD_BEFORE'), true, flags: JSON_THROW_ON_ERROR);
/** @var list<array{0: string, 1: list<string>}> $after */
$after = json_decode((string) getenv('HOLD_AFTER'), true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + (float) getenv('HOLD_DEADLINE_SECONDS');

$result = ['waiter_seen' => false, 'committed' => false, 'sqlstate' => null, 'error' => null];

try {
    $conn->beginTransaction();
    $conn->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [getenv('HOLD_TENANT')]);
    $conn->executeStatement('SELECT 1 FROM purchase_order WHERE id = ?::uuid FOR NO KEY UPDATE', [getenv('HOLD_ORDER')]);

    foreach ($before as [$sql, $bind]) {
        $conn->executeStatement($sql, $bind);
    }

    $pid = (int) $conn->fetchOne('SELECT pg_backend_pid()');
    file_put_contents((string) getenv('HOLD_READY_FILE'), (string) $pid);

    while (microtime(true) < $deadline) {
        if (true === $conn->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE ?::int = ANY (pg_blocking_pids(pid)))',
            [$pid],
        )) {
            $result['waiter_seen'] = true;
            break;
        }
        usleep(2000);
    }

    foreach ($after as [$sql, $bind]) {
        $conn->executeStatement($sql, $bind);
    }

    $conn->commit();
    $result['committed'] = true;
} catch (DriverException $e) {
    $result['sqlstate'] = $e->getSQLState();
    $result['error'] = $e->getMessage();
    if ($conn->isTransactionActive()) {
        $conn->rollBack();
    }
} catch (\Throwable $e) {
    $result['error'] = $e::class.': '.$e->getMessage();
    if ($conn->isTransactionActive()) {
        $conn->rollBack();
    }
}

// Ready file written even on an early failure, so the parent never waits for
// a signal that cannot come.
if ('' === trim((string) @file_get_contents((string) getenv('HOLD_READY_FILE')))) {
    file_put_contents((string) getenv('HOLD_READY_FILE'), 'failed');
}

$conn->close();
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
