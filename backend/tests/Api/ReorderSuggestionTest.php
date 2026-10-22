<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Analytics\ApiResource\ReorderSuggestionResource;
use App\Modules\Inventory\Application\Contract\PickableStock;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Security\PermissionRequirement;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Remaining-ops G3-C1 — `GetReorderSummary` and `ListReorderSuggestions`** (`docs/session13/g3-g7b-final-red.md` C1 apply
 * list > `g3-g7b-reorder-settings-design.md` Revision 1 FINAL > body; `remaining-ops-final-red.md` M-4; owner ruling RO-R1
 * (c); `API.md` §4.2's reorder ruling and its *fails if*).
 *
 * Pins, each compared by literal:
 *  - the ruling's three named tests: every default role reaches the summary (roles DERIVED from the seeded defaults);
 *    the detail list refuses a caller short of any one of its SIX codes, naming it (403 + one E-3 row each); no role
 *    holding `invoice.view` without every `.view` (PC-12 clause 3's predicate, RED M-1) reaches the list;
 *  - M-4's on hand (pickable bins only) and on order (live lines of live POs in ordered / partially_received);
 *  - the managed predicate `reorder_point > 0 OR reorder_quantity > 0`, a value ≤ 0 treated as unset (RED M-3, F-L3);
 *  - the suggestion `max(quantity, shortfall, MOQ)`, and the at-the-point-suggests-0 case (L-5);
 *  - an archived preferred supplier is never suggested — the retired G-41 tripwire's last clause;
 *  - the summary is the list counted, and reads no catalogue (L-1); another workspace never counts.
 */
final class ReorderSuggestionTest extends PurchaseOrderTestCase
{
    /** @var array<string, array{method: string, path: string}> */
    public const PINNED_REORDER_OPERATIONS = [
        'GetReorderSummary' => ['method' => 'GET', 'path' => '/forecast/reorder/summary'],
        'ListReorderSuggestions' => ['method' => 'GET', 'path' => '/forecast/reorder'],
    ];

    /** §4.2's Perm cell for the detail list, byte order (P-0; final RED F-L1). */
    public const DETAIL_CODES = ['forecast.view', 'inventory_item.view', 'purchase_order.view', 'stock_level.view', 'supplier.view', 'supplier_product.view'];

    /** `ReorderSuggestionResource`'s serialized members — §4.4.3's ReorderSuggestion row (design D-C1-4). */
    public const PINNED_SUGGESTION_FIELDS = ['id', 'inventory_item', 'qty_on_hand_total', 'qty_on_order', 'reorder_point', 'reorder_quantity', 'suggested_quantity', 'preferred_supplier', 'lead_time_days', 'unit_cost', 'currency_code'];

    #[Test]
    public function theForecastPathDeclaresExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/forecast(/|$|\?)#', $path)) {
                $declared[$name] = ['method' => (string) $operation->getMethod(), 'path' => $path];
            }
        }
        ksort($declared);
        $pinned = self::PINNED_REORDER_OPERATIONS;
        ksort($pinned);

        self::assertSame($pinned, $declared);
        self::assertSame(self::DETAIL_CODES, PermissionRequirement::of(TransactionBoundaryTest::contractOperations()['ListReorderSuggestions'])->codes(),
            'The detail list\'s declaration is not the six codes in byte order.');
        self::assertSame(['forecast.view'], PermissionRequirement::of(TransactionBoundaryTest::contractOperations()['GetReorderSummary'])->codes(),
            'The summary requires something beyond forecast.view (the ruling\'s first fails-if).');
    }

    /** Independent of `docs/`: the DTO's serialized members, in order, and the inline schema is exactly them. */
    #[Test]
    public function theWireSchemaIsTheResourcesOwnMembers(): void
    {
        $members = [];
        foreach (new \ReflectionClass(ReorderSuggestionResource::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attribute = new \ReflectionProperty(ReorderSuggestionResource::class, $parameter->getName())->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $attribute ? $parameter->getName() : $attribute->newInstance()->serializedName;
        }

        self::assertSame(self::PINNED_SUGGESTION_FIELDS, $members);
        self::assertSame($members, array_keys(ReorderSuggestionResource::WIRE_SCHEMA['properties']));
        self::assertSame($members, ReorderSuggestionResource::WIRE_SCHEMA['required']);
        self::assertNotContains('qty_available', $members);
        self::assertNotContains('qty_reserved', $members);
    }

    /**
     * The ruling's first named test: **the role set is DERIVED from the seeded defaults**, never named, and every one gets a
     * 200 carrying the count and nothing else. This is the assertion that would have gone red on the ruling's revision 2.
     */
    #[Test]
    public function everyDefaultRoleCanReachTheSummary(): void
    {
        $roles = DefaultRoles::all();
        self::assertCount(8, $roles, 'Control: the default-role table is not the eight roles F-1 step 5 names.');

        foreach ($roles as $role => $codes) {
            $this->actAs(self::TENANT_A, $codes);
            $this->send('GET', '/forecast/reorder/summary');

            self::assertSame(200, $this->httpStatus(), $role.': '.$this->responseContent());
            self::assertSame(['items_below_reorder_point'], array_keys($this->json()), $role.': the summary carries more than the count.');
        }
    }

    /**
     * The ruling's second named test, driven one code at a time: a caller holding the other five gets a 403 naming the
     * missing one, and exactly one E-3 row naming it (the gate's first-missing rule, byte order — F-L7).
     */
    #[Test]
    public function theDetailListRequiresEveryConstituentCode(): void
    {
        foreach (self::DETAIL_CODES as $missing) {
            $this->actAs(self::TENANT_A, array_values(array_diff(self::DETAIL_CODES, [$missing])));
            $before = \count($this->auditRowsOf('authz.permission.denied'));

            $this->send('GET', '/forecast/reorder');

            self::assertSame(403, $this->httpStatus(), $missing.': '.$this->responseContent());
            self::assertSame('urn:erp:problem:permission-denied', $this->json()['type']);
            self::assertSame($missing, $this->json()['required_permission']);
            $rows = \array_slice($this->auditRowsOf('authz.permission.denied'), $before);
            self::assertCount(1, $rows, $missing.': not exactly one E-3 row.');
            self::assertSame($missing, $rows[0]['permission_code']);
            self::assertNull($rows[0]['subject_type'], 'The path names no row, so the denial names none (PermissionGate::denialSubjectOf).');
        }

        // Short of two: the FIRST missing in byte order is named (F-L7).
        $this->actAs(self::TENANT_A, ['forecast.view', 'inventory_item.view', 'stock_level.view', 'supplier.view']);
        $this->send('GET', '/forecast/reorder');
        self::assertSame(403, $this->httpStatus());
        self::assertSame('purchase_order.view', $this->json()['required_permission']);

        // Control: holding all six is not refused.
        $this->actAs(self::TENANT_A, self::DETAIL_CODES);
        $this->send('GET', '/forecast/reorder');
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
    }

    /**
     * The ruling's third named test, **scoped to PC-12 clause 3's predicate** (RED M-1): no default role that holds
     * `invoice.view` WITHOUT holding every `.view` code reaches the detail list. Owner and admin hold every `.view` (and both
     * halves of the margin pair as seeded, their accepted position), so they are outside the domain. The domain is derived
     * from `DefaultRoles::all()` — the definition the seed writes, never a typed list of names — and the `.view` set from the
     * server's `permission` catalogue. **Mutation (amended 2026-10-22, `g3-c1-red.md` M-1):** the ruling's own — grant
     * `supplier_product.view` to `finance` — can no longer fail under six codes (finance stays four short); the live one grants
     * `viewer` `purchase_order.view` + `supplier_product.view` → this test names `viewer`.
     */
    #[Test]
    public function noRoleHoldingInvoiceViewCanReachTheDetailList(): void
    {
        $views = array_map('strval', $this->owner()->fetchFirstColumn("SELECT code FROM permission WHERE code LIKE '%.view' ORDER BY code"));
        self::assertContains('invoice.view', $views, 'Control: the catalogue holds no invoice.view.');

        $domain = [];
        foreach (DefaultRoles::all() as $role => $codes) {
            if (\in_array('invoice.view', $codes, true) && [] !== array_diff($views, $codes)) {
                $domain[] = $role;
                $this->actAs(self::TENANT_A, $codes);
                $this->send('GET', '/forecast/reorder');
                self::assertSame(403, $this->httpStatus(), $role.' holds invoice.view and reaches the reorder list: PC-12\'s margin pair is assembled.');
            }
        }
        sort($domain);
        self::assertSame([DefaultRoles::FINANCE, DefaultRoles::SALES_REP, DefaultRoles::VIEWER], $domain,
            'Control: the clause-3 domain over the defaults is not the three invoice.view holders the ruling names.');
    }

    /** M-4's on hand: a quarantine bin, an archived bin and a bin of an archived warehouse never count; a live bin does. */
    #[Test]
    public function quarantineAndArchivedStockAreNotOnHand(): void
    {
        $main = $this->seedWarehouse(self::TENANT_A, 'Reorder Main');
        $closed = $this->seedWarehouse(self::TENANT_A, 'Reorder Closed');
        $live = $this->seedStorageLocation(self::TENANT_A, $main, 'R-LIVE');
        $held = $this->seedStorageLocation(self::TENANT_A, $main, 'R-HELD', PickableStock::NEVER_PICKABLE_KIND);
        $retired = $this->seedStorageLocation(self::TENANT_A, $main, 'R-RETIRED');
        $inClosed = $this->seedStorageLocation(self::TENANT_A, $closed, 'R-CLOSED');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$retired]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$closed]);

        $item = $this->managedItem('SKU-RO-OH', point: '100');
        foreach ([$live => '3', $held => '50', $retired => '60', $inClosed => '70'] as $bin => $quantity) {
            $this->stock($item, $bin, $quantity);
        }

        self::assertSame('3.000000', $this->rowOf($item)['qty_on_hand_total']);
    }

    /**
     * The two readers agree on one seed covering every pickable case: the reorder on hand equals the sum, over every warehouse,
     * of `PickableStock::candidateBins` (design §2.7). Both readers are built from ONE SQL fragment
     * (`PickableStockReader::PICKABLE_FROM`/`PICKABLE_WHERE`), so this catches a reader that stops using it; the definition
     * itself is held by the fragment, and its cases by {@see self::quarantineAndArchivedStockAreNotOnHand} (`g3-c1-red.md` L-2).
     */
    #[Test]
    public function theCountedBinsAreThePickableBins(): void
    {
        $a = $this->seedWarehouse(self::TENANT_A, 'Reorder Pick A');
        $b = $this->seedWarehouse(self::TENANT_A, 'Reorder Pick B');
        $bins = [
            $this->seedStorageLocation(self::TENANT_A, $a, 'P-1') => '2.5',
            $this->seedStorageLocation(self::TENANT_A, $a, 'P-2', PickableStock::NEVER_PICKABLE_KIND) => '9',
            $this->seedStorageLocation(self::TENANT_A, $b, 'P-3') => '4',
            $this->seedStorageLocation(self::TENANT_A, $b, 'P-4') => '0',
            $retired = $this->seedStorageLocation(self::TENANT_A, $b, 'P-5') => '11',
            $inClosed = $this->seedStorageLocation(self::TENANT_A, $closed = $this->seedWarehouse(self::TENANT_A, 'Reorder Pick Closed'), 'P-6') => '13',
        ];
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$retired]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$closed]);
        $item = $this->managedItem('SKU-RO-PICK', point: '1000');
        foreach ($bins as $bin => $quantity) {
            $this->stock($item, $bin, $quantity);
        }

        $pickable = self::getContainer()->get(PickableStock::class);
        \assert($pickable instanceof PickableStock);
        $this->actAs(self::TENANT_A, self::DETAIL_CODES);
        $sum = '0';
        foreach ([$a, $b, $closed] as $warehouse) {
            foreach ($pickable->candidateBins($warehouse, [$item])[$item] ?? [] as $bin) {
                $sum = bcadd($sum, $bin->qtyOnHand, 6);
            }
        }

        self::assertSame('6.500000', $sum, 'Control: the pick read did not see the seed.');
        self::assertSame($sum, $this->rowOf($item)['qty_on_hand_total']);
    }

    /**
     * M-4's on order: only the outstanding of LIVE lines of LIVE orders in `ordered` / `partially_received` counts — not a
     * draft, a received or a cancelled order, an archived order, or a deleted line.
     */
    #[Test]
    public function onOrderIsWhatOpenOrdersStillOwe(): void
    {
        $item = $this->managedItem('SKU-RO-OO', point: '1000');
        $supplier = $this->seedSupplier(self::TENANT_A, 'Reorder Supply '.bin2hex(random_bytes(3)));
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Reorder Supply WH');

        $cases = [
            [PurchaseOrder::STATUS_DRAFT, false, '10', '0', false],
            [PurchaseOrder::STATUS_ORDERED, false, '7', '0', false],           // counts 7
            [PurchaseOrder::STATUS_PARTIALLY_RECEIVED, false, '10', '4', false], // counts 6
            [PurchaseOrder::STATUS_RECEIVED, false, '5', '5', false],
            [PurchaseOrder::STATUS_CANCELLED, false, '8', '0', false],
            // No archived ORDERED case: `purchase_order_archived_only_while_draft` refuses one, so an archived order is a draft and
            // the status predicate already excludes it (the `deleted_at IS NULL` term is the partial index's own, for P-1).
            [PurchaseOrder::STATUS_DRAFT, true, '9', '0', false],              // archived draft
            [PurchaseOrder::STATUS_ORDERED, false, '11', '0', true],           // deleted line
        ];
        foreach ($cases as $n => [$status, $archived, $ordered, $received, $lineDeleted]) {
            $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: $status, archived: $archived);
            $line = $this->seedLine(self::TENANT_A, $order, $item, quantity: $ordered, quantityReceived: $received);
            if ($lineDeleted) {
                $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE purchase_order_line SET deleted_at = now() WHERE id = ?::uuid', [$line]);
            }
        }

        self::assertSame('13.000000', $this->rowOf($item)['qty_on_order']);
    }

    /**
     * The reorder's on order and the buyer's line agree: for the same open orders, the on-order figure is the sum of
     * `ListPurchaseOrderLines`' `quantity_outstanding` (one derivation, `PurchaseOrderLineRow::OUTSTANDING`).
     */
    #[Test]
    public function onOrderEqualsTheLinesOwnOutstanding(): void
    {
        $item = $this->managedItem('SKU-RO-AGREE', point: '1000');
        $supplier = $this->seedSupplier(self::TENANT_A, 'Reorder Agree '.bin2hex(random_bytes(3)));
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Reorder Agree WH');
        $orders = [];
        foreach ([[PurchaseOrder::STATUS_ORDERED, '4.25', '0'], [PurchaseOrder::STATUS_PARTIALLY_RECEIVED, '12', '2.5']] as [$status, $ordered, $received]) {
            $orders[] = $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: $status);
            $this->seedLine(self::TENANT_A, $order, $item, quantity: $ordered, quantityReceived: $received);
        }

        $this->actAs(self::TENANT_A, self::DETAIL_CODES);
        $sum = '0';
        foreach ($orders as $order) {
            $this->send('GET', '/purchase-orders/'.$order.'/lines');
            self::assertSame(200, $this->httpStatus(), $this->responseContent());
            foreach ($this->json()['items'] as $line) {
                $sum = bcadd($sum, (string) $line['quantity_outstanding'], 6);
            }
        }

        self::assertSame('13.750000', $sum, 'Control: the lines did not carry the seed.');
        self::assertSame($sum, $this->rowOf($item)['qty_on_order']);
    }

    /**
     * The floor (M-4: outstanding floored at 0) cannot be observed while `purchase_order_line_not_over_received` stands —
     * the database refuses an over-received line whoever writes it. This pins the PREMISE: the day the constraint goes, this
     * is red, and the floor gains a behaviour test with it (`PurchaseOrderLineRow`'s class docblock says the same).
     */
    #[Test]
    public function theOverReceiptConstraintThatMakesTheFloorUnobservableStillStands(): void
    {
        self::assertSame(1, (int) $this->owner()->fetchOne(
            "SELECT count(*) FROM pg_constraint WHERE conname = 'purchase_order_line_not_over_received' AND contype = 'c' AND convalidated"),
            'purchase_order_line_not_over_received is gone: an over-received line is now storable, so the reorder\'s GREATEST(0, …) '
            .'floor is reachable — test it (seed received > ordered) in the commit that drops the constraint.');
    }

    /**
     * RO-R1 (c): the point is compared with on hand PLUS on order, and the suggestion is netted of it — an item whose open
     * order lifts it above its point is not listed, and a listed one suggests only the remaining shortfall. (Mutation
     * M-ONORDER, found GREEN against the first test set: `qty_on_order` was asserted as served, never as used.)
     */
    #[Test]
    public function openSupplyIsNettedFromTheListingAndTheSuggestion(): void
    {
        $bin = $this->bin('R-NET');
        $supplier = $this->seedSupplier(self::TENANT_A, 'Reorder Net '.bin2hex(random_bytes(3)));
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Reorder Net WH');
        $covered = $this->managedItem('SKU-RO-NET-C', point: '10');
        $partly = $this->managedItem('SKU-RO-NET-P', point: '10');
        foreach ([$covered => '9', $partly => '3'] as $item => $onOrder) {
            $this->stock($item, $bin, '2');
            $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);
            $this->seedLine(self::TENANT_A, $order, $item, quantity: $onOrder);
        }

        self::assertNotContains($covered, $this->listedIds(), 'On hand 2 + on order 9 is above the point 10.');
        self::assertSame('5.000000', $this->rowOf($partly)['suggested_quantity'], 'Point 10 − (2 on hand + 3 on order).');
    }

    /** F-4 step 1's literal "at or below": projected = point is listed, one above is not. */
    #[Test]
    public function atThePointIsListedAndAboveIsNot(): void
    {
        $bin = $this->bin('R-AT');
        $at = $this->managedItem('SKU-RO-AT', point: '5', quantity: '10');
        $above = $this->managedItem('SKU-RO-ABOVE', point: '5', quantity: '10');
        $this->stock($at, $bin, '5');
        $this->stock($above, $bin, '5.000001');

        $listed = $this->listedIds();
        self::assertContains($at, $listed);
        self::assertNotContains($above, $listed);
    }

    /**
     * The managed predicate (RED M-3): a point of 0 with a quantity is "order N when out" — listed when out, not when
     * stocked; both 0 (the column defaults) is never listed, even when out; an archived item is never listed.
     */
    #[Test]
    public function aZeroPointWithAQuantityIsListedWhenOutAndBothZeroIsNever(): void
    {
        $bin = $this->bin('R-ZERO');
        $whenOut = $this->managedItem('SKU-RO-WO', point: '0', quantity: '6');
        $whenOutStocked = $this->managedItem('SKU-RO-WOS', point: '0', quantity: '6');
        $this->stock($whenOutStocked, $bin, '1');
        $unmanaged = $this->seedItem(self::TENANT_A, 'SKU-RO-NONE');
        $archived = $this->managedItem('SKU-RO-ARCH', point: '9', quantity: '9');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $listed = $this->listedIds();
        self::assertContains($whenOut, $listed);
        self::assertSame('6.000000', $this->rowOf($whenOut)['suggested_quantity']);
        self::assertNotContains($whenOutStocked, $listed);
        self::assertNotContains($unmanaged, $listed);
        self::assertNotContains($archived, $listed);
    }

    /**
     * Final RED F-L3: nothing CHECKs the sign of either value, so a value ≤ 0 is unset. A negative point with no quantity is
     * not managed; a negative quantity never makes a suggestion negative — the shortfall wins; both stored values are served
     * as stored.
     */
    #[Test]
    public function aNegativeReorderValueIsTreatedAsUnset(): void
    {
        $bin = $this->bin('R-NEG');
        $negativePoint = $this->managedItem('SKU-RO-NP', point: '-5', quantity: '0');
        $negativeQuantity = $this->managedItem('SKU-RO-NQ', point: '10', quantity: '-3');
        $this->stock($negativeQuantity, $bin, '4');
        $negativeBoth = $this->managedItem('SKU-RO-NB', point: '-1', quantity: '-1');
        // `g3-c1-red.md` M-2: the case where the rule decides the LISTING — a negative point beside a positive quantity is
        // "order N when out": listed at zero stock (projected 0 ≤ max(-5, 0)), never compared with the raw -5.
        $whenOut = $this->managedItem('SKU-RO-NPQ', point: '-5', quantity: '6');
        $whenOutStocked = $this->managedItem('SKU-RO-NPQS', point: '-5', quantity: '6');
        $this->stock($whenOutStocked, $bin, '1');

        $listed = $this->listedIds();
        self::assertNotContains($negativePoint, $listed);
        self::assertNotContains($negativeBoth, $listed);
        self::assertContains($whenOut, $listed);
        self::assertNotContains($whenOutStocked, $listed);
        self::assertSame('6.000000', $this->rowOf($whenOut)['suggested_quantity']);
        self::assertSame('-5.000000', $this->rowOf($whenOut)['reorder_point'], 'The stored value is served as stored.');
        $row = $this->rowOf($negativeQuantity);
        self::assertSame('6.000000', $row['suggested_quantity']);
        self::assertSame('-3.000000', $row['reorder_quantity'], 'The stored value is served as stored.');
    }

    /** The suggestion is the reorder quantity, or the shortfall when that is larger (D-C1-2). */
    #[Test]
    public function theSuggestionCoversTheShortfall(): void
    {
        $bin = $this->bin('R-SHORT');
        $quantityWins = $this->managedItem('SKU-RO-QW', point: '10', quantity: '25');
        $shortfallWins = $this->managedItem('SKU-RO-SW', point: '40', quantity: '25');
        $this->stock($quantityWins, $bin, '2');
        $this->stock($shortfallWins, $bin, '2.5');

        self::assertSame('25.000000', $this->rowOf($quantityWins)['suggested_quantity']);
        self::assertSame('37.500000', $this->rowOf($shortfallWins)['suggested_quantity']);
    }

    /**
     * The minimum order quantity of the live preferred entry raises the suggestion; the row carries the supplier and its
     * terms (unit cost scale 4, lead time, currency).
     */
    #[Test]
    public function theSuggestionMeetsTheMinimumOrder(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Reorder MOQ '.bin2hex(random_bytes(3)));
        $item = $this->managedItem('SKU-RO-MOQ', point: '10', quantity: '5', supplier: $supplier);
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item, leadTimeDays: 12);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier_product SET min_order_quantity = 48, unit_cost = 3.2500 WHERE id = ?::uuid', [$entry]);

        $row = $this->rowOf($item);
        self::assertSame('48.000000', $row['suggested_quantity']);
        self::assertSame(['id' => $supplier, 'name' => $this->supplierName($supplier)], $row['preferred_supplier']);
        self::assertSame(12, $row['lead_time_days']);
        self::assertSame('3.2500', $row['unit_cost']);
        self::assertSame('EUR', $row['currency_code']);
    }

    /**
     * RED L-5 / owner notice N-13: at its point, with no reorder quantity and no catalogue entry, the item is listed and
     * suggests 0 — no invented floor.
     */
    #[Test]
    public function atThePointWithNoQuantityAndNoEntrySuggestsZero(): void
    {
        $bin = $this->bin('R-L5');
        $item = $this->managedItem('SKU-RO-L5', point: '4');
        $this->stock($item, $bin, '4');

        $row = $this->rowOf($item);
        self::assertSame('0.000000', $row['suggested_quantity']);
        self::assertNull($row['preferred_supplier']);
    }

    /**
     * **The retired G-41 tripwire's last clause** (C5 C2b keeps an archived supplier's reference and catalogue): an item whose
     * preferred supplier is archived is still LISTED — it still needs stock — but the supplier, its terms and its minimum
     * order are never served. Compared WHOLE.
     */
    #[Test]
    public function anArchivedPreferredSupplierIsNeverSuggested(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Reorder Archived '.bin2hex(random_bytes(3)));
        $item = $this->managedItem('SKU-RO-ARCHSUP', point: '10', quantity: '5', supplier: $supplier);
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier_product SET min_order_quantity = 500 WHERE id = ?::uuid', [$entry]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$supplier]);

        self::assertSame([
            'id' => $item,
            'inventory_item' => ['id' => $item, 'sku' => 'SKU-RO-ARCHSUP', 'name' => 'SKU-RO-ARCHSUP'],
            'qty_on_hand_total' => '0.000000',
            'qty_on_order' => '0.000000',
            'reorder_point' => '10.000000',
            'reorder_quantity' => '5.000000',
            'suggested_quantity' => '10.000000',
            'preferred_supplier' => null,
            'lead_time_days' => null,
            'unit_cost' => null,
            'currency_code' => null,
        ], $this->rowOf($item));
    }

    /** A live preferred supplier with no catalogue entry for the item: the supplier is served, its terms are null. */
    #[Test]
    public function aPreferredSupplierWithNoEntryHasNoTerms(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Reorder NoEntry '.bin2hex(random_bytes(3)));
        $item = $this->managedItem('SKU-RO-NOENTRY', point: '3', quantity: '2', supplier: $supplier);
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-RO-OTHERITEM'));

        $row = $this->rowOf($item);
        self::assertSame(['id' => $supplier, 'name' => $this->supplierName($supplier)], $row['preferred_supplier']);
        self::assertNull($row['lead_time_days']);
        self::assertNull($row['unit_cost']);
        self::assertNull($row['currency_code']);
        self::assertSame('3.000000', $row['suggested_quantity']);
    }

    /** The summary is the same list, counted (one path); an empty list is a 0, never a 404. */
    #[Test]
    public function theSummaryCountsTheList(): void
    {
        $this->actAs(self::TENANT_A, ['forecast.view']);
        $this->send('GET', '/forecast/reorder/summary');
        self::assertSame(['items_below_reorder_point' => 0], $this->json());

        $bin = $this->bin('R-COUNT');
        $this->managedItem('SKU-RO-C1', point: '5');
        $this->managedItem('SKU-RO-C2', point: '5', quantity: '1');
        $this->stock($this->managedItem('SKU-RO-C3', point: '5'), $bin, '6');

        $listed = $this->listedIds();
        self::assertCount(2, $listed);
        $this->actAs(self::TENANT_A, ['forecast.view']);
        $this->send('GET', '/forecast/reorder/summary');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['items_below_reorder_point' => \count($listed)], $this->json());
    }

    /**
     * RED L-1: the Overview count runs S1 + S2 only — it issues no read of `supplier` or `supplier_product` (statement log),
     * so `/overview` never reads a cost. Control: the detail list does read the catalogue.
     */
    #[Test]
    public function theSummaryReadsNoCatalogue(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Reorder Statement '.bin2hex(random_bytes(3)));
        $item = $this->managedItem('SKU-RO-STMT', point: '5', supplier: $supplier);
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);
        $catalogue = '/\b(?:supplier|supplier_product)\b(?!_id)/';

        $this->actAs(self::TENANT_A, self::DETAIL_CODES);
        $this->recorder()->start();
        $this->send('GET', '/forecast/reorder/summary');
        $this->recorder()->stop();
        self::assertSame(['items_below_reorder_point' => 1], $this->json());
        self::assertNotSame([], $this->recorder()->matching('/\binventory_item\b/'), 'Control: the statement log saw nothing of the summary.');
        self::assertSame([], array_values(array_filter($this->recorder()->matching($catalogue), static fn (string $sql): bool => 1 !== preg_match('/^\s*(SET|SELECT set_config|BEGIN|COMMIT)/i', $sql))),
            'The summary read the catalogue.');

        $this->recorder()->start();
        $this->send('GET', '/forecast/reorder');
        $this->recorder()->stop();
        self::assertNotSame([], $this->recorder()->matching('/\bsupplier_product\b/'), 'Control: the detail list issued no catalogue read, so the pattern is blind.');
    }

    /** Another workspace's items, stock and orders never count, in either operation. */
    #[Test]
    public function anotherTenantsStockAndOrdersNeverCount(): void
    {
        $mine = $this->managedItem('SKU-RO-TEN', point: '10', quantity: '1');
        $theirs = $this->managedItem('SKU-RO-TEN', point: '10', quantity: '1', tenant: self::TENANT_B);
        $theirWarehouse = $this->seedWarehouse(self::TENANT_B, 'Reorder Theirs');
        $this->stock($theirs, $this->seedStorageLocation(self::TENANT_B, $theirWarehouse, 'T-1'), '3', self::TENANT_B);
        $theirSupplier = $this->seedSupplier(self::TENANT_B, 'Reorder Their Supplier');
        $theirOrder = $this->seedOrder(self::TENANT_B, self::freshNumber('PO'), $theirSupplier, $theirWarehouse, status: PurchaseOrder::STATUS_ORDERED);
        $this->seedLine(self::TENANT_B, $theirOrder, $theirs, quantity: '4');

        $row = $this->rowOf($mine);
        self::assertSame('0.000000', $row['qty_on_hand_total']);
        self::assertSame('0.000000', $row['qty_on_order']);
        self::assertNotContains($theirs, $this->listedIds());
        $this->send('GET', '/forecast/reorder/summary');
        self::assertSame(['items_below_reorder_point' => 1], $this->json());
    }

    /** API-22: an empty list in an itemless workspace says so; with items but nothing listed, it says the workspace has rows. */
    #[Test]
    public function anEmptyListTellsAnEmptyWorkspaceFromNothingToReorder(): void
    {
        $this->actAs(self::TENANT_A, self::DETAIL_CODES);
        $this->send('GET', '/forecast/reorder');
        self::assertSame(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => false], $this->json());

        $this->seedItem(self::TENANT_A, 'SKU-RO-UNMANAGED');
        $this->send('GET', '/forecast/reorder');
        self::assertSame(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => true], $this->json());
    }

    // ---- helpers ----------------------------------------------------------

    private function managedItem(string $sku, string $point = '0', string $quantity = '0', ?string $supplier = null, string $tenant = self::TENANT_A): string
    {
        $item = $this->seedItem($tenant, $sku, $supplier);
        $this->insertAsOwnerForTenant($tenant, 'UPDATE inventory_item SET reorder_point = ?::numeric, reorder_quantity = ?::numeric WHERE id = ?::uuid', [$point, $quantity, $item]);

        return $item;
    }

    private function bin(string $code): string
    {
        return $this->seedStorageLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A, 'Reorder '.$code), $code);
    }

    private function stock(string $item, string $bin, string $quantity, string $tenant = self::TENANT_A): void
    {
        $this->insertAsOwnerForTenant($tenant,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), $tenant, $item, $bin, $quantity]);
    }

    private function supplierName(string $supplier): string
    {
        return (string) ($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT name FROM supplier WHERE id = ?::uuid', [$supplier])[0]['name'] ?? '');
    }

    /** @return list<string> the listed item ids, as a six-code holder sees them */
    private function listedIds(): array
    {
        $this->actAs(self::TENANT_A, self::DETAIL_CODES);
        $this->send('GET', '/forecast/reorder');
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        return array_column($this->json()['items'], 'id');
    }

    /** @return array<string, mixed> the item's row (it must be listed) */
    private function rowOf(string $item): array
    {
        $this->actAs(self::TENANT_A, self::DETAIL_CODES);
        $this->send('GET', '/forecast/reorder');
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        foreach ($this->json()['items'] as $row) {
            if ($row['id'] === $item) {
                return $row;
            }
        }
        self::fail('The item is not listed: '.$this->responseContent());
    }
}
