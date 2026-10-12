<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Suppliers\ApiResource\SupplierCollection;
use App\Modules\Suppliers\ApiResource\SupplierResource;
use App\Modules\Suppliers\Application\SupplierListCriteria;
use App\Modules\Suppliers\Application\SupplierReader;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * `ListSuppliers` — parameter parsing and the refusals API-19/API-19b require. An undeclared parameter never reaches this
 * class (`Platform\Http\UndeclaredQueryParameterRefusal`); `archived` is undeclared on purpose (API-19c limb 1).
 *
 * @implements ProviderInterface<SupplierCollection>
 */
final readonly class SupplierCollectionProvider implements ProviderInterface
{
    public function __construct(private SupplierReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SupplierCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $page = $this->reader->list($this->criteria($query));

        return new SupplierCollection(
            items: array_map(SupplierResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /** @param array<string, mixed> $query */
    private function criteria(array $query): SupplierListCriteria
    {
        $errors = [];

        $limit = SupplierListCriteria::DEFAULT_LIMIT;
        if (isset($query['limit'])) {
            $raw = $query['limit'];
            if (!\is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1 || (int) $raw > SupplierListCriteria::MAXIMUM_LIMIT) {
                $errors[] = self::error('limit', 'out_of_range', \sprintf('limit must be a whole number between 1 and %d.', SupplierListCriteria::MAXIMUM_LIMIT));
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

        $sort = SupplierListCriteria::SORTS[0];
        if (isset($query['sort'])) {
            $raw = $query['sort'];
            if (!\is_string($raw) || !\in_array($raw, SupplierListCriteria::SORTS, true)) {
                $errors[] = self::error('sort', 'unserved_sort', \sprintf('The orders this list offers are %s.', implode(' and ', SupplierListCriteria::SORTS)));
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

        return new SupplierListCriteria(limit: $limit, sort: $sort, search: $search, after: $after, before: $before);
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
