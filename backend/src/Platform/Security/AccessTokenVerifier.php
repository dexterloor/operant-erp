<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * The verifying half of the access token — `SECURITY_MODEL.md` **E-20**: one
 * credential, an absolute lifetime of eight hours from issuance, and nothing
 * extends it.
 *
 * ---------------------------------------------------------------------------
 * Why this is Platform and minting is not (API-O-26, ruled 2026-09-14)
 * ---------------------------------------------------------------------------
 * `ARCHITECTURE.md` §4 splits the two: Platform owns "JWT verification",
 * UserManagement owns "JWT minting/refresh". One class used to do both, and
 * `AuthenticationGate` depending on it was a registered A-1 deviation. The class
 * was split rather than moved whole: every module — `Ai` included — may import
 * `PlatformSecurity`, so a Platform class that could *mint* would make a signed
 * token reachable from the LLM module. This class can only say whether a token
 * is one the application issued. `AccessTokenIssuer` signs, and reads its
 * algorithm off `ALGORITHM` below rather than restating it.
 *
 * ---------------------------------------------------------------------------
 * The one claim that is load-bearing
 * ---------------------------------------------------------------------------
 * ADR-005 D-5: **`tenant_id` is a signed JWT claim**, read server-side and passed
 * to `set_config`, and never accepted from a header, query parameter or body
 * (T-1 / API-7). `sub` is the `app_user` id. Those two are all this returns.
 *
 * ---------------------------------------------------------------------------
 * HS256, and the algorithm is pinned on the way *in*
 * ---------------------------------------------------------------------------
 * `JWT::decode()` is given exactly one algorithm. That is the `alg: none` and
 * algorithm-confusion defence, and it is the reason a symmetric key is safe
 * here: the issuer and the verifier are two classes in the same process holding
 * the same secret, so an asymmetric key would buy a separation this deployment
 * does not have. `%env(AUTH_TOKEN_SIGNING_KEY)%`, referenced by name (Standing
 * Rule 10).
 *
 * `firebase/php-jwt` validates `exp` and `nbf` itself and raises on a failure;
 * this class turns every failure — expired, malformed, wrong signature, wrong
 * algorithm, a missing claim — into the **same** `null`, because a caller that
 * could tell them apart would eventually report which.
 *
 * **But the library validates `exp` only if it is there.** Until the RED review
 * of 2026-09-14 a correctly signed token with no `exp` verified and never
 * expired: E-20's absolute lifetime held only because `AccessTokenIssuer`
 * always sets one. `iat` and `exp` are now required, as integers — E-20's pair,
 * in the form the issuer writes — and their absence is the same `null`.
 *
 * **And `exp` may be no later than `iat` + eight hours (V1-S-4, 2026-09-16).**
 * E-20's *"later than `iat` + 8h"* clause is now checked on the way in rather
 * than resting on the issuer alone. `LIFETIME_SECONDS` lives here, beside
 * `ALGORITHM`, and `AccessTokenIssuer` reads it — the A-1 objection that kept the
 * bound out (a Platform class may not import UserManagement) is avoided by the
 * direction of the import, exactly as `ALGORITHM` already was. A far `exp` still
 * needs the signing key, so this is defence against an issuer misconfiguration.
 *
 * **Any `nbf` is refused.** The issuer never writes one, and `firebase/php-jwt`
 * skips its future-`iat` check whenever `nbf` is present.
 */
final readonly class AccessTokenVerifier
{
    public const ALGORITHM = 'HS256';

    /** E-20: eight hours, absolute. The issuer mints `exp = iat + LIFETIME_SECONDS`; this refuses anything longer. */
    public const LIFETIME_SECONDS = 8 * 60 * 60;

    /** The HS256 digest size. Below it every other control in the path is intact and forgeable. */
    public const MINIMUM_KEY_BYTES = 32;

    public function __construct(private string $signingKey)
    {
        self::refuseAKeyShorterThanTheDigest($signingKey);
    }

    /**
     * Shared with `AccessTokenIssuer`, so the floor and its reason exist once: a
     * short key on the issuer mints forgeable tokens, on the verifier accepts them.
     */
    public static function refuseAKeyShorterThanTheDigest(string $signingKey): void
    {
        if (self::MINIMUM_KEY_BYTES > \strlen($signingKey)) {
            throw new \InvalidArgumentException(
                'AUTH_TOKEN_SIGNING_KEY is shorter than 32 bytes. An HS256 key below the digest size '
                .'is the one configuration mistake that leaves every other control in this path intact '
                .'and forgeable.'
            );
        }
    }

    /** @return array{tenant_id: string, sub: string}|null null for every failure, indistinguishably */
    public function verify(string $jwt): ?array
    {
        try {
            $claims = (array) JWT::decode($jwt, new Key($this->signingKey, self::ALGORITHM));
        } catch (\Throwable) {
            return null;
        }

        $tenantId = $claims['tenant_id'] ?? null;
        $subject = $claims['sub'] ?? null;

        // E-20's pair, as the issuer writes it. `JWT::decode()` checks `exp`
        // only when it is present, so without this a correctly signed token
        // with no `exp` never expires.
        if (!\is_string($tenantId) || !\is_string($subject) || !\is_int($claims['iat'] ?? null) || !\is_int($claims['exp'] ?? null)) {
            return null;
        }

        // E-20's upper bound, and no `nbf` (V1-S-4): see the class docblock.
        if ($claims['exp'] - $claims['iat'] > self::LIFETIME_SECONDS || \array_key_exists('nbf', $claims)) {
            return null;
        }

        return ['tenant_id' => $tenantId, 'sub' => $subject];
    }
}
