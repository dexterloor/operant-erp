<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\SettingsProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `GET /api/v1/settings` — §4.2's `GetSettings` (G7a, 2026-10-21) and §4.4.3's Settings row: `display_name` (from
 * `tenant.name`), `workspace` (from `tenant.slug`) and `default_currency_code` (from `tenant_settings`) — and nothing else.
 *
 * **No `id`, and no tenant identifier at any depth** (API-6, P-6, T-1): the resource is a singleton addressed by no id,
 * whose identity is the caller's own tenant — §4.4.3 step 2's singleton exception, pinned in
 * `ResponseShapeTest::SINGLETON_RESOURCES` (checked: its read path carries no `{…}` variable).
 *
 * The currency is served read-only (owner ruling RO-S1); serving it is `PO`/`UX`'s to confirm (final RED L-3).
 */
#[ApiResource(
    shortName: 'Settings',
    operations: [
        new Get(
            uriTemplate: '/settings',
            name: 'GetSettings',
            openapi: new OpenApiOperation(operationId: 'GetSettings'),
            provider: SettingsProvider::class,
            // §4.2's `Perm` cell. Enforced by `PermissionGate` before the provider reads anything — E-1.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'tenant_settings.view'],
        ),
    ],
)]
final class SettingsResource
{
    public function __construct(
        #[SerializedName('display_name')]
        public string $displayName,
        public string $workspace,
        #[SerializedName('default_currency_code')]
        public string $defaultCurrencyCode,
    ) {
    }
}
