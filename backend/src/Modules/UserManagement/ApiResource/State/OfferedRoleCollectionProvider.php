<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\UserManagement\ApiResource\OfferedRoleCollection;
use App\Modules\UserManagement\ApiResource\OfferedRoleResource;
use App\Modules\UserManagement\Application\OfferedRoleReader;
use App\Modules\UserManagement\Application\OfferedRoleView;
use Symfony\Component\HttpFoundation\Request;

/**
 * `ListOfferedRoles` — `archived` and `q` parsed by `RoleCollectionProvider`'s own parsers (shared, not copied); anything
 * else is refused before this runs (`Platform\Http\UndeclaredQueryParameterRefusal`).
 *
 * @implements ProviderInterface<OfferedRoleCollection>
 */
final readonly class OfferedRoleCollectionProvider implements ProviderInterface
{
    public function __construct(private OfferedRoleReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OfferedRoleCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];
        $read = $this->reader->list((string) ($uriVariables['id'] ?? ''), RoleCollectionProvider::archived($query), RoleCollectionProvider::search($query));

        return new OfferedRoleCollection(
            array_map(static fn (OfferedRoleView $view): OfferedRoleResource => OfferedRoleResource::fromView($view), $read['items']),
            null,
            null,
            $read['tenantHasRows'],
        );
    }
}
