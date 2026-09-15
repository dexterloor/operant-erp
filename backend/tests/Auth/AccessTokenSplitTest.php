<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Infrastructure\AccessTokenIssuer;
use App\Platform\Security\AccessTokenVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * API-O-26, as ruled on 2026-09-14: minting and verifying are two classes on two
 * sides of the A-1 line, and they must still agree on everything a token is.
 *
 * Before the split one class held the key and the algorithm for both directions,
 * so agreement was true by construction. After it, two classes hold the key and
 * one of them borrows the other's algorithm constant — so agreement is now a
 * claim, and these are the checks that make it one.
 *
 * Every negative here carries its positive control **inside the same test**: a
 * verifier that returns `null` for everything passes every negative, and a sibling
 * test does not protect a `--filter` run.
 */
final class AccessTokenSplitTest extends KernelTestCase
{
    /**
     * A test literal, not a secret. 65 bytes rather than the 32-byte floor:
     * `firebase/php-jwt` refuses to *sign* HS512 under a key shorter than its
     * digest, and the algorithm-confusion fixture below must be signable.
     */
    private const KEY_A = 'test-only-signing-key-A-0123456789abcdefghijklmnopqrstuvwxyz01234';

    private const KEY_B = 'test-only-signing-key-B-0123456789abcdefghijklmnopqrstuvwxyz01234';

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const USER = '22222222-2222-4222-8222-222222222222';

    /**
     * The production wiring: the issuer `SignIn` holds and the verifier
     * `AuthenticationGate` holds, as the container builds them, each bound to
     * `AUTH_TOKEN_SIGNING_KEY` by name.
     *
     * *Mutation, run:* make `AccessTokenIssuer::issue()` sign with `'HS512'`
     * instead of `AccessTokenVerifier::ALGORITHM`; the verifier refuses every
     * token sign-in issues, and this is red.
     *
     * *Mutation, run:* bind the issuer's `$signingKey` in `config/services.yaml`
     * to a different environment variable; red.
     */
    #[Test]
    public function aTokenTheIssuerMintsIsReadByTheGatesVerifier(): void
    {
        $issuer = self::getContainer()->get('test.auth.token_issuer');
        \assert($issuer instanceof AccessTokenIssuer);
        $verifier = self::getContainer()->get('test.auth.token_verifier');
        \assert($verifier instanceof AccessTokenVerifier);

        $token = $issuer->issue(self::TENANT, self::USER, new \DateTimeImmutable());

        self::assertSame(
            ['tenant_id' => self::TENANT, 'sub' => self::USER],
            $verifier->verify($token->jwt),
            'A token the application issued is not verified by the application. After API-O-26 the issuer '
            .'and the verifier are two classes, and nothing but this check holds them to one key and one '
            .'algorithm.',
        );
    }

    /**
     * The algorithm is pinned on the way in: the right key under another HMAC is
     * still refused. This is the algorithm-confusion defence, stated with a
     * fixture that can express it — the same key, a valid signature, only the
     * algorithm differs.
     *
     * *Mutation, run:* have `AccessTokenVerifier::verify()` build its `Key` from
     * the token header's own `alg`; the HS512 token verifies, and this is red.
     */
    #[Test]
    public function theRightKeyUnderAnotherAlgorithmIsRefused(): void
    {
        $verifier = new AccessTokenVerifier(self::KEY_A);
        $claims = ['sub' => self::USER, 'tenant_id' => self::TENANT, 'iat' => time(), 'exp' => time() + 60];

        self::assertNotNull(
            $verifier->verify(JWT::encode($claims, self::KEY_A, AccessTokenVerifier::ALGORITHM)),
            'The positive control did not verify, so the refusal below proves nothing.',
        );

        self::assertNull($verifier->verify(JWT::encode($claims, self::KEY_A, 'HS512')));
    }

    /**
     * *Mutation, run:* have `AccessTokenVerifier::verify()` decode the payload
     * without checking the signature; the token signed under the other key
     * verifies, and this is red.
     */
    #[Test]
    public function aTokenSignedWithAnotherKeyIsRefused(): void
    {
        $token = (new AccessTokenIssuer(self::KEY_A))->issue(self::TENANT, self::USER, new \DateTimeImmutable());

        self::assertNotNull((new AccessTokenVerifier(self::KEY_A))->verify($token->jwt),
            'The positive control did not verify, so the refusal below proves nothing.');

        self::assertNull((new AccessTokenVerifier(self::KEY_B))->verify($token->jwt));
    }

    /**
     * An expired token is the same `null` — `exp` is validated on the way in,
     * which is the whole of E-20's enforcement on the verifying side.
     *
     * *Mutation, run:* set `JWT::$leeway` to a day inside `verify()`; red.
     */
    #[Test]
    public function anExpiredTokenIsRefused(): void
    {
        $issuer = new AccessTokenIssuer(self::KEY_A);
        $verifier = new AccessTokenVerifier(self::KEY_A);

        self::assertNotNull($verifier->verify($issuer->issue(self::TENANT, self::USER, new \DateTimeImmutable())->jwt),
            'The positive control did not verify, so the refusal below proves nothing.');

        $lapsed = new \DateTimeImmutable('@'.(time() - AccessTokenVerifier::LIFETIME_SECONDS - 60));
        self::assertNull($verifier->verify($issuer->issue(self::TENANT, self::USER, $lapsed)->jwt));
    }

