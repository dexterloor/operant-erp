<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Fixture;

use App\Platform\Messaging\PreAuthenticationMessage;
use App\Platform\Messaging\TenantMessage;

/** Planted: implements both markers, so the one-marker rule must refuse it. */
final readonly class BothMarkersProbe implements PreAuthenticationMessage, TenantMessage
{
}
