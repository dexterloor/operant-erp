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

        return $this->reader->list($this->criteria($query));
    }

    /** @param array<string, mixed> $query */
    private function criteria(array $query): PurchaseOrderListCriteria
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

        $legal = PurchaseOrderViews::legalSorts($statuses, null !== $supplierId);
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

        $after = self::cursor($query, 'after', $errors);
        $before = self::cursor($query, 'before', $errors);

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
            return Cursor::decode((string) $query[$name]);
        } catch (InvalidCursor) {
            $errors[] = self::error($name, 'malformed_cursor', \sprintf('%s must be a cursor returned by a previous response.', $name));

            return null;
        }
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $parameter, string $code, string $message): array
    {
        return ['pointer' => '?'.$parameter, 'code' => $code, 'message' => $message];
    }
}
