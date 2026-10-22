<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\SettingsProvider;
use App\Modules\UserManagement\ApiResource\State\UpdateSettingsProcessor;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `GET /api/v1/settings` — §4.2's `GetSettings` (G7a, 2026-10-21) and §4.4.3's Settings row: `display_name` (from
 * `tenant_settings`, since G7b), `workspace` (from `tenant.slug`) and `default_currency_code` (from `tenant_settings`) — and
 * nothing else.
 *
 * `PATCH /api/v1/settings` — §4.2's `UpdateSettings` (G7b, 2026-10-22; owner ruling RO-S1): `{display_name}` only, under
 * `tenant_settings.edit` (the owner marker, E-15b — default `owner` alone), 200 with this body. The currency stays
 * read-only (refused as an undeclared field, and unwritable at the database). `Idem` `no`: setting a name to a value is
 * idempotent. {@see \App\Modules\UserManagement\Application\SettingsWriter}.
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
        new Patch(
            uriTemplate: '/settings',
            name: 'UpdateSettings',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateSettings'),
            input: UpdateSettingsInput::class,
            denormalizationContext: [
                // API-9: `default_currency_code` (read-only, RO-S1) and `workspace` are refused and named back.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            // Nothing is read before the settings row lock (`SettingsWriter`, step 1).
            read: false,
            processor: UpdateSettingsProcessor::class,
            // §4.2's `Perm` cell: the owner marker (E-15b). Enforced by `PermissionGate` before the processor runs — E-1.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'tenant_settings.edit'],
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
