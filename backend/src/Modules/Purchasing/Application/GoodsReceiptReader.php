<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The read half of `/goods-receipts`. One statement for the receipt and its
 * order, one for its lines, both inside the tenancy transaction, so RLS bounds
 * them and nothing is lazy-loaded after the transaction closes.
 *
 * **Lines are listed in the order's line-number order**, then by bin. Every
 * line of a receipt is inserted in one transaction, so `created_at` ties and
 * the table has no line number of its own; ordering by the order line's number
 * is the one stable order that also means something to the reader.
 *
 * **The receiver's join is outer and unfiltered, exactly as `PurchaseOrderRow`
 * joins the creator.** `received_by_user_id` is nullable, so an inner join would
 * lose the receipt; and there is no `deleted_at IS NULL` on `app_user`, so a
 * receiver who has since been archived is still named — archiving a person does
 * not un-book the delivery they booked.
 *
 * **The bin's join is unfiltered for the same reason** (API-23b's
 * `storage_location = {id, code}`): a bin retired after the delivery still names
 * where the goods went, so there is no `deleted_at IS NULL` on
 * `storage_location`. It is inner because `goods_receipt_line.storage_location_id`
 * is `NOT NULL` under a composite foreign key, so the row always exists. It reads
 * a table `Modules\Warehouses` owns — the same accepted A-2 residual as
 * `PurchaseOrderRow`'s supplier and warehouse joins, and `GoodsReceiptWriter`
 * already reads it. Accepted residual: a bin code is unique only among live bins
 * of one warehouse, so after a retire-and-reuse two old lines can show one code.
 *
 * **The item's join is inner and unfiltered, for the bin's reasons** (§4.4.3's
 * `lines[].inventory_item = {id, sku, name}`, owner rulings Q1(c) and Q-A).
 * `goods_receipt_line` has no item column, so the item is the ordered line's:
 * `purchase_order_line.inventory_item_id` is `NOT NULL` under a composite foreign
 * key, and an item archived after the delivery is still the item that arrived.
 * It reads `inventory_item`, a table `Modules\Inventory` owns — the same A-2
 * residual as `PurchaseOrderLineRow`'s item join. The order line's reference is
 * `{id, line_number}` (API-23b clause (i)).
 *
 * **`ListGoodsReceipts` (remaining-ops G1)** reads the same row through the same two statements, so a list row is
 * byte-for-byte `GetGoodsReceipt`'s document; see {@see self::listStatement()} for its two orders.
 */
