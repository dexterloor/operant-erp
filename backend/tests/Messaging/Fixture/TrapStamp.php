<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Fixture;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * A REAL stamp (RED H-2): from 7.4.18 Symfony refuses a stamp header naming a non-stamp class by itself, so a trap that is
 * not a `StampInterface` would be refused for Symfony's reason and prove nothing about ours.
 */
final class TrapStamp implements StampInterface
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;

        throw new \LogicException('constructed');
    }
}
