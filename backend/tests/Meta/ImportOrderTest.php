<?php

declare(strict_types=1);

namespace App\Tests\Meta;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **One formatting rule, gated — and this project has no general code-style
 * gate, which is the more important half of this sentence.**
 *
 * ---------------------------------------------------------------------------
 * Read this before assuming the tree is formatted
 * ---------------------------------------------------------------------------
 * There is **no** `php-cs-fixer`, no `ecs`, no `rector` and no linter of any
 * kind in `composer.json`; `require-dev` is PHPUnit, `browser-kit` and
 * `css-selector`. Nothing checks brace placement, spacing, trailing commas,
 * docblock shape or anything else, and **this file does not change that**. It
 * checks one rule. Do not read a green run here as *"the codebase is
 * formatted"* — read it as *"the import lists are in order"*, which is all it
 * says.
 *
 * A formatter was the obvious answer and was not taken here: adding one is a new
 * dependency and a decision about every file in the repository, which is bigger
 * than the defect that prompted it and belongs to the owner rather than to a
 * check written alongside it. That decision is recorded as owed. **This is the
 * zero-dependency version of the one rule that actually broke.**
 *
 * ---------------------------------------------------------------------------
 * Why this rule and not another
 * ---------------------------------------------------------------------------
 * Because it broke **twice in three commits**, both times unnoticed:
 * `0c8f25a` put three import lists back in alphabetical order, `f8a26ce`
 * re-broke one, and `e992b13` fixed it again. Nothing could see it, because
 * nothing was looking. A rule that has failed twice in one working day is a rule
 * with a demonstrated need for a gate; the rest of code style, on this project,
 * has not demonstrated one.
 *
 * ---------------------------------------------------------------------------
 * What it found when it was written, which is worth knowing
 * ---------------------------------------------------------------------------
 * **34 of 183 files were out of order** — 19% — even though `0c8f25a`'s commit
 * message says alphabetical order is *"how the rest of the codebase keeps
 * them"*. That claim was an overstatement, and it could be, because no run
 * measured it. Nearly every one of the 34 was the same shape: `App\Platform\…`
 * sorted before `App\Modules\…`, which is the order those names had **before**
 * Output 5 moved the entities out of `src/Entity` and into the module that owns
 * their table. A rename does not re-sort the list it is in, and nothing asked.
 * All 34 were sorted in the commit that added this test.
 *
 * ---------------------------------------------------------------------------
 * The rule, exactly
 * ---------------------------------------------------------------------------
 * Every file under `src/` and `tests/` with two or more top-level `use`
 * statements has them sorted by `strcmp` of the imported name — the part before
 * any ` as ` alias, because the alias is a local label and not what is being
 * imported. Measured first: `strcmp` on the raw name, `strcasecmp`, and the
 * separator-folding variant a formatter uses all agree on this tree, so the
 * simplest was taken rather than a rule chosen to make the tree pass.
 *
 * Trait imports inside a class body are invisible to it: they are indented, and
 * the pattern is anchored to column zero. Every one of the 183 files has its
 * `use` statements in a **single contiguous block** (measured, not assumed), and
 * a file whose block is broken by a blank line or a comment is reported rather
 * than quietly skipped — that would be a hole of exactly the kind this step
 * exists to close.
 */
final class ImportOrderTest extends TestCase
{
    /**
     * Files with at least two top-level imports, below which a green run here
     * would be saying nothing. The tree had 183 when this was written; the floor
     * is deliberately far below that, because it is a non-vacuity guard and not
     * a second pin to maintain.
     */
    private const MINIMUM_FILES_WITH_IMPORTS = 100;

    /** A top-level import — column zero, so a trait `use` inside a class body is not one. */
    private const IMPORT = '/^use [A-Za-z_\\\\]/';

    /**
     * **Mutation:** swap any two lines of any import block in `src/` or
     * `tests/`; red, naming the file and printing the order it expected. Run
     * 2026-09-12 against `src/Platform/Tenancy/TenantContext.php` and against
     * this file's own directory neighbour.
     */
    #[Test]
    public function everyImportListIsInOrder(): void
    {
        $checked = 0;
        $broken = [];
        $unsorted = [];

        foreach (self::phpFiles() as $path) {
            $lines = explode("\n", (string) file_get_contents($path));

            $positions = [];
            foreach ($lines as $i => $line) {
                if (1 === preg_match(self::IMPORT, $line)) {
                    $positions[] = $i;
                }
            }

            if (\count($positions) < 2) {
                continue;
            }

            ++$checked;
            $first = $positions[0];
            $last = $positions[\count($positions) - 1];

            if ($last - $first + 1 !== \count($positions)) {
                $broken[] = self::relative($path);

                continue;
            }

            $block = \array_slice($lines, $first, $last - $first + 1);
            $sorted = $block;
            usort($sorted, static fn (string $a, string $b): int => strcmp(self::imported($a), self::imported($b)));

            if ($sorted !== $block) {
                $unsorted[] = self::relative($path).':'.($first + 1)."\n    expected:\n      ".implode("\n      ", $sorted);
            }
        }

        self::assertGreaterThanOrEqual(self::MINIMUM_FILES_WITH_IMPORTS, $checked, \sprintf(
            'Only %d files were checked. Either the scan stopped finding the tree or this floor is wrong; a check '
            .'over almost nothing passes for the wrong reason.', $checked));

        self::assertSame([], $broken,
            "A file's top-level `use` statements are not one contiguous block, so this check cannot read them as a "
            ."list:\n  ".implode("\n  ", $broken)
            ."\nPut them back in one block (that is the shape every other file in the tree has) rather than leaving "
            .'a file this rule cannot see.');

        self::assertSame([], $unsorted,
            "An import list is out of order:\n  ".implode("\n  ", $unsorted)
            ."\nThis broke twice in three commits before anything checked it. There is no formatter in this "
            .'repository — see this class\'s docblock — so the order is kept by this test and by nothing else.');
    }

    /** The imported name: what is before any ` as ` alias, and before the `;`. */
    private static function imported(string $line): string
    {
        return (string) preg_replace('/^use\s+([^\s;]+).*$/', '$1', $line);
    }

    /** @return list<string> */
    private static function phpFiles(): array
    {
        $root = \dirname(__DIR__, 2);
        $found = [];

        foreach (['src', 'tests'] as $directory) {
            /** @var iterable<\SplFileInfo> $files */
            $files = new \RegexIterator(
                new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS)),
                '/\.php$/',
            );

            foreach ($files as $file) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private static function relative(string $path): string
    {
        return substr($path, \strlen(\dirname(__DIR__, 2)) + 1);
    }
}
