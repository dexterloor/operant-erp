<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * T-6 and `API.md` §7.3 make the **number of KDF invocations** an observable:
 * *"exactly one, on every path, against a fixed dummy hash on either miss"*, and
 * §7.3's second mutation — *"move the `permitsLogin()` check ahead of the KDF;
 * the hasher-invocation assertion on the non-active branch goes to zero"* — is
 * only runnable if something counts.
 *
 * ---------------------------------------------------------------------------
 * Why this sits at the KDF boundary and not at `VerifiesPasswords` — fake-green
 * eighteen
 * ---------------------------------------------------------------------------
 * It used to be `CountingPasswordVerifier`, a decorator of `VerifiesPasswords`
 * wrapping `PasswordVerifier` from **outside**. It incremented and delegated, so
 * it counted **wrapper calls, not KDF executions** — and
 * `NativePasswordHasher::verify()` returns `false` at **zero rounds** for an
 * empty or over-long plaintext. The counter therefore read `1` while nothing had
 * run, which made
 * `PasswordChangeTest::theKdfRunsExactlyOnceOnEveryPathThatReachesIt`
 * green-by-construction on its two empty-password cases. **A counter that cannot
 * see the work it counts is the defect, not the symptom.**
 *
 * Moving it down one level is necessary but not sufficient: a call count taken
 * *anywhere* above `password_verify()` still cannot distinguish a call that ran
 * the KDF from one the delegate refused. What closes the gap is the **guard**
 * below. `PasswordVerifier::verify()` now substitutes a fixed, in-range
 * candidate for any plaintext the delegate would refuse, so **every** call
 * arriving here runs the KDF — and this class throws if that invariant is ever
 * broken rather than counting a call that did nothing.
 *
 * So the equation *one call = one KDF execution* is stated once, in the one
 * place whose violation is a loud test failure. Delete the floor from
 * `PasswordVerifier::verify()` and the empty-password cases raise here instead
 * of quietly counting.
 *
 * Registered in `when@test` only, exactly as `RecordingMiddleware` is:
 * production decorates nothing and `PasswordVerifier` builds its own KDF.
 */
final class CountingKdf implements PasswordHasherInterface
{
    private int $invocations = 0;

    public function __construct(private readonly PasswordHasherInterface $inner)
    {
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        if ('' === $plainPassword || \strlen($plainPassword) > PasswordHasherInterface::MAX_PASSWORD_LENGTH) {
            throw new \LogicException(\sprintf(
                'A candidate of %d bytes reached the KDF seam. NativePasswordHasher::verify() answers '
                .'false at zero rounds for the empty string and above %d bytes, so counting this call '
                .'would report work that never happened — which is the fake-green this class was rebuilt '
                .'to end. PasswordVerifier::verify() is supposed to have substituted an in-range '
                .'candidate before this point.',
                \strlen($plainPassword),
                PasswordHasherInterface::MAX_PASSWORD_LENGTH,
            ));
        }

        ++$this->invocations;

        return $this->inner->verify($hashedPassword, $plainPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        // No KDF runs: `password_needs_rehash()` reads the encoded parameters.
        return $this->inner->needsRehash($hashedPassword);
    }

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        // Deliberately NOT counted, and this survives the move down. Seeding a
        // fixture hashes passwords, and a counter that moved when a test set
        // something up would make every "exactly once" assertion a statement
        // about the fixture. The consequence is worth stating, because a
        // change-password request runs the KDF **twice** — once through
        // `verify()` and once here — and only the first is observable.
        return $this->inner->hash($plainPassword);
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
