<?php

declare(strict_types=1);

namespace App\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Inventory\ApiResource\ItemResource;
use App\Inventory\Application\ItemWriter;

/**
 * `RestoreItem` — API-12a / ST-9. The permission is the one that archived it
 * (`inventory_item.delete`), and the response is API-21 branch 5: 200 with the
 * resource in its **new** state, so ST-11's confirmation renders from the
 * response rather than from a refetch.
 *
 * @implements ProcessorInterface<mixed, ItemResource>
 */
final readonly class RestoreItemProcessor implements ProcessorInterface
{
    public function __construct(private ItemWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ItemResource
    {
        return $this->writer->restore((string) ($uriVariables['id'] ?? ''));
    }
}
