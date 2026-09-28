<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`ListFulfilments` — sales step 6, commit C1.** The shipment register (`/fulfilments`) and the shipments of one
 * order (`?sales_order_id=`), in `IA.md` §5.6's two orders:
 *
 * - unfiltered: `fulfilment_number`, newest first, over `fulfilment_number_unique` (R-1);
 * - inside an order: `shipped_at`, newest first, then `id`, over `fulfilment_tenant_order_idx`.
 *
 * No new index (D-d: *"measure first"*). A `sales_order_id` that names another workspace's order, or no order, is
 * the **same empty 200** (`SEC` §9.1) — never a 404 or 400, so the filter is not an oracle for another workspace's
 * ids. The row is `GetFulfilment`'s shape, lines included (§4.4.3: `fulfilment` has no line sub-list, so its
 * lines stay embedded).
 */
final class FulfilmentListTest extends PurchaseOrderTestCase
{
    /** A list row is exactly the document `GetFulfilment` returns, inside API-22's envelope. */
    #[Test]
    public function aListRowIsTheFulfilmentGetFulfilmentReturns(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Shape');
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-197000', null, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
        $item = $this->seedItem(self::TENANT_A, 'SKU-SHAPE');
        $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $item, quantity: '5');
        $bin = $this->seedBinWithStock($warehouse, 'A-01', $item, '5');

        $this->send('POST', '/sales-orders/'.$order.'/fulfilments', ['lines' => [
            ['sales_order_line_id' => $line, 'quantity' => '2', 'storage_location_id' => $bin],
        ]], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = $this->json()['id'];

        $this->send('GET', '/fulfilments/'.$id);
        $document = $this->json();

        $this->send('GET', '/fulfilments');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['items', 'next_cursor', 'previous_cursor', 'tenant_has_rows'], array_keys($this->json()));
        self::assertSame([$document], $this->json()['items'], 'A list row is not the document GetFulfilment returns.');
        self::assertNotSame([], $document['lines'], 'The fixture shipped a line; an empty lines array proves nothing.');
        self::assertNull($this->json()['next_cursor']);
        self::assertNull($this->json()['previous_cursor']);
        self::assertTrue($this->json()['tenant_has_rows']);

