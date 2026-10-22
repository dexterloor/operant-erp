<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk;

/**
 * **The resources the owner ruled out of bulk archive and restore** (RO-B2, 2026-10-21, `docs/session13/remaining-ops-ruling.md`).
 *
 * The admissible set is derived (`BulkAdmissibleSetTest`), so an exclusion has to be stated, with its reason, or a
 * derivable resource would simply be admitted. `BulkAdmissibleSetTest::eachExclusionIsLiveOrNamesItsTrigger` holds every
 * entry either live (the resource WOULD be derived without it) or not yet derivable with its trigger named — never dead.
 * {@see BulkArchivalPermission} refuses a member registered for an excluded resource (a wiring fault).
 */
final class BulkExclusions
{
    /** @var array<string, string> resource => the ruling's text */
    public const RULED = [
        'app_user' => 'owner ruling RO-B2, 2026-10-21: their deletes carry E-15g/E-15h lock work (a user\'s delete strips roles under the role lock; not the last owner)',
        'role' => 'owner ruling RO-B2, 2026-10-21: their deletes carry E-15g/E-15h lock work (a held or seeded role is refused under the role lock)',
    ];

    private function __construct()
    {
    }
}
