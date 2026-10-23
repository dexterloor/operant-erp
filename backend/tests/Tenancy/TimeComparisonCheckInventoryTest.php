<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\Sales\Application\InvoiceIssuer;
use App\Modules\Sales\Application\InvoicePayment;
use App\Modules\Sales\Application\InvoicePaymentReversal;
use App\Modules\UserManagement\Application\RoleGrantProposalCreation;
use App\Modules\UserManagement\Infrastructure\Entity\RoleGrantProposal;
use App\Modules\UserManagement\Infrastructure\Messaging\PasswordResetRequestedHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Every CHECK that orders two instants is accounted for — the trigger, not the bug** (proposal clock fix, 2026-10-21;
 * `docs/session13/proposal-clock-fix-design.md` §3.4 with `proposal-clock-fix-red.md` M-3's domain).
 *
 * **The rule it carries:** the database clock is a WALL clock. Two instants read in different transactions can come out
 * in the wrong order when it steps back (the Lima host agent pulls a fast VM guest back ~0.1 s every few seconds; NTP can
 * step a server). So a CHECK requiring a later transaction's instant to be "not before" an earlier one needs the writer
 * to clamp: write `max(<this transaction's clock>, <the earlier instant>)`, bounded to 60 seconds so a row written outside
 * the application is not silently absorbed. Two instants written by ONE statement from one value are safe as they are.
 *
 * **Domain, from the server:** `pg_constraint` CHECKs on non-partition relations (an inherited CHECK repeats on every
 * partition) of the application schema naming two or more `timestamptz` / `timestamp` / `date` columns — plus a second
 * arm: any CHECK whose definition calls a clock function (`now()`, `clock_timestamp()`, `current_*`, …). The two arms
 * together must equal the pinned map exactly, so a new comparing CHECK is red here until someone says how it is made safe.
 *
 * **What it cannot see, honestly:** whether the clamp named in the map is really there (the behaviour tests are
 * `RoleGrantProposalClockStepTest` and `InvoiceClockStepTest`; a hand that adds a map entry without a clamp defeats this
 * test — the residual `OwedAmendmentTripwireTest` carries too); trigger bodies (`cascade_deletion_to_lines` compares
 * `deleted_at` by EQUALITY as identity — a different class, not step-sensitive); PHP-side comparisons
 * (`InvoicePayment::paidAt()`'s issue check); and test-side assertions across transactions.
 */
final class TimeComparisonCheckInventoryTest extends TenancyTestCase
{
    /**
     * Constraint name → how it is safe against a backward step, naming the method that makes it so (asserted to exist).
     *
     * @var array<string, array{0: string, 1: class-string, 2: string}>
     */
    private const SAFE = [
        'invoice_due_window' => ['one statement: due_at and issued_at from the same now() in the issuing INSERT', InvoiceIssuer::class, 'issue'],
        'invoice_paid_not_before_issue' => ['clamped: paid_at = max(min(requested, now), issued_at), within 60 s', InvoicePayment::class, 'paidAt'],
        // Mail-C1: one statement, one clock — the worker binds created_at and expires_at = created_at + 30 min from ONE
        // ClockInterface reading in the token INSERT; no later transaction writes either.
        'password_reset_token_window' => ['one statement: created_at and expires_at = created_at + 1800 s from one worker clock reading in the token INSERT', PasswordResetRequestedHandler::class, '__invoke'],
        'invoice_payment_reversed_not_before_issue' => ['clamped: GREATEST(now(), issued_at) in MARK_REVERSED, within 60 s', InvoicePaymentReversal::class, 'reverse'],
        'role_grant_proposal_decided_not_before_proposed' => ['clamped: decided_at = max(at, proposed_at), within 60 s', RoleGrantProposal::class, 'decisionInstant'],
        'role_grant_proposal_expiry_window' => ['one statement: proposed_at and expires_at from one clock_timestamp() in the INSERT', RoleGrantProposalCreation::class, 'propose'],
    ];

    private const TWO_INSTANTS = <<<'SQL'
        SELECT c.conname
          FROM pg_constraint c
          JOIN pg_class cl ON cl.oid = c.conrelid
          JOIN pg_namespace n ON n.oid = cl.relnamespace
         WHERE c.contype = 'c' AND n.nspname = 'public' AND NOT cl.relispartition
           AND (SELECT count(*) FROM unnest(c.conkey) k
                  JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k
                 WHERE a.atttypid = ANY ('{timestamptz,timestamp,date}'::regtype[])) >= 2
         ORDER BY c.conname
        SQL;

    private const CALLS_A_CLOCK = <<<'SQL'
        SELECT c.conname
          FROM pg_constraint c
          JOIN pg_class cl ON cl.oid = c.conrelid
          JOIN pg_namespace n ON n.oid = cl.relnamespace
         WHERE c.contype = 'c' AND n.nspname = 'public' AND NOT cl.relispartition
           AND pg_get_constraintdef(c.oid) ~* '(now\(\)|clock_timestamp|statement_timestamp|transaction_timestamp|current_(date|time|timestamp)|localtime)'
         ORDER BY c.conname
        SQL;

    #[Test]
    public function everyCheckOrderingTwoInstantsIsMadeSafeAgainstABackwardClockStep(): void
    {
        $derived = array_map('strval', $this->owner()->fetchFirstColumn(self::TWO_INSTANTS));

        self::assertContains('invoice_due_window', $derived, 'Anti-vacuity: the derived set lost a CHECK known to compare two instants, so the query is blind.');

        $pinned = array_keys(self::SAFE);
        sort($pinned);
        self::assertSame($pinned, $derived,
            "The CHECKs that order two instants changed. A later transaction's instant compared with an earlier one must be "
            .'written as max(<this clock>, <the earlier instant>) within 60 seconds — the database clock can step back '
            .'(docs/session13/proposal-clock-fix-design.md §3). Clamp the writer, then add the constraint to SAFE with the method that clamps it.');
    }

    /** The second arm runs and finds nothing today: no CHECK compares a column with the clock itself. */
    #[Test]
    public function noCheckComparesAColumnWithTheClock(): void
    {
        $rows = $this->owner()->fetchFirstColumn(self::CALLS_A_CLOCK);

        self::assertSame([], $rows, 'A CHECK calls a clock function: its truth changes with time and with every clock step. Rule on it before it ships.');

        // Control: the arm's pattern does match a clock call, so an empty answer is not a broken regex.
        self::assertTrue((bool) $this->owner()->fetchOne(
            "SELECT 'CHECK ((paid_at <= now()))' ~* '(now\\(\\)|clock_timestamp|statement_timestamp|transaction_timestamp|current_(date|time|timestamp)|localtime)'"));
    }

    /** Each map entry names a method that exists, so a reader can check the stated mechanism. */
    #[Test]
    public function everyEntryNamesARealMethod(): void
    {
        foreach (self::SAFE as $constraint => [, $class, $method]) {
            self::assertTrue(method_exists($class, $method), $constraint.': '.$class.'::'.$method.' does not exist.');
        }
    }
}
