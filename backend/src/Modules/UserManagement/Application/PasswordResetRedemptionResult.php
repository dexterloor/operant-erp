<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

final readonly class PasswordResetRedemptionResult
{
    /** @param list<array{pointer: string, code: string, message: string}> $errors */
    private function __construct(
        public PasswordResetRedemptionOutcome $outcome,
        public ?\DateTimeImmutable $changedAt = null,
        public array $errors = [],
    ) {
    }

    public static function changed(\DateTimeImmutable $at): self
    {
        return new self(PasswordResetRedemptionOutcome::Changed, $at);
    }

    public static function refused(): self
    {
        return new self(PasswordResetRedemptionOutcome::Refused);
    }

    /** @param list<array{pointer: string, code: string, message: string}> $errors */
    public static function invalid(array $errors): self
    {
        return new self(PasswordResetRedemptionOutcome::Invalid, errors: $errors);
    }

    public static function throttled(): self
    {
        return new self(PasswordResetRedemptionOutcome::Throttled);
    }
}
