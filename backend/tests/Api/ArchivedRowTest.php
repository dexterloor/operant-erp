<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * API-13a and TB-3 (`docs/API.md` §5.2a, which §4.4.4 defers to) — an archived
 * row is read, not refused; it never leaks into a live list; and it is
 * read-only.
 *
 * ---------------------------------------------------------------------------
 * Domain — `SchemaManifest::TABLES`' `soft` entries, restricted to built paths
 * ---------------------------------------------------------------------------
 * The contract derives the domain from the manifest's `soft` tables. Owner
 * decision (e) under ruling A restricts it to the tables with **built**
 * operations, and this file derives that restriction rather than typing it:
 *
 * 1. every soft table is tied to its §4.2 resource by the resource's
 *    `Restore / <Resource>` row (`guard:<table>.deleted_at`), and the tie is held
 *    equal to `SchemaManifest::softDeletedTables()` in both directions first;
 * 2. every registry operation is tied to a resource by its own §4.2
 *    `Verb / Resource` cell (a `List` plural resolved as `ResponseShapeTest`
 *    resolves it);
 * 3. each test's subject operations are then chosen by the shape its clause
 *    names — a direct-hit `Get`; a `List`; a `PATCH`, a `DELETE`, or a `POST`
 *    transition (any verb but `Create`, which writes a new row, and `Restore`,
 *    which API-12a makes the one write whose subject *is* an archived row).
 *
 * A soft table with no subject operation for a clause is **excluded from that
 * clause, with its reason**, and every soft table is accounted for as included
 * or excluded — so none can drop out silently. Measured 2026-09-14 (C5 batch
 * B), the exclusions are the ones owner decision (e) predicted: eight soft tables
 * have no built operation at all; `app_user`'s only built read is `/me`, whose
 * cell names `CurrentUser`, not `User`; and `purchase_order_line` has no
 * built direct-hit `Get`, so it is in the list and write clauses only.
 *
 * The one per-table part is the fixture that seeds two live rows, because a row
 * cannot be seeded generically. Archiving one of them is generic —
 * `UPDATE <table> SET deleted_at = now()` — and the other stays live as each
 * clause's positive control. Fixtures are keyed by table and held equal to the
 * included tables inside each test.
 */
final class ArchivedRowTest extends PurchaseOrderTestCase
{
    private const BUSINESS_RULE = 'urn:erp:problem:business-rule-violated';

    /**
     * **API-13a — a `GET` on a soft-deleted row returns 200 with a non-null
     * `archived_at`, never 404.** Control inside: the live twin, read the same
     * way, carries a null `archived_at`, so the field is not constant.
     *
     * **Mutation (§5.2a's, C5 batch B, 2026-09-14; cache deleted — Rule 17):**
     * the soft-delete predicate applied to the direct-hit query; red, 404.
     * Recorded in `docs/session9/progress-C5B.md` row 13.
     */
    #[Test]
    public function aSoftDeletedRowIsReadableByDirectHit(): void
    {
        $subjects = $this->subjects(static fn (string $verb, HttpOperation $operation): bool => 'Get' === $verb);

        // Every fixture before the first request: a refused write closes the
        // entity manager the seeding helpers run through.
        $pairs = $this->pairs($subjects);

        $defects = [];
        foreach ($subjects as $table => $operations) {
            foreach ($operations as $name => $operation) {
                [$live, $archived] = $pairs[$name];

                $this->send('GET', self::path($operation, $live));
                self::assertSame(200, $this->httpStatus(), $name.' did not read the live twin.');
                $body = $this->json();
                self::assertTrue(\array_key_exists('archived_at', $body) && null === $body['archived_at'],
                    $name.'\'s live twin does not carry a null archived_at, so the check below proves nothing.');

                $this->send('GET', self::path($operation, $archived));
                if (200 !== $this->httpStatus()) {
                    $defects[] = \sprintf('%s on an archived %s row answered %d', $name, $table, $this->httpStatus());
                } elseif (null === ($this->json()['archived_at'] ?? null)) {
                    $defects[] = \sprintf('%s on an archived %s row returned a null or absent archived_at', $name, $table);
                }
            }
        }

        self::assertSame([], $defects, 'API-13a: an archived row is not readable by direct hit.');
    }

    /**
     * **TB-3 — no list returns a soft-deleted row when `archived` is absent or
     * false.** Each list is read with no `archived` parameter and, where the list
     * declares one, with `archived=false`. Control inside: the live twin is on
     * every one of those pages.
     *
     * **Mutation (§5.2a's second, C5 batch B, 2026-09-14; cache deleted — Rule
     * 17):** the soft-delete predicate dropped from a list query; red, naming
     * it. Recorded in `docs/session9/progress-C5B.md` row 14.
     */
    #[Test]
    public function aSoftDeletedRowIsAbsentFromEveryLiveList(): void
    {
        $subjects = $this->subjects(static fn (string $verb, HttpOperation $operation): bool => 'List' === $verb);

        // Every fixture before the first request: a refused write closes the
        // entity manager the seeding helpers run through.
        $pairs = $this->pairs($subjects);

        $defects = [];
        foreach ($subjects as $table => $operations) {
            foreach ($operations as $name => $operation) {
                [$live, $archived] = $pairs[$name];
                $queries = [''];
                if ((bool) $operation->getParameters()?->has('archived')) {
                    $queries[] = '?archived=false';
                }

                foreach ($queries as $query) {
                    $this->send('GET', self::path($operation, $live).$query);
                    self::assertSame(200, $this->httpStatus(), $name.$query.' did not answer.');
                    $ids = array_column($this->json()['items'] ?? [], 'id');

                    self::assertContains($live['row'], $ids, $name.$query.' does not list the live twin, so an absent archived row proves nothing.');
                    if (\in_array($archived['row'], $ids, true)) {
                        $defects[] = \sprintf('%s%s returns an archived %s row', $name, $query, $table);
                    }
                }
            }
        }

        self::assertSame([], $defects, 'TB-3: a live list returns a soft-deleted row.');
    }

