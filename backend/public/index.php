<?php

use App\Kernel;
use App\Platform\Runtime\ServingCredentialGuard;
use App\Platform\Tenancy\DatabaseRoles;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    // V3-1(i), owner ruling 2026-09-15 ("App refuses to start"). No kernel is built while any environment source holds
    // a database credential, in a recognised form, that is not an application role's: under PHP-FPM or `php -S` every
    // request is refused with 503 (a health check must treat 503 as down); under a worker runtime the worker crash-loops.
    // Dotenv has already been merged by the runtime, so a credential re-supplied by a dotenv file is seen. The log line
    // carries variable names only. See ServingCredentialGuard and tests/Runtime/. Mail-C1: the web set excludes the mail
    // worker's login (DatabaseRoles::SERVING_WEB_ROLES), so a worker DSN here is refused too.
    $offending = ServingCredentialGuard::offendingNames($_ENV, $_SERVER, getenv(), DatabaseRoles::SERVING_WEB_ROLES);
    if ([] !== $offending) {
        $line = ServingCredentialGuard::MARKER.' — non-application database credential in: '.implode(', ', $offending);
        error_log($line);
        if (\PHP_SAPI === 'cli') {
            fwrite(fopen('php://stderr', 'w'), $line.\PHP_EOL);
        } else {
            http_response_code(503);
        }
        exit(ServingCredentialGuard::EXIT_CODE);
    }

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
