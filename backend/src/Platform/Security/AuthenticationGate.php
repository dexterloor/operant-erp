<?php

declare(strict_types=1);

namespace App\Platform\Security;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\UserManagement\Infrastructure\AccessTokens;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;

/**
 * The request-level authentication gate over `/api/v1` — `API.md` §14.2 item 25,
 * closed.
 *
 * ---------------------------------------------------------------------------
 * What was here before, and why it could not simply be wired
 * ---------------------------------------------------------------------------
 * `/me` read its own bearer token inside its state provider and the six `/items`
 * operations resolved tenant through `CurrentTenant`, which failed closed but
 * authenticated nothing. §14.2 item 25 recorded why the seam was left open:
 * *"wiring that seam to the token without a permission check would ship half of
 * an enforcement design — E-1 puts the permission check before the row is loaded
 * and there is no check to put there yet."* `PermissionGate` is that check, and
 * it runs immediately after this listener on the same event.
 *
 * ---------------------------------------------------------------------------
 * The domain is the row's own Path, and it is derived
 * ---------------------------------------------------------------------------
 * §4.2, verbatim: *"`—` in `Perm` means the operation requires no permission
 * code. Whether it also requires a JWT is decided by the row's own `Path`, not by
 * a second convention: `/auth/*` is pre-authentication; everything else requires
 * a valid token."* So this gate branches on the operation's `uriTemplate` and on
 * nothing else — not on a list of public routes, which is the artefact that goes
 * stale the first time an operation is added.
 *
 * `PreAuthPrincipalTest::theTokenPathIssuesNothingOnTheApplicationConnection` is the check that
 * catches the inverse mistake: authenticate `/auth/token` and the sign-in path
 * acquires an `erp_app` read before it has a principal, which is the exposure
 * ADR-006 closed. That test was written before this class existed and goes red
 * on it without an edit.
 *
 * ---------------------------------------------------------------------------
 * Every refusal is the same refusal
 * ---------------------------------------------------------------------------
 * Absent header, wrong scheme, malformed token, bad signature, wrong algorithm,
 * expired, a `sub` that no longer resolves, a `tenant_id` that does not exist —
 * all of them are `authentication-failed`, the same constant object `IssueToken`
 * throws. This is the argument `CurrentUserProvider` already made for `/me`,
 * moved to where it now covers every operation.
 *
 * ---------------------------------------------------------------------------
 * Why `kernel.controller` and not a firewall
 * ---------------------------------------------------------------------------
 * `symfony/security-bundle` is not installed and this contract needs none of it:
 * there is one credential (E-20), no session, no remember-me, no user provider
 * chain, and the refusal shape is `ApiProblem` rather than an entry point. What a
 * firewall would add here is a second place where "which paths are public" is
 * written down — the exact duplication the paragraph above rejects.
 */
#[AsEventListener(event: ControllerEvent::class, priority: self::PRIORITY)]
#[AsEventListener(event: FinishRequestEvent::class, method: 'onFinishRequest')]
final readonly class AuthenticationGate
{
    /**
     * Ahead of `PermissionGate` (24) and of `IdempotencyKeyGuard` (0, the
     * default). The order is 401 → 403 → 428, and it is the order the refusals
     * have to arrive in: a caller who cannot authenticate must not learn that an
     * operation exists that requires a header, and a caller who holds no
     * permission must not learn it either (ST-4b, E-1).
     */
    public const PRIORITY = 32;

    public function __construct(
        private CurrentOperation $operations,
        private AccessTokens $tokens,
        private IdentityReader $reader,
        private RequestIdentity $identities,
        #[Autowire('%app.api_base_path%')]
        private string $basePath,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        // Cleared first, unconditionally. A holder that is only ever written
        // serves the previous request's caller to any path that reaches a
        // controller without passing this gate.
        $this->identities->clear();

        $operation = $this->operations->of($event->getRequest());

        if (!$operation instanceof HttpOperation || $this->isPreAuthentication($operation)) {
            return;
        }

        $request = $event->getRequest();
        $claims = $this->tokens->read($this->bearer($request) ?? throw ApiProblem::authenticationFailed());

        if (null === $claims) {
            throw ApiProblem::authenticationFailed();
        }

        $this->identities->set(
            $this->reader->read($claims['tenant_id'], $claims['sub']) ?? throw ApiProblem::authenticationFailed(),
        );
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        $this->identities->clear();
    }

    /**
     * §4.2's rule, applied to the operation's own path.
     *
     * The base path is stripped rather than assumed absent: `getUriTemplate()`
     * has returned both forms across API Platform versions, and a prefix check
     * that silently stops matching is a gate that silently stops gating.
     */
    private function isPreAuthentication(HttpOperation $operation): bool
    {
        $path = (string) $operation->getUriTemplate();

        if (str_starts_with($path, $this->basePath)) {
            $path = substr($path, \strlen($this->basePath));
        }

        return str_starts_with($path, '/auth/');
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
