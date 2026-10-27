<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Modules\UserManagement\Application\GrantRules;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\GrantConflict;
use App\Modules\UserManagement\Domain\HeldSpanConflict;
use App\Modules\UserManagement\Domain\SeparationOfDuty;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **G-91 / BK-11: the held separation-of-duty clause, as pure functions** (`SEC` `docs/design/sec-held-conflict.md`
 * Revision 2: R1.3's worked cases, R2.1's rule, R2.2's invariant, R2.4's "masked"). Reads no document and no database.
 *
 * Cases (R1.3), each through {@see GrantRules::conflictVerdict()} — the one verdict every grant route and the picker reach:
 *  - **A** `admin` + `finance` ← `sales_rep`: admitted (adds no new pair on SoD-3; the recorded narrowing, G-91 open part);
 *  - **B** `admin` + `warehouse_operator` ← `purchasing_manager`: refused on SoD-2 (approve × receive is new);
 *  - **C** `{invoice.issue}` + `warehouse_operator` ← `sales_rep`: refused on SoD-4 (confirm × ship is new);
 *  - **D** `sales_rep` + `finance` ← `viewer`: admitted (viewer holds no governed code).
 */
final class HeldSpanConflictTest extends TestCase
{
    /** R1.3's worked cases, plus the owner-holder, the purchasing-manager-holder and violator ← owner. */
    #[Test]
    public function theWorkedCasesAreDecidedAsTheRuleSays(): void
    {
        $admin = DefaultRoles::codesFor(DefaultRoles::ADMIN);
        $finance = DefaultRoles::codesFor(DefaultRoles::FINANCE);
        $warehouse = DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR);
        $salesRep = DefaultRoles::codesFor(DefaultRoles::SALES_REP);

