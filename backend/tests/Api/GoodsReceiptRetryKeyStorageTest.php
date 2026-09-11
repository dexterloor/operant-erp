<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Step 8-i — `goods_receipt`'s retry key, as the database enforces it.**
 *
 * `CreateGoodsReceipt` was on E9-2's blocked list because a retried timeout
 * posted a second receipt and a second set of stock movements, and nothing
 * refused it: the table's only non-surrogate unique is on the server-minted
 * `receipt_number`. `Version20260911000400` gives it the domain-column key
 * `purchase_order` got at step 5, in the shape D-B left it in at 6b: the
 * header's hex SHA-256, beside the request digest a same-key replay is
 * compared against.
 *
 * `IdempotencyContractTest` proves the index exists and is keyed on what the
 * client sends. This file proves the three refusals as statements: a raw key
 * is refused, a receipt without a key is refused, and the same key twice in one
 * workspace is refused while another workspace may use it.
 */
final class GoodsReceiptRetryKeyStorageTest extends PurchaseOrderTestCase
{
    /**
     * **NOT NULL, not nullable-plus-trigger, and that is the one place this
     * departs from `purchase_order`'s shape.** `purchase_order.idempotency_key`
     * is nullable because rows existed before it. No code has ever written a
     * `goods_receipt` row, so there is no history for a NULL to describe.
     */
    #[Test]
    public function aReceiptWithoutAKeyIsRefused(): void
    {
        $order = $this->orderedOrder();

        self::assertSame('23502', $this->sqlStateOf(fn () => $this->insertReceipt($order, 'GR-1', null, hash('sha256', 'r'))));
        self::assertSame('23502', $this->sqlStateOf(fn () => $this->insertReceipt($order, 'GR-1', hash('sha256', 'k'), null)));
    }

    /** A writer that forgot to hash is refused by Postgres, not trusted. */
    #[Test]
    public function aKeyOrDigestThatIsNotLowerCaseHexSha256IsRefused(): void
    {
        $order = $this->orderedOrder();

        self::assertSame('23514', $this->sqlStateOf(fn () => $this->insertReceipt($order, 'GR-1', Uuid::v4()->toRfc4122(), hash('sha256', 'r'))));
        self::assertSame('23514', $this->sqlStateOf(fn () => $this->insertReceipt($order, 'GR-1', hash('sha256', 'k'), strtoupper(hash('sha256', 'r')))));
    }

    #[Test]
    public function theSameKeyTwiceInOneWorkspaceIsRefusedAndAnotherWorkspaceMayUseIt(): void
    {
        $key = hash('sha256', 'the client key');
        $orderA = $this->orderedOrder();

        $this->insertReceipt($orderA, 'GR-1', $key, hash('sha256', 'r'));
        self::assertSame('23505', $this->sqlStateOf(fn () => $this->insertReceipt($orderA, 'GR-2', $key, hash('sha256', 'r'))));

        $orderB = $this->orderedOrder(self::TENANT_B);
        $this->insertReceipt($orderB, 'GR-1', $key, hash('sha256', 'r'), self::TENANT_B);

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM goods_receipt'));
    }

    /** @return array{order: string, warehouse: string} */
    private function orderedOrder(string $tenantId = self::TENANT_A): array
    {
        $supplier = $this->seedSupplier($tenantId, 'Acme');
        $warehouse = $this->seedWarehouse($tenantId);

        return [
            'order' => $this->seedOrder($tenantId, 'PO-1', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED),
            'warehouse' => $warehouse,
        ];
    }

    /** @param array{order: string, warehouse: string} $order */
    private function insertReceipt(array $order, string $number, ?string $key, ?string $digest, string $tenantId = self::TENANT_A): void
    {
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO goods_receipt (id, tenant_id, purchase_order_id, warehouse_id, receipt_number, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?, ?)
            SQL, [Uuid::v4()->toRfc4122(), $tenantId, $order['order'], $order['warehouse'], $number, $key, $digest]);
    }

    private function sqlStateOf(callable $write): ?string
    {
        try {
            $write();
        } catch (DriverException $e) {
            return $e->getSQLState();
        }

        self::fail('The write was accepted.');
    }
}
