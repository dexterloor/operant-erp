<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\Application\PurchaseOrderReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetPurchaseOrder`, and the row load for `UpdatePurchaseOrder` and
 * `DeletePurchaseOrder`.
 *
 * §5.3 clause 2: a row RLS does not return is `not-found`, never
 * `permission-denied` — a 403 on a cross-tenant id confirms the id exists
 * somewhere. An id that is not a uuid at all takes the same path, so all three of
 * "another tenant's", "hard-deleted" and "never existed" answer identically.
 *
 * **`ApprovePurchaseOrder` deliberately does not use this provider** and declares
 * `read: false` instead. Its row has to be loaded inside the transaction that
 * writes it — §8.1's steps 2 to 5 are one atomic sequence — and a provider runs
 * before the processor opens one.
 *
 * @implements ProviderInterface<PurchaseOrderResource>
 */
final readonly class PurchaseOrderProvider implements ProviderInterface
{
    public function __construct(private PurchaseOrderReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderResource
    {
        return $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();
    }
}
