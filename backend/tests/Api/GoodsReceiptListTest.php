<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`ListGoodsReceipts` — remaining-ops G1.** The receipt register (`/goods-receipts`) and the receipts of one
 * purchase order (`?purchase_order_id=`), in `IA.md` §5.6's two orders:
 *
 * - unfiltered: `receipt_number`, newest first, over `goods_receipt_number_unique` (R-1);
 * - inside an order: `received_at`, newest first, then `id`, over `goods_receipt_tenant_order_idx`.
 *
 * `ListFulfilments`' shape one module over. **No new index** (RED L-6, decided at G1: see
 * `docs/session13/g1-goods-receipts-progress.md` and `SchemaQueryPlanTest`'s two goods-receipt plans). A
 * `purchase_order_id` that names another workspace's order, or no order, is the **same empty 200** (`SEC` §9.1) —
 * never a 404 or 400. The row is `GetGoodsReceipt`'s document, lines included (§4.2 has no
 * `ListGoodsReceiptLines`, so the lines stay embedded), and `received_by` is `null` where no receiver was recorded.
 */
final class GoodsReceiptListTest extends PurchaseOrderTestCase
{
    /** A list row is exactly the document `GetGoodsReceipt` returns, inside API-22's envelope. */
    #[Test]
    public function aListRowIsTheReceiptGetGoodsReceiptReturns(): void
    {
        $o = $this->order('PO-317000', PurchaseOrder::STATUS_ORDERED);
        $item = $this->seedItem(self::TENANT_A, 'SKU-GRL-SHAPE');
        $line = $this->seedLine(self::TENANT_A, $o['order'], $item, quantity: '5.000000');
        $bin = $this->seedStorageLocation(self::TENANT_A, $o['warehouse'], 'A-01');

        $this->send('POST', '/purchase-orders/'.$o['order'].'/goods-receipts', ['lines' => [
            ['purchase_order_line_id' => $line, 'quantity' => '2', 'storage_location_id' => $bin],
        ]], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = $this->json()['id'];

        $this->send('GET', '/goods-receipts/'.$id);
        $document = $this->json();

        $this->send('GET', '/goods-receipts');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['items', 'next_cursor', 'previous_cursor', 'tenant_has_rows'], array_keys($this->json()));
        self::assertSame([$document], $this->json()['items'], 'A list row is not the document GetGoodsReceipt returns.');
        self::assertNotSame([], $document['lines'], 'The fixture received a line; an empty lines array proves nothing.');
        self::assertNotNull($document['received_by'], 'A receipt booked through the API names who booked it.');
        self::assertNull($this->json()['next_cursor']);
        self::assertNull($this->json()['previous_cursor']);
        self::assertTrue($this->json()['tenant_has_rows']);

        $this->send('GET', '/goods-receipts?purchase_order_id='.$o['order']);
        self::assertSame([$document], $this->json()['items']);
    }

