<?php

declare(strict_types=1);

namespace App\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Problem\ApiProblem;
use App\Inventory\ApiResource\ItemResource;
use App\Inventory\Application\ItemReader;

/**
 * `GetItem`.
 *
 * §5.3 clause 2: a row RLS does not return is `not-found`, never
 * `permission-denied` -- a 403 on a cross-tenant id confirms the id exists
 * somewhere. An id that is not a uuid at all takes the same path, so all three
 * of "another tenant's", "hard-deleted" and "never existed" answer identically.
 *
 * @implements ProviderInterface<ItemResource>
 */
final readonly class ItemProvider implements ProviderInterface
{
    public function __construct(private ItemReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ItemResource
    {
        return $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();
    }
}
