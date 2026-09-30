<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Modules\UserManagement\Application\GrantConfirmationPolicy;
use App\Modules\UserManagement\Domain\AdministrativeCharter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\GrantConflict;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Modules\UserManagement\Domain\PermissionCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **E-15e's routing and PC-13's "new conflict only", as pure functions** (roles slice 2, C3; design §5.2; the E-15e
 * design's T4b, `docs/session12/roles-e15e-design.md` §9). The HTTP half is `RoleAssignTest`.
 */
final class GrantConfirmationPolicyTest extends TestCase
{
    /**
     * **T4b.** For every catalogue code c, a non-owner granting a role holding c alone needs confirmation exactly when c is
     * in `X(admin)` — the charter applied, code by code. An owner never needs it. `invoice.void` is pinned by name: A2.1a
     * put it on SoD-3's controlled half, so this route routes it (design §9). Disclosed: green on today's table whether the
     * policy derives or types the list — that is why `XAdminIsNeverTypedTest` exists (M3).
     */
    #[Test]
    public function needsConfirmationIsTheCharterAppliedToEveryCatalogueCode(): void
    {
        $admin = DefaultRoles::codesFor(DefaultRoles::ADMIN);
        $owner = DefaultRoles::codesFor(DefaultRoles::OWNER);
        $excluded = AdministrativeCharter::excludedCodes();
        $routed = [];
        $direct = [];
        foreach (PermissionCatalogue::codes() as $code) {
            $needs = GrantConfirmationPolicy::needsConfirmation($admin, [$code]);
            self::assertSame(\in_array($code, $excluded, true), $needs, $code);
            self::assertFalse(GrantConfirmationPolicy::needsConfirmation($owner, [$code]), 'An owner needs confirmation for '.$code);
            $needs ? $routed[] = $code : $direct[] = $code;
        }

        self::assertNotSame([], $routed, 'Anti-vacuity: no code routes to the owner.');
        self::assertNotSame([], $direct, 'Anti-vacuity: every code routes to the owner.');
        self::assertContains('invoice.void', $routed, 'A2.1a: a non-owner can grant invoice.void directly.');
        self::assertContains(OwnerMarker::CODE, $routed);
    }

    /** Keyed on the actor's codes through the owner predicate — never on `role.assign` (M2), never on a name (M2b). */
    #[Test]
    public function theActorIsJudgedByTheMarkerAloneAndTheRoleByItsCodes(): void
    {
        self::assertTrue(GrantConfirmationPolicy::needsConfirmation(['role.assign'], ['invoice.pay']));
        self::assertFalse(GrantConfirmationPolicy::needsConfirmation(['role.assign', OwnerMarker::CODE], ['invoice.pay']));
        self::assertFalse(GrantConfirmationPolicy::needsConfirmation([OwnerMarker::CODE], ['invoice.pay']), 'The marker, not role.assign, exempts.');
        self::assertFalse(GrantConfirmationPolicy::needsConfirmation(['role.assign'], ['invoice.issue', 'role.view']), 'A preparing half is not X(admin).');
        self::assertTrue(GrantConfirmationPolicy::needsConfirmation(['role.assign'], ['role.view', 'goods_receipt.create']), 'One X(admin) code among others routes.');
        self::assertFalse(GrantConfirmationPolicy::needsConfirmation(['role.assign'], []), 'A role with no code routes nowhere.');
    }

    /**
     * PC-13's `new = spans(H∪R) − spans(H) − spans(R)`, on the design's cases (spar P8): the six refused default pairs, the
     * `viewer`-to-a-conflicted-holder control (A2), and the single-role-spans control (A2b).
     */
    #[Test]
    public function aGrantIsRefusedOnlyForTheRowsItNewlySpans(): void
    {
        $codes = static fn (string ...$roles): array => array_values(array_unique(array_merge(...array_map(DefaultRoles::codesFor(...), $roles))));

        self::assertSame(['SoD-3'], GrantConflict::newRows($codes(DefaultRoles::SALES_REP), DefaultRoles::codesFor(DefaultRoles::FINANCE)));
        self::assertSame(['SoD-3'], GrantConflict::newRows($codes(DefaultRoles::FINANCE), DefaultRoles::codesFor(DefaultRoles::SALES_REP)));
        self::assertSame(['SoD-4'], GrantConflict::newRows($codes(DefaultRoles::SALES_REP), DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR)));
        self::assertSame(['SoD-2'], GrantConflict::newRows($codes(DefaultRoles::BUYER), DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR)));
        self::assertSame([], GrantConflict::newRows($codes(DefaultRoles::SALES_REP, DefaultRoles::FINANCE), DefaultRoles::codesFor(DefaultRoles::VIEWER)),
            'A2: a split the subject already spans is not a reason to refuse an unrelated grant.');
        self::assertSame([], GrantConflict::newRows($codes(DefaultRoles::BUYER), DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER)),
            'A2b: purchasing_manager spans SoD-1 alone, which E-18 permits.');
        self::assertSame([], GrantConflict::newRows([], DefaultRoles::codesFor(DefaultRoles::OWNER)), 'A first role is never a new conflict.');
    }
}
