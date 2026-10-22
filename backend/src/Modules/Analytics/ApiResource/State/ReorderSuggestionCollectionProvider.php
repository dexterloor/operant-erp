<?php

declare(strict_types=1);

namespace App\Modules\Analytics\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Analytics\ApiResource\ReorderSuggestionCollection;
use App\Modules\Analytics\ApiResource\ReorderSuggestionResource;
use App\Modules\Analytics\Application\ReorderSuggester;

/**
 * `ListReorderSuggestions`. The gate has already refused a caller lacking any of the six codes (E-1). The operation declares
 * no query parameter, so any parameter is refused before this class runs (`UndeclaredQueryParameterRefusal`).
 *
 * @implements ProviderInterface<ReorderSuggestionCollection>
 */
final readonly class ReorderSuggestionCollectionProvider implements ProviderInterface
{
    public function __construct(private ReorderSuggester $suggester)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReorderSuggestionCollection
    {
        $result = $this->suggester->suggestions();

        return new ReorderSuggestionCollection(
            items: array_map(ReorderSuggestionResource::fromView(...), $result['suggestions']),
            // §4.4.2: a bounded list carries both cursors null always.
            nextCursor: null,
            previousCursor: null,
            // API-22: does the workspace hold any item at all — distinguishes "nothing needs reordering" from an empty workspace.
            tenantHasRows: $result['tenantHasItems'],
        );
    }
}
