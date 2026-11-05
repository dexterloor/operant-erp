<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\PickQueueEntryCollection;
use App\Modules\Sales\ApiResource\PickQueueEntryResource;
use App\Modules\Sales\Application\PickQueueCriteria;
use App\Modules\Sales\Application\PickQueueReader;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Pagination\TimestampSortKey;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `ListPickQueueEntries` — parameter parsing and the refusals API-17/API-18 require. `FulfilmentCollectionProvider`'s
 * shape, with `warehouse_id` for `sales_order_id`. The cursor's key is always `ordered_at`, so a cursor whose key is not a
 * timestamp is refused as `malformed_cursor` here, before it can reach `?::timestamptz` as a 500.
 *
 * @implements ProviderInterface<PickQueueEntryCollection>
 */
final readonly class PickQueueEntryCollectionProvider implements ProviderInterface
{
    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    public function __construct(private PickQueueReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PickQueueEntryCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $page = $this->reader->list(self::criteriaFrom($query));

        return new PickQueueEntryCollection(
            items: array_map(PickQueueEntryResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function criteriaFrom(array $query): PickQueueCriteria
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

        $warehouseId = null;
        if (isset($query['warehouse_id'])) {
            $raw = $query['warehouse_id'];
            if (!\is_string($raw) || !Uuid::isValid($raw)) {
                $errors[] = self::error('warehouse_id', 'not_a_uuid', 'warehouse_id must be a UUID.');
            } else {
                $warehouseId = $raw;
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

        if (isset($query['after'], $query['before'])) {
            $errors[] = self::error('after', 'mutually_exclusive', 'Send at most one of after and before.');
        }

        $after = self::cursor($query, 'after', $errors);
        $before = self::cursor($query, 'before', $errors);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return new PickQueueCriteria(
            limit: $limit,
            warehouseId: $warehouseId,
            search: $search,
            after: $after,
            before: $before,
        );
    }

    /**
     * @param array<string, mixed>                                        $query
     * @param list<array{pointer: string, code: string, message: string}> $errors
     */
    private static function cursor(array $query, string $name, array &$errors): ?Cursor
    {
        if (!isset($query[$name]) || '' === $query[$name]) {
            return null;
        }

        try {
            $cursor = Cursor::decode((string) $query[$name]);
        } catch (InvalidCursor) {
            $cursor = null;
        }

        if (null === $cursor || !TimestampSortKey::isValid($cursor->sortKey)) {
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
