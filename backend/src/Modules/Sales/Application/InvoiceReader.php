<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Platform\Http\Pagination\Cursor;
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
 * (V2-C-10's first half), and — since sales step 7 C0 — `amount_credited`, `amount_due` and `credited_in_full`
 * (V2-C-10's second half), all from {@see InvoiceCredit}, the one place "credited in full" is decided. Never
 * `issued_by` (`SEC` §2).
 *
 * **`status` is derived, total over the stored domain** (`ARCH` §5.1; owner ruling Q2): an invoice **credited in full**
 * reads `issued` — even a stored `overdue` one, so the predicate is the CASE's FIRST branch (`c0-amendments` site 3;
 * a conjunct on the date branch alone would let a stored `overdue` through its `ELSE`); otherwise an `issued` invoice
 * whose `due_at` has passed reads `overdue`, and a stored `overdue` reads `overdue`; every other stored value reads as
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
     * **`ListInvoices`** (sales step 6, C4) — one keyset page (API-17/API-18) in the view's one order, each row the
     * same document `GetInvoice` answers, `status` derived the same way.
     *
     * Membership is decided on the stored status and the stored credits (TB-4 ³), never on the derived label. The label
     * only relabels a row: `overdue` by date, and `issued` for an invoice credited in full, even a stored `overdue` one. A `customer_id` or `fulfilment_id` naming another workspace's row, or none, is a predicate no visible row
     * satisfies — RLS bounds `invoice` to the caller's workspace — so the answer is the same empty 200 (`SEC` §9.6);
     * nothing is probed, so there is nothing to 404 on.
     */
    public function list(InvoiceListCriteria $criteria): InvoicePage
    {
        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($criteria): InvoicePage {
            $connection = $em->getConnection();
            $where = [];
            $parameters = self::statusParameters();

            if ([] !== $criteria->statuses) {
                $where[] = 'inv.status IN ('.implode(', ', array_fill(0, \count($criteria->statuses), '?::text')).')';
                $parameters = [...$parameters, ...$criteria->statuses];
            }
            // Invoices' one credit-derived split (TB-4 ³): Unpaid holds `{issued, overdue}` NOT credited in full,
            // Credited holds the same statuses credited in full. One fragment, never restated.
            if (InvoiceViews::CREDIT_NOT_IN_FULL === $criteria->credit) {
                $where[] = 'NOT '.InvoiceCredit::creditedInFull();
            } elseif (InvoiceViews::CREDIT_IN_FULL === $criteria->credit) {
                $where[] = InvoiceCredit::creditedInFull();
            }
            if (null !== $criteria->customerId) {
                $where[] = 'inv.customer_id = ?::uuid';
                $parameters[] = $criteria->customerId;
            }
            if (null !== $criteria->fulfilmentId) {
                $where[] = 'inv.fulfilment_id = ?::uuid';
                $parameters[] = $criteria->fulfilmentId;
            }
            if (null !== $criteria->search) {
                // API-19d / TB-5: the document number, by prefix — the one form `invoice_number_unique` serves.
                $where[] = 'inv.invoice_number LIKE ?';
                $parameters[] = addcslashes($criteria->search, '%_\\').'%';
            }

            $column = 'inv.'.$criteria->sort;
            $cast = InvoiceViews::isTimestampSort($criteria->sort) ? '::timestamptz' : '';
            // The page runs in the view's direction; a backward page runs the other way and is reversed after.
            $ascending = $criteria->isNewestFirst() === $criteria->isBackwards();

            $cursor = $criteria->after ?? $criteria->before;
            if (null !== $cursor) {
                $where[] = \sprintf('(%s, inv.id) %s (?%s, ?::uuid)', $column, $ascending ? '>' : '<', $cast);
                $parameters[] = $cursor->sortKey;
                $parameters[] = $cursor->id;
            }

            $direction = $ascending ? 'ASC' : 'DESC';
            $sql = self::select()
                .([] === $where ? '' : ' WHERE '.implode(' AND ', $where))
                .\sprintf(' ORDER BY %s %s, inv.id %s LIMIT ?', $column, $direction, $direction);
            $parameters[] = $criteria->limit + 1;

            // Every sort key is selected under its own name, so the cursor reads the raw column as Postgres renders it.
            $rows = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }
            if ($criteria->isBackwards()) {
                $rows = array_reverse($rows);
            }

            [$next, $previous] = self::cursors($criteria, $rows, $hasMore);

            return new InvoicePage(
                items: array_map(self::view(...), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: an EXISTS probe with no filter, only when the page is empty.
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM invoice)') : true,
            );
        });
    }

    /**
     * **`ListInvoiceLines`** (sales step 7 C0) — every shipped line of the invoice's fulfilment, with what was billed
     * and what this invoice's credit notes have credited on it; null when the invoice is not visible (404).
     *
     * `quantity_billed` is `IssueInvoice`'s own formula, {@see SalesReturnReader::billed()} = shipped − returned before
     * issue (one copy; read through `billed()` since C5 C4b, RED round 2 F-1), and `amount_billed` its per-line rounding. **Disclosed limit:** for a VOID invoice whose shipment later
     * took a deducting return (recorded while no live invoice existed), the derivation reads today's deductions, not
     * the ones that invoice priced — design §4.4 step 7a's equality assertion (C1) is the check on the live invoice.
     * Credited sums are line sums (`credit_note_line.quantity` / `.amount`), never the invoice-level total
     * (`InvoiceCredit`).
     *
     * @return ?list<InvoiceLineView>
     */
    public function lines(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($id): ?array {
            $connection = $em->getConnection();
            $id = Uuid::fromString($id)->toRfc4122();

            if (false === $connection->fetchOne('SELECT 1 FROM invoice WHERE id = ?::uuid', [$id])) {
                return null;
            }

            $rows = $connection->fetchAllAssociative(\sprintf(<<<'SQL'
                SELECT fl.id, fl.quantity AS shipped_quantity,
                       sol.id AS sales_order_line_id, sol.line_number,
                       i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name,
                       %1$s::numeric(19,6) AS quantity_billed,
                       sol.unit_price,
                       round(%1$s * sol.unit_price, 4)::numeric(19,4) AS amount_billed,
                       COALESCE((SELECT SUM(cl.quantity) FROM credit_note_line cl
                                   JOIN credit_note c ON c.tenant_id = cl.tenant_id AND c.id = cl.credit_note_id
                                  WHERE cl.tenant_id = fl.tenant_id AND cl.fulfilment_line_id = fl.id
                                    AND c.invoice_id = inv.id), 0::numeric(19,6)) AS quantity_credited,
                       COALESCE((SELECT SUM(cl.amount) FROM credit_note_line cl
                                   JOIN credit_note c ON c.tenant_id = cl.tenant_id AND c.id = cl.credit_note_id
                                  WHERE cl.tenant_id = fl.tenant_id AND cl.fulfilment_line_id = fl.id
                                    AND c.invoice_id = inv.id), 0::numeric(19,4)) AS amount_credited
                  FROM invoice inv
                  JOIN fulfilment_line fl ON fl.tenant_id = inv.tenant_id AND fl.fulfilment_id = inv.fulfilment_id
                  JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
                  JOIN inventory_item i ON i.tenant_id = sol.tenant_id AND i.id = sol.inventory_item_id
                 WHERE inv.id = ?::uuid
                 ORDER BY sol.line_number, fl.storage_location_id, fl.id
                SQL, SalesReturnReader::billed('fl')), [$id]);

            return array_map(static function (array $row): InvoiceLineView {
                $item = new SalesItemRef((string) $row['inventory_item_id'], (string) $row['inventory_item_sku'], (string) $row['inventory_item_name']);

                return new InvoiceLineView(
                    id: (string) $row['id'],
                    fulfilmentLine: new BilledLineRef(
                        (string) $row['id'],
                        new SalesLineRef((string) $row['sales_order_line_id'], (int) $row['line_number']),
                        $item,
                        (string) $row['shipped_quantity'],
                    ),
                    inventoryItem: $item,
                    quantityBilled: (string) $row['quantity_billed'],
                    unitPrice: (string) $row['unit_price'],
                    amountBilled: (string) $row['amount_billed'],
                    quantityCredited: (string) $row['quantity_credited'],
                    amountCredited: (string) $row['amount_credited'],
                );
            }, $rows);
        });
    }

    /**
     * @param list<array<string, mixed>> $rows in the list's own order
     *
     * @return array{?string, ?string} next, previous
     */
    private static function cursors(InvoiceListCriteria $criteria, array $rows, bool $hasMore): array
    {
        if ([] === $rows) {
            return [null, null];
        }

        // Every sort key is NOT NULL (`invoice_number`, `due_at`, `issued_at`), so `6fe8b7d`'s empty-key cursor
        // cannot arise here.
        $first = new Cursor((string) $rows[0][$criteria->sort], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow[$criteria->sort], (string) $lastRow['id']);

        if ($criteria->isBackwards()) {
            return [$last->encode(), $hasMore ? $first->encode() : null];
        }

        return [$hasMore ? $last->encode() : null, null !== $criteria->after ? $first->encode() : null];
    }

    /**
     * The header columns and joins, with the derived status first-bound. Callers append their own `WHERE` and pass
     * {@see self::statusParameters()} ahead of their own parameters.
     */
    public static function select(): string
    {
        return \sprintf(<<<'SQL'
            SELECT inv.id, inv.invoice_number,
                   CASE WHEN %1$s THEN ?::text
                        WHEN inv.status = ?::text OR (inv.status = ?::text AND inv.due_at < now())
                        THEN ?::text ELSE inv.status END AS status,
                   inv.total_amount, inv.currency_code, inv.issued_at, inv.due_at, inv.paid_at,
                   %2$s AS amount_credited, %3$s AS amount_due, %1$s AS credited_in_full,
                   pu.id AS paid_by_id, pu.display_name AS paid_by_display_name,
                   f.id AS fulfilment_id, f.fulfilment_number,
                   c.id AS customer_id, c.name AS customer_name
              FROM invoice inv
              JOIN fulfilment f ON f.tenant_id = inv.tenant_id AND f.id = inv.fulfilment_id
              JOIN customer c ON c.tenant_id = inv.tenant_id AND c.id = inv.customer_id
              LEFT JOIN app_user pu ON pu.tenant_id = inv.tenant_id AND pu.id = inv.paid_by_user_id
            SQL, InvoiceCredit::creditedInFull(), InvoiceCredit::credited(), InvoiceCredit::amountDue());
    }

    /** @return list<string> the four statuses {@see self::select()}'s derivation binds, in its order */
    public static function statusParameters(): array
    {
        return [Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE, Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE];
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
            // `SEC` §2 (Q7): `{id, display_name}` and nothing more, null unless paid. LEFT join, with NO `deleted_at`
            // predicate, so a recorder deactivated or deleted since is still named — the document keeps naming what it
            // named (M-16). `invoice_paid_by_matches_status` makes a non-null payer on an unpaid invoice impossible.
            paidBy: null === $row['paid_by_id'] ? null : new SalesActorRef((string) $row['paid_by_id'], (string) $row['paid_by_display_name']),
            fulfilment: new FulfilmentRef((string) $row['fulfilment_id'], (string) $row['fulfilment_number']),
            customer: new SalesNamedRef((string) $row['customer_id'], (string) $row['customer_name']),
            amountCredited: (string) $row['amount_credited'],
            amountDue: (string) $row['amount_due'],
            creditedInFull: (bool) $row['credited_in_full'],
        );
    }
}
