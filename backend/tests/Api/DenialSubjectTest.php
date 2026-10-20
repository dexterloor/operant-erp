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
 * including that variable, read off the registry — **path first** (sales step 7 C1, 2026-10-01; `roles-s3-red-3.md`
 * H-1): the GET's last literal segment, kebab → snake, singular (`/credit-notes/{}` → `credit_note`), when that names a
 * `SchemaManifest` table; otherwise the resource half of the GET's permission code (`/items/{}` → `inventory_item.view`
 * → `inventory_item`). *(Until then it was the permission prefix alone, which would have certified `GetCreditNote` —
 * `/credit-notes/{id}` under `invoice.view` — as `('invoice', <credit-note id>)`: the same mislabel F-1 fixed, re-made by
 * the test meant to catch it.)* Wherever the path's table and the permission's differ, the prefix must be named in
 * {@see self::PATH_TABLE_IS_NOT_THE_PERMISSIONS}, with its reason — so a differing GET cannot arrive unreviewed. The per-operation declaration the fix adds
 * (`PermissionGate::OPERATION_DENIAL_SUBJECT`) is NOT read here, so the declaration and this test are two sources.
 */
final class DenialSubjectTest extends PurchaseOrderTestCase
{
    /**
     * The four shipped operations the spar derived from the live router as mislabelled (`roles-s2-spar.md` P15) — a
     * pin on the domain, so a derivation that silently loses one is red rather than green over less.
     */
    // Sales step 7 C1: `IssueCreditNote` (nested under the invoice) and `GetCreditNote` (a credit note read under
    // `invoice.view` — the case the path-first derivation exists for).
    // Sales step 7 C1b: `IssueAdjustmentCreditNote`, nested under the invoice exactly as `IssueCreditNote` is.
    // Suppliers slice 1 C3 (design §5.5, RED L-5): the three catalogue operations — gated `supplier_product.*`, their
    // recorded `{id}` a `supplier` row.
    // Warehouses slice 1 C1: `ListStorageLocations` — gated `storage_location.view`, its recorded `{id}` a `warehouse` row.
    // Warehouses slice 1 C3: `CreateStorageLocation`, `UpdateStorageLocation` — gated `storage_location.*`, the same subject.
    // Warehouse/location group C2: `RestoreStorageLocation` — gated `storage_location.delete`, the same subject.
    // Warehouse/location group C3: `DeleteStorageLocation` — gated `storage_location.delete`, the same subject.
    private const NESTED_OPERATIONS_OF_ANOTHER_RESOURCE = ['AssignRole', 'CancelRoleGrantProposal', 'ConfirmRoleGrantProposal', 'CreateAdjustmentDecision', 'CreateFulfilment', 'CreateGoodsReceipt', 'CreateRoleGrantProposal', 'CreateSalesReturn', 'CreateStorageLocation', 'CreateSupplierProduct', 'DeleteStorageLocation', 'DeleteSupplierProduct', 'GetCreditNote', 'GetPickList', 'GetRoleGrantProposal', 'IssueAdjustmentCreditNote', 'IssueCreditNote', 'IssueInvoice', 'ListOfferedRoles', 'ListStorageLocations', 'ListSupplierProducts', 'RejectRoleGrantProposal', 'RestoreStorageLocation', 'RevokeRole', 'UpdateStorageLocation', 'UpdateSupplierProduct'];

    /** The flat positive control: `/items/{id}` addresses `inventory_item`, and so does `inventory_item.view`. */
    private const FLAT_CONTROL = 'GetItem';

    /**
     * Path prefixes that address a known table although no item-`GET` is built at them. **Empty since users slice 1 C1
     * (2026-10-08):** `'/users/{}' => 'app_user'` stood here from roles slice 2, C2 (`RevokeRole`) while `GetUser` was
     * unbuilt (spar P16; `SEC` Acknowledgement B.3 item 2), and `GetUser`'s commit removed it. The removal is now a
     * mechanism, not a hand-carried note (B.3 item 5): {@see self::tablesAddressedByPrefix()} refuses an entry whose prefix
     * has a built item-`GET` (design `users-s1-design.md` §7; mutation 15 re-adds the entry → red).
     *
     * @var array<string, string>
     */
    private const ADDRESSED_WITHOUT_A_GET_OPERATION = [];

    /**
     * Item-GET prefixes whose path names a table OTHER than their permission code's resource half — reviewed, each with
     * its reason. The derivation follows the path; this register only forces the review (a GET added here without one
     * is red in {@see self::tablesAddressedByPrefix()}).
     *
     * @var array<string, string> normalised prefix => why the read is gated on another resource's code
     */
    private const PATH_TABLE_IS_NOT_THE_PERMISSIONS = [
        '/credit-notes/{}' => 'A credit note is money: read under invoice.view (owner ruling Q-B, sales step 7 C1), never a code of its own.',
        '/role-grant-proposals/{}' => 'A pending grant is privilege history: read under role.assign (SECURITY_MODEL.md §5.2.3, never role.view, which viewer holds), never a code of its own (roles slice 3 C1; design F-4).',
    ];

