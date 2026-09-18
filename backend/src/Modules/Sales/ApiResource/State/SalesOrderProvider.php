<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\Application\SalesOrderReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetSalesOrder`, and nothing else yet — `UpdateSalesOrder`, `DeleteSalesOrder` and `ConfirmSalesOrder`
 * are declared in §4.2 and unbuilt, and each will decide for itself whether its row belongs to a provider
 * or to the transaction that writes it (`ApprovePurchaseOrder` is the shipped example of the second:
 * §8.1's steps are one atomic sequence and a provider runs before the processor opens one).
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
