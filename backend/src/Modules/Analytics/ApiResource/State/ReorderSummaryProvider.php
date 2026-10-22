<?php

declare(strict_types=1);

namespace App\Modules\Analytics\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Analytics\ApiResource\ReorderSummaryResource;
use App\Modules\Analytics\Application\ReorderSuggester;

/**
 * `GetReorderSummary`. The gate has already refused a caller without `forecast.view` (E-1). Counts the listed items only —
 * no catalogue read (RED L-1).
 *
 * @implements ProviderInterface<ReorderSummaryResource>
 */
final readonly class ReorderSummaryProvider implements ProviderInterface
{
    public function __construct(private ReorderSuggester $suggester)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReorderSummaryResource
    {
        return new ReorderSummaryResource($this->suggester->count());
    }
}
