<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Customers\Application\Port\CustomerOpenWork;
use App\Modules\Customers\Application\Port\CustomerOpenWorkCount;
use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **Sales' answer to {@see CustomerOpenWork}** (C5 C4b; `docs/session13/c5-design.md` Revision 1 R1.2 / R1.6;
 * `c5-red.md` RED round 2 F-1; `session29-c5-ruling.md` Q-1 = (b′)): what a customer still has open, in **ONE statement
 * with four scalar subqueries** — one READ COMMITTED snapshot, so a concurrent `VoidInvoice` cannot move a shipment
 * between "billed" and "unbilled" between two counts and let both miss it (mutation M17: split it → red,
 * `CustomerDeleteTest::theOpenWorkIsCountedInOneStatement`).
 *
 * The legs are **composed from the shipped owners, never re-spelled** (R1.2):
 *  1. **open sales orders** — live (`deleted_at IS NULL`) orders in `draft`, `confirmed` or `partially_fulfilled`, bound
 *     from `SalesOrder::STATUS_*` (API-15); `fulfilled` and `cancelled` are closed;
 *  2. **billable shipments with no live invoice** — a fulfilment of the customer's orders with no invoice whose status
 *     is not `void` (the `invoice_tenant_fulfilment_not_void` shape) and at least one line with
 *     {@see SalesReturnReader::billed()} `> 0` — **the issuer's own expression** (`InvoiceIssuer`'s `billable` count reads
 *     the same term since C4b, F-1), so a shipment returned in full before invoicing is not billable here either, and
 *     `IssueInvoice` would refuse it (`invoice.fulfilment_has_something_to_bill`). The order's `deleted_at IS NULL` is
 *     there for `sales_order_tenant_customer_idx` (partial on live rows); it removes nothing — only a draft may be
 *     archived (`sales_order_archived_only_while_draft`) and a draft has no shipment;
 *  3. **invoices the customer owes** — `issued`/`overdue` (`InvoiceCredit`'s own spelling) and NOT
 *     {@see InvoiceCredit::creditedInFull()} — credited in full is terminal (`PayInvoice` and `VoidInvoice` both refuse
 *     it), so counting it would make a dead end (RED H-1);
 *  4. **credits owed to the customer** — returns with {@see SalesReturnReader::owesACredit()}, joined to the fulfilment's
 *     live invoice as `live` exactly as `SalesReturnReader::headerSql()` joins it: `creditable` OR `blockedByPayment`, so
 *     a return owed a credit on a PAID invoice (G-23's queue) blocks too (RED H-2; mutation M21 — `creditable()` instead
 *     → the blocked-by-payment test is red).
 *
 * Reads only Sales' tables (A-2); RLS bounds every subquery to the workspace. Writes nothing.
 */
final readonly class CustomerOpenSalesWork implements CustomerOpenWork
{
    /** The statuses that keep a sales order open (R1.2 leg 1). */
    public const OPEN_ORDER_STATUSES = [
        SalesOrder::STATUS_DRAFT,
        SalesOrder::STATUS_CONFIRMED,
        SalesOrder::STATUS_PARTIALLY_FULFILLED,
    ];

    public function openWorkInside(EntityManagerInterface $em, string $customerId): CustomerOpenWorkCount
    {
        $row = $em->getConnection()->fetchAssociative(self::statement(), [$customerId, $customerId, $customerId, $customerId]);
        if (false === $row) {
            throw new \LogicException('A SELECT of four scalar subqueries returned no row.');
        }

        return new CustomerOpenWorkCount(
            (int) $row['open_sales_orders'],
            (int) $row['unbilled_shipments'],
            (int) $row['unpaid_invoices'],
            (int) $row['owed_credits'],
        );
    }

    /**
     * THE statement — public so a test reads (and `EXPLAIN`s) the text it pins, never a transcription. Four `?`, each the
     * customer id.
     */
    public static function statement(): string
    {
        return \sprintf(<<<'SQL'
            SELECT
              (SELECT count(*)
                 FROM sales_order so
                WHERE so.customer_id = ?::uuid AND so.deleted_at IS NULL AND so.status IN (%1$s)) AS open_sales_orders,
              (SELECT count(*)
                 FROM fulfilment f
                 JOIN sales_order fso ON fso.tenant_id = f.tenant_id AND fso.id = f.sales_order_id
                WHERE fso.customer_id = ?::uuid AND fso.deleted_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM invoice fi
                                   WHERE fi.tenant_id = f.tenant_id AND fi.fulfilment_id = f.id AND fi.status <> '%2$s')
                  AND EXISTS (SELECT 1 FROM fulfilment_line fl
                               WHERE fl.tenant_id = f.tenant_id AND fl.fulfilment_id = f.id AND %3$s > 0)) AS unbilled_shipments,
              (SELECT count(*)
                 FROM invoice inv
                WHERE inv.customer_id = ?::uuid AND inv.status IN ('%4$s', '%5$s') AND NOT %6$s) AS unpaid_invoices,
              (SELECT count(*)
                 FROM sales_return r
                 JOIN fulfilment rf ON rf.tenant_id = r.tenant_id AND rf.id = r.fulfilment_id
                 JOIN sales_order rso ON rso.tenant_id = rf.tenant_id AND rso.id = rf.sales_order_id
                 LEFT JOIN invoice live ON live.tenant_id = r.tenant_id AND live.fulfilment_id = r.fulfilment_id AND live.status <> '%2$s'
                WHERE rso.customer_id = ?::uuid AND live.id IS NOT NULL AND %7$s) AS owed_credits
            SQL,
            "'".implode("', '", self::OPEN_ORDER_STATUSES)."'",
            Invoice::STATUS_VOID,
            SalesReturnReader::billed('fl'),
            Invoice::STATUS_ISSUED,
            Invoice::STATUS_OVERDUE,
            InvoiceCredit::creditedInFull('inv'),
            SalesReturnReader::owesACredit(),
        );
    }
}
