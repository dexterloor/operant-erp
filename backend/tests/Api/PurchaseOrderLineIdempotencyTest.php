<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **V2-R-12 — `CreatePurchaseOrderLine` wrote a second line on retry** (V4 batch B3a, standing rule 1, 2026-09-16).
 *
 * The line create minted a new id and `MAX(line_number) + 1` on every request, and the only unique on the table is
 * `(tenant_id, purchase_order_id, line_number)` among live lines. A retried timeout therefore wrote a second line and
 * `recomputeTotal()` raised the order's total — a draft an approver would then approve at the wrong amount. §4.2 marked the
 * row `Idem: no`, which API-14's predicate (*"repeating it writes a second row the caller did not intend"*) does not allow.
 *
 * The fix applies mechanisms already ruled, `CreatePurchaseOrder`'s precedent: the `Idempotency-Key` header's SHA-256 in
 * `purchase_order_line.idempotency_key` on the live-row unique `purchase_order_line_tenant_idem_live`, with a
 * `request_digest` (D-B) that includes the **path's order id**, so the same key sent to another order is a 409 and never a
 * replay of another order's line. The concurrent half is `PurchaseOrderRowLockTest::aConcurrentSameKeyOnAnotherOrderIsAConflictNotA500`.
 *
 * Rows are counted in the database, not responses: a replay that answered 201 twice while writing twice passes every
 * response-level assertion.
 */
final class PurchaseOrderLineIdempotencyTest extends PurchaseOrderTestCase
{
    /** @return array{order: string, supplier: string, warehouse: string, item: string} */
    private function draftWithAListedItem(string $number = 'PO-1'): array
    {
        $draft = $this->seedDraft(self::TENANT_A, $number);
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);

