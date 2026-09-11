<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\ApiResource\CreatePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreatePurchaseOrder` — API-21 branch 4: §4.2 declares `GetPurchaseOrder` at
 * `/purchase-orders/{id}`, which is the row-level URI this `POST` creates, so
 * the answer is **201** with a `Location` naming it and the created resource as
 * the body.
 *
 * No `CreatedRowLocation::OPERATION_URI_TEMPLATE` is declared, and the absence is
 * the same one `CreateItem` relies on: that listener exists because API
 * Platform's own `Location` names a resource's *first item operation*, and for
 * this resource the first item operation **is** `GetPurchaseOrder`. The vendor
 * header is already right; declaring a template would be a second mechanism
 * producing the same string.
 *
 * ---------------------------------------------------------------------------
 * The one thing this processor does beyond delegating, and why it is here
 * ---------------------------------------------------------------------------
 * It reads the `Idempotency-Key` **header** off the request and hands it to the
 * writer. That is not decoration: the header's value *is* this operation's retry
 * key — it lands in `purchase_order.idempotency_key` and a replay collides on
 * `purchase_order_tenant_idem_live` — so something has to carry it from the HTTP
 * layer into the write.
 *
 * **It is read here rather than in `PurchaseOrderWriter` because of A-11.** The
 * deptrac ruleset admits `Symfony\Component\HttpFoundation` into
 * `<M>ApiResource` and `Platform\Http` and **nowhere else**, so an application
 * service reaching for a `Request` or a `RequestStack` is a boundary violation
 * rather than a convenience. Passing the key as a string keeps
 * `PurchaseOrderWriter::create()` callable from anything — the reorder agent
 * (F-4) raises purchase orders and has no request at all, and it will need to
 * mint its own key for the same column.
 *
 * **The header is not re-validated here.** `IdempotencyKeyGuard` runs on
 * `kernel.controller`, ahead of every state class, and it is what refuses a
 * missing header with `idempotency-key-required` (428) and an over-long one with
 * `validation-failed`. A second check in this class would be a second place for
 * the two to disagree about what a valid key is. The `??` below is therefore not
 * a fallback: it is a total expression over a value the guard has already
 * established is present, and if the flag is ever removed from the operation the
 * empty string reaches the database where the `BEFORE INSERT` trigger refuses it
 * loudly.
 *
 * @implements ProcessorInterface<CreatePurchaseOrderInput, PurchaseOrderResource>
 */
final readonly class CreatePurchaseOrderProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderResource
    {
        \assert($data instanceof CreatePurchaseOrderInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request
            ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER)
            : '';

        return $this->writer->create($data, $key);
    }
}
