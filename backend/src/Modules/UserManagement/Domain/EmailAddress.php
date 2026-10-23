<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **An email address as the reset request carries it — and, from U-C1, as `CreateUser` accepts it** (Mail-C1,
 * `mail-c1-build-plan.md` R1.5; RED M-3, L-7). One code path: {@see self::fromInput()} normalises (`PreAuthKeys::email`,
 * the sign-in normalisation — `"ALICE@EXAMPLE.COM "` is `alice@example.com`), then the constructor validates; the
 * constructor is also what `ObjectNormalizer` calls when the worker decodes a queued message, so the rule holds on both
 * sides of the queue.
 *
 * Refused: invalid UTF-8; any C0 control (U+0000–U+001F), DEL (U+007F) or C1 control (U+0080–U+009F) — so a NUL can never
 * reach the worker's lookup, where Postgres would raise 22021 on every retry (M-3); more than 254 characters; anything but
 * exactly one `@` with both sides non-empty. **Nothing stricter (L-7):** `app_user` constrains only `email = lower(email)`,
 * so `a@b` and `x+y@sub.example` are storable, and a stricter predicate would lock their owners out of recovery.
 *
 * **Wire contract (M-4 (b)):** the public property is named exactly like the constructor parameter; changing either, or the
 * rule, is a message-version change — drain `async` and `failed` first, or add a new message class.
 */
final readonly class EmailAddress
{
    public const MAXIMUM_LENGTH = 254;

    /** C0, DEL and C1, as code points (`u`). */
    private const CONTROL = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u';

    public string $value;

    public function __construct(string $value)
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException('An email address needs one "@" with something on each side, at most 254 characters and no control characters.');
        }
        $this->value = $value;
    }

    /** @throws \InvalidArgumentException when the normalised input is not an address */
    public static function fromInput(string $input): self
    {
        if (!mb_check_encoding($input, 'UTF-8')) {
            throw new \InvalidArgumentException('An email address must be valid UTF-8.');
        }

        return new self(PreAuthKeys::email($input));
    }

    public static function isValid(string $value): bool
    {
        if (!mb_check_encoding($value, 'UTF-8') || 1 === preg_match(self::CONTROL, $value)) {
            return false;
        }
        if (mb_strlen($value, 'UTF-8') > self::MAXIMUM_LENGTH || 1 !== substr_count($value, '@')) {
            return false;
        }
        [$local, $domain] = explode('@', $value);

        return '' !== $local && '' !== $domain;
    }
}
