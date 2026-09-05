<?php
// Opens one transaction on the pooled endpoint and holds it for HOLD_SECONDS.
//
// The ready signal goes to a file named by HOLD_READY_FILE, not to stdout.
// A pipe handshake was tried first and produced a false negative: the parent's
// fgets() did not return until the child had already committed, so the
// measurement recorded 11 ms of waiting for a transaction that was over.
require dirname(__DIR__, 3).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

$params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse(getenv('APP_DATABASE_URL'));
$conn = DriverManager::getConnection($params);

$holdSeconds = (float) getenv('HOLD_SECONDS');

$conn->beginTransaction();
$conn->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [getenv('HOLD_TENANT')]);
$pid = $conn->fetchOne('SELECT pg_backend_pid()');

file_put_contents(getenv('HOLD_READY_FILE'), $pid.' '.$holdSeconds);

$conn->fetchOne('SELECT pg_sleep(?)', [$holdSeconds]);
$conn->commit();
$conn->close();
