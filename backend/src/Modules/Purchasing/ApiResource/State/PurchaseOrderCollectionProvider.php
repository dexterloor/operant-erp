<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Purchasing\ApiResource\PurchaseOrderCollection;
use App\Modules\Purchasing\Application\PurchaseOrderListCriteria;
use App\Modules\Purchasing\Application\PurchaseOrderReader;
use App\Modules\Purchasing\Application\PurchaseOrderViews;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Pagination\TimestampSortKey;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `ListPurchaseOrders` — parameter parsing, and the refusals API-19/API-19b
 * require.
 *
 * The **accepted** parameter set is read off the operation's own declared
 * parameters, and an undeclared one never reaches this class
 * (`Platform\Http\UndeclaredQueryParameterRefusal`, F2 2026-09-15). §4.2's
 * `Query parameters` column is exhaustive, `PurchaseOrderCollection` declares
 * exactly that column, and the spec documents the same set -- so a parameter
 * accepted here but undocumented, or documented and refused, is not expressible.
 *
 * **`archived`** (order restores C3, 2026-10-21; API-19c limb 1 now that `IA.md` §5.6's `/purchase-orders` Archived row
 * is no longer `⛔ D-a`): `true`/`1` serve the archived orders instead of the live ones (TB-3), `false`/`0` or absent the
 * live ones, anything else 422 `not_a_boolean`. With `status` or `supplier_id` it is a 422 `mutually_exclusive` at
 * `?archived` (TO CONFIRM by `UX`/`SEC`). `RestorePurchaseOrder` ships with it (E9-3).
 *
 * **What a *view* is, and which orders it offers, is `PurchaseOrderViews`'** --
 * that is a fact about the query and the indexes behind it, and this class only
 * parses what a client sent and refuses what the view does not serve.
 *
 * @implements ProviderInterface<PurchaseOrderCollection>
 */
