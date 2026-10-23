<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/** {@see PasswordResetRequestOutcome} plus, for `Invalid`, the field errors (JSON pointers into the body). */
final readonly class PasswordResetRequestResult
{
    /** @param list<array{pointer: string, code: string, message: string}> $errors */
    private function __construct(public PasswordResetRequestOutcome $outcome, public array $errors = [])
    {
    }

    public static function accepted(): self
    {
        return new self(PasswordResetRequestOutcome::Accepted);
    }

    public static function throttled(): self
    {
        return new self(PasswordResetRequestOutcome::Throttled);
    }

    /** @param list<array{pointer: string, code: string, message: string}> $errors */
    public static function invalid(array $errors): self
    {
        return new self(PasswordResetRequestOutcome::Invalid, $errors);
    }
}
