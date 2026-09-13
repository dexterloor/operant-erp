<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Infrastructure\AccessTokenIssuer;
use App\Modules\UserManagement\Infrastructure\PasswordVerifier;
use App\Platform\Security\AccessTokenVerifier;
use App\Tests\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * The two configured values on this path that fail silently when they are wrong,
 * plus the one the component itself got wrong.
 *
 * A dummy at weaker parameters than the live hashes makes the two miss paths
 * measurably faster than the wrong-password path — T-6's timing axis, and the
 * one observable no response assertion can see. An HS256 key below the digest
 * size leaves every other control intact and forgeable. And
 * `NativePasswordHasher::verify()` answers `false` at **zero KDF rounds** for an
 * empty or over-long plaintext, which had the same effect on a different axis:
 * `::theKdfIsNotShortCircuitedByAnEmptyOrOverLongPassword` is the check that was
 * missing.
 */
final class PasswordVerifierTest extends ApiTestCase
{
    /**
     * §7.1 step 5's *"a fixed dummy hash of the same cost factor"*, held by there
     * being one configured value rather than four.
     *
     * bcrypt had one knob and Argon2id has three, so the assertion is over all
     * three — and over the algorithm, which is the knob a bcrypt fallback would
     * have made variable.
     *
     * *Mutation, run:* build the KDF with literal parameters in
     * `PasswordVerifier::kdfFor()` — `new NativePasswordHasher(4, 64 * 1024 * 1024,
     * null, \PASSWORD_ARGON2ID)` — instead of reading them off the dummy. The
     * suite's dummy is `m=1024,t=3`, the factory then produces `m=65536,t=4`, and
     * this is red **twice over**: on the parameter comparison here, and at
     * construction, because `needsRehash($dummy)` becomes true and the container
     * cannot build the service at all. That is exactly the drift the
     * single-parameter design removes.
     */
    #[Test]
    public function theKdfParametersAreReadOffTheDummySoTheDummyAndTheLiveHashesCannotDrift(): void
    {
        $verifier = self::getContainer()->get('test.auth.password_verifier');
        \assert($verifier instanceof PasswordVerifier);

        $dummy = (string) self::getContainer()->getParameter('app.auth.dummy_password_hash');
        $info = password_get_info($dummy);

        self::assertSame(\PASSWORD_ARGON2ID, $info['algo'],
            'The dummy is not Argon2id, so no parameters can be read off it and the store is mixed.');

        $declared = $info['options'];
        $inForce = $verifier->parameters();
        ksort($declared);
        ksort($inForce);
        self::assertSame($declared, $inForce);

        $live = password_get_info($verifier->hash('a passphrase long enough to be accepted'));
        $produced = $live['options'];
        ksort($produced);

        self::assertSame($info['algo'], $live['algo'],
            'A live hash uses a different algorithm than the dummy the miss paths run against. That is the '
            .'mixed store the Argon2id switch closed: the miss paths and the wrong-password path then cost '
            .'different amounts, which is the user-existence oracle E-7 removes.');
        self::assertSame($declared, $produced,
            'A live hash is produced at different parameters than the dummy the miss paths run against, '
            .'which makes an unknown email measurably faster than a wrong password.');
    }

