<?php

declare(strict_types=1);

namespace App\Modules\Analytics\ApiResource;

/** `inventory_item = {id, sku, name}` (API-23b clause (ii)). */
final readonly class ReorderItemReference
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
    ) {
    }
}
