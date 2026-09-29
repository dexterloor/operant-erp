<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\AuditLog\Infrastructure\DenialAuditWriter;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Security\PermissionGate;
use App\Platform\Tenancy\SchemaManifest;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **F-1 — a denial row's subject pair must name ONE row** (shipped defect, `docs/session13/roles-s2-sec.md` §4.1, rule
 * as restated by `roles-s2-spar.md` P16; inserted under the standing rule of 2026-09-13, owner notified 2026-09-29).
 *
 * The rule, restated: **`subject_type` is the type of the resource the recorded path variable addresses; the id stays
 * unresolved (E-1); where no path variable addresses a resource of a known table, neither half is written.**
 *
 * Until this fix `PermissionGate::subjectIdIn()` recorded the FIRST uuid route parameter — the PARENT on a nested path —
 * while `DenialAuditWriter` took `subject_type` from the permission code's resource half. On a nested create or read
 * whose parent is a different resource the two halves described different rows: `(goods_receipt, <purchase order id>)`.
 *
 * **The expectation is derived, never typed** (the empty-set trap and the second-copy trap both apply): the resource a
 * path variable addresses is the table of the built item-`GET` operation whose URI template is the path up to and
 * including that variable, read off the registry — `/purchase-orders/{}` is `GetPurchaseOrder`, which requires
 * `purchase_order.view`, so the variable addresses `purchase_order`. The per-operation declaration the fix adds
 * (`PermissionGate::OPERATION_DENIAL_SUBJECT`) is NOT read here, so the declaration and this test are two sources.
 */
final class DenialSubjectTest extends PurchaseOrderTestCase
{
    /**
     * The four shipped operations the spar derived from the live router as mislabelled (`roles-s2-spar.md` P15) — a
     * pin on the domain, so a derivation that silently loses one is red rather than green over less.
     */
    private const NESTED_OPERATIONS_OF_ANOTHER_RESOURCE = ['CreateFulfilment', 'CreateGoodsReceipt', 'GetPickList', 'IssueInvoice'];

    /** The flat positive control: `/items/{id}` addresses `inventory_item`, and so does `inventory_item.view`. */
    private const FLAT_CONTROL = 'GetItem';

    /**
     * Path prefixes that address a known table although no item-`GET` is built at them. Empty today. `RevokeRole` /
     * `AssignRole` (roles slice 2, C2/C3) add `'/users/{}' => 'app_user'` here: `GetUser` is unbuilt (spar P16).
     *
     * @var array<string, string>
     */
    private const ADDRESSED_WITHOUT_A_GET_OPERATION = [];

    #[Test]
    public function everyDenialNamesTheTypeOfTheResourceItsRecordedPathVariableAddresses(): void
    {
        $addressed = self::tablesAddressedByPrefix();
        self::assertSame('inventory_item', $addressed['/items/{}'] ?? null,
            'Control: the derivation does not map /items/{} to inventory_item, so every comparison below is against nothing.');

        $violations = [];
        $drove = [];
        $pairs = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $required = $operation->getExtraProperties()[PermissionGate::OPERATION_PERMISSION] ?? null;
            $template = self::templateOf($operation);

            if (!\is_string($required) || 0 === preg_match_all('/\{([^}]+)\}/', $template, $variables)) {
                continue;
            }

            // A fresh principal holding nothing, so E-3a's per-(actor, permission) cap cannot hide this row.
            $this->actAs(self::TENANT_A, []);
            $values = [];
            foreach ($variables[1] as $variable) {
                $values[$variable] = Uuid::v4()->toRfc4122();
            }
            $path = (string) preg_replace_callback('/\{([^}]+)\}/', static fn (array $m): string => $values[$m[1]], $template);

            $this->send((string) $operation->getMethod(), $path, \in_array($operation->getMethod(), ['POST', 'PATCH', 'PUT'], true) ? [] : null);
            self::assertSame(403, $this->httpStatus(), $name.' did not refuse a caller who holds nothing.');
            $drove[] = (string) $name;

            $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT subject_type, subject_id::text AS subject_id FROM audit_log WHERE actor_user_id = ?::uuid AND event_type = ?',
                [$this->actorId, AuditEvent::AUTHZ_PERMISSION_DENIED]);
            self::assertCount(1, $rows, $name.' did not write exactly one denial row.');
            [$type, $id] = [$rows[0]['subject_type'], $rows[0]['subject_id']];
            $pairs[(string) $name] = [$type, $id];

            // The variables that address a known table, in path order.
            $addressing = [];
            foreach ($variables[1] as $variable) {
                $prefix = self::normalised(substr($template, 0, (int) strpos($template, '{'.$variable.'}') + \strlen($variable) + 2));
                $table = $addressed[$prefix] ?? null;
                if (null !== $table) {
                    $addressing[$variable] = $table;
                }
            }

