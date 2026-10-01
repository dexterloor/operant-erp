<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\DatabaseRoles;
use App\Tests\Support\CreditedSegment;
use PHPUnit\Framework\Attributes\Test;

/**
 * **The live-grant pin on TB-4's Credited segment** (sales step 7 C0; `output9-sales-8-c0-amendments.md` A6-a, revised
 * after amendments RED-2 H-1, L-1, L-2). The Credited exception holds only while an invoice, once credited in full,
 * can never leave Credited (`IA.md` §5.4 ³, condition (1)). On the server that means **no write can lower an invoice's
 * credited sum or raise its total**:
 *   - no role may UPDATE any column of `credit_note` / `credit_note_line` (re-pointing a note's `invoice_id`, or
 *     lowering its `total_amount`, lowers a sum), nor DELETE or TRUNCATE either table;
 *   - no role may UPDATE `invoice.total_amount`;
 *   - and an INSERT cannot lower a sum, because `credit_note_total_positive` is present and validated.
 *
 * **Why not `GrantFootprintTest`:** it compares the server with `SchemaManifest`, so a builder who widens both together
 * stays green. These expectations are hard-coded, on purpose.
 *
 * **The domain is the server's** (RED-2 L-1, `SchemaGuardTest`'s source): every role in `pg_roles` that is not a
 * superuser, not the schema owner (asked of the owner connection, never a literal) and not a `pg_*` role — NOLOGIN
 * roles included. Control: every `DatabaseRoles::APPLICATION_ROLES` name is in it.
 * **UPDATE is asked with `has_any_column_privilege`**, true for a table-level grant OR any column grant:
 * `has_table_privilege` answers false for a column-level grant, and column grants are how this project grants writes.
 * DELETE and TRUNCATE are asked with `has_table_privilege` (`has_any_column_privilege` raises
 * `unrecognized privilege type` for them, `GrantFootprintTest`).
 *
 * **Limits, stated so they are not mistaken for cover:**
 *   - it cannot see an INSERT-only reversal DOCUMENT (a new table): such a document lowers nothing until the predicate
 *     subtracts it, so the `operationId` allow-list and `InvoiceCreditTest`'s singleness scan are the pins for that;
 *   - **owner-run code is out of its sight — a different gap from G-15** (RED-2 L-2). G-15 is forgery by SQL. A
 *     legitimate owner process (the `app:roles:reconcile` precedent) or a **data-writing migration** that rewrites
 *     `credit_note` is not forgery, and this pin cannot see it. Partial cover: an UPDATE or DELETE on `credit_note`
 *     added to `src/` moves the Sales module's pin in `WriteOwnershipTest` (S-7(c)). **Gap: migrations are not
 *     scanned;**
 *   - a superuser (G-15);
 *   - the LOCAL cluster only; Neon is unproven (G-3).
 */
final class CreditedSegmentLapseTest extends TenancyTestCase
{
    /** Measured 2026-10-01 off `pg_get_constraintdef` after `Version20261001000500`. */
    private const TOTAL_POSITIVE_DEFINITION = 'CHECK ((total_amount > (0)::numeric))';

    #[Test]
    public function noRoleCanLowerAnInvoicesCreditedSumOrRaiseItsTotal(): void
    {
        $roles = $this->domain();

        // Domain control: the derived set is not empty and holds every application role.
        foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
            self::assertContains($role, $roles, 'The server-derived role domain lost '.$role.', so the pin below would skip it.');
        }

        $violations = [];
        foreach ($roles as $role) {
            foreach (['credit_note', 'credit_note_line'] as $table) {
                if ($this->ask('SELECT has_any_column_privilege(?, ?, \'UPDATE\')', [$role, $table])) {
                    $violations[] = $role.' can UPDATE a column of '.$table;
                }
                foreach (['DELETE', 'TRUNCATE'] as $privilege) {
                    if ($this->ask('SELECT has_table_privilege(?, ?, ?)', [$role, $table, $privilege])) {
                        $violations[] = $role.' holds '.$privilege.' on '.$table;
                    }
                }
            }
            if ($this->ask('SELECT has_column_privilege(?, \'invoice\', \'total_amount\', \'UPDATE\')', [$role])) {
                $violations[] = $role.' can UPDATE invoice.total_amount';
            }
        }

        self::assertSame([], $violations, CreditedSegment::LAPSE."\n  ".implode("\n  ", $violations));

        // The CHECK that stops an INSERT from lowering the sum: present, validated, and the measured definition.
        $check = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.convalidated, pg_get_constraintdef(c.oid) AS def
              FROM pg_constraint c
             WHERE c.conrelid = 'credit_note'::regclass AND c.conname = 'credit_note_total_positive' AND c.contype = 'c'
            SQL);
        self::assertSame([['convalidated' => true, 'def' => self::TOTAL_POSITIVE_DEFINITION]], $check, CreditedSegment::LAPSE);

        // Positive controls, same method.
        // (1) The method sees a column-only grant: erp_app's invoice UPDATE is column-level, so the column question
        //     says yes where the table question says no. If the table question ever says yes, this control has lost
        //     its meaning — it would no longer prove that a column grant is seen.
        self::assertTrue($this->ask('SELECT has_any_column_privilege(?, \'invoice\', \'UPDATE\')', [DatabaseRoles::APP]),
            'Control: has_any_column_privilege no longer sees erp_app\'s column-level UPDATE on invoice.');
        self::assertFalse($this->ask('SELECT has_table_privilege(?, \'invoice\', \'UPDATE\')', [DatabaseRoles::APP]),
            'Control lost its meaning: erp_app now holds TABLE-level UPDATE on invoice, so the column-grant control above proves nothing.');
        self::assertTrue($this->ask('SELECT has_column_privilege(?, \'invoice\', \'status\', \'UPDATE\')', [DatabaseRoles::APP]),
            'Control: erp_app can no longer UPDATE invoice.status, so the per-column question is answering something else.');
        // (2) The tables are readable at all (C0's grant), so a false above is not a missing table.
        self::assertTrue($this->ask('SELECT has_table_privilege(?, \'credit_note\', \'SELECT\')', [DatabaseRoles::APP]),
            'Control: erp_app cannot read credit_note.');
        // (3) The constraint query finds a known existing CHECK.
        self::assertSame(1, (int) $this->owner()->fetchOne(<<<'SQL'
            SELECT count(*) FROM pg_constraint
             WHERE conrelid = 'invoice'::regclass AND conname = 'invoice_paid_by_matches_status' AND contype = 'c' AND convalidated
            SQL), 'Control: the constraint query no longer finds invoice_paid_by_matches_status.');
    }

    /** @return list<string> */
    private function domain(): array
    {
        $owner = (string) $this->owner()->fetchOne('SELECT current_user');

        return $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT rolname FROM pg_roles
             WHERE NOT rolsuper
               AND rolname <> ?
               AND rolname NOT LIKE 'pg\_%'
             ORDER BY 1
            SQL, [$owner]);
    }

    /** @param list<string> $parameters */
    private function ask(string $sql, array $parameters): bool
    {
        return (bool) $this->owner()->fetchOne($sql, $parameters);
    }
}
