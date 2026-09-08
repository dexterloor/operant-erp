<?php

declare(strict_types=1);

namespace App\Tests\Meta;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **Every skip in this suite is a contract-document skip, and nothing else.**
 *
 * ---------------------------------------------------------------------------
 * What this replaces, and why the obvious answer was measured and rejected
 * ---------------------------------------------------------------------------
 * `docs/` is gitignored, so the contract-derived checks carry a guard: where
 * the document is absent they skip. A skip is invisible in a green banner, so
 * the standing worry is that some *other* test starts skipping — a broken
 * fixture, a missing extension, a `markTestSkipped` left behind after a
 * debugging session — and rides out under the same silence.
 *
 * The obvious answer is `failOnSkipped="true"`. It was **run** rather than
 * reasoned about, and it is worse than the problem: PHPUnit renders
 * `OK, but some tests were skipped!` in the identical black-on-green of a clean
 * pass **and exits 1** — a green banner and a failing build in the same run,
 * on every clean checkout, forever. Nobody reading the terminal would believe
 * the exit code.
 *
 * So the invariant is asserted instead of the count, in two clauses, and it is
 * **static**: it reads the committed source tree under `tests/`, needs no
 * document, no database, no second test run, and behaves identically on a clean
 * checkout and on a developer's machine. That last property is the whole point
 * of clause (ii) — an unguarded reach into the contract document is a defect
 * that *only* explodes on a fresh clone, which is the one place nobody can act
 * on the finding.
 *
 * ---------------------------------------------------------------------------
 * The two clauses
 * ---------------------------------------------------------------------------
 * **(i)** every `markTestSkipped` call site sits inside the body of an
 * `if (!ContractDocument::isAvailable())` guard;
 * **(ii)** every test method that *reaches* `ContractDocument::` — directly, or
 * through a helper it calls — does so only after such a guard has had its
 * chance to skip.
 *
 * **The domain is derived, never restated.** Neither clause carries a file
 * list, a method list or a count: the domain is whatever the scan finds under
 * `tests/`, and both clauses assert their subject is non-empty first, because a
 * scanner that silently reads nothing is the shape this project has now found
 * thirteen times.
 *
 * **This file scans itself and passes**, which is deliberate: the three names it
 * looks for appear here only as string constants, and a string literal is not a
 * `T_STRING`. There is therefore no exclusion list to keep in step.
 *
 * ---------------------------------------------------------------------------
 * Mutations — all five were run against `ApiConfigurationTest`, not reasoned about
 * ---------------------------------------------------------------------------
 * **(i)** add `self::markTestSkipped('x');` to a test method with no guard;
 * `everySkipSiteIsGuardedByTheContractDocument` names the file and line; red.
 *
 * **(ii)**, in the three spellings a defect could actually take, each of which
 * `everyTestMethodReachingTheContractDocumentIsGated` names by method; all red:
 * the imported short name (`ContractDocument::isAvailable()`), the
 * fully-qualified one (`\App\Tests\Support\ContractDocument::isAvailable()`),
 * and an **indirect** reach — a private helper that touches it, called from an
 * unguarded test method. All three are red on a machine where `docs/API.md`
 * exists, which is the case the run-time behaviour cannot distinguish and the
 * reason this clause is static.
 *
 * **The reverse, and it is the half that says the clause discriminates:** add a
 * guard *and* a reference to the same method; **green**. Without this the clause
 * would be indistinguishable from "no test may mention the document".
 *
 * The fully-qualified spelling is in that list because the first draft matched
 * `T_STRING` alone and let it straight through. It was found by running the
 * mutation, not by reading the code — see `isDocumentReference()`.
 */
