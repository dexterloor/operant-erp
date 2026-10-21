<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Pagination\Cursor;
use PHPUnit\Framework\Attributes\Test;

/**
 * **A cursor's sort key is refused, never cast into a 500** (shipped-defect fix, 2026-10-21; SESSION_LOG Move 666).
 *
 * The cursor is unsigned by design (`Cursor`'s docblock: it names a position, not a capability), so its sort key is
 * whatever a client sends. Where a view orders by a `timestamptz`, the key is bound as text and cast with
 * `?::timestamptz`; a key Postgres refuses there raises `22008`/`22007` inside the read, which no translator maps, so
 * the caller got a bare 500. The shipped check (`isTimestamp()`, copied into eight providers — the eighth, `ListSalesOrders`' Archived view, arrived with order-restores C2) let an offset of `+16`
 * through `+99` pass — Postgres's displacement limit is `±15:59:59` — and two lists (`ListSalesOrders`,
 * `ListPurchaseOrders` on `sort=ordered_at`) and one regex (`ListRoleGrantProposals`) checked less than that.
 *
 * **Domain, derived:** every list in the registry that declares `after` (keyset), on every view its fixture names
 * ({@see ListOperationFixtures}). A view is **timestamp-keyed** when the cursor the API itself hands out carries a key
 * of Postgres's ISO shape — the classification reads the server's output, not a list of sort names. On a
 * timestamp-keyed view every hostile key must answer 422 `malformed_cursor` on the cursor's own parameter, for `after`
 * and for `before`; on every other view a hostile key may be read as text (200) or refused (422), but never a 500.
 *
 * **Controls inside the method:** the domain is non-empty; every keyset view pages (a real cursor exists); the real
 * cursor itself still answers 200 on every timestamp-keyed view (so a 422 is about the key, not the view); every view
 * whose query names a `sort=…_at` is classified timestamp-keyed, and so is every list with no `sort` parameter whose
 * order the API states is by time — a cross-check that the classification is not vacuous.
 */
final class CursorSortKeyContractTest extends PurchaseOrderTestCase
{
    use ListOperationFixtures;

    private const ROWS = 2;

    /** Postgres's own ISO rendering of a `timestamptz` (DateStyle ISO), which is what a real cursor carries. */
    private const PG_ISO_TIMESTAMP = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2}){0,2}$/';

    /**
     * Keys a client can forge. Each was measured against Postgres 17 on 2026-10-21: the offsets past `±15:59:59`
     * raise `time zone displacement out of range`, the fields past range raise `date/time field value out of range`,
     * the rest are not timestamps at all.
     */
    private const HOSTILE_KEYS = [
        '2026-01-01 00:00:00+16',
        '2026-01-01 00:00:00-16',
        '2026-01-01 00:00:00+99',
        '2026-01-01 00:00:00.5+99:00',
        '2026-01-01 00:00:00+05:60',
        '2026-01-01 00:00:00+05:30:60',
        '2026-01-01 00:00:00+15:59:99',
        '2026-13-01 00:00:00+00',
        '2026-02-30 00:00:00+00',
        '0000-01-01 00:00:00+00',
        '2026-01-01 24:00:01+00',
        '2026-01-01 23:60:00+00',
        'not-a-time',
        '',
        "SKU\0",
        "2026-01-01 00:00:00+00\0",
    ];

    /** Lists with no `sort` parameter whose one order is by time (§14.2 items 29/30, IA §5.6) — the cross-check only. */
    private const TIME_ORDERED_WITHOUT_SORT = ['ListAuditLogEntries', 'ListAuditRecords', 'ListStockMovements'];

    /** Views whose one order is by time, named by the filter that selects them (IA §5.6: an order's shipments/receipts). */
    private const TIME_ORDERED_VIEWS = ['ListFulfilments' => 'sales_order_id=', 'ListGoodsReceipts' => 'purchase_order_id='];

    #[Test]
    public function aForgedSortKeyIsRefusedOnEveryTimestampKeyedViewAndNeverA500(): void
    {
        $lists = array_filter($this->listOperations(), static fn ($operation): bool => \in_array('after', self::declaredParameters($operation), true));
        self::assertNotSame([], $lists, 'No keyset list in the registry, so nothing was examined.');

        $fixtures = $this->listFixtures();
        $timestampKeyed = [];
        $defects = [];

        foreach ($lists as $name => $operation) {
            [$path, $seed] = $fixtures[$name]();
            $views = $seed(self::ROWS)['views'];

            foreach ($views as $view) {
                $viewPath = self::withQuery($path, $view);
                $this->send('GET', self::withQuery($viewPath, 'limit=1'));
                self::assertSame(200, $this->httpStatus(), $name.' '.$view.': the first page did not answer 200.');
                $next = $this->json()['next_cursor'] ?? null;
                self::assertIsString($next, $name.' ['.$view.'] did not page, so no real cursor was examined.');
                $real = Cursor::decode($next);
                $isTimestamp = 1 === preg_match(self::PG_ISO_TIMESTAMP, $real->sortKey);
                $label = $name.' ['.$view.']';

                $timeOrderedView = isset(self::TIME_ORDERED_VIEWS[$name]) && str_starts_with($view, self::TIME_ORDERED_VIEWS[$name]);
                if (1 === preg_match('/(^|&)sort=[a-z_]+_at(&|$)/', $view) || \in_array($name, self::TIME_ORDERED_WITHOUT_SORT, true) || $timeOrderedView) {
                    self::assertTrue($isTimestamp, $label.' orders by time but its cursor key '.json_encode($real->sortKey).' is not Postgres\'s ISO shape.');
                }

                if ($isTimestamp) {
                    $timestampKeyed[] = $label;
                    $this->send('GET', self::withQuery($viewPath, 'limit=1&after='.urlencode($next)));
                    self::assertSame(200, $this->httpStatus(), $label.': the real cursor was refused, so a 422 below would say nothing about the key.');
                }

                foreach (self::HOSTILE_KEYS as $key) {
                    $forged = (new Cursor($key, $real->id))->encode();
                    foreach (['after', 'before'] as $parameter) {
                        $this->send('GET', self::withQuery($viewPath, 'limit=1&'.$parameter.'='.urlencode($forged)));
                        $status = $this->httpStatus();
                        $where = \sprintf('%s ?%s key %s', $label, $parameter, json_encode($key));
                        if (500 <= $status) {
                            $defects[] = $where.' answered '.$status;
                            continue;
                        }
                        if (!$isTimestamp) {
                            continue;
                        }
                        $errors = 422 === $status ? array_map(static fn (array $e): array => [$e['pointer'], $e['code']], $this->json()['errors'] ?? []) : [];
                        if (422 !== $status || [['?'.$parameter, 'malformed_cursor']] !== $errors) {
                            $defects[] = $where.' answered '.$status.' '.json_encode($errors).', not 422 malformed_cursor';
                        }
                    }
                }
            }
        }

        self::assertNotSame([], $timestampKeyed, 'No view in the domain is timestamp-keyed, so the refusal was asserted on nothing.');
        self::assertSame([], $defects, "A forged cursor sort key was cast instead of refused:\n".implode("\n", $defects));
    }
}
