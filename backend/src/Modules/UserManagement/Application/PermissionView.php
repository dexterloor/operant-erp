<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * One row of the global permission vocabulary, as `PermissionReader` reads it — the three columns the
 * `permission` table has, plus where the code sits in ADR-008 §2's SoD table (V2-C-5, roles slice 4 C0). An `Application`
 * value so the reader returns no `ApiResource` class (A-7).
 */
final readonly class PermissionView
{
    public function __construct(
        public string $code,
        public string $module,
        public string $description,
        /** @var list<array{id: string, half: 'preparing'|'controlled'}> `SeparationOfDuty::placementsOf($code)` */
        public array $sodRows,
    ) {
    }
}
