<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\SalesOrderLineResource;
use App\Modules\Sales\Application\SalesOrderReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * The row load for the sales line's item-level write operations.
 *
 * It exists for `PurchaseOrderLineProvider`'s reason: without it, API Platform's default provider
 * answers a `PATCH` at an id no row holds with a bare `NotFoundHttpException` rather than the same §5.2
 * `not-found` body a read of that id would produce, and §5.3 clause 2 requires the two to be the same
 * refusal.
 *
 * **The pair `(id, lineId)` is what names the row**, so a `lineId` belonging to a different order is
 * `not-found` and not an edit of somebody else's line. The writer re-reads under the same predicate
 * inside its transaction, because a provider's read is outside it.
 *
 * §5.2a: a soft-deleted line inside the caller's tenant is **read**, not refused — there is no
 * `deleted_at` predicate here — and the refusal on writing it comes from the writer with
 * `business-rule-violated`.
 *
 * @implements ProviderInterface<SalesOrderLineResource>
 */
final readonly class SalesOrderLineProvider implements ProviderInterface
{
    public function __construct(private SalesOrderReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SalesOrderLineResource
    {
        return $this->reader->getLine(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['lineId'] ?? ''),
        ) ?? throw ApiProblem::notFound();
    }
}
