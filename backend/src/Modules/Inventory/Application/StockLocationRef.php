<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

/** API-23b's `storage_location = {id, code}` (the bin; it has no name column), as an `Application` value. */
final readonly class StockLocationRef
{
    public function __construct(
        public string $id,
        public string $code,
    ) {
    }
}
