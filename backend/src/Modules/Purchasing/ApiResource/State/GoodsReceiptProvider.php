<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Purchasing\ApiResource\GoodsReceiptResource;
use App\Modules\Purchasing\Application\GoodsReceiptReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetGoodsReceipt`. Another workspace's receipt and one that does not exist
 * are the same `not-found` (§5.3): RLS makes them indistinguishable.
 *
 * @implements ProviderInterface<GoodsReceiptResource>
 */
final readonly class GoodsReceiptProvider implements ProviderInterface
{
    public function __construct(private GoodsReceiptReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GoodsReceiptResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return GoodsReceiptResource::fromView($view);
    }
}
