<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\InvoiceLineCollection;
use App\Modules\Sales\ApiResource\InvoiceLineResource;
use App\Modules\Sales\Application\InvoiceReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `ListInvoiceLines` — no parameter at all: the list is `(bounded)`, so `limit`, `after`, `before` and `sort` are
 * undeclared and refused before this runs (`UndeclaredQueryParameterRefusal`). An invoice this workspace cannot see,
 * or none, is a 404 (E-2 / ST-5).
 *
 * @implements ProviderInterface<InvoiceLineCollection>
 */
final readonly class InvoiceLineCollectionProvider implements ProviderInterface
{
    public function __construct(private InvoiceReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvoiceLineCollection
    {
        $lines = $this->reader->lines((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return new InvoiceLineCollection(
            items: array_map(InvoiceLineResource::fromView(...), $lines),
            // §4.4.2: a bounded list carries both cursors null always.
            nextCursor: null,
            previousCursor: null,
            // An existing invoice always bills at least one shipped line (a fulfilment has lines), so the probe is moot.
            tenantHasRows: true,
        );
    }
}
