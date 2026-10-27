<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **Every caller of the role-administration actor re-check names the code its operation is gated on** — roles slice 4 C0
 * (design F-1, §11 C0 (a)).
 *
 * Until C0 `RoleAdministrationActor` hard-coded `role.assign`. It now takes the code from its caller, which is what lets
 * `CreateRole`/`UpdateRole`/`DeleteRole` re-derive `role.create`/`role.edit`/`role.delete` under the lock. The cost of a
 * parameter is that a caller can pass the wrong one, and the behaviour tests reach the lock-time branch only through a race
 * (`PurchaseOrderRowLockTest`), not for every caller. So the calls are derived from `src/` and held to a pin:
 *
 *  - every `->reCheckUnderTheLock(` call under `src/` is found and its LAST argument resolved — a string literal, or a
 *    `self::CONSTANT` of the calling class read by reflection (an argument of any other shape is a failure, not a skip);
 *  - the derived `class → code` map must EQUAL {@see self::PINNED}. A new caller is red here until its operation's §4.2
 *    `Perm` code is added — the same string its `PermissionGate::OPERATION_PERMISSION` declaration carries.
 *
 * When C0 landed every one of the six callers passed `role.assign`, byte-identical to the constant it replaced, so C0
 * changed no shipped behaviour (each operation's 403 body and denial row still name `role.assign`).
 *
 * **Controls, same test:** the extraction reads a planted literal and a planted `self::` constant out of strings this test
 * builds, and the domain is not empty.
 */
final class RoleAdministrationActorCallerTest extends TestCase
{
    /** @var array<string, string> calling class (short name) → the code it re-derives under the lock */
    private const PINNED = [
        // G5-C1: `CreateBulkRoleAssignment`'s own §4.2 code.
        'BulkRoleAssignment' => 'role.assign',
        'RoleAssignment' => 'role.assign',
        // Roles slice 4 C1: `CreateRole`'s own §4.2 code.
        'RoleCreation' => 'role.create',
        // Roles slice 4 C3: `DeleteRole`'s own §4.2 code (its `role.assign` backstop reads the returned set; it is not a
        // second call).
        'RoleDeletion' => 'role.delete',
        'RoleGrantProposalCancellation' => 'role.assign',
        'RoleGrantProposalConfirmation' => 'role.assign',
        'RoleGrantProposalCreation' => 'role.assign',
        'RoleGrantProposalRejection' => 'role.assign',
        // G2: `RestoreRole`'s own §4.2 code (its `role.assign` backstop reads the returned set, as `RoleDeletion`'s).
        'RoleRestoration' => 'role.delete',
        'RoleRevocation' => 'role.assign',
        // Roles slice 4 C2: `UpdateRole`'s own §4.2 code.
        'RoleUpdate' => 'role.edit',
        // G4-C2: `DeleteUser`'s own §4.2 code.
        'UserCreation' => 'app_user.create',
        'UserDeletion' => 'app_user.delete',
        // G4-C3: `RestoreUser`'s own §4.2 code (undoing a delete).
        'UserRestoration' => 'app_user.delete',
        // G4-C1: `UpdateUser`'s own §4.2 code (passed as `self::REQUIRED_PERMISSION`).
        'UserUpdate' => 'app_user.edit',
    ];

    private const CALL = '/->reCheckUnderTheLock\(([^;]*)\);/';

    #[Test]
    public function everyCallerReDerivesItsOwnOperationsCode(): void
    {
        self::assertSame(['literal'], self::lastArguments('<?php $this->actor->reCheckUnderTheLock($em, $i, $ip, $s, \'literal\');'), 'A literal last argument was not read.');
        self::assertSame(['self::X'], self::lastArguments("<?php \$a->reCheckUnderTheLock(\$em,\n \$i, \$ip, \$s, self::X);"), 'A self:: constant across a line break was not read.');

        $root = \dirname(__DIR__, 2).'/src';
        $derived = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $arguments = self::lastArguments($source);
            if ([] === $arguments) {
                continue;
            }
            self::assertSame(1, preg_match('/^namespace\s+([^;]+);/m', $source, $namespace), $file->getPathname().' has no namespace.');
            $class = $namespace[1].'\\'.$file->getBasename('.php');
            self::assertCount(1, $arguments, $class.' re-checks the actor more than once; one operation re-derives one code.');
            $derived[$file->getBasename('.php')] = self::resolve($class, $arguments[0]);
        }
        ksort($derived);

        self::assertNotSame([], $derived, 'No caller of reCheckUnderTheLock was found under src/, so the scan is blind.');
        self::assertSame(self::PINNED, $derived, "A role-administration caller re-derives a code this pin does not name. Pass the operation's OWN "
            .'§4.2 Perm code (the one its PermissionGate declaration carries) and add the caller here.');
    }

    /** @return list<string> the raw last argument of every call in `$source` */
    private static function lastArguments(string $source): array
    {
        preg_match_all(self::CALL, $source, $matches);
        $arguments = [];
        foreach ($matches[1] as $list) {
            $parts = array_map('trim', explode(',', $list));
            $last = (string) end($parts);
            $arguments[] = trim($last, "'");
        }

        return $arguments;
    }

    private static function resolve(string $class, string $argument): string
    {
        if (str_starts_with($argument, 'self::')) {
            self::assertTrue(class_exists($class), $class.' does not autoload.');
            $value = (new \ReflectionClassConstant($class, substr($argument, 6)))->getValue();
            self::assertIsString($value);

            return $value;
        }
        self::assertMatchesRegularExpression('/\A[a-z_]+\.[a-z_]+\z/', $argument, $class.' passes something that is neither a code literal nor a self:: constant: '.$argument);

        return $argument;
    }
}
