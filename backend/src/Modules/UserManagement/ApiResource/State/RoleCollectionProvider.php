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

        [$archived, $search] = self::parse($query);
        $read = $this->reader->list($archived, $search);

        return new RoleCollection(
            array_map(static fn (RoleView $view): RoleResource => RoleResource::fromView($view), $read['items']),
            null,
            null,
            $read['tenantHasRows'],
        );
    }

    /**
     * **The list's whole pre-read parse, shared with `CreateExport`** (G6-C1; `g6-c1-red.md` L-1): `archived` then `q`, in
     * that order, as `ListRoles` and `ListUsers` both run it — so `RoleExportSource` and `UserExportSource` call this rather
     * than re-composing the two by hand.
     *
     * @param array<string, mixed> $query
     *
     * @return array{0: bool, 1: ?string} archived, and the search (null when none)
     */
    public static function parse(array $query): array
    {
        return [self::archived($query), self::search($query)];
    }

    /**
     * `SalesOrderLineCollectionProvider::archived()`'s parsing: `true`/`1`/`false`/`0`, anything else refused
     * rather than coerced. Public since users slice 1 C2: `ListUsers` and `ListOfferedRoles` share it (one module).
     *
     * @param array<string, mixed> $query
     */
    public static function archived(array $query): bool
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
    public static function search(array $query): ?string
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
