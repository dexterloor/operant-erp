<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use PHPUnit\Framework\Attributes\Test;

/**
 * API-22 (`docs/API.md` §4.4.2) — every collection response is one envelope,
 * and its forward cursor goes null on the last page and only there.
 *
 * ---------------------------------------------------------------------------
 * Domain
 * ---------------------------------------------------------------------------
 * **Derived from the operation registry, never a list in the test**: every
 * `GET` in `TransactionBoundaryTest::contractOperations()` whose operationId's
 * verb is `List` (§12.3 makes the verb the operation's classification, and
 * `OperationIdTest` holds the operationId to its §4.2 `Verb / Resource` cell,
 * so a list whose name drifted is red there). The domain reads no document, so
 * this file runs identically on a clean checkout (`SkipInvariantTest`).
 *
 * Whether a list pages is read off the operation too — a list that declares a
 * `limit` query parameter pages; one that does not is §4.2's `(bounded)`, whose
 * whole set is one page — so the walk below has no per-operation branching.
 *
 * The fixtures are the one per-operation part, because a row cannot be seeded
 * generically. They live in {@see ListOperationFixtures} (moved there by C5
 * batch C, 2026-09-15, so batch C's list checks seed the same rows) and are held
 * equal to the domain inside each test, so a list built tomorrow is red here
 * until it is given one.
 */
final class ListEnvelopeTest extends PurchaseOrderTestCase
{
    use ListOperationFixtures;

    /** API-22's members, and nothing else. */
    private const ENVELOPE = ['items', 'next_cursor', 'previous_cursor', 'tenant_has_rows'];

    /** How many rows each fixture seeds; more than two pages at {@see self::PAGE}. */
    private const ROWS = 5;

    private const PAGE = 2;

