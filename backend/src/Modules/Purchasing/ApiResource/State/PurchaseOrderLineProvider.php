<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource;
use App\Modules\Purchasing\Application\PurchaseOrderReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * The row load for `UpdatePurchaseOrderLine` and `DeletePurchaseOrderLine`.
 *
 * It exists for the reason `ItemProvider` is wired onto `UpdateItem`: without it,
 * API Platform's default provider answers a `PATCH` at an id no row holds with a
 * bare `NotFoundHttpException` rather than the same §5.2 `not-found` body a
 * read of that id would produce, and §5.3 clause 2 requires the two to be the
 * same refusal.
 *
 * **The pair `(id, lineId)` is what names the row**, so a `lineId` that belongs
 * to a different order is `not-found` and not an edit of somebody else's line.
 * The writer re-reads under the same predicate inside its transaction, because a
 * provider's read is outside it.
 *
 * §5.2a: a soft-deleted line inside the caller's tenant is **read**, not refused
 * — there is no `deleted_at` predicate here — and the refusal on writing it comes
 * from the writer with `business-rule-violated`.
 *
 * @implements ProviderInterface<PurchaseOrderLineResource>
 */
final readonly class PurchaseOrderLineProvider implements ProviderInterface
{
    public function __construct(private PurchaseOrderReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderLineResource
    {
        return $this->reader->getLine(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['lineId'] ?? ''),
        ) ?? throw ApiProblem::notFound();
    }
}
