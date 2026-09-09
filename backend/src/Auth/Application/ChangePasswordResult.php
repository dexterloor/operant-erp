<?php

declare(strict_types=1);

namespace App\Auth\Application;

final readonly class ChangePasswordResult
{
    /**
     * @param list<array{pointer: string, code: string, message: string}> $errors
     */
    private function __construct(
        public ChangePasswordOutcome $outcome,
        public ?\DateTimeImmutable $changedAt = null,
        public array $errors = [],
    ) {
    }

    public static function changed(\DateTimeImmutable $at): self
    {
        return new self(ChangePasswordOutcome::Changed, $at);
    }

    /**
     * @param list<array{pointer: string, code: string, message: string}> $errors
     */
    public static function refused(array $errors): self
    {
        return new self(ChangePasswordOutcome::Refused, null, $errors);
    }

    public static function throttled(): self
    {
        return new self(ChangePasswordOutcome::Throttled);
    }
}
