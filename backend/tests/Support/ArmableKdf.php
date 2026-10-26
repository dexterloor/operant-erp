<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * **Mail-C2 (R1.9): the one armable KDF seam.** Decorates `app.auth.kdf` outside `CountingKdf` (`services.yaml`
 * `when@test`, explicit `decoration_priority`). It does nothing unless a test arms it; when armed it runs the callback on
 * `hash()` only — the redeem's step 6, between the step-4 checks and the step-7 write — never on `verify()`, so a sign-in
 * inside the callback does not re-trigger it. Disarmed after one call and in `tearDown`. Used by T-R5a, T-R5c, T-R6b, T-R10.
 * Production never sees this class.
 */
final class ArmableKdf implements PasswordHasherInterface
{
    /** @var (callable(): void)|null */
    private $onHash = null;

    public function __construct(private readonly PasswordHasherInterface $inner)
    {
    }

    /** @param callable(): void $onHash */
    public function arm(callable $onHash): void
    {
        $this->onHash = $onHash;
    }

    public function disarm(): void
    {
        $this->onHash = null;
    }

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        $hash = $this->inner->hash($plainPassword);
        $callback = $this->onHash;
        $this->onHash = null;
        if (null !== $callback) {
            $callback();
        }

        return $hash;
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        return $this->inner->verify($hashedPassword, $plainPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return $this->inner->needsRehash($hashedPassword);
    }
}
