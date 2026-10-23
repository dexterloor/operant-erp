<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\CreatePasswordResetInput;
use App\Modules\UserManagement\Application\PasswordResetRequest;
use App\Modules\UserManagement\Application\PasswordResetRequestCommand;
use App\Modules\UserManagement\Application\PasswordResetRequestOutcome;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreatePasswordReset`'s boundary (Mail-C1): the client address the way `IssueTokenProcessor` takes it, and the three
 * outcomes mapped — `Accepted` → 202 with no body (the operation's `status`, `output: false`), `Invalid` → 422,
 * `Throttled` → 429. No outcome names an account.
 */
final readonly class CreatePasswordResetProcessor implements ProcessorInterface
{
    public function __construct(private PasswordResetRequest $request)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        \assert($data instanceof CreatePasswordResetInput);

        $request = $context['request'] ?? null;

        $result = $this->request->request(new PasswordResetRequestCommand(
            workspace: $data->workspace,
            email: $data->email,
            ipAddress: $request instanceof Request ? $request->getClientIp() : null,
        ));

        return match ($result->outcome) {
            PasswordResetRequestOutcome::Accepted => null,
            PasswordResetRequestOutcome::Invalid => throw ApiProblem::validationFailed($result->errors),
            PasswordResetRequestOutcome::Throttled => throw ApiProblem::tooManyAttempts(),
        };
    }
}