        return $draft;
    }

    /**
     * @param array<string, string> $body
     */
    private function createLine(string $orderId, array $body, ?string $key): void
    {
        $this->send('POST', '/purchase-orders/'.$orderId.'/lines', $body, null === $key ? [] : [IdempotencyKeyGuard::HEADER => $key]);
    }

    /** @return array<string, string> */
    private static function body(string $item, string $quantity = '2', string $unitCost = '3'): array
    {
        return ['inventory_item_id' => $item, 'quantity' => $quantity, 'unit_cost' => $unitCost];
    }

    private function lineCount(string $orderId): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM purchase_order_line WHERE purchase_order_id = ?::uuid', [$orderId]);
    }

    private function totalOf(string $orderId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT total_amount FROM purchase_order WHERE id = ?::uuid', [$orderId])[0]['total_amount'];
    }

    /**
     * **The defect.** At `4cb214d`: 201 twice, two rows, total 12.0000.
     *
     * *Mutation (b):* the writer ignores the header (the key lookup never matches) → red here on the row count.
     */
    #[Test]
    public function aRetriedLineCreateWithTheSameKeyWritesOneLine(): void
    {
        $draft = $this->draftWithAListedItem();
        $key = Uuid::v4()->toRfc4122();

        $this->createLine($draft['order'], self::body($draft['item']), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];
        $location = $this->client()->getResponse()->headers->get('Location');

        $this->createLine($draft['order'], self::body($draft['item']), $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json()['id'], 'The replay answered with a different line.');
        self::assertSame($location, $this->client()->getResponse()->headers->get('Location'),
            'A replay answers with the same Location as the first response (§6.2).');
        self::assertSame(1, $this->lineCount($draft['order']),
            'A retried line create wrote a second line (V2-R-12).');
        self::assertSame('6.0000', $this->totalOf($draft['order']),
            'The retry moved the order total, which is the amount an approver approves.');
    }

    /** The other direction: without it, the test above is satisfied by an order that only ever takes one line. */
    #[Test]
    public function aDifferentKeyAddsASecondLine(): void
    {
        $draft = $this->draftWithAListedItem();

        $this->createLine($draft['order'], self::body($draft['item']), Uuid::v4()->toRfc4122());
        self::assertSame(201, $this->httpStatus());
        $this->createLine($draft['order'], self::body($draft['item']), Uuid::v4()->toRfc4122());
        self::assertSame(201, $this->httpStatus());

        self::assertSame(2, $this->lineCount($draft['order']));
        self::assertSame('12.0000', $this->totalOf($draft['order']));
    }

    /** D-B: the same key with a different body is §6.2's 409, and nothing is written. Each digested field in turn. */
    #[Test]
    public function aRetriedLineCreateWithTheSameKeyAndADifferentBodyIsAConflict(): void
    {
        $draft = $this->draftWithAListedItem();
        $otherItem = $this->seedItem(self::TENANT_A, 'SKU-OTHER');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $otherItem);
        $key = Uuid::v4()->toRfc4122();

        $this->createLine($draft['order'], self::body($draft['item']), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        foreach ([
            'inventory_item_id' => self::body($otherItem),
            'quantity' => self::body($draft['item'], quantity: '5'),
            'unit_cost' => self::body($draft['item'], unitCost: '4'),
        ] as $field => $different) {
            $this->createLine($draft['order'], $different, $key);

            self::assertSame(409, $this->httpStatus(), $field.': '.(string) $this->client()->getResponse()->getContent());
            self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type'], $field);
            self::assertSame(1, $this->lineCount($draft['order']), $field.': the conflicting request wrote a line.');
        }

        self::assertSame('6.0000', $this->totalOf($draft['order']));
    }

    /** A cosmetically different spelling of the same request is the same request (scale-normalised decimals, uuid case). */
    #[Test]
    public function aCosmeticallyDifferentSpellingOfTheSameLineIsAReplay(): void
    {
        $draft = $this->draftWithAListedItem();
        $key = Uuid::v4()->toRfc4122();

        $this->createLine($draft['order'], self::body($draft['item'], '2', '3'), $key);
        self::assertSame(201, $this->httpStatus());
        $first = $this->json()['id'];

        $this->createLine($draft['order'], self::body(strtoupper($draft['item']), '2.000000', '3.0000'), $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json()['id']);
        self::assertSame(1, $this->lineCount($draft['order']));
    }

    /**
     * **The path id is digested.** The index is tenant-wide, so without it the same key sent to another order would be
     * answered with the first order's line — a 201 for a line that is not on the order the caller addressed.
     *
     * *Mutation (c):* the digest omits the path id → red here (201, the other order's line).
     */
    #[Test]
    public function aLineCreateWithTheSameKeyOnAnotherOrderIsAConflict(): void
    {
        $first = $this->draftWithAListedItem('PO-1');
        $second = $this->seedOrder(self::TENANT_A, 'PO-2', $first['supplier'], $first['warehouse']);
        $key = Uuid::v4()->toRfc4122();

        $this->createLine($first['order'], self::body($first['item']), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->createLine($second, self::body($first['item']), $key);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertSame(1, $this->lineCount($first['order']));
        self::assertSame(0, $this->lineCount($second), 'The conflicting request wrote a line on the other order.');
    }

    /**
     * **The key lookup runs before the draft guard**, so a retry that arrives after the order was approved is handed the
     * line its first attempt committed, not refused for an act that already happened.
     */
    #[Test]
    public function aLineCreateRetryAfterApprovalReturnsTheLine(): void
    {
        $draft = $this->draftWithAListedItem();
        $key = Uuid::v4()->toRfc4122();

        $this->createLine($draft['order'], self::body($draft['item']), $key);
        self::assertSame(201, $this->httpStatus());
        $first = $this->json()['id'];

        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE purchase_order SET status = 'ordered', ordered_at = now() WHERE id = ?::uuid", [$draft['order']]);

        $this->createLine($draft['order'], self::body($draft['item']), $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json()['id']);
        self::assertSame(1, $this->lineCount($draft['order']));

        // And a NEW key on the placed order is still refused by the draft guard.
        $this->createLine($draft['order'], self::body($draft['item']), Uuid::v4()->toRfc4122());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.is_editable_only_while_draft', $this->json()['rule']);
    }

    /** API-14 as amended: a `unique:` cell demands the header. No key → 428, and nothing is written. */
    #[Test]
    public function aLineCreateWithoutAKeyIs428(): void
    {
        $draft = $this->draftWithAListedItem();

        $this->createLine($draft['order'], self::body($draft['item']), null);

        self::assertSame(428, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-required', $this->json()['type']);
        self::assertSame(0, $this->lineCount($draft['order']));
    }

    /** The line holds the header's hash and the request digest, never the raw key — D-B, as on `purchase_order`. */
    #[Test]
    public function theLineStoresTheKeysHashAndADigest(): void
    {
        $draft = $this->draftWithAListedItem();
        $key = Uuid::v4()->toRfc4122();

        $this->createLine($draft['order'], self::body($draft['item']), $key);
        self::assertSame(201, $this->httpStatus());

        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT idempotency_key, request_digest FROM purchase_order_line WHERE id = ?::uuid', [$this->json()['id']])[0];

        self::assertSame(hash('sha256', $key), $row['idempotency_key']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['request_digest']);
    }

    /**
     * **Nested lines carry NULL keys**: the order holds the key, and N lines sharing it would collide on the unique. This
     * is why the migration has no required-key trigger.
     */
    #[Test]
    public function aNestedCreateStillWritesItsLinesWithNullKeys(): void
    {
        $draft = $this->draftWithAListedItem();

        $this->send('POST', '/purchase-orders', [
            'supplier_id' => $draft['supplier'],
            'warehouse_id' => $draft['warehouse'],
            'lines' => [self::body($draft['item']), self::body($draft['item']), self::body($draft['item'])],
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame(
            [['keyed' => false, 'digested' => false], ['keyed' => false, 'digested' => false], ['keyed' => false, 'digested' => false]],
            $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT idempotency_key IS NOT NULL AS keyed, request_digest IS NOT NULL AS digested FROM purchase_order_line
                  WHERE purchase_order_id = ?::uuid ORDER BY line_number', [$this->json()['id']]),
        );
    }
}