    /**
     * The dummy matches nothing, which is the property the whole substitution
     * rests on: if it ever matched, an unknown email would authenticate.
     *
     * The last two cases are the ones the short-circuit made vacuous: they
     * answered `false` for free rather than because the KDF said so.
     *
     * *Mutation, run:* make `PasswordVerifier::verify()` return the hasher's
     * answer without the `null !== $hash` conjunction, and set the dummy to a
     * hash of a known string; that string then signs in as any unknown user.
     * Red on the second assertion.
     *
     * *Second mutation, run:* drop the `&& $usable` conjunction and let the
     * substituted candidate's answer stand; `UNUSABLE_CANDIDATE` then
     * authenticates against a hash of itself. Red on the last assertion below.
     */
    #[Test]
    public function aMissVerifiesAgainstTheDummyAndAlwaysAnswersFalse(): void
    {
        $verifier = self::getContainer()->get('test.auth.password_verifier');
        \assert($verifier instanceof PasswordVerifier);

        $hash = $verifier->hash('the real password');

        self::assertTrue($verifier->verify($hash, 'the real password'));
        self::assertFalse($verifier->verify($hash, 'something else'));
        self::assertFalse($verifier->verify(null, 'the real password'));
        self::assertFalse($verifier->verify(null, ''));
        self::assertFalse($verifier->verify($hash, ''));
        self::assertFalse($verifier->verify($hash, str_repeat('p', PasswordHasherInterface::MAX_PASSWORD_LENGTH + 1)));

        // The substituted candidate is not a back door. Hashing it and offering
        // an unusable plaintext must still answer false.
        $substitute = (new \ReflectionClass(PasswordVerifier::class))->getConstant('UNUSABLE_CANDIDATE');
        \assert(\is_string($substitute));
        self::assertFalse($verifier->verify($verifier->hash($substitute), ''));
        self::assertTrue($verifier->verify($verifier->hash($substitute), $substitute),
            'The substitute is not a forbidden password — it is only forbidden as a *substitution*. If this '
            .'is red the conjunction is rejecting a legitimate plaintext.');
    }

    /**
     * A parameter that is not an Argon2id hash the component can be built to
     * match is refused at construction, loudly, rather than silently defaulting.
     *
     * **The bcrypt case is the one with teeth.** A verifier that accepted both
     * formats *is* the mixed store, at runtime, forever — so the retired dummy is
     * driven through here to pin that there is no fallback.
     *
     * **Each case asserts *which* guard refused it, and that is not decoration —
     * it is what makes the guards individually falsifiable.** Measured: deleting
     * the algorithm check alone leaves this method **green**, because a bcrypt
     * hash declares no `memory_cost` and the knob check one line down refuses it
     * anyway. The refusal is over-determined, so a bare `expectException` cannot
     * tell the three guards apart and a mutation of any one of them survives.
     *
     * *Mutation, run:* replace the algorithm check in
     * `PasswordVerifier::parametersOf()` with `if (false)`. Without the message
     * assertions this is **green** — measured, and it is why they are here; with
     * them the first two cases are red because the message they get names
     * `memory_cost` instead.
     *
     * *Second mutation, run:* replace the `threads` check with `if (false)`; the
     * third case is red, and the verifier would otherwise hash at `p=1` against a
     * dummy declaring `p=2`.
     *
     * **The missing-knob branch is deliberately not driven from here and is not
     * claimed to be tested:** `password_get_info()` reports all three options for
     * every Argon2id hash it recognises, so no input reaches it today. It is a
     * guard against a future component that reports fewer, and it is stated as
     * unreached rather than counted as covered.
     */
    #[Test]
    public function aDummyTheKdfCannotBeBuiltToMatchIsRefusedAtConstruction(): void
    {
        $refused = [
            'not a hash at all' => ['not a hash at all', 'not an Argon2id hash'],
            // The test dummy this project ran on until 2026-09-10.
            'the retired bcrypt dummy' => ['$2y$04$kce4oF.FRXilQx4aZL.rOevBh72CH1EfFWRG4auT/dh.TdE.oGwZa', 'not an Argon2id hash'],
            // NativePasswordHasher pins threads to 1 and takes no argument for
            // it, so this dummy is unbuildable rather than merely unusual.
            'argon2id at p=2' => ['$argon2id$v=19$m=1024,t=3,p=2$VHI3bmJZZUIudFF3MDB4Sg$eewE/E8Yvk7d/WPEVdqbJVmrVw4AyOKiQ0W4FudFr4o', 'threads other than 1'],
        ];

        foreach ($refused as $label => [$dummy, $because]) {
            try {
                new PasswordVerifier($dummy);
                self::fail(\sprintf('"%s" was accepted as app.auth.dummy_password_hash.', $label));
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString($because, $refusal->getMessage(), \sprintf(
                    '"%s" was refused, but by a different guard than the one this case exists to pin. The '
                    .'refusal is over-determined, so a message that moves means a guard that stopped '
                    .'working without anything going red.', $label));
            }
        }
    }