    /**
     * **A correctly signed token with no lifetime is refused** — RED review,
     * 2026-09-14. `firebase/php-jwt` checks `exp` only *if it is present*, so a
     * token signed with the right key and carrying no `exp` verified, for ever.
     * E-20's eight-hour absolute lifetime held only because the issuer always
     * sets `exp`; E-20's violation clause names *"an `exp` that is absent"*, and
     * the row defines `exp` as `iat` + 8h, so both halves of the pair must be
     * there, and be integers, as `AccessTokenIssuer` writes them.
     *
     * Every refusal is the same `null` as every other failure. The positive
     * control — the same claims with both present — is inside this test.
     */
    #[Test]
    public function aSignedTokenWithoutAnIntegerIatAndExpIsRefused(): void
    {
        $verifier = new AccessTokenVerifier(self::KEY_A);
        $now = time();
        $sign = static fn (array $claims): string => JWT::encode(
            ['sub' => self::USER, 'tenant_id' => self::TENANT] + $claims, self::KEY_A, AccessTokenVerifier::ALGORITHM,
        );

        self::assertSame(['tenant_id' => self::TENANT, 'sub' => self::USER], $verifier->verify($sign(['iat' => $now, 'exp' => $now + 60])),
            'The positive control did not verify, so the refusals below prove nothing.');

        foreach ([
            'no exp' => ['iat' => $now],
            'no iat' => ['exp' => $now + 60],
            'neither' => [],
            'exp a numeric string' => ['iat' => $now, 'exp' => (string) ($now + 60)],
            'exp a float' => ['iat' => $now, 'exp' => $now + 60.5],
            'iat a numeric string' => ['iat' => (string) $now, 'exp' => $now + 60],
            'exp null' => ['iat' => $now, 'exp' => null],
        ] as $case => $claims) {
            self::assertNull($verifier->verify($sign($claims)), 'A correctly signed token was verified with '.$case.'.');
        }
    }

    /**
     * **V1-S-4 (V4 B3b, 2026-09-16): E-20's upper bound is checked on the way in,
     * and `nbf` is refused.**
     *
     * The verifier required integer `iat` and `exp` but not `exp - iat <= 8h`, so
     * a correctly signed token with a far `exp` verified until then; E-20's
     * violation clause names *"an `exp` later than `iat` + 8h"*. And
     * `firebase/php-jwt` skips its future-`iat` check whenever `nbf` is present,
     * while the issuer never writes one — so any `nbf` is refused.
     *
     * Eight hours is spelled here independently of
     * `AccessTokenVerifier::LIFETIME_SECONDS`, so a change to the constant is red
     * here rather than compared with itself. The boundary (exactly 8h) is the
     * positive control, inside the test.
     *
     * *Mutation:* drop the bound from `verify()` → the `8h + 1` case is red.
     */
    #[Test]
    public function aSignedTokenLivingLongerThanEightHoursOrCarryingNbfIsRefused(): void
    {
        $verifier = new AccessTokenVerifier(self::KEY_A);
        $now = time();
        $eightHours = 8 * 60 * 60;
        $sign = static fn (array $claims): string => JWT::encode(
            ['sub' => self::USER, 'tenant_id' => self::TENANT] + $claims, self::KEY_A, AccessTokenVerifier::ALGORITHM,
        );

        self::assertSame(['tenant_id' => self::TENANT, 'sub' => self::USER],
            $verifier->verify($sign(['iat' => $now, 'exp' => $now + $eightHours])),
            'exp = iat + 8h is E-20 exactly and must verify, or the refusals below prove nothing.');

        self::assertNull($verifier->verify($sign(['iat' => $now, 'exp' => $now + $eightHours + 1])),
            'A correctly signed token with exp = iat + 8h + 1 verified: E-20\'s absolute lifetime is not bounded on the way in.');

        self::assertNull($verifier->verify($sign(['iat' => $now, 'exp' => $now + 60, 'nbf' => $now - 60])),
            'A correctly signed token carrying nbf verified. The issuer never writes one, and its presence disables the '
            .'library\'s future-iat check.');
    }

    /**
     * The key floor is on both sides. A short key on the issuer alone mints
     * forgeable tokens; on the verifier alone it accepts them.
     *
     * *Mutation, run:* delete the floor call from `AccessTokenIssuer::__construct()`;
     * red. (The verifier's own floor is
     * `PasswordVerifierTest::aSigningKeyShorterThanTheDigestIsRefused`.)
     */
    #[Test]
    public function theIssuerRefusesAKeyShorterThanTheDigest(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AccessTokenIssuer('too short');
    }
}
