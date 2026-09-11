<?php

declare(strict_types=1);

namespace App\Platform\Http\Problem;

use ApiPlatform\Metadata\ErrorResource;
use ApiPlatform\Metadata\Exception\HttpExceptionInterface;
use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use ApiPlatform\Metadata\Error as ErrorOperation;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-4 / API-13 — the one refusal shape.
 *
 * Why this is an `#[ErrorResource]` and not a plain exception: API Platform's
 * `ErrorProvider` returns a thrown exception **as-is** when its class is a
 * resource class, and otherwise rebuilds it into `ApiPlatform\State\ApiResource\
 * Error`, whose `jsonproblem` group carries `type`, `title`, `status`, `detail`
 * and `instance` and nothing else. Every extension member §5.2 declares --
 * `errors[]`, `rule`, `required_permission`, `current` -- would be dropped on the
 * way out. Declaring the resource here is what makes the catalogue's extension
 * column reachable at runtime.
 *
 * `openapi: false` for the same reason the vendor's error resources carry it:
 * this class is a response shape, not an addressable resource, and a
 * `/api/v1/problems/{id}` path would be a route nobody asked for.
 */
#[ErrorResource(
    uriTemplate: '/problems/{status}',
    uriVariables: ['status'],
    openapi: false,
    operations: [
        new ErrorOperation(
            name: '_api_problem',
            // Reuses the route the vendor error resource already declares
            // instead of minting `/api/v1/problems/{status}`. This class is a
            // response shape; it should not add a path to the API.
            routeName: '_api_errors',
            outputFormats: ['jsonproblem' => ['application/problem+json', 'application/json']],
            normalizationContext: [
                'groups' => ['jsonproblem'],
                'skip_null_values' => true,
            ],
        ),
    ],
    provider: 'api_platform.state.error_provider',
)]
final class ApiProblem extends \RuntimeException implements ProblemExceptionInterface, HttpExceptionInterface
{
    /**
     * @param list<array{pointer: string, code: string, message: string}>|null $errors
     * @param array<string, mixed>|null                                       $current
     * @param array<string, string>                                           $headers
     */
    private function __construct(
        private readonly ProblemType $problem,
        private readonly ?string $detail = null,
        private readonly ?array $errors = null,
        private readonly ?string $rule = null,
        private readonly ?string $ruleMessage = null,
        private readonly ?string $requiredPermission = null,
        private readonly ?array $current = null,
        private readonly ?string $allow = null,
        private readonly array $headers = [],
    ) {
        parent::__construct($problem->title());
    }

    /** @param list<array{pointer: string, code: string, message: string}> $errors */
    public static function validationFailed(array $errors, ?string $detail = null): self
    {
        return new self(ProblemType::ValidationFailed, $detail, errors: $errors);
    }

    public static function businessRuleViolated(string $rule, string $message): self
    {
        return new self(ProblemType::BusinessRuleViolated, $message, rule: $rule, ruleMessage: $message);
    }

    public static function permissionDenied(string $requiredPermission): self
    {
        return new self(ProblemType::PermissionDenied, null, requiredPermission: $requiredPermission);
    }

    public static function notFound(): self
    {
        return new self(ProblemType::NotFound);
    }

    /**
     * §7.3 — **every** sign-in refusal, and the one object that makes E-7's
     * first four observables true by construction.
     *
     * It takes no arguments and it never will. `detail` is null, there is no
     * `instance`, no request id, no timestamp and no `attempts_remaining`: *"a
     * member that varies is a channel"*, and body length follows from the body
     * being a constant. The unknown-workspace, unknown-email, wrong-password,
     * non-active-tenant and deactivated-user branches all reach this method, so
     * there is no per-branch shape for a later change to diverge.
     *
     * `WWW-Authenticate` is sent because RFC 9110 makes it mandatory on a 401,
     * and its value is a constant for the same reason the body is. **No
     * `Retry-After`, here or on either 429** (§5.2a): `Retry-After` is the
     * remaining time, and ST-8a withholds it.
     */
    public static function authenticationFailed(): self
    {
        return new self(ProblemType::AuthenticationFailed, headers: ['WWW-Authenticate' => 'Bearer']);
    }

