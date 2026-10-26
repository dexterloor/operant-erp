<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsCredentials;
use App\Modules\UserManagement\Domain\SealsIdentities;

/**
 * Cred-C1 T-C4: counts `check()` calls on the credential seal, so "the MAC is computed on every sign-in path — the hit and
 * both misses" is an assertion and not a reading (`API.md` §7.3; the `CountingKdf` precedent). `CredentialSeal::check()`
 * computes the expected MAC before it inspects the stored value, so one call is one HMAC; a caller that skipped the call on
 * a miss path would read 0 here. Registered `when@test` only; production decorates nothing.
 */
final class CountingSeal implements SealsCredentials, SealsIdentities
{
    private int $checks = 0;

    /** Mail-C2 (T-R16): the redeem's two seal computations, counted apart so the sign-in count above does not move. */
    private int $verifierMatches = 0;

    private int $identityChecks = 0;

    public function __construct(private readonly SealsCredentials&SealsIdentities $inner)
    {
    }

    public function seal(string $tenantId, string $userId, string $passwordHash): string
    {
        return $this->inner->seal($tenantId, $userId, $passwordHash);
    }

    public function check(string $tenantId, string $userId, string $passwordHash, ?string $stored): SealCheck
    {
        ++$this->checks;

        return $this->inner->check($tenantId, $userId, $passwordHash, $stored);
    }

    public function keyId(): string
    {
        return $this->inner->keyId();
    }

    /** Mail-C1: sealing an identity is delegated uncounted; Mail-C2 counts `checkIdentity()` and `verifierMatches()` (T-R16). */
    public function sealIdentity(string $tenantId, string $userId, string $email): string
    {
        return $this->inner->sealIdentity($tenantId, $userId, $email);
    }

    public function checkIdentity(string $tenantId, string $userId, string $email, ?string $stored): SealCheck
    {
        ++$this->identityChecks;

        return $this->inner->checkIdentity($tenantId, $userId, $email, $stored);
    }

    public function macVerifier(string $tenantId, string $userId, string $purpose, string $selector, int $expiresAtUnixSeconds, string $verifierHex): string
    {
        return $this->inner->macVerifier($tenantId, $userId, $purpose, $selector, $expiresAtUnixSeconds, $verifierHex);
    }

    public function verifierMatches(string $tenantId, string $userId, string $purpose, string $selector, int $expiresAtUnixSeconds, string $verifierHex, string $stored): bool
    {
        ++$this->verifierMatches;

        return $this->inner->verifierMatches($tenantId, $userId, $purpose, $selector, $expiresAtUnixSeconds, $verifierHex, $stored);
    }

    public function checks(): int
    {
        return $this->checks;
    }

    public function verifierMatchCount(): int
    {
        return $this->verifierMatches;
    }

    public function identityCheckCount(): int
    {
        return $this->identityChecks;
    }

    public function reset(): void
    {
        $this->checks = 0;
        $this->verifierMatches = 0;
        $this->identityChecks = 0;
    }
}
