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
 *       `T_CONSTANT_ENCAPSED_STRING` and `T_ENCAPSED_AND_WHITESPACE` (the
 *       literal parts of an interpolated string or heredoc, so
 *       `"INSERT INTO {$table}"` yields the piece `INSERT INTO ` and nothing
 *       after it). Each piece has its `text` as written (quotes included) and
 *       its `value` as PHP spells it at runtime — quotes removed, escapes
 *       decoded ({@see self::decode()}). **Scans match on `value`**;
 *     - the methods it **calls** (`->x(`, `?->x(`, `::x(`);
 *     - the class names it **names** as `X::class` or `new X`, by short name.
 *    Closures and anonymous classes belong to the method they are in;
 *  - per method, the **constants of its own class it references** —
 *    `self::X`, `static::X`, `OwnShortName::X` — and `constants`: each class
 *    constant's literals and the own-class constants its value references.
 *    {@see self::literalsReachedBy()} joins them, so `executeStatement(self::SQL)`
 *    is a method holding that SQL (RED review, 2026-09-14). Another class's
 *    constant is not followed;
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
     *     methods: array<string, array{public: bool, class: string, name: string, literals: list<array{text: string, value: string, line: int}>, calls: list<string>, named: list<string>, constants: list<string>}>,
     *     outside: list<array{class: ?string, text: string, value: string, line: int}>,
     *     constants: array<string, array{literals: list<array{text: string, value: string, line: int}>, refs: list<string>}>
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
        $quoting = null;
        $constants = [];
        $constant = null;
        $inConstDeclaration = false;

        foreach ($tokens as $i => $token) {
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? $token[1] : $token;

            // Which quoting the next T_ENCAPSED_AND_WHITESPACE piece is in, so
            // its escapes can be decoded the way PHP decodes them.
            if ('"' === $text || '`' === $text) {
                $quoting = null === $quoting ? 'double' : null;
            } elseif (\T_START_HEREDOC === $id) {
                $quoting = str_contains($text, "'") ? 'nowdoc' : 'heredoc';
            } elseif (\T_END_HEREDOC === $id) {
                $quoting = null;
            }

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

            if ($atClassBody && \T_CONST === $id) {
                $inConstDeclaration = true;
                continue;
            }

            if ($atClassBody && $inConstDeclaration && \T_STRING === $id && '=' === ($tokens[self::nextMeaningful($tokens, $i)] ?? null)) {
                $constant = $class.'::'.$text;
                $constants[$constant] = ['literals' => [], 'refs' => []];
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
                    $methods[$current] = ['public' => $pendingPublic, 'class' => (string) $class, 'name' => $pendingMethod, 'literals' => [], 'calls' => [], 'named' => [], 'constants' => []];
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
                // An abstract or interface method: a signature with no body; or
                // the end of a constant declaration.
                $pendingMethod = null;
                $modifiers = [];
                $inConstDeclaration = false;
                $constant = null;
                continue;
            }

            $isLiteral = \T_CONSTANT_ENCAPSED_STRING === $id || \T_ENCAPSED_AND_WHITESPACE === $id;

            $value = !$isLiteral ? '' : (\T_CONSTANT_ENCAPSED_STRING === $id ? self::valueOfQuoted($text) : self::decode($text, (string) $quoting));

            $ownConstant = null === $class ? null : self::ownConstantAt($tokens, $i, $class);

            if (null === $current) {
                if ($isLiteral) {
                    $outside[] = ['class' => $class, 'text' => $text, 'value' => $value, 'line' => $token[2]];
                    if (null !== $constant) {
                        $constants[$constant]['literals'][] = ['text' => $text, 'value' => $value, 'line' => $token[2]];
                    }
                } elseif (null !== $constant && null !== $ownConstant) {
                    $constants[$constant]['refs'][] = $class.'::'.$ownConstant;
                }
                continue;
            }

            if (null !== $ownConstant) {
                $methods[$current]['constants'][] = $class.'::'.$ownConstant;
                continue;
            }

            if ($isLiteral) {
                $methods[$current]['literals'][] = ['text' => $text, 'value' => $value, 'line' => $token[2]];
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

        return ['namespace' => $namespace, 'methods' => $methods, 'outside' => $outside, 'constants' => $constants];
    }

    /**
     * **A method's literals plus the literals of every constant of its own class
     * it reaches** — through `self::X`, `static::X` or `OwnClass::X`, and through
     * a constant whose value is another such reference (`const B = self::A`).
     *
     * `PurchaseOrderRowLock` runs its statement as `self::SQL`, and until the RED
     * review of 2026-09-14 a scan over a method's own literals could not see a
     * write held that way: `private const SQL = 'UPDATE sales_order …'` walked
     * past the sales tripwire.
     *
     * @param array{methods: array<string, array{constants: list<string>, literals: list<array{text: string, value: string, line: int}>}>, constants: array<string, array{literals: list<array{text: string, value: string, line: int}>, refs: list<string>}>} $scan
     *
     * @return list<array{text: string, value: string, line: int}>
     */
    public static function literalsReachedBy(array $scan, string $methodKey): array
    {
        $literals = $scan['methods'][$methodKey]['literals'];
        $queue = $scan['methods'][$methodKey]['constants'];
        $seen = [];

        while (null !== ($name = array_shift($queue))) {
            if (isset($seen[$name]) || !isset($scan['constants'][$name])) {
                continue;
            }
            $seen[$name] = true;
            array_push($literals, ...$scan['constants'][$name]['literals']);
            array_push($queue, ...$scan['constants'][$name]['refs']);
        }

        return $literals;
    }

    /**
     * The name `X` when the token at `$i` is the `X` of `self::X`, `static::X`
     * or `<own short class name>::X` and is not a call or `::class`.
     *
     * @param list<mixed> $tokens
     */
    private static function ownConstantAt(array $tokens, int $i, string $class): ?string
    {
        if (\T_STRING !== self::idAt($tokens, $i) || 'class' === strtolower($tokens[$i][1])) {
            return null;
        }
        $colon = self::previousMeaningful($tokens, $i);
        if (\T_DOUBLE_COLON !== self::idAt($tokens, $colon) || '(' === ($tokens[self::nextMeaningful($tokens, $i)] ?? null)) {
            return null;
        }
        $owner = self::previousMeaningful($tokens, (int) $colon);
        $ownerId = self::idAt($tokens, $owner);
        if (\T_STATIC === $ownerId) {
            return $tokens[$i][1];
        }
        if (\in_array($ownerId, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
            $name = substr((string) strrchr('\\'.$tokens[$owner][1], '\\'), 1);

            return 'self' === strtolower($name) || $name === $class ? $tokens[$i][1] : null;
        }

        return null;
    }

    /**
     * The string a `T_CONSTANT_ENCAPSED_STRING` token spells at runtime: quotes
     * (and a `b` prefix) removed, escapes decoded as PHP decodes them.
     */
    private static function valueOfQuoted(string $token): string
    {
        $token = ltrim($token, 'bB');
        $quote = $token[0];

        return self::decode(substr($token, 1, -1), "'" === $quote ? 'single' : 'double');
    }

    /**
     * **Escapes decoded, because a scan that reads the source spelling of a
     * literal reads `"SELECT 1;\nUPDATE t"` as `1;` `\` `nUPDATE` — the `n`
     * glued to the keyword — and that is the shape a RED review planted past
     * both scans on 2026-09-14.** Single-quoted: `\\` and `\'` only. Double-quoted
     * and heredoc: PHP's escape set (`\n \r \t \v \e \f \\ \$`, `\"` outside a
     * heredoc, octal, `\x`, `\u{}`); any other backslash is kept, as PHP keeps
     * it. A nowdoc is not decoded.
     */
    public static function decode(string $body, string $quoting): string
    {
        return match ($quoting) {
            'nowdoc' => $body,
            'single' => (string) preg_replace('/\\\\([\\\\\'])/', '$1', $body),
            default => (string) preg_replace_callback(
                'heredoc' === $quoting
                    ? '/\\\\(?:([nrtvef\\\\$])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/'
                    : '/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
                static fn (array $m): string => match (true) {
                    '' !== ($m[1] ?? '') => ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f"][$m[1]] ?? $m[1],
                    '' !== ($m[2] ?? '') => \chr((int) octdec($m[2]) & 0xFF),
                    '' !== ($m[3] ?? '') => \chr((int) hexdec($m[3])),
                    default => mb_chr((int) hexdec($m[4])),
                },
                $body,
            ),
        };
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
