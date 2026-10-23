<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

/**
 * A message dispatched **before** anyone has signed in (Mail-C0; design §4.1). It carries no `TenantIdStamp`: it names
 * its workspace by slug in its payload, and the worker re-resolves it (ARCHITECTURE.md §6.4 M-1 exemption). Every
 * {@see MessageCatalogue} class implements exactly one of this and {@see TenantMessage}.
 */
interface PreAuthenticationMessage
{
}
