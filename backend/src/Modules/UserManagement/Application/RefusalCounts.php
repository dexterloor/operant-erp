<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\SharedKernel\Display\Counted;

/**
 * **A count in a refusal sentence, written in people's words** (`UX` `docs/session13/roles-s4-ux-copy.md` §1 rule R-2):
 * `1 person` / `N people`, and `1 role proposal is` / `N role proposals are`, never "account(s)" or "request(s)". ONE copy,
 * used by the three counting sentences (`UpdateRole` #10 and #11, `DeleteRole` #19 and #20); each constant takes `%s`, not
 * `%d`. `openProposals()` returns the subject with its verb so the two agree; for 1 the later "they're / them" in #20 is read
 * as singular "they", which keeps #20 one constant.
 *
 * The counting rule itself is {@see Counted::of()} (`ARCH` F-10, `docs/session13/owed-arch-c5.md`, 2026-10-14); these two
 * keep this module's vocabulary and delegate, so their output is byte-identical to what they returned before (the literal
 * pins in `RoleUpdateTest` / `RoleDeleteTest` and `CountedTest::refusalCountsDelegatesWithoutChangingItsWords` are the check).
 */
final class RefusalCounts
{
    /** Not instantiable: two pure functions. */
    private function __construct()
    {
    }

    public static function people(int $count): string
    {
        return Counted::of($count, 'person', 'people');
    }

    public static function openProposals(int $count): string
    {
        return Counted::of($count, 'role proposal is', 'role proposals are');
    }
}
