<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\GrantConflict;
use App\Modules\UserManagement\Domain\MarginExposure;
use App\Modules\UserManagement\Domain\PermissionCatalogue;
use App\Modules\UserManagement\Domain\RoleCompanions;
use App\Modules\UserManagement\Domain\RoleDefinitionRules;
use App\Modules\UserManagement\Domain\SeparationOfDuty;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pure rules roles slice 4 judges a role definition by — built in C0, called by nothing yet except these tests
 * (`docs/session13/roles-s4-design.md` §4.1, §4.4, §4.5, §4.6, §4.7; owner rulings D-5a, D-5b, D-6 (1)).
 *
 * Reads no document and touches no database, so it cannot skip. Every expected value is a literal worked out from ADR-008
 * §2's table, never computed by the function under test.
 */
final class RoleDefinitionRulesTest extends TestCase
{
    #[Test]
    public function aCodesPlacementsAreEveryRowAndHalfThatNamesIt(): void
    {
        self::assertSame([['id' => 'SoD-1', 'half' => 'controlled'], ['id' => 'SoD-2', 'half' => 'preparing']], SeparationOfDuty::placementsOf('purchase_order.approve'));
        self::assertSame([['id' => 'SoD-3', 'half' => 'controlled'], ['id' => 'SoD-5', 'half' => 'controlled']], SeparationOfDuty::placementsOf('credit_note.issue'));
        self::assertSame([['id' => 'SoD-3', 'half' => 'preparing'], ['id' => 'SoD-4', 'half' => 'preparing']], SeparationOfDuty::placementsOf('invoice.issue'));
        self::assertSame([], SeparationOfDuty::placementsOf('warehouse.view'));
        self::assertSame([], SeparationOfDuty::placementsOf('no.such_code'));
    }

    #[Test]
    public function edgesAreSpannedOnlyWhenBothEndsAreHeld(): void
    {
        self::assertSame([['invoice.issue', 'invoice.pay']], SeparationOfDuty::edgesSpannedBy(['invoice.pay', 'invoice.issue', 'warehouse.view']));
        self::assertSame([], SeparationOfDuty::edgesSpannedBy(['invoice.pay', 'invoice.void']), 'Two controlled codes of one row are not an edge.');
        self::assertSame([], SeparationOfDuty::edgesSpannedBy([]));
        self::assertCount(\count(SeparationOfDuty::conflictEdges()), SeparationOfDuty::edgesSpannedBy(PermissionCatalogue::codes()), 'The whole catalogue spans every edge.');
    }

