<?php

declare(strict_types=1);

/**
 * Asserts that the database the SUITE connects to is genuinely empty.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * `make reset` runs `docker compose down -v`, which destroys a volume, and then
 * migrates. Nothing connected those two facts: compose acts on whatever the
 * ambient Docker context is, while the suite connects to `DATABASE_URL`. When
 * those are two different engines the reset destroys one database and the
 * suite tests another, and the only visible symptom is `migrate` reporting
 * "Already at the latest version" -- which reads like a no-op rather than the
 * alarm it is.
 *
 * Measured on 2026-09-10: Colima and Docker Desktop were both running this
 * stack. Colima published the ports on IPv4, Docker Desktop on IPv6, and
 * `127.0.0.1` resolves IPv4 first -- so every run in that session reached a
 * postmaster that had been up for eight hours and had never been reset, while
 * `make reset` destroyed and rebuilt a container nothing used. Three commits
 * were reported as "verified from a destroyed volume" on that basis and none
 * of them had been.
 *
 * ---------------------------------------------------------------------------
 * What it checks, and why it derives rather than restates
 * ---------------------------------------------------------------------------
 * It connects through `DATABASE_URL` -- the same string the suite uses, not a
 * container name, not a compose service -- and asks the server what it holds.
 * A freshly created volume has no user tables at all. Anything else means the
 * connection is not reaching the database that was just destroyed.
 *
 * It also prints the postmaster start time, because that is the one value that
 * makes the failure legible at a glance: a server that has been up for hours
 * cannot be one that was created seconds ago.
 *
 * The domain is the server's, not a constant here: `information_schema.tables`
 * is asked what exists rather than compared against a list this file carries.
 *
 * @see Makefile, the `reset` target
 */

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// The owner DSN lives in the root `.env.test`, not in `.env` (V3-1(i), V4 batch
// B2b-1, 2026-09-16): every Symfony kernel loads `.env`, so the credential that
// owns the schema was moved out of it. This script is not a kernel, and it reads
// the owner's file by explicit path -- the same `DATABASE_URL` the suite reads
// through `loadEnv` under APP_ENV=test, so "the database the suite connects to"
// still means exactly that. Loaded through PHP Dotenv, never shell-sourced.
$root = \dirname(__DIR__, 2);
$ownerEnv = $root . '/.env.test';
if (!is_file($ownerEnv)) {
    fwrite(STDERR, "assert-clean-database: missing " . $ownerEnv . " (cp .env.test.example .env.test, then fill in every CHANGE_ME).\n");
    exit(2);
}
(new Dotenv())->load($ownerEnv);

$url = $_ENV['DATABASE_URL'] ?? '';
if ('' === $url) {
    fwrite(STDERR, "assert-clean-database: DATABASE_URL is not set in .env.test.\n");
    exit(2);
}

$parts = parse_url($url);
if (false === $parts || !isset($parts['host'], $parts['port'], $parts['path'])) {
    fwrite(STDERR, "assert-clean-database: DATABASE_URL is not a parseable URL.\n");
    exit(2);
}

$dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s',
    $parts['host'],
    $parts['port'],
    ltrim($parts['path'], '/'),
);

try {
    $pdo = new PDO($dsn, $parts['user'] ?? '', $parts['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    // Deliberately not echoing the DSN -- it carries a password.
    fwrite(STDERR, sprintf(
        "assert-clean-database: cannot connect to %s:%d. Is the stack up?\n",
        $parts['host'],
        $parts['port'],
    ));
    exit(2);
}

$row = $pdo->query(
    "select
        (select count(*) from information_schema.tables
          where table_schema not in ('pg_catalog', 'information_schema')) as tables,
        pg_postmaster_start_time()::timestamp(0)::text as started,
        date_trunc('second', now() - pg_postmaster_start_time())::text as uptime,
        inet_server_addr()::text as server"
)->fetch(PDO::FETCH_ASSOC);

$tables = (int) $row['tables'];

if (0 !== $tables) {
    fwrite(STDERR, sprintf(
        "\n  assert-clean-database: FAILED\n\n"
        . "  The volume was destroyed, but %s:%d still reports %d table(s).\n"
        . "  That server started %s and has been up %s.\n\n"
        . "  The reset and the suite are not reaching the same database.\n"
        . "  Most likely two container runtimes are publishing the same ports --\n"
        . "  check `docker context ls`, and `lsof -nP -iTCP:%d -sTCP:LISTEN` for\n"
        . "  more than one listener (IPv4 and IPv6 can be different engines).\n\n"
        . "  Do NOT report a suite run as 'from a destroyed volume' until this passes.\n\n",
        $parts['host'],
        $parts['port'],
        $tables,
        $row['started'],
        $row['uptime'],
        $parts['port'],
    ));
    exit(1);
}

fwrite(STDOUT, sprintf(
    "  assert-clean-database: OK -- 0 tables, server %s up %s.\n",
    $row['server'] ?? 'local',
    $row['uptime'],
));
exit(0);
