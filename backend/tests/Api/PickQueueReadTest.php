<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\ApiResource\PickQueueEntryResource;
use App\Modules\Sales\Application\SalesOrderViews;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Pagination\Cursor;
use App\Tests\Support\SecurityModelDocument;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **BK-21 — `ListPickQueueEntries` (`GET /pick-queue`)**, the warehouse's way in to the pick screen (owner D-5 of
 * 2026-11-03; `docs/design/bk21-design.md` Revision 1 as fixed by `bk21-final-red.md`).
 *
 * Pins:
 *  - the `/pick-queue` path family is exactly {@see self::PINNED_PICK_QUEUE_OPERATIONS}, joined into
 *    `AuthOperationContractTest`'s union; the DTO's members are exactly {@see self::PINNED_PICK_QUEUE_FIELDS}, and the
 *    inline wire schema is those members (T-6's code-constant half, which does not skip without `docs/`);
 *  - T-1 rows: the open segment only — never a draft, a fulfilled order, a cancelled one (either kind) or an archived one;
 *  - T-2 oldest first by `(ordered_at, id)`, a tie group walked at `limit=1` both ways;
 *  - T-3 a confirmed row with a NULL `ordered_at` (owner SQL only) never appears, and every emitted cursor answers 200;
 *  - T-4 the warehouse filter; T-5 the order-number prefix (never infix; `%` and `_` escaped);
 *  - T-6 no money column (server-derived, positive control) and PC-9's fifth exception names every served column;
 *  - T-7 the gate is `fulfilment.create` alone (`admin` refused by SoD-4); T-8 `tenant_has_rows` over the base view;
 *  - T-9 the refusals; T-12 an archived customer is still named.
 *
 * ⚠ Every fixture here passes `orderedAt:` explicitly: `seedSalesOrder()` defaults it to NULL, and a NULL-dated row is
 * exactly what the queue hides (design R3).
 */
final class PickQueueReadTest extends PurchaseOrderTestCase
{
    /**
     * The `/pick-queue` operations, held equal to the registry.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_PICK_QUEUE_OPERATIONS = [
        'ListPickQueueEntries' => ['method' => 'GET', 'path' => '/pick-queue', 'idem' => false],
    ];

    /** `PickQueueEntryResource`'s serialized members — §4.4.3's PickQueueEntry row, plus `id`. */
    public const PINNED_PICK_QUEUE_FIELDS = ['id', 'order_number', 'status', 'ordered_at', 'customer', 'warehouse'];

    private string $warehouse = '';

    private string $customer = '';

    #[Test]
    public function thePickQueuePathDeclaresExactlyThePinnedOperation(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/pick-queue(/|$)#', $path)) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $path];
            }
        }
        ksort($declared);
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_PICK_QUEUE_OPERATIONS);

        self::assertSame($pinned, $declared);
    }

    /** Independent of `docs/`: the DTO's serialized members, in order, and the inline wire schema is exactly them. */
    #[Test]
    public function theEntryShapeIsTheRowsAndNothingElse(): void
    {
        $members = [];
        foreach (new \ReflectionClass(PickQueueEntryResource::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attribute = new \ReflectionProperty(PickQueueEntryResource::class, $parameter->getName())->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $attribute ? $parameter->getName() : $attribute->newInstance()->serializedName;
        }

        self::assertSame(self::PINNED_PICK_QUEUE_FIELDS, $members);
        self::assertSame(self::PINNED_PICK_QUEUE_FIELDS, array_keys(PickQueueEntryResource::WIRE_SCHEMA['properties']));
        self::assertSame(self::PINNED_PICK_QUEUE_FIELDS, PickQueueEntryResource::WIRE_SCHEMA['required']);
        self::assertSame(SalesOrderViews::SEGMENTS['open'], PickQueueEntryResource::WIRE_SCHEMA['properties']['status']['enum']);
    }

    /** The fixture-visibility control (design R3): a seeded, dated, confirmed order is served, whole. */
    #[Test]
    public function aDatedConfirmedOrderIsServedWithItsNumberDateCustomerAndWarehouse(): void
    {
        $this->world();
        $id = $this->order('SO-870001', SalesOrder::STATUS_CONFIRMED, '2026-03-01T09:00:00+00:00');

        $this->send('GET', '/pick-queue');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            'items' => [[
                'id' => $id,
                'order_number' => 'SO-870001',
                'status' => 'confirmed',
                'ordered_at' => '2026-03-01T09:00:00.000+00:00',
                'customer' => ['id' => $this->customer, 'name' => 'Queue Customer'],
                'warehouse' => ['id' => $this->warehouse, 'name' => 'Queue WH'],
            ]],
            'next_cursor' => null,
            'previous_cursor' => null,
            'tenant_has_rows' => true,
        ], $this->json());
    }

    /** T-1: the open segment, released and live — nothing else. */
    #[Test]
    public function onlyConfirmedAndPartlyShippedOrdersAreQueued(): void
    {
        $this->world();
        $confirmed = $this->order('SO-870010', SalesOrder::STATUS_CONFIRMED, '2026-03-01T09:00:00+00:00');
        $partly = $this->order('SO-870011', SalesOrder::STATUS_PARTIALLY_FULFILLED, '2026-03-02T09:00:00+00:00');
        $this->order('SO-870012', SalesOrder::STATUS_DRAFT, null);
        $this->order('SO-870013', SalesOrder::STATUS_FULFILLED, '2026-03-03T09:00:00+00:00');
        $this->order('SO-870014', SalesOrder::STATUS_CANCELLED, '2026-03-04T09:00:00+00:00');
        $this->order('SO-870015', SalesOrder::STATUS_CANCELLED, null);
        $archived = $this->order('SO-870016', SalesOrder::STATUS_DRAFT, null);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->send('GET', '/pick-queue');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([$confirmed, $partly], $this->ids());
        self::assertSame(['confirmed', 'partially_fulfilled'], array_column($this->json()['items'], 'status'));
    }

    /** T-2: oldest first, and a tie group (two orders, one instant) walks whole at `limit=1`, forward and back. */
    #[Test]
    public function theQueueIsOldestFirstAndATieGroupPagesWhole(): void
    {
        $this->world();
        $late = $this->order('SO-870020', SalesOrder::STATUS_CONFIRMED, '2026-03-05T09:00:00+00:00');
        $tieA = $this->order('SO-870021', SalesOrder::STATUS_CONFIRMED, '2026-03-02T09:00:00+00:00');
        $tieB = $this->order('SO-870022', SalesOrder::STATUS_PARTIALLY_FULFILLED, '2026-03-02T09:00:00+00:00');
        $early = $this->order('SO-870023', SalesOrder::STATUS_CONFIRMED, '2026-03-01T09:00:00+00:00');
        $ties = [$tieA, $tieB];
        sort($ties);
        $expected = [$early, ...$ties, $late];

        $this->send('GET', '/pick-queue');
        self::assertSame($expected, $this->ids());

        [$forward, $backward] = $this->walk(1);
        self::assertSame($expected, $forward);
        self::assertSame(array_reverse($expected), $backward);
    }

    /**
     * T-3 (design R2, final review L-1): two confirmed live rows with a NULL `ordered_at` — writable only by owner SQL —
     * never appear, and every cursor emitted on the way answers 200. Under M1 (the predicate dropped) the `limit=4` page
     * holds a NULL row as item N+1 and its `next_cursor` is keyed on `''`, which answers 422.
     */
    #[Test]
    public function aConfirmedOrderWithNoDateIsNeverQueuedAndNoCursorBreaks(): void
    {
        $this->world();
        $real = [
            $this->order('SO-870030', SalesOrder::STATUS_CONFIRMED, '2026-03-01T09:00:00+00:00'),
            $this->order('SO-870031', SalesOrder::STATUS_CONFIRMED, '2026-03-02T09:00:00+00:00'),
            $this->order('SO-870032', SalesOrder::STATUS_PARTIALLY_FULFILLED, '2026-03-03T09:00:00+00:00'),
        ];
        $this->order('SO-870033', SalesOrder::STATUS_CONFIRMED, null);
        $this->order('SO-870034', SalesOrder::STATUS_CONFIRMED, null);

        // Positive control: the hazard really was planted.
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A,
            "SELECT count(*) FROM sales_order WHERE status = 'confirmed' AND ordered_at IS NULL AND deleted_at IS NULL"));

        $cursors = [];

        $this->send('GET', '/pick-queue?limit=8');
        self::assertSame(200, $this->httpStatus());
        self::assertSame($real, $this->ids(), 'A NULL-dated row was served (limit=N+5).');

        $this->send('GET', '/pick-queue?limit=4');
        self::assertSame(200, $this->httpStatus());
        self::assertSame($real, $this->ids(), 'A NULL-dated row was served (limit=N+1).');
        $cursors[] = $this->json()['next_cursor'];
        self::assertNull($this->json()['next_cursor'], 'The N+1 page emitted a cursor: a NULL-dated row was counted.');

        [$forward, $backward, $emitted] = $this->walk(1, true);
        self::assertSame($real, $forward);
        self::assertSame(array_reverse($real), $backward);

        foreach (array_filter([...$cursors, ...$emitted]) as $cursor) {
            $this->send('GET', '/pick-queue?after='.$cursor);
            self::assertSame(200, $this->httpStatus(), 'An emitted cursor did not answer 200: '.(string) $this->client()->getResponse()->getContent());
        }
        self::assertNotSame([], array_filter($emitted), 'The walk emitted no cursor, so none was followed.');
    }

    /** T-4: the warehouse filter; another workspace's, an unknown or an archived warehouse is an empty 200. */
    #[Test]
    public function theWarehouseFilterReturnsThatWarehousesOrdersAndAStrangerIsAnEmptyPage(): void
    {
        $this->world();
        $mine = $this->order('SO-870040', SalesOrder::STATUS_CONFIRMED, '2026-03-01T09:00:00+00:00');
        $other = $this->seedWarehouse(self::TENANT_A, 'Other WH');
        $elsewhere = $this->seedSalesOrder(self::TENANT_A, 'SO-870041', $this->customer, $other, SalesOrder::STATUS_CONFIRMED, '2026-03-02T09:00:00+00:00');
        $theirs = $this->seedWarehouse(self::TENANT_B, 'Their WH');
        $this->seedSalesOrder(self::TENANT_B, 'SO-870042', null, $theirs, SalesOrder::STATUS_CONFIRMED, '2026-03-03T09:00:00+00:00');
        $empty = $this->seedWarehouse(self::TENANT_A, 'Empty WH');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$empty]);

        $this->send('GET', '/pick-queue?warehouse_id='.$this->warehouse);
        self::assertSame([$mine], $this->ids());
        $this->send('GET', '/pick-queue?warehouse_id='.$other);
        self::assertSame([$elsewhere], $this->ids());

        foreach (['theirs' => $theirs, 'unknown' => Uuid::v4()->toRfc4122(), 'archived' => $empty] as $label => $id) {
            $this->send('GET', '/pick-queue?warehouse_id='.$id);
            self::assertSame(200, $this->httpStatus(), $label);
            self::assertSame(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => true], $this->json(), $label);
        }
    }

    /** T-5: a prefix of the order number, never an infix; `_` and `%` are characters. */
    #[Test]
    public function theSearchIsAnEscapedOrderNumberPrefix(): void
    {
        $this->world();
        $first = $this->order('SO-000123', SalesOrder::STATUS_CONFIRMED, '2026-03-01T09:00:00+00:00');
        $this->order('SO-100012', SalesOrder::STATUS_CONFIRMED, '2026-03-02T09:00:00+00:00');

        $this->send('GET', '/pick-queue?q=SO-0001');
        self::assertSame([$first], $this->ids());
        foreach (['0001', 'SO-_', 'SO-%25'] as $q) {
            $this->send('GET', '/pick-queue?q='.$q);
            self::assertSame(200, $this->httpStatus());
            self::assertSame([], $this->ids(), 'q='.$q.' matched.');
        }
        $this->send('GET', '/pick-queue?q=');
        self::assertCount(2, $this->ids(), 'An empty q is ignored.');
    }

    /**
     * T-6, money-free — derived from the server: every member the queue emits is held against every `numeric(19,4)` column
     * (PC-12 clause 1's domain). Positive control: the same walk over `GetSalesOrder`'s body finds `total_amount`.
     */
    #[Test]
    public function theQueueCarriesNoMoneyColumn(): void
    {
        $money = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT a.attname
              FROM pg_attribute a
              JOIN pg_class c ON c.oid = a.attrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped
               AND format_type(a.atttypid, a.atttypmod) = 'numeric(19,4)'
            SQL);
        self::assertContains('total_amount', $money, 'PC-12 clause 1\'s domain was not read from the catalogue.');

        $this->world();
        $id = $this->order('SO-870050', SalesOrder::STATUS_CONFIRMED, '2026-03-01T09:00:00+00:00');

        $this->send('GET', '/sales-orders/'.$id);
        self::assertContains('total_amount', array_intersect(self::memberNames($this->json()), $money),
            'The positive control failed: the walk found no money column even in GetSalesOrder\'s body.');

        $this->send('GET', '/pick-queue');
        $body = $this->json();
        self::assertNotSame([], $body['items'], 'The queue page walked was empty.');
        self::assertArrayHasKey('customer', $body['items'][0], 'The walked row carries no customer.');
        self::assertSame([], array_values(array_intersect(self::memberNames($body), $money)),
            'PC-12 clause 1: the pick queue projects a numeric(19,4) column.');
    }

    /** T-6, the docs half: PC-9's fifth exception names the gate and every column the queue projects. Skips without docs/. */
    #[Test]
    public function pc9sFifthExceptionNamesExactlyTheColumnsThisReadProjects(): void
    {
        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored; PC-9\'s exception is read from it.');
        }

        $text = (string) file_get_contents(SecurityModelDocument::path());
        $start = strpos($text, '**The fifth deliberate exception is the pick queue, `ListPickQueueEntries`');
        self::assertNotFalse($start, 'PC-9 no longer states its fifth deliberate exception.');
        $end = strpos($text, '**Violated by:**', $start);
        self::assertNotFalse($end);
        $exception = substr($text, $start, $end - $start);
        self::assertStringContainsString('`fulfilment.create` alone', $exception);

        $this->world();
        $this->order('SO-870060', SalesOrder::STATUS_CONFIRMED, '2026-03-01T09:00:00+00:00');
        $this->send('GET', '/pick-queue');
        $leaves = array_values(array_unique(self::leafNames($this->json()['items'])));
        sort($leaves);
        self::assertSame(['id', 'name', 'order_number', 'ordered_at', 'status'], $leaves);

        $missing = array_values(array_filter($leaves, static fn (string $leaf): bool => !str_contains($exception, '`'.$leaf.'`')));
        self::assertSame([], $missing, 'PC-9\'s fifth exception does not name every column the pick queue projects.');
    }

    /** T-7: `fulfilment.create` alone, enforced before the read (E-1). `admin` does not hold it (SoD-4). */
    #[Test]
    public function onlyAHolderOfFulfilmentCreateReadsTheQueue(): void
    {
        $this->world();
        $this->order('SO-870070', SalesOrder::STATUS_CONFIRMED, '2026-03-01T09:00:00+00:00');

        $allowed = [
            'owner' => DefaultRoles::codesFor(DefaultRoles::OWNER),
            'warehouse_operator' => DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR),
            'composed fulfilment.create only' => ['fulfilment.create'],
        ];
        foreach ($allowed as $label => $codes) {
            $this->actAs(self::TENANT_A, $codes);
            $this->send('GET', '/pick-queue');
            self::assertSame(200, $this->httpStatus(), $label);
            self::assertCount(1, $this->ids(), $label);
        }

        self::assertNotContains('sales_order.view', DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR),
            'The role this read exists for now holds sales_order.view; the PC-9 exception needs re-reading.');

        $refused = [
            'admin' => DefaultRoles::codesFor(DefaultRoles::ADMIN),
            'sales_rep' => DefaultRoles::codesFor(DefaultRoles::SALES_REP),
            'viewer' => DefaultRoles::codesFor(DefaultRoles::VIEWER),
            'composed fulfilment.view only' => ['fulfilment.view'],
        ];
        foreach ($refused as $label => $codes) {
            $this->actAs(self::TENANT_A, $codes);
            $this->send('GET', '/pick-queue');
            self::assertSame(403, $this->httpStatus(), $label);
            self::assertSame('fulfilment.create', $this->json()['required_permission'], $label);
        }
    }

    /**
     * T-8: `tenant_has_rows` is computed over the queue's own base view, never over `sales_order` — so a workspace holding
     * only drafts, then also only fulfilled, then also only cancelled orders (both kinds) answers `false` each time, and a
     * workspace with a queued order but an empty filter answers `true`.
     */
    #[Test]
    public function tenantHasRowsNeverDisclosesOrdersTheQueueDoesNotList(): void
    {
        $this->actAs(self::TENANT_B);
        $customer = $this->seedCustomer(self::TENANT_B, 'B Customer');
        $warehouse = $this->seedWarehouse(self::TENANT_B, 'B WH');
        $stages = [
            'drafts only' => [['SO-870080', SalesOrder::STATUS_DRAFT, null]],
            'and fulfilled' => [['SO-870081', SalesOrder::STATUS_FULFILLED, '2026-03-01T09:00:00+00:00']],
            'and cancelled, both kinds' => [['SO-870082', SalesOrder::STATUS_CANCELLED, '2026-03-02T09:00:00+00:00'], ['SO-870083', SalesOrder::STATUS_CANCELLED, null]],
        ];
        foreach ($stages as $label => $orders) {
            foreach ($orders as [$number, $status, $orderedAt]) {
                $this->seedSalesOrder(self::TENANT_B, $number, $customer, $warehouse, $status, $orderedAt);
            }
            $this->send('GET', '/pick-queue');
            self::assertSame(200, $this->httpStatus(), $label);
            self::assertSame(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => false], $this->json(), $label);
        }

        $this->seedSalesOrder(self::TENANT_B, 'SO-870084', $customer, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-03-03T09:00:00+00:00');
        $this->send('GET', '/pick-queue?q=SO-9');
        self::assertSame(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => true], $this->json());
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'warehouse_id not a uuid' => ['warehouse_id=nope', '?warehouse_id'];
        yield 'limit zero' => ['limit=0', '?limit'];
        yield 'limit over the maximum' => ['limit=201', '?limit'];
        yield 'both cursors' => ['after=x&before=y', '?after'];
        yield 'an undecodable cursor' => ['after=not-a-cursor', '?after'];
        yield 'a cursor keyed on an order number' => ['after='.new Cursor('SO-000001', '00000000-0000-4000-8000-000000000000')->encode(), '?after'];
        yield 'a cursor keyed on nothing' => ['before='.new Cursor('', '00000000-0000-4000-8000-000000000000')->encode(), '?before'];
        yield 'an undeclared sort' => ['sort=order_number', '?sort'];
        yield 'an undeclared status' => ['status=draft', '?status'];
    }

    /** T-9: each refusal is the house 422 at its parameter, never a 500. */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function aBadParameterIsRefusedAtItsPointer(string $query, string $pointer): void
    {
        $this->send('GET', '/pick-queue?'.$query);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertContains($pointer, array_column($this->json()['errors'] ?? [], 'pointer'), (string) $this->client()->getResponse()->getContent());
    }

    /** T-12 (PC-9 condition 3): an archived customer's open order is still queued, and still named. */
    #[Test]
    public function anArchivedCustomersOrderIsStillQueuedAndNamed(): void
    {
        $this->world();
        $id = $this->order('SO-870090', SalesOrder::STATUS_CONFIRMED, '2026-03-01T09:00:00+00:00');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$this->customer]);

        $this->send('GET', '/pick-queue');

        self::assertSame([$id], $this->ids());
        self::assertSame(['id' => $this->customer, 'name' => 'Queue Customer'], $this->json()['items'][0]['customer']);
    }

    // -----------------------------------------------------------------------

    private function world(): void
    {
        $this->customer = $this->seedCustomer(self::TENANT_A, 'Queue Customer');
        $this->warehouse = $this->seedWarehouse(self::TENANT_A, 'Queue WH');
    }

    private function order(string $number, string $status, ?string $orderedAt): string
    {
        return $this->seedSalesOrder(self::TENANT_A, $number, $this->customer, $this->warehouse, $status, $orderedAt);
    }

    /** @return list<string> */
    private function ids(): array
    {
        return array_column($this->json()['items'] ?? [], 'id');
    }

    /**
     * Walk the whole queue at `$limit`, forward by `next_cursor` and back from the last page by `previous_cursor`.
     *
     * @return array{list<string>, list<string>, list<?string>} forward ids, backward ids, every cursor emitted
     */
    private function walk(int $limit, bool $collect = false): array
    {
        $emitted = [];
        $forward = [];
        $cursor = null;
        $last = null;
        for ($guard = 0; $guard < 50; ++$guard) {
            $this->send('GET', '/pick-queue?limit='.$limit.(null === $cursor ? '' : '&after='.$cursor));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            array_push($forward, ...array_column($page['items'], 'id'));
            $emitted[] = $page['next_cursor'];
            $emitted[] = $page['previous_cursor'];
            $last = $page;
            $cursor = $page['next_cursor'];
            if (null === $cursor) {
                break;
            }
        }

        $backward = array_reverse(array_column($last['items'] ?? [], 'id'));
        $cursor = $last['previous_cursor'] ?? null;
        for ($guard = 0; null !== $cursor && $guard < 50; ++$guard) {
            $this->send('GET', '/pick-queue?limit='.$limit.'&before='.$cursor);
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            array_push($backward, ...array_reverse(array_column($page['items'], 'id')));
            $emitted[] = $page['next_cursor'];
            $emitted[] = $page['previous_cursor'];
            $cursor = $page['previous_cursor'];
        }

        return [$forward, $backward, $collect ? $emitted : []];
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private static function memberNames(array $body): array
    {
        $names = [];
        foreach ($body as $key => $value) {
            if (\is_string($key)) {
                $names[] = $key;
            }
            if (\is_array($value)) {
                array_push($names, ...self::memberNames($value));
            }
        }

        return $names;
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private static function leafNames(array $body): array
    {
        $names = [];
        foreach ($body as $key => $value) {
            if (\is_array($value)) {
                array_push($names, ...self::leafNames($value));
            } elseif (\is_string($key)) {
                $names[] = $key;
            }
        }

        return $names;
    }
}
