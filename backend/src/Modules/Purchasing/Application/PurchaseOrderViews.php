<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;

/**
 * **What a `/purchase-orders` *view* is** — the segment expansion (§10.3
 * derivation step 2) and the orders that view offers (API-19).
 *
 * TB-1's whole content is that sortability is a property of a **(list, view)
 * pair** and not of a column, because most of the ordering indexes are partial.
 * This class is that pair, and it is in `Application` rather than beside the
 * provider because it is a fact about the *query* — which rows, in which order,
 * off which index — and not about parsing a query string.
 */
final class PurchaseOrderViews
{
    /**
     * §10.3 derivation step 2 — *"a `status = ?` or `status IN (…)` predicate
     * becomes the **`status` segment parameter**, whose value set is the
     * manifest's own segment names for that route and not the entity's raw
     * `STATUS_*` values — `/purchase-orders` has an **Open** segment covering two
     * statuses, and a client that had to know which two would be carrying the
     * state machine."*
     *
     * The keys are §4.2's four names. The values are the entity's own constants,
     * never literals, so a change to a status value moves this table with it —
     * and `open`'s two members are exactly the two in
     * `purchase_order_tenant_open_idx`'s partial predicate, which is what makes
     * that index usable for this segment.
     *
     * **`draft` is the one segment whose rows may have no `ordered_at`**, and
     * `purchase_order_ordered_at_set` — `CHECK (status = 'draft' OR ordered_at IS
     * NOT NULL)` — is what makes that a database fact rather than an observation.
     * See `legalSorts()`.
     *
     * @var array<string, non-empty-list<string>>
     */
    public const SEGMENTS = [
        'open' => [PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED],
        'draft' => [PurchaseOrder::STATUS_DRAFT],
        'received' => [PurchaseOrder::STATUS_RECEIVED],
        'cancelled' => [PurchaseOrder::STATUS_CANCELLED],
    ];

    /**
     * The same four names as `SEGMENTS`' keys, written out because an attribute
     * argument must be a **constant expression** and `array_keys()` is a function
     * call. Two spellings of one list is exactly the drift this project logs, so
     * `PurchaseOrderOperationContractTest::theSegmentNamesAreTheSegmentTablesKeys`
     * compares them and `segmentNames()` below is what every non-attribute caller
     * uses.
     *
     * @var non-empty-list<string>
     */
    public const SEGMENT_NAMES = ['open', 'draft', 'received', 'cancelled'];

    public const SORT_ORDER_NUMBER = 'order_number';
    public const SORT_ORDERED_AT = 'ordered_at';

    /** @return list<string> §4.2's segment names, derived from the table above */
    public static function segmentNames(): array
    {
        return array_keys(self::SEGMENTS);
    }

    /**
     * **API-19 — the legal `sort` values for a (path, view) pair are exactly the
     * rows of the sort manifest, and a value outside it is refused.**
     *
     * `IA.md` §5.6's five `/purchase-orders` rows, read as written:
     *
     *   | view                   | default        | alternate      |
     *   |------------------------|----------------|----------------|
     *   | **Open**               | `ordered_at`   | `order_number` |
     *   | Draft                  | `order_number` | —              |
     *   | Received · Cancelled   | `order_number` | —              |
     *   | Archived               | ⛔ **D-a**     | —              |
     *   | filtered to a supplier | `ordered_at`   | `order_number` |
     *
     * So `order_number` is legal on every live view and `ordered_at` on two of
     * them, and the two lines below are those two sentences.
     *
     * ⚠ **ONE DEVIATION FROM THE MANIFEST, stated rather than smoothed over.**
     * The manifest's *filtered to a supplier* row carries the predicate
     * `deleted_at IS NULL AND supplier_id = ?` — **no status** — and orders it by
     * `ordered_at`. That set **includes drafts**, and the `CHECK` above permits
     * `ordered_at IS NULL` on exactly those. So `ordered_at` is a **nullable**
     * sort key over that view; API-18's cursor is a row comparison,
     * `(NULL, id) > (?, ?)` evaluates to NULL in SQL, and every draft would be
     * silently dropped from every page after the first. **A list that loses rows
     * across a page boundary is worse than one ordered by its second key.**
     *
     * `ordered_at` is therefore offered **only where every row in the view
     * carries one** — where the status predicate excludes `draft` — and the
     * supplier view falls back to `order_number` when it carries no segment or
     * carries `draft`. §4.4.2 says every document list opens on a segment
     * (*"`/purchase-orders` opens on Open"*), so the supplier view in the product
     * always carries one and the deviation is invisible to the screen the
     * manifest row was written for. **Reported as a finding against that manifest
     * row rather than fixed here** — the manifest is `UX`'s artifact.
     *
     * The unsegmented, unfiltered list has no manifest row at all and is ordered
     * by `order_number`, which `purchase_order_tenant_number_live` serves under
     * this list's own `deleted_at IS NULL` predicate.
     *
     * @param list<string> $statuses the expanded segment; `[]` is no status predicate
     *
     * @return non-empty-list<string> the view's default order first
     */
    public static function legalSorts(array $statuses, bool $filteredToASupplier): array
    {
        // The **Open** row, and the *filtered to a supplier* row.
        $manifestOffersOrderedAt = self::SEGMENTS['open'] === $statuses || $filteredToASupplier;

        // The deviation above: a nullable sort key is not a sort key.
        $everyRowCarriesOne = [] !== $statuses && !\in_array(PurchaseOrder::STATUS_DRAFT, $statuses, true);

        return $manifestOffersOrderedAt && $everyRowCarriesOne
            ? [self::SORT_ORDERED_AT, self::SORT_ORDER_NUMBER]
            : [self::SORT_ORDER_NUMBER];
    }
}
