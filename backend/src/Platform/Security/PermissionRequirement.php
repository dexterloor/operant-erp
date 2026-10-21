<?php

declare(strict_types=1);

namespace App\Platform\Security;

use ApiPlatform\Metadata\HttpOperation;

/**
 * **§4.2's `Perm` cell, read once (P-0, 2026-10-22).** The one place an operation's permission declaration is parsed.
 *
 * Authority: `docs/session13/g3-g7b-final-red.md` F-H1/F-M1/F-M2/F-L1 > `g3-g7b-reorder-settings-design.md` Revision 1
 * FINAL (P-0) and its H-4 coordination with G5-D0.
 *
 * ===========================================================================
 * Why a value object, and why every reader goes through it
 * ===========================================================================
 * Before P-0 the gate and twelve test sites each read the raw extra property and filtered it with `\is_string()`. A
 * declaration of any other shape -- the list G3-C1 needs, the sentinel G5-D0 needs -- would have been silently SKIPPED
 * by the sweeps (fail-open) while the gate refused it on an assert. Every reader now asks this class, matches on
 * {@see self::kind()}, and cannot treat a derived operation as static: {@see self::codes()} throws on anything but
 * ALL_OF. `PermissionRequirementReadScanTest` holds the "every reader" half down.
 *
 * ===========================================================================
 * The declaration forms -- one spelling per meaning
 * ===========================================================================
 * - `null` -> NONE (§4.2's `—`: authenticated, no code).
 * - `'code'` -> ALL_OF with one code.
 * - `['a.x', 'b.y', …]` -> ALL_OF with every code required: at least two, distinct, in byte-ascending order (F-L1: the
 *   canonical order is byte order everywhere, and the 403 names the first missing code in it). A one-element list is
 *   refused, because `'code'` already spells that meaning.
 * - {@see PermissionGate::DERIVED} together with {@see PermissionGate::DERIVED_PERMISSION} `=> <resolver service id>`
 *   -> DERIVED: exactly one code, resolved from the request (G5-D0). P-0 only parses it; nothing resolves it yet --
 *   {@see PermissionRequirements} throws on that arm until G5-D0 adds it.
 *
 * Anything else is a wiring error and a `LogicException`, as an absent declaration always was.
 *
 * Deliberately NOT here: "every code this requirement can demand". For ALL_OF that set is a conjunction and for DERIVED
 * a disjunction, and building the DERIVED set needs the container (F-H1). It lives on {@see PermissionRequirements}.
 */
