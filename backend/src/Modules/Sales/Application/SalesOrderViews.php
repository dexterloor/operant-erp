<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;

/**
 * **What a `/sales-orders` *view* is** — the segment expansion (§10.3 derivation
 * step 2) and the orders that view offers (API-19).
 *
 * TB-1's whole content is that sortability is a property of a **(list, view)
 * pair** and not of a column, because most of the ordering indexes are partial.
 * This class is that pair, and it is in `Application` rather than beside the
 * provider because it is a fact about the *query* — which rows, in which order,
 * off which index — and not about parsing a query string.
 */
final class SalesOrderViews
{
    /**
     * §10.3 derivation step 2 — a `status = ?` or `status IN (…)` predicate becomes
     * the **`status` segment parameter**, whose value set is the manifest's own
     * segment names for that route and never the entity's raw `STATUS_*` values:
     * `/sales-orders` has an **Open** segment covering two statuses, and a client
     * that had to know which two would be carrying the state machine.
     *
     * The keys are §4.2's four names. The values are the entity's own constants,
     * never literals (API-15) — and `open`'s two members are exactly the two in
     * `sales_order_tenant_open_idx`'s partial predicate
     * (`WHERE deleted_at IS NULL AND status = ANY (ARRAY['confirmed','partially_fulfilled'])`,
     * measured), which is what makes that index usable for this segment.
     *
     * **Measured:** `sales_order_status_values` admits five statuses; these four
     * segments partition all five, so no status is unreachable through the
     * parameter.
     *
     * @var array<string, non-empty-list<string>>
     */
    public const SEGMENTS = [
        'open' => [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED],
        'draft' => [SalesOrder::STATUS_DRAFT],
        'fulfilled' => [SalesOrder::STATUS_FULFILLED],
        'cancelled' => [SalesOrder::STATUS_CANCELLED],
    ];

    /**
     * The same four names as `SEGMENTS`' keys, written out because an attribute
     * argument must be a **constant expression** and `array_keys()` is a function
     * call. Two spellings of one list is exactly the drift this project logs, so
     * `SalesOrderListTest::theSegmentNamesAreTheSegmentTablesKeys` compares them and
     * `segmentNames()` below is what every non-attribute caller uses.
     *
     * @var non-empty-list<string>
     */
    public const SEGMENT_NAMES = ['open', 'draft', 'fulfilled', 'cancelled'];

    /**
     * **The statuses whose rows may carry no `ordered_at`.**
     *
     * ⚠ **On `sales_order` this is a CODE fact, and on `purchase_order` the same
     * list is a DATABASE fact. The difference is not cosmetic and must not be
     * smoothed over.** `PurchaseOrderViews::ORDERED_AT_MAY_BE_NULL` is the first
     * disjunct of `purchase_order_ordered_at_set`, and a test asks the live
     * constraint, one status at a time, which statuses it exempts. **`sales_order`
     * carries no `ordered_at` CHECK at all** — measured against `pg_constraint`, its
     * four are `id_is_uuid_v4`, `idempotency_key_sha256`, `request_digest_sha256` and
     * `status_values` — so there is nothing to ask and the same test cannot be
     * written here. `SalesOrder::cancel()`'s own docblock already records the
     * absence.
     *
     * What holds the list instead is that **`SalesOrder::confirm()` is the only
     * writer of the column**, and `confirm()`'s only legal source is `draft`. So:
     *
     *   - `draft` — never written, so always NULL;
     *   - `cancelled` — `cancel()` accepts `STATUS_DRAFT` as a source and
     *     *"`ordered_at` is deliberately not touched"*, so `CreateSalesOrder` →
     *     `CancelSalesOrder` produces `status = 'cancelled', ordered_at IS NULL`
     *     **through shipped operations, today**;
     *   - `confirmed`, `partially_fulfilled`, `fulfilled` — reachable only through
     *     `confirm()`, which writes the column in the same call.
     *
     * `SalesOrderListTest::theSalesTableFixesNoOrderedDateGuaranteeSoTheCodeMust`
     * pins that asymmetry from the server — it asserts this table **accepts** a
     * `confirmed` row with a NULL `ordered_at` while `purchase_order` refuses the
     * equivalent — so a reader cannot mistake the sales guarantee for the purchase
     * one, and a later hand that wants the stronger guarantee knows it needs a
     * migration rather than a docblock.
     *
     * @var non-empty-list<string>
     */
    public const ORDERED_AT_MAY_BE_NULL = [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CANCELLED];

    public const SORT_ORDER_NUMBER = 'order_number';
    public const SORT_ORDERED_AT = 'ordered_at';

