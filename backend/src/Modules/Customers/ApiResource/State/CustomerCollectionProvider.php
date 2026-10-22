<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Customers\ApiResource\CustomerCollection;
use App\Modules\Customers\ApiResource\CustomerResource;
use App\Modules\Customers\Application\CustomerListCriteria;
use App\Modules\Customers\Application\CustomerReader;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Pagination\TimestampSortKey;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * `ListCustomers` — parameter parsing and the refusals API-19/API-19b require. An undeclared parameter never reaches this
 * class (`Platform\Http\UndeclaredQueryParameterRefusal`).
 *
 * **`archived`** (C5 C4a; API-19c limb 1, now that `/customers` Archived is no longer `⛔ D-a`): `true`/`1` serve the
 * archived customers instead of the live ones (TB-3), `false`/`0` or absent the live ones, anything else is 422
 * `not_a_boolean` (`WarehouseCollectionProvider::archived()`'s handling, copied rather than imported — modules do not
 * share providers). **`sort` is per view** ({@see CustomerListCriteria::legalSorts()}): `name`/`code` on the live view,
 * `archived_at` on the Archived view; the other view's value is `unserved_sort`. **A cursor whose key is not a
 * timestamp sent to the Archived view is `malformed_cursor`** (`InvoiceCollectionProvider`'s precedent): a live-view
 * cursor would otherwise reach `?::timestamptz` and 500 (design §4.2 trap 2).
 *
 * @implements ProviderInterface<CustomerCollection>
 */
final readonly class CustomerCollectionProvider implements ProviderInterface
{
    public function __construct(private CustomerReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $page = $this->reader->list(self::criteriaFrom($query));

        return new CustomerCollection(
            items: array_map(CustomerResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /**
     * **The list's whole pre-read parse, shared with `CreateExport`** (G6-C1, design Response M-5): the provider and {@see CustomerExportSource} both call this, so an
     * export's filters are validated, combined and refused exactly as the list's are — by construction, not by a second copy
     * (TB-7). `$limit` is the export's cap, an explicit argument the wire never reaches (`limit` is not an export filter;
     * API-14a): when given it replaces the parsed page size, unbounded by `MAXIMUM_LIMIT`, and the reader's `LIMIT limit + 1`
     * reports whether the view is over it.
     *
     * @param array<string, mixed> $query
     */
    public static function criteriaFrom(array $query, ?int $limit = null): CustomerListCriteria
    {
        $errors = [];

        $limit ??= CustomerListCriteria::DEFAULT_LIMIT;
        if (isset($query['limit'])) {
            $raw = $query['limit'];
            if (!\is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1 || (int) $raw > CustomerListCriteria::MAXIMUM_LIMIT) {
                $errors[] = self::error('limit', 'out_of_range', \sprintf('limit must be a whole number between 1 and %d.', CustomerListCriteria::MAXIMUM_LIMIT));
            } else {
                $limit = (int) $raw;
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

        $legal = CustomerListCriteria::legalSorts($archived);
        $sort = $legal[0];
        if (isset($query['sort'])) {
            $raw = $query['sort'];
            if (!\is_string($raw) || !\in_array($raw, $legal, true)) {
                $errors[] = self::error('sort', 'unserved_sort', \sprintf('The orders this view offers are %s.', implode(' and ', $legal)));
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

        return new CustomerListCriteria(limit: $limit, sort: $sort, search: $search, after: $after, before: $before, archived: $archived);
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
            $cursor = null;
        }

        if (null === $cursor) {
            $errors[] = self::error($name, 'malformed_cursor', \sprintf('%s must be a cursor returned by a previous response.', $name));

            return null;
        }
        if (CustomerListCriteria::SORT_ARCHIVED_AT === $sort && !TimestampSortKey::isValid($cursor->sortKey)) {
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
