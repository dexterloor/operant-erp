<?php

declare(strict_types=1);

namespace App\Auth\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Problem\ApiProblem;
use App\Api\Security\RequestIdentity;
use App\Auth\ApiResource\ChangePasswordInput;
use App\Auth\ApiResource\PasswordResource;
use App\Auth\Application\ChangePassword;
use App\Auth\Application\ChangePasswordCommand;
use App\Auth\Application\ChangePasswordOutcome;
use Symfony\Component\HttpFoundation\Request;

/**
 * The boundary for `ChangePassword`, and deliberately as thin as
 * `IssueTokenProcessor`.
 *
 * **Every decision is `ChangePassword`'s.** What is here is the mapping from
 * three outcomes to three responses, plus the one thing that cannot be decided
 * further in: **the principal comes from `RequestIdentity::authenticated()` and
 * from nowhere else.** That is what makes the operation self-service by
 * construction — there is no request field naming a subject, and the only
 * identity in scope is the one `AuthenticationGate` resolved from the signed
 * token before this controller ran. `authenticated()` is the fail-closed
 * accessor: with no identity it throws rather than defaulting.
 *
 * The client address comes from `Request::getClientIp()`, exactly as
 * `IssueTokenProcessor` takes it, and E-21's fourth violation clause — an address
 * taken from a client-settable header with no `framework.trusted_proxies` —
 * lands on this line too. `getClientIp()` returns `REMOTE_ADDR` unless that
 * setting is configured, so the default is the safe one. Here the address is
 * **forensic** rather than a limiter key (API-16, E-23): the throttle is keyed on
 * the actor, not on the address, so a spoofed value costs the trail its accuracy
 * and buys no extra budget.
 *
 * @implements ProcessorInterface<ChangePasswordInput, PasswordResource>
 */
final readonly class ChangePasswordProcessor implements ProcessorInterface
{
    public function __construct(private RequestIdentity $identities, private ChangePassword $changePassword)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PasswordResource
    {
        \assert($data instanceof ChangePasswordInput);

        $identity = $this->identities->authenticated();
        $request = $context['request'] ?? null;

        $result = $this->changePassword->change(new ChangePasswordCommand(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            currentPassword: $data->currentPassword,
            newPassword: $data->newPassword,
            ipAddress: $request instanceof Request ? $request->getClientIp() : null,
        ));

        return match ($result->outcome) {
            ChangePasswordOutcome::Changed => new PasswordResource(
                changedAt: ($result->changedAt ?? throw new \LogicException('A completed change carries no instant.'))
                    ->format(\DateTimeInterface::RFC3339),
            ),
            // E-23: the operation is throttled and the record is not. Nothing was
            // written, because nothing happened.
            ChangePasswordOutcome::Throttled => throw ApiProblem::tooManyAttempts(),
            ChangePasswordOutcome::Refused => throw ApiProblem::validationFailed($result->errors),
        };
    }
}
