<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Inventory\ApiResource\StockMovementCollection;
use App\Modules\Inventory\ApiResource\StockMovementResource;
use App\Modules\Inventory\Application\StockMovementListCriteria;
use App\Modules\Inventory\Application\StockMovementReader;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `ListStockMovements` — parameter parsing and the refusals API-17/API-18 require (`FulfilmentCollectionProvider`'s shape,
 * less the search). The gate has already refused a caller without `stock_movement.view` (E-1), and anything but the four
 * declared parameters is refused before this class runs (`UndeclaredQueryParameterRefusal`).
 *
 * **One refusal of its own: a cursor whose sort key is not a timestamp** — a cursor minted by another list (`/items`'
 * `sku`, a register's number) followed here would reach Postgres as `'SKU-1'::timestamptz` and raise: a 500 from a
 * client mistake. It is refused as `malformed_cursor` instead (design R-L L-1; `CustomerCollectionProvider` precedent).
 *
 * @implements ProviderInterface<StockMovementCollection>
 */
final readonly class StockMovementCollectionProvider implements ProviderInterface
{
    public function __construct(private StockMovementReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StockMovementCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $page = $this->reader->list($this->criteria($query));

        return new StockMovementCollection(
            items: array_map(StockMovementResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /** @param array<string, mixed> $query */
    private function criteria(array $query): StockMovementListCriteria
    {
        $errors = [];

        $limit = StockMovementListCriteria::DEFAULT_LIMIT;
        if (isset($query['limit'])) {
            $raw = $query['limit'];
            if (!\is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1 || (int) $raw > StockMovementListCriteria::MAXIMUM_LIMIT) {
                $errors[] = self::error('limit', 'out_of_range', \sprintf('limit must be a whole number between 1 and %d.', StockMovementListCriteria::MAXIMUM_LIMIT));
            } else {
                $limit = (int) $raw;
            }
        }

        $itemId = null;
        if (isset($query['inventory_item_id']) && '' !== $query['inventory_item_id']) {
            $raw = $query['inventory_item_id'];
            if (!\is_string($raw) || !Uuid::isValid($raw)) {
                $errors[] = self::error('inventory_item_id', 'not_a_uuid', 'inventory_item_id must be a UUID.');
            } else {
                $itemId = Uuid::fromString($raw)->toRfc4122();
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

        return new StockMovementListCriteria(limit: $limit, inventoryItemId: $itemId, after: $after, before: $before);
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

        if (null === $cursor || !self::isTimestamp($cursor->sortKey)) {
            $errors[] = self::error($name, 'malformed_cursor', \sprintf('%s must be a cursor returned by a previous response for this view.', $name));

            return null;
        }

        return $cursor;
    }

    /** A `timestamptz` exactly as Postgres renders one in this project's sessions (`2026-09-02 00:00:00.123456+00`). */
    private static function isTimestamp(string $value): bool
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2})?$/', $value)) {
            return false;
        }

        $parsed = date_parse($value);

        return 0 === $parsed['error_count'] && 0 === $parsed['warning_count']
            && checkdate((int) $parsed['month'], (int) $parsed['day'], (int) $parsed['year']);
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $parameter, string $code, string $message): array
    {
        return ['pointer' => '?'.$parameter, 'code' => $code, 'message' => $message];
    }
}