    #[Test]
    public function theCeilingIsEveryAddedCodeTheActorLacks(): void
    {
        self::assertSame(['invoice.void'], RoleDefinitionRules::beyondCeiling(['invoice.view', 'invoice.void'], ['invoice.view', 'role.edit']));
        self::assertSame([], RoleDefinitionRules::beyondCeiling([], ['role.edit']));
        self::assertSame([], RoleDefinitionRules::beyondCeiling(DefaultRoles::codesFor(DefaultRoles::ADMIN), DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        self::assertNotSame([], RoleDefinitionRules::beyondCeiling(PermissionCatalogue::codes(), DefaultRoles::codesFor(DefaultRoles::ADMIN)), 'admin lacks X(admin), so composing the whole catalogue exceeds its ceiling.');
    }

    /** PC-4 at composition has no owner exemption: a composed "co-owner" holding the whole catalogue spans every row (§4.5). */
    #[Test]
    public function pc4OnTheResultSpansEveryRowTheSetSpansWhoeverActs(): void
    {
        self::assertSame(['SoD-1'], RoleDefinitionRules::separationOfDutyRowsSpanned(['purchase_order.create', 'purchase_order.cancel']));
        self::assertSame(['SoD-3', 'SoD-5'], RoleDefinitionRules::separationOfDutyRowsSpanned(['invoice.issue', 'credit_note.issue', 'sales_return.create']));
        self::assertSame([], RoleDefinitionRules::separationOfDutyRowsSpanned(['invoice.pay', 'invoice.void', 'credit_note.issue']));
        self::assertSame(SeparationOfDuty::rowIds(), RoleDefinitionRules::separationOfDutyRowsSpanned(PermissionCatalogue::codes()));
    }

    /** §4.7: edge grain, new conflicts only, a pre-existing span tolerated, a pair inside the new set left to PC-4. */
    #[Test]
    public function anEditIsJudgedByThePairsItNewlyGivesEachHolder(): void
    {
        // The worked example: a holder already spanning SoD-3 gains a SECOND SoD-3 controlled code through the edit.
        $held = ['invoice.issue', 'invoice.pay'];
        self::assertSame([['credit_note.adjust', 'invoice.issue']], RoleDefinitionRules::newConflictEdgesForHolder($held, [], ['credit_note.adjust']));
        self::assertSame([], GrantConflict::newRows($held, ['credit_note.adjust']), 'Control: row grain does not see it, which is why edge grain is used.');

        // A pair the holder already carried through this role is tolerated (new-conflict-only).
        self::assertSame([], RoleDefinitionRules::newConflictEdgesForHolder(['invoice.issue'], ['invoice.pay'], ['invoice.pay', 'warehouse.view']));

        // A pair inside the new set alone is PC-4's to refuse, not this re-run's.
        self::assertSame([], RoleDefinitionRules::newConflictEdgesForHolder([], [], ['invoice.issue', 'invoice.pay']));

        // A purchasing_manager holder (spans SoD-1 on its own) is not blocked by an unrelated edit.
        self::assertSame([], RoleDefinitionRules::newConflictEdgesForHolder(DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER), [], ['warehouse.view']));

        // A genuinely new cross-role pair is caught.
        self::assertSame([['goods_receipt.create', 'purchase_order.create']], RoleDefinitionRules::newConflictEdgesForHolder(['purchase_order.create'], ['warehouse.view'], ['goods_receipt.create']));
    }

    #[Test]
    public function theCompanionTableIsTheOwnersRulingAndEveryDefaultSatisfiesIt(): void
    {
        $pairs = array_map(static fn (array $row): string => $row['holding'].' -> '.$row['requires'].' ('.$row['ruling'].')', RoleCompanions::rows());
        self::assertSame([
            'credit_note.issue -> invoice.view (D-5a)',
            'credit_note.adjust -> invoice.view (D-5a)',
            'role.create -> role.view (D-5b)',
            'role.edit -> role.view (D-5b)',
            'role.delete -> role.view (D-5b)',
            'role.delete -> role.assign (D-6)',
            // Users slice 1 C3: owner ruling Q-U3 (2026-10-08).
            'role.assign -> app_user.view (Q-U3)',
        ], $pairs);
        foreach (RoleCompanions::rows() as $row) {
            self::assertTrue(PermissionCatalogue::has($row['holding']), $row['holding'].' is not in the catalogue.');
            self::assertTrue(PermissionCatalogue::has($row['requires']), $row['requires'].' is not in the catalogue.');
        }

        $holdsALeftCode = 0;
        foreach (DefaultRoles::all() as $name => $codes) {
            self::assertSame([], RoleCompanions::missingFrom($codes), 'PC-4a: the default role '.$name.' breaks the companion table.');
            $holdsALeftCode += [] !== array_intersect(array_column(RoleCompanions::rows(), 'holding'), $codes) ? 1 : 0;
        }
        self::assertGreaterThanOrEqual(3, $holdsALeftCode, 'Too few defaults hold a left-hand code for the default check to mean anything.');

        self::assertSame(['role.view', 'role.assign'], array_column(RoleCompanions::missingFrom(['role.delete']), 'requires'));
        self::assertSame(['invoice.view'], array_column(RoleCompanions::missingFrom(['credit_note.issue', 'role.view']), 'requires'));
        self::assertSame([], RoleCompanions::missingFrom(['credit_note.issue', 'credit_note.adjust', 'invoice.view']));
        // Users slice 1 C3 (owner ruling Q-U3): role.assign needs app_user.view — and still NOT role.view (§5.2.11's exception).
        self::assertSame(['app_user.view'], array_column(RoleCompanions::missingFrom(['role.assign']), 'requires'),
            'role.assign without role.view is the stated exception (§5.2.11), not a row; its one companion is app_user.view (Q-U3).');
        self::assertSame([], RoleCompanions::missingFrom(['role.assign', 'app_user.view']));
    }

    #[Test]
    public function marginExposureNeedsACostAndASaleSourceAndIsLiftedOnlyByEveryView(): void
    {
        self::assertTrue(MarginExposure::of(['purchase_order.create', 'invoice.issue']), 'Two WRITE sources (PC-17) reunite the margin.');
        self::assertFalse(MarginExposure::of(['purchase_order.view', 'purchase_order.edit']), 'Cost alone is not the margin.');
        self::assertFalse(MarginExposure::of(['invoice.view', 'sales_order.view']), 'Sale alone is not the margin.');
        self::assertFalse(MarginExposure::of([]));

        $everyView = PermissionCatalogue::viewCodes();
        self::assertNotSame([], $everyView);
        self::assertFalse(MarginExposure::of($everyView), 'A seat holding every .view is exempt.');
        self::assertTrue(MarginExposure::of(array_values(array_diff($everyView, ['audit_log.view']))), 'One .view short of every one, the exemption is lost.');
    }
}
