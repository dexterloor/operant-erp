<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * `docs/SECURITY_MODEL.md` §5 — the `E-*` enforcement register — read as data.
 *
 * The companion to `ContractDocument`, and it exists for the same reason and
 * with the same caveat: `docs/` is **gitignored**, so a check whose only domain
 * is the register has nothing to read on a clean checkout. Every figure this
 * project enforces is therefore **pinned in the test file** and checked against
 * the code on every run; where the document is present the pin is *also* checked
 * against the register, so the pin cannot drift from the rule silently.
 *
 * ---------------------------------------------------------------------------
 * Why this class exists at all, and it is a defect it was written to close
 * ---------------------------------------------------------------------------
 * The first version of `PreAuthBudgetTest` and `AuthLockoutThresholdTest` drove
 * their loops from `PreAuthBudgets::SLUG_CEILING` and `LockoutStore::THRESHOLD`
 * — the very constants under test. **Both mutations survived**: lowering the
 * ceiling to 50 and the threshold to 5 left the suite green, because the test
 * moved with the constant. That is this project's recurring fake-green in its
 * purest form, and it was found by running the mutation rather than by reading
 * the test. The figures are now literals in the test files, the constants are
 * compared against them, and this class is the third side of the comparison.
 */
final class SecurityModelDocument
{
    public static function path(): string
    {
        return \dirname(__DIR__, 3).'/docs/SECURITY_MODEL.md';
    }

    public static function isAvailable(): bool
    {
        return is_file(self::path());
    }

    /**
     * The register row for one `E-*` code, as its raw markdown.
     *
     * Rows are located by their own first cell — `| **E-21** | …` — so a row
     * that moves in the file is still found and a code that no longer exists
     * returns the empty string rather than the wrong row.
     */
    public static function enforcementRow(string $code): string
    {
        $needle = '| **'.$code.'** |';

        foreach (explode("\n", (string) file_get_contents(self::path())) as $line) {
            if (str_starts_with($line, $needle)) {
                return $line;
            }
        }

        return '';
    }

