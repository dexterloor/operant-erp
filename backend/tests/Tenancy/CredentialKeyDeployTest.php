<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Runtime\ServingCredentialGuard;
use App\Tests\Support\DeployArtefacts;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * **T-G75-1 — the password-seal key's release gate, as a mechanism** (Mail-C0, 2026-10-23; owner ruling D-C3/G-75,
 * `docs/session13/dc3-g75-ruling.md`; `dc3-g75-recommendation.md` Revision 3 FINAL; plan Revision 1 FINAL §R7).
 * Standalone on purpose: H5 switches itself off when its operand (i) is false (`leavesTheLaptop()` returns `[]`), and
 * G-10's closing work would make it false, silencing the G-75 deadline with it (dc3-g75 RED M-1).
 *
 * **Domain:** a *key-holding artefact* is a deploy artefact ({@see DeployArtefacts::isArtefact()}) or a compose service
 * outside {@see DeployArtefacts::LOCAL_COMPOSE_SERVICES} whose CONTENTS name `AUTH_CREDENTIAL_MAC_KEY`.
 *   (a) every key-holding artefact that serves or works (names `public/index.php`, `php-fpm`, `frankenphp`, `php -S` or
 *       `messenger:consume`, or is a compose service) also names `app:credentials:check-key`. A migration-only artefact
 *       (its only command `doctrine:migrations:*`) is exempt: `check-key` runs on `erp_app` and the console guard refuses
 *       it beside the owner DSN; its key is T-G75-2's check at Mail-C1.
 *   (b) a literal `AUTH_CREDENTIAL_MAC_KEY_ID` value (not a `${…}` interpolation) is ONE value across every key-holding
 *       artefact.
 *   (c) if any key-holding artefact exists, the console registers `app:credentials:reseal` and `app:credentials:revoke`
 *       (Cred-C2) — "Cred-C2 before the first deploy" as a mechanism. *(Reading choice, plan §R7: conditional on an
 *       artefact, because Rev 3 says the real tree with no artefact is green on (a)–(d), and neither command exists yet.)*
 *   (d) `ServingCredentialGuard::ALLOWED_NAMES` carries `AUTH_CREDENTIAL_MAC_KEY_PREVIOUS` and `AUTH_CREDENTIAL_MAC_KEY_ID`
 *       (unconditional).
 * Plants pass CONTENTS, not names, through the same decision (H5's pattern). Mutation M23: each clause planted false in
 * turn → red; dropping the migration-job exemption → the migration-only plant red.
 */
final class CredentialKeyDeployTest extends KernelTestCase
{
    private const KEY = 'AUTH_CREDENTIAL_MAC_KEY';

    private const SERVING = ['public/index.php', 'php-fpm', 'frankenphp', 'php -S', 'messenger:consume'];

    #[Test]
    public function everyKeyHoldingDeployArtefactRunsTheKeyCheckAndTheRotationCommandsExist(): void
    {
        $commands = self::consoleCommands();
        self::assertContains('app:credentials:check-key', $commands, 'The console command list was not read.');
        $allowed = ServingCredentialGuard::ALLOWED_NAMES;

        // Planted controls (contents, not names).
        $serving = ['Dockerfile' => "ENV ".self::KEY."=\${".self::KEY."}\nCMD php-fpm"];
        $full = [...$commands, 'app:credentials:reseal', 'app:credentials:revoke'];
        self::assertNotSame([], self::obligations($serving, [], $full, $allowed), '(a): a serving artefact without check-key was not refused.');
        self::assertSame([], self::obligations(['Dockerfile' => $serving['Dockerfile']."\nRUN php bin/console app:credentials:check-key"], [], $full, $allowed), '(a): check-key did not satisfy it.');
        self::assertSame([], self::obligations(['Procfile' => 'release: '.self::KEY.' php bin/console doctrine:migrations:migrate'], [], $full, $allowed), '(a): the migration-only artefact was not exempt.');
        self::assertNotSame([], self::obligations([], ['app' => 'environment: {'.self::KEY.': x}'], $full, $allowed), '(a): a key-holding compose service without check-key was not refused.');
        $withCheck = static fn (string $id): string => self::KEY."=k\n".self::KEY."_ID=$id\nCMD php-fpm && php bin/console app:credentials:check-key";
        self::assertNotSame([], self::obligations(['Dockerfile' => $withCheck('aaaaaaaa'), 'fly.toml' => $withCheck('bbbbbbbb')], [], $full, $allowed), '(b): two key ids were not refused.');
        self::assertSame([], self::obligations(['Dockerfile' => $withCheck('aaaaaaaa'), 'fly.toml' => $withCheck('aaaaaaaa')], [], $full, $allowed), '(b): one key id twice was refused.');
        self::assertNotSame([], self::obligations(['Dockerfile' => $withCheck('aaaaaaaa')], [], $commands, $allowed), '(c): a key-holding artefact before Cred-C2 was not refused.');
        self::assertNotSame([], self::obligations([], [], $full, array_values(array_diff($allowed, [self::KEY.'_ID']))), '(d): ALLOWED_NAMES without _KEY_ID was not refused.');
        self::assertNotSame([], self::obligations([], [], $full, array_values(array_diff($allowed, [self::KEY.'_PREVIOUS']))), '(d): ALLOWED_NAMES without _PREVIOUS was not refused.');
        self::assertSame([], self::obligations(['Dockerfile' => 'CMD php-fpm'], [], $commands, $allowed), 'An artefact that holds no key armed the gate.');
        // Mail-C1 (RED M-6): the mail worker is a key-holding artefact from its first deploy — it holds the root key and the
        // only token INSERT — so a worker service running `messenger:consume` must run check-key too (today on `erp_app`,
        // which the worker keeps only until G-1.3's split; `RESIDUAL_GAPS.md` G-1.3). Mutation MU-39 → red.
        $worker = 'command: php bin/console messenger:consume async'."\n".'environment: {'.self::KEY.': x}';
        self::assertNotSame([], self::obligations([], ['worker' => $worker], $full, $allowed), '(a): a key-holding worker service without check-key was not refused.');
        self::assertSame([], self::obligations([], ['worker' => $worker."\nentrypoint: php bin/console app:credentials:check-key"], $full, $allowed), '(a): check-key did not satisfy the worker.');
        // A compose service is caught by the service clause whatever it runs, so only a worker in a deploy FILE proves the
        // `messenger:consume` token itself (MU-39 was green against the compose plant alone).
        $procfile = 'worker: '.self::KEY.'=x php bin/console messenger:consume async';
        self::assertNotSame([], self::obligations(['Procfile' => $procfile], [], $full, $allowed), '(a): a key-holding worker deploy file without check-key was not refused.');

        [$files, $services] = self::realArtefacts();
        $owed = self::obligations($files, $services, $commands, $allowed);
        self::assertSame([], $owed, "G-75's release gate (T-G75-1) is not met:\n  ".implode("\n  ", $owed));
    }

    /**
     * @param array<string, string> $files    deploy-artefact path => contents
     * @param array<string, string> $services non-local compose service => its definition as text
     * @param list<string>          $commands registered console command names
     * @param list<string>          $allowed  ServingCredentialGuard::ALLOWED_NAMES
     *
     * @return list<string> what is owed, empty when the gate is met
     */
    private static function obligations(array $files, array $services, array $commands, array $allowed): array
    {
        $owed = [];
        $holding = [];
        foreach ($files as $path => $text) {
            if (str_contains($text, self::KEY)) {
                $holding['file '.$path] = [$text, false];
            }
        }
        foreach ($services as $name => $text) {
            if (str_contains($text, self::KEY)) {
                $holding['compose service '.$name] = [$text, true];
            }
        }
        $ids = [];
        foreach ($holding as $what => [$text, $isService]) {
            $serves = $isService;
            foreach (self::SERVING as $token) {
                $serves = $serves || str_contains($text, $token);
            }
            $migrationOnly = str_contains($text, 'doctrine:migrations:') && !array_filter(self::SERVING, static fn (string $t): bool => str_contains($text, $t));
            if ($serves && !$migrationOnly && !str_contains($text, 'app:credentials:check-key')) {
                $owed[] = "(a) $what holds the seal key and serves or works, but never runs app:credentials:check-key";
            }
            if (preg_match_all('/'.self::KEY.'_ID["\']?\s*[=:]\s*["\']?([^\s"\'$}{,]+)/', $text, $m) > 0) {
                foreach ($m[1] as $id) {
                    $ids[$id][] = $what;
                }
            }
        }
        if (\count($ids) > 1) {
            $owed[] = '(b) the key-holding artefacts name '.\count($ids).' different '.self::KEY.'_ID values: '.implode(', ', array_keys($ids));
        }
        if ([] !== $holding) {
            foreach (['app:credentials:reseal', 'app:credentials:revoke'] as $command) {
                if (!\in_array($command, $commands, true)) {
                    $owed[] = "(c) a key-holding deploy artefact exists but $command is not built (Cred-C2 comes before the first deploy)";
                }
            }
        }
        foreach ([self::KEY.'_PREVIOUS', self::KEY.'_ID'] as $name) {
            if (!\in_array($name, $allowed, true)) {
                $owed[] = "(d) ServingCredentialGuard::ALLOWED_NAMES lacks $name";
            }
        }

        return $owed;
    }

    /** @return array{array<string, string>, array<string, string>} */
    private static function realArtefacts(): array
    {
        $files = [];
        foreach (DeployArtefacts::filesUnderTheRepository() as $path) {
            if (DeployArtefacts::isArtefact($path)) {
                $files[$path] = (string) file_get_contents(DeployArtefacts::repositoryRoot().'/'.$path);
            }
        }
        $services = [];
        foreach (DeployArtefacts::composeServices() as $name => $definition) {
            if (!\in_array($name, DeployArtefacts::LOCAL_COMPOSE_SERVICES, true)) {
                $services[(string) $name] = Yaml::dump($definition, 8);
            }
        }

        return [$files, $services];
    }

    /** @return list<string> */
    private static function consoleCommands(): array
    {
        $application = new Application(self::bootKernel());

        return array_keys($application->all());
    }
}
