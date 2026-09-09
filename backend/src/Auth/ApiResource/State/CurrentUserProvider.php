<?php

declare(strict_types=1);

namespace App\Auth\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Problem\ApiProblem;
use App\Auth\ApiResource\CurrentUserResource;
use App\Auth\Application\CurrentUserReader;
use App\Auth\Infrastructure\AccessTokens;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GetCurrentUser`.
 *
 * ---------------------------------------------------------------------------
 * The token is read here, and this is the whole of the authenticated surface
 * ---------------------------------------------------------------------------
 * `/me` is the only operation this wave ships that requires a token, so the
 * bearer read lives with it rather than in a firewall. That is a scope decision
 * with a named consequence: **there is no request-level authentication gate over
 * the rest of `/api/v1`**, and the other six operations still resolve their
 * tenant through `App\Api\Tenancy\CurrentTenant`, which fails closed. Wiring
 * that seam to this token without a permission check would ship half of an
 * enforcement design — E-1 puts the permission check *before* the row is loaded,
 * and there is no check to put there yet. Recorded in `API.md` §14.2.
 *
 * ---------------------------------------------------------------------------
 * Every refusal is the same refusal
 * ---------------------------------------------------------------------------
 * Absent header, wrong scheme, malformed token, bad signature, wrong algorithm,
 * expired, a `sub` that no longer resolves — all of them are
 * `authentication-failed`, the same constant object `IssueToken` throws. E-7 is
 * about the sign-in path, but the reasoning generalises for free here: a client
 * that could tell *"expired"* from *"revoked user"* would eventually be told
 * which users exist.
 *
 * **A soft-deleted user's live token stops working**, because
 * `CurrentUserReader` applies `deleted_at IS NULL`. There is no revocation
 * (API-O-13) and this is not one — it is the read failing, on this one
 * operation.
 *
 * @implements ProviderInterface<CurrentUserResource>
 */
final readonly class CurrentUserProvider implements ProviderInterface
{
    public function __construct(private AccessTokens $tokens, private CurrentUserReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CurrentUserResource
    {
        $request = $context['request'] ?? null;

        if (!$request instanceof Request) {
            throw ApiProblem::authenticationFailed();
        }

        $claims = $this->tokens->read($this->bearer($request) ?? throw ApiProblem::authenticationFailed());

        if (null === $claims) {
            throw ApiProblem::authenticationFailed();
        }

        return $this->reader->read($claims['tenant_id'], $claims['sub']) ?? throw ApiProblem::authenticationFailed();
    }

    /**
     * RFC 9110 makes the scheme token case-insensitive, so the comparison is
     * too. Nothing else is accepted: no `access_token` query parameter and no
     * body field — a token in a URL is written to access logs, proxy logs and
     * this project's own `RecordingMiddleware` fixtures, which is the argument
     * `LEAD` used to withdraw the body-field form of `RefreshToken` (D-14).
     */
    private function bearer(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if (!\is_string($header) || 1 !== preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
