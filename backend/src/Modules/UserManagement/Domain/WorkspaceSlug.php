<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **A workspace slug as a password-reset request carries it** (Mail-C1, `mail-c1-build-plan.md` R1.5; RED M-2, M-3). A
 * value object because it crosses the queue: its constructor is what `AllowlistSerializer`'s `ObjectNormalizer` calls at
 * decode, so the invariant is re-checked on the worker side, not trusted from the web.
 *
 * {@see self::fromInput()} normalises first (`PreAuthKeys::slug`, the sign-in normalisation — so `" Acme "` is `acme`),
 * then validates. The rule is `tenant_slug_shape`'s regex transcribed (`Version20260906000100`: the heredoc's `\$` is
 * PHP escaping, not part of the pattern), after a UTF-8 and NUL refusal. Nothing stricter: a slug the registry can hold
 * must pass.
 *
 * **Wire contract (M-4 (b)):** the public property is named exactly like the constructor parameter. Changing either, or
 * the rule, is a message-version change — drain `async` and `failed` first, or add a new message class.
 */
final readonly class WorkspaceSlug
{
    /** `tenant_slug_shape`, transcribed. `D`: `$` does not match before a trailing newline. */
    public const PATTERN = '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D';

    public string $value;

    public function __construct(string $value)
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException('A workspace slug must be 3 to 63 lower-case letters, digits or hyphens.');
        }
        $this->value = $value;
    }

    /** @throws \InvalidArgumentException when the normalised input is not a slug */
    public static function fromInput(string $input): self
    {
        return new self(PreAuthKeys::slug($input));
    }

    public static function isValid(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0") && 1 === preg_match(self::PATTERN, $value);
    }
}
