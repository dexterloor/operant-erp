<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\SalesOrderLineResource;
use App\Modules\Sales\Application\SalesOrderWriter;

/**
 * `RestoreSalesOrderLine` — clears `deleted_at` on a line the caller already holds the id of, so **200
 * with the resource in its new state, and no `Location`**. `RestoreItem` is the shipped model.
 *
 * ⚠ **This ships while `RestorePurchaseOrderLine` does not, and the asymmetry is deliberate.** The
 * purchase twin's own processor concedes that API-O-11's obtainability ground is false for a line restore
 * — `ListPurchaseOrderLines` offers `archived`, and so does `ListSalesOrderLines` — and substitutes a
 * coherence argument: *"shipping the child restore alone would leave a line restorable inside an order
 * that is not."* **That argument is answered here by guards rather than by absence.**
 * `refuseIfArchived($order)` and `refuseUnlessDraft($order)` run in front of the restore, so a
 * cascade-archived line cannot be restored at all — its order is archived and the first guard refuses.
 * The only restorable line is one deleted individually while its order was a live draft, which is
 * coherent by construction.
 *
 * ⚠ **The reason to build it now is REACHABILITY, not symmetry.** `sales_order_line_tenant_idem_live` is
 * partial on `deleted_at IS NULL`, so an archived line releases its retry key and a live line may take
 * it. Restoring the first then puts two live rows on one key — `23505`. `RED` measured that hazard and
 * measured that it has **two** triggers, not one: this operation, which owns its statement and translates
 * the violation by name, and `RestoreSalesOrder`, whose `sales_order_cascade_deletion` trigger sets
 * **every** line live in one statement it did not write. Deferring this operation would not defer the
 * hazard; it would relocate it into a trigger-raised violation inside a commit about orders.
 *
 * @implements ProcessorInterface<mixed, SalesOrderLineResource>
 */
final readonly class RestoreSalesOrderLineProcessor implements ProcessorInterface
{
    public function __construct(private SalesOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SalesOrderLineResource
    {
        return $this->writer->restoreLine(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['lineId'] ?? ''),
        );
    }
}