final readonly class PermissionRequirement
{
    /** §4.2 `—`. */
    public const NONE = 'none';

    /** One code, or two or more codes all required. */
    public const ALL_OF = 'all_of';

    /** One code resolved from the request (G5-D0). */
    public const DERIVED = 'derived';

    /** §3's `<resource>.<action>` shape (PC-1). */
    private const CODE_SHAPE = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /**
     * @param list<string> $codes
     */
    private function __construct(
        private string $kind,
        private array $codes,
        private ?string $resolver,
    ) {
    }

    /**
     * The operation's requirement. Throws -- with the gate's long-standing text -- when the operation declares nothing.
     */
    public static function of(HttpOperation $operation): self
    {
        $extra = $operation->getExtraProperties();
        $name = (string) $operation->getName();

        if (!\array_key_exists(PermissionGate::OPERATION_PERMISSION, $extra)) {
            throw new \LogicException(\sprintf(
                'Operation "%s" declares no "%s" extra property. Every operation must declare §4.2\'s '
                .'Perm cell — a permission code, or null for the column\'s "—". An operation that '
                .'declares nothing is an operation nobody decided about, and this gate refuses to '
                .'guess in the direction that ships it unchecked.',
                $name,
                PermissionGate::OPERATION_PERMISSION,
            ));
        }

        $resolver = \array_key_exists(PermissionGate::DERIVED_PERMISSION, $extra) ? $extra[PermissionGate::DERIVED_PERMISSION] : null;

        if (null !== $resolver && !\is_string($resolver)) {
            throw new \LogicException(\sprintf('Operation "%s" declares a derived-permission resolver that is not a service id.', $name));
        }

        return self::fromDeclaration($extra[PermissionGate::OPERATION_PERMISSION], $resolver, $name);
    }

    /**
     * A declaration in its written form -- what an operation's extra property holds, or what a pin transcribes.
     */
    public static function fromDeclaration(mixed $declared, ?string $resolver = null, string $operation = '(unnamed)'): self
    {
        if (PermissionGate::DERIVED === $declared) {
            if (null === $resolver || '' === $resolver) {
                throw new \LogicException(\sprintf(
                    'Operation "%s" declares a derived permission and names no "%s" resolver.',
                    $operation,
                    PermissionGate::DERIVED_PERMISSION,
                ));
            }

            return new self(self::DERIVED, [], $resolver);
        }

        if (null !== $resolver) {
            throw new \LogicException(\sprintf(
                'Operation "%s" names a "%s" resolver but does not declare a derived permission.',
                $operation,
                PermissionGate::DERIVED_PERMISSION,
            ));
        }

        if (null === $declared) {
            return new self(self::NONE, [], null);
        }

        if (\is_string($declared)) {
            return new self(self::ALL_OF, [self::code($declared, $operation)], null);
        }

        if (!\is_array($declared) || !array_is_list($declared)) {
            throw new \LogicException(\sprintf(
                'Operation "%s" declares a required permission that is neither a code, a list of codes, §4.2\'s "—" (null), nor a derived permission.',
                $operation,
            ));
        }

        if (\count($declared) < 2) {
            throw new \LogicException(\sprintf(
                'Operation "%s" declares its permission as a list of %d. One code is spelled as the bare string, and an empty '
                .'list is not "—" (null is): one spelling per meaning.',
                $operation,
                \count($declared),
            ));
        }

        $codes = [];
        foreach ($declared as $code) {
            if (!\is_string($code)) {
                throw new \LogicException(\sprintf('Operation "%s" declares a permission list holding a non-string.', $operation));
            }
            $codes[] = self::code($code, $operation);
        }

        $sorted = array_values(array_unique($codes));
        sort($sorted, \SORT_STRING);

        if ($sorted !== $codes) {
            throw new \LogicException(\sprintf(
                'Operation "%s" declares [%s]; an all-of permission is written as distinct codes in byte-ascending order, the '
                .'order the 403 names the first missing one in.',
                $operation,
                implode(', ', $codes),
            ));
        }

        return new self(self::ALL_OF, $codes, null);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * @return list<string> ALL_OF only, byte-ascending; LogicException for any other kind, so a consumer cannot silently
     *                      treat NONE as "no codes" or a derived operation as static
     */
    public function codes(): array
    {
        if (self::ALL_OF !== $this->kind) {
            throw new \LogicException(\sprintf(
                'A %s permission requirement has no static code list. Match on kind() first%s.',
                $this->kind,
                self::DERIVED === $this->kind ? '; a derived requirement\'s codes come from PermissionRequirements (G5-D0)' : '',
            ));
        }

        return $this->codes;
    }

    /** The resolver service id. DERIVED only; LogicException otherwise. */
    public function resolver(): string
    {
        if (self::DERIVED !== $this->kind || null === $this->resolver) {
            throw new \LogicException(\sprintf('A %s permission requirement has no resolver.', $this->kind));
        }

        return $this->resolver;
    }

    /** An injective, readable rendering: `none`, `all_of:a.x,b.y`, `derived:<service id>`. For messages and comparisons. */
    public function describe(): string
    {
        return match ($this->kind) {
            self::NONE => self::NONE,
            self::ALL_OF => self::ALL_OF.':'.implode(',', $this->codes),
            default => self::DERIVED.':'.(string) $this->resolver,
        };
    }

    private static function code(string $code, string $operation): string
    {
        if (1 !== preg_match(self::CODE_SHAPE, $code)) {
            throw new \LogicException(\sprintf(
                'Operation "%s" declares "%s", which is neither a §3-shaped permission code nor the derived sentinel.',
                $operation,
                $code,
            ));
        }

        return $code;
    }
}
