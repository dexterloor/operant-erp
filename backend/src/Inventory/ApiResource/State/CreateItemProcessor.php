<?php

declare(strict_types=1);

namespace App\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Inventory\ApiResource\CreateItemInput;
use App\Inventory\ApiResource\ItemResource;
use App\Inventory\Application\ItemWriter;

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
