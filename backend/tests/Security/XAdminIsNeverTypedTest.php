<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Modules\UserManagement\Domain\AdministrativeCharter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **T4 — `X(admin)` is never typed as a list outside the SoD home** (roles slice 2, C3; `docs/session12/roles-e15e-design.md`
 * §9 T4; V2-R-2 *fails if* `X(admin)` is typed as a constant; PC-14 *"it is never a list"*).
 *
 * The scan: every flat array literal under `src/` whose members are all code-shaped strings (`'<module>.<verb>'`), outside
 * `SeparationOfDuty.php` (the ONE committed copy of ADR-008 §2). One with at least two members, **every** one of them in
 * `AdministrativeCharter::excludedCodes()` (derived, not listed), is a typed `X(admin)` list. *"Every member"* keeps the
 * scan off `PermissionCatalogue`'s code list, `DefaultRoles`' role lists (`warehouse_operator` holds X(admin) codes among
 * `.view` codes) and single-code permission gates.
 *
 * **Honest limits, disclosed:** a typed list padded with one non-`X(admin)` code escapes it; a list with a comment between
 * members, a list built by concatenation or `array_merge`, and a keyed map (`'x' => 'invoice.pay'`) are not seen. T4b
 * (`GrantConfirmationPolicyTest`) and review cover the rest. Mutation M3 (type the list inside `GrantConfirmationPolicy`)
 * turns this red.
 */
final class XAdminIsNeverTypedTest extends TestCase
{
    private const ARRAY_OF_CODES = "/\\[\\s*((?:'[a-z_]+\\.[a-z_]+'\\s*,\\s*)*'[a-z_]+\\.[a-z_]+')\\s*,?\\s*\\]/";

    #[Test]
    public function noArrayOutsideTheSoDHomeIsAListOfXAdminCodes(): void
    {
        // Planted controls through the same function.
        self::assertSame(1, \count(self::typedLists("<?php \$x = ['invoice.pay', 'goods_receipt.create'];")), 'A typed X(admin) pair was not seen.');
        self::assertSame(1, \count(self::typedLists("<?php \$x = [\n    'invoice.pay',\n    'goods_receipt.create',\n];")), 'A multi-line typed pair was not seen.');
        self::assertSame([], self::typedLists("<?php \$x = ['goods_receipt.create', 'fulfilment.create', 'warehouse.view'];"), 'A role list with a .view code was flagged.');
        self::assertSame([], self::typedLists("<?php \$gate = ['permission' => 'invoice.pay'];"), 'A single-code gate was flagged.');
        self::assertSame([], self::typedLists("<?php \$x = ['invoice.pay'];"), 'A one-member list was flagged.');

        $root = \dirname(__DIR__, 2).'/src';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && 'SeparationOfDuty.php' !== $file->getFilename()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        self::assertGreaterThan(100, \count($files), 'The scan read almost nothing under src/.');
        self::assertContains($root.'/Modules/UserManagement/Application/GrantConfirmationPolicy.php', $files, 'The routing policy is not in the scan.');

        $found = [];
        foreach ($files as $path) {
            foreach (self::typedLists((string) file_get_contents($path)) as $list) {
                $found[] = substr($path, \strlen($root) + 1).': '.$list;
            }
        }

        self::assertSame([], $found, "X(admin) is typed as a list outside SeparationOfDuty (V2-R-2, PC-14). Derive it from "
            ."AdministrativeCharter::excludedCodes():\n  ".implode("\n  ", $found));
    }

    /** @return list<string> the offending array literals */
    private static function typedLists(string $source): array
    {
        $excluded = AdministrativeCharter::excludedCodes();
        preg_match_all(self::ARRAY_OF_CODES, $source, $matches);
        $lists = [];
        foreach ($matches[1] as $i => $inner) {
            preg_match_all("/'([a-z_]+\\.[a-z_]+)'/", $inner, $codes);
            if (\count($codes[1]) >= 2 && [] === array_diff($codes[1], $excluded)) {
                $lists[] = preg_replace('/\s+/', ' ', $matches[0][$i]) ?? '';
            }
        }

        return $lists;
    }
}
