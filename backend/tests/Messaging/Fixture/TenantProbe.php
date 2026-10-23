<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Fixture;

use App\Platform\Messaging\TenantMessage;

/** A planted workspace message. Never in `MessageCatalogue::ALL`. */
final readonly class TenantProbe implements TenantMessage
{
    public function __construct(public ProbeId $id)
    {
    }
}