            if (null === $id) {
                if (null !== $type || [] !== $addressing) {
                    $violations[] = \sprintf('%s wrote (%s, NULL) although %s addresses a known table', $name, $type ?? 'NULL', implode(', ', array_keys($addressing)));
                }
                continue;
            }

            $variable = array_search($id, $values, true);
            if (false === $variable) {
                $violations[] = \sprintf('%s recorded an id that is none of its path variables: %s', $name, $id);
                continue;
            }
            if (($addressing[$variable] ?? null) !== $type) {
                $violations[] = \sprintf('%s wrote (%s, {%s}) but {%s} addresses %s', $name, $type ?? 'NULL', $variable, $variable, $addressing[$variable] ?? 'no known table');
            }
        }

        foreach ([...self::NESTED_OPERATIONS_OF_ANOTHER_RESOURCE, self::FLAT_CONTROL] as $mustBeDriven) {
            self::assertContains($mustBeDriven, $drove, 'The domain lost '.$mustBeDriven.', so this test no longer covers it.');
        }
        self::assertSame('inventory_item', $pairs[self::FLAT_CONTROL][0] ?? null,
            'Positive control: the flat /items/{id} denial did not record inventory_item, so a "no violation" below proves nothing.');

        self::assertSame([], $violations,
            "A denial row's subject pair names two different rows (F-1):\n  ".implode("\n  ", $violations));
    }

    /**
     * **The id half, with real rows** — `SEC`'s `EXISTS` test. The gate never resolves the id (E-1), so existence is a
     * property of the test's fixture, not of the gate: each of the four nested operations is denied against a REAL
     * parent row, and the recorded pair must name that row — `EXISTS (SELECT 1 FROM <subject_type> WHERE id = subject_id)`.
     * The flat control (`GetItem` on a real item) is the same assertion on a path that was never wrong. The 403 body is
     * asserted too: F-1 changes which pair the trail records and nothing a caller sees.
     */
    #[Test]
    public function eachNestedDenialNamesARealRowOfItsRecordedType(): void
    {
        $po = $this->seedOrder(self::TENANT_A, 'PO-171000', $this->seedSupplier(self::TENANT_A, 'Acme F1'),
            $this->seedWarehouse(self::TENANT_A, 'WH F1'), status: PurchaseOrder::STATUS_ORDERED);
        $so = $this->seedSalesOrder(self::TENANT_A, 'SO-171000', status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z');
        $item = $this->seedItem(self::TENANT_A, 'SKU-F1');
        $fulfilment = $this->aRealFulfilment();

        $cases = [
            'CreateGoodsReceipt' => ['POST', '/purchase-orders/'.$po.'/goods-receipts', 'goods_receipt.create', $po],
            'GetPickList' => ['GET', '/sales-orders/'.$so.'/pick-list', 'fulfilment.view', $so],
            'CreateFulfilment' => ['POST', '/sales-orders/'.$so.'/fulfilments', 'fulfilment.create', $so],
            'IssueInvoice' => ['POST', '/fulfilments/'.$fulfilment.'/invoices', 'invoice.issue', $fulfilment],
            self::FLAT_CONTROL => ['GET', '/items/'.$item, 'inventory_item.view', $item],
        ];

        foreach ($cases as $name => [$method, $path, $permission, $realId]) {
            $this->actAs(self::TENANT_A, []);
            $this->send($method, $path, 'GET' === $method ? null : []);

            self::assertSame(403, $this->httpStatus(), $name);
            // The wire, byte for byte, as captured BEFORE the fix (progress note, F-1): the fix changes which pair
            // the trail records and nothing a caller sees.
            self::assertSame(
                \sprintf('{"required_permission":"%s","type":"urn:erp:problem:permission-denied","title":"Permission denied","status":403}', $permission),
                (string) $this->client()->getResponse()->getContent(),
                $name.'\'s 403 body is not the one it answered before F-1.');

            $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT subject_type, subject_id::text AS subject_id FROM audit_log WHERE actor_user_id = ?::uuid AND event_type = ?',
                [$this->actorId, AuditEvent::AUTHZ_PERMISSION_DENIED]);
            self::assertCount(1, $rows, $name);
            self::assertSame($realId, $rows[0]['subject_id'], $name.' recorded an id that is not its path\'s.');
            $type = (string) $rows[0]['subject_type'];
            self::assertArrayHasKey($type, SchemaManifest::TABLES, $name);
            self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
                \sprintf('SELECT count(*) FROM %s WHERE id = ?::uuid', $type), [$realId]),
                \sprintf('%s wrote (%s, %s), and no %s row has that id: the pair names two different rows (F-1).', $name, $type, $realId, $type));
        }
    }

    /**
     * Every declared `OPERATION_DENIAL_SUBJECT` is `[<SchemaManifest table>, <one of the operation's own path
     * variables>]` — a declaration naming a table that does not exist, or a variable the path does not have, would
     * write a NULL id and a pair that says nothing. Anti-vacuity: the four are declared.
     */
    #[Test]
    public function everyDeclaredDenialSubjectNamesATableAndOneOfItsOwnPathVariables(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $extra = $operation->getExtraProperties();
            if (!\array_key_exists(PermissionGate::OPERATION_DENIAL_SUBJECT, $extra)) {
                continue;
            }
            $declared[] = (string) $name;
            [$table, $variable] = $extra[PermissionGate::OPERATION_DENIAL_SUBJECT];
            self::assertArrayHasKey($table, SchemaManifest::TABLES, $name.' declares a denial subject naming no table.');
            self::assertStringContainsString('{'.$variable.'}', self::templateOf($operation),
                $name.' declares a denial subject variable its path does not have.');
        }
        sort($declared);

        self::assertSame(self::NESTED_OPERATIONS_OF_ANOTHER_RESOURCE, $declared,
            'The operations declaring a denial subject are not exactly the four nested operations F-1 found.');
    }

    /**
     * `DenialAuditWriter::record()`'s optional subject type is CHECKED: a name outside `SchemaManifest::TABLES` is a
     * wiring error and refused loudly, never written; a table name is written as given, overriding the permission's
     * prefix (here `goods_receipt.create` would otherwise say `goods_receipt`).
     */
    #[Test]
    public function theWriterWritesADeclaredSubjectTypeAndRefusesOneThatIsNoTable(): void
    {
        $writer = self::getContainer()->get(DenialAuditWriter::class);
        \assert($writer instanceof DenialAuditWriter);
        $order = Uuid::v4()->toRfc4122();

        $writer->record(self::TENANT_A, (string) $this->actorId, 'Fixture Actor', 'goods_receipt.create', $order, null, 'purchase_order');
        self::assertSame([['subject_type' => 'purchase_order', 'subject_id' => $order]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT subject_type, subject_id::text AS subject_id FROM audit_log WHERE event_type = ?', [AuditEvent::AUTHZ_PERMISSION_DENIED]));

        try {
            $writer->record(self::TENANT_A, (string) $this->actorId, 'Fixture Actor', 'goods_receipt.view', $order, null, 'purchase_orders');
            self::fail('A subject type naming no table was accepted.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('purchase_orders', $e->getMessage());
        }
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log'),
            'The refused subject type still wrote a row.');
    }

    /** A fulfilment made through the API as a caller holding every code, so the id is a real `fulfilment` row. */
    private function aRealFulfilment(): string
    {
        $this->actAs(self::TENANT_A);
        $customer = $this->seedCustomer(self::TENANT_A, 'Customer F1');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH F1-S');
        $item = $this->seedItem(self::TENANT_A, 'SKU-F1-S');
        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
            [$bin, self::TENANT_A, $warehouse, 'A-01']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 10)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin]);

        $this->send('POST', '/sales-orders', [
            'customer_id' => $customer, 'warehouse_id' => $warehouse, 'currency_code' => 'EUR', 'ship_to_address_id' => $address,
            'lines' => [['inventory_item_id' => $item, 'quantity' => '2', 'unit_price' => '1']],
        ], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];
        $this->send('POST', '/sales-orders/'.$order.'/confirmation');
        self::assertSame(200, $this->httpStatus());
        $this->send('GET', '/sales-orders/'.$order.'/lines');
        $line = $this->json()['items'][0]['id'];
        $this->send('POST', '/sales-orders/'.$order.'/fulfilments',
            ['lines' => [['sales_order_line_id' => $line, 'quantity' => '2', 'storage_location_id' => $bin]]],
            ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return (string) $this->json()['id'];
    }

    /**
     * Normalised path prefix (`/purchase-orders/{}`) => the table its item-`GET` requires the `.view` of. Derived from
     * the registry: every built `GET` whose template ends in a variable, its table the resource half of its permission
     * code when that names a `SchemaManifest` table.
     *
     * @return array<string, string>
     */
    private static function tablesAddressedByPrefix(): array
    {
        $addressed = self::ADDRESSED_WITHOUT_A_GET_OPERATION;

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $template = self::templateOf($operation);
            $required = $operation->getExtraProperties()[PermissionGate::OPERATION_PERMISSION] ?? null;

            if ('GET' !== $operation->getMethod() || !str_ends_with($template, '}') || !\is_string($required)) {
                continue;
            }

            $table = strstr($required, '.', true);
            if (!\is_string($table) || !\array_key_exists($table, SchemaManifest::TABLES)) {
                continue;
            }

            $prefix = self::normalised($template);
            self::assertTrue(!isset($addressed[$prefix]) || $addressed[$prefix] === $table,
                \sprintf('Two item GETs at %s disagree on the table (%s vs %s, at %s).', $prefix, $addressed[$prefix] ?? '', $table, $name));
            $addressed[$prefix] = $table;
        }

        return $addressed;
    }

    private static function templateOf(HttpOperation $operation): string
    {
        $path = (string) $operation->getUriTemplate();

        return str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path;
    }

    private static function normalised(string $template): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $template);
    }
}
