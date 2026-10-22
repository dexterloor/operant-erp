<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use App\Modules\UserManagement\ApiResource\PermissionCollection;
use App\Modules\UserManagement\ApiResource\PermissionResource;
use App\Modules\UserManagement\Application\PermissionReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `permission` source: `ListPermissions`' current view (G6-C1).
 *
 * The parse is the list's own (none: `ListPermissions` declares no query parameter, so `filters` can carry no member).
 * The global catalogue, a bounded list with no query parameter: the list's own statement with `LIMIT cap + 1` (design
 * M-7). The export is gated on `role.view`, the code `ListPermissions` is declared with (never `permission.view`:
 * D-20).
 */
final readonly class PermissionExportSource implements ExportSource
{
    public function __construct(private PermissionReader $reader)
    {
    }

    public function resource(): string
    {
        return 'permission';
    }

    public function listResourceClass(): string
    {
        return PermissionCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListPermissions';
    }

    public function rowClass(): string
    {
        return PermissionResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        // `g6-c1-red.md` L-2: `ListPermissions` declares no query parameter, so the export's own check refuses every
        // member before this runs. If the List ever declares one, this source must parse it as the List does — until then a
        // member reaching here is a wiring fault, never a filter silently ignored (which would export more than the List).
        if ([] !== $filters) {
            throw new \LogicException(\sprintf('ListPermissions declares no filter, yet the export source was handed %s.', implode(', ', array_keys($filters))));
        }

        return function () use ($cap): ExportedRows {
            $read = $this->reader->list($cap);

            return ExportedRows::capped(array_map(PermissionResource::fromView(...), $read['items']), $cap);
        };
    }
}
