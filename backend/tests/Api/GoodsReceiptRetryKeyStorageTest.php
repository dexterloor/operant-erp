<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
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

        self::assertSame('23502', $this->sqlStateOf(fn () => $this->insertReceipt($order, 'GR-000001', null, hash('sha256', 'r'))));
        self::assertSame('23502', $this->sqlStateOf(fn () => $this->insertReceipt($order, 'GR-000001', hash('sha256', 'k'), null)));
    }

    /**
     * **A writer that forgot to hash is refused by Postgres, not trusted — and
     * the CHECK is `'^[0-9a-f]{64}$'`, every character of which is load-bearing.**
     *
     * Step 8-i ran **one** bad value per column: a UUID for the key, an
     * upper-case digest. That pair leaves two ways to weaken the constraint
     * without any test noticing. Dropping the `^…$` anchors leaves
     * `~ '[0-9a-f]{64}'`, which **accepts a 64-character hash with anything
     * stuck on either end** — a raw header value appended to a hash would store
     * and the column would no longer mean what the replay read assumes. And
     * writing it case-insensitively (`~*`, or `[0-9a-fA-F]`) accepts
     * `strtoupper($hash)`, which is a *different string* from the one
     * `IdempotencyKeyHash::of()` produces, so two spellings of one key would
     * both be stored and the unique would refuse neither against the other.
     *
     * So each column now runs the whole set, and the columns run the same set
     * rather than one each: a constraint written for one and forgotten for the
     * other is the shape this file exists to catch.
     */
    #[Test]
    #[DataProvider('valuesTheShapeCheckMustRefuse')]
    public function aKeyOrDigestThatIsNotLowerCaseHexSha256IsRefused(string $column, string $value): void
    {
        $order = $this->orderedOrder();
        $good = hash('sha256', 'good');

        $refused = $this->refusalOf(fn () => 'idempotency_key' === $column
            ? $this->insertReceipt($order, 'GR-000001', $value, $good)
            : $this->insertReceipt($order, 'GR-000001', $good, $value));

        // ⚠ **The constraint NAME, not the bare SQLSTATE** (CD-7, `RED` F1). Since `goods_receipt_number_shape`
        // also answers `23514`, a bare-state assertion would stay green on a receipt number of the wrong shape
        // whatever this column's CHECK said -- and both mutations the docblock names would stop reddening.
        self::assertStringStartsWith('23514', $refused);
        self::assertStringContainsString('goods_receipt_'.$column.'_sha256', $refused,
            'The write was refused, but not by the '.$column.' shape CHECK this case is about.');

        // The positive control, inside the same test: the same insert with a well-formed key and digest lands.
        // Without it, a fixture that every CHECK refuses (a wrong number, a missing order) reads as a pass.
        $this->insertReceipt($order, 'GR-000001', $good, hash('sha256', 'the digest'));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function valuesTheShapeCheckMustRefuse(): iterable
    {
        $hash = hash('sha256', 'k');

        $bad = [
            'not a hash at all' => Uuid::v4()->toRfc4122(),
            'upper case' => strtoupper($hash),
            'a hash with a character after it' => $hash.'x',
            'a hash with a character before it' => 'x'.$hash,
            'one character short' => substr($hash, 0, 63),
        ];

        foreach (['idempotency_key', 'request_digest'] as $column) {
            foreach ($bad as $what => $value) {
                yield $column.': '.$what => [$column, $value];
            }
        }
    }

    #[Test]
    public function theSameKeyTwiceInOneWorkspaceIsRefusedAndAnotherWorkspaceMayUseIt(): void
    {
        $key = hash('sha256', 'the client key');
        $orderA = $this->orderedOrder();

        $this->insertReceipt($orderA, 'GR-000001', $key, hash('sha256', 'r'));
        self::assertSame('23505', $this->sqlStateOf(fn () => $this->insertReceipt($orderA, 'GR-000002', $key, hash('sha256', 'r'))));

        $orderB = $this->orderedOrder(self::TENANT_B);
        $this->insertReceipt($orderB, 'GR-000001', $key, hash('sha256', 'r'), self::TENANT_B);

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM goods_receipt'));
    }

    /** @return array{order: string, warehouse: string} */
    private function orderedOrder(string $tenantId = self::TENANT_A): array
    {
        $supplier = $this->seedSupplier($tenantId, 'Acme');
        $warehouse = $this->seedWarehouse($tenantId);

        return [
            'order' => $this->seedOrder($tenantId, 'PO-000001', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED),
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

    /** The refused write's SQLSTATE and message, so a caller can name the constraint that refused it. */
    private function refusalOf(callable $write): string
    {
        try {
            $write();
        } catch (DriverException $e) {
            return $e->getSQLState().' '.$e->getMessage();
        }

        self::fail('The write was accepted.');
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
