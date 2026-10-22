<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\UserManagement\ApiResource\UserCollection;
use App\Modules\UserManagement\ApiResource\UserResource;
use App\Modules\UserManagement\Application\UserReader;
use App\Modules\UserManagement\Application\UserView;
use Symfony\Component\HttpFoundation\Request;

/**
 * `ListUsers` — two parameters, `archived` and `q`, parsed by `RoleCollectionProvider`'s own parsers (shared, not copied:
 * one module, so no A-7 line); anything else is refused before this runs (`Platform\Http\UndeclaredQueryParameterRefusal`).
 *
 * @implements ProviderInterface<UserCollection>
 */
final readonly class UserCollectionProvider implements ProviderInterface
{
    public function __construct(private UserReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        [$archived, $search] = RoleCollectionProvider::parse($query);
        $read = $this->reader->list($archived, $search);

        return new UserCollection(
            array_map(static fn (UserView $view): UserResource => UserResource::fromView($view), $read['items']),
            null,
            null,
            $read['tenantHasRows'],
        );
    }
}