    /**
     * **A receipt with no recorded receiver is listed, with `received_by: null`** — the receiver's join is outer
     * (`received_by_user_id` is nullable), so an inner join (mutation G1-e) loses the row.
     */
    #[Test]
    public function aReceiptWithNoRecordedReceiverIsListedWithANullReceiver(): void
    {
        $o = $this->order('PO-318000');
        $this->seedReceipt($o, 'GR-000001', '2026-09-01T00:00:00Z', receiver: null);

        $this->send('GET', '/goods-receipts');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['GR-000001'], array_column($this->json()['items'], 'receipt_number'));
        self::assertNull($this->json()['items'][0]['received_by']);
        self::assertSame([], $this->json()['items'][0]['lines']);
        self::assertSame(['id' => $o['order'], 'order_number' => 'PO-318000'], $this->json()['items'][0]['purchase_order']);
    }

    /**
     * **Unfiltered: number order, newest first (IA R-1) — NOT receipt time.** The fixture makes the two disagree:
     * the highest number was received earliest, so ordering by `received_at` (mutation G1-c) reverses the page.
     */
    #[Test]
    public function theRegisterIsInNumberOrderNewestFirstNotReceiptTime(): void
    {
        $o = $this->order('PO-319000');
        $this->seedReceipt($o, 'GR-000001', '2026-09-03T00:00:00Z');
        $this->seedReceipt($o, 'GR-000002', '2026-09-02T00:00:00Z');
        $this->seedReceipt($o, 'GR-000003', '2026-09-01T00:00:00Z');

        $this->send('GET', '/goods-receipts');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['GR-000003', 'GR-000002', 'GR-000001'], array_column($this->json()['items'], 'receipt_number'));
    }

    /**
     * **Inside an order: `received_at` newest first, then `id` newest first** — and only that order's receipts. Two
     * share one `received_at` (a back-dated delivery can), which is the tie group the `(received_at, id)` row
     * comparison exists for, and the number order disagrees with the time order so a number sort cannot pass.
     */
    #[Test]
    public function insideAnOrderTheReceiptsAreNewestReceiptFirst(): void
    {
        $o = $this->order('PO-321000');
        $other = $this->order('PO-322000');
        $early = $this->seedReceipt($o, 'GR-000003', '2026-09-01T00:00:00Z');
        $tieA = $this->seedReceipt($o, 'GR-000001', '2026-09-02T00:00:00Z');
        $tieB = $this->seedReceipt($o, 'GR-000002', '2026-09-02T00:00:00Z');
        $this->seedReceipt($other, 'GR-000004', '2026-09-05T00:00:00Z');

        $ties = [$tieA, $tieB];
        rsort($ties);

        $this->send('GET', '/goods-receipts?purchase_order_id='.$o['order']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([...$ties, $early], array_column($this->json()['items'], 'id'));
    }

    /**
     * API-17/API-18 — both views walk every row exactly once forwards, and `previous_cursor` walks back to the row
     * the forward walk started from. Two rows share a `received_at`, so the order's walk crosses a tie at a page
     * boundary (mutation G1-f, dropping `id` from the row comparison, repeats or skips one).
     */
    #[Test]
    public function theKeysetWalkReturnsEveryRowExactlyOnceInBothViewsAndBothDirections(): void
    {
        $o = $this->order('PO-323000');
        foreach ([['GR-000001', '2026-09-01'], ['GR-000002', '2026-09-02'], ['GR-000003', '2026-09-02'], ['GR-000004', '2026-09-03']] as [$n, $day]) {
            $this->seedReceipt($o, $n, $day.'T00:00:00Z');
        }

        foreach (['/goods-receipts?limit=1', '/goods-receipts?limit=1&purchase_order_id='.$o['order']] as $base) {
            $walk = [];
            $cursors = [];
            $cursor = null;
            $lastAfter = null;
            for ($page = 0; $page < 6; ++$page) {
                $lastAfter = $cursor;
                $this->send('GET', $base.(null === $cursor ? '' : '&after='.urlencode($cursor)));
                self::assertSame(200, $this->httpStatus(), $base.': '.(string) $this->client()->getResponse()->getContent());
                $walk = [...$walk, ...array_column($this->json()['items'], 'id')];
                $cursors[] = $this->json()['previous_cursor'];
                $cursor = $this->json()['next_cursor'];
                if (null === $cursor) {
                    break;
                }
            }
            self::assertCount(4, $walk, $base.': the forward walk did not return every row.');
            self::assertSame($walk, array_values(array_unique($walk)), $base.': a row was repeated across a page boundary.');
            self::assertNull($cursors[0], $base.': the first page offered a previous page.');

            // Back one page from the second: lands on the first row.
            self::assertNotNull($cursors[1], $base.': a page reached by `after` offered no previous page.');
            $this->send('GET', $base.'&before='.urlencode((string) $cursors[1]));
            self::assertSame(200, $this->httpStatus());
            self::assertSame([$walk[0]], array_column($this->json()['items'], 'id'), $base.': walking back did not land on the first row.');
            self::assertNotNull($this->json()['next_cursor'], $base.': a page reached by `before` offered no next page.');
            self::assertNull($this->json()['previous_cursor'], $base.': walking back off the front offered a previous page.');

            // Back from the LAST row to the first, one page at a time, ACROSS the tie (RED review L-2): a defect on the
            // backward path alone — `>` on the sort key without `id`, or the wrong tie order in the ascending read —
            // repeats, skips or misorders a row here. Starts on the forward walk's last page, by the cursor that reached it.
            self::assertNotNull($lastAfter);
            $this->send('GET', $base.'&after='.urlencode((string) $lastAfter));
            $back = [];
            $before = $this->json()['previous_cursor'];
            $back[] = array_column($this->json()['items'], 'id');
            for ($page = 0; $page < 6 && null !== $before; ++$page) {
                $this->send('GET', $base.'&before='.urlencode((string) $before));
                self::assertSame(200, $this->httpStatus(), $base.': '.(string) $this->client()->getResponse()->getContent());
                $back[] = array_column($this->json()['items'], 'id');
                $before = $this->json()['previous_cursor'];
            }
            self::assertSame(array_reverse($walk), array_merge(...array_map(array_reverse(...), $back)),
                $base.': walking backward from the last page did not return the forward walk reversed.');
        }
    }

    /**
     * **`SEC` §9.1: a `purchase_order_id` naming another workspace's order, or no order, is the same empty 200** —
     * byte for byte, and never 404 or 400. Mutation G1-d (404 for an unknown order) turns this red.
     */
    #[Test]
    public function aForeignOrMissingOrderFilterIsTheSameEmpty200(): void
    {
        $this->seedReceipt($this->order('PO-324000'), 'GR-000001', '2026-09-01T00:00:00Z');
        $theirs = $this->orderFor(self::TENANT_B, 'PO-325000');
        $this->seedReceiptFor(self::TENANT_B, $theirs['order'], $theirs['warehouse'], 'GR-000009', '2026-09-01T00:00:00Z', null);

        $bodies = [];
        foreach (['theirs' => $theirs['order'], 'nowhere' => Uuid::v4()->toRfc4122()] as $label => $id) {
            $this->send('GET', '/goods-receipts?purchase_order_id='.$id);
            self::assertSame(200, $this->httpStatus(), $label.': '.(string) $this->client()->getResponse()->getContent());
            $bodies[$label] = (string) $this->client()->getResponse()->getContent();
        }

        self::assertSame([], json_decode($bodies['theirs'], true)['items']);
        self::assertTrue(json_decode($bodies['theirs'], true)['tenant_has_rows'],
            'This workspace has a receipt, so an empty filtered page must say so (ST-2).');
        self::assertSame($bodies['theirs'], $bodies['nowhere'], 'Another workspace\'s order is distinguishable from no order.');
    }

    /** E-1: a caller without `goods_receipt.view` gets the same 403 whatever the filter names. */
    #[Test]
    public function aCallerWithoutGoodsReceiptViewGetsTheSame403WhateverTheFilterNames(): void
    {
        $mine = $this->order('PO-326000');
        $theirs = $this->orderFor(self::TENANT_B, 'PO-327000');

        $this->actAs(self::TENANT_A, ['purchase_order.view']);

        $observed = [];
        foreach (['none' => '', 'mine' => '?purchase_order_id='.$mine['order'], 'theirs' => '?purchase_order_id='.$theirs['order'],
            'nowhere' => '?purchase_order_id='.Uuid::v4()->toRfc4122()] as $label => $query) {
            $this->send('GET', '/goods-receipts'.$query);
            $response = $this->client()->getResponse();
            $headers = $response->headers->all();
            ksort($headers);
            if (\array_key_exists('date', $headers)) {
                $headers['date'] = ['<present>'];
            }
            $observed[$label] = ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => (string) $response->getContent()];
        }

        self::assertSame(403, $observed['none']['status']);
        self::assertSame('goods_receipt.view', json_decode($observed['none']['body'], true)['required_permission']);
        self::assertCount(1, array_unique(array_map(json_encode(...), $observed)),
            "The filters are distinguishable to a caller without goods_receipt.view:\n".print_r($observed, true));
    }

    /** `warehouse_operator` holds `goods_receipt.view` and reads the register. */
    #[Test]
    public function aWarehouseOperatorReadsTheRegister(): void
    {
        self::assertContains('goods_receipt.view', DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));
        $this->seedReceipt($this->order('PO-328000'), 'GR-000001', '2026-09-01T00:00:00Z');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $this->send('GET', '/goods-receipts');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['GR-000001'], array_column($this->json()['items'], 'receipt_number'));
    }

    /**
     * API-19d / TB-5 — `q` searches `goods_receipt.receipt_number` alone, by prefix, with its wildcards escaped.
     */
    #[Test]
    public function theSearchMatchesTheReceiptNumberByPrefixAndEscapesItsWildcards(): void
    {
        $o = $this->order('PO-329000');
        $this->seedReceipt($o, 'GR-000101', '2026-09-01T00:00:00Z');
        $this->seedReceipt($o, 'GR-000102', '2026-09-01T00:00:00Z');
        $this->seedReceipt($o, 'GR-000201', '2026-09-01T00:00:00Z');

        $this->send('GET', '/goods-receipts?q=GR-0001');
        self::assertSame(['GR-000102', 'GR-000101'], array_column($this->json()['items'], 'receipt_number'));

        $this->send('GET', '/goods-receipts?q=000101');
        self::assertSame([], $this->json()['items'], 'The search matched mid-string, which the index cannot serve.');

        $this->send('GET', '/goods-receipts?q=PO-329000');
        self::assertSame([], $this->json()['items'], 'The search reached the order number, which is a cross-table match TB-5 forbids.');

        $this->send('GET', '/goods-receipts?q=%25');
        self::assertSame([], $this->json()['items'], 'A literal % matched everything, so the wildcard is not escaped.');

        $this->send('GET', '/goods-receipts?q=GR_000101');
        self::assertSame([], $this->json()['items'], 'A literal _ matched any character, so the wildcard is not escaped.');

        // The search composes with the order filter (both predicates apply). The order's view sorts by receipt time,
        // and these share one, so the pair is compared as a set.
        $this->seedReceipt($this->order('PO-330000'), 'GR-000103', '2026-09-01T00:00:00Z');
        $this->send('GET', '/goods-receipts?q=GR-0001&purchase_order_id='.$o['order']);
        $numbers = array_column($this->json()['items'], 'receipt_number');
        sort($numbers);
        self::assertSame(['GR-000101', 'GR-000102'], $numbers);
    }

    /** ST-2 / API-22: an empty page says whether the workspace has any receipt at all, and a tenant sees only its own. */
    #[Test]
    public function anEmptyPageReportsWhetherTheWorkspaceHasAnyAndATenantSeesOnlyItsOwn(): void
    {
        $theirs = $this->orderFor(self::TENANT_B, 'PO-331000');
        $this->seedReceiptFor(self::TENANT_B, $theirs['order'], $theirs['warehouse'], 'GR-000001', '2026-09-01T00:00:00Z', null);

        $this->send('GET', '/goods-receipts');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([], $this->json()['items'], 'Another workspace\'s receipt was listed.');
        self::assertFalse($this->json()['tenant_has_rows'], 'Another workspace\'s receipt counted as this one\'s.');

        $this->seedReceipt($this->order('PO-332000'), 'GR-000001', '2026-09-01T00:00:00Z');
        $this->send('GET', '/goods-receipts?q=GR-9');
        self::assertSame([], $this->json()['items']);
        self::assertTrue($this->json()['tenant_has_rows']);
    }

    /**
     * A cursor is refused, not a 500, when its sort key cannot be this view's: a register cursor (a number) sent to
     * an order's view (which compares `received_at`, a `timestamptz`). Malformed and doubled cursors, a bad UUID and
     * an out-of-range limit are 422s too.
     */
    #[Test]
    public function aCursorFromTheOtherViewIsRefusedNotA500(): void
    {
        $o = $this->order('PO-333000');
        $this->seedReceipt($o, 'GR-000001', '2026-09-01T00:00:00Z');
        $this->seedReceipt($o, 'GR-000002', '2026-09-02T00:00:00Z');

        $this->send('GET', '/goods-receipts?limit=1');
        $registerCursor = (string) $this->json()['next_cursor'];

        $this->send('GET', '/goods-receipts?limit=1&purchase_order_id='.$o['order'].'&after='.urlencode($registerCursor));
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['?after'], array_column($this->json()['errors'], 'pointer'));
        self::assertSame('malformed_cursor', $this->json()['errors'][0]['code']);

        $this->send('GET', '/goods-receipts?after=not-a-cursor');
        self::assertSame(422, $this->httpStatus());
        self::assertSame('malformed_cursor', $this->json()['errors'][0]['code']);

        $this->send('GET', '/goods-receipts?after=a&before=b');
        self::assertSame(422, $this->httpStatus());
        self::assertContains('mutually_exclusive', array_column($this->json()['errors'], 'code'));
    }

    // -----------------------------------------------------------------------

    /** @return array{order: string, warehouse: string} */
    private function order(string $number, string $status = PurchaseOrder::STATUS_ORDERED): array
    {
        return $this->orderFor(self::TENANT_A, $number, $status);
    }

    /** @return array{order: string, warehouse: string} */
    private function orderFor(string $tenantId, string $number, string $status = PurchaseOrder::STATUS_ORDERED): array
    {
        $warehouse = $this->seedWarehouse($tenantId, 'WH '.$number);
        $supplier = $this->seedSupplier($tenantId, 'Supplier '.$number);

        return [
            'order' => $this->seedOrder($tenantId, $number, $supplier, $warehouse, status: $status),
            'warehouse' => $warehouse,
        ];
    }

    /** @param array{order: string, warehouse: string} $order */
    private function seedReceipt(array $order, string $number, string $receivedAt, ?string $receiver = 'actor'): string
    {
        return $this->seedReceiptFor(self::TENANT_A, $order['order'], $order['warehouse'], $number, $receivedAt,
            'actor' === $receiver ? (string) $this->actorId : $receiver);
    }

    /** A line-less receipt, as the owner: the order tests read the header only. */
    private function seedReceiptFor(string $tenantId, string $orderId, string $warehouseId, string $number, string $receivedAt, ?string $receiver): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO goods_receipt
                (id, tenant_id, purchase_order_id, warehouse_id, receipt_number, received_at, received_by_user_id,
                 idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::timestamptz, ?::uuid,
                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
            SQL, [$id, $tenantId, $orderId, $warehouseId, $number, $receivedAt, $receiver]);

        return $id;
    }
}
