<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Platform\Http\Problem\ApiProblem;
use App\Modules\UserManagement\ApiResource\IssueTokenInput;
use App\Modules\UserManagement\ApiResource\TokenResource;
use App\Modules\UserManagement\Application\SignIn;
use App\Modules\UserManagement\Application\SignInCommand;
use App\Modules\UserManagement\Application\SignInOutcome;
use Symfony\Component\HttpFoundation\Request;

/**
 * The boundary for `IssueToken`, and deliberately the thinnest thing in this
 * slice.
 *
 * Every decision is `SignIn`'s. What is here is the mapping from four outcomes
 * to three responses — and the fact that **three of the four outcomes map to two
 * objects, both of them constant**, is what E-7 is: the refusal cannot vary by
 * branch because the branch is not in scope at this point in the program.
 *
 * The client address comes from `Request::getClientIp()`. **E-21's fourth
 * violation clause is a deployment condition and is named here because this is
 * the line it lands on:** an `ip_prefix` taken from a client-settable header
 * with no trusted-proxy configuration lets the attacker choose the limiter key,
 * both budgets go free, and *every test stays green*. `getClientIp()` returns
 * `REMOTE_ADDR` unless `framework.trusted_proxies` is set, so the default is the
 * safe one — and the day a proxy is put in front of this, that setting is the
 * thing to get right. Recorded in `API.md` §14.2 rather than left in a comment
 * alone.
 *
 * @implements ProcessorInterface<IssueTokenInput, TokenResource>
 */
final readonly class IssueTokenProcessor implements ProcessorInterface
{
    public function __construct(private SignIn $signIn)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TokenResource
    {
        \assert($data instanceof IssueTokenInput);

        $request = $context['request'] ?? null;

        $result = $this->signIn->attempt(new SignInCommand(
            workspace: $data->workspace,
            email: $data->email,
            password: $data->password,
            ipAddress: $request instanceof Request ? $request->getClientIp() : null,
        ));

        return match ($result->outcome) {
            SignInOutcome::Issued => new TokenResource(
                accessToken: $result->token?->jwt ?? throw new \LogicException('An issued sign-in carries no token.'),
                tokenType: 'Bearer',
                expiresAt: $result->token->expiresAt->format(\DateTimeInterface::RFC3339),
            ),
            SignInOutcome::Refused => throw ApiProblem::authenticationFailed(),
            SignInOutcome::Paused => throw ApiProblem::signInPaused(),
            SignInOutcome::Throttled => throw ApiProblem::tooManyAttempts(),
        };
    }
}
