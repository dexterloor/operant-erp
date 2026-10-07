<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * One role as `RoleReader` reads it — scalars only, so the reader returns no `ApiResource` class (A-7).
 * The fields are API-23's derivation for `Role` plus response R-10's two additions and, since roles slice 4 C1a,
 * `marginExposure` — PC-12 clause 3's definition-time warning, a pure function of `permissionCodes`; see `RoleResource`.
 */
final readonly class RoleView
{
    /**
     * @param list<string> $permissionCodes sorted ascending
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public array $permissionCodes,
        public bool $isSystem,
        public int $holderCount,
        public bool $marginExposure,
        public ?string $archivedAt,
    ) {
    }
}
