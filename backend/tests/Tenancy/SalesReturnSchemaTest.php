<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Component\Uid\Uuid;

/**
 * **The return schema `CreateSalesReturn` will write into — sales step 7, commit R1** (`Version20261001000100`). No
 * operation exists yet, so every row here is written **as the owner**: the subject is the constraint, and constraints
 * bind every role (`attemptAsOwnerForTenant`'s docblock). `erp_app` and `erp_ai` hold only SELECT on both tables after
 * this commit; `GrantFootprintTest` holds that against the manifest, both directions.
 *
 * - `sales_return_number_shape`: `RET-` + exactly six digits (CD-7 / Q12 house style, `IA.md` D-f).
 * - `sales_return_deducts_only_before_invoice`: owner ruling Q-L (a′) — a deducting return names no invoice. All four
 *   quadrants, so a CHECK written the wrong way round (or dropped) is red.
 * - The header retry key: NOT NULL, 64 lower-case hex, and a TOTAL unique (class `none`, no `deleted_at`).
 * - The reason domain (design Q-I's proposal; `recorded_in_error` is OI-2 R3's).
 * - Every reference is composite `(tenant_id, …)`: another workspace's fulfilment, invoice, recorder, shipped line or
 *   bin is refused (23503).
 * - Lines: quantity strictly positive; one line per shipped line per return.
 */
