<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit reads C0b — **G-35 (f): no invoice may be due more than 365 days after issue** (was 3650;
 * `docs/session13/audit-reads-owner-q-ruling.md` §2.2–§2.4 / §5 C0b, judge 2026-10-20 under the owner's delegation; owner
 * notice given 2026-10-20). `invoice_due_window` is dropped and re-added at `interval '365 days'`, in the identical UTC
 * form (`Version20260923000100`: days are added on the UTC calendar, so the bound does not move an hour across a DST
 * change in the session's zone). `PaymentTerms::MAXIMUM_DAYS` moves to 365 in the same commit, and
 * `PaymentTermsBoundTest` holds the two equal and asserts the constraint is validated.
 *
 * **It refuses; it never clamps.** The re-added constraint is validated (no `NOT VALID`), so it scans every stored invoice;
 * one due beyond 365 days fails this migration loudly (23514) and the transaction leaves the 3650 constraint in place.
 * Clamping would silently rewrite a commercial term with no audit row. **Measured 2026-10-20 on a scratch database**: an
 * invoice due 400 days after issue, seeded in a rolled-back transaction, made the re-add fail as the schema owner, although
 * that owner's own `SELECT count(*) FROM invoice` saw **0** rows (FORCE ROW LEVEL SECURITY, no tenant GUC) — constraint
 * validation is not filtered by RLS. With the row removed, the same statements succeeded with `convalidated = true`.
 *
 * **Why there is no customer-row guard here:** `customer` is under FORCE ROW LEVEL SECURITY too, so a count run as the owner
 * sees nothing without a per-tenant loop (measured on `invoice` as above) — a guard would be vacuous. A customer stored
 * above 365 days stays readable; an edit that omits terms succeeds; an edit that resends them is refused 422, and its next
 * invoice is refused 409 `invoice.payment_terms_are_usable` (*"correct the customer's terms first"*) — the safe direction.
 * **Why there is no CHECK on `customer.payment_terms_days`:** it would make `IssueInvoice`'s step-4 refusal unseedable and
 * unreachable (`PaymentTerms` docblock; `customers-s1-design.md` §4.1). A full-form client that resends unchanged terms
 * above 365 is refused, because `UpdateCustomer` validates shape before it diffs (`CustomerWriter` docblock).
 *
 * `down()` restores the 3650-day constraint, same form.
 */
final class Version20261020001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit reads C0b (G-35 (f)): invoice_due_window narrowed from 3650 to 365 days, validated, never clamped.';
    }

    public function up(Schema $schema): void
    {
        $this->reissue(365);
    }

    public function down(Schema $schema): void
    {
        $this->reissue(3650);
    }

    private function reissue(int $days): void
    {
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT invoice_due_window');
        $this->addSql(sprintf(<<<'SQL'
            ALTER TABLE invoice ADD CONSTRAINT invoice_due_window CHECK (
                due_at >= issued_at
                AND due_at <= ((issued_at AT TIME ZONE 'UTC') + interval '%d days') AT TIME ZONE 'UTC'
            )
            SQL, $days));
    }
}
