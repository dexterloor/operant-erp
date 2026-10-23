<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Modules\UserManagement\Domain\SealsCredentials;

/**
 * Cred-C1 (final RED M-1 of the design's first RED): **one** way a fixture gives a person a password that signs in — the
 * hash AND its seal, both columns, through the container's own `SealsCredentials` (the key the kernel under test holds).
 * A fixture that wrote `password_hash` alone would now be a transplanted credential, refused at sign-in and at
 * `ChangePassword`. Rows for actors who never sign in keep `password_mac` NULL, as production's unusable credentials do.
 *
 * The using class must offer `insertAsOwnerForTenant()` (every `TenancyTestCase` does).
 */
trait CredentialFixtures
{
    /** @return string the hash written */
    protected function setPassword(string $tenantId, string $userId, string $password): string
    {
        $hash = $this->fixtureKdf()->hash($password);
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE app_user SET password_hash = ?, password_mac = ? WHERE id = ?::uuid',
            [$hash, $this->sealFor($tenantId, $userId, $hash), $userId]);

        return $hash;
    }

    /** The seal the server under test would write for this tuple. */
    protected function sealFor(string $tenantId, string $userId, string $hash): string
    {
        $seal = self::getContainer()->get('test.auth.credential_seal');
        \assert($seal instanceof SealsCredentials);

        return $seal->seal($tenantId, $userId, $hash);
    }

    private function fixtureKdf(): CountingKdf
    {
        $kdf = self::getContainer()->get(CountingKdf::class);
        \assert($kdf instanceof CountingKdf);

        return $kdf;
    }
}
