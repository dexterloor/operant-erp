<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\Application\SalesOrderReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetSalesOrder`, `UpdateSalesOrder` and `DeleteSalesOrder` — each of the two writes reads its row here
 * first, so an id no row answers is the same `not-found` the read returns (§5.3 clause 2), and then reads it
 * again under its lock. *(This sentence called both writes unbuilt until 2026-09-21.)*
 * **`ConfirmSalesOrder` decided against this class:** it declares
 * `read: false` and loads its row inside the transaction that writes it, because its lock, its archived
 * check, its status guard and its line count are one atomic sequence and a provider runs before the
 * processor opens one. That is `ApprovePurchaseOrder`'s shipped position.
 *
 * §5.3 clause 2: a row RLS does not return is `not-found`, never `permission-denied` — a 403 on a
 * cross-tenant id confirms the id exists somewhere.
 *
 * @implements ProviderInterface<SalesOrderResource>
 */
final readonly class SalesOrderProvider implements ProviderInterface
{
    public function __construct(private SalesOrderReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SalesOrderResource
    {
        return $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();
    }
}