    /**
     * Prefixes whose last segment names a table that is NOT the one the variable addresses — a path that genuinely breaks
     * the rule. None today.
     *
     * @var array<string, string>
     */
    private const PATH_SEGMENT_OVERRIDES = [];

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
        // Roles slice 2, C2 (`SEC` Acknowledgement B.3 item 3): a REAL subject user holding a real grant. `{roleId}` is not
        // the recorded variable — the row whose privileges change is the user.
        $user = $this->seedPrincipal(self::TENANT_A, ['role.view']);
        $grantedRole = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$user])[0]['r'];
        // Suppliers slice 1 C3: a REAL supplier and a real entry of its catalogue. `{productId}` is not the recorded variable
        // — the declared subject is the supplier (`OPERATION_DENIAL_SUBJECT`).
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme F1 Catalogue');
        $product = $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-F1-CAT'));
        // Warehouses slice 1 C1: a REAL warehouse (the location list's recorded `{id}`).
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH F1 Locations');
        // Customers slice 2 C2: a REAL customer and a real contact of it. `{contactId}` is not the recorded variable — the
        // path-first derivation names the customer, which the permission's resource half `customer` agrees with.
        $contactCustomer = $this->seedCustomer(self::TENANT_A, 'Customer F1 Contacts');
        $contact = $this->seedCustomerContact(self::TENANT_A, $contactCustomer);

        $cases = [
            'CreateGoodsReceipt' => ['POST', '/purchase-orders/'.$po.'/goods-receipts', 'goods_receipt.create', $po],
            'GetPickList' => ['GET', '/sales-orders/'.$so.'/pick-list', 'fulfilment.view', $so],
            'CreateFulfilment' => ['POST', '/sales-orders/'.$so.'/fulfilments', 'fulfilment.create', $so],
            'IssueInvoice' => ['POST', '/fulfilments/'.$fulfilment.'/invoices', 'invoice.issue', $fulfilment],
            // Sales step 7 R2: a return hangs off the shipment it returns, so a denial names the fulfilment.
            'CreateSalesReturn' => ['POST', '/fulfilments/'.$fulfilment.'/sales-returns', 'sales_return.create', $fulfilment],
            'RevokeRole' => ['DELETE', '/users/'.$user.'/roles/'.$grantedRole, 'role.assign', $user],
            // Roles slice 2, C3 (B.3 items 1 and 3): the gate refuses before the body or the Idempotency-Key is read
            // (PermissionGate::PRIORITY: 401 → 403 → 428), and the row names the user.
            'AssignRole' => ['POST', '/users/'.$user.'/roles', 'role.assign', $user],
            'ListSupplierProducts' => ['GET', '/suppliers/'.$supplier.'/products', 'supplier_product.view', $supplier],
            'CreateSupplierProduct' => ['POST', '/suppliers/'.$supplier.'/products', 'supplier_product.edit', $supplier],
            'UpdateSupplierProduct' => ['PATCH', '/suppliers/'.$supplier.'/products/'.$product, 'supplier_product.edit', $supplier],
            'DeleteSupplierProduct' => ['DELETE', '/suppliers/'.$supplier.'/products/'.$product, 'supplier_product.edit', $supplier],
            'DeleteCustomerContact' => ['DELETE', '/customers/'.$contactCustomer.'/contacts/'.$contact, 'customer.edit', $contactCustomer],
            // Customers slice 2 C3: a real address of the same customer; the denial names the customer.
            'DeleteCustomerAddress' => ['DELETE', '/customers/'.$contactCustomer.'/addresses/'.$this->seedCustomerAddress(self::TENANT_A, $contactCustomer),
                'customer.edit', $contactCustomer],
            // Warehouses slice 1 C1: the location list names the warehouse its path addresses.
            'ListStorageLocations' => ['GET', '/warehouses/'.$warehouse.'/locations', 'storage_location.view', $warehouse],
            // Warehouses slice 1 C3: the two location writes name the warehouse too; `{locationId}` is not the recorded variable.
            'CreateStorageLocation' => ['POST', '/warehouses/'.$warehouse.'/locations', 'storage_location.create', $warehouse],
            'UpdateStorageLocation' => ['PATCH', '/warehouses/'.$warehouse.'/locations/'.$this->seedStorageLocation(self::TENANT_A, $warehouse, 'F1-01'),
                'storage_location.edit', $warehouse],
            // Warehouse/location group C2: the location restore names the warehouse as well.
            'RestoreStorageLocation' => ['POST', '/warehouses/'.$warehouse.'/locations/'.$this->seedStorageLocation(self::TENANT_A, $warehouse, 'F1-02').'/restoration',
                'storage_location.delete', $warehouse],
            // Warehouse/location group C3: the location delete names the warehouse as well.
            'DeleteStorageLocation' => ['DELETE', '/warehouses/'.$warehouse.'/locations/'.$this->seedStorageLocation(self::TENANT_A, $warehouse, 'F1-03'),
                'storage_location.delete', $warehouse],
            self::FLAT_CONTROL => ['GET', '/items/'.$item, 'inventory_item.view', $item],
        ];

        foreach ($cases as $name => [$method, $path, $permission, $realId]) {
            $this->actAs(self::TENANT_A, []);
            $this->send($method, $path, \in_array($method, ['GET', 'DELETE'], true) ? null : []);

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
            'The operations declaring a denial subject are not exactly the registered nested operations of another resource.');
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

        $unreviewed = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $template = self::templateOf($operation);
            $required = $operation->getExtraProperties()[PermissionGate::OPERATION_PERMISSION] ?? null;

            if ('GET' !== $operation->getMethod() || !str_ends_with($template, '}') || !\is_string($required)) {
                continue;
            }

            $prefix = self::normalised($template);
            $table = self::PATH_SEGMENT_OVERRIDES[$prefix] ?? self::addressedTable($template, $required, array_keys(SchemaManifest::TABLES));
            if (null === $table) {
                continue;
            }
            $permissionTable = (string) strstr($required, '.', true);
            if ($table !== $permissionTable && !isset(self::PATH_TABLE_IS_NOT_THE_PERMISSIONS[$prefix])) {
                $unreviewed[] = \sprintf('%s (%s): the path addresses %s, its code is %s', $name, $prefix, $table, $required);
            }

            self::assertTrue(!isset($addressed[$prefix]) || $addressed[$prefix] === $table,
                \sprintf('Two item GETs at %s disagree on the table (%s vs %s, at %s).', $prefix, $addressed[$prefix] ?? '', $table, $name));
            $addressed[$prefix] = $table;
        }

        foreach (self::ADDRESSED_WITHOUT_A_GET_OPERATION as $prefix => $table) {
            self::assertFalse(self::hasBuiltItemGet($prefix),
                \sprintf('ADDRESSED_WITHOUT_A_GET_OPERATION names %s, which now has a built item GET: remove the entry (users slice 1 C1).', $prefix));
        }

        self::assertSame([], $unreviewed, "An item GET's path names a table other than its permission code's, and nobody reviewed it. "
            ."Name it in PATH_TABLE_IS_NOT_THE_PERMISSIONS with the reason, or fix the declaration:\n  ".implode("\n  ", $unreviewed));
        foreach (array_keys(self::PATH_TABLE_IS_NOT_THE_PERMISSIONS) as $prefix) {
            self::assertArrayHasKey($prefix, $addressed, 'PATH_TABLE_IS_NOT_THE_PERMISSIONS names '.$prefix.', which no built GET serves: a dead entry.');
        }

        return $addressed;
    }

    /**
     * Path first: the last literal segment before the template's final variable, kebab → snake, singular, when it names a
     * table; else the permission code's resource half when THAT names a table; else null.
     *
     * @param list<string> $tables
     */
    private static function addressedTable(string $template, string $required, array $tables): ?string
    {
        $segments = array_values(array_filter(explode('/', $template), static fn (string $s): bool => '' !== $s && !str_starts_with($s, '{')));
        $last = str_replace('-', '_', (string) end($segments));
        foreach ([(string) preg_replace('/ies$/', 'y', $last), (string) preg_replace('/s$/', '', $last), (string) preg_replace('/es$/', '', $last), $last] as $candidate) {
            if (\in_array($candidate, $tables, true)) {
                return $candidate;
            }
        }
        $fallback = strstr($required, '.', true);

        return \is_string($fallback) && \in_array($fallback, $tables, true) ? $fallback : null;
    }

    /** Planted controls on the path-first rule (`roles-s3-red-3.md` H-1's fix (1)). */
    #[Test]
    public function thePathNamesTheTableBeforeThePermissionDoes(): void
    {
        $tables = array_keys(SchemaManifest::TABLES);
        self::assertSame('credit_note', self::addressedTable('/credit-notes/{id}', 'invoice.view', $tables), 'A GET whose path names another table than its code did not map to the path\'s table.');
        self::assertSame('inventory_item', self::addressedTable('/items/{id}', 'inventory_item.view', $tables), 'The permission fallback no longer applies where the segment names no table.');
        self::assertSame('goods_receipt', self::addressedTable('/goods-receipts/{id}', 'goods_receipt.view', $tables));
        self::assertSame('sales_return', self::addressedTable('/sales-returns/{id}', 'sales_return.view', $tables));
        self::assertSame('app_user', self::addressedTable('/users/{id}', 'app_user.view', $tables));
        self::assertNull(self::addressedTable('/nothing-here/{id}', 'nothing.view', $tables));
    }

    /** Whether the registry builds a `GET` whose normalised template is exactly `$prefix`. */
    private static function hasBuiltItemGet(string $prefix): bool
    {
        foreach (TransactionBoundaryTest::contractOperations() as $operation) {
            if ('GET' === $operation->getMethod() && self::normalised(self::templateOf($operation)) === $prefix) {
                return true;
            }
        }

        return false;
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
