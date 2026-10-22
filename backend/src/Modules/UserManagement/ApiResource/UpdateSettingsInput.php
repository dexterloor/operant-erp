<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateSettings`' accepted request field — §4.2's cell verbatim: `display_name` (G7b, owner ruling RO-S1). `mixed`, so the
 * writer answers a wrong type with the house 422 (`UpdateRoleInput`'s reason). Unknown keys are refused
 * (`allow_extra_attributes => false`): `default_currency_code` (read-only, RO-S1) and `workspace` (the registry's slug) are
 * not accepted. Uninitialised, so absent and `null` stay distinguishable (`UpdateItemInput`'s precedent).
 */
final class UpdateSettingsInput
{
    #[SerializedName('display_name')]
    public mixed $displayName;

    /**
     * The members this request actually carried, keyed by their WIRE names.
     *
     * @return array<string, mixed>
     */
    public function submitted(): array
    {
        return (new \ReflectionProperty($this, 'displayName'))->isInitialized($this) ? ['display_name' => $this->displayName] : [];
    }
}
