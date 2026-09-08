<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;

/**
 * Registered in the test environment only. It records the statement text at the
 * driver layer, which is the last point before the wire and therefore the only
 * place a claim about "the query the application sent" can be made honestly.
 */
final readonly class RecordingMiddleware implements Middleware
{
    public function __construct(private SqlRecorder $recorder)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new RecordingDriver($driver, $this->recorder);
    }
}

/** @internal */
final class RecordingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly SqlRecorder $recorder)
    {
        parent::__construct($driver);
    }

    public function connect(array $params): DriverConnection
    {
        return new RecordingConnection(parent::connect($params), $this->recorder);
    }
}

/** @internal */
final class RecordingConnection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $connection, private readonly SqlRecorder $recorder)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): DriverStatement
    {
        $this->recorder->record($sql);

        return new RecordingStatement(parent::prepare($sql));
    }

    public function query(string $sql): Result
    {
        $this->recorder->record($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        $this->recorder->record($sql);

        return parent::exec($sql);
    }
}

/** @internal */
final class RecordingStatement extends AbstractStatementMiddleware
{
}
