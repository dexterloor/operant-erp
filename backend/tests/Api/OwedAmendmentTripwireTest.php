<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;

/**
 * An owner-ruled amendment that is recorded but NOT YET APPLIED is owed at the
 * moment the operation it governs is built — and until this class existed, the
 * only thing standing between that build and a permission set the ruling
 * forbids was a sentence in `docs/API.md` §15.1 that somebody had to read.
 *
 * **Why this class is a test and not a register entry.** ADR-008's own D2 rule
 * is that work stays owed *"ON THE SEEDER … not on a date, and not on someone
 * remembering"*, and `docs/RESIDUAL_GAPS.md` CD-2 is carried the same way — its
 * pointer lives in `WriteOwnershipTest`'s failure message, where the person is
 * already standing. This class applies that pattern to ADR-008 **Amendment 2.1**.
 * A prose reminder cannot fail; this can.
 *
 * **The domain is the GENERATED SPEC, deliberately, not the documents.** Every
 * doc-parsing test in this suite SKIPS where `docs/` is absent (it is gitignored
 * — ROADMAP ruling B), so a tripwire that read `docs/API.md` would be silent in
 * exactly the environment that has no human reading the row either. The spec is
 * built from the running application, so this check has the same force
 * everywhere.
 *
 * **What it does NOT claim.** It does not detect whether A2.1 has been applied —
 * nothing mechanical can, because §4's role table is prose in a gitignored
 * document and `SecurityModelDocument` parses §3 only. It detects the *trigger*
 * and refuses to let it pass silently. Clearing the tripwire is a deliberate
 * edit to {@see self::OPERATIONS_OWING_AN_UNAPPLIED_AMENDMENT}, made in the same
 * commit that applies the amendment — the `PINNED_ALLOWLIST` mechanism this
 * suite uses elsewhere.
 */
final class OwedAmendmentTripwireTest extends ApiTestCase
{
    /**
     * `METHOD path` (as {@see GeneratedSpec::operations()} keys them) => the
     * amendment that must be applied in the same commit that builds it.
     *
     * Paths are the ones `docs/API.md` §4.2 gives these rows; all three are
     * `unbuilt` in §15.1 at `e87d731`. **Keyed by path rather than operationId
     * because the path is what the spec exposes** — an operation renamed on its
     * way to being built would slip an operationId pin.
     */
    private const OPERATIONS_OWING_AN_UNAPPLIED_AMENDMENT = [
        'POST /api/v1/invoices/{id}/void' => 'VoidInvoice',
        'POST /api/v1/invoices/{id}/credit-notes' => 'IssueCreditNote',
        'POST /api/v1/fulfilments/{id}/returns' => 'CreateSalesReturn',
    ];

    /**
     * The positive control, and it is not decoration. **The first version of this
     * class was FAKE-GREEN**: it pinned the paths `docs/API.md` §4.2 prints
     * (`/invoices/{id}/void`) while the generated spec prefixes every path with
     * `/api/v1`, so the intersection was vacuously empty and a planted BUILT
     * operation did not turn it red. This is the empty-set trap this suite has
     * recorded before — an exhaustive check whose domain silently does not
     * overlap what it is checking.
     *
     * Held here so the prefix assumption cannot rot again: if the spec's path
     * shape moves, THIS fails, rather than the tripwire going quietly blind.
     */
    private const A_PATH_KNOWN_TO_BE_BUILT = 'POST /api/v1/purchase-orders';