    /**
     * **ST-9 — a `PATCH`, `DELETE` or transition on a soft-deleted row is
     * refused as `business-rule-violated`.** Control inside: the same request on
     * the live twin succeeds, so the refusal is the archived state's and not the
     * request's.
     *
     * **Mutation (C5 batch B, 2026-09-14; cache deleted — Rule 17):** the
     * archived guard removed from one writer; red, naming the operation.
     * Recorded in `docs/session9/progress-C5B.md` row 15.
     */
    #[Test]
    public function aSoftDeletedRowRefusesEveryWrite(): void
    {
        $subjects = $this->subjects(static fn (string $verb, HttpOperation $operation): bool => \in_array($operation->getMethod(), ['PATCH', 'DELETE'], true)
            || ('POST' === $operation->getMethod() && !\in_array($verb, ['Create', 'Restore'], true)));

        $bodies = self::writeBodies();
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
        $pairs = $this->pairs($subjects);

        $defects = [];
        foreach ($subjects as $table => $operations) {
            foreach ($operations as $name => $operation) {
                [$live, $archived] = $pairs[$name];
                $method = (string) $operation->getMethod();

                $this->send($method, self::path($operation, $live), $bodies[$name]);
                self::assertLessThan(300, $this->httpStatus(), \sprintf('%s on the live twin was refused (%d), so a refusal of the archived row proves nothing: %s',
                    $name, $this->httpStatus(), substr((string) $this->client()->getResponse()->getContent(), 0, 300)));

                $this->send($method, self::path($operation, $archived), $bodies[$name]);
                $content = (string) $this->client()->getResponse()->getContent();
                $type = json_decode($content, true)['type'] ?? null;
                if (self::BUSINESS_RULE !== $type) {
                    $defects[] = \sprintf('%s on an archived %s row answered %d %s', $name, $table, $this->httpStatus(), substr($content, 0, 200));
                }
            }
        }

        self::assertSame([], $defects, 'ST-9: a write on a soft-deleted row was not refused as a business rule.');
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
    private function subjects(callable $shape): array
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

        $fixtures = array_keys($this->fixtures());
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
    private function pairs(array $subjects): array
    {
        $pairs = [];
        foreach ($subjects as $table => $operations) {
            foreach (array_keys($operations) as $name) {
                $pairs[$name] = $this->pair($table);
            }
        }

        return $pairs;
    }

    /**
     * Two live rows of a soft table, the second then archived.
     *
     * @return array{0: array<string, string>, 1: array<string, string>} each a URI-variable map plus `row`, the row's id
     */
    private function pair(string $table): array
    {
        $live = $this->fixtures()[$table]('L');
        $archived = $this->fixtures()[$table]('A');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE '.$table.' SET deleted_at = now() WHERE id = ?::uuid', [$archived['row']]);

        return [$live, $archived];
    }

    /**
     * One live row per soft table with built operations, as the URI variables
     * every path on it takes plus `row`.
     *
     * @return array<string, callable(string): array<string, string>>
     */
    private function fixtures(): array
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
            'purchase_order_line' => function (string $tag): array {
                $draft = $this->seedDraft(self::TENANT_A, 'PO-ARC-LINE-'.$tag.'-'.bin2hex(random_bytes(3)));
                $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

                return ['id' => $draft['order'], 'lineId' => $line, 'row' => $line];
            },
        ];
    }

    /**
     * A request body that succeeds on a live row, per write operation. Held equal
     * to the derived write domain inside the test.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private static function writeBodies(): array
    {
        return [
            'ApprovePurchaseOrder' => [],
            'CancelPurchaseOrder' => ['reason' => 'Supplier withdrew'],
            'DeleteItem' => null,
            'DeletePurchaseOrder' => null,
            'DeletePurchaseOrderLine' => null,
            'UpdateItem' => ['name' => 'Renamed'],
            'UpdatePurchaseOrder' => ['expected_at' => '2030-01-01T00:00:00Z'],
            'UpdatePurchaseOrderLine' => ['quantity' => '2'],
        ];
    }

    /** @param array<string, string> $variables */
    private static function path(HttpOperation $operation, array $variables): string
    {
        return (string) preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($operation, $variables): string {
            self::assertArrayHasKey($m[1], $variables, $operation->getName().'\'s path takes {'.$m[1].'} and the fixture does not provide it.');

            return $variables[$m[1]];
        }, (string) $operation->getUriTemplate());
    }
}
