<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Inventory\ApiResource\CreateItemInput;
use App\Modules\Inventory\ApiResource\ItemResource;
use App\Modules\Inventory\Application\ItemWriter;

/**
 * `CreateItem` — API-21 branch 4: §4.2 declares `GetItem` at `/items/{id}`,
 * which is the row-level URI this POST creates, so the answer is **201** with a
 * `Location` naming it and the created resource as the body.
 *
 * @implements ProcessorInterface<CreateItemInput, ItemResource>
 */
final readonly class CreateItemProcessor implements ProcessorInterface
{
    public function __construct(private ItemWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ItemResource
    {
        \assert($data instanceof CreateItemInput);

        return $this->writer->create($data);
    }
}
