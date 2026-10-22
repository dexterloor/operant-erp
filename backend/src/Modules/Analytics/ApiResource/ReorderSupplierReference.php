<?php

declare(strict_types=1);

namespace App\Modules\Analytics\ApiResource;

/** `preferred_supplier = {id, name}` (API-23b); absent (null) when there is none or it is archived. */
final readonly class ReorderSupplierReference
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
