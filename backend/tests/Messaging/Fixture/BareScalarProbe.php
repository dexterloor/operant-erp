<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Fixture;

use App\Platform\Messaging\PreAuthenticationMessage;

/** Planted: a bare `string` payload (ARCH M-2 forbids it). */
final readonly class BareScalarProbe implements PreAuthenticationMessage
{
    public function __construct(public string $customerName)
    {
    }
}
