<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * **What counts as "leaving the laptop", in ONE place** (Mail-C0, 2026-10-23; `docs/session13/mail-c0-build-plan.md`
 * Revision 1 FINAL §R6). Read by H5 (`GrantFootprintTest::noRoleOrCredentialWriteLeavesTheLaptopBeforeItsClosingWork`),
 * `ComposeLocalServiceTest` and T-G75-1 (`CredentialKeyDeployTest`). Until Mail-C0 the two lists were `private const`s of
 * `GrantFootprintTest`; a second copy for the new tests would have drifted (plan §12 F-12).
 *
 * **Name-shaped by design**: a guess about the future, and a dashboard-configured deploy leaves no file at all (G-10's
 * prose triggers).
 */
final class DeployArtefacts
{
    /**
     * The compose services the local stack is pinned to; any other service is a deploy artefact by this rule. `mailpit`
     * (the local mail catcher) and `redis` (the mail queue) joined at Mail-C0, both loopback-bound and holding no
     * application credential — `ComposeLocalServiceTest` holds them to that.
     */
    public const LOCAL_COMPOSE_SERVICES = ['mailpit', 'pgbouncer', 'postgres', 'redis'];

    /** Platform-config and container file names (basename or repository-relative path). */
    public const NAMES = [
        '/^Dockerfile/', '/^fly\.toml$/', '/^render\.yaml$/', '/^railway\.json$/', '/^vercel\.json$/', '/^app\.yaml$/',
        '/^Procfile$/', '/^netlify\.toml$/', '/^heroku\.yml$/', '/^\.platform\.app\.yaml$/', '#^\.github/workflows/.*deploy#',
    ];

    /** The local stack's own compose file — the one compose file that is NOT an artefact. */
    public const LOCAL_COMPOSE_FILE = 'infra/docker-compose.yml';

    /**
     * **Final RED L-2:** any other compose file (`compose.yaml`, `compose.override.yaml`, `docker-compose*.yml`, anywhere)
     * is an artefact. A separate rule, not a {@see self::NAMES} entry: a `docker-compose` pattern there would match the local
     * file itself and arm H5 for ever. The walk is the filesystem, not git, so a gitignored `docker-compose.override.yml`
     * arms it too — fail-closed, on purpose.
     */
    public static function isArtefact(string $path): bool
    {
        foreach (self::NAMES as $pattern) {
            if (1 === preg_match($pattern, basename($path)) || 1 === preg_match($pattern, $path)) {
                return true;
            }
        }

        return $path !== self::LOCAL_COMPOSE_FILE && 1 === preg_match('/^(docker-)?compose(\..+)?\.ya?ml$/', basename($path));
    }

    /** @return list<string> every file under the repository root, relative, skipping vendored and generated trees */
    public static function filesUnderTheRepository(): array
    {
        $root = self::repositoryRoot();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !($f->isDir() && \in_array($f->getFilename(), ['.git', 'vendor', 'var', 'node_modules', 'docs'], true)),
        ));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $files[] = ltrim(substr($file->getPathname(), \strlen($root)), '/');
        }

        return $files;
    }

    /** @return array<string, mixed> the local compose file's services, by name */
    public static function composeServices(): array
    {
        $parsed = Yaml::parseFile(self::repositoryRoot().'/'.self::LOCAL_COMPOSE_FILE);
        if (!\is_array($parsed) || !\is_array($parsed['services'] ?? null)) {
            throw new \RuntimeException(self::LOCAL_COMPOSE_FILE.' has no services map.');
        }

        return $parsed['services'];
    }

    public static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
