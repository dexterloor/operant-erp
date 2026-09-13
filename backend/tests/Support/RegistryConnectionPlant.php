<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ConnectionRegistry;
use Doctrine\Persistence\ManagerRegistry;

/**
 * **The routes to a database connection that no `services.yaml` binding can
 * pin — planted here, never registered as a service, so that
 * `ConnectionBindingTest` can show its derivation sees them.**
 *
 * RED review, 2026-09-14: a Sales class taking a `ConnectionRegistry` and
 * calling `getConnection()` walked past A-10's check, and that call returns the
 * default connection, which is the schema owner. `$entityManager` is the
 * negative: the entity manager is pinned to `app`, and it stays allowed.
 */
final readonly class RegistryConnectionPlant
{
    public function __construct(
        public ConnectionRegistry $connections,
        public ManagerRegistry $managers,
        public DriverConnection $driver,
        public EntityManagerInterface $entityManager,
    ) {
    }
}
