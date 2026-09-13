<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * **The token walk behind the source scans — one pass over one PHP file, and
 * nothing about what any particular scan is looking for.**
 *
 * Extracted from `PurchaseOrderRowLockTest` on 2026-09-14 so that the write
 * scan of `ARCHITECTURE.md` S-7(c) (`WriteOwnershipTest`) runs on the same
 * engine rather than on a second copy of it. Behaviour was kept identical for
 * the lock-first scan: its `writersOf()` output for both aggregates was dumped
 * before the extraction and compared byte-for-byte after it.
 *
 * What the walk records, per file:
 *
 *  - `namespace`: the file's namespace, so a scan can name a class by FQCN;
 *  - `methods`: every method body of every class-like, keyed
 *    `ShortClass::method`, with
 *     - the **string-literal pieces** it holds, in order, with their line —
 *       `T_CONSTANT_ENCAPSED_STRING` (quotes included, as written) and
 *       `T_ENCAPSED_AND_WHITESPACE` (the literal parts of an interpolated
 *       string or heredoc, so `"INSERT INTO {$table}"` yields the piece
 *       `INSERT INTO ` and nothing after it);
 *     - the methods it **calls** (`->x(`, `?->x(`, `::x(`);
 *     - the class names it **names** as `X::class` or `new X`, by short name.
 *    Closures and anonymous classes belong to the method they are in;
 *  - `outside`: the literal pieces **outside any method body** — class
 *    constants, property and parameter defaults — each with the class it is
 *    in (`null` outside a class). The lock-first scan ignores these, as it
 *    always has; the write scan does not, because `private const SQL = 'UPDATE …'`
 *    is a write statement wherever it is declared.
 *
 * Comments and docblocks are never literals, so prose in them is invisible to
 * every scan built on this walk.
 */
final class PhpSourceScan
{
    /**
     * @return list<string> absolute paths of every `.php` file under `$directory`, sorted
     */
    public static function phpFilesUnder(string $directory): array
    {
        $paths = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ('php' === $file->getExtension()) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return array{
     *     namespace: string,
     *     methods: array<string, array{public: bool, class: string, name: string, literals: list<array{text: string, line: int}>, calls: list<string>, named: list<string>}>,
     *     outside: list<array{class: ?string, text: string, line: int}>
     * }
     */
    public static function scan(string $source): array
    {
        $tokens = token_get_all($source);
        $namespace = '';
        $methods = [];
        $outside = [];
        $depth = 0;
        $class = null;
        $classDepth = null;
        $pendingClass = null;
        $pendingMethod = null;
        $pendingPublic = true;
        $modifiers = [];
        $current = null;
        $methodDepth = null;

        foreach ($tokens as $i => $token) {
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? $token[1] : $token;

            if (\T_NAMESPACE === $id && null === $class) {
                $next = self::nextMeaningful($tokens, $i);
                if (\in_array(self::idAt($tokens, $next), [\T_STRING, \T_NAME_QUALIFIED], true)) {
                    $namespace = $tokens[$next][1];
                }
                continue;
            }

            if (\in_array($id, [\T_CLASS, \T_TRAIT, \T_ENUM, \T_INTERFACE], true) && \T_DOUBLE_COLON !== self::idAt($tokens, self::previousMeaningful($tokens, $i))) {
                if (\T_STRING === self::idAt($tokens, self::nextMeaningful($tokens, $i))) {
                    $pendingClass = $tokens[self::nextMeaningful($tokens, $i)][1];
                }
                continue;
            }

            $atClassBody = null !== $classDepth && $depth === $classDepth && null === $current;

            if ($atClassBody && \in_array($id, [\T_PUBLIC, \T_PROTECTED, \T_PRIVATE], true)) {
                $modifiers[] = $id;
                continue;
            }

            if ($atClassBody && \T_FUNCTION === $id) {
                $next = self::nextMeaningful($tokens, $i);
                if ('&' === ($tokens[$next] ?? null)) {
                    $next = self::nextMeaningful($tokens, $next);
                }
                if (\T_STRING === self::idAt($tokens, $next)) {
                    $pendingMethod = $tokens[$next][1];
                    $pendingPublic = !\in_array(\T_PRIVATE, $modifiers, true) && !\in_array(\T_PROTECTED, $modifiers, true);
                }
                $modifiers = [];
                continue;
            }

            if ('{' === $text || \T_CURLY_OPEN === $id || \T_DOLLAR_OPEN_CURLY_BRACES === $id) {
                ++$depth;
                if (null !== $pendingClass && null === $classDepth) {
                    $class = $pendingClass;
                    $classDepth = $depth;
                    $pendingClass = null;
                } elseif (null !== $pendingMethod && null === $current && null !== $classDepth && $depth === $classDepth + 1) {
                    $current = $class.'::'.$pendingMethod;
                    $methods[$current] = ['public' => $pendingPublic, 'class' => (string) $class, 'name' => $pendingMethod, 'literals' => [], 'calls' => [], 'named' => []];
                    $methodDepth = $depth;
                    $pendingMethod = null;
                }
                continue;
            }

            if ('}' === $text) {
                if (null !== $current && $depth === $methodDepth) {
                    $current = null;
                    $methodDepth = null;
                } elseif (null !== $classDepth && $depth === $classDepth) {
                    $class = null;
                    $classDepth = null;
                }
                --$depth;
                continue;
            }

            if ($atClassBody && ';' === $text) {
                // An abstract or interface method: a signature with no body.
                $pendingMethod = null;
                $modifiers = [];
                continue;
            }

            $isLiteral = \T_CONSTANT_ENCAPSED_STRING === $id || \T_ENCAPSED_AND_WHITESPACE === $id;

            if (null === $current) {
                if ($isLiteral) {
                    $outside[] = ['class' => $class, 'text' => $text, 'line' => $token[2]];
                }
                continue;
            }

            if ($isLiteral) {
                $methods[$current]['literals'][] = ['text' => $text, 'line' => $token[2]];
                continue;
            }

            if (\in_array($id, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                $previous = self::idAt($tokens, self::previousMeaningful($tokens, $i));
                $nextIndex = self::nextMeaningful($tokens, $i);
                $next = null === $nextIndex ? null : $tokens[$nextIndex];

                if (\in_array($previous, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true) && '(' === $next) {
                    $methods[$current]['calls'][] = $text;
                }

                $isClassConstant = \T_DOUBLE_COLON === self::idAt($tokens, $nextIndex)
                    && \T_CLASS === self::idAt($tokens, self::nextMeaningful($tokens, (int) $nextIndex));
                if ($isClassConstant || \T_NEW === $previous) {
                    $methods[$current]['named'][] = substr((string) strrchr('\\'.$text, '\\'), 1);
                }
            }
        }

        return ['namespace' => $namespace, 'methods' => $methods, 'outside' => $outside];
    }

    /** @param list<mixed> $tokens */
    private static function nextMeaningful(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $n = \count($tokens); $j < $n; ++$j) {
            if (!\is_array($tokens[$j]) || !\in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function previousMeaningful(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; --$j) {
            if (!\is_array($tokens[$j]) || !\in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function idAt(array $tokens, ?int $i): ?int
    {
        return null !== $i && \is_array($tokens[$i]) ? $tokens[$i][0] : null;
    }
}
