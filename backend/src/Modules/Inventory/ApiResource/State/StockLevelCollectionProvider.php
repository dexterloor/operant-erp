<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Inventory\ApiResource\StockLevelCollection;
use App\Modules\Inventory\ApiResource\StockLevelResource;
use App\Modules\Inventory\Application\StockLevelReader;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * `ListStockLevels`. Order of refusals (design R-FINAL.1 step 2): the gate has already refused a caller without
 * `stock_level.view` (E-1); `UndeclaredQueryParameterRefusal` has already refused any parameter but the two scopes; a
 * malformed uuid has been refused against the parameter schema. What is left is API-19b's **exactly one**: neither or both
 * is 422 `validation-failed`, `exactly_one_scope` on both pointers, with `UX`'s copy (`stock-reads-ux.md` §4).
 *
 * @implements ProviderInterface<StockLevelCollection>
 */
final readonly class StockLevelCollectionProvider implements ProviderInterface
{
    public const NEITHER_SCOPE = 'storage_location_id or inventory_item_id is required: stock is listed for one bin or one item.';
    public const BOTH_SCOPES = 'Send storage_location_id or inventory_item_id, not both: stock is listed for one bin or one item.';

    public function __construct(private StockLevelReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StockLevelCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        [$scope, $id] = self::scopeFrom($query);
        $read = $this->reader->list($scope, $id);

        return new StockLevelCollection(
            items: array_map(StockLevelResource::fromView(...), $read['items']),
            // §4.4.2: a bounded list carries both cursors null always.
            nextCursor: null,
            previousCursor: null,
            tenantHasRows: $read['tenantHasRows'],
        );
    }

    /**
     * **The list's whole pre-read parse, shared with `CreateExport`** (G6-C0, design Response M-5): exactly one of the two
     * scopes, refused as the list refuses it. Both the provider and {@see StockLevelExportSource} call this.
     *
     * @param array<string, mixed> $query
     *
     * @return array{0: string, 1: string} the scope parameter and its value
     */
    public static function scopeFrom(array $query): array
    {
        $given = array_values(array_filter(
            [StockLevelReader::SCOPE_LOCATION, StockLevelReader::SCOPE_ITEM],
            static fn (string $scope): bool => isset($query[$scope]) && '' !== $query[$scope],
        ));

        if (1 !== \count($given)) {
            $message = [] === $given ? self::NEITHER_SCOPE : self::BOTH_SCOPES;
            throw ApiProblem::validationFailed([
                ['pointer' => '?'.StockLevelReader::SCOPE_LOCATION, 'code' => 'exactly_one_scope', 'message' => $message],
                ['pointer' => '?'.StockLevelReader::SCOPE_ITEM, 'code' => 'exactly_one_scope', 'message' => $message],
            ]);
        }

        $scope = $given[0];

        return [$scope, \is_string($query[$scope]) ? $query[$scope] : ''];
    }
}
