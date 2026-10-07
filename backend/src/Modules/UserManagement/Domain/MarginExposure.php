<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **PC-12's margin predicate over a code set** — roles slice 4 C0 (design §4.6). A holder of `$codes` is exposed when it
 * holds a cost source AND a sale source ({@see MarginSources}) without holding every `.view` in the catalogue — the exemption
 * belongs to a seat that already reads everything (`owner`, `admin`).
 *
 * What a caller does with `true` is PC-12 clause 3's, and it differs by role kind, deliberately:
 *  - a **default** role that is exposed FAILS the check (`MarginSourcesDerivationTest`, the strict half; today none is);
 *  - a **composed** role, or any union, is **warned, never refused** (owner, 2026-09-16: *"Default strict, custom warned"*).
 *    `Role.margin_exposure` (C1a) is that warning at definition; the pair warning at assignment (clause 4, G-28 item 1) is
 *    still owed to `GetUser`'s picker.
 *
 * A function of `permission_codes` only, so a caller that may read a role's codes may read this — non-additive disclosure.
 */
final class MarginExposure
{
    /** Not instantiable: one pure predicate. */
    private function __construct()
    {
    }

    /** @param list<string> $codes */
    public static function of(array $codes): bool
    {
        if ([] === array_diff(PermissionCatalogue::viewCodes(), $codes)) {
            return false;
        }

        return self::holdsAny(MarginSources::costSources(), $codes) && self::holdsAny(MarginSources::saleSources(), $codes);
    }

    /**
     * @param list<list<string>> $sources
     * @param list<string>       $codes
     */
    private static function holdsAny(array $sources, array $codes): bool
    {
        foreach ($sources as $source) {
            if ([] !== $source && [] === array_diff($source, $codes)) {
                return true;
            }
        }

        return false;
    }
}
