<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Modules\UserManagement\Domain\VerifiesPasswords;

/**
 * T-6 and `API.md` §7.3 make the **number of KDF invocations** an observable:
 * *"exactly one, on every path, against a fixed dummy hash on either miss"*, and
 * §7.3's second mutation — *"move the `permitsLogin()` check ahead of the KDF;
 * the hasher-invocation assertion on the non-active branch goes to zero"* — is
 * only runnable if something counts.
 *
 * A count is not decidable from a response body, a status code or a SQL log, so
 * it is decorated here rather than inferred. Registered in `when@test` only,
 * exactly as `RecordingMiddleware` is: production wires
 * `App\Modules\UserManagement\Infrastructure\PasswordVerifier` directly.
 */
final class CountingPasswordVerifier implements VerifiesPasswords
{
    private int $invocations = 0;

    public function __construct(private readonly VerifiesPasswords $inner)
    {
    }

    public function verify(?string $hash, string $plaintext): bool
    {
        ++$this->invocations;

        return $this->inner->verify($hash, $plaintext);
    }

    public function needsRehash(string $hash): bool
    {
        return $this->inner->needsRehash($hash);
    }

    public function hash(string $plaintext): string
    {
        // Deliberately NOT counted. Seeding a fixture hashes passwords, and a
        // counter that moved when a test set something up would make every
        // "exactly once" assertion a statement about the fixture.
        return $this->inner->hash($plaintext);
    }

    public function reset(): void
    {
        $this->invocations = 0;
    }

    public function invocations(): int
    {
        return $this->invocations;
    }
}
