<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use App\Modules\UserManagement\Application\PermissionView;

/**
 * One permission — `{code, module, description}`, §4.4.3's Permission row, *"the three columns the
 * `permission` table has"*.
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
 * V2-C-5's `sod_rows[]` is deliberately absent — deferred to `CreateRole`/`UpdateRole` by owner ruling
 * Q2, on `PermissionListTest::noRoleCompositionShipsWithoutSodRows`.
 */
final class PermissionResource
{
    public function __construct(
        public string $code,
        public string $module,
        public string $description,
    ) {
    }

    public static function fromView(PermissionView $view): self
    {
        return new self($view->code, $view->module, $view->description);
    }
}
