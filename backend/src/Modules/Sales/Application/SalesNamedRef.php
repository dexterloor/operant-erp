<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * API-23b's `{id, name}` expansion, as an `Application` value the fulfilment-side
 * read models (`PickListView`, `FulfilmentView`) can hold without reaching into
 * `ApiResource` (A-7). `SalesNamedReference` is the `ApiResource` twin the order
 * reads use; the short names differ on purpose, because the source scans key
 * classes by short name.
 */
final readonly class SalesNamedRef
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
