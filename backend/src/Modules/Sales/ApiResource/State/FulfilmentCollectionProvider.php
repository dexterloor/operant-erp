<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\FulfilmentCollection;
use App\Modules\Sales\ApiResource\FulfilmentResource;
use App\Modules\Sales\Application\FulfilmentListCriteria;
use App\Modules\Sales\Application\FulfilmentReader;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `ListFulfilments` — parameter parsing and the refusals API-17/API-18 require. `SalesOrderCollectionProvider`'s
 * shape, less the segment and the sort (this route has neither).
 *
 * **One refusal the sales-order list does not need:** a cursor whose sort key cannot be this view's. The register
 * compares `fulfilment_number` (text) and an order's view compares `shipped_at` (`timestamptz`), so a register
 * cursor followed on an order's view would reach Postgres as `'FUL-000001'::timestamptz` and raise — a 500 from a
 * client mistake. It is refused as `malformed_cursor` instead.
 *
 * @implements ProviderInterface<FulfilmentCollection>
 */
final readonly class FulfilmentCollectionProvider implements ProviderInterface
{
    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    public function __construct(private FulfilmentReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FulfilmentCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $page = $this->reader->list($this->criteria($query));

        return new FulfilmentCollection(
            items: array_map(FulfilmentResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /** @param array<string, mixed> $query */
    private function criteria(array $query): FulfilmentListCriteria
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

        $salesOrderId = null;
        if (isset($query['sales_order_id'])) {
            $raw = $query['sales_order_id'];
            if (!\is_string($raw) || !Uuid::isValid($raw)) {
                $errors[] = self::error('sales_order_id', 'not_a_uuid', 'sales_order_id must be a UUID.');
            } else {
                $salesOrderId = $raw;
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

        $byShipment = null !== $salesOrderId;
        $after = self::cursor($query, 'after', $byShipment, $errors);
        $before = self::cursor($query, 'before', $byShipment, $errors);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return new FulfilmentListCriteria(
            limit: $limit,
            salesOrderId: $salesOrderId,
            search: $search,
            after: $after,
            before: $before,
        );
    }

    /**
     * @param array<string, mixed>                                        $query
     * @param list<array{pointer: string, code: string, message: string}> $errors
     */
    private static function cursor(array $query, string $name, bool $byShipment, array &$errors): ?Cursor
    {
        if (!isset($query[$name]) || '' === $query[$name]) {
            return null;
        }

        try {
            $cursor = Cursor::decode((string) $query[$name]);
        } catch (InvalidCursor) {
            $cursor = null;
        }

        if (null === $cursor || ($byShipment && !self::isTimestamp($cursor->sortKey))) {
            $errors[] = self::error($name, 'malformed_cursor', \sprintf('%s must be a cursor returned by a previous response for this view.', $name));

            return null;
        }

        return $cursor;
    }

    /** A `timestamptz` exactly as Postgres renders one in this project's sessions (`2026-09-02 00:00:00.123+00`). */
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
