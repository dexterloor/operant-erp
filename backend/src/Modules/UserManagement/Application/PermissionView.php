<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * One row of the global permission vocabulary, as `PermissionReader` reads it — the three columns the
 * `permission` table has. An `Application` value so the reader returns no `ApiResource` class (A-7).
 */
final readonly class PermissionView
{
    public function __construct(
        public string $code,
        public string $module,
        public string $description,
    ) {
    }
}
