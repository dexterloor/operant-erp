<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * `SECURITY_MODEL.md` **E-20** — one credential, an absolute lifetime of eight
 * hours from issuance, and nothing extends it.
 *
 * ---------------------------------------------------------------------------
 * What is in the token, and the one claim that is load-bearing
 * ---------------------------------------------------------------------------
 * ADR-005 D-5: **`tenant_id` is a signed JWT claim**, read server-side and
 * passed to `set_config`, and never accepted from a header, query parameter or
 * body (T-1 / API-7). `sub` is the `app_user` id. `iat` and `exp` are E-20's
 * pair and `exp` is `iat` + `LIFETIME_SECONDS` — derived, so no deployment
 * setting and no client-supplied value can reach it, which is two of E-20's
 * three violation clauses closed by construction rather than by a check.
 *
 * There is no `jti` and no refresh claim. D-14 ships neither `RefreshToken` nor
 * `RevokeSession`, there is no session store, and **no path mints a token from a
 * token** — a claim that only a revocation mechanism could use would be a
 * promise this wave does not keep (API-O-13).
 *
 * ---------------------------------------------------------------------------
 * HS256, and the algorithm is pinned on the way *in* as well as out
 * ---------------------------------------------------------------------------
 * `JWT::decode()` is given exactly one algorithm. That is the `alg: none` and
 * algorithm-confusion defence, and it is the reason a symmetric key is safe
 * here: the issuer and the verifier are the same process with the same secret,
 * so an asymmetric key would buy a separation this deployment does not have.
 * `%env(AUTH_TOKEN_SIGNING_KEY)%`, referenced by name (Standing Rule 10).
 *
 * `firebase/php-jwt` validates `exp` and `nbf` itself and raises on a failure;
 * this class turns every failure — expired, malformed, wrong signature, wrong
 * algorithm — into the **same** `null`, because a caller that could tell them
 * apart would eventually report which.
 */
final readonly class AccessTokens
{
    /** E-20: eight hours, absolute. `exp = iat + 8h`, and nothing slides it. */
    public const LIFETIME_SECONDS = 8 * 60 * 60;

    public const ALGORITHM = 'HS256';

    public function __construct(private string $signingKey)
    {
        if (32 > \strlen($signingKey)) {
            throw new \InvalidArgumentException(
                'AUTH_TOKEN_SIGNING_KEY is shorter than 32 bytes. An HS256 key below the digest size '
                .'is the one configuration mistake that leaves every other control in this path intact '
                .'and forgeable.'
            );
        }
    }

    public function issue(string $tenantId, string $userId, \DateTimeImmutable $issuedAt): IssuedToken
    {
        $iat = $issuedAt->getTimestamp();
        $exp = $iat + self::LIFETIME_SECONDS;

        $jwt = JWT::encode([
            'sub' => $userId,
            'tenant_id' => $tenantId,
            'iat' => $iat,
            'exp' => $exp,
        ], $this->signingKey, self::ALGORITHM);

        return new IssuedToken($jwt, (new \DateTimeImmutable('@'.$exp))->setTimezone(new \DateTimeZone('UTC')));
    }

    /** @return array{tenant_id: string, sub: string}|null null for every failure, indistinguishably */
    public function read(string $jwt): ?array
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
