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
}
