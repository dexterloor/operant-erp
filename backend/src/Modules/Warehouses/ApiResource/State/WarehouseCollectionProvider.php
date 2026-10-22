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
        [$archived, $search] = self::parse(self::query($context));
        $read = $this->reader->list($archived, $search);

        return new WarehouseCollection(
            array_map(WarehouseResource::fromView(...), $read['items']),
            null,
            null,
            $read['tenantHasRows'],
        );
    }

    /**
     * **The list's whole pre-read parse, shared with `CreateExport`** (G6-C1, design Response M-5): the provider and
     * `WarehouseExportSource` both call this, so `archived` and `q` are refused exactly as the list refuses them, in the
     * same order (TB-7).
     *
     * @param array<string, mixed> $query
     *
     * @return array{0: bool, 1: ?string} archived, and the search (null when none)
     */
    public static function parse(array $query): array
    {
        return [self::archivedIn($query), self::searchIn($query)];
    }

    /**
     * `RoleCollectionProvider::archived()`'s parsing (another module, so not imported): `true`/`1`/`false`/`0`, anything
     * else refused rather than coerced. Shared with `ListStorageLocations` (one module).
     *
     * @param array<string, mixed> $context
     */
    public static function archived(array $context): bool
    {
        return self::archivedIn(self::query($context));
    }

    /** @param array<string, mixed> $query */
    private static function archivedIn(array $query): bool
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
     * `RoleCollectionProvider::search()`'s handling: a non-string `q` is refused; an empty one is no search.
     *
     * @param array<string, mixed> $query
     */
    private static function searchIn(array $query): ?string
    {
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
