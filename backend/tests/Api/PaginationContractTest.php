<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * API-3's total clause (`docs/API.md` §2.1) and API-17/API-18 (§10.1) — no
 * keyset list reports a total, no list query emits `OFFSET`, and every cursor
 * carries the sort key and the id.
 *
 * ---------------------------------------------------------------------------
 * Domain
 * ---------------------------------------------------------------------------
 * **Every list operation in the registry**, from {@see ListOperationFixtures}:
 * each `GET` in `TransactionBoundaryTest::contractOperations()` whose verb is
 * `List`, held equal to the fixtures on every call. §2.1 says *"every list
 * operation in the table of §4"*; a §4.2 row that is not built has nothing to
 * drive, so the built rows are the domain, and a list built tomorrow joins it
 * without this file being edited. Which lists page is read off the operation
 * (it declares `limit`/`after`), and where a test needs §4.2's `*(bounded)*`
 * marking the two are held equal first.
 *
 * Each method holds its controls inside itself: the domain is non-empty, a
 * planted positive fires the detector, and — where there is a second source —
 * the domain equals it.
 */
final class PaginationContractTest extends PurchaseOrderTestCase
{
    use ListOperationFixtures;

    /** Rows each fixture seeds: enough for a page of one to have a successor and a predecessor. */
    private const ROWS = 3;

    /** API-3's three names. */
    private const TOTAL_MEMBERS = ['total', 'totalItems', 'hydra:totalItems'];

