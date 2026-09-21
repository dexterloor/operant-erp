<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\ContractDocument;

/**
 * The soft-deleted-row domain of `ArchivedRowTest` (API-13a, TB-3, ST-9), its
 * fixtures, and clause 15's write requests driven for real — held once, so every
 * consumer reads the same derivation instead of a copy.
 *
 * Moved here from `ArchivedRowTest` by V4 B5 (V1-C1a, the Q3 raisability probe),
 * when `ErrorContractTest` half 2 began feeding clause 15's real
 * `business-rule-violated` refusals to its detector as a fourth probe. **A second
 * copy of this derivation is the defect B5's *fails if* names**: a write added to
 * the domain would then reach one consumer and not the other.
 *
 * ---------------------------------------------------------------------------
 * Domain — `SchemaManifest::TABLES`' `soft` entries, restricted to built paths
 * ---------------------------------------------------------------------------
 * The contract derives the domain from the manifest's `soft` tables. Owner
 * decision (e) under ruling A restricts it to the tables with **built**
 * operations, and this derivation reaches that restriction rather than typing it:
 *
 * 1. every soft table is tied to its §4.2 resource by the resource's
 *    `Restore / <Resource>` row (`guard:<table>.deleted_at`), and the tie is held
 *    equal to `SchemaManifest::softDeletedTables()` in both directions first;
 * 2. every registry operation is tied to a resource by its own §4.2
 *    `Verb / Resource` cell (a `List` plural resolved as `ResponseShapeTest`
 *    resolves it);
 * 3. each clause's subject operations are then chosen by the shape its clause
 *    names — a direct-hit `Get`; a `List`; a `PATCH`, a `DELETE`, or a `POST`
 *    transition (any verb but `Create`, which writes a new row, and `Restore`,
 *    which API-12a makes the one write whose subject *is* an archived row).
 *
 * A soft table with no subject operation for a clause is **excluded from that
 * clause, with its reason**, and every soft table is accounted for as included
 * or excluded — so none can drop out silently. The exclusions are the ones owner
 * decision (e) predicted: the soft tables with no built operation at all;
 * `app_user`'s only built read is `/me`, whose cell names `CurrentUser`, not
 * `User`; and `purchase_order_line` has no built direct-hit `Get`, so it is in
 * the list and write clauses only.
 *
 * ⚠ **This paragraph carried a COUNT — *"eight soft tables have no built
 * operation at all"* — measured 2026-09-14 and never moved again. It was already
 * false before `GetSalesOrder`** (`CreateSalesOrder` had shipped, so `sales_order`
 * had a built operation and was excluded for the other reason), and the read made
 * it falser. **The number is deleted rather than corrected**: it is derived on
 * every run by the assertions below, which name each excluded table and its
 * reason in their own failure messages, so a second copy here was a set nobody
 * maintained. Read the derivation, never this comment.
 *
 * The one per-table part is the fixture that seeds two live rows, because a row
 * cannot be seeded generically. Archiving one of them is generic —
 * `UPDATE <table> SET deleted_at = now()` — and the other stays live as each
 * clause's positive control. Fixtures are keyed by table and held equal to the
 * included tables inside each derivation.
 *
 * **Database dependency.** Every consumer seeds rows in `TENANT_A` as the schema
 * owner (`insertAsOwnerForTenant`, `seedItem`, `seedDraft`, `seedLine`), so a
 * consumer must extend `PurchaseOrderTestCase` and needs the migrated test
 * database — which, since B5, includes `ErrorContractTest`.
 *
 * @phpstan-require-extends PurchaseOrderTestCase
 */
