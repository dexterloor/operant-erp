<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * The pick read, as `PickListReader` builds it. Its columns are exactly
 * `SECURITY_MODEL.md` PC-9's second deliberate exception (owner ruling R7 of
 * 2026-09-22, columns named by `SEC`); `PickListResource` serializes it.
 */
final readonly class PickListView
{
    /**
     * @param list<PickListLineView> $lines
     */
    public function __construct(
        public string $id,
        public string $orderNumber,
        public string $status,
        public SalesNamedRef $customer,
        public SalesNamedRef $warehouse,
        public string $shipToLine,
        public string $shipToCity,
        public string $shipToPostalCode,
        public string $shipToCountryCode,
        public array $lines,
    ) {
    }
}
