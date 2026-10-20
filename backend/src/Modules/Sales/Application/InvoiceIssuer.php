<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\AuditLog\Infrastructure\InvoiceAuditWriter;
use App\Modules\Customers\Application\Contract\PaymentTerms;
use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`IssueInvoice` — one invoice per shipment (D-A as amended; FLOWS F-5 step 7), one transaction.** Sales step 6,
 * C3 (2026-09-28), built from `docs/session13/output9-sales-7-design.md` §19 under the owner's rulings of 2026-09-23
 * (`-ruling.md`: Q1 *"What shipped"*, Q2 *"Worked out when shown"*, Q5 *"Column + audit event"*, Q9, Q12) and CD-7's
 * ruling CD7-1 (2026-09-28, the number cap).
 *
 * **Raw SQL only, and on purpose.** `erp_app` holds a COLUMN-level INSERT on exactly the ten columns the INSERT below
 * names (`Version20260928000200`); the ORM would insert `status`, `issued_at`, `paid_at`, `created_at` and
 * `updated_at` too and meet `42501`. That refusal is the grant doing its job — fix a `42501` here by removing the
 * column from the statement, never by widening the grant.
 *
 * The statement order (design §19 C3):
 *  1. The E-1 gate (`invoice.issue`) has already run — `PermissionGate`, on `kernel.controller`.
 *  2. The fulfilment, for **not-found only**. RLS bounds it to the caller's workspace, so another workspace's
 *     fulfilment and a random uuid are the same 404 (E-2 / ST-5). It reads the fulfilment ALONE, so nothing touches
 *     the order before step 2a.
 *  2a. **`SalesOrderRowLock` on the shipment's order — first** (sales step 7 R2, 2026-10-01; `ARCH` §6(c), W-5's
 *     sales document order). `CreateSalesReturn` takes the same lock, so a return and an issue of one shipment
 *     serialise: a return is deducted by this invoice or stamped with it, never both and never neither.
 *  3. `assertFulfilmentIsNotAlreadyInvoiced()` — a live (non-void) invoice for this shipment refuses the request:
 *     **a repeat is refused, never replayed** (§4.2 item 1; `Idempotency-Key` is required by API-14 and unused). The
 *     live number is disclosed only to a holder of `invoice.view` (R-14), never the id, total or customer. Named
 *     `assert…` and not `refuseUnless…`, so it stays outside `EditWindowGuardTest` clause (b): it is not an edit
 *     window.
 *  4. The customer's payment terms must be 0–365 days (one year since G-35 (f), C0b) (`invoice.payment_terms_are_usable`): `invoice_due_window`
 *     would otherwise refuse as a 23514 → 500. The bound is {@see PaymentTerms::MAXIMUM_DAYS} — one source with
 *     `CreateCustomer`/`UpdateCustomer`'s refusal since customers slice 1 C2 (`PaymentTermsBoundTest` holds it equal to
 *     the CHECK); this step stays for a row stored outside it by SQL.
 *  5. The amount: `SUM(round((fulfilment_line.quantity − returned_before_issue) × sales_order_line.unit_price, 4))`
 *     — **what shipped, less the returns classified as deducting** (owner ruling Q1 as refined by Q-L (a′),
 *     2026-09-30: a return recorded before any invoice, by a `fulfilment.create` holder other than the shipper or by
 *     an owner; `sales_return.deducts`). Every other return reduces the invoice only through a credit note. Rounded
 *     per line (D-A, G-16 unchanged), joined on `(tenant_id, id)` pairs with **no** `deleted_at` filter (an order line
 *     archived after it shipped still priced what shipped). **Q-G (1):** a shipment whose every line was returned
 *     before billing is refused (`invoice.fulfilment_has_something_to_bill`) — keyed on QUANTITIES, so a zero-priced
 *     shipment still issues a zero invoice, as before. A negative total (`unit_price` has no sign CHECK, M-12) and a
 *     total wider than `numeric(19,4)` are refused in domain terms before the INSERT can 23514/22003.
 *  6. The number: `MAX + 1` over every invoice of the workspace (a voided one included: numbers are never
 *     reissued). **The 1,000,000th is refused** (`invoice.number_series_is_exhausted`, Q12 + CD-7) **before any
 *     formatting**, and only then padded by PHP (SQL `lpad` truncates). It runs AFTER step 3, so a repeat on the
 *     shipment billed as `INV-999999` is told it is already invoiced, not that the series is exhausted.
 *  7. `INSERT INTO invoice (<the ten>) SELECT … FROM fulfilment JOIN sales_order` — `sales_order_id`,
 *     `customer_id` and `currency_code` are copied from the **billed shipment's own order in the same statement**
 *     (the three-copy read-back, `ARCH` §1), `tenant_id` is the REQUEST's (`SEC` §9.4) and `issued_by_user_id` the
 *     token subject. `due_at` is computed by the database in UTC from `now()` — the transaction timestamp, which is
 *     also `issued_at`'s default — so both sides of `invoice_due_window` come from one clock (RED-3 F-1) and a DST
 *     change in the session zone cannot move the day (Q2). `RETURNING id` must yield exactly one row.
 *  8. `invoice.issued`, metadata `{}`, in the same transaction (Q5; `SEC` §5.1). Then the 201 body, read back.
 *  9. **A 23505, outside the failed transaction**: `invoice_number_unique` (a concurrent issue took the number) and
 *     `invoice_tenant_fulfilment_not_void` (a concurrent issue of the SAME shipment) are both answered by re-reading,
 *     in a fresh transaction, whether this shipment now has a live invoice — step 3's refusal if so; "try again",
 *     naming nothing, if not.
 *
 * **What it deliberately does not do:** take the INVOICE row lock (it creates the row; `PurchaseOrderRowLockTest`'s
 * `INVOICE_ALLOWED_WITHOUT_A_LOCK`); expose or accept any request field (§4.2: `—`). *(Until 2026-10-01 this read "lock
 * the order … deduct returned quantities (Q1: none can exist yet)": both were owed to `CreateSalesReturn`'s commit by
 * `OwedAmendmentTripwireTest`'s returned-deduction pin, and both landed in it.)*
 */
final readonly class InvoiceIssuer
{
    private const NUMBER_INDEX = 'invoice_number_unique';

    private const LIVE_INDEX = 'invoice_tenant_fulfilment_not_void';

    /** `numeric(19,4)`: at most fifteen integer digits. */
    private const TOTAL_CEILING = '1000000000000000';

    public const LAST_NUMBER = 999999;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private InvoiceReader $reader,
        private InvoiceAuditWriter $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function issue(string $fulfilmentId): InvoiceView
    {
        if (!Uuid::isValid($fulfilmentId)) {
            throw ApiProblem::notFound();
        }
        $fulfilmentId = Uuid::fromString($fulfilmentId)->toRfc4122();
        $identity = $this->identities->authenticated();
        $id = Uuid::v4()->toRfc4122();

        try {
            return $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): InvoiceView => $this->issueInside($em, $id, $fulfilmentId, $identity),
            );
        } catch (UniqueConstraintViolationException $e) {
            // 9. Outside `run()`: the failed statement aborted the transaction, so the re-read opens a fresh one, in
            // the Identity's own workspace.
            $message = $e->getMessage();
            $number = str_contains($message, self::NUMBER_INDEX);
            $live = str_contains($message, self::LIVE_INDEX);
            if (!$number && !$live) {
                throw $e;
            }

            $liveNumber = $this->liveInvoiceNumberAfterAFailedTransaction($identity->tenantId, $fulfilmentId);

            if (null !== $liveNumber || $live) {
                throw self::alreadyInvoiced($identity, $liveNumber);
            }

            throw ApiProblem::businessRuleViolated(
                'invoice.invoice_number_is_unique_per_workspace',
                'Another invoice was issued at the same moment. Try again.',
            );
        }
    }

    private function issueInside(EntityManagerInterface $em, string $id, string $fulfilmentId, Identity $identity): InvoiceView
    {
        $connection = $em->getConnection();

        // 2.
        $orderId = $connection->fetchOne('SELECT sales_order_id FROM fulfilment WHERE id = ?::uuid', [$fulfilmentId]);
        if (false === $orderId) {
            throw ApiProblem::notFound();
        }

        // 2a.
        SalesOrderRowLock::holdInside($em, (string) $orderId);

        $fulfilment = $connection->fetchAssociative(<<<'SQL'
            SELECT f.id, c.payment_terms_days
              FROM fulfilment f
              JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
              JOIN customer c ON c.tenant_id = so.tenant_id AND c.id = so.customer_id
             WHERE f.id = ?::uuid
            SQL, [$fulfilmentId]);
        if (false === $fulfilment) {
            throw new \LogicException('A fulfilment found before its order\'s lock is not readable after it.');
        }

        // 3.
        $this->assertFulfilmentIsNotAlreadyInvoiced($em, $fulfilmentId, $identity);

        // 4.
        $terms = (int) $fulfilment['payment_terms_days'];
        if ($terms < PaymentTerms::MINIMUM_DAYS || $terms > PaymentTerms::MAXIMUM_DAYS) {
            throw ApiProblem::businessRuleViolated(
                'invoice.payment_terms_are_usable',
                \sprintf('This customer\'s payment terms are %d days. An invoice can only be issued on terms of 0 to %d days; correct the customer\'s terms first.',
                    $terms, PaymentTerms::MAXIMUM_DAYS),
            );
        }

        // 5. `billed(fl)` is `SalesReturnReader::billed()` — ONE copy of the issuer's formula (C5 C4b, `c5-red.md` RED round 2
        // F-1): `DeleteCustomer`'s unbilled-shipment count (`CustomerOpenSalesWork`), the credit writer and the decision writer
        // read the same term, so "billable" cannot mean one thing here and another there (`AdjustmentDecisionTermsTest`).
        $amount = $connection->fetchAssociative(\sprintf(<<<'SQL'
            SELECT count(*) AS joined,
                   count(*) FILTER (WHERE %1$s > 0) AS billable,
                   COALESCE(SUM(round(%1$s * sol.unit_price, 4)), 0)::text AS total,
                   (SELECT count(*) FROM fulfilment_line x WHERE x.tenant_id = ?::uuid AND x.fulfilment_id = ?::uuid) AS lines
              FROM fulfilment_line fl
              JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
             WHERE fl.tenant_id = ?::uuid AND fl.fulfilment_id = ?::uuid
            SQL, SalesReturnReader::billed('fl')), [$identity->tenantId, $fulfilmentId, $identity->tenantId, $fulfilmentId]);
        if (false === $amount || (int) $amount['joined'] !== (int) $amount['lines']) {
            throw new \LogicException('A fulfilment line did not join its order line, so the invoice total would omit it.');
        }
        if (0 === (int) $amount['billable']) {
            throw ApiProblem::businessRuleViolated(
                'invoice.fulfilment_has_something_to_bill',
                'Everything on this shipment came back before it was invoiced, so there is nothing to bill.',
            );
        }
        $total = (string) $amount['total'];
        if (bccomp($total, '0', 4) < 0) {
            throw ApiProblem::businessRuleViolated(
                'invoice.total_is_not_negative',
                'The lines of this shipment price to less than nothing, so no invoice can be issued for it. Correct the order\'s unit prices first.',
            );
        }
        if (bccomp($total, self::TOTAL_CEILING, 4) >= 0) {
            throw ApiProblem::businessRuleViolated(
                'invoice.total_fits',
                'This shipment\'s total is larger than an invoice can hold (fifteen digits before the decimal point).',
            );
        }

        // 6.
        $number = self::nextInvoiceNumber($em);

        // 7.
        $inserted = $connection->fetchFirstColumn(<<<'SQL'
            INSERT INTO invoice
                (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number,
                 currency_code, total_amount, due_at, issued_by_user_id)
            SELECT ?::uuid, ?::uuid, f.id, so.id, so.customer_id, ?,
                   so.currency_code, ?::numeric,
                   ((now() AT TIME ZONE 'UTC') + make_interval(days => ?::int)) AT TIME ZONE 'UTC',
                   ?::uuid
              FROM fulfilment f
              JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
             WHERE f.id = ?::uuid
            RETURNING id
            SQL, [$id, $identity->tenantId, $number, $total, $terms, $identity->userId, $fulfilmentId]);
        if (1 !== \count($inserted)) {
            throw new \LogicException(\sprintf('Issuing an invoice inserted %d rows, not one.', \count($inserted)));
        }

        // 8.
        $this->audit->recordIssued(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            invoiceId: $id,
        );

        return $this->reader->readInside($em, $id)
            ?? throw new \LogicException('The invoice just issued is not visible to its own transaction.');
    }

    /**
     * Step 3. A shipment has at most one live invoice (`invoice_tenant_fulfilment_not_void`); a void one releases it.
     * The index is the backstop for two concurrent issues; this is the answer for a sequential repeat.
     */
    private function assertFulfilmentIsNotAlreadyInvoiced(EntityManagerInterface $em, string $fulfilmentId, Identity $identity): void
    {
        $liveNumber = self::liveInvoiceNumber($em, $fulfilmentId);
        if (null !== $liveNumber) {
            throw self::alreadyInvoiced($identity, $liveNumber);
        }
    }

    private static function liveInvoiceNumber(EntityManagerInterface $em, string $fulfilmentId): ?string
    {
        $number = $em->getConnection()->fetchOne(
            'SELECT invoice_number FROM invoice WHERE fulfilment_id = ?::uuid AND status <> ?::text',
            [$fulfilmentId, Invoice::STATUS_VOID],
        );

        return false === $number ? null : (string) $number;
    }

    /**
     * Step 9's re-read. **Not `TenantContext::run()`**: Doctrine closes the entity manager when an exception leaves
     * `wrapInTransaction()`, so a second `run()` in the same request fails with *"The EntityManager is closed"*
     * (measured on the first run of the D-3 race tests, 2026-09-28). The manager's CONNECTION is still usable — its
     * transaction was rolled back — so the re-read opens its own transaction on it, binds the Identity's tenant through
     * `TenantContext::establishOn()` (the one sanctioned writer of tenant context), reads, and rolls back.
     */
    private function liveInvoiceNumberAfterAFailedTransaction(string $tenantId, string $fulfilmentId): ?string
    {
        // Through the manager, never an injected `Connection` (A-10): `getConnection()` answers on a closed manager.
        $this->em->getConnection()->beginTransaction();
        try {
            $this->tenants->establishOn($this->em->getConnection(), $tenantId);

            return self::liveInvoiceNumber($this->em, $fulfilmentId);
        } finally {
            $this->em->getConnection()->rollBack();
        }
    }

    /**
     * R-14: the live invoice's number is appended only for a caller who may read invoices. A composed role holding
     * `invoice.issue` without `invoice.view` learns that the shipment is invoiced — the operation's own precondition,
     * `SEC`'s accepted residual — and nothing else: never the number, id, total or customer.
     */
    private static function alreadyInvoiced(Identity $identity, ?string $liveNumber): ApiProblem
    {
        $detail = 'This shipment already has an invoice that has not been voided.';
        if (null !== $liveNumber && $identity->holds('invoice.view')) {
            $detail .= ' It is '.$liveNumber.'.';
        }

        return ApiProblem::businessRuleViolated('invoice.fulfilment_is_not_already_invoiced', $detail);
    }

    private static function nextInvoiceNumber(EntityManagerInterface $em): string
    {
        $next = (int) $em->getConnection()->fetchOne(
            'SELECT COALESCE(MAX(substring(invoice_number FROM 5)::int), 0) + 1 FROM invoice',
        );

        // Q12 + CD-7 (owner ruling CD7-1, 2026-09-28): `invoice_number_shape` pins six digits, so the millionth is
        // refused here in domain terms rather than left to the CHECK as a 23514 -> 500. Counted over every row,
        // voided ones included: numbers are never reissued. Decided BEFORE any formatting (`ARCH` §2).
        if ($next > self::LAST_NUMBER) {
            throw ApiProblem::businessRuleViolated(
                'invoice.number_series_is_exhausted',
                'This workspace has used every invoice number from INV-000001 to INV-999999, so no further invoice can be issued. Numbers are never reissued.',
            );
        }

        return 'INV-'.str_pad((string) $next, 6, '0', \STR_PAD_LEFT);
    }
}
