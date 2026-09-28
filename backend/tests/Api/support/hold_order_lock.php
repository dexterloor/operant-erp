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
//   1. BEGIN; set the tenant; take the row lock on HOLD_TABLE (whitelisted:
//      `purchase_order` or `sales_order`, defaulting to the first); run HOLD_BEFORE.
//   2. Write this backend's pid to HOLD_READY_FILE. The parent sends B now.
//   3. Wait until Postgres reports some backend blocked BY THIS ONE
//      (`pg_blocking_pids`). That is the moment B is provably inside its
//      transaction and waiting on a lock this transaction holds.
//   4. Run HOLD_AFTER, then COMMIT. If no waiter was ever seen, ROLL BACK
//      instead: nothing raced, so HOLD_BEFORE's writes must not land and be
//      mistaken for a result.
//   5. Print one JSON line: whether a waiter was seen, whether the commit
//      happened, and the SQLSTATE of anything that failed.
//
// The deadline in step 3 exists only for the failing case -- a B that never
// touches the order row -- so the parent is never left hanging. It is not a
// timing assumption: no passing run depends on how long anything takes.
require dirname(__DIR__, 3).'/vendor/autoload.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;

$readyFile = (string) getenv('HOLD_READY_FILE');
$result = ['waiter_seen' => false, 'committed' => false, 'sqlstate' => null, 'error' => null];
$conn = null;

try {
    $params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse((string) getenv('APP_DIRECT_DATABASE_URL'));
    // pdo_pgsql maps ATTR_TIMEOUT to libpq's connect_timeout; DBAL's DSN
    // builder has no connect_timeout of its own. A database that is not
    // there is a quick failure here rather than a parent waiting on a child.
    $params['driverOptions'] = [\PDO::ATTR_TIMEOUT => 5];

    /** @var list<array{0: string, 1: list<string>}> $before */
    $before = json_decode((string) getenv('HOLD_BEFORE'), true, flags: JSON_THROW_ON_ERROR);
    /** @var list<array{0: string, 1: list<string>}> $after */
    $after = json_decode((string) getenv('HOLD_AFTER'), true, flags: JSON_THROW_ON_ERROR);
    $deadline = microtime(true) + (float) getenv('HOLD_DEADLINE_SECONDS');

    $conn = DriverManager::getConnection($params);
    $conn->beginTransaction();
    $conn->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [getenv('HOLD_TENANT')]);
    // HOLD_TABLE was added with `ConfirmSalesOrder`: the same race harness now drives both aggregates,
    // and the holder has to take the lock on the table the request will take it on. It is checked against
    // a literal whitelist rather than interpolated as given -- a table name cannot be a bound parameter,
    // and an env var reaching a statement unchecked is the one shape this file must not have.
    $table = (string) getenv('HOLD_TABLE') ?: 'purchase_order';

    // `invoice` joined with `PayInvoice` (sales step 6, C5): `InvoiceRowLock` is the same `FOR NO KEY UPDATE`, legal for
    // `erp_app` once `Version20260928000400` granted a column UPDATE on the table.
    if (!in_array($table, ['purchase_order', 'sales_order', 'invoice'], true)) {
        throw new RuntimeException('HOLD_TABLE names no aggregate this holder locks: '.$table);
    }

    $conn->executeStatement(sprintf('SELECT 1 FROM %s WHERE id = ?::uuid FOR NO KEY UPDATE', $table), [getenv('HOLD_ORDER')]);

    foreach ($before as [$sql, $bind]) {
        $conn->executeStatement($sql, $bind);
    }

    $pid = (int) $conn->fetchOne('SELECT pg_backend_pid()');
    file_put_contents($readyFile, (string) $pid);

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

    if ($result['waiter_seen']) {
        foreach ($after as [$sql, $bind]) {
            $conn->executeStatement($sql, $bind);
        }

        $conn->commit();
        $result['committed'] = true;
    } else {
        $conn->rollBack();
        $result['error'] = 'No backend ever waited on this one; rolled back.';
    }
} catch (DriverException $e) {
    $result['sqlstate'] = $e->getSQLState();
    $result['error'] = $e->getMessage();
} catch (\Throwable $e) {
    $result['error'] = $e::class.': '.$e->getMessage();
} finally {
    if ($conn instanceof Connection && $conn->isTransactionActive()) {
        $conn->rollBack();
    }
}

// Every path out of the `try` above reaches this line, so the parent always
// gets a signal: the pid once the lock is held, or `failed` if something went
// wrong before the lock was taken.
if ('' === trim((string) @file_get_contents($readyFile))) {
    file_put_contents($readyFile, 'failed');
}

$conn?->close();
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
