<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

/**
 * The seal refused its declared key id (Mail-C1, G-75 T-G75-2): `AUTH_CREDENTIAL_MAC_KEY_ID` is not the key's id, or
 * production has none. Its own class so `app:credentials:check-key` can answer exit 3 rather than crash.
 */
final class CredentialKeyIdRefused extends \InvalidArgumentException
{
}