    /** §5.2 — either E-21 budget is exhausted. No count and no window: both are reconnaissance (ST-8a). */
    public static function tooManyAttempts(): self
    {
        return new self(ProblemType::TooManyAttempts);
    }

    /**
     * §5.2 — `auth_lockout` holds a live `locked_until` for this
     * `(workspace, email)` pair.
     *
     * The **policy** duration ST-8b publishes belongs on the sign-in screen, not
     * in this body: the remaining time is what a `Retry-After` or a `detail`
     * would carry, and ST-8b discloses the constant while withholding the
     * remainder.
     */
    public static function signInPaused(): self
    {
        return new self(ProblemType::SignInPaused);
    }

    /** @param array<string, mixed> $current */
    public static function staleRecord(array $current): self
    {
        return new self(ProblemType::StaleRecord, null, current: $current);
    }

    public static function idempotencyKeyRequired(): self
    {
        return new self(ProblemType::IdempotencyKeyRequired, 'This operation requires an Idempotency-Key header.');
    }

    /**
     * §6.2 — the same `Idempotency-Key` arrived with a different request digest.
     *
     * The body says only that the key was used for a different request. It does
     * not say what that request was or which row it made: a key is a client's
     * own value, but the first request's contents are not this caller's to learn
     * by guessing keys.
     */
    public static function idempotencyKeyConflict(): self
    {
        return new self(
            ProblemType::IdempotencyKeyConflict,
            'This Idempotency-Key was already used for a different request in this workspace. A new request needs a new key.',
        );
    }

    public static function unsupportedMediaType(?string $detail = null): self
    {
        return new self(ProblemType::UnsupportedMediaType, $detail);
    }

    public static function notAcceptable(?string $detail = null): self
    {
        return new self(ProblemType::NotAcceptable, $detail);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        $allow = implode(', ', $allowed);

        // RFC 9110 makes the `Allow` header mandatory on a 405. §5.2 also names
        // `Allow` as an extension member, so it is emitted in both places: the
        // header for the protocol, the member for a client that reads the body.
        return new self(ProblemType::MethodNotAllowed, null, allow: $allow, headers: ['Allow' => $allow]);
    }

    #[Groups(['jsonproblem'])]
    public function getType(): string
    {
        return $this->problem->uri();
    }

    #[Groups(['jsonproblem'])]
    public function getTitle(): ?string
    {
        return $this->problem->title();
    }

    #[Groups(['jsonproblem'])]
    public function getStatus(): ?int
    {
        return $this->problem->status();
    }

    #[Groups(['jsonproblem'])]
    public function getDetail(): ?string
    {
        return $this->detail;
    }

    #[Groups(['jsonproblem'])]
    public function getInstance(): ?string
    {
        return null;
    }

    /** @return list<array{pointer: string, code: string, message: string}>|null */
    #[Groups(['jsonproblem'])]
    public function getErrors(): ?array
    {
        return $this->errors;
    }

    #[Groups(['jsonproblem'])]
    public function getRule(): ?string
    {
        return $this->rule;
    }

    #[Groups(['jsonproblem'])]
    #[SerializedName('message')]
    public function getRuleMessage(): ?string
    {
        return $this->ruleMessage;
    }

    #[Groups(['jsonproblem'])]
    #[SerializedName('required_permission')]
    public function getRequiredPermission(): ?string
    {
        return $this->requiredPermission;
    }

    /** @return array<string, mixed>|null */
    #[Groups(['jsonproblem'])]
    public function getCurrent(): ?array
    {
        return $this->current;
    }

    #[Groups(['jsonproblem'])]
    public function getAllow(): ?string
    {
        return $this->allow;
    }

    public function getStatusCode(): int
    {
        return $this->problem->status();
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
