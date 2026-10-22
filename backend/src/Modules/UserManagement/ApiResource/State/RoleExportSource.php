<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use App\Modules\UserManagement\ApiResource\RoleCollection;
use App\Modules\UserManagement\ApiResource\RoleResource;
use App\Modules\UserManagement\Application\RoleReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `role` source: `ListRoles`' current view (G6-C1).
 *
 * The parse is the list's own ({@see RoleCollectionProvider::parse()}, `archived` then `q`, in the list's
 * order). A bounded list (TB-1c): the list's own `archived`/`q` parse and statement, with `LIMIT cap + 1`
 * (design M-7).
 */
final readonly class RoleExportSource implements ExportSource
{
    public function __construct(private RoleReader $reader)
    {
    }

    public function resource(): string
    {
        return 'role';
    }

    public function listResourceClass(): string
    {
        return RoleCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListRoles';
    }

    public function rowClass(): string
    {
        return RoleResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        [$archived, $search] = RoleCollectionProvider::parse($filters);

        return function () use ($archived, $search, $cap): ExportedRows {
            $read = $this->reader->list($archived, $search, $cap);

            return ExportedRows::capped(array_map(RoleResource::fromView(...), $read['items']), $cap);
        };
    }
}
