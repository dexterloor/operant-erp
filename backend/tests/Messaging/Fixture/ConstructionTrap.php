<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Fixture;

/** A message-shaped class whose construction is observable: a refusal "before construction" leaves `$constructed` false. */
final class ConstructionTrap
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;

        throw new \LogicException('constructed');
    }
}
