<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\DeployArtefacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **A local compose service is exempt from H5 only because it stays on this machine and holds nothing** (Mail-C0,
 * 2026-10-23; plan §7.1 / Revision 1 FINAL §R6/§R7). Every name in {@see DeployArtefacts::LOCAL_COMPOSE_SERVICES} must
 * publish every port on `127.0.0.1` only — **including `postgres` and `pgbouncer`** since 2a C1 (2026-11-06,
 * `RESIDUAL_GAPS.md` G-1.x: the database ports were published on every interface until then). Every name except
 * `postgres` and `pgbouncer` — exempt from the rest because they ARE the credential holders, governed by G-1.x and
 * `ComposeCredentialExposureTest` — must also:
 *   - name no application or database credential (an environment key, or a `${…}` interpolation anywhere in the
 *     service, matching `ERP_`, `DB_`, `AUTH_` or `POSTGRES_`);
 *   - carry no `build` (a built image is a deploy artefact by another name);
 *   - pin an explicit image tag that is not `latest`.
 * Mutations: M11 (Mailpit UI on `0.0.0.0`) and M12 (`ERP_APP_PASSWORD` in a local service) → red; 2a's M1/M1b (the
 * PgBouncer or the Postgres line without `127.0.0.1:`) → red naming the service. The planted controls below go through
 * the same decisions.
 */
final class ComposeLocalServiceTest extends TestCase
{
    private const CREDENTIAL_HOLDERS = ['pgbouncer', 'postgres'];

    #[Test]
    public function everyLocalServiceIsLoopbackBoundAndCarriesNoApplicationCredential(): void
    {
        $services = DeployArtefacts::composeServices();
        $checked = 0;
        $portChecked = [];
        foreach (DeployArtefacts::LOCAL_COMPOSE_SERVICES as $name) {
            self::assertArrayHasKey($name, $services, "$name is listed as a local service but the compose file has no such service: the list is stale.");
            self::assertSame([], self::portFindings($name, $services[$name]), "Local service $name publishes a port beyond this machine.");
            $portChecked[] = $name;
            if (\in_array($name, self::CREDENTIAL_HOLDERS, true)) {
                continue;
            }
            ++$checked;
            self::assertSame([], self::findings($name, $services[$name]), "Local service $name is not local-only.");
        }
        self::assertGreaterThan(0, $checked, 'No non-exempt local service was checked, so this test proves nothing.');
        foreach (self::CREDENTIAL_HOLDERS as $holder) {
            self::assertContains($holder, $portChecked, "The port rule never reached the credential holder $holder.");
            self::assertNotSame([], (array) ($services[$holder]['ports'] ?? []), "Anti-vacuity: $holder publishes no port, so its port check proves nothing.");
        }

        // Planted controls through the same decision.
        $good = ['image' => 'axllent/mailpit:v1.32.0', 'ports' => ['127.0.0.1:${P:?x}:8025'], 'environment' => ['MP_MAX_MESSAGES' => '500']];
        self::assertSame([], self::findings('planted', $good));
        self::assertNotSame([], self::findings('planted', ['ports' => ['0.0.0.0:1:2']] + $good), 'An all-interfaces port was not refused.');
        self::assertNotSame([], self::findings('planted', ['ports' => ['${P}:8025']] + $good), 'A port with no host address was not refused.');
        self::assertNotSame([], self::findings('planted', ['environment' => ['ERP_APP_PASSWORD' => '${ERP_APP_PASSWORD}']] + $good), 'A credential key was not refused.');
        self::assertNotSame([], self::findings('planted', ['command' => ['x', '${DB_OWNER_PASSWORD}']] + $good), 'A credential interpolation was not refused.');
        self::assertNotSame([], self::findings('planted', ['build' => '.'] + $good), 'A build was not refused.');
        self::assertNotSame([], self::findings('planted', ['image' => 'axllent/mailpit'] + $good), 'An untagged image was not refused.');
        self::assertNotSame([], self::findings('planted', ['image' => 'axllent/mailpit:latest'] + $good), 'A latest tag was not refused.');

        // A credential holder is held to the port rule too (2a C1): its real definition passes only because it is bound to
        // the loopback; the same definition on every interface is refused.
        $holder = ['image' => 'postgres:17-alpine', 'environment' => ['POSTGRES_PASSWORD' => '${POSTGRES_SUPERUSER_PASSWORD}'], 'ports' => ['127.0.0.1:${POSTGRES_DIRECT_PORT}:5432']];
        self::assertSame([], self::portFindings('planted-holder', $holder));
        self::assertNotSame([], self::portFindings('planted-holder', ['ports' => ['${POSTGRES_DIRECT_PORT}:5432']] + $holder), 'A credential holder on every interface was not refused.');
        self::assertNotSame([], self::portFindings('planted-holder', ['ports' => ['0.0.0.0:${PGBOUNCER_PORT}:6432']] + $holder), 'A credential holder on 0.0.0.0 was not refused.');
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    private static function portFindings(string $name, mixed $definition): array
    {
        if (!\is_array($definition)) {
            return ["$name: not a map"];
        }
        $findings = [];
        foreach ((array) ($definition['ports'] ?? []) as $port) {
            if (!\is_string($port) || !str_starts_with($port, '127.0.0.1:')) {
                $findings[] = "$name: port ".json_encode($port).' is not bound to 127.0.0.1';
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    private static function findings(string $name, mixed $definition): array
    {
        if (!\is_array($definition)) {
            return ["$name: not a map"];
        }
        $findings = self::portFindings($name, $definition);
        $credential = '/^(ERP_|DB_|AUTH_|POSTGRES_)/';
        foreach (array_keys((array) ($definition['environment'] ?? [])) as $key) {
            if (1 === preg_match($credential, (string) $key)) {
                $findings[] = "$name: environment key $key names a credential";
            }
        }
        $text = (string) json_encode($definition, \JSON_UNESCAPED_SLASHES);
        if (preg_match_all('/\$\{([A-Z0-9_]+)/', $text, $m) > 0) {
            foreach ($m[1] as $variable) {
                if (1 === preg_match($credential, $variable)) {
                    $findings[] = "$name: interpolates \${$variable}";
                }
            }
        }
        if (\array_key_exists('build', $definition)) {
            $findings[] = "$name: has a build";
        }
        $image = (string) ($definition['image'] ?? '');
        $tag = str_contains(basename($image), ':') ? substr($image, (int) strrpos($image, ':') + 1) : '';
        if ('' === $tag || 'latest' === $tag) {
            $findings[] = "$name: image ".json_encode($image).' has no explicit non-latest tag';
        }

        return $findings;
    }
}
