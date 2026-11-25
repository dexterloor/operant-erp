<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

/**
 * **`?allow_shared_name`, BK-47** (`docs/session13/bk47-design.md` Revision 1 R1-3 / R1-6; `RESIDUAL_GAPS.md` G-48): the
 * query parameter that lets `CreateCustomer`, `UpdateCustomer` and `RestoreCustomer` go ahead when another customer,
 * live or archived, already has the name. It skips the shared-name refusal and nothing else: never the code check, never
 * any other rule (API-19e: a write's query parameter is a directive deciding one of the write's own refusals).
 *
 * The value is the `archived` parse `CustomerCollectionProvider` already ships: absent → off; `true` or `1` → on; `false`
 * or `0` → off; anything else — the empty value, `2`, `yes`, or PHP's array form `?allow_shared_name[]=1` — is the one
 * error {@see self::error()}. {@see self::fromQuery()} never throws: the writer puts the error into the SAME 422 as the
 * body's field errors (`bk47-red.md` L-4), so a request with a blank name and a malformed switch is told both at once.
 *
 * Every writer path takes the switch as a **required** argument, so every caller says what it passes; bulk restore passes
 * {@see self::off()} explicitly (design §2.4).
 */
final readonly class SharedNameSwitch
{
    public const PARAMETER = 'allow_shared_name';

    public const NOT_A_BOOLEAN = 'allow_shared_name must be true or false.';

    private function __construct(
        private bool $on,
        private bool $valid,
    ) {
    }

    /** The raw query value (`$request->query->all()[PARAMETER] ?? null`), so an array arrives as an array and is refused. */
    public static function fromQuery(mixed $raw): self
    {
        return match ($raw) {
            null, 'false', '0' => new self(false, true),
            'true', '1' => new self(true, true),
            default => new self(false, false),
        };
    }

    public static function off(): self
    {
        return new self(false, true);
    }

    /** On only when the value was valid and true: a malformed switch is refused, never read as on. */
    public function isOn(): bool
    {
        return $this->valid && $this->on;
    }

    /** @return array{pointer: string, code: string, message: string}|null */
    public function error(): ?array
    {
        return $this->valid ? null : ['pointer' => '?'.self::PARAMETER, 'code' => 'not_a_boolean', 'message' => self::NOT_A_BOOLEAN];
    }
}
