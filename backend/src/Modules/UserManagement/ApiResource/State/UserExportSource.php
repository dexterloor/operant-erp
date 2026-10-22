<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use App\Modules\UserManagement\ApiResource\UserCollection;
use App\Modules\UserManagement\ApiResource\UserResource;
use App\Modules\UserManagement\Application\UserReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `app_user` source: `ListUsers`' current view (G6-C1).
 *
 * The parse is the list's own ({@see RoleCollectionProvider::parse()}, `archived` then `q`, in the list's
 * order). A bounded list (TB-1c): the list's own `archived`/`q` parse (`RoleCollectionProvider`'s, which
 * `ListUsers` shares) and statement, with `LIMIT cap + 1` (design M-7).
 */
final readonly class UserExportSource implements ExportSource
{
    public function __construct(private UserReader $reader)
    {
    }

    public function resource(): string
    {
        return 'app_user';
    }

    public function listResourceClass(): string
    {
        return UserCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListUsers';
    }

    public function rowClass(): string
    {
        return UserResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        [$archived, $search] = RoleCollectionProvider::parse($filters);

        return function () use ($archived, $search, $cap): ExportedRows {
            $read = $this->reader->list($archived, $search, $cap);

            return ExportedRows::capped(array_map(UserResource::fromView(...), $read['items']), $cap);
        };
    }
}
