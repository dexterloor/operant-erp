<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use App\Modules\UserManagement\Application\OfferedRoleView;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **One offered role — `ListOfferedRoles`' item** (users slice 1 C3; `API.md` §4.4.3's `OfferedRole` row, K-2;
 * `docs/session13/users-s1-design.md` rev 3 §2.2).
 *
 * Not an `ApiResource` of its own: no §4.2 row reads or writes one offered role, so API Platform mints no component for it
 * and `OfferedRoleCollection` publishes the item schema INLINE ({@see self::WIRE_SCHEMA}), held equal to this class's
 * members by `OfferedRoleListTest` (the `InvoiceLine` precedent). `ResponseShapeTest` finds it by the `<Resource>Resource`
 * convention beside its envelope, and ties it to `role` by §4.4.3's *"OfferedRole is derived from `role`"*: each item is
 * one `role` row, and its `id` is that role's id.
 */
final readonly class OfferedRoleResource
{
    private const REFERENCE = ['type' => 'object', 'required' => ['id', 'name'], 'properties' => [
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'name' => ['type' => 'string'],
    ]];

    /** The published item schema — the members below, nothing more. */
    public const WIRE_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'name', 'conflicts_with', 'margin_exposure_with', 'refusal', 'requires_owner_confirmation'],
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'name' => ['type' => 'string'],
            'conflicts_with' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'required' => ['role', 'sod_row'],
                'properties' => ['role' => self::REFERENCE, 'sod_row' => ['type' => 'string']],
            ]],
            'margin_exposure_with' => ['type' => 'array', 'items' => self::REFERENCE],
            'refusal' => ['type' => ['string', 'null']],
            'requires_owner_confirmation' => ['type' => 'boolean'],
        ],
    ];

    /**
     * @param list<RoleConflictReference> $conflictsWith
     * @param list<RoleReference>         $marginExposureWith
     */
    public function __construct(
        public string $id,
        public string $name,
        #[SerializedName('conflicts_with')]
        public array $conflictsWith,
        #[SerializedName('margin_exposure_with')]
        public array $marginExposureWith,
        public ?string $refusal,
        #[SerializedName('requires_owner_confirmation')]
        public bool $requiresOwnerConfirmation,
    ) {
    }

    public static function fromView(OfferedRoleView $view): self
    {
        return new self(
            $view->id,
            $view->name,
            array_map(static fn (array $c): RoleConflictReference => new RoleConflictReference(new RoleReference($c['role']['id'], $c['role']['name']), $c['sod_row']), $view->conflictsWith),
            array_map(static fn (array $r): RoleReference => new RoleReference($r['id'], $r['name']), $view->marginExposureWith),
            $view->refusal,
            $view->requiresOwnerConfirmation,
        );
    }
}