    /**
     * A KDF that disagrees with the dummy is refused, and the disagreement is
     * detected by asking the **component** rather than by comparing numbers.
     *
     * This is what stops the injected-hasher seam — which exists so the test
     * environment can count invocations — from becoming a second source of truth.
     *
     * *Mutation, run:* delete the `needsRehash($dummyHash)` guard from
     * `PasswordVerifier::__construct()`; a verifier hashing at `m=65536` against a
     * dummy at `m=1024` is accepted, the miss paths get 60× cheaper than the
     * wrong-password path, and this is red.
     */
    #[Test]
    public function aKdfThatDisagreesWithTheDummyIsRefused(): void
    {
        $dummy = (string) self::getContainer()->getParameter('app.auth.dummy_password_hash');
        $other = '$argon2id$v=19$m=65536,t=4,p=1$S1F6OVZrRTBnS2lkd3ZhMg$m0ZB1phEShmbpBMRp34kto63MuUEw8yUM3BmLe07MT0';

        self::assertNotSame(
            password_get_info($dummy)['options'],
            password_get_info($other)['options'],
            'The two fixtures no longer differ, so the negative below is vacuous.'
        );

        // Same class, built from a dummy at other parameters.
        $this->expectException(\InvalidArgumentException::class);

        new PasswordVerifier($dummy, PasswordVerifier::kdfFor($other));
    }

    /**
     * **Fake-green eighteen, closed and kept closed.**
     *
     * `NativePasswordHasher::verify()` opens with
     * `if ('' === $plainPassword || $this->isPasswordTooLong($plainPassword)) return false;`
     * — above every algorithm branch — so it answers `false` at **zero rounds**
     * for two inputs the caller picks. Measured at production cost, Argon2id
     * `m=65536,t=4,p=1`: wrong password 223.265 ms, EMPTY 0.000 ms, OVER-LONG
     * 0.000 ms. **Argon2id does not fix it** — that was measured rather than
     * assumed, and bcrypt at cost 4 behaves identically.
     *
     * The consequence was not an E-7 break (an empty password is attacker-chosen
     * and identical for every account, so it discloses nothing about a tenant or
     * a user) but the loss of the **constant-cost premise**, and it made
     * `API.md` §7.3's *"exactly one KDF invocation on every path"* false as
     * written.
     *
     * This method measures rather than counting, deliberately: a call count taken
     * anywhere above `password_verify()` cannot tell a call that ran the KDF from
     * one the delegate refused, so the count in `CountingKdf` and this
     * measurement are checking different things and neither subsumes the other.
     * It also builds its own verifier from the configured dummy rather than
     * taking the container's, so it measures the **production** wiring — no
     * counting decorator, no guard — and stays independent of them.
     *
     * *Mutation, run:* delete the `$usable`/`$candidate` substitution from
     * `PasswordVerifier::verify()` and pass `$plaintext` straight through; both
     * halves of the first assertion group collapse to ~0 ms and this is red.
     */
    #[Test]
    public function theKdfIsNotShortCircuitedByAnEmptyOrOverLongPassword(): void
    {
        $dummy = (string) self::getContainer()->getParameter('app.auth.dummy_password_hash');
        $verifier = new PasswordVerifier($dummy);
        $hash = $verifier->hash('a passphrase long enough to be accepted');
        $overLong = str_repeat('p', PasswordHasherInterface::MAX_PASSWORD_LENGTH + 1);

        $wrong = self::medianMillis(static fn () => $verifier->verify($hash, 'not the passphrase at all'));
        $empty = self::medianMillis(static fn () => $verifier->verify($hash, ''));
        $over = self::medianMillis(static fn () => $verifier->verify($hash, $overLong));

        // A quarter of a real verify is a floor with four orders of magnitude of
        // headroom: the short-circuit measures 0.000 ms against 2.19 ms of KDF at
        // the suite's parameters. It is a ratio and not a figure so that retuning
        // the test dummy cannot make it wrong.
        $floor = $wrong / 4;

        self::assertGreaterThan($floor, $empty, \sprintf(
            'An empty password answered in %.4f ms against %.4f ms for a wrong one. The KDF was skipped, so '
            .'this endpoint costs what the caller chooses and every "exactly one invocation" claim about it '
            .'is false.', $empty, $wrong));
        self::assertGreaterThan($floor, $over, \sprintf(
            'An over-long password answered in %.4f ms against %.4f ms for a wrong one. Same defect, other '
            .'end of the range.', $over, $wrong));

        // And the reason the substitution above has to exist: the component
        // underneath still short-circuits. If this half ever goes red because
        // Symfony started running the KDF for these inputs, the floor in
        // `PasswordVerifier::verify()` has stopped being load-bearing and should
        // be removed deliberately rather than left as decoration.
        $bare = PasswordVerifier::kdfFor($dummy);
        $bareWrong = self::medianMillis(static fn () => $bare->verify($hash, 'not the passphrase at all'));
        $bareEmpty = self::medianMillis(static fn () => $bare->verify($hash, ''));

        self::assertLessThan($bareWrong / 4, $bareEmpty, \sprintf(
            'NativePasswordHasher::verify() no longer short-circuits the empty string (%.4f ms against '
            .'%.4f ms). That is good news and it makes the floor in PasswordVerifier::verify() redundant.',
            $bareEmpty, $bareWrong));
    }