final class SkipInvariantTest extends TestCase
{
    /**
     * The three names the scan looks for, held as **strings** so that this file
     * is not a subject of its own check by accident. `PhpToken` reports a
     * string literal as `T_CONSTANT_ENCAPSED_STRING`; every match below is on
     * `T_STRING`, so these three lines are invisible to it.
     */
    private const SKIP_CALL = 'markTestSkipped';
    private const DOCUMENT_CLASS = 'ContractDocument';
    private const AVAILABILITY_PROBE = 'isAvailable';

    /** Clause (i). */
    #[Test]
    public function everySkipSiteIsGuardedByTheContractDocument(): void
    {
        $sites = 0;
        $unguarded = [];

        foreach ($this->sourceFiles() as $path => $tokens) {
            $guards = $this->guards($tokens);

            foreach ($tokens as $index => $token) {
                if (!$this->isName($token, self::SKIP_CALL)) {
                    continue;
                }

                ++$sites;

                foreach ($guards as $guard) {
                    if ($index > $guard['bodyStart'] && $index < $guard['bodyEnd']) {
                        continue 2;
                    }
                }

                $unguarded[] = $this->where($path, $token->line);
            }
        }

        self::assertGreaterThan(0, $sites, 'The scan found no skip site at all, so this clause had no subject.');
        self::assertSame([], $unguarded, 'A skip in this suite is not a contract-document skip.');
    }

    /** Clause (ii). */
    #[Test]
    public function everyTestMethodReachingTheContractDocumentIsGated(): void
    {
        $methods = $this->methods();

        self::assertNotSame([], $methods, 'The scan parsed no method, so this clause had no subject.');

        $gates = $this->resolveGates($methods);
        $reaches = $this->resolveReaches($methods, $gates);

        $subjects = 0;
        $ungated = [];

        foreach ($methods as $key => $method) {
            if ([] === ($reaches[$key] ?? [])) {
                continue;
            }
            if (!$method['isTest']) {
                // A helper's reach is not a defect on its own; it is carried to
                // whichever test method calls it, and reported there.
                continue;
            }

            ++$subjects;
            $gate = $gates[$key] ?? null;

            foreach ($reaches[$key] as $position) {
                if (null === $gate || $position <= $gate) {
                    $ungated[] = $key;
                    break;
                }
            }
        }

        self::assertGreaterThan(0, $subjects, 'No test method reaches the contract document, so this clause had no subject.');
        self::assertSame([], array_values(array_unique($ungated)), 'A test method reads the contract document without a guard that can skip.');
    }

    /**
     * A guard is `if ( ! ContractDocument :: isAvailable ( ) )` and the block it
     * governs. Both the negation and the probe are required: `if (isAvailable())`
     * around a skip is the inverted form and must not be admitted.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return list<array{condStart: int, condEnd: int, bodyStart: int, bodyEnd: int}>
     */
    private function guards(array $tokens): array
    {
        $guards = [];

        foreach ($tokens as $index => $token) {
            if (\T_IF !== $token->id) {
                continue;
            }

            $open = $this->next($tokens, $index);
            if (null === $open || '(' !== $tokens[$open]->text) {
                continue;
            }

            $close = $this->match($tokens, $open, '(', ')');
            if (null === $close || !$this->conditionIsTheAvailabilityProbe($tokens, $open, $close)) {
                continue;
            }

            $bodyStart = $this->next($tokens, $close);
            if (null === $bodyStart) {
                continue;
            }

            if ('{' === $tokens[$bodyStart]->text) {
                $bodyEnd = $this->match($tokens, $bodyStart, '{', '}');
            } else {
                // A braceless single-statement guard runs to its semicolon.
                $bodyEnd = null;
                for ($i = $bodyStart; $i < \count($tokens); ++$i) {
                    if (';' === $tokens[$i]->text) {
                        $bodyEnd = $i;
                        break;
                    }
                }
                --$bodyStart;
            }

            if (null === $bodyEnd) {
                continue;
            }

            $guards[] = ['condStart' => $open, 'condEnd' => $close, 'bodyStart' => $bodyStart, 'bodyEnd' => $bodyEnd];
        }

        return $guards;
    }

