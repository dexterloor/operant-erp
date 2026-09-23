<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\UserManagement\ApiResource\RoleCollection;
use App\Modules\UserManagement\ApiResource\RoleResource;
use App\Modules\UserManagement\Application\RoleReader;
use App\Modules\UserManagement\Application\RoleView;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * `ListRoles` — two parameters, `archived` and `q`; anything else is refused before this runs
 * (`Platform\Http\UndeclaredQueryParameterRefusal`).
 *
 * @implements ProviderInterface<RoleCollection>
 */
final readonly class RoleCollectionProvider implements ProviderInterface
{
    public function __construct(private RoleReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RoleCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $read = $this->reader->list(self::archived($query), self::search($query));

        return new RoleCollection(
            array_map(static fn (RoleView $view): RoleResource => RoleResource::fromView($view), $read['items']),
            null,
            null,
            $read['tenantHasRows'],
        );
    }

    /**
     * `SalesOrderLineCollectionProvider::archived()`'s parsing: `true`/`1`/`false`/`0`, anything else refused
     * rather than coerced.
     *
     * @param array<string, mixed> $query
     */
    private static function archived(array $query): bool
    {
        if (!isset($query['archived'])) {
            return false;
        }

        return match ($query['archived']) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw ApiProblem::validationFailed([[
                'pointer' => '?archived',
                'code' => 'not_a_boolean',
                'message' => 'archived must be true or false.',
            ]]),
        };
    }

    /**
     * `SalesOrderCollectionProvider`'s handling: a non-string `q` is refused; an empty one is no search.
     *
     * @param array<string, mixed> $query
     */
    private static function search(array $query): ?string
    {
        if (!isset($query['q'])) {
            return null;
        }
        if (!\is_string($query['q'])) {
            throw ApiProblem::validationFailed([['pointer' => '?q', 'code' => 'not_a_string', 'message' => 'q must be a string.']]);
        }

        return '' === $query['q'] ? null : $query['q'];
    }
}
