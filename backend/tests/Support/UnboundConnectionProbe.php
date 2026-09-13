<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * **A service that takes a `Connection` and has no binding — the thing A-10
 * forbids in `src/`, built here so that what it resolves to is measured rather
 * than argued.**
 *
 * Registered in the test environment only, autowired, with no `arguments:`.
 * The parameter is named `$app` on purpose: it is the name most of the real
 * bindings in `config/services.yaml` use, so this is what one of those classes
 * would receive the day its binding is deleted. `ConnectionBindingTest` asks it
 * which database role it is connected as.
 */
final readonly class UnboundConnectionProbe
{
    public function __construct(public Connection $app)
    {
    }
}