    /**
     * **API-3 / TB-2b — no response declares `application/ld+json`, and no list
     * response carries `total`, `totalItems` or `hydra:totalItems` unless its
     * path is `(bounded)`.** Every list is read on its default view, on every
     * page of a one-row walk where it pages, and once with
     * `Accept: application/ld+json`; every body is walked at every depth, so a
     * total tucked inside a nested member is found too.
     *
     * **The bounded exception is §4.2's `*(bounded)*` marking**, which API-19a's
     * exclusion 2 says is the sort manifest's `(bounded, TB-1c` rows by
     * construction — and `ListPurchaseOrderLines`, the one bounded built list, has
     * no manifest row at all, so the §4.2 marking is the only source that decides
     * it. It is held equal, both directions, to *"the operation declares no
     * `limit`"* before it is trusted.
     *
     * Controls inside this method: the domain is non-empty and every list in it is
     * a §4.2 `List` row; at least one list is keyset (not bounded), so the
     * absence is asserted somewhere; the walker finds a planted top-level `total`
     * and a planted nested `hydra:totalItems`, and does not flag `total_amount`.
     *
     * **Mutations (C5 batch C, 2026-09-15; cache deleted — Rule 17):**
     * `ItemCollection` gains `public int $total = 0` → red naming
     * `ListItems … $.total`; the same member on `PurchaseOrderLineCollection` (the
     * bounded list) → **green here**, which is the exception applied rather than
     * declared. Recorded in `docs/session10/progress-C5C.md` row 17.
     *
     * **Residual non-coverage:** a total under a name other than the three (e.g.
     * `count`) — §2.1 names three and `ItemListTest`/`PurchaseOrderListTest`
     * name `page`, `offset` and `count` per list; a `COUNT(*)` query behind a
     * keyset list that never reaches the body (TB-2b's query half, not API-3's).
     */
    #[Test]
    public function noKeysetListReportsATotal(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; the (bounded) exception is §4.2\'s marking and has nothing to read without it.');
        }

        $planted = [];
        self::walkForTotals(['items' => [['total_amount' => '1.00', 'lines' => [['hydra:totalItems' => 2]]]], 'total' => 5], '$', $planted);
        self::assertSame(['$.items[0].lines[0].hydra:totalItems', '$.total'], $planted, 'The walker did not find exactly the two planted totals.');

        $lists = $this->listOperations();
        $rows = ContractDocument::operationRows();

        $bounded = [];
        foreach ($lists as $name => $operation) {
            self::assertMatchesRegularExpression('/^List \//', $rows[$name]['verbResource'] ?? '', $name.' is not a §4.2 List row.');
            $bounded[$name] = ContractDocument::isBounded($name);
            self::assertSame($bounded[$name], !\in_array('limit', self::declaredParameters($operation), true),
                $name.': §4.2\'s (bounded) marking and "declares no limit" disagree, so which exception applies is undecided.');
        }
        self::assertContains(false, $bounded, 'Every built list is bounded, so the absence of a total was asserted on nothing.');

        $fixtures = $this->listFixtures();
        $defects = [];

        foreach ($lists as $name => $operation) {
            [$path, $seed] = $fixtures[$name]();
            $seed(self::ROWS);

            $bodies = [];
            $this->send('GET', $path);
            $bodies['default view'] = [$this->httpStatus(), $this->contentType(), $this->json()];

            if (!$bounded[$name]) {
                $cursor = null;
                $page = 0;
                do {
                    $this->send('GET', $path.'?limit=1'.(null === $cursor ? '' : '&after='.urlencode($cursor)));
                    $body = $this->json();
                    $bodies['page '.++$page] = [$this->httpStatus(), $this->contentType(), $body];
                    $cursor = $body['next_cursor'] ?? null;
                } while (null !== $cursor && $page <= self::ROWS);
                self::assertGreaterThan(1, $page, $name.' did not page, so a page after the first was never examined.');
            }

            $this->send('GET', $path, null, [], 'application/ld+json');
            if (str_contains($this->contentType(), 'ld+json')) {
                $defects[] = $name.': Accept application/ld+json answered '.$this->contentType();
            }

            foreach ($bodies as $label => [$status, $contentType, $body]) {
                self::assertSame(200, $status, $name.' '.$label.' did not answer 200.');
                if (str_contains($contentType, 'ld+json')) {
                    $defects[] = \sprintf('%s %s declares %s', $name, $label, $contentType);
                }
                if ($bounded[$name]) {
                    continue;
                }
                $found = [];
                self::walkForTotals($body, '$', $found);
                foreach ($found as $where) {
                    $defects[] = \sprintf('%s %s carries %s on a path §4.2 does not mark (bounded)', $name, $label, $where);
                }
            }
        }

        self::assertSame([], $defects, 'API-3 / TB-2b: a keyset list reports a total, or a response declares application/ld+json.');
    }

    /**
     * **API-17 / P-3 — no list query emits `OFFSET`**, recorded at the driver
     * (`SqlRecorder`) over every request a list answers here: each fixture view
     * paged at one row to its end, a `before` step back from page two, and one
     * request with every filter the list declares (`q`, `supplier_id`,
     * `archived`). Bounded lists are in the domain: they take no `limit`, and an
     * `OFFSET` there is still offset pagination.
     *
     * **The detector reads the SQL, not a substring.** Comments and quoted
     * literals / identifiers are blanked first (one left-to-right pass, so a
     * `--` inside a literal hides nothing), then `\bOFFSET\b` case-insensitive —
     * which is also a match across a newline or a tab — or MySQL's
     * `LIMIT x, y`. A constant carries no special case: the recorder sees the
     * statement the constant was concatenated into.
     *
     * Controls inside this method: the domain is non-empty; every list recorded at
     * least one statement with a `FROM`, so the scan had a subject per operation;
     * seven planted positives fire (lower case, across newlines, a tab, a comment
     * between `LIMIT` and `OFFSET`, `LIMIT 10, 5`, `LIMIT ?, ?`, `OFFSET … ROWS
     * FETCH NEXT`, and a literal containing `--` before a real `OFFSET`) and six
     * planted negatives do not (the word inside a literal, a line comment, a block
     * comment, a quoted identifier, `offset_days`, and `LIMIT 5`).
     *
     * **Mutation (C5 batch C, 2026-09-15; cache deleted — Rule 17):**
     * `ItemReader` gains `private const PAGE = "\n offset 0"` appended after
     * `LIMIT ?` — a constant, lower case, across a newline → red naming
     * `ListItems`. Recorded in `docs/session10/progress-C5C.md` row 18.
     *
     * **Residual non-coverage:** a statement that does not pass through the
     * recording driver middleware (a connection built outside the container);
     * Postgres dollar-quoted strings (`$$ … $$`) and `E'…\''` escapes, which the
     * blanking pass does not model — the first could hide an `OFFSET`, the second
     * could cause a false red; a query path a list only takes on parameter values
     * not driven here (e.g. `sort` values outside the fixture views). **And offset
     * pagination spelt without the keyword** (V1-R-10, recorded 2026-09-16, V4 B4e;
     * from the V1 RED review, not re-measured here): `ROW_NUMBER() OVER (…)` with
     * `WHERE rn > ?`, and `FETCH ABSOLUTE` / `MOVE` on a server-side cursor. Owner:
     * BE-LEAD. Trigger to revisit: the first list query that uses a window function.
     */
    #[Test]
    public function noListQueryEmitsOffset(): void
    {
        foreach ([
            "SELECT 1 FROM t LIMIT 5 offset 10",
            "SELECT 1 FROM t LIMIT 5\nOFFSET\n10",
            "SELECT 1 FROM t LIMIT 5\tOFFSET 10",
            'SELECT 1 FROM t LIMIT 5 /* page two */ OFFSET 10',
            'SELECT 1 FROM t LIMIT 10, 5',
            'SELECT 1 FROM t LIMIT ?, ?',
            'SELECT 1 FROM t OFFSET 10 ROWS FETCH NEXT 5 ROWS ONLY',
            "SELECT '--' AS x FROM t OFFSET 5",
        ] as $positive) {
            self::assertTrue(self::emitsOffset($positive), 'The detector missed a planted offset: '.json_encode($positive));
        }
        foreach ([
            "SELECT 'OFFSET 5' FROM t",
            "SELECT 1 FROM t -- OFFSET 5",
            'SELECT 1 FROM t /* OFFSET 5 */',
            'SELECT "offset" FROM t',
            'SELECT offset_days FROM t',
            'SELECT 1 FROM t LIMIT 5',
        ] as $negative) {
            self::assertFalse(self::emitsOffset($negative), 'The detector flagged a statement with no offset: '.json_encode($negative));
        }

        $lists = $this->listOperations();
        $fixtures = $this->listFixtures();
        $defects = [];

        foreach ($lists as $name => $operation) {
            [$path, $seed] = $fixtures[$name]();
            $context = $seed(self::ROWS);
            $declared = self::declaredParameters($operation);
            $pages = \in_array('limit', $declared, true);

            $this->recorder()->start();

            foreach ($context['views'] as $view) {
                $cursor = null;
                $page = 0;
                do {
                    $query = array_filter([$view, $pages ? 'limit=1' : '', null === $cursor ? '' : 'after='.urlencode($cursor)]);
                    $this->send('GET', $path.([] === $query ? '' : '?'.implode('&', $query)));
                    self::assertSame(200, $this->httpStatus(), $name.' did not answer '.implode('&', $query));
                    $body = $this->json();
                    ++$page;
                    if (2 === $page && \is_string($body['previous_cursor'] ?? null)) {
                        $this->send('GET', $path.'?'.implode('&', array_filter([$view, 'limit=1', 'before='.urlencode($body['previous_cursor'])])));
                        self::assertSame(200, $this->httpStatus(), $name.' did not answer its before step.');
                    }
                    $cursor = $body['next_cursor'] ?? null;
                } while (null !== $cursor && $page <= self::ROWS);
            }

            $filters = [];
            if (\in_array('q', $declared, true) && null !== $context['search']) {
                $filters[] = 'q='.urlencode($context['search']);
            }
            if (\in_array('supplier_id', $declared, true) && null !== $context['supplier']) {
                $filters[] = 'supplier_id='.$context['supplier'];
            }
            if ([] !== $filters) {
                $this->send('GET', $path.'?'.implode('&', $filters));
                self::assertSame(200, $this->httpStatus(), $name.' did not answer its filters.');
            }
            if (\in_array('archived', $declared, true)) {
                $this->send('GET', $path.'?archived=true');
                self::assertSame(200, $this->httpStatus(), $name.' did not answer archived=true.');
            }

            $this->recorder()->stop();
            $statements = $this->recorder()->statements();

            self::assertNotSame([], array_filter($statements, static fn (string $sql): bool => 1 === preg_match('/\bFROM\b/i', $sql)),
                $name.' recorded no read, so the scan had no subject for it.');

            foreach ($statements as $sql) {
                if (self::emitsOffset($sql)) {
                    $defects[] = $name.': '.$sql;
                }
            }
        }

        self::assertSame([], $defects, 'API-17: a list query emits OFFSET.');
    }

    /**
     * **API-18 / TB-2a — a cursor decodes to exactly two members: the id of the
     * row at the page boundary, and that row's sort key.** Checked on
     * `next_cursor` (the last row of page one) and on `previous_cursor` (the first
     * row of page two), on every view the list's fixture names.
     *
     * Where the view names its order (`sort=f`), the non-id member must equal the
     * row's `f`; where it does not, it must equal some non-id scalar member of the
     * row — the default order is not named on the wire, and naming it here would
     * restate a provider. Timestamps compare as instants, because the cursor holds
     * the value Postgres rendered and the body holds §4.4's format.
     *
     * Controls inside this method: at least one list pages; the `sort` values the
     * fixture views name are exactly the operation's declared `sort` enum (so
     * every declared order is decoded, and a list with no `sort` names none);
     * every view's page one carried a `next_cursor`, and a page two that refuses
     * it is recorded as a defect of that cursor; the detector refuses a
     * planted sort-key-only cursor, a three-member cursor, a cursor whose key is
     * the wrong column, and one whose key matches no member, and accepts a
     * well-formed one.
     *
     * **Mutations (C5 batch C, 2026-09-15; cache deleted — Rule 17):** §10.1's
     * own — `Cursor::encode()` drops `i` → red naming every list;
     * `PurchaseOrderReader::cursors()` always reads `order_number` → red naming
     * `ListPurchaseOrders` on `status=open&sort=ordered_at`. Recorded in
     * `docs/session10/progress-C5C.md` row 19.
     *
     * **Residual non-coverage:** a decoded value containing a tenant identifier is
     * row 12's (`ResponseShapeTest::noResponseCarriesATenantIdentifier`); the
     * tie-group consequence §10.1's mutation names is
     * `PurchaseOrderListTest::aTieGroupIsPagedWithoutSkippingOrRepeating`, per list;
     * on a default view, a key equal to a non-sort member of the same value
     * passes.
     */
    #[Test]
    public function everyCursorCarriesTheSortKeyAndTheId(): void
    {
        $row = ['id' => '00000000-0000-4000-8000-000000000001', 'sku' => 'SKU-A', 'name' => 'Alpha'];
        self::assertNull(self::cursorDefect(['k' => 'SKU-A', 'i' => $row['id']], $row, 'sku'), 'The detector refused a well-formed cursor.');
        self::assertNull(self::cursorDefect(['k' => 'Alpha', 'i' => $row['id']], $row, null), 'The detector refused a default-view key matching a member.');
        foreach ([
            'sort key only' => [['k' => 'SKU-A'], 'sku'],
            'three members' => [['k' => 'SKU-A', 'i' => $row['id'], 't' => 'x'], 'sku'],
            'the wrong column' => [['k' => 'Alpha', 'i' => $row['id']], 'sku'],
            'a key matching nothing' => [['k' => 'zzz', 'i' => $row['id']], null],
            'no id' => [['k' => 'SKU-A', 'j' => 'SKU-A'], 'sku'],
        ] as $label => [$planted, $sort]) {
            self::assertNotNull(self::cursorDefect($planted, $row, $sort), 'The detector accepted a planted cursor with '.$label.'.');
        }

        $fixtures = $this->listFixtures();
        $defects = [];
        $paged = 0;

        foreach ($this->listOperations() as $name => $operation) {
            if (!\in_array('after', self::declaredParameters($operation), true)) {
                continue;
            }
            ++$paged;

            [$path, $seed] = $fixtures[$name]();
            $context = $seed(self::ROWS);

            $named = [];
            foreach ($context['views'] as $view) {
                parse_str($view, $q);
                if (isset($q['sort'])) {
                    $named[] = (string) $q['sort'];
                }
            }
            $enum = $operation->getParameters()?->get('sort')?->getSchema()['enum'] ?? [];
            sort($named);
            sort($enum);
            self::assertSame($enum, $named, $name.': the fixture views do not decode exactly the declared sort enum.');

            foreach ($context['views'] as $view) {
                parse_str($view, $q);
                $sort = isset($q['sort']) ? (string) $q['sort'] : null;
                $prefix = '' === $view ? '?' : '?'.$view.'&';

                $this->send('GET', $path.$prefix.'limit=1');
                self::assertSame(200, $this->httpStatus(), $name.' did not answer '.$view);
                $first = $this->json();
                self::assertIsString($first['next_cursor'] ?? null, $name.' ['.$view.'] page one carried no next_cursor, so nothing was decoded.');

                $defect = self::cursorDefect(self::decode($first['next_cursor']), $first['items'][0], $sort);
                if (null !== $defect) {
                    $defects[] = \sprintf('%s [%s] next_cursor %s', $name, $view, $defect);
                }

                // A cursor the server itself refuses is a defect of the cursor,
                // recorded by name rather than asserted away before the report.
                $this->send('GET', $path.$prefix.'limit=1&after='.urlencode($first['next_cursor']));
                $second = $this->json();
                if (200 !== $this->httpStatus() || !\is_string($second['previous_cursor'] ?? null)) {
                    $defects[] = \sprintf('%s [%s] page two answered %d with no previous_cursor', $name, $view, $this->httpStatus());
                    continue;
                }

                $defect = self::cursorDefect(self::decode($second['previous_cursor']), $second['items'][0], $sort);
                if (null !== $defect) {
                    $defects[] = \sprintf('%s [%s] previous_cursor %s', $name, $view, $defect);
                }
            }
        }

        self::assertGreaterThan(0, $paged, 'No list pages, so no cursor was decoded.');
        self::assertSame([], $defects, 'API-18: a cursor does not carry exactly the sort key and the id.');
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $found
     */
    private static function walkForTotals(array $body, string $where, array &$found): void
    {
        foreach ($body as $key => $value) {
            $here = \is_int($key) ? $where.'['.$key.']' : $where.'.'.$key;
            if (\is_string($key) && \in_array($key, self::TOTAL_MEMBERS, true)) {
                $found[] = $here;
            }
            if (\is_array($value)) {
                self::walkForTotals($value, $here, $found);
            }
        }
        sort($found);
    }

    private static function emitsOffset(string $sql): bool
    {
        // Comments, string literals and quoted identifiers, in one left-to-right
        // pass so whichever opens first wins.
        $bare = (string) preg_replace('~/\*.*?\*/|--[^\n]*|\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"~s', ' ', $sql);

        return 1 === preg_match('/\bOFFSET\b/i', $bare)
            || 1 === preg_match('/\bLIMIT\s+(?:\d+|\?|\$\d+|:[a-z_]+)\s*,/i', $bare);
    }

    /** @return array<mixed>|null */
    private static function decode(string $cursor): ?array
    {
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed>|null    $decoded
     * @param array<string, mixed> $row
     */
    private static function cursorDefect(?array $decoded, array $row, ?string $sort): ?string
    {
        if (null === $decoded) {
            return 'does not decode to an object';
        }
        if (2 !== \count($decoded)) {
            return \sprintf('carries %d members [%s], not the sort key and the id', \count($decoded), implode(', ', array_keys($decoded)));
        }

        $idMembers = array_keys(array_filter($decoded, static fn (mixed $v): bool => $v === $row['id']));
        if (1 !== \count($idMembers)) {
            return 'carries no member equal to the boundary row\'s id '.json_encode($row['id']).': '.json_encode($decoded);
        }

        $key = $decoded[array_values(array_diff(array_keys($decoded), $idMembers))[0]];

        if (null !== $sort) {
            return self::sameValue($key, $row[$sort] ?? null)
                ? null
                : \sprintf('carries the key %s, which is not the boundary row\'s `%s` (%s)', json_encode($key), $sort, json_encode($row[$sort] ?? null));
        }

        foreach ($row as $member => $value) {
            if ('id' !== $member && self::sameValue($key, $value)) {
                return null;
            }
        }

        return 'carries the key '.json_encode($key).', which equals no member of the boundary row';
    }

    private static function sameValue(mixed $a, mixed $b): bool
    {
        if (!\is_string($a) || !\is_string($b)) {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $pattern = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/';
        if (1 === preg_match($pattern, $a) && 1 === preg_match($pattern, $b)) {
            return false !== strtotime($a) && strtotime($a) === strtotime($b);
        }

        return false;
    }
}