final readonly class GoodsReceiptReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function get(string $id): ?GoodsReceiptView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?GoodsReceiptView => $this->readInside($em, $id),
        );
    }

    /** Inside a transaction the caller already holds. */
    public function readInside(EntityManagerInterface $em, string $id): ?GoodsReceiptView
    {
        $connection = $em->getConnection();

        $row = $connection->fetchAssociative(self::HEADER_SQL.' WHERE gr.id = ?::uuid', [$id]);

        if (false === $row) {
            return null;
        }

        return self::view($row, $connection->fetchAllAssociative(self::LINES_SQL.' WHERE grl.goods_receipt_id = ?::uuid'.self::LINES_ORDER, [$id]));
    }

    /**
     * `ListGoodsReceipts` (remaining-ops G1) — one keyset page (API-17/API-18), newest first in the view's one order.
     *
     * A `purchase_order_id` naming another workspace's order, or no order, is simply a predicate no visible row
     * satisfies: RLS bounds `goods_receipt` to the caller's tenant, so the page is empty and the response is the same
     * empty 200 either way (`SEC` §9.1). The order is never probed, so there is nothing to 404 on. Each row is
     * `GetGoodsReceipt`'s document: the page's headers in one statement, all of its lines in a second (`= ANY`).
     */
    public function list(GoodsReceiptListCriteria $criteria): GoodsReceiptPage
    {
        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($criteria): GoodsReceiptPage {
            $connection = $em->getConnection();
            [$sql, $parameters] = self::listStatement($criteria);

            // One row more than the page answers "is there another page" without a COUNT (TB-2b).
            $rows = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }
            if ($criteria->isBackwards()) {
                $rows = array_reverse($rows);
            }

            $linesByReceipt = [];
            if ([] !== $rows) {
                $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
                foreach ($connection->fetchAllAssociative(
                    self::LINES_SQL.' WHERE grl.goods_receipt_id = ANY (?::uuid[])'.self::LINES_ORDER,
                    ['{'.implode(',', $ids).'}'],
                ) as $line) {
                    $linesByReceipt[(string) $line['goods_receipt_id']][] = $line;
                }
            }

            [$next, $previous] = self::cursors($criteria, $rows, $hasMore);

            return new GoodsReceiptPage(
                items: array_map(static fn (array $row): GoodsReceiptView => self::view($row, $linesByReceipt[(string) $row['id']] ?? []), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: an EXISTS probe with no filter, only when the page is empty.
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM goods_receipt)') : true,
            );
        });
    }

    /**
     * **The page statement `list()` issues, exposed so `SchemaQueryPlanTest` explains THIS statement** and not a copy that
     * could drift from it (customers' L-4 rule). Its two orders are `IA.md` §5.6's, both newest first:
     *
     * - the register: `(receipt_number, id)` over `goods_receipt_number_unique` (R-1). `id` is kept although the number is
     *   unique per tenant, so the cursor has API-18's one `(sort key, id)` shape (RED L-6 review, L-1);
     * - one order's receipts: `(received_at, id)` over `goods_receipt_tenant_order_idx (tenant_id, purchase_order_id,
     *   received_at)`. **No new index (RED L-6, decided at G1):** the index serves the order and the `received_at` half of
     *   the keyset; the `id` tie-break inside a same-instant group is an incremental sort over that group. The group is one
     *   order's receipts — few at any realistic volume, serialised under the order's lock — not a tenant-wide group like the
     *   stock-movement and audit ledgers, whose indexes end in `id`. Nothing in the schema caps it (a receipt needs only a
     *   positive quantity, and `received_at` is the client's), so a degenerate same-instant group inside one order pages by
     *   re-reading that group: accepted, recorded in `API.md` §15.1.
     *
     * @return array{string, list<int|string>}
     */
    public static function listStatement(GoodsReceiptListCriteria $criteria): array
    {
        $sort = $criteria->sort();
        // Never interpolated from the request: the column is one of the criteria's two constants.
        $column = 'gr.'.$sort;

        $where = [];
        $parameters = [];

        if (null !== $criteria->purchaseOrderId) {
            $where[] = 'gr.purchase_order_id = ?::uuid';
            $parameters[] = $criteria->purchaseOrderId;
        }

        if (null !== $criteria->search) {
            // API-19d / TB-5: one column, the document number, by prefix -- the only form `goods_receipt_number_unique`
            // can serve (collation caveat: G-39). Wildcards escaped, so `%` and `_` are characters.
            $where[] = 'gr.receipt_number LIKE ?';
            $parameters[] = addcslashes($criteria->search, '%_\\').'%';
        }

        $cursor = $criteria->after ?? $criteria->before;
        if (null !== $cursor) {
            // API-18: (sort key, id) as ONE row comparison. The list reads newest first, so a forward page continues
            // BELOW the cursor and a backward page above it. The provider has refused a cursor whose key cannot be this
            // view's, so the cast cannot 500.
            $where[] = \sprintf('(%s, gr.id) %s (?%s, ?::uuid)', $column, $criteria->isBackwards() ? '>' : '<',
                GoodsReceiptListCriteria::SORT_RECEIVED_AT === $sort ? '::timestamptz' : '');
            $parameters[] = $cursor->sortKey;
            $parameters[] = $cursor->id;
        }

        $direction = $criteria->isBackwards() ? 'ASC' : 'DESC';
        $order = \sprintf(' ORDER BY %s %s, gr.id %s', $column, $direction, $direction);
        // The page first, from `goods_receipt` alone, so `LIMIT` stops the index scan whatever join follows; then the
        // order and the receiver, and the order again over at most `limit + 1` rows — `StockMovementReader`'s shape
        // (`5c183a4`): with the joins inside the page, the planner's join strategy decides whether the scan is cut.
        $sql = self::HEADER_SELECT
            .' FROM (SELECT '.self::RECEIPT_COLUMNS.' FROM goods_receipt gr'
            .([] === $where ? '' : ' WHERE '.implode(' AND ', $where))
            .$order.' LIMIT ?) gr '
            .self::HEADER_JOINS
            .$order;
        $parameters[] = $criteria->limit + 1;

        return [$sql, $parameters];
    }

    private const HEADER_SELECT = <<<'SQL'
        SELECT gr.id, gr.receipt_number, gr.received_at, po.id AS purchase_order_id, po.order_number,
               u.id AS received_by_id, u.display_name AS received_by_display_name
        SQL;

    /** What the derived page carries: everything {@see self::HEADER_SELECT} and {@see self::HEADER_JOINS} read from `gr`. */
    private const RECEIPT_COLUMNS = 'gr.id, gr.tenant_id, gr.purchase_order_id, gr.received_by_user_id, gr.receipt_number, gr.received_at';

    private const HEADER_JOINS = <<<'SQL'
        JOIN purchase_order po ON po.tenant_id = gr.tenant_id AND po.id = gr.purchase_order_id
          LEFT JOIN app_user u ON u.tenant_id = gr.tenant_id AND u.id = gr.received_by_user_id
        SQL;

    private const HEADER_SQL = self::HEADER_SELECT.' FROM goods_receipt gr '.self::HEADER_JOINS;

    private const LINES_SQL = <<<'SQL'
        SELECT grl.id, grl.goods_receipt_id, grl.purchase_order_line_id, grl.quantity_received,
               pol.line_number AS purchase_order_line_number,
               i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name,
               sl.id AS storage_location_id, sl.code AS storage_location_code
          FROM goods_receipt_line grl
          JOIN purchase_order_line pol ON pol.tenant_id = grl.tenant_id AND pol.id = grl.purchase_order_line_id
          JOIN inventory_item i ON i.tenant_id = pol.tenant_id AND i.id = pol.inventory_item_id
          JOIN storage_location sl ON sl.tenant_id = grl.tenant_id AND sl.id = grl.storage_location_id
        SQL;

    private const LINES_ORDER = ' ORDER BY pol.line_number, grl.storage_location_id, grl.id';

    /**
     * @param list<array<string, mixed>> $rows in the list's own order
     *
     * @return array{?string, ?string} next, previous
     */
    private static function cursors(GoodsReceiptListCriteria $criteria, array $rows, bool $hasMore): array
    {
        if ([] === $rows) {
            return [null, null];
        }

        $sort = $criteria->sort();
        // Both sort keys are NOT NULL (`receipt_number`, `received_at`), so `6fe8b7d`'s empty-key cursor cannot arise.
        $first = new Cursor((string) $rows[0][$sort], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow[$sort], (string) $lastRow['id']);

        if ($criteria->isBackwards()) {
            return [$last->encode(), $hasMore ? $first->encode() : null];
        }

        return [$hasMore ? $last->encode() : null, null !== $criteria->after ? $first->encode() : null];
    }

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $lines
     */
    private static function view(array $row, array $lines): GoodsReceiptView
    {
        return new GoodsReceiptView(
            id: (string) $row['id'],
            receiptNumber: (string) $row['receipt_number'],
            receivedAt: (string) PurchaseOrderRow::timestamp($row['received_at']),
            purchaseOrder: new OrderReference((string) $row['purchase_order_id'], (string) $row['order_number']),
            receivedById: null === $row['received_by_id'] ? null : (string) $row['received_by_id'],
            receivedByDisplayName: null === $row['received_by_id'] ? null : (string) $row['received_by_display_name'],
            lines: array_map(static fn (array $line): GoodsReceiptLineView => new GoodsReceiptLineView(
                id: (string) $line['id'],
                purchaseOrderLine: new OrderLineReference((string) $line['purchase_order_line_id'], (int) $line['purchase_order_line_number']),
                inventoryItem: new ItemReference((string) $line['inventory_item_id'], (string) $line['inventory_item_sku'], (string) $line['inventory_item_name']),
                quantity: (string) $line['quantity_received'],
                storageLocation: new LocationReference((string) $line['storage_location_id'], (string) $line['storage_location_code']),
            ), $lines),
        );
    }
}