final class SalesReturnSchemaTest extends TenancyTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function numbersTheShapeRefuses(): iterable
    {
        yield 'five digits' => ['RET-12345', '23514'];
        yield 'seven digits' => ['RET-1234567', '23514'];
        yield 'another document\'s prefix' => ['FUL-000001', '23514'];
        yield 'lower-case prefix' => ['ret-000001', '23514'];
        yield 'six digits (the control)' => ['RET-000001', 'accepted'];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('numbersTheShapeRefuses')]
    public function theNumberIsExactlyRetDashSixDigits(string $number, string $expected): void
    {
        $w = $this->world();

        self::assertSame($expected, $this->attemptReturn($w, ['sales_return_number' => $number]));
    }

    /**
     * **Q-L (a′): a return deducts only when no invoice existed when it was recorded.** The four quadrants of
     * `NOT deducts OR invoice_id IS NULL`; only "deducts AND names an invoice" is refused.
     *
     * @return iterable<string, array{bool, bool, string}>
     */
    public static function deductionQuadrants(): iterable
    {
        yield 'deducts, no invoice (the ordinary pre-invoice return)' => [true, false, 'accepted'];
        yield 'deducts, but names an invoice' => [true, true, '23514'];
        yield 'does not deduct, names an invoice (recorded after issue)' => [false, true, 'accepted'];
        yield 'does not deduct, no invoice (the shipper\'s own return, credited)' => [false, false, 'accepted'];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('deductionQuadrants')]
    public function aDeductingReturnNamesNoInvoice(bool $deducts, bool $withInvoice, string $expected): void
    {
        $w = $this->world(withInvoice: true);

        self::assertSame($expected, $this->attemptReturn($w, [
            'deducts' => $deducts ? 'true' : 'false',
            'invoice_id' => $withInvoice ? $w['invoice'] : null,
        ]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function reasons(): iterable
    {
        yield 'customer_returned' => ['customer_returned', 'accepted'];
        yield 'damaged_in_transit' => ['damaged_in_transit', 'accepted'];
        yield 'wrong_item_shipped' => ['wrong_item_shipped', 'accepted'];
        yield 'recorded_in_error (OI-2 R3)' => ['recorded_in_error', 'accepted'];
        yield 'a stock-movement reason is not a return reason' => ['return', '23514'];
        yield 'upper case' => ['CUSTOMER_RETURNED', '23514'];
        yield 'empty' => ['', '23514'];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('reasons')]
    public function theReasonIsOneOfTheDocumentsOwnValues(string $reason, string $expected): void
    {
        $w = $this->world();

        self::assertSame($expected, $this->attemptReturn($w, ['reason' => $reason]));
    }

    /**
     * **The header retry key, on `fulfilment`'s reasoning.** Both columns NOT NULL and 64 lower-case hex; the unique on
     * `(tenant_id, idempotency_key)` is TOTAL — and per workspace: another workspace may hold the same hash.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function theRetryKeyIsAHexDigestUniquePerWorkspace(): void
    {
        $w = $this->world();
        $b = $this->world(self::TENANT_B);
        $key = hash('sha256', 'one key');

        foreach (['idempotency_key', 'request_digest'] as $column) {
            self::assertSame('23502', $this->attemptReturn($w, [$column => null]), $column.' accepted NULL.');
            self::assertSame('23514', $this->attemptReturn($w, [$column => strtoupper($key)]), $column.' accepted upper-case hex.');
            self::assertSame('23514', $this->attemptReturn($w, [$column => substr($key, 1)]), $column.' accepted 63 characters.');
        }

        $this->insertReturn($w, ['idempotency_key' => $key, 'sales_return_number' => 'RET-000001']);
        self::assertSame('23505', $this->attemptReturn($w, ['idempotency_key' => $key, 'sales_return_number' => 'RET-000002']),
            'A second return took a key already held in this workspace.');
        self::assertSame('23505', $this->attemptReturn($w, ['sales_return_number' => 'RET-000001']),
            'A second return took a number already held in this workspace.');
        self::assertSame('accepted', $this->attemptReturn($b, ['idempotency_key' => $key, 'sales_return_number' => 'RET-000001']),
            'The control: another workspace may hold the same key hash and the same number.');
    }

    /** Every reference is composite: another workspace's row is refused (23503), and the NOT NULL ones refuse NULL. */
    #[\PHPUnit\Framework\Attributes\Test]
    public function everyReferenceIsThisWorkspaces(): void
    {
        $w = $this->world(withInvoice: true);
        $b = $this->world(self::TENANT_B, withInvoice: true);

        self::assertSame('23503', $this->attemptReturn($w, ['fulfilment_id' => $b['fulfilment']]));
        self::assertSame('23503', $this->attemptReturn($w, ['invoice_id' => $b['invoice'], 'deducts' => 'false']));
        self::assertSame('23503', $this->attemptReturn($w, ['recorded_by_user_id' => $b['user']]));
        self::assertSame('23502', $this->attemptReturn($w, ['fulfilment_id' => null]));
        self::assertSame('23502', $this->attemptReturn($w, ['recorded_by_user_id' => null]));
        self::assertSame('23502', $this->attemptReturn($w, ['deducts' => null]));
        self::assertSame('23502', $this->attemptReturn($w, ['returned_at' => null]));
        self::assertSame('accepted', $this->attemptReturn($w, ['invoice_id' => $w['invoice'], 'deducts' => 'false']),
            'The control: this workspace\'s invoice.');

        $return = $this->insertReturn($w, []);
        self::assertSame('23503', $this->attemptLine($w, $return, ['fulfilment_line_id' => $b['fulfilment_line']]));
        self::assertSame('23503', $this->attemptLine($w, $return, ['storage_location_id' => $b['bin']]));
        self::assertSame('23503', $this->attemptLine($w, $return, ['sales_return_id' => Uuid::v4()->toRfc4122()]));
        self::assertSame('accepted', $this->attemptLine($w, $return, []), 'The control: this workspace\'s shipped line and bin.');
    }

    /** A line's quantity is strictly positive at `numeric(19,6)`, and a return names each shipped line once. */
    #[\PHPUnit\Framework\Attributes\Test]
    public function aLineReturnsAPositiveQuantityOfAShippedLineOnce(): void
    {
        $w = $this->world();
        $return = $this->insertReturn($w, []);

        self::assertSame('23514', $this->attemptLine($w, $return, ['quantity' => '0']));
        self::assertSame('23514', $this->attemptLine($w, $return, ['quantity' => '-0.000001']));
        self::assertSame('accepted', $this->attemptLine($w, $return, ['quantity' => '0.000001']));

        $this->executeAsOwnerForTenant(self::TENANT_A, ...$this->lineStatement($w, $return, []));
        self::assertSame('23505', $this->attemptLine($w, $return, []),
            'A return named the same shipped line twice; the per-line bound reads one row per return.');
        self::assertSame('accepted', $this->attemptLine($w, $this->insertReturn($w, []), []),
            'The control: another return of the same shipment may name the same line.');
    }

    // -----------------------------------------------------------------------

    private static int $worlds = 420000;

    private static int $returns = 0;

    /**
     * A confirmed order with one line, shipped from one bin by `user`; with `$withInvoice`, a live invoice for it.
     *
     * @return array{tenant: string, user: string, fulfilment: string, fulfilment_line: string, bin: string, invoice: string|null}
     */
    private function world(string $tenantId = self::TENANT_A, bool $withInvoice = false): array
    {
        $tag = substr(Uuid::v4()->toRfc4122(), 0, 8);
        // CD-7: the document numbers are pinned to six digits, so a process-wide counter, never the random tag.
        $number = \sprintf('%06d', ++self::$worlds);
        $ids = ['user', 'customer', 'warehouse', 'bin', 'item', 'order', 'line', 'fulfilment', 'fulfilment_line', 'invoice'];
        $id = array_combine($ids, array_map(static fn (): string => Uuid::v4()->toRfc4122(), $ids));

        $statements = [
            ["INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', 'Return Fixture')",
                [$id['user'], $tenantId, 'return-'.$tag.'@example.test']],
            ["INSERT INTO customer (id, tenant_id, code, name, currency_code) VALUES (?::uuid, ?::uuid, ?, ?, 'EUR')",
                [$id['customer'], $tenantId, 'C-'.$tag, 'Customer '.$tag]],
            ["INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, ?, ?, 'DE')",
                [$id['warehouse'], $tenantId, 'W-'.$tag, 'Warehouse '.$tag]],
            ['INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [$id['bin'], $tenantId, $id['warehouse'], 'A-'.$tag]],
            ["INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code) VALUES (?::uuid, ?::uuid, ?, ?, 'EA', 'EUR')",
                [$id['item'], $tenantId, 'SKU-'.$tag, 'Item '.$tag]],
            [<<<'SQL'
                INSERT INTO sales_order (id, tenant_id, order_number, customer_id, warehouse_id, currency_code,
                                         ship_to_country_code, status, ordered_at, idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, 'EUR', 'GB', 'confirmed', now(),
                        encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                SQL, [$id['order'], $tenantId, 'SO-'.$number, $id['customer'], $id['warehouse']]],
            ["INSERT INTO sales_order_line (id, tenant_id, sales_order_id, inventory_item_id, line_number, quantity, unit_price) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 5, 2.5000)",
                [$id['line'], $tenantId, $id['order'], $id['item']]],
            [<<<'SQL'
                INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number, shipped_by_user_id,
                                        idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::uuid,
                        encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                SQL, [$id['fulfilment'], $tenantId, $id['order'], $id['warehouse'], 'FUL-'.$number, $id['user']]],
            ['INSERT INTO fulfilment_line (id, tenant_id, fulfilment_id, sales_order_line_id, storage_location_id, quantity) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, 5)',
                [$id['fulfilment_line'], $tenantId, $id['fulfilment'], $id['line'], $id['bin']]],
        ];
        if ($withInvoice) {
            $statements[] = [<<<'SQL'
                INSERT INTO invoice (id, tenant_id, sales_order_id, customer_id, fulfilment_id, issued_by_user_id,
                                     invoice_number, currency_code, total_amount, due_at)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, 'EUR', 12.5000, now() + interval '30 days')
                SQL, [$id['invoice'], $tenantId, $id['order'], $id['customer'], $id['fulfilment'], $id['user'], 'INV-'.$number]];
        }
        foreach ($statements as [$sql, $params]) {
            $this->executeAsOwnerForTenant($tenantId, $sql, $params);
        }

        return ['tenant' => $tenantId, 'user' => $id['user'], 'fulfilment' => $id['fulfilment'],
            'fulfilment_line' => $id['fulfilment_line'], 'bin' => $id['bin'], 'invoice' => $withInvoice ? $id['invoice'] : null];
    }

    /**
     * @param array{tenant: string, user: string, fulfilment: string, fulfilment_line: string, bin: string, invoice: string|null} $w
     * @param array<string, string|null>                                                                                           $override
     *
     * @return array{string, list<string|null>}
     */
    private function returnStatement(array $w, array $override): array
    {
        $columns = array_merge([
            'id' => Uuid::v4()->toRfc4122(),
            'tenant_id' => $w['tenant'],
            'fulfilment_id' => $w['fulfilment'],
            'invoice_id' => null,
            'sales_return_number' => \sprintf('RET-%06d', ++self::$returns),
            'reason' => 'customer_returned',
            'returned_at' => '2026-10-01T09:00:00Z',
            'recorded_by_user_id' => $w['user'],
            'deducts' => 'true',
            'idempotency_key' => hash('sha256', Uuid::v4()->toRfc4122()),
            'request_digest' => hash('sha256', Uuid::v4()->toRfc4122()),
        ], $override);
        $casts = ['id' => '::uuid', 'tenant_id' => '::uuid', 'fulfilment_id' => '::uuid', 'invoice_id' => '::uuid',
            'returned_at' => '::timestamptz', 'recorded_by_user_id' => '::uuid', 'deducts' => '::boolean'];

        return [
            \sprintf('INSERT INTO sales_return (%s) VALUES (%s)', implode(', ', array_keys($columns)),
                implode(', ', array_map(static fn (string $c): string => '?'.($casts[$c] ?? '::text'), array_keys($columns)))),
            array_values($columns),
        ];
    }

    /**
     * @param array{tenant: string, user: string, fulfilment: string, fulfilment_line: string, bin: string, invoice: string|null} $w
     * @param array<string, string|null>                                                                                           $override
     *
     * @return array{string, list<string|null>}
     */
    private function lineStatement(array $w, string $returnId, array $override): array
    {
        $columns = array_merge([
            'id' => Uuid::v4()->toRfc4122(),
            'tenant_id' => $w['tenant'],
            'sales_return_id' => $returnId,
            'fulfilment_line_id' => $w['fulfilment_line'],
            'storage_location_id' => $w['bin'],
            'quantity' => '1',
        ], $override);
        $casts = ['quantity' => '::numeric'];

        return [
            \sprintf('INSERT INTO sales_return_line (%s) VALUES (%s)', implode(', ', array_keys($columns)),
                implode(', ', array_map(static fn (string $c): string => '?'.($casts[$c] ?? '::uuid'), array_keys($columns)))),
            array_values($columns),
        ];
    }

    /**
     * @param array{tenant: string, user: string, fulfilment: string, fulfilment_line: string, bin: string, invoice: string|null} $w
     * @param array<string, string|null>                                                                                           $override
     */
    private function attemptReturn(array $w, array $override): string
    {
        return $this->attemptAsOwnerForTenant($w['tenant'], ...$this->returnStatement($w, $override));
    }

    /**
     * @param array{tenant: string, user: string, fulfilment: string, fulfilment_line: string, bin: string, invoice: string|null} $w
     * @param array<string, string|null>                                                                                           $override
     */
    private function insertReturn(array $w, array $override): string
    {
        [$sql, $params] = $this->returnStatement($w, $override);
        $this->executeAsOwnerForTenant($w['tenant'], $sql, $params);

        return (string) $params[0];
    }

    /**
     * @param array{tenant: string, user: string, fulfilment: string, fulfilment_line: string, bin: string, invoice: string|null} $w
     * @param array<string, string|null>                                                                                           $override
     */
    private function attemptLine(array $w, string $returnId, array $override): string
    {
        return $this->attemptAsOwnerForTenant($w['tenant'], ...$this->lineStatement($w, $returnId, $override));
    }
}
