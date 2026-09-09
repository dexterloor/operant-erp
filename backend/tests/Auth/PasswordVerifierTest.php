<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\Infrastructure\AccessTokens;
use App\Auth\Infrastructure\PasswordVerifier;
use App\Tests\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two configured values on this path that fail silently when they are wrong.
 *
 * A dummy hash at a lower cost than the live hashes makes the two miss paths
 * measurably faster than the wrong-password path — T-6's timing axis, and the
 * one observable no response assertion can see. An HS256 key below the digest
 * size leaves every other control intact and forgeable.
 */
final class PasswordVerifierTest extends ApiTestCase
{
    /**
     * §7.1 step 5's *"a fixed dummy hash of the same cost factor"*, held by there
     * being one configured value rather than two.
     *
     * *Mutation, run:* construct `NativePasswordHasher` with a literal cost —
     * `new NativePasswordHasher(null, null, 12, \PASSWORD_BCRYPT)` — instead of
     * `$this->cost`. The suite's dummy is cost 4, the verifier then produces
     * cost 12, and this is red. That is exactly the drift the single-parameter
     * design removes.
     */
    #[Test]
    public function theCostFactorIsReadOffTheDummySoTheDummyAndTheLiveHashesCannotDrift(): void
    {
        $verifier = self::getContainer()->get('test.auth.password_verifier');
        \assert($verifier instanceof PasswordVerifier);

        $dummy = (string) self::getContainer()->getParameter('app.auth.dummy_password_hash');
        $info = password_get_info($dummy);

        self::assertSame(\PASSWORD_BCRYPT, $info['algo'], 'The dummy is not bcrypt, so no cost can be read off it.');
        self::assertSame($info['options']['cost'], $verifier->cost());

        $live = password_get_info($verifier->hash('anything at all'));
        self::assertSame($info['algo'], $live['algo'], 'The dummy and the live hashes use different algorithms.');
        self::assertSame($info['options']['cost'], $live['options']['cost'],
            'A live hash is produced at a different cost than the dummy the miss paths run against, which '
            .'makes an unknown email measurably faster than a wrong password.');
    }

    /**
     * The dummy matches nothing, which is the property the whole substitution
     * rests on: if it ever matched, an unknown email would authenticate.
     *
     * *Mutation, run:* make `PasswordVerifier::verify()` return the hasher's
     * answer without the `null !== $hash` conjunction, and set the dummy to a
     * hash of a known string; that string then signs in as any unknown user.
     * Red on the second assertion.
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
    }

    /**
     * A parameter that is not a bcrypt hash is refused at construction, loudly,
     * rather than silently defaulting the cost.
     *
     * *Mutation, run:* delete the guard from `PasswordVerifier::__construct()`;
     * `password_get_info()` returns `algo: null`, the hasher is built with a null
     * cost, and the dummy no longer shares a cost with anything. Red.
     */
    #[Test]
    public function aDummyThatIsNotABcryptHashIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PasswordVerifier('not a hash at all');
    }

    /**
     * *Mutation, run:* delete the length guard from `AccessTokens::__construct()`;
     * a one-character signing key is accepted and this is red.
     */
    #[Test]
    public function aSigningKeyShorterThanTheDigestIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AccessTokens('too short');
    }

    /**
     * The algorithm is pinned on the way in as well as out. `firebase/php-jwt`
     * takes the accepted algorithm as an argument, and a token signed with a
     * different one — or with none — must not decode.
     *
     * *Mutation, run:* widen `AccessTokens::read()` to accept `['HS256', 'none']`;
     * the unsigned token decodes and this is red.
     */
    #[Test]
    public function aTokenSignedWithAnotherAlgorithmIsNotRead(): void
    {
        $tokens = self::getContainer()->get('test.auth.access_tokens');
        \assert($tokens instanceof AccessTokens);

        $good = $tokens->issue('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', new \DateTimeImmutable());
        self::assertNotNull($tokens->read($good->jwt), 'A token this class just minted did not read back, so every negative below is vacuous.');

        [, $payload] = explode('.', $good->jwt, 3);
        $unsigned = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '=').'.'.$payload.'.';

        self::assertNull($tokens->read($unsigned));
        self::assertNull($tokens->read('not a token'));
        self::assertNull($tokens->read(''));
    }
}