    /**
     * **The Archived view's one order** (order restores C2, 2026-10-21; `IA.md` §5.6 `/sales-orders` Archived row, D-a's
     * `sales_order_tenant_archived_idx`): `archived_at` = `deleted_at DESC, id DESC`, most recently archived first. Offered
     * ONLY on the Archived view, and the Archived view offers nothing else ({@see self::ARCHIVED_SORTS}) — it never calls
     * {@see self::legalSorts()}, whose `ordered_at` would be a nullable key there: every archived sales order is a draft
     * (`sales_order_archived_only_while_draft`), and a draft carries no `ordered_at` (`6fe8b7d`'s trap; RED M-1).
     */
    public const SORT_ARCHIVED_AT = 'archived_at';

    /** @var non-empty-list<string> the Archived view's legal sorts, default first */
    public const ARCHIVED_SORTS = [self::SORT_ARCHIVED_AT];

    /** @return list<string> §4.2's segment names, derived from the table above */
    public static function segmentNames(): array
    {
        return array_keys(self::SEGMENTS);
    }

    /**
     * **API-19 — the legal `sort` values for a (path, view) pair are exactly the
     * rows of the sort manifest, and a value outside it is refused.**
     *
     * `IA.md` §5.6's four `/sales-orders` rows, read as written:
     *
     *   | view                           | default        | alternate      |
     *   |--------------------------------|----------------|----------------|
     *   | **Open**                       | `ordered_at`   | `order_number` |
     *   | Draft · Fulfilled · Cancelled  | `order_number` | —              |
     *   | Archived                       | `archived_at`  | —              |   (order restores C2; {@see self::ARCHIVED_SORTS})
     *   | filtered to a customer         | `ordered_at`   | `order_number` |
     *
     * So `order_number` is legal on every live view and `ordered_at` on two of them,
     * and the two lines below are those two sentences.
     *
     * ⚠ **ONE DEVIATION FROM THE MANIFEST, stated rather than smoothed over** — the
     * same one `PurchaseOrderViews` records, and it is wider here. The manifest's
     * *filtered to a customer* row carries the predicate
     * `deleted_at IS NULL AND customer_id = ?` — **no status** — and orders it by
     * `ordered_at`. That set includes drafts and cancellations, and on this table
     * **nothing forbids** `ordered_at IS NULL` on either (`ORDERED_AT_MAY_BE_NULL`).
     * API-18's cursor is a row comparison, `(NULL, id) > (?, ?)` evaluates to NULL in
     * SQL, and every such row is silently dropped from every page after the first —
     * and, because the reader renders the key with `(string)`, a page boundary
     * landing on one emits a `next_cursor` carrying `''` that raises when the client
     * follows it. **That is not a hypothetical: it is the defect `6fe8b7d` fixed in
     * shipped purchasing code on 2026-09-20, and this class is written after it
     * rather than before.**
     *
     * `ordered_at` is therefore offered **only where every row in the view carries
     * one** — where the status predicate excludes both exempt statuses — and the
     * customer view falls back to `order_number` when it carries no segment or
     * carries the Draft or the Cancelled one, which is the order the manifest's own
     * *Draft · Fulfilled · Cancelled* row already gives those segments. §4.4.2 says
     * every document list opens on a segment, so the customer view in the product
     * always carries one and the deviation is invisible to the screen the manifest
     * row was written for. **Reported as a finding against that manifest row rather
     * than fixed here — the manifest is `UX`'s artifact.**
     *
     * **This narrowing is invisible to `SortManifestTest`**, whose own docblock says
     * the enum is compared *per operation, not per (path, view)* — the declared enum
     * is the path's union either way. It is therefore carried by
     * `SalesOrderListTest`'s per-view tests, which refuse it on `cancelled` and
     * accept it on `open` in one test, and drive the **harm** in another. A docblock
     * alone would be the sentence-outrunning-its-mechanism shape this slice kept
     * finding.
     *
     * The unsegmented, unfiltered list has no manifest row at all and is ordered by
     * `order_number`, which `sales_order_tenant_number_live` serves under this list's
     * own `deleted_at IS NULL` predicate.
     *
     * @param list<string> $statuses the expanded segment; `[]` is no status predicate
     *
     * @return non-empty-list<string> the view's default order first
     */
    public static function legalSorts(array $statuses, bool $filteredToACustomer): array
    {
        // The **Open** row, and the *filtered to a customer* row.
        $manifestOffersOrderedAt = self::SEGMENTS['open'] === $statuses || $filteredToACustomer;

        // The deviation above: a nullable sort key is not a sort key. The test is
        // against the whole exempt set, never against one of its members -- naming
        // `draft` alone is exactly the defect `6fe8b7d` had to repair next door.
        $everyRowCarriesOne = [] !== $statuses && [] === array_intersect($statuses, self::ORDERED_AT_MAY_BE_NULL);

        return $manifestOffersOrderedAt && $everyRowCarriesOne
            ? [self::SORT_ORDERED_AT, self::SORT_ORDER_NUMBER]
            : [self::SORT_ORDER_NUMBER];
    }
}
