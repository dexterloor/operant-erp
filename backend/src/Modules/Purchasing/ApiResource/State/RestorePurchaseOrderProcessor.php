<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;

/**
 * `RestorePurchaseOrder` (order restores C3, 2026-10-21) — API-12a / ST-9. The permission is the one that archives
 * (`purchase_order.delete`, §4.2), and the response is API-21 branch 5: 200 with the order in its **new** state, exactly
 * `GetPurchaseOrder`'s body (`RestoreCustomerProcessor`'s shape). Everything else is `PurchaseOrderWriter::restore()`.
 *
 * @implements ProcessorInterface<mixed, PurchaseOrderResource>
 */
final readonly class RestorePurchaseOrderProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderResource
    {
        return $this->writer->restore((string) ($uriVariables['id'] ?? ''));
    }
}
