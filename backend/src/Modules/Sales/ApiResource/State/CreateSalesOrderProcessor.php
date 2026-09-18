<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\CreateSalesOrderInput;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\Application\SalesOrderWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateSalesOrder` — API-21 branch 4: 201, the created resource as the body, and a `Location` naming
 * `/sales-orders/{id}`. That header is the **vendor's own IRI** as of 2026-09-19, because `GetSalesOrder`
 * now exists and is the resource's first item operation; for the one commit before it did,
 * `CreatedRowLocation::OPERATION_URI_TEMPLATE` supplied the same string, because the vendor would
 * otherwise have named a route that 404s. `SalesOrderResource` says the rest.
 *
 * ---------------------------------------------------------------------------
 * The one thing this processor does beyond delegating, and why it is here
 * ---------------------------------------------------------------------------
 * It reads the `Idempotency-Key` **header** off the request and hands it to the writer. That is not
 * decoration: the header's value *is* this operation's retry key — its SHA-256 lands in
 * `sales_order.idempotency_key` (D-B: hashed, never stored raw) and a replay collides on
 * `sales_order_tenant_idem_live`.
 *
 * **It is read here rather than in `SalesOrderWriter` because of A-11**, and the file's *path* is part of
 * that: the deptrac ruleset admits `Symfony\Component\HttpFoundation` into `<M>ApiResource` and
 * `Platform\Http` and **nowhere else**, and `SalesApiResource` is `App\Modules\Sales\ApiResource\…`. A
 * processor one directory up, in `App\Modules\Sales\State\…`, would be in no layer that admits a
 * `Request`. Passing the key as a string also keeps `SalesOrderWriter::create()` callable from anything
 * with no request at all.
 *
 * **The header is not re-validated here.** `IdempotencyKeyGuard` runs on `kernel.controller`, ahead of
 * every state class, and it is what refuses a missing header with `idempotency-key-required` (428) and an
 * over-long one with `validation-failed`. A second check here would be a second place for the two to
 * disagree. The `??` below is therefore not a fallback: it is a total expression over a value the guard has
 * already established is present, and if the flag is ever removed from the operation the empty string
 * reaches `IdempotencyKeyHash::of()`, which refuses it loudly.
 *
 * @implements ProcessorInterface<CreateSalesOrderInput, SalesOrderResource>
 */
final readonly class CreateSalesOrderProcessor implements ProcessorInterface
{
    public function __construct(private SalesOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SalesOrderResource
    {
        \assert($data instanceof CreateSalesOrderInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request
            ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER)
            : '';

        return $this->writer->create($data, $key);
    }
}