        $this->send('GET', '/fulfilments?sales_order_id='.$order);
        self::assertSame([$document], $this->json()['items']);
    }

    /**
     * **Unfiltered: number order, newest first (IA R-1) — NOT shipment time.** The fixture makes the two disagree:
     * the highest number shipped earliest, so ordering by `shipped_at` (mutation C1-c) reverses the page.
     */
    #[Test]
    public function theRegisterIsInNumberOrderNewestFirstNotShipmentTime(): void
    {
        $o = $this->order('SO-194000');
        $this->seedFulfilment($o, 'FUL-000001', '2026-09-03T00:00:00Z');
        $this->seedFulfilment($o, 'FUL-000002', '2026-09-02T00:00:00Z');
        $this->seedFulfilment($o, 'FUL-000003', '2026-09-01T00:00:00Z');

        $this->send('GET', '/fulfilments');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['FUL-000003', 'FUL-000002', 'FUL-000001'], array_column($this->json()['items'], 'fulfilment_number'));
    }

    /**
     * **Inside an order: `shipped_at` newest first, then `id` newest first** — and only that order's shipments. Two
     * share one `shipped_at`, which is the tie group the `(shipped_at, id)` row comparison exists for, and the number
     * order disagrees with the time order so a number sort cannot pass.
     */
    #[Test]
    public function insideAnOrderTheShipmentsAreNewestShipmentFirst(): void
    {
        $o = $this->order('SO-163000');
        $other = $this->order('SO-182000');
        $early = $this->seedFulfilment($o, 'FUL-000003', '2026-09-01T00:00:00Z');
        $tieA = $this->seedFulfilment($o, 'FUL-000001', '2026-09-02T00:00:00Z');
        $tieB = $this->seedFulfilment($o, 'FUL-000002', '2026-09-02T00:00:00Z');
        $this->seedFulfilment($other, 'FUL-000004', '2026-09-05T00:00:00Z');

        $ties = [$tieA, $tieB];
        rsort($ties);

        $this->send('GET', '/fulfilments?sales_order_id='.$o['order']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([...$ties, $early], array_column($this->json()['items'], 'id'));
    }

    /**
     * API-17/API-18 — both views walk every row exactly once forwards, and `previous_cursor` walks back to the row
     * the forward walk started from.
     */
    #[Test]
    public function theKeysetWalkReturnsEveryRowExactlyOnceInBothViewsAndBothDirections(): void
    {
        $o = $this->order('SO-213000');
        foreach ([['FUL-000001', '2026-09-01'], ['FUL-000002', '2026-09-02'], ['FUL-000003', '2026-09-02'], ['FUL-000004', '2026-09-03']] as [$n, $day]) {
            $this->seedFulfilment($o, $n, $day.'T00:00:00Z');
        }

        foreach (['/fulfilments?limit=1', '/fulfilments?limit=1&sales_order_id='.$o['order']] as $base) {
            $walk = [];
            $cursors = [];
            $cursor = null;
            for ($page = 0; $page < 6; ++$page) {
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
        }
    }

    /**
     * **`SEC` §9.1: a `sales_order_id` naming another workspace's order, or no order, is the same empty 200** — byte
     * for byte, and never 404 or 400. Mutation C1-d (404 for an unknown order) turns this red.
     */
    #[Test]
    public function aForeignOrMissingOrderFilterIsTheSameEmpty200(): void
    {
        $this->seedFulfilment($this->order('SO-172000'), 'FUL-000001', '2026-09-01T00:00:00Z');

        $shipperB = $this->seedPrincipal(self::TENANT_B, []);
        $theirs = $this->seedSalesOrder(self::TENANT_B, 'SO-202000', status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z');
        $this->seedFulfilmentFor(self::TENANT_B, $theirs, $this->warehouseOf(self::TENANT_B, $theirs), 'FUL-000009', '2026-09-01T00:00:00Z', $shipperB);

        $bodies = [];
        foreach (['theirs' => $theirs, 'nowhere' => Uuid::v4()->toRfc4122()] as $label => $id) {
            $this->send('GET', '/fulfilments?sales_order_id='.$id);
            self::assertSame(200, $this->httpStatus(), $label.': '.(string) $this->client()->getResponse()->getContent());
            $bodies[$label] = (string) $this->client()->getResponse()->getContent();
        }

        self::assertSame([], json_decode($bodies['theirs'], true)['items']);
        self::assertTrue(json_decode($bodies['theirs'], true)['tenant_has_rows'],
            'This workspace has a fulfilment, so an empty filtered page must say so (ST-2).');
        self::assertSame($bodies['theirs'], $bodies['nowhere'], 'Another workspace\'s order is distinguishable from no order.');
    }

    /** E-1: a caller without `fulfilment.view` gets the same 403 whatever the filter names. */
    #[Test]
    public function aCallerWithoutFulfilmentViewGetsTheSame403WhateverTheFilterNames(): void
    {
        $mine = $this->order('SO-153000');
        $theirs = $this->seedSalesOrder(self::TENANT_B, 'SO-203000');

        $this->actAs(self::TENANT_A, ['sales_order.view']);

        $observed = [];
        foreach (['none' => '', 'mine' => '?sales_order_id='.$mine['order'], 'theirs' => '?sales_order_id='.$theirs,
            'nowhere' => '?sales_order_id='.Uuid::v4()->toRfc4122()] as $label => $query) {
            $this->send('GET', '/fulfilments'.$query);
            $response = $this->client()->getResponse();
            $headers = $response->headers->all();
            ksort($headers);
            if (\array_key_exists('date', $headers)) {
                $headers['date'] = ['<present>'];
            }
            $observed[$label] = ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => (string) $response->getContent()];
        }

        self::assertSame(403, $observed['none']['status']);
        self::assertSame('fulfilment.view', json_decode($observed['none']['body'], true)['required_permission']);
        self::assertCount(1, array_unique(array_map(json_encode(...), $observed)),
            "The filters are distinguishable to a caller without fulfilment.view:\n".print_r($observed, true));
    }

    /** `warehouse_operator` holds `fulfilment.view` without `sales_order.view` (2026-09-15) and reads the register. */
    #[Test]
    public function aWarehouseOperatorReadsTheRegister(): void
    {
        $this->seedFulfilment($this->order('SO-216000'), 'FUL-000001', '2026-09-01T00:00:00Z');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $this->send('GET', '/fulfilments');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['FUL-000001'], array_column($this->json()['items'], 'fulfilment_number'));
    }

    /**
     * API-19d / TB-5 — `q` searches `fulfilment.fulfilment_number` alone, by prefix, with its wildcards escaped.
     */
    #[Test]
    public function theSearchMatchesTheFulfilmentNumberByPrefixAndEscapesItsWildcards(): void
    {
        $o = $this->order('SO-188000');
        $this->seedFulfilment($o, 'FUL-000101', '2026-09-01T00:00:00Z');
        $this->seedFulfilment($o, 'FUL-000102', '2026-09-01T00:00:00Z');
        $this->seedFulfilment($o, 'FUL-000201', '2026-09-01T00:00:00Z');

        $this->send('GET', '/fulfilments?q=FUL-0001');
        self::assertSame(['FUL-000102', 'FUL-000101'], array_column($this->json()['items'], 'fulfilment_number'));

        $this->send('GET', '/fulfilments?q=000101');
        self::assertSame([], $this->json()['items'], 'The search matched mid-string, which the index cannot serve.');

        $this->send('GET', '/fulfilments?q=SO-188000');
        self::assertSame([], $this->json()['items'], 'The search reached the order number, which is a cross-table match TB-5 forbids.');

        $this->send('GET', '/fulfilments?q=%25');
        self::assertSame([], $this->json()['items'], 'A literal % matched everything, so the wildcard is not escaped.');

        $this->send('GET', '/fulfilments?q=FUL_000101');
        self::assertSame([], $this->json()['items'], 'A literal _ matched any character, so the wildcard is not escaped.');
    }

    /** ST-2 / API-22: an empty page says whether the workspace has any fulfilment at all, and a tenant sees only its own. */
    #[Test]
    public function anEmptyPageReportsWhetherTheWorkspaceHasAnyAndATenantSeesOnlyItsOwn(): void
    {
        $shipperB = $this->seedPrincipal(self::TENANT_B, []);
        $theirs = $this->seedSalesOrder(self::TENANT_B, 'SO-134000', status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z');
        $this->seedFulfilmentFor(self::TENANT_B, $theirs, $this->warehouseOf(self::TENANT_B, $theirs), 'FUL-000001', '2026-09-01T00:00:00Z', $shipperB);

        $this->send('GET', '/fulfilments');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([], $this->json()['items'], 'Another workspace\'s fulfilment was listed.');
        self::assertFalse($this->json()['tenant_has_rows'], 'Another workspace\'s fulfilment counted as this one\'s.');

        $this->seedFulfilment($this->order('SO-126000'), 'FUL-000001', '2026-09-01T00:00:00Z');
        $this->send('GET', '/fulfilments?q=FUL-9');
        self::assertSame([], $this->json()['items']);
        self::assertTrue($this->json()['tenant_has_rows']);
    }

    /**
     * A cursor is refused, not a 500, when its sort key cannot be this view's: a register cursor (a number) sent to
     * an order's view (which compares `shipped_at`, a `timestamptz`).
     */
    #[Test]
    public function aCursorFromTheOtherViewIsRefusedNotA500(): void
    {
        $o = $this->order('SO-145000');
        $this->seedFulfilment($o, 'FUL-000001', '2026-09-01T00:00:00Z');
        $this->seedFulfilment($o, 'FUL-000002', '2026-09-02T00:00:00Z');

        $this->send('GET', '/fulfilments?limit=1');
        $registerCursor = (string) $this->json()['next_cursor'];

        $this->send('GET', '/fulfilments?limit=1&sales_order_id='.$o['order'].'&after='.urlencode($registerCursor));
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['?after'], array_column($this->json()['errors'], 'pointer'));
        self::assertSame('malformed_cursor', $this->json()['errors'][0]['code']);

        $this->send('GET', '/fulfilments?after=a&before=b');
        self::assertSame(422, $this->httpStatus());
        self::assertContains('mutually_exclusive', array_column($this->json()['errors'], 'code'));
    }

    // -----------------------------------------------------------------------

    /** @return array{order: string, warehouse: string} */
    private function order(string $number): array
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$number);

        return [
            'order' => $this->seedSalesOrder(self::TENANT_A, $number, null, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z'),
            'warehouse' => $warehouse,
        ];
    }

    /** @param array{order: string, warehouse: string} $order */
    private function seedFulfilment(array $order, string $number, string $shippedAt): string
    {
        return $this->seedFulfilmentFor(self::TENANT_A, $order['order'], $order['warehouse'], $number, $shippedAt, (string) $this->actorId);
    }

    /** A line-less fulfilment, as the owner: the order tests read the header only. */
    private function seedFulfilmentFor(string $tenantId, string $orderId, string $warehouseId, string $number, string $shippedAt, string $shipper): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO fulfilment
                (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number, shipped_at, shipped_by_user_id,
                 idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::timestamptz, ?::uuid,
                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
            SQL, [$id, $tenantId, $orderId, $warehouseId, $number, $shippedAt, $shipper]);

        return $id;
    }

    private function warehouseOf(string $tenantId, string $orderId): string
    {
        return (string) $this->fetchAsOwnerForTenant($tenantId,
            'SELECT warehouse_id::text AS w FROM sales_order WHERE id = ?::uuid', [$orderId])[0]['w'];
    }

    private function seedBinWithStock(string $warehouseId, string $code, string $itemId, string $qty): string
    {
        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO storage_location (id, tenant_id, warehouse_id, code, kind) VALUES (?::uuid, ?::uuid, ?::uuid, ?, \'bin\')',
            [$bin, self::TENANT_A, $warehouseId, $code]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $itemId, $bin, $qty]);

        return $bin;
    }
}