        self::assertNull($this->verdictRows([...$admin, ...$finance], DefaultRoles::SALES_REP), 'Case A (adds nothing new) was refused.');
        self::assertSame(['SoD-2'], $this->verdictRows([...$admin, ...$warehouse], DefaultRoles::PURCHASING_MANAGER), 'Case B.');
        self::assertSame(['SoD-4'], $this->verdictRows(['invoice.issue', ...$warehouse], DefaultRoles::SALES_REP), 'Case C.');
        self::assertNull($this->verdictRows([...$salesRep, ...$finance], DefaultRoles::VIEWER), 'Case D (viewer) was refused.');
        self::assertNull($this->verdictRows(DefaultRoles::codesFor(DefaultRoles::OWNER), DefaultRoles::SALES_REP), 'An owner holder was refused sales_rep.');
        self::assertNull($this->verdictRows(DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER), DefaultRoles::ADMIN), 'A purchasing_manager holder was refused admin.');
        self::assertNull($this->verdictRows([...$salesRep, ...$finance], DefaultRoles::OWNER), 'A violator was refused owner (owner spans every row alone).');

        // The old clause alone admitted B and C — the gap this closes.
        self::assertSame([], GrantConflict::newRows([...$admin, ...$warehouse], DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER)));
        self::assertSame([], GrantConflict::newRows(['invoice.issue', ...$warehouse], $salesRep));
    }

    /**
     * R1.2, the clean-person half, on today's table: when the holder is one default role and the granted role is another,
     * the verdict equals `GrantConflict::newRows()` exactly — the held clause refuses nothing the old rule admits.
     */
    #[Test]
    public function forOneDefaultRoleGivenAnotherTheVerdictEqualsTheRowRule(): void
    {
        $pairs = 0;
        foreach (DefaultRoles::all() as $heldName => $held) {
            foreach (DefaultRoles::all() as $grantedName => $granted) {
                $expected = GrantConflict::newRows($held, $granted);
                self::assertSame([] === $expected ? null : $expected, $this->verdictRows($held, $grantedName),
                    \sprintf('%s holder given %s: the held clause changed the verdict for someone who combines nothing.', $heldName, $grantedName));
                ++$pairs;
            }
        }
        self::assertSame(\count(DefaultRoles::names()) ** 2, $pairs);
    }

    /**
     * The `r ∉ spans(R)` guard: a role that spans SoD-1 alone (create + approve) given to a holder of `cancel` alone is not
     * refused on SoD-1 — E-18's deliberate permission. Without the guard the pair (create, cancel) would be new (M2).
     */
    #[Test]
    public function aRoleSpanningARowAloneIsNotRefusedOnThatRow(): void
    {
        self::assertSame([], HeldSpanConflict::newEdgeRows(['purchase_order.cancel'], ['purchase_order.create', 'purchase_order.approve']));
        self::assertNull(GrantRules::conflictVerdict(['purchase_order.cancel'],
            ['name' => 'Raise and approve', 'live' => true, 'is_system' => false, 'codes' => ['purchase_order.create', 'purchase_order.approve']]));
        // Control: the same pair is new when the role does not span the row alone.
        self::assertSame(['SoD-1'], HeldSpanConflict::newEdgeRows(['purchase_order.cancel', 'purchase_order.approve'], ['purchase_order.create']));
    }

    /** Arm H's two predicates: violated (no single role spans the row) and masked (a role spanning it alone hides it). */
    #[Test]
    public function violatedAndMaskedRowsAreTold(): void
    {
        $roles = static fn (string ...$names): array => array_combine(
            array_map(static fn (string $n): string => 'id-'.$n, $names),
            array_map(static fn (string $n): array => DefaultRoles::codesFor($n), $names),
        );

        self::assertSame(['SoD-3'], HeldSpanConflict::violatedRows($roles(DefaultRoles::SALES_REP, DefaultRoles::FINANCE)));
        self::assertSame([], HeldSpanConflict::maskedRows($roles(DefaultRoles::SALES_REP, DefaultRoles::FINANCE)));
        self::assertSame([], HeldSpanConflict::violatedRows($roles(DefaultRoles::OWNER)), 'owner alone is not a violator.');
        self::assertSame([], HeldSpanConflict::maskedRows($roles(DefaultRoles::OWNER)), 'owner alone is not masked.');
        self::assertSame([], HeldSpanConflict::violatedRows($roles(DefaultRoles::PURCHASING_MANAGER)), 'purchasing_manager alone is not a violator.');
        self::assertSame([], HeldSpanConflict::maskedRows($roles(DefaultRoles::PURCHASING_MANAGER)));

        $masked = $roles(DefaultRoles::OWNER, DefaultRoles::SALES_REP, DefaultRoles::FINANCE);
        self::assertSame([], HeldSpanConflict::violatedRows($masked), 'owner hides the violation from PC-13\'s predicate.');
        self::assertSame(['id-owner' => ['SoD-3']], HeldSpanConflict::maskedRows($masked));

        // purchasing_manager spans SoD-1 alone and is needed for it (warehouse_operator does not span SoD-1): not masked; SoD-2
        // is spanned by neither alone: violated.
        $mixed = $roles(DefaultRoles::PURCHASING_MANAGER, DefaultRoles::WAREHOUSE_OPERATOR);
        self::assertSame(['SoD-2'], HeldSpanConflict::violatedRows($mixed));
        self::assertSame([], HeldSpanConflict::maskedRows($mixed));
    }

    /**
     * **R2.2's invariant** — the premise of "no change for anyone who combines nothing": every default role that spans a
     * row alone holds every code on both halves of it (today `owner` on every row, `purchasing_manager` on SoD-1). It goes
     * red only on the change that breaks the equivalence, not on every table change (that is the fingerprint's job).
     */
    #[Test]
    public function everyDefaultRoleSpanningARowAloneHoldsEveryCodeOnIt(): void
    {
        self::assertSame([], self::partialSpans(DefaultRoles::all()),
            "(g') now refuses grants to holders of this role that today's rule admits; the held side needs per-role sets "
            .'(LiveGrantReader::rolesHeldBy) before this table change ships.');
        self::assertContains('owner SoD-1', self::fullSpans(DefaultRoles::all()), 'Fixture: owner spans no row, so the invariant is vacuous.');
        self::assertContains('purchasing_manager SoD-1', self::fullSpans(DefaultRoles::all()));

        // Planted control: a role spanning SoD-1 without `purchase_order.cancel` is found.
        self::assertSame(['planted SoD-1'], self::partialSpans(['planted' => ['purchase_order.create', 'purchase_order.approve']]));
    }

    /**
     * @param list<string> $held
     *
     * @return list<string>|null the refused rows, or null when admitted
     */
    private function verdictRows(array $held, string $granted): ?array
    {
        $verdict = GrantRules::conflictVerdict(array_values(array_unique($held)),
            ['name' => $granted, 'live' => true, 'is_system' => true, 'codes' => DefaultRoles::codesFor($granted)]);

        if (null === $verdict) {
            return null;
        }
        self::assertSame('user_role.separation_of_duty', $verdict->code);
        self::assertSame($granted, $verdict->data['role_name']);

        return $verdict->data['rows'];
    }

    /**
     * @param array<string, list<string>> $roles
     *
     * @return list<string> "<role> <row>" for every row a role spans alone without every code of that row
     */
    private static function partialSpans(array $roles): array
    {
        $found = [];
        foreach ($roles as $name => $codes) {
            foreach (SeparationOfDuty::rowsSpannedBy($codes) as $row) {
                $all = [...SeparationOfDuty::preparingHalf($row), ...SeparationOfDuty::controlledHalf($row)];
                if ([] !== array_diff($all, $codes)) {
                    $found[] = $name.' '.$row;
                }
            }
        }

        return $found;
    }

    /**
     * @param array<string, list<string>> $roles
     *
     * @return list<string>
     */
    private static function fullSpans(array $roles): array
    {
        $found = [];
        foreach ($roles as $name => $codes) {
            foreach (SeparationOfDuty::rowsSpannedBy($codes) as $row) {
                $found[] = $name.' '.$row;
            }
        }

        return $found;
    }
}
