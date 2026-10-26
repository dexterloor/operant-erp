<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\CreatePasswordResetRedemptionInput;
use App\Modules\UserManagement\ApiResource\PasswordResetRedemptionResource;
use App\Modules\UserManagement\Application\PasswordResetRedemption;
use App\Modules\UserManagement\Application\PasswordResetRedemptionCommand;
use App\Modules\UserManagement\Application\PasswordResetRedemptionOutcome;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/** Mail-C2: maps the redeem's outcome to 200 / the constant sign-in 401 (D-M4) / 422 / 429. */
final readonly class CreatePasswordResetRedemptionProcessor implements ProcessorInterface
{
    public function __construct(private PasswordResetRedemption $redemption)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PasswordResetRedemptionResource
    {
        \assert($data instanceof CreatePasswordResetRedemptionInput);

        $request = $context['request'] ?? null;

        $result = $this->redemption->redeem(new PasswordResetRedemptionCommand(
            token: $data->token,
            password: $data->password,
            ipAddress: $request instanceof Request ? $request->getClientIp() : null,
        ));

        return match ($result->outcome) {
            PasswordResetRedemptionOutcome::Changed => new PasswordResetRedemptionResource(
                changedAt: ($result->changedAt ?? throw new \LogicException('A completed redemption carries no instant.'))
                    ->format(\DateTimeInterface::RFC3339),
            ),
            PasswordResetRedemptionOutcome::Refused => throw ApiProblem::authenticationFailed(),
            PasswordResetRedemptionOutcome::Invalid => throw ApiProblem::validationFailed($result->errors),
            PasswordResetRedemptionOutcome::Throttled => throw ApiProblem::tooManyAttempts(),
        };
    }
}
