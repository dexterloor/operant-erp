<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\UserManagement\ApiResource\PermissionCollection;
use App\Modules\UserManagement\ApiResource\PermissionResource;
use App\Modules\UserManagement\Application\PermissionReader;
use App\Modules\UserManagement\Application\PermissionView;

/**
 * `ListPermissions`. It declares no parameter, so any query parameter is refused before this runs
 * (`Platform\Http\UndeclaredQueryParameterRefusal`).
 *
 * @implements ProviderInterface<PermissionCollection>
 */
final readonly class PermissionCollectionProvider implements ProviderInterface
{
    public function __construct(private PermissionReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PermissionCollection
    {
        $read = $this->reader->list();

        return new PermissionCollection(
            array_map(static fn (PermissionView $view): PermissionResource => PermissionResource::fromView($view), $read['items']),
            null,
            null,
            $read['tenantHasRows'],
        );
    }
}
