<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/** What `GetSettings` serves, read by {@see SettingsReader}. */
final readonly class SettingsView
{
    public function __construct(
        public string $displayName,
        public string $workspace,
        public string $defaultCurrencyCode,
    ) {
    }
}