    /**
     * ADR-008 Amendment 2.1 widens SoD-3's controlled half to
     * `invoice.issue` / `invoice.pay` **or** `invoice.void` **or**
     * `credit_note.issue`. It is owner-ruled (OI-2, 2026-09-14) and **unapplied**:
     * ADR-008 §2's SoD-3 row still reads `invoice.issue` / `invoice.pay` alone,
     * and `SECURITY_MODEL.md` §4.1 says so in terms — *"Nothing in this
     * subsection is in §3's catalogue or §4's role table yet, and that is
     * deliberate."*
     *
     * **Mutation proof (run 2026-09-18, Move 217) — and it caught a real defect
     * on the first attempt, which is why it is recorded rather than summarised.**
     * Planting `'POST /purchase-orders' => 'CreatePurchaseOrder'` — a BUILT
     * operation — left this **GREEN**, because the pin used `docs/API.md` §4.2's
     * unprefixed paths while the spec prefixes `/api/v1`. The check was comparing
     * disjoint sets. Fixed by prefixing the pin and adding
     * {@see self::A_PATH_KNOWN_TO_BE_BUILT} as a positive control; re-planting
     * `'POST /api/v1/purchase-orders'` then turned it **RED**, naming that path
     * and printing the whole message below, and removing it returned it to GREEN
     * on the three unbuilt rows. **A pin nobody has seen fail is not evidence,
     * and this one was not evidence until the second run.**
     */
    #[Test]
    public function noOperationOwingAnUnappliedAmendmentHasBeenBuilt(): void
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $built = array_keys(GeneratedSpec::operations($spec));

        self::assertContains(self::A_PATH_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the spec no longer spells built paths the way this pin does, so the '
            .'tripwire below would compare two disjoint sets and pass on everything. Re-derive the '
            .'path shape from the spec before trusting any result from this class.');

        $arrived = array_intersect_key(
            self::OPERATIONS_OWING_AN_UNAPPLIED_AMENDMENT,
            array_fill_keys($built, true),
        );

        self::assertSame([], $arrived, $this->pointer($arrived));
    }

    /**
     * The pointer goes in the failure message, not in a list somebody has to
     * remember to read. Owner ruling 2026-09-18: the project's read-before list
     * is load-bearing *because* it is short, so a new obligation earns its place
     * at the point of use instead.
     *
     * @param array<string, string> $arrived
     */
    private function pointer(array $arrived): string
    {
        return \sprintf(
            "%s has been built, and ADR-008 Amendment 2.1 has NOT been applied.\n\n"
            ."A2.1 is OWNER-RULED (OI-2, 2026-09-14) and widens SoD-3's controlled half to\n"
            ."`invoice.issue` / `invoice.pay` OR `invoice.void` OR `credit_note.issue`. Applying it\n"
            ."takes `invoice.void` off `admin`. Until it is applied, whoever issues an invoice can\n"
            ."also cancel it, which is the separation this ruling exists to create.\n\n"
            ."DO THIS IN THIS COMMIT, or move this pin deliberately and say why:\n"
            ."  1. ADR-008 §2's SoD-3 row — widen the controlled half. A2.6 requires §3's new codes,\n"
            ."     §4's `admin` change, PC-13's pair table and A2.5's run IN THE SAME PASS.\n"
            ."  2. `SECURITY_MODEL.md` §4.1's *Becomes false when built* list — TEN sentences, not\n"
            ."     nine: `IA.md:425`'s L-9 reversal-register row was added 2026-09-18 and is owned by\n"
            ."     no other register.\n"
            ."  3. SEC-O-18 decides WHO may void once `admin` cannot. It is DEFERRED, its deadline is\n"
            ."     this build, and `admin` = 53 is invariant across both of its live outcomes — so its\n"
            ."     answer does not block §4's `admin` edit.\n"
            ."  4. Re-run `python3 docs/adr/sod_check.py` — but DO NOT paste its `admin` count as\n"
            ."     evidence: `ADMIN_EXCL` is typed, not derived, so that assertion validates a number\n"
            ."     the same edit chose. Its check-back is a SUBSET test and CANNOT see A2.1 entering\n"
            ."     §2. Prefer ADR-008 D2's committed SoD port (V2-R-2) over editing that constant.\n\n"
            ."Full entry: `docs/DELIVERY_LEDGER.md` risk register, row R-39 (scheduled Output 9 / M2, on this build).\n"
            ."Whether A2.1 may be applied in halves (A2.1a `invoice.void` / A2.1b `credit_note.issue`)\n"
            ."is the OWNER's call and was still open at Move 217 — do not split it unasked.",
            implode(', ', $arrived),
        );
    }
}