    /**
     * §3's catalogue, expanded — the permission codes, not the shorthand.
     *
     * §3 writes a module's codes as `inventory_item.view` `.create` `.edit`
     * `.delete`, so a backticked token beginning with a dot inherits the resource
     * from the token before it. Expanding it here rather than in a test means the
     * one place that understands the shorthand is the one place that reads it.
     *
     * §3's own heading states the total, and
     * `PermissionEnforcementContractTest::theCatalogueThisApiDrawsFromIsStillTheOneSecurityModelPublishes`
     * compares this parser's output against that number — so a parser that
     * silently stopped expanding, or stopped finding the table, fails rather than
     * returning a short list nothing checks.
     *
     * @return list<string>
     */
    public static function permissionCodes(): array
    {
        $codes = [];
        $inside = false;
        $resource = null;

        foreach (explode("\n", (string) file_get_contents(self::path())) as $line) {
            if (str_starts_with($line, '## 3. ')) {
                $inside = true;

                continue;
            }
            if ($inside && str_starts_with($line, '## ')) {
                break;
            }
            if (!$inside || !str_starts_with($line, '| `Modules\\') && !str_starts_with($line, '| `Platform\\')) {
                continue;
            }

            preg_match_all('/`([A-Za-z_][A-Za-z0-9_]*)?\.([a-z_]+)`/', $line, $matches, \PREG_SET_ORDER);

            foreach ($matches as $match) {
                if ('' !== $match[1]) {
                    $resource = $match[1];
                }
                if (null === $resource) {
                    continue;
                }
                $codes[] = $resource.'.'.$match[2];
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * §3's catalogue **with its module column**, which `permissionCodes()`
     * discards.
     *
     * PC-1a makes the Module column *"the `permission.module` value verbatim"* —
     * namespace-shaped and capitalised, `Modules\Purchasing` and
     * `Platform\Tenancy`, never bare and never lowercase. The committed
     * `PermissionCatalogue` carries that value, so it needs comparing against
     * something, and a flat list of codes cannot do it.
     *
     * The resource is reset per row, unlike in `permissionCodes()`, so a row
     * opening with shorthand cannot inherit a resource from the row above.
     *
     * @return array<string, list<string>> module => codes, in §3's order
     */
    public static function catalogueByModule(): array
    {
        $catalogue = [];
        $inside = false;

        foreach (explode("\n", (string) file_get_contents(self::path())) as $line) {
            if (str_starts_with($line, '## 3. ')) {
                $inside = true;

                continue;
            }
            if ($inside && str_starts_with($line, '## ')) {
                break;
            }
            if (!$inside || !str_starts_with($line, '| `Modules\\') && !str_starts_with($line, '| `Platform\\')) {
                continue;
            }

            $cells = explode('|', trim($line, '|'));

            if (1 !== preg_match('/`([^`]+)`/', $cells[0], $module)) {
                continue;
            }

            $codes = [];
            $resource = null;

            preg_match_all('/`([A-Za-z_][A-Za-z0-9_]*)?\.([a-z_]+)`/', $cells[1] ?? '', $matches, \PREG_SET_ORDER);

            foreach ($matches as $match) {
                if ('' !== ($match[1] ?? '')) {
                    $resource = $match[1];
                }
                if (null === $resource) {
                    continue;
                }
                $codes[] = $resource.'.'.$match[2];
            }

            $catalogue[$module[1]] = $codes;
        }

        return $catalogue;
    }

    /**
     * §4's table — the six roles it writes out, with the count each row states in
     * its own first cell.
     *
     * `owner` and `admin` are **not** here: §4 states them in prose above the
     * table, deliberately, because *"`owner`'s effective set is 'everything', and
     * spelling out 60 codes to say so obscures it"*. `derivedRoleSize()` reads
     * those two.
     *
     * `viewer`'s row is written as a derivation — *"every `.view` in §3 **except**
     * three … and two non-`.view` codes"* — so everything before the first em-dash
     * is its exclusion list and is dropped, exactly as `docs/adr/sod_check.py`
     * drops it.
     *
     * @return array<string, array{count: int, codes: list<string>}>
     */
    public static function enumeratedRoles(): array
    {
        $roles = [];

        foreach (explode("\n", (string) file_get_contents(self::path())) as $line) {
            if (1 !== preg_match('/^\| \*\*`(\w+)`\*\* \((\d+)\) \| (.*) \|$/', $line, $match)) {
                continue;
            }

            $body = $match[3];

            if (str_contains($body, '**except')) {
                $body = explode(' — ', $body, 2)[1] ?? '';
            }

            $codes = [];

            foreach (self::backtickedTokens($body) as $token) {
                if (1 === preg_match('/^\w+\.\w+$/', $token) && !\in_array($token, $codes, true)) {
                    $codes[] = $token;
                }
            }

            $roles[$match[1]] = ['count' => (int) $match[2], 'codes' => $codes];
        }

        return $roles;
    }

    /**
     * The size §4's prose states for one of its two derived rows — *"`owner`
     * holds **all 60**"*, *"**`admin` holds 54**"*.
     *
     * A size and not a set: §4 gives `admin`'s six exclusions in prose with two
     * parenthetical citations inside the list, and parsing that would be a parser
     * nobody could trust. The **set** is reconciled the other way round, against
     * ADR-008 §2 through PC-14's derivation, which is where it is actually ruled.
     */
    public static function derivedRoleSize(string $role): ?int
    {
        $pattern = 'owner' === $role
            ? '/`owner` holds \*\*all (\d+)\*\*/'
            : '/\*\*`'.preg_quote($role, '/').'` holds (\d+)\*\*/';

        if (1 === preg_match($pattern, (string) file_get_contents(self::path()), $match)) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * Every backticked token on a line.
     *
     * @return list<string>
     */
    private static function backtickedTokens(string $line): array
    {
        preg_match_all('/`([^`]+)`/', $line, $matches);

        return $matches[1];
    }

    /** The total §3's own heading states, so the parser above has something to be wrong against. */
    public static function catalogueSizeFromTheHeading(): ?int
    {
        foreach (explode("\n", (string) file_get_contents(self::path())) as $line) {
            if (1 === preg_match('/^## 3\. Catalogue — (\d+) permissions$/', $line, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }
}
