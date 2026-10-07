<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use App\Modules\UserManagement\Application\PermissionView;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One permission — `{code, module, description, sod_rows}`, §4.4.3's Permission row: the three columns the
 * `permission` table has, and where the code sits in ADR-008 §2's SoD table.
 *
 * **No `id`: the table has none — its primary key is `code`.** Ruled by the owner 2026-09-23 (roles
 * slice 1, Q3+Q4): a synthetic `id` would be a second name for one value. It is the single pinned,
 * database-checked exception to API-23 step 2 (`ResponseShapeTest::NO_ID_RESOURCES`).
 * **`module` ships as stored** (`Modules\Purchasing`, PC-1a's vocabulary); a display label is a
 * client-side mapping.
 *
 * A plain DTO, **not** an ApiResource: it has no operation of its own. It must keep exactly this name,
 * beside its list envelope, because `ResponseShapeTest::dtoClassOf()` resolves a List-only resource by
 * the `<Resource>Resource` convention.
 *
 * **`sod_rows[]` — V2-C-5, added in roles slice 4 C0** (deferred to `CreateRole`/`UpdateRole` by owner ruling Q2 of
 * 2026-09-23, on `PermissionListTest::noRoleCompositionShipsWithoutSodRows`, and landed one commit ahead of them so that
 * tripwire is satisfied before either operation arrives). One `{id, half}` per SoD row naming the code, `half` being
 * `preparing` or `controlled`; `[]` for a code on no split. Derived from `SeparationOfDuty`, so the role-composition screen
 * (IA-O-9) refuses a split at selection time from the table the server refuses it from (PC-4 at composition).
 * `PermissionListTest::sodRowsAreTheSeparationOfDutyTableForEveryCode` holds it equal for every code.
 */
final class PermissionResource
{
    public function __construct(
        public string $code,
        public string $module,
        public string $description,
        /** @var list<array{id: string, half: 'preparing'|'controlled'}> */
        #[SerializedName('sod_rows')]
        public array $sodRows,
    ) {
    }

    public static function fromView(PermissionView $view): self
    {
        return new self($view->code, $view->module, $view->description, $view->sodRows);
    }
}
