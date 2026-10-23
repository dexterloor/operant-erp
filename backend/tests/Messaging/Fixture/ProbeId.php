<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Fixture;

/** An identifier value object: the payload rule's permitted shape. */
final readonly class ProbeId
{
    public function __construct(public string $value)
    {
    }
}
