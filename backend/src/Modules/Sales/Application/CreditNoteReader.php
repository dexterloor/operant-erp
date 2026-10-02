<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The read half of `/credit-notes` — `GetCreditNote` and `ListCreditNotes`** (sales step 7 C1 and C2; built with `IssueCreditNote` so the 201's
 * `Location` answers, V2-R-4). `FulfilmentReader`'s shape: one statement for the header, one for the lines.
 *
 * **Joins.** All inner but the return: `invoice_id`, `issued_by_user_id` are NOT NULL under composite keys, and the
 * issuer is read with NO `deleted_at` predicate, so a user deleted since is still named (the document keeps naming
 * what it named, `paid_by`'s M-16). The return is a LEFT join: an adjustment credits no return (C1b).
 */
final readonly class CreditNoteReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function get(string $id): ?CreditNoteView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?CreditNoteView => $this->readInside($em, Uuid::fromString($id)->toRfc4122()),
        );
    }

    /** Inside a transaction the caller already holds. */
    public function readInside(EntityManagerInterface $em, string $id): ?CreditNoteView
    {
        $connection = $em->getConnection();

        $row = $connection->fetchAssociative(self::HEADER.' WHERE cn.id = ?::uuid', [$id]);
        if (false === $row) {
            return null;
        }

        return self::view($row, $connection->fetchAllAssociative(self::LINES.' WHERE cnl.credit_note_id = ?::uuid'.self::LINES_ORDER, [$id]));
    }

    /**
     * **`ListCreditNotes`** (sales step 7 C2; design §4.5): ONE statement, `GetCreditNote`'s header with the filters in
     * its own WHERE, so a row is the very document `GetCreditNote` serves (the R3 shape, `SalesReturnReader::list()`).
     * `customer_id` is the invoice's own `customer_id` (the copy taken at issue, `InvoiceReader`'s filter), read through
     * the header's existing inner join. A foreign or unknown id is a predicate no visible row satisfies: an empty page,
     * never a 404 (E-2).
     */
    public function list(CreditNoteListCriteria $criteria): CreditNotePage
    {
        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($criteria): CreditNotePage {
            $connection = $em->getConnection();
            $where = [];
            $parameters = [];

            foreach ([['cn.invoice_id', $criteria->invoiceId], ['inv.customer_id', $criteria->customerId],
                ['cn.sales_return_id', $criteria->salesReturnId]] as [$column, $value]) {
                if (null !== $value) {
                    $where[] = $column.' = ?::uuid';
                    $parameters[] = $value;
                }
            }
            if (null !== $criteria->search) {
                $where[] = 'cn.credit_note_number LIKE ?';
                $parameters[] = addcslashes($criteria->search, '%_\\').'%';
            }
            $cursor = $criteria->after ?? $criteria->before;
            if (null !== $cursor) {
                // API-18: (sort key, id) as ONE row comparison; newest first, so forward continues BELOW the cursor.
                $where[] = \sprintf('(cn.credit_note_number, cn.id) %s (?, ?::uuid)', $criteria->isBackwards() ? '>' : '<');
                $parameters[] = $cursor->sortKey;
                $parameters[] = $cursor->id;
            }

            $direction = $criteria->isBackwards() ? 'ASC' : 'DESC';
            $sql = self::HEADER
                .([] === $where ? '' : ' WHERE '.implode(' AND ', $where))
                .\sprintf(' ORDER BY cn.credit_note_number %s, cn.id %s LIMIT ?', $direction, $direction);
            $parameters[] = $criteria->limit + 1;

            $rows = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }
            if ($criteria->isBackwards()) {
                $rows = array_reverse($rows);
            }

            $linesByNote = [];
            if ([] !== $rows) {
                foreach ($connection->fetchAllAssociative(
                    self::LINES.' WHERE cnl.credit_note_id = ANY (?::uuid[])'.self::LINES_ORDER,
                    ['{'.implode(',', array_map(static fn (array $row): string => (string) $row['id'], $rows)).'}'],
                ) as $line) {
                    $linesByNote[(string) $line['credit_note_id']][] = $line;
                }
            }

            $next = $previous = null;
            if ([] !== $rows) {
                $first = new Cursor((string) $rows[0]['credit_note_number'], (string) $rows[0]['id']);
                $lastRow = $rows[array_key_last($rows)];
                $last = new Cursor((string) $lastRow['credit_note_number'], (string) $lastRow['id']);
                [$next, $previous] = $criteria->isBackwards()
                    ? [$last->encode(), $hasMore ? $first->encode() : null]
                    : [$hasMore ? $last->encode() : null, null !== $criteria->after ? $first->encode() : null];
            }

            return new CreditNotePage(
                items: array_map(static fn (array $row): CreditNoteView => self::view($row, $linesByNote[(string) $row['id']] ?? []), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: an EXISTS probe with no filter, only when the page is empty.
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM credit_note)') : true,
            );
        });
    }

    public const HEADER = <<<'SQL'
        SELECT cn.id, cn.credit_note_number, cn.reason, cn.currency_code, cn.total_amount, cn.issued_at,
               inv.id AS invoice_id, inv.invoice_number,
               sr.id AS sales_return_id, sr.sales_return_number,
               u.id AS issued_by_id, u.display_name AS issued_by_display_name
          FROM credit_note cn
          JOIN invoice inv ON inv.tenant_id = cn.tenant_id AND inv.id = cn.invoice_id
          JOIN app_user u ON u.tenant_id = cn.tenant_id AND u.id = cn.issued_by_user_id
          LEFT JOIN sales_return sr ON sr.tenant_id = cn.tenant_id AND sr.id = cn.sales_return_id
        SQL;

    public const LINES = <<<'SQL'
        SELECT cnl.id, cnl.credit_note_id, cnl.quantity, cnl.amount,
               fl.id AS fulfilment_line_id, fl.quantity AS shipped_quantity,
               sol.id AS sales_order_line_id, sol.line_number,
               i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name
          FROM credit_note_line cnl
          JOIN fulfilment_line fl ON fl.tenant_id = cnl.tenant_id AND fl.id = cnl.fulfilment_line_id
          JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
          JOIN inventory_item i ON i.tenant_id = sol.tenant_id AND i.id = sol.inventory_item_id
        SQL;

    public const LINES_ORDER = ' ORDER BY sol.line_number, fl.storage_location_id, cnl.id';

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $lines
     */
    public static function view(array $row, array $lines): CreditNoteView
    {
        return new CreditNoteView(
            id: (string) $row['id'],
            creditNoteNumber: (string) $row['credit_note_number'],
            reason: (string) $row['reason'],
            invoice: new InvoiceNumberRef((string) $row['invoice_id'], (string) $row['invoice_number']),
            salesReturn: null === $row['sales_return_id'] ? null : new SalesReturnNumberRef((string) $row['sales_return_id'], (string) $row['sales_return_number']),
            currencyCode: (string) $row['currency_code'],
            totalAmount: (string) $row['total_amount'],
            issuedAt: (string) SalesOrderRow::timestamp($row['issued_at']),
            issuedBy: new SalesActorRef((string) $row['issued_by_id'], (string) $row['issued_by_display_name']),
            lines: array_map(static function (array $line): CreditNoteLineView {
                $item = new SalesItemRef((string) $line['inventory_item_id'], (string) $line['inventory_item_sku'], (string) $line['inventory_item_name']);

                return new CreditNoteLineView(
                    id: (string) $line['id'],
                    fulfilmentLine: new BilledLineRef(
                        (string) $line['fulfilment_line_id'],
                        new SalesLineRef((string) $line['sales_order_line_id'], (int) $line['line_number']),
                        $item,
                        (string) $line['shipped_quantity'],
                    ),
                    inventoryItem: $item,
                    quantity: (string) $line['quantity'],
                    amount: (string) $line['amount'],
                );
            }, $lines),
        );
    }
}