final readonly class PurchaseOrderCollectionProvider implements ProviderInterface
{
    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    public function __construct(private PurchaseOrderReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        return $this->reader->list(self::criteriaFrom($query));
    }

    /**
     * **The list's whole pre-read parse, shared with `CreateExport`** (G6-C1, design Response M-5): the provider and {@see PurchaseOrderExportSource} both call this, so an
     * export's filters are validated, combined and refused exactly as the list's are — by construction, not by a second copy
     * (TB-7). `$limit` is the export's cap, an explicit argument the wire never reaches (`limit` is not an export filter;
     * API-14a): when given it replaces the parsed page size, unbounded by `MAXIMUM_LIMIT`, and the reader's `LIMIT limit + 1`
     * reports whether the view is over it.
     *
     * @param array<string, mixed> $query
     */
    public static function criteriaFrom(array $query, ?int $limit = null): PurchaseOrderListCriteria
    {
        $errors = [];

        $limit ??= self::DEFAULT_LIMIT;
        if (isset($query['limit'])) {
            $raw = $query['limit'];
            if (!\is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1 || (int) $raw > self::MAXIMUM_LIMIT) {
                $errors[] = self::error('limit', 'out_of_range', \sprintf('limit must be a whole number between 1 and %d.', self::MAXIMUM_LIMIT));
            } else {
                $limit = (int) $raw;
            }
        }

        $statuses = [];
        if (isset($query['status'])) {
            $raw = $query['status'];
            if (!\is_string($raw) || !\array_key_exists($raw, PurchaseOrderViews::SEGMENTS)) {
                $errors[] = self::error('status', 'unknown_segment', \sprintf(
                    'status must be one of %s.',
                    implode(', ', PurchaseOrderViews::segmentNames()),
                ));
            } else {
                $statuses = PurchaseOrderViews::SEGMENTS[$raw];
            }
        }

        $supplierId = null;
        if (isset($query['supplier_id'])) {
            $raw = $query['supplier_id'];
            if (!\is_string($raw) || !Uuid::isValid($raw)) {
                $errors[] = self::error('supplier_id', 'not_a_uuid', 'supplier_id must be a UUID.');
            } else {
                $supplierId = $raw;
            }
        }

        $search = null;
        if (isset($query['q'])) {
            $raw = $query['q'];
            if (!\is_string($raw)) {
                $errors[] = self::error('q', 'not_a_string', 'q must be a string.');
            } elseif ('' !== $raw) {
                $search = $raw;
            }
        }

        $archived = false;
        if (isset($query['archived'])) {
            $archived = match ($query['archived']) {
                'true', '1' => true,
                'false', '0' => false,
                default => null,
            };
            if (null === $archived) {
                $errors[] = self::error('archived', 'not_a_boolean', 'archived must be true or false.');
                $archived = false;
            }
        }

        if ($archived && (isset($query['status']) || isset($query['supplier_id']))) {
            // R1.5 / final RED L-10, `SalesOrderCollectionProvider`'s twin: the Archived view is a sibling of the segments
            // (TB-3a), every archived purchase order is a draft by CHECK, and the archived index serves neither filter.
            // Shipped as the STRICTER default; the code, the pointer and the 422 itself are TO CONFIRM by `UX` and `SEC`.
            $errors[] = self::error('archived', 'mutually_exclusive',
                'The archived orders are their own view: send archived without status or supplier_id.');
        }

        // API-19, per view: the Archived view has its own one-element list, never the live derivation (RED M-1).
        $legal = $archived ? PurchaseOrderViews::ARCHIVED_SORTS : PurchaseOrderViews::legalSorts($statuses, null !== $supplierId);
        $sort = $legal[0];

        if (isset($query['sort'])) {
            $raw = $query['sort'];
            if (!\is_string($raw) || !\in_array($raw, $legal, true)) {
                // API-19: **refuse**, never ignore. *"A server that silently
                // ignores an unserved sort returns rows in an order the screen
                // claims and the database did not produce."*
                $errors[] = self::error('sort', 'unserved_sort', \sprintf(
                    'The orders this view offers are %s.',
                    implode(' and ', $legal),
                ));
            } else {
                $sort = $raw;
            }
        }

        if (isset($query['after'], $query['before'])) {
            $errors[] = self::error('after', 'mutually_exclusive', 'Send at most one of after and before.');
        }

        $after = self::cursor($query, 'after', $sort, $errors);
        $before = self::cursor($query, 'before', $sort, $errors);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return new PurchaseOrderListCriteria(
            limit: $limit,
            sort: $sort,
            statuses: $statuses,
            supplierId: $supplierId,
            search: $search,
            after: $after,
            before: $before,
            archived: $archived,
        );
    }

    /**
     * @param array<string, mixed>                                        $query
     * @param list<array{pointer: string, code: string, message: string}> $errors
     */
    private static function cursor(array $query, string $name, string $sort, array &$errors): ?Cursor
    {
        if (!isset($query[$name]) || '' === $query[$name]) {
            return null;
        }

        try {
            $cursor = Cursor::decode((string) $query[$name]);
        } catch (InvalidCursor) {
            $errors[] = self::error($name, 'malformed_cursor', \sprintf('%s must be a cursor returned by a previous response.', $name));

            return null;
        }

        // Both timestamp sorts bind the key as `?::timestamptz` (the reader's `SORT_KEY_CASTS` for `ordered_at`; the Archived
        // view's `archived_at`, order restores C3), so a key Postgres would refuse there is refused HERE, as a malformed
        // cursor, not raised inside the read as a 500 (shipped-defect fix, 2026-10-21: until then any forged `ordered_at` key --
        // `x`, `''`, an offset of `+16` -- answered 500). An `order_number` cursor from the live view is refused on the
        // Archived view the same way. ⚠ A live `ordered_at` cursor IS a timestamp and is accepted as a position among
        // archived rows (final RED M-2: harmless, recorded, `Cursor` not changed) — `SalesOrderCollectionProvider`'s twin.
        if ((PurchaseOrderViews::SORT_ORDERED_AT === $sort || PurchaseOrderViews::SORT_ARCHIVED_AT === $sort)
            && !TimestampSortKey::isValid($cursor->sortKey)) {
            $errors[] = self::error($name, 'malformed_cursor', \sprintf('%s must be a cursor returned by a previous response for this view.', $name));

            return null;
        }

        return $cursor;
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $parameter, string $code, string $message): array
    {
        return ['pointer' => '?'.$parameter, 'code' => $code, 'message' => $message];
    }
}