trait ArchivedRowSubjects
{
    /**
     * **Clause 15's requests (ST-9), driven once for every consumer.** For each
     * write of the derived domain: the request on the live twin, which must
     * succeed (so a refusal of the archived row is the archived state's and not
     * the request's), then the same request on the archived row, whose answer is
     * returned unjudged — `ArchivedRowTest` judges it against ST-9,
     * `ErrorContractTest` against §5.2's `Errors` cells.
     *
     * Every body is held equal to the derived domain first, and the domain is
     * non-empty (`archivedRowSubjects()`), so neither consumer can receive an
     * empty set silently.
     *
     * @return array<string, array{table: string, status: int, contentType: string, content: string}> operation name => the archived row's answer
     */
    private function archivedRowWriteAnswers(): array
    {
        $subjects = $this->archivedRowSubjects(static fn (string $verb, HttpOperation $operation): bool => \in_array($operation->getMethod(), ['PATCH', 'DELETE'], true)
            || ('POST' === $operation->getMethod() && !\in_array($verb, ['Create', 'Restore'], true)));

        $bodies = self::archivedRowWriteBodies();
        $driven = [];
        foreach ($subjects as $operations) {
            $driven = [...$driven, ...array_keys($operations)];
        }
        sort($driven);
        $bodyNames = array_keys($bodies);
        sort($bodyNames);
        self::assertSame($driven, $bodyNames, 'Every write in the domain must have a request body here, and only those.');

        // Every fixture before the first request: a refused write closes the
        // entity manager the seeding helpers run through.
        $pairs = $this->archivedRowPairs($subjects);

        $answers = [];
        foreach ($subjects as $table => $operations) {
            foreach ($operations as $name => $operation) {
                [$live, $archived] = $pairs[$name];
                $method = (string) $operation->getMethod();

                $this->send($method, self::archivedRowPath($operation, $live), $bodies[$name]);
                self::assertLessThan(300, $this->httpStatus(), \sprintf('%s on the live twin was refused (%d), so a refusal of the archived row proves nothing: %s',
                    $name, $this->httpStatus(), substr((string) $this->client()->getResponse()->getContent(), 0, 300)));

                $this->send($method, self::archivedRowPath($operation, $archived), $bodies[$name]);
                $response = $this->client()->getResponse();
                $answers[$name] = [
                    'table' => $table,
                    'status' => $this->httpStatus(),
                    'contentType' => (string) $response->headers->get('Content-Type'),
                    'content' => (string) $response->getContent(),
                ];
            }
        }

        return $answers;
    }

