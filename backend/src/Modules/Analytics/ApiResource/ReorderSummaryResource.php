<?php

declare(strict_types=1);

namespace App\Modules\Analytics\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Analytics\ApiResource\State\ReorderSummaryProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`GetReorderSummary` — `GET /api/v1/forecast/reorder/summary`, `forecast.view` ALONE** (remaining-ops G3-C1, 2026-10-22;
 * `API.md` §4.2's reorder ruling and F-4 step 1). `/overview`'s data call, so every default role reaches it and login never
 * ends on ST-4b (`ReorderSuggestionTest::everyDefaultRoleCanReachTheSummary`).
 *
 * `{ "items_below_reorder_point": n }` and NOTHING else (the ruling's *fails if*): `n` is the length of the very list
 * `ListReorderSuggestions` serves ({@see \App\Modules\Analytics\Application\ReorderSuggester::count()}), computed without
 * the catalogue read. **Netted against open purchase orders, so it is the one figure built from purchase orders that a role
 * without `purchase_order.view` sees** — the one place H-2's rule is knowingly not applied (owner notice N-4, final RED F-L8).
 *
 * **No `id`** — §4.4.3 step 2's singleton exception, second ground (final RED F-L4): a tableless per-tenant aggregate
 * addressed by no id (`ResponseShapeTest::SINGLETON_RESOURCES`).
 */
#[ApiResource(
    shortName: 'ReorderSummary',
    operations: [
        new Get(
            uriTemplate: '/forecast/reorder/summary',
            name: 'GetReorderSummary',
            openapi: new OpenApiOperation(operationId: 'GetReorderSummary'),
            provider: ReorderSummaryProvider::class,
            // §4.2's `Perm` cell, enforced before the provider runs (E-1).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'forecast.view'],
        ),
    ],
)]
final class ReorderSummaryResource
{
    public function __construct(
        #[SerializedName('items_below_reorder_point')]
        public int $itemsBelowReorderPoint,
    ) {
    }
}
