<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The read half of `/invoices` — `GetInvoice` (sales step 6, C3).** Built with `IssueInvoice` so the 201's
 * `Location` names a route that answers (M-19).
 *
 * **The shape** is §4.4.3's Invoice row: `invoice_number`, `status`, `total_amount`, `issued_at`, `due_at`, `paid_at`,
 * plus `currency_code`, the billed shipment's `fulfilment = {id, fulfilment_number}` and `customer = {id, name}`
 * (V2-C-10's first half). Never `issued_by` (`SEC` §2) and never an amount credited or due (V2-C-10's second half,
 * owed to `IssueCreditNote`).
 *
 * **`status` is derived, total over the stored domain** (`ARCH` §5.1; owner ruling Q2): an `issued` invoice whose
 * `due_at` has passed reads `overdue`, and a stored `overdue` reads `overdue`; every other stored value reads as
 * itself. `now()` is the **database's** clock — the one `invoice_due_window` and `due_at` itself were computed on —
 * so no PHP clock decides whether an invoice is overdue. `Invoice::markOverdue()` is kept but has no caller
 * (`RESIDUAL_GAPS.md` R-C, C4). The statuses are bound from `Invoice::STATUS_*`, never spelled as SQL literals
 * (API-15; `SalesOrderLineRow`'s shape).
 *
 * **Joins.** Both inner, both unfiltered: `fulfilment_id` and `customer_id` are NOT NULL under composite keys, and an
 * archived customer still names who was billed (an invoice is a statement already made). The customer is the
 * invoice's own `customer_id` (the copy taken from the order at issue), not re-derived through the order.
 */
final readonly class InvoiceReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function get(string $id): ?InvoiceView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?InvoiceView => $this->readInside($em, Uuid::fromString($id)->toRfc4122()),
        );
    }

    /** Inside a transaction the caller already holds. */
    public function readInside(EntityManagerInterface $em, string $id): ?InvoiceView
    {
        $row = $em->getConnection()->fetchAssociative(
            self::select().' WHERE inv.id = ?::uuid',
            [...self::statusParameters(), $id],
        );

        return false === $row ? null : self::view($row);
    }

    /**
     * The header columns and joins, with the derived status first-bound. Callers append their own `WHERE` and pass
     * {@see self::statusParameters()} ahead of their own parameters.
     */
    public static function select(): string
    {
        return <<<'SQL'
            SELECT inv.id, inv.invoice_number,
                   CASE WHEN inv.status = ?::text OR (inv.status = ?::text AND inv.due_at < now())
                        THEN ?::text ELSE inv.status END AS status,
                   inv.total_amount, inv.currency_code, inv.issued_at, inv.due_at, inv.paid_at,
                   f.id AS fulfilment_id, f.fulfilment_number,
                   c.id AS customer_id, c.name AS customer_name
              FROM invoice inv
              JOIN fulfilment f ON f.tenant_id = inv.tenant_id AND f.id = inv.fulfilment_id
              JOIN customer c ON c.tenant_id = inv.tenant_id AND c.id = inv.customer_id
            SQL;
    }

    /** @return list<string> the three statuses {@see self::select()}'s derivation binds, in its order */
    public static function statusParameters(): array
    {
        return [Invoice::STATUS_OVERDUE, Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE];
    }

    /** @param array<string, mixed> $row */
    public static function view(array $row): InvoiceView
    {
        return new InvoiceView(
            id: (string) $row['id'],
            invoiceNumber: (string) $row['invoice_number'],
            status: (string) $row['status'],
            totalAmount: (string) $row['total_amount'],
            currencyCode: (string) $row['currency_code'],
            issuedAt: (string) SalesOrderRow::timestamp($row['issued_at']),
            dueAt: (string) SalesOrderRow::timestamp($row['due_at']),
            paidAt: SalesOrderRow::timestamp($row['paid_at']),
            fulfilment: new FulfilmentRef((string) $row['fulfilment_id'], (string) $row['fulfilment_number']),
            customer: new SalesNamedRef((string) $row['customer_id'], (string) $row['customer_name']),
        );
    }
}
