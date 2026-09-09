<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Infrastructure\IssuedToken;

final readonly class SignInResult
{
    private function __construct(public SignInOutcome $outcome, public ?IssuedToken $token)
    {
    }

    public static function issued(IssuedToken $token): self
    {
        return new self(SignInOutcome::Issued, $token);
    }

    public static function refused(): self
    {
        return new self(SignInOutcome::Refused, null);
    }

    public static function paused(): self
    {
        return new self(SignInOutcome::Paused, null);
    }

    public static function throttled(): self
    {
        return new self(SignInOutcome::Throttled, null);
    }
}