    /**
     * The derived domain for one clause: soft table → the registry operations
     * on its resource that the clause's shape selects. Every soft table is
     * included or excluded with a reason; the partition and a non-empty
     * inclusion are asserted here, so every caller carries both controls.
     *
     * @param callable(string, HttpOperation): bool $shape the verb and operation a clause names
     *
     * @return array<string, array<string, HttpOperation>>
     */
    private function archivedRowSubjects(callable $shape): array
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; the soft table → resource tie is §4.2\'s Restore rows.');
        }

        $soft = SchemaManifest::softDeletedTables();
        $tie = ContractDocument::restoreGuardTables();
        $tied = array_values($tie);
        sort($tied);
        $manifest = $soft;
        sort($manifest);
        self::assertNotSame([], $manifest, 'SchemaManifest declares no soft table, so the domain is vacuous.');
        self::assertSame($manifest, $tied, '§4.2\'s Restore guards and SchemaManifest\'s soft tables disagree, so no soft table can be tied to a resource.');

        $rows = ContractDocument::operationRows();
        $known = ResponseShapeTest::knownResources($rows, []);
        $builtOnResource = [];
        $selected = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            self::assertArrayHasKey($name, $rows, $name.' has no §4.2 row, so its resource cannot be named.');
            [$verb, $noun] = explode(' / ', $rows[$name]['verbResource']);
            $resource = 'List' === $verb ? ResponseShapeTest::resourceOf($rows[$name]['verbResource'], $known) : $noun;

            if (!\array_key_exists($resource, $tie)) {
                continue;
            }
            $builtOnResource[$tie[$resource]][] = $name;
            if ($shape($verb, $operation)) {
                $selected[$tie[$resource]][$name] = $operation;
            }
        }

        $excluded = [];
        foreach ($soft as $table) {
            if (isset($selected[$table])) {
                continue;
            }
            $excluded[$table] = isset($builtOnResource[$table])
                ? 'built operations ['.implode(', ', $builtOnResource[$table]).'] include none of this clause\'s shape'
                : 'no built operation names its resource '.array_search($table, $tie, true);
        }

        $accounted = [...array_keys($selected), ...array_keys($excluded)];
        sort($accounted);
        self::assertSame($manifest, $accounted, 'A soft table is neither included nor excluded.');
        self::assertNotSame([], $selected, 'No soft table has a built operation of this clause\'s shape. Excluded: '.json_encode($excluded));

        $fixtures = array_keys($this->archivedRowFixtures());
        $needed = array_keys($selected);
        self::assertSame([], array_diff($needed, $fixtures), 'An included soft table has no fixture. Excluded, with reasons: '.json_encode($excluded));
        self::assertSame([], array_diff($fixtures, array_keys($builtOnResource)), 'A fixture names a soft table with no built operation.');

        ksort($selected);

        return $selected;
    }

    /**
     * A pair per subject operation, all seeded before any request is sent.
     *
     * @param array<string, array<string, HttpOperation>> $subjects
     *
     * @return array<string, array{0: array<string, string>, 1: array<string, string>}>
     */
    private function archivedRowPairs(array $subjects): array
    {
        $pairs = [];
        foreach ($subjects as $table => $operations) {
            foreach (array_keys($operations) as $name) {
                $pairs[$name] = $this->archivedRowPair($table);
            }
        }

        return $pairs;
    }

    /**
     * Two live rows of a soft table, the second then archived.
     *
     * @return array{0: array<string, string>, 1: array<string, string>} each a URI-variable map plus `row`, the row's id
     */
    private function archivedRowPair(string $table): array
    {
        $live = $this->archivedRowFixtures()[$table]('L');
        $archived = $this->archivedRowFixtures()[$table]('A');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE '.$table.' SET deleted_at = now() WHERE id = ?::uuid', [$archived['row']]);

        return [$live, $archived];
    }

    /**
     * One live row per soft table with built operations, as the URI variables
     * every path on it takes plus `row`.
     *
     * @return array<string, callable(string): array<string, string>>
     */
    private function archivedRowFixtures(): array
    {
        return [
            'inventory_item' => function (string $tag): array {
                $item = $this->seedItem(self::TENANT_A, 'SKU-ARC-'.$tag.'-'.bin2hex(random_bytes(3)));

                return ['id' => $item, 'row' => $item];
            },
            'purchase_order' => function (string $tag): array {
                $draft = $this->seedDraft(self::TENANT_A, 'PO-ARC-'.$tag.'-'.bin2hex(random_bytes(3)));
                // A line, so approving the live twin is not refused for having none.
                $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

                return ['id' => $draft['order'], 'row' => $draft['order']];
            },
            // Added 2026-09-19 with `GetSalesOrder`: `sales_order` is class `soft` and has a built
            // direct-hit `Get`, so the Get clause selects it. ⚠ **It was in that clause ONLY until
            // `ConfirmSalesOrder` shipped later the same day**, and the sentence that said so is replaced
            // rather than left standing: a `POST` transition on this resource now exists, so the write
            // clause selects it too — which is why the fixture gained a line. Without one, confirming the
            // **live twin** is refused by the line guard, and this trait asserts the live twin succeeds
            // precisely so that a refusal of the archived row is the archived state's doing.
            'sales_order' => function (string $tag): array {
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-ARC-'.$tag.'-'.bin2hex(random_bytes(3)));
                $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-ARC-SO-'.$tag.'-'.bin2hex(random_bytes(3))));

                return ['id' => $order, 'row' => $order];
            },
            // Added 2026-09-21 with `ListSalesOrderLines`. ⚠ **The sentence that stood here — *"There is
            // no built direct-hit `Get` and no built write on this resource, so it is in the list clause
            // only — which is why the fixture returns the order as `id` and the line as `row`"* — stopped
            // being true the same week**: `UpdateSalesOrderLine` is a built write at
            // `/sales-orders/{id}/lines/{lineId}`, so the write clause selects this table too and
            // `archivedRowPath()` now has a `{lineId}` to substitute. **The `lineId` key is added here in
            // that commit**, mirroring `purchase_order_line`'s. The failure would have been loud either
            // way -- `archivedRowPath()` asserts the key exists -- which is one point in its favour and
            // not a reason to have left it.
            'sales_order_line' => function (string $tag): array {
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-ARC-LINE-'.$tag.'-'.bin2hex(random_bytes(3)));
                $line = $this->seedSalesOrderLine(self::TENANT_A, $order,
                    $this->seedItem(self::TENANT_A, 'SKU-ARC-SL-'.$tag.'-'.bin2hex(random_bytes(3))));

                return ['id' => $order, 'lineId' => $line, 'row' => $line];
            },
            'purchase_order_line' => function (string $tag): array {
                $draft = $this->seedDraft(self::TENANT_A, 'PO-ARC-LINE-'.$tag.'-'.bin2hex(random_bytes(3)));
                $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

                return ['id' => $draft['order'], 'lineId' => $line, 'row' => $line];
            },
        ];
    }

    /**
     * A request body that succeeds on a live row, per write operation. Held equal
     * to the derived write domain inside `archivedRowWriteAnswers()`.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private static function archivedRowWriteBodies(): array
    {
        return [
            'ApprovePurchaseOrder' => [],
            'CancelPurchaseOrder' => ['reason' => 'Supplier withdrew'],
            // `null`, not `[]`: `ConfirmSalesOrder` declares `deserialize: false`, and an empty JSON
            // object would be sent with a `Content-Type` the operation never reads. It accepts nothing
            // (§4.2's `Accepted request fields` is `—`), so the request carries nothing.
            'ConfirmSalesOrder' => null,
            // `reason` rather than `null`: unlike the confirmation above, §4.2 accepts a field here and
            // the operation reads a body. The refusal under test is the archived one, which fires before
            // `cancel()` and before the audit write, so the reason never reaches `metadata` on this path.
            'CancelSalesOrder' => ['reason' => 'Customer withdrew'],
            'DeleteItem' => null,
            'DeletePurchaseOrder' => null,
            'DeletePurchaseOrderLine' => null,
            'UpdateItem' => ['name' => 'Renamed'],
            'UpdatePurchaseOrder' => ['expected_at' => '2030-01-01T00:00:00Z'],
            // ⚠ **An EMPTY body, and the reason is a control in this very trait rather than laziness.**
            // All three of `UpdateSalesOrder`'s fields are ids of seeded rows, and these bodies are
            // `static` -- so any id spelled here is one no fixture owns. The first attempt sent a
            // literal uuid and the trait refused it correctly: *"UpdateSalesOrder on the live twin was
            // refused (422), so a refusal of the archived row proves nothing"*. An empty PATCH is a
            // legal request (`submitted()` is empty, no column is written, the row is read back 200),
            // so the live twin succeeds and the archived twin's 409 is the archived state's alone.
            //
            // **What this case therefore does NOT prove, said rather than left to be assumed:** that
            // the archived guard runs *before* reference resolution. That ordering is proved by
            // `SalesOrderWriteTest::anArchivedOrderIsRefusedBeforeAnyReferenceIsResolved`, which sends
            // an unresolvable `warehouse_id` at an archived order and demands
            // `sales_order.is_archived` rather than `unknown_reference`.
            'UpdateSalesOrder' => [],
            'UpdatePurchaseOrderLine' => ['quantity' => '2'],
            // These bodies are `static`, so no seeded id may appear in one. A quantity is all this
            // operation needs, and the live twin must SUCCEED with it -- which is the trait's own control
            // that a refusal of the archived row is the archived state's doing and not the body's.
            'UpdateSalesOrderLine' => ['quantity' => '2'],
            // A `DELETE` carries no body, and the trait's selector puts it in the domain anyway.
            'DeleteSalesOrderLine' => null,
        ];
    }

    /** @param array<string, string> $variables */
    private static function archivedRowPath(HttpOperation $operation, array $variables): string
    {
        return (string) preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($operation, $variables): string {
            self::assertArrayHasKey($m[1], $variables, $operation->getName().'\'s path takes {'.$m[1].'} and the fixture does not provide it.');

            return $variables[$m[1]];
        }, (string) $operation->getUriTemplate());
    }
}