    /**
     * *Mutation, run:* delete the floor call from `AccessTokenVerifier::__construct()`;
     * a one-character signing key is accepted and this is red. (The issuer's own
     * floor is `AccessTokenSplitTest::theIssuerRefusesAKeyShorterThanTheDigest`.)
     */
    #[Test]
    public function aSigningKeyShorterThanTheDigestIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AccessTokenVerifier('too short');
    }

    /**
     * The algorithm is pinned on the way in as well as out. `firebase/php-jwt`
     * takes the accepted algorithm as an argument, and a token signed with a
     * different one — or with none — must not decode.
     *
     * *Mutation, run (against the pre-split `AccessTokens::read()`):* widen it to
     * accept `['HS256', 'none']`; the unsigned token decodes and this is red. The
     * same pin is now `AccessTokenVerifier::verify()`, and the right key under
     * another HMAC is `AccessTokenSplitTest::theRightKeyUnderAnotherAlgorithmIsRefused`.
     */
    #[Test]
    public function aTokenSignedWithAnotherAlgorithmIsNotRead(): void
    {
        $issuer = self::getContainer()->get('test.auth.token_issuer');
        \assert($issuer instanceof AccessTokenIssuer);
        $tokens = self::getContainer()->get('test.auth.token_verifier');
        \assert($tokens instanceof AccessTokenVerifier);

        $good = $issuer->issue('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', new \DateTimeImmutable());
        self::assertNotNull($tokens->verify($good->jwt), 'A token the issuer just minted did not verify, so every negative below is vacuous.');

        [, $payload] = explode('.', $good->jwt, 3);
        $unsigned = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '=').'.'.$payload.'.';

        self::assertNull($tokens->verify($unsigned));
        self::assertNull($tokens->verify('not a token'));
        self::assertNull($tokens->verify(''));
    }

    /**
     * The median of nine runs, in milliseconds. A median rather than a mean
     * because one scheduler hiccup in nine should not move the figure, and nine
     * rather than three because at the suite's Argon2id parameters a run costs
     * about 2 ms and the whole method is under 50 ms.
     */
    private static function medianMillis(callable $work, int $runs = 9): float
    {
        $samples = [];

        for ($i = 0; $i < $runs; ++$i) {
            $started = hrtime(true);
            $work();
            $samples[] = (hrtime(true) - $started) / 1e6;
        }

        sort($samples);

        return $samples[intdiv($runs, 2)];
    }
}
