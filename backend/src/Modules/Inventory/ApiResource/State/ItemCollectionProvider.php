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
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `ListItems` — parameter parsing, and the refusals API-19/API-19b require.
 *
 * The **accepted** parameter set is read off the operation's own declared
 * parameters, never from a list in this class. §4.2's `Query parameters` column
 * is exhaustive, `ItemCollection` declares exactly that column, and the spec
 * documents the same set -- so a parameter accepted here but undocumented, or
 * documented and refused, is not expressible.
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
     * refused by `refuseUndeclaredParameters()` below and the `unserved_sort`
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

        $this->refuseUndeclaredParameters($operation, $query);

        return $this->reader->list($this->criteria($query));
    }

    /** @param array<string, mixed> $query */
    private function refuseUndeclaredParameters(Operation $operation, array $query): void
    {
        $declared = [];
        foreach ($operation->getParameters() ?? [] as $key => $parameter) {
            $declared[] = (string) ($parameter->getKey() ?? $key);
        }

        $undeclared = array_diff(array_keys($query), $declared);
        if ([] !== $undeclared) {
            throw ApiProblem::validationFailed(array_map(
                static fn (string $name): array => [
                    'pointer' => '?'.$name,
                    'code' => 'undeclared_parameter',
                    'message' => \sprintf('This operation declares no query parameter named "%s".', $name),
                ],
                array_values($undeclared),
            ));
        }
    }

    /** @param array<string, mixed> $query */
    private function criteria(array $query): ItemListCriteria
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

        // Unreachable while `sort` is undeclared -- see `SORT_SKU`. Kept as the
        // refusal that has to be in place the moment D-b re-arms the parameter.
        if (isset($query['sort']) && self::SORT_SKU !== $query['sort']) {
            $errors[] = self::error('sort', 'unserved_sort', \sprintf('The only order this list offers is "%s".', self::SORT_SKU));
        }

        if (isset($query['after'], $query['before'])) {
            $errors[] = self::error('after', 'mutually_exclusive', 'Send at most one of after and before.');
        }

        $after = $this->cursor($query, 'after', $errors);
        $before = $this->cursor($query, 'before', $errors);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return new ItemListCriteria(
            limit: $limit,
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
    private function cursor(array $query, string $name, array &$errors): ?Cursor
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
        // A query parameter is not addressable by a JSON pointer into a body
        // this operation does not have, so it is named with the `?` form.
        return ['pointer' => '?'.$parameter, 'code' => $code, 'message' => $message];
    }
}
