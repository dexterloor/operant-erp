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
 */
final readonly class AccessTokenVerifier
{
    public const ALGORITHM = 'HS256';

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

        if (!\is_string($tenantId) || !\is_string($subject)) {
            return null;
        }

        return ['tenant_id' => $tenantId, 'sub' => $subject];
    }
}
