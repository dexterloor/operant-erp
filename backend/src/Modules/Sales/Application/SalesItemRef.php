<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/** API-23b clause (ii)'s `inventory_item = {id, sku, name}`, as an `Application` value (see `SalesNamedRef`). */
final readonly class SalesItemRef
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
    ) {
    }
}
