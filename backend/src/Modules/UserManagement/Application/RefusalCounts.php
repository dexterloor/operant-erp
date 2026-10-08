<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * **A count in a refusal sentence, written in people's words** (`UX` `docs/session13/roles-s4-ux-copy.md` §1 rule R-2):
 * `1 person` / `N people`, and `1 role proposal is` / `N role proposals are`, never "account(s)" or "request(s)". ONE copy,
 * used by the three counting sentences (`UpdateRole` #10 and #11, `DeleteRole` #19 and #20); each constant takes `%s`, not
 * `%d`. `openProposals()` returns the subject with its verb so the two agree; for 1 the later "they're / them" in #20 is read
 * as singular "they", which keeps #20 one constant.
 */
final class RefusalCounts
{
    /** Not instantiable: two pure functions. */
    private function __construct()
    {
    }

    public static function people(int $count): string
    {
        return 1 === $count ? '1 person' : $count.' people';
    }

    public static function openProposals(int $count): string
    {
        return 1 === $count ? '1 role proposal is' : $count.' role proposals are';
    }
}
