<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Inventory\ApiResource\ItemCollection;
use App\Modules\Inventory\Application\ItemListCriteria;
use App\Modules\Inventory\Application\ItemReader;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Pagination\TimestampSortKey;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `ListItems` — parameter parsing, and the refusals API-19/API-19b require.
 *
 * The **accepted** parameter set is the operation's own declared parameters,
 * and an undeclared one never reaches this class: `Platform\Http\UndeclaredQueryParameterRefusal`
 * refuses it for every operation, at the position this provider used to (F2,
 * 2026-09-15). §4.2's `Query parameters` column is exhaustive, `ItemCollection`
 * declares exactly that column, and the spec documents the same set -- so a
 * parameter accepted here but undocumented, or documented and refused, is not
 * expressible.
 *
 * **`archived`** (remaining-ops G-I, 2026-10-21; API-19c limb 1, now that `IA.md` §5.6's `/items` Archived row is no
 * longer `⛔ D-a`): `true`/`1` serve the archived items instead of the live ones (TB-3), `false`/`0` or absent the live
 * ones, anything else 422 `not_a_boolean` (`CustomerCollectionProvider`'s handling, copied — modules do not share
 * providers). With `supplier_id` it is a 422 `mutually_exclusive` at `?archived` (`SalesOrderCollectionProvider`'s
 * precedent for `customer_id`; the stricter default, `UX`/`SEC` to confirm). **A cursor whose key is not a timestamp sent
 * to the Archived view is `malformed_cursor`**: a live-view `sku` cursor would otherwise reach `?::timestamptz` and 500.
 *
 * @implements ProviderInterface<ItemCollection>
 */
final readonly class ItemCollectionProvider implements ProviderInterface
{
    /**
     * The one order the sort manifest gives `/items`, and the order
     * `ItemReader` writes into its `ORDER BY`.
     *
     * **`sort` is not a declared parameter today**, so a request carrying one is
     * refused by `Platform\Http\UndeclaredQueryParameterRefusal` and the `unserved_sort`
     * branch in `criteria()` is not reached. That branch is kept rather than
     * deleted because it is the half that has to be right on the day D-b closes:
     * `/items` acquires `inventory_item_tenant_name_live`, the parameter comes
     * back (`ItemOperationContractTest::itemsOffersSortIfAndOnlyIfItsOrderingIndexIsMigrated`
     * makes that unavoidable), and API-19's rule -- **refuse** an unserved value
     * rather than ignore it -- has to hold from the first request. A server that
     * silently ignores an unserved sort returns rows in an order the screen
     * claims and the database did not produce.
     */
    public const SORT_SKU = 'sku';

    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    public function __construct(private ItemReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ItemCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        return $this->reader->list(self::criteriaFrom($query));
    }

    /**
     * **The list's whole pre-read parse, shared with `CreateExport`** (G6-C0, design Response M-5): the provider and
     * {@see ItemExportSource} both call this, so an export's filters are validated, combined and refused exactly as the
     * list's are — by construction, not by a second copy (TB-7). `$limit` is the export's cap, an explicit argument the wire
     * never reaches (`limit` is not an export filter; API-14a): when given it replaces the parsed page size, unbounded by
     * {@see self::MAXIMUM_LIMIT}, and the reader's `LIMIT limit + 1` reports whether the view is over it.
     *
     * @param array<string, mixed> $query
     */
    public static function criteriaFrom(array $query, ?int $limit = null): ItemListCriteria
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

        $supplierId = null;
        if (isset($query['supplier_id'])) {
            $raw = $query['supplier_id'];
            if (!\is_string($raw) || !Uuid::isValid($raw)) {
                $errors[] = self::error('supplier_id', 'not_a_uuid', 'supplier_id must be a UUID.');
            } else {
                $supplierId = $raw;
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

        if ($archived && isset($query['supplier_id'])) {
            // The Archived view is a sibling of the live list (TB-3a), and its index serves no supplier filter, so the
            // combination is refused rather than served unindexed — `ListSalesOrders`' stricter default for `customer_id`
            // (order restores C2). Loosening later breaks no client; the code, pointer and copy are TO CONFIRM by `UX`/`SEC`.
            $errors[] = self::error('archived', 'mutually_exclusive',
                'The archived items are their own view: send archived without supplier_id.');
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

        // Unreachable while `sort` is undeclared -- see `SORT_SKU`. Kept as the
        // refusal that has to be in place the moment D-b re-arms the parameter.
        if (isset($query['sort']) && self::SORT_SKU !== $query['sort']) {
            $errors[] = self::error('sort', 'unserved_sort', \sprintf('The only order this list offers is "%s".', self::SORT_SKU));
        }

        if (isset($query['after'], $query['before'])) {
            $errors[] = self::error('after', 'mutually_exclusive', 'Send at most one of after and before.');
        }

        $after = self::cursor($query, 'after', $archived, $errors);
        $before = self::cursor($query, 'before', $archived, $errors);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return new ItemListCriteria(
            limit: $limit,
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
    private static function cursor(array $query, string $name, bool $archived, array &$errors): ?Cursor
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

        // The Archived view's key is cast `::timestamptz`, so a cursor whose key is not a timestamp (an `sku` cursor from the
        // live view) is refused here rather than 500 there. The reverse — an archived cursor sent to the live view — is a
        // text position among SKUs, served: `Cursor` carries no view marker, and it seeks only within rows the caller may
        // read (`SalesOrderCollectionProvider`'s recorded M-2 shape). The check is the shared `TimestampSortKey` (cf01f17), which
        // also holds G-I RED M-1's narrowing (offset hour <= 15) that this class first carried in a private copy.
        if ($archived && !TimestampSortKey::isValid($cursor->sortKey)) {
            $errors[] = self::error($name, 'malformed_cursor', \sprintf('%s must be a cursor returned by a previous response for this view.', $name));

            return null;
        }

        return $cursor;
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $parameter, string $code, string $message): array
    {
        // A query parameter is not addressable by a JSON pointer into a body
        // this operation does not have, so it is named with the `?` form.
        return ['pointer' => '?'.$parameter, 'code' => $code, 'message' => $message];
    }
}
