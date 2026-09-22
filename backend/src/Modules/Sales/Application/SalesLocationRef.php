<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/** API-23b's `storage_location = {id, code}`, as an `Application` value (see `SalesNamedRef`). */
final readonly class SalesLocationRef
{
    public function __construct(
        public string $id,
        public string $code,
    ) {
    }
}
