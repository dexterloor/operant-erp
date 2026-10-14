<?php

declare(strict_types=1);

namespace App\SharedKernel\Display;

/**
 * **A count in a sentence a person reads: `1 <one>` / `N <many>`, never `(s)`** (`UX` R-2, `docs/session13/roles-s4-ux-copy.md`
 * §1; decided shared by `ARCH`, `docs/session13/owed-arch-c5.md` F-10, 2026-10-14).
 *
 * ONE counting rule for every module, so that two hand copies of it cannot drift ("two agreeing copies are one source").
 * **Only the counting rule is shared; the vocabulary stays with the module** that owns the sentence: each module passes its
 * own nouns (`'open purchase order'` / `'open purchase orders'`, `'person'` / `'people'`), and `UX` owns the wording.
 *
 * It lives in `SharedKernel` for `QuantityRendering`'s reason: every business `*Application` layer already lists
 * `SharedKernel`, so using it adds no deptrac layer edge, and it depends on nothing. `0` is spelt with `$many`
 * (`0 open purchase orders`); no refusal fires at 0 today (0 means "not refused"), and if one ever does, whether 0 reads
 * "no …" is `UX`'s call, not this function's.
 */
final class Counted
{
    /** Not instantiable: one pure function. */
    private function __construct()
    {
    }

    public static function of(int $count, string $one, string $many): string
    {
        return 1 === $count ? '1 '.$one : $count.' '.$many;
    }
}
