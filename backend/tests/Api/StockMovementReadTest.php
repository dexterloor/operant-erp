<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\ApiResource\StockMovementResource;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Pagination\Cursor;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Stock reads C2 — `ListStockMovements` and `GetStockMovement`** (`docs/session13/stock-reads-design.md` §2.2–§2.3, §4.7
 * and Revision 1; `SEC` / `UX` / `DATA` / `PO` role files of 2026-10-14).
 *
 * Pins:
 *  - the `/stock-movements` paths are exactly {@see self::PINNED_STOCK_MOVEMENT_OPERATIONS}, joined into
 *    `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union;
 *  - newest first by business time, `occurred_at DESC, id DESC`, in both views; a TIE GROUP (one instant) walked at
 *    `limit=1` forward and backward, and a large one at `limit=50`, sees every row exactly once (M-C2-2); two movements
 *    inside one millisecond walk in order — the cursor key is the column's raw text (M-C2-3, a guard for a future writer:
 *    real rows carry whole seconds today);
 *  - the literal body of a receipt, a fulfilment and a return movement: `source_type` / `source_id` bare, never expanded
 *    (`SEC` §2); a holder of `stock_movement.view` without the documents' codes still sees `source_id`;
 *  - `?sort` is undeclared (§14.2 item 29); a cursor whose key is not a timestamp is `malformed_cursor`, never a 500;
 *  - an unknown and another workspace's item filter are the same empty page; `tenant_has_rows` tells an empty workspace
 *    from an empty filter (M13);
 *  - `GetStockMovement`: 200; an unknown, malformed, `adjustments` or another workspace's id is the same 404; 403 first.
 */
final class StockMovementReadTest extends PurchaseOrderTestCase
{
    /**
     * The `/stock-movements` operations, held equal to the registry.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_STOCK_MOVEMENT_OPERATIONS = [
        'ListStockMovements' => ['method' => 'GET', 'path' => '/stock-movements', 'idem' => false],
        'GetStockMovement' => ['method' => 'GET', 'path' => '/stock-movements/{id}', 'idem' => false],
    ];

    /** `StockMovementResource`'s serialized members — §4.4.3's StockMovement row, plus `id`. */
    public const PINNED_STOCK_MOVEMENT_FIELDS = ['id', 'quantity', 'occurred_at', 'reason', 'source_type', 'source_id', 'inventory_item', 'storage_location'];

    private string $bin = '';

    private string $item = '';

    #[Test]
    public function theStockMovementPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/stock-movements(/|$)#', $path)) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $path];
            }
        }
        ksort($declared);
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_STOCK_MOVEMENT_OPERATIONS);
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /** Independent of `docs/`: the DTO's serialized members, in order. */
    #[Test]
    public function theMovementShapeIsTheRowsAndNothingElse(): void
    {
        $members = [];
        foreach (new \ReflectionClass(StockMovementResource::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attribute = new \ReflectionProperty(StockMovementResource::class, $parameter->getName())->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $attribute ? $parameter->getName() : $attribute->newInstance()->serializedName;
        }

        self::assertSame(self::PINNED_STOCK_MOVEMENT_FIELDS, $members);
    }

    /** The literal rows of a receipt, a fulfilment and a return movement: `source_*` bare, signed quantity, scale 6. */
    #[Test]
    public function eachMovementIsServedWholeWithItsSourceBare(): void
    {
        $this->world();
        $receipt = Uuid::v4()->toRfc4122();
        $shipment = Uuid::v4()->toRfc4122();
        $return = Uuid::v4()->toRfc4122();
        $in = $this->movement('5', 'receipt', '2026-03-01 09:00:00+00', 'goods_receipt', $receipt);
        $out = $this->movement('-2', 'fulfilment', '2026-03-02 09:00:00+00', 'fulfilment', $shipment);
        $back = $this->movement('1', 'return', '2026-03-03 09:00:00+00', 'sales_return', $return);

        $this->send('GET', '/stock-movements');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $item = ['id' => $this->item, 'sku' => 'SKU-MOV-1', 'name' => 'SKU-MOV-1'];
        $bin = ['id' => $this->bin, 'code' => 'A-01'];
        self::assertSame([
            'items' => [
                ['id' => $back, 'quantity' => '1.000000', 'occurred_at' => '2026-03-03T09:00:00.000+00:00', 'reason' => 'return', 'source_type' => 'sales_return', 'source_id' => $return, 'inventory_item' => $item, 'storage_location' => $bin],
                ['id' => $out, 'quantity' => '-2.000000', 'occurred_at' => '2026-03-02T09:00:00.000+00:00', 'reason' => 'fulfilment', 'source_type' => 'fulfilment', 'source_id' => $shipment, 'inventory_item' => $item, 'storage_location' => $bin],
                ['id' => $in, 'quantity' => '5.000000', 'occurred_at' => '2026-03-01T09:00:00.000+00:00', 'reason' => 'receipt', 'source_type' => 'goods_receipt', 'source_id' => $receipt, 'inventory_item' => $item, 'storage_location' => $bin],
            ],
            'next_cursor' => null,
            'previous_cursor' => null,
            'tenant_has_rows' => true,
        ], $this->json());

        $this->send('GET', '/stock-movements/'.$out);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['id' => $out, 'quantity' => '-2.000000', 'occurred_at' => '2026-03-02T09:00:00.000+00:00', 'reason' => 'fulfilment', 'source_type' => 'fulfilment', 'source_id' => $shipment, 'inventory_item' => $item, 'storage_location' => $bin], $this->json());
    }

    /**
     * `SEC` §2: a holder of `stock_movement.view` and NOTHING else still sees the kind and the id of the document that
     * caused a movement — so withholding it later is a deliberate edit (M5) — and never a field of that document (M6).
     */
    #[Test]
    public function aHolderOfTheMovementCodeAloneSeesTheSourceIdAndNothingOfTheDocument(): void
    {
        $this->world();
        $receipt = Uuid::v4()->toRfc4122();
        $id = $this->movement('3', 'receipt', '2026-03-01 09:00:00+00', 'goods_receipt', $receipt);
        $this->actAs(self::TENANT_A, ['stock_movement.view']);

        $this->send('GET', '/stock-movements/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('goods_receipt', $this->json()['source_type']);
        self::assertSame($receipt, $this->json()['source_id']);
        self::assertIsString($this->json()['source_id'], 'source_id is bare, never an object naming the document.');
        self::assertSame(['id' => $this->item, 'sku' => 'SKU-MOV-1', 'name' => 'SKU-MOV-1'], $this->json()['inventory_item']);
    }

    /** Newest first by business time, in both views; the item filter narrows to one item (M1, M4). */
    #[Test]
    public function movementsAreNewestFirstAndTheItemFilterNarrows(): void
    {
        $this->world();
        $other = $this->seedItem(self::TENANT_A, 'SKU-MOV-2');
        $old = $this->movement('1', 'receipt', '2026-01-01 00:00:00+00');
        $mid = $this->movement('1', 'receipt', '2026-02-01 00:00:00+00', null, null, $other);
        $new = $this->movement('1', 'receipt', '2026-03-01 00:00:00+00');

        $this->send('GET', '/stock-movements');
        self::assertSame([$new, $mid, $old], array_column($this->json()['items'], 'id'));

        $this->send('GET', '/stock-movements?inventory_item_id='.$this->item);
        self::assertSame([$new, $old], array_column($this->json()['items'], 'id'));
    }

    /**
     * **The mutation that matters (M-C2-2):** three movements at ONE instant — a document's lines, or every document
     * back-dated to the same second — walked at `limit=1` forward, then backward from the last page, each seen exactly once
     * and in `id DESC` order. A keyset on `occurred_at` alone skips or repeats inside the group.
     */
    #[Test]
    public function aTieGroupIsWalkedOneRowAtATimeInBothDirectionsWithoutSkipOrRepeat(): void
    {
        $this->world();
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->movement('1', 'receipt', '2026-04-01 12:00:00+00');
        }
        $expected = $ids;
        usort($expected, static fn (string $a, string $b): int => strcmp($b, $a));

        [$forward, $lastBody] = $this->walk('/stock-movements', 1, 'after', 'next_cursor');
        self::assertSame($expected, $forward);

        $backward = array_column($lastBody['items'], 'id');
        $cursor = $lastBody['previous_cursor'];
        $pages = 0;
        while (null !== $cursor && $pages++ < 10) {
            $this->send('GET', '/stock-movements?limit=1&before='.urlencode($cursor));
            self::assertSame(200, $this->httpStatus());
            $backward = [...array_column($this->json()['items'], 'id'), ...$backward];
            $cursor = $this->json()['previous_cursor'];
        }
        self::assertSame($expected, $backward, 'The backward walk inside the tie group skipped or repeated a row.');
    }

    /** A large tie group (120 at one instant) walked at `limit=50` — three pages, every row once (design R-FINAL.2 step 5). */
    #[Test]
    public function aLargeTieGroupIsWalkedAtTheDefaultPageSizeWithoutSkipOrRepeat(): void
    {
        $this->world();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, occurred_at)
            SELECT gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, 1, 'receipt', '2026-05-01 08:00:00+00'
            FROM generate_series(1, 120)
            SQL, [self::TENANT_A, $this->item, $this->bin]);

        [$seen] = $this->walk('/stock-movements?inventory_item_id='.$this->item, 50, 'after', 'next_cursor');

        self::assertCount(120, $seen);
        self::assertCount(120, array_unique($seen), 'A row was repeated across pages inside the tie group.');
        $sorted = $seen;
        usort($sorted, static fn (string $a, string $b): int => strcmp($b, $a));
        self::assertSame($sorted, $seen, 'Inside a tie group the order is id DESC.');
    }

    /**
     * M-C2-3: two movements 400 µs apart inside ONE millisecond walk in order at `limit=1`. A cursor keyed on the wire's
     * millisecond rendering would put both on the wrong side of itself. A guard for a future writer: today's writers bind
     * whole seconds, but the column's `DEFAULT now()` gives microseconds to one that omits the value.
     */
    #[Test]
    public function twoMovementsInsideOneMillisecondWalkInOrder(): void
    {
        $this->world();
        $earlier = $this->movement('1', 'receipt', '2026-06-01 10:00:00.000100+00');
        $later = $this->movement('1', 'receipt', '2026-06-01 10:00:00.000500+00');

        [$seen] = $this->walk('/stock-movements', 1, 'after', 'next_cursor');

        self::assertSame([$later, $earlier], $seen);
    }

    /** §14.2 item 29: `sort` is not declared. A cursor whose key is not a timestamp is refused, never cast (M9). */
    #[Test]
    public function sortIsUndeclaredAndAForeignCursorIsMalformed(): void
    {
        $this->send('GET', '/stock-movements?sort=occurred_at');
        self::assertSame(422, $this->httpStatus());
        self::assertContains(['pointer' => '?sort', 'code' => 'undeclared_parameter'],
            array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']));

        foreach (['after', 'before'] as $name) {
            $this->send('GET', '/stock-movements?'.$name.'='.urlencode(new Cursor('SKU-1', Uuid::v4()->toRfc4122())->encode()));
            self::assertSame(422, $this->httpStatus(), $name.': '.$this->client()->getResponse()->getContent());
            self::assertSame([['pointer' => '?'.$name, 'code' => 'malformed_cursor']],
                array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']));
        }

        $this->send('GET', '/stock-movements?after=not-a-cursor');
        self::assertSame(422, $this->httpStatus());
    }

    /** No existence oracle: an unknown and another workspace's item answer byte-equal empty pages; M13. */
    #[Test]
    public function anUnknownAndAForeignItemAreTheSameEmptyPageAndTheWorkspaceSaysWhetherItHasRows(): void
    {
        $this->send('GET', '/stock-movements');
        self::assertSame([[], false], [$this->json()['items'], $this->json()['tenant_has_rows']]);

        $this->world();
        $this->movement('1', 'receipt', '2026-03-01 00:00:00+00');
        $foreignWarehouse = $this->seedWarehouse(self::TENANT_B, 'Theirs');
        $foreignItem = $this->seedItem(self::TENANT_B, 'SKU-MOV-THEIRS');
        $this->insertAsOwnerForTenant(self::TENANT_B,
            "INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 'receipt')",
            [Uuid::v4()->toRfc4122(), self::TENANT_B, $foreignItem, $this->seedStorageLocation(self::TENANT_B, $foreignWarehouse, 'A-01')]);

        $this->send('GET', '/stock-movements?inventory_item_id='.$foreignItem);
        self::assertSame(200, $this->httpStatus());
        $theirs = (string) $this->client()->getResponse()->getContent();
        $this->send('GET', '/stock-movements?inventory_item_id='.Uuid::v4()->toRfc4122());
        self::assertSame($theirs, (string) $this->client()->getResponse()->getContent());
        self::assertSame(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => true], $this->json());
    }

    /** `GetStockMovement`: unknown, malformed, `adjustments` and another workspace's id are the same 404 (L-4). */
    #[Test]
    public function anUnknownAMalformedAndAForeignMovementAreTheSame404(): void
    {
        $foreignWarehouse = $this->seedWarehouse(self::TENANT_B, 'Theirs');
        $foreign = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_B,
            "INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 'receipt')",
            [$foreign, self::TENANT_B, $this->seedItem(self::TENANT_B, 'SKU-MOV-B'), $this->seedStorageLocation(self::TENANT_B, $foreignWarehouse, 'A-01')]);

        $bodies = [];
        foreach ([$foreign, Uuid::v4()->toRfc4122(), 'not-a-uuid', 'adjustments'] as $id) {
            $this->send('GET', '/stock-movements/'.$id);
            self::assertSame(404, $this->httpStatus(), $id.': '.$this->client()->getResponse()->getContent());
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertCount(1, array_unique($bodies), 'The four 404s are not byte-equal.');
    }

    /** E-1: a caller without `stock_movement.view` is 403 on both, the same for a real and a random id; holders read. */
    #[Test]
    public function onlyHoldersOfTheMovementCodeReadEither(): void
    {
        $this->world();
        $id = $this->movement('1', 'receipt', '2026-03-01 00:00:00+00');

        foreach (DefaultRoles::names() as $role) {
            $codes = DefaultRoles::codesFor($role);
            $this->actAs(self::TENANT_A, $codes);
            $holds = \in_array('stock_movement.view', $codes, true);
            $this->send('GET', '/stock-movements');
            self::assertSame($holds ? 200 : 403, $this->httpStatus(), $role);
            $this->send('GET', '/stock-movements/'.$id);
            self::assertSame($holds ? 200 : 403, $this->httpStatus(), $role);
            if (!$holds) {
                $real = (string) $this->client()->getResponse()->getContent();
                $this->send('GET', '/stock-movements/'.Uuid::v4()->toRfc4122());
                self::assertSame($real, (string) $this->client()->getResponse()->getContent(), $role);
            }
        }
    }

    private function world(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Movements');
        $this->bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01');
        $this->item = $this->seedItem(self::TENANT_A, 'SKU-MOV-1');
    }

    /** A movement, as the owner (no `src/` path but the three stock writers inserts one). @return string its id */
    private function movement(string $quantity, string $reason, string $occurredAt, ?string $sourceType = null, ?string $sourceId = null, ?string $item = null): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, occurred_at, source_type, source_id)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, ?, ?::timestamptz, ?, ?::uuid)
            SQL, [$id, self::TENANT_A, $item ?? $this->item, $this->bin, $quantity, $reason, $occurredAt, $sourceType, $sourceId]);

        return $id;
    }

    /**
     * Follow `next_cursor` from the first page; return the ids seen and the last page's body.
     *
     * @return array{list<string>, array<string, mixed>}
     */
    private function walk(string $path, int $limit, string $direction, string $cursorKey): array
    {
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $this->send('GET', $path.(str_contains($path, '?') ? '&' : '?').'limit='.$limit.(null === $cursor ? '' : '&'.$direction.'='.urlencode($cursor)));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $body = $this->json();
            $seen = [...$seen, ...array_column($body['items'], 'id')];
            $cursor = $body[$cursorKey];
        } while (null !== $cursor && ++$pages < 500);

        return [$seen, $body];
    }
}
