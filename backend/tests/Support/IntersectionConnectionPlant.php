<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * **A `Connection` reached through an intersection type and through a DNF type — planted here, never registered as a
 * service — so that `ConnectionBindingTest` can show its constructor walk sees both** (V3-2 / V1-R-12 route B5).
 *
 * Before 2026-09-16 the walk read `ReflectionNamedType` and `ReflectionUnionType` only, so `Connection&\Countable` was
 * invisible to A-10's check. `$count` is the negative: an intersection that does not contain a connection type.
 */
final readonly class IntersectionConnectionPlant
{
    public function __construct(
        public Connection&\Countable $intersection,
        public (Connection&\Countable)|null $dnf,
        public \Countable&\ArrayAccess $count,
    ) {
    }
}
