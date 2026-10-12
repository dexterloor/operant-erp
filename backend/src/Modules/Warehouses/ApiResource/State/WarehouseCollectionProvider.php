<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Warehouses\ApiResource\WarehouseCollection;
use App\Modules\Warehouses\ApiResource\WarehouseResource;
use App\Modules\Warehouses\Application\WarehouseReader;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * `ListWarehouses` — two parameters, `archived` and `q`; anything else is refused before this runs
 * (`Platform\Http\UndeclaredQueryParameterRefusal`).
 *
 * @implements ProviderInterface<WarehouseCollection>
 */
final readonly class WarehouseCollectionProvider implements ProviderInterface
{
    public function __construct(private WarehouseReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): WarehouseCollection
    {
        $read = $this->reader->list(self::archived($context), self::search($context));

        return new WarehouseCollection(
            array_map(WarehouseResource::fromView(...), $read['items']),
            null,
            null,
            $read['tenantHasRows'],
        );
    }

    /**
     * `RoleCollectionProvider::archived()`'s parsing (another module, so not imported): `true`/`1`/`false`/`0`, anything
     * else refused rather than coerced. Shared with `ListStorageLocations` (one module).
     *
     * @param array<string, mixed> $context
     */
    public static function archived(array $context): bool
    {
        $query = self::query($context);
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
     * `RoleCollectionProvider::search()`'s handling: a non-string `q` is refused; an empty one is no search.
     *
     * @param array<string, mixed> $context
     */
    private static function search(array $context): ?string
    {
        $query = self::query($context);
        if (!isset($query['q'])) {
            return null;
        }
        if (!\is_string($query['q'])) {
            throw ApiProblem::validationFailed([['pointer' => '?q', 'code' => 'not_a_string', 'message' => 'q must be a string.']]);
        }

        return '' === $query['q'] ? null : $query['q'];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function query(array $context): array
    {
        $request = $context['request'] ?? null;

        return $request instanceof Request ? $request->query->all() : [];
    }
}