    /**
     * **Every list operation returns the envelope**, on the empty path and on a
     * page with rows: exactly `items`, `next_cursor`, `previous_cursor` and
     * `tenant_has_rows`, typed as §4.4.2 writes them — so a missing cursor member
     * and a `total`, `totalItems`, `page` or `offset` member are both red.
     *
     * Controls inside this method: the domain is not empty and equals the
     * fixtures; every populated page carried rows (so `items` was not vacuously
     * a list); the envelope check refuses a planted `total`.
     *
     * **Mutation (C5 batch B, 2026-09-14; cache deleted — Rule 17):**
     * `PurchaseOrderLineCollection`'s `#[SerializedName('next_cursor')]` →
     * `'cursor'`; red naming `ListPurchaseOrderLines`. Recorded in
     * `docs/session9/progress-C5B.md` row 8.
     */
    #[Test]
    public function everyListOperationReturnsTheEnvelope(): void
    {
        $lists = $this->listOperations();
        $fixtures = $this->listFixtures();

        self::assertSame([], self::envelopeDefects(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => false]),
            'The detector refused a well-formed envelope.');
        self::assertNotSame([], self::envelopeDefects(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => false, 'total' => 0]),
            'The detector accepted a `total` member, so the assertion below cannot go red on one.');

        $defects = [];

        foreach ($lists as $name => $operation) {
            [$path, $seed] = $fixtures[$name]();

            $this->send('GET', $path);
            self::assertSame(200, $this->httpStatus(), $name.' did not answer its empty path.');
            foreach (self::envelopeDefects($this->json()) as $defect) {
                $defects[] = $name.' (empty): '.$defect;
            }

            $seed(self::ROWS);
            $this->send('GET', $path);
            self::assertSame(200, $this->httpStatus(), $name.' did not answer its populated path.');
            $body = $this->json();
            foreach (self::envelopeDefects($body) as $defect) {
                $defects[] = $name.' (with rows): '.$defect;
            }
            self::assertNotSame([], $body['items'] ?? [], $name.'\'s populated page carried no rows, so the envelope was never seen holding one.');
        }

        self::assertSame([], $defects, 'API-22: a collection response is not the envelope.');
    }

    /**
     * **`next_cursor` is null on the last page and only there.** Every list is
     * walked from its first page by following `next_cursor` as `after` until it
     * is null. A page that hands out a cursor whose successor holds nothing is a
     * page with no successor carrying a non-null cursor — red, by name — and so
     * is a walk that never ends. A `(bounded)` list is one page, and its
     * `next_cursor` must be null on it.
     *
     * Controls inside this method: the domain is not empty and equals the
     * fixtures; at least one list actually paged (two or more pages), so the
     * "only there" half was exercised; every walk saw as many rows as the whole
     * default view holds.
     *
     * **Mutation (§4.4.2's, C5 batch B, 2026-09-14; cache deleted — Rule 17):**
     * `ItemCollectionProvider` returns a `next_cursor` from the last page as
     * well; the walk steps onto an empty page; red naming `ListItems`.
     * Recorded in `docs/session9/progress-C5B.md` row 9.
     */
    #[Test]
    public function theLastPageCarriesANullCursor(): void
    {
        $lists = $this->listOperations();
        $fixtures = $this->listFixtures();

        $defects = [];
        $paged = [];

        foreach ($lists as $name => $operation) {
            [$path, $seed] = $fixtures[$name]();
            $seed(self::ROWS);

            // The whole default view in one request — API-17's default page is
            // far above the rows seeded here — so the walk has a count to reach.
            $this->send('GET', $path);
            $whole = \count($this->json()['items'] ?? []);
            self::assertGreaterThanOrEqual(self::ROWS, $whole, $name.'\'s default view does not hold the seeded rows.');

            $pages = self::pages($operation);
            $query = $pages ? '?limit='.self::PAGE : '';
            $seen = 0;
            $cursor = null;
            $count = 0;

            do {
                $this->send('GET', $path.$query.(null === $cursor ? '' : '&after='.urlencode($cursor)));
                self::assertSame(200, $this->httpStatus(), $name.' did not answer page '.($count + 1).'.');
                $body = $this->json();
                ++$count;

                if (null !== $cursor && [] === $body['items']) {
                    $defects[] = \sprintf('%s: page %d carried a non-null next_cursor and has no successor (the next page is empty)', $name, $count - 1);
                    break;
                }

                $seen += \count($body['items']);
                $cursor = $body['next_cursor'] ?? null;

                if ($count > $whole + 1) {
                    $defects[] = $name.': next_cursor never went null — the walk ran off the end';
                    break;
                }
            } while (null !== $cursor);

            if (!$pages && 1 !== $count) {
                $defects[] = $name.' is bounded and handed out a cursor';
            }
            if ([] === array_filter($defects, static fn (string $d): bool => str_starts_with($d, $name))) {
                self::assertSame($whole, $seen, $name.'\'s walk stopped before the rows ran out: a null next_cursor on a page that has a successor.');
            }
            if ($count >= 2) {
                $paged[] = $name;
            }
        }

        self::assertSame([], $defects, 'API-22: next_cursor is non-null on a page that has no successor.');
        self::assertNotSame([], $paged, 'No list walked more than one page, so a cursor on a page with a successor was never seen.');
    }

    /** Whether the list pages at all: it declares `limit`, which a `(bounded)` list does not. */
    private static function pages(HttpOperation $operation): bool
    {
        return (bool) $operation->getParameters()?->has('limit');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private static function envelopeDefects(array $body): array
    {
        $defects = [];
        $members = array_keys($body);
        sort($members);
        $expected = self::ENVELOPE;
        sort($expected);

        if ($expected !== $members) {
            $defects[] = 'members are ['.implode(', ', $members).'], not ['.implode(', ', $expected).']';
        }
        if (!\array_key_exists('items', $body) || !\is_array($body['items']) || !array_is_list($body['items'])) {
            $defects[] = '`items` is not a list';
        }
        foreach (['next_cursor', 'previous_cursor'] as $cursor) {
            if (!\array_key_exists($cursor, $body) || !(null === $body[$cursor] || \is_string($body[$cursor]))) {
                $defects[] = '`'.$cursor.'` is absent or neither a string nor null';
            }
        }
        if (!\is_bool($body['tenant_has_rows'] ?? null)) {
            $defects[] = '`tenant_has_rows` is absent or not a boolean';
        }

        return $defects;
    }
}
