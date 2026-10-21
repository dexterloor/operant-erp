<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\InvoiceCollection;
use App\Modules\Sales\ApiResource\InvoiceResource;
use App\Modules\Sales\Application\InvoiceListCriteria;
use App\Modules\Sales\Application\InvoiceReader;
use App\Modules\Sales\Application\InvoiceViews;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Pagination\TimestampSortKey;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `ListInvoices`' parameters, checked before the reader runs (`SalesOrderCollectionProvider`'s shape). An unserved
 * `sort` is **refused**, never ignored (API-19), per view (`InvoiceViews::legalSorts()`). A cursor whose key cannot be
 * the view's sort key — a register cursor sent to a `due_at` view — is `malformed_cursor`, not a 500
 * (`FulfilmentCollectionProvider`'s precedent).
 *
 * @implements ProviderInterface<InvoiceCollection>
 */
final readonly class InvoiceCollectionProvider implements ProviderInterface
{
    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    public function __construct(private InvoiceReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvoiceCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $page = $this->reader->list($this->criteria($query));

        return new InvoiceCollection(
            items: array_map(InvoiceResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /** @param array<string, mixed> $query */
    private function criteria(array $query): InvoiceListCriteria
    {
        $errors = [];

        $limit = self::DEFAULT_LIMIT;
        if (isset($query['limit'])) {
            $raw = $query['limit'];
            if (!\is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1 || (int) $raw > self::MAXIMUM_LIMIT) {
                $errors[] = self::error('limit', 'out_of_range', \sprintf('limit must be a whole number between 1 and %d.', self::MAXIMUM_LIMIT));
            } else {
                $limit = (int) $raw;
            }
        }

        $statuses = [];
        $credit = null;
        $segment = null;
        if (isset($query['status'])) {
            $raw = $query['status'];
            if (!\is_string($raw) || !\array_key_exists($raw, InvoiceViews::SEGMENTS)) {
                $errors[] = self::error('status', 'unknown_segment', \sprintf('status must be one of %s.', implode(', ', InvoiceViews::SEGMENT_NAMES)));
            } else {
                // A segment is its stored statuses AND its credit condition (TB-4 ³): Unpaid and Credited share statuses.
                $segment = $raw;
                $statuses = InvoiceViews::SEGMENTS[$raw]['statuses'];
                $credit = InvoiceViews::SEGMENTS[$raw]['credit'];
            }
        }

        $customerId = self::uuid($query, 'customer_id', $errors);
        $fulfilmentId = self::uuid($query, 'fulfilment_id', $errors);

        $search = null;
        if (isset($query['q'])) {
            $raw = $query['q'];
            if (!\is_string($raw)) {
                $errors[] = self::error('q', 'not_a_string', 'q must be a string.');
            } elseif ('' !== $raw) {
                $search = $raw;
            }
        }

        $legal = InvoiceViews::legalSorts($segment, null !== $customerId, null !== $fulfilmentId);
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

        return new InvoiceListCriteria(
            limit: $limit,
            sort: $sort,
            statuses: $statuses,
            credit: $credit,
            customerId: $customerId,
            fulfilmentId: $fulfilmentId,
            search: $search,
            after: $after,
            before: $before,
        );
    }

    /**
     * @param array<string, mixed>                                        $query
     * @param list<array{pointer: string, code: string, message: string}> $errors
     */
    private static function uuid(array $query, string $name, array &$errors): ?string
    {
        if (!isset($query[$name])) {
            return null;
        }
        $raw = $query[$name];
        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            $errors[] = self::error($name, 'not_a_uuid', $name.' must be a UUID.');

            return null;
        }

        return $raw;
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

        if (null === $cursor || (InvoiceViews::isTimestampSort($sort) && !TimestampSortKey::isValid($cursor->sortKey))) {
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