    /** @param list<\PhpToken> $tokens */
    private function conditionIsTheAvailabilityProbe(array $tokens, int $open, int $close): bool
    {
        $negatedAt = null;

        for ($i = $open + 1; $i < $close; ++$i) {
            if ('!' === $tokens[$i]->text && null === $negatedAt) {
                $negatedAt = $i;
            }

            if (!$this->isDocumentReference($tokens, $i) || null === $negatedAt || $i < $negatedAt) {
                continue;
            }

            $operator = $this->next($tokens, $i);
            $probe = null === $operator ? null : $this->next($tokens, $operator);

            if (null !== $probe && $this->isName($tokens[$probe], self::AVAILABILITY_PROBE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every method in the scanned tree, with the positions that decide both
     * clauses. A closure is not a method and is skipped; its body still lies
     * inside the enclosing method's range, so nothing is lost.
     *
     * @return array<string, array{
     *     isTest: bool,
     *     name: string,
     *     inlineGate: int|null,
     *     directReaches: list<int>,
     *     calls: list<array{name: string, at: int}>
     * }>
     */
    private function methods(): array
    {
        $methods = [];

        foreach ($this->sourceFiles() as $path => $tokens) {
            $guards = $this->guards($tokens);
            $class = basename($path, '.php');
            $pendingIsTest = false;

            foreach ($tokens as $index => $token) {
                if (\T_ATTRIBUTE === $token->id) {
                    $end = $this->match($tokens, $index, '[', ']');
                    for ($i = $index + 1; null !== $end && $i < $end; ++$i) {
                        if ($this->isName($tokens[$i], 'Test')) {
                            $pendingIsTest = true;
                        }
                    }

                    continue;
                }

                if (\T_FUNCTION !== $token->id) {
                    continue;
                }

                $nameAt = $this->next($tokens, $index);
                if (null === $nameAt || \T_STRING !== $tokens[$nameAt]->id) {
                    $pendingIsTest = false;

                    continue; // A closure, not a method.
                }

                $name = $tokens[$nameAt]->text;
                $isTest = $pendingIsTest || str_starts_with($name, 'test');
                $pendingIsTest = false;

                $open = $this->next($tokens, $nameAt);
                $close = null === $open ? null : $this->match($tokens, $open, '(', ')');
                if (null === $close) {
                    continue;
                }

                $bodyStart = null;
                for ($i = $close + 1; $i < \count($tokens); ++$i) {
                    if (';' === $tokens[$i]->text) {
                        break; // Abstract or interface method: no body.
                    }
                    if ('{' === $tokens[$i]->text) {
                        $bodyStart = $i;
                        break;
                    }
                }

                $bodyEnd = null === $bodyStart ? null : $this->match($tokens, $bodyStart, '{', '}');
                if (null === $bodyStart || null === $bodyEnd) {
                    continue;
                }

                $methods[$class.'::'.$name] = [
                    'isTest' => $isTest,
                    'name' => $name,
                    'inlineGate' => $this->inlineGate($tokens, $guards, $bodyStart, $bodyEnd),
                    'directReaches' => $this->directReaches($tokens, $guards, $bodyStart, $bodyEnd),
                    'calls' => $this->calls($tokens, $bodyStart, $bodyEnd),
                ];
            }
        }

        return $methods;
    }

    /**
     * The earliest point in a body at which an inline guard has already had its
     * chance to skip: the closing brace of a guard whose body actually calls
     * `markTestSkipped`. A guard that does something else is not a gate.
     *
     * @param list<\PhpToken>                                                          $tokens
     * @param list<array{condStart: int, condEnd: int, bodyStart: int, bodyEnd: int}> $guards
     */
    private function inlineGate(array $tokens, array $guards, int $bodyStart, int $bodyEnd): ?int
    {
        $gate = null;

        foreach ($guards as $guard) {
            if ($guard['condStart'] < $bodyStart || $guard['bodyEnd'] > $bodyEnd) {
                continue;
            }

            for ($i = $guard['bodyStart']; $i < $guard['bodyEnd']; ++$i) {
                if ($this->isName($tokens[$i], self::SKIP_CALL)) {
                    $gate = min($gate ?? $guard['bodyEnd'], $guard['bodyEnd']);
                    break;
                }
            }
        }

        return $gate;
    }

    /**
     * References to the document class inside a body, **less** the ones in a
     * guard's own condition — `!ContractDocument::isAvailable()` is the guard,
     * not a read of the document.
     *
     * @param list<\PhpToken>                                                          $tokens
     * @param list<array{condStart: int, condEnd: int, bodyStart: int, bodyEnd: int}> $guards
     *
     * @return list<int>
     */
    private function directReaches(array $tokens, array $guards, int $bodyStart, int $bodyEnd): array
    {
        $reaches = [];

        for ($i = $bodyStart; $i < $bodyEnd; ++$i) {
            if (!$this->isDocumentReference($tokens, $i)) {
                continue;
            }

            foreach ($guards as $guard) {
                if ($i > $guard['condStart'] && $i < $guard['condEnd']) {
                    continue 2;
                }
            }

            $reaches[] = $i;
        }

        return $reaches;
    }

    /**
     * `$this->name()`, `self::name()`, `Klass::name()` — the calls through which
     * a gate or a reach propagates.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return list<array{name: string, at: int}>
     */
    private function calls(array $tokens, int $bodyStart, int $bodyEnd): array
    {
        $calls = [];

        for ($i = $bodyStart + 1; $i < $bodyEnd; ++$i) {
            if (\T_STRING !== $tokens[$i]->id) {
                continue;
            }

            $previous = $this->previous($tokens, $i);
            $following = $this->next($tokens, $i);

            if (null === $previous || null === $following || '(' !== $tokens[$following]->text) {
                continue;
            }
            if (!\in_array($tokens[$previous]->id, [\T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $calls[] = ['name' => $tokens[$i]->text, 'at' => $i];
        }

        return $calls;
    }

    /**
     * A method is gated at the earliest of its inline gate and any call to a
     * method that is itself a gate. Resolution is **by name across the whole
     * scan and conservative**: a name is a gate only if *every* method carrying
     * it is one, so an ambiguous helper cannot launder a missing guard.
     *
     * @param array<string, array{isTest: bool, name: string, inlineGate: int|null, directReaches: list<int>, calls: list<array{name: string, at: int}>}> $methods
     *
     * @return array<string, int>
     */
    private function resolveGates(array $methods): array
    {
        $gates = [];
        foreach ($methods as $key => $method) {
            if (null !== $method['inlineGate']) {
                $gates[$key] = $method['inlineGate'];
            }
        }

        do {
            $changed = false;

            foreach ($methods as $key => $method) {
                foreach ($method['calls'] as $call) {
                    $candidates = $this->byName($methods, $call['name']);
                    if ([] === $candidates) {
                        continue;
                    }

                    foreach ($candidates as $candidate) {
                        if (!isset($gates[$candidate])) {
                            continue 2;
                        }
                    }

                    if (!isset($gates[$key]) || $call['at'] < $gates[$key]) {
                        $gates[$key] = $call['at'];
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return $gates;
    }

    /**
     * A method reaches the document directly, or at the point where it calls
     * something that does. Resolution here is conservative the other way: *any*
     * method carrying the name reaching is enough.
     *
     * @param array<string, array{isTest: bool, name: string, inlineGate: int|null, directReaches: list<int>, calls: list<array{name: string, at: int}>}> $methods
     * @param array<string, int>                                                                                                                          $gates
     *
     * @return array<string, list<int>>
     */
    private function resolveReaches(array $methods, array $gates): array
    {
        $reaches = [];
        foreach ($methods as $key => $method) {
            if ([] !== $method['directReaches']) {
                $reaches[$key] = $method['directReaches'];
            }
        }

        do {
            $changed = false;

            foreach ($methods as $key => $method) {
                foreach ($method['calls'] as $call) {
                    foreach ($this->byName($methods, $call['name']) as $candidate) {
                        if ($candidate === $key || [] === ($reaches[$candidate] ?? [])) {
                            continue;
                        }
                        // A gate's own reach is discharged inside it.
                        if (isset($gates[$candidate])) {
                            continue;
                        }
                        if (\in_array($call['at'], $reaches[$key] ?? [], true)) {
                            continue;
                        }

                        $reaches[$key][] = $call['at'];
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return $reaches;
    }

    /**
     * @param array<string, array{isTest: bool, name: string, inlineGate: int|null, directReaches: list<int>, calls: list<array{name: string, at: int}>}> $methods
     *
     * @return list<string>
     */
    private function byName(array $methods, string $name): array
    {
        $keys = [];
        foreach ($methods as $key => $method) {
            if ($method['name'] === $name) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The scanned domain: every PHP file under `tests/`, found rather than
     * listed. Tokenised once and memoised, because both clauses walk it.
     *
     * @return array<string, list<\PhpToken>>
     */
    private function sourceFiles(): array
    {
        static $files = null;

        if (null !== $files) {
            return $files;
        }

        $root = \dirname(__DIR__);
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $found[$file->getPathname()] = \PhpToken::tokenize((string) file_get_contents($file->getPathname()));
            }
        }

        ksort($found);
        self::assertNotSame([], $found, 'The scan found no PHP file under '.$root.', so every clause below would be vacuous.');

        return $files = $found;
    }

    private function isName(\PhpToken $token, string $name): bool
    {
        return \T_STRING === $token->id && $token->text === $name;
    }

    /**
     * A **use** of the document class, in any of the three spellings PHP 8
     * tokenises differently: the imported short name (`T_STRING`), a relative
     * qualified name (`T_NAME_QUALIFIED`) and a leading-slash one
     * (`T_NAME_FULLY_QUALIFIED`).
     *
     * The trailing `::` is required, and it does two jobs: it is what "reaches
     * `ContractDocument::`" literally means, and it is what separates a use of
     * the class from the `use App\Tests\Support\ContractDocument;` import that
     * every one of these files opens with — an import is not a read.
     *
     * The first spelling was all this check matched when it was written, and a
     * fully-qualified call would have walked straight past it. Found by running
     * the clause-(ii) mutation, not by reading the code.
     *
     * @param list<\PhpToken> $tokens
     */
    private function isDocumentReference(array $tokens, int $index): bool
    {
        $token = $tokens[$index];

        if (!\in_array($token->id, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $segments = explode('\\', $token->text);
        if (end($segments) !== self::DOCUMENT_CLASS) {
            return false;
        }

        $operator = $this->next($tokens, $index);

        return null !== $operator && \T_DOUBLE_COLON === $tokens[$operator]->id;
    }

    /** @param list<\PhpToken> $tokens */
    private function next(array $tokens, int $from): ?int
    {
        for ($i = $from + 1; $i < \count($tokens); ++$i) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private function previous(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; --$i) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private function match(array $tokens, int $open, string $opener, string $closer): ?int
    {
        $depth = 0;

        for ($i = $open; $i < \count($tokens); ++$i) {
            $text = $tokens[$i]->text;

            // `#[` opens an attribute and is closed by a plain `]`.
            if ($text === $opener || (']' === $closer && \T_ATTRIBUTE === $tokens[$i]->id)) {
                ++$depth;
            } elseif ($text === $closer) {
                if (0 === --$depth) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function where(string $path, int $line): string
    {
        return substr($path, strrpos($path, '/tests/') + 1).':'.$line;
    }
}
