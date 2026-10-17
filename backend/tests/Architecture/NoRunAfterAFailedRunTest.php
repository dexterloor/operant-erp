<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **No `catch` or `finally` body starts a second `run()`** — the shipped-defect fix of 2026-10-17
 * (`docs/session13/reread-fix-recommendation.md` Revision 1, `reread-fix-red.md` M-3).
 *
 * `TenantContext::run()` wraps its work in ORM `EntityManager::wrapInTransaction()`, which CLOSES the manager when any
 * throwable leaves the block — a 23505, an `ApiProblem`, anything. Nothing reopens it for the rest of the request. A
 * second `run()` after that does not fail at its start (the connection still answers `BEGIN`, `set_config` and every
 * DBAL read); it fails at its END, where `wrapInTransaction()` calls `flush()` and the closed manager throws *"The
 * EntityManager is closed."* — an HTTP 500. A re-read whose callback throws (a 409) escapes before `flush()` and so looks
 * correct, which is how `CreateCustomerAddress`'s identical-retry race shipped as a 500 behind a green conflict branch
 * (`docs/session13/reread-defect-progress.md`, probe-proven).
 *
 * The shape this scan refuses: a `catch` (of ANY exception type) or a `finally` body in `src/` that calls `->run(`
 * directly, or calls a same-class method (`$this->m(`, `self::m(`, `static::m(`) whose own body calls `->run(`. A failed
 * statement is handled INSIDE the one `run()` instead (`INSERT … ON CONFLICT … DO NOTHING`, or a savepoint), or re-read on
 * the manager's connection under `TenantContext::establishOn()` (`InvoiceIssuer::liveInvoiceNumberAfterAFailedTransaction`).
 *
 * **Disclosed limits:** one level of same-class resolution only; a catch that calls ANOTHER class's method which then runs
 * `run()`, or a caller that catches one service's exception and calls a different service, is invisible. `->run(` is
 * matched by name, so a `run()` on an unrelated object inside a catch is reported too — rename or justify it here.
 *
 * **Controls, same test:** a planted source holding each refused shape (catch → `run()`; catch → same-class helper →
 * `run()`; `finally` → `run()`; `catch (\Throwable)` → `self::` helper) is found, and its translate-only catch and its
 * non-running helper are not; the real-source scan must see a minimum number of catch/finally bodies and must know
 * `CustomerAddressWriter::create` as a method that calls `run()`, so an empty answer cannot be a blind scan.
 */
final class NoRunAfterAFailedRunTest extends TestCase
{
    #[Test]
    public function noCatchOrFinallyBodyStartsASecondRun(): void
    {
        $planted = self::scan(['Planted' => <<<'PHP'
            <?php
            final class Planted
            {
                public function directInACatch(): void
                {
                    try {
                        $this->tenants->run('t', fn () => 1);
                    } catch (UniqueConstraintViolationException) {
                        $this->tenants->run('t', fn () => 2);
                    }
                }

                public function viaAHelperInACatch(): void
                {
                    try {
                        $this->tenants->run('t', fn () => 1);
                    } catch (ApiProblem $e) {
                        return $this->reRead();
                    }
                }

                public function inAFinally(): void
                {
                    try {
                        $this->tenants->run('t', fn () => 1);
                    } finally {
                        $this->tenants?->run('t', fn () => 2);
                    }
                }

                public function viaAStaticHelperInAThrowableCatch(): void
                {
                    try {
                        $this->tenants->run('t', fn () => 1);
                    } catch (\Throwable) {
                        self::alsoReRead($this);
                    }
                }

                public function translateOnly(): void
                {
                    try {
                        $this->tenants->run('t', fn () => 1);
                    } catch (UniqueConstraintViolationException $e) {
                        throw self::translated($e);
                    }
                }

                private function reRead(): int
                {
                    return $this->tenants->run('t', fn () => 3);
                }

                private static function alsoReRead(object $self): int
                {
                    return $self->tenants->run('t', fn () => 4);
                }

                private static function translated(\Throwable $e): \Throwable
                {
                    return $e;
                }
            }
            PHP]);
        self::assertSame([
            'Planted::directInACatch catch → ->run(',
            'Planted::inAFinally finally → ->run(',
            'Planted::viaAHelperInACatch catch → reRead() → ->run(',
            'Planted::viaAStaticHelperInAThrowableCatch catch → alsoReRead() → ->run(',
        ], array_keys($planted['findings']),
            'The scan does not find exactly the planted shapes (catch → run, catch → helper → run, finally → run, catch '
            .'(\Throwable) → self:: helper → run) and none of the translate-only catch.');

        $sources = [];
        $root = \dirname(__DIR__, 2).'/';
        foreach (PhpSourceScan::phpFilesUnder($root.'src') as $path) {
            $sources[substr($path, \strlen($root))] = (string) file_get_contents($path);
        }
        $real = self::scan($sources);

        self::assertGreaterThanOrEqual(40, $real['bodies'],
            'Control: the scan saw fewer than 40 catch/finally bodies in src/, so it may be blind.');
        self::assertContains('CustomerAddressWriter::create', $real['runners'],
            'Control: the scan no longer knows CustomerAddressWriter::create as a method that calls run(), so it may be blind.');

        self::assertSame([], $real['findings'],
            "A catch or finally body starts a second run(). The first run() closed the EntityManager when the exception left\n"
            ."wrapInTransaction(), so the second one fails at its flush() with \"The EntityManager is closed.\" (HTTP 500) whenever\n"
            .'its callback returns. Handle the failure inside the one run() (ON CONFLICT … DO NOTHING, or a savepoint), or re-read '
            .'on the manager\'s connection under TenantContext::establishOn() (InvoiceIssuer). Found: '
            .json_encode($real['findings'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, string> $sources path or label => file source
     *
     * @return array{findings: array<string, string>, bodies: int, runners: list<string>}
     */
    private static function scan(array $sources): array
    {
        $findings = [];
        $bodies = 0;
        $allRunners = [];

        foreach ($sources as $label => $source) {
            $tokens = array_values(array_filter(
                token_get_all($source),
                static fn ($t): bool => !\is_array($t) || !\in_array($t[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
            ));
            $count = \count($tokens);

            $class = (string) $label;
            for ($c = 0; $c < $count; ++$c) {
                if (\is_array($tokens[$c]) && \in_array($tokens[$c][0], [\T_CLASS, \T_TRAIT, \T_ENUM], true)
                    && isset($tokens[$c + 1]) && \is_array($tokens[$c + 1]) && \T_STRING === $tokens[$c + 1][0]) {
                    $class = $tokens[$c + 1][1];
                    break;
                }
            }

            // Named methods: name => [first body token, matching close brace].
            $methods = [];
            for ($i = 0; $i < $count; ++$i) {
                if (!\is_array($tokens[$i]) || \T_FUNCTION !== $tokens[$i][0]) {
                    continue;
                }
                $j = $i + 1;
                if (isset($tokens[$j]) && '&' === $tokens[$j]) {
                    ++$j;
                }
                if (!isset($tokens[$j]) || !\is_array($tokens[$j]) || '(' === $tokens[$j]) {
                    continue; // a closure: it belongs to its enclosing method
                }
                $name = $tokens[$j][1];
                $open = self::bodyOpenAfterParens($tokens, $j + 1);
                if (null === $open) {
                    continue;
                }
                $methods[$name] = [$open, self::matchingClose($tokens, $open)];
            }

            $runners = [];
            foreach ($methods as $name => [$open, $close]) {
                if (self::callsRun($tokens, $open, $close)) {
                    $runners[$name] = true;
                    $allRunners[] = $class.'::'.$name;
                }
            }

            for ($i = 0; $i < $count; ++$i) {
                if (!\is_array($tokens[$i]) || !\in_array($tokens[$i][0], [\T_CATCH, \T_FINALLY], true)) {
                    continue;
                }
                $kind = \T_CATCH === $tokens[$i][0] ? 'catch' : 'finally';
                $open = \T_CATCH === $tokens[$i][0] ? self::bodyOpenAfterParens($tokens, $i + 1) : $i + 1;
                if (null === $open || '{' !== $tokens[$open]) {
                    continue;
                }
                $close = self::matchingClose($tokens, $open);
                ++$bodies;

                $enclosing = '?';
                foreach ($methods as $name => [$mOpen, $mClose]) {
                    if ($mOpen < $i && $i < $mClose) {
                        $enclosing = $name;
                    }
                }

                if (self::callsRun($tokens, $open, $close)) {
                    $findings[$class.'::'.$enclosing.' '.$kind.' → ->run('] = $label.':'.$tokens[$i][2];
                    continue;
                }
                for ($k = $open; $k < $close - 2; ++$k) {
                    $receiver = $tokens[$k];
                    $isSelfCall = (\is_array($receiver) && \T_VARIABLE === $receiver[0] && '$this' === $receiver[1]
                            && \is_array($tokens[$k + 1]) && \in_array($tokens[$k + 1][0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true))
                        || (\is_array($receiver) && \in_array(strtolower($receiver[1]), ['self', 'static'], true)
                            && \is_array($tokens[$k + 1]) && \T_DOUBLE_COLON === $tokens[$k + 1][0]);
                    if (!$isSelfCall || !\is_array($tokens[$k + 2]) || \T_STRING !== $tokens[$k + 2][0] || '(' !== ($tokens[$k + 3] ?? null)) {
                        continue;
                    }
                    $callee = $tokens[$k + 2][1];
                    if (isset($runners[$callee])) {
                        $findings[$class.'::'.$enclosing.' '.$kind.' → '.$callee.'() → ->run('] = $label.':'.$tokens[$i][2];
                    }
                }
            }
        }
        ksort($findings);
        sort($allRunners);

        return ['findings' => $findings, 'bodies' => $bodies, 'runners' => $allRunners];
    }

    /** @param list<mixed> $tokens */
    private static function callsRun(array $tokens, int $open, int $close): bool
    {
        for ($k = $open; $k < $close - 2; ++$k) {
            if (\is_array($tokens[$k]) && \in_array($tokens[$k][0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)
                && \is_array($tokens[$k + 1]) && \T_STRING === $tokens[$k + 1][0] && 'run' === strtolower($tokens[$k + 1][1])
                && '(' === $tokens[$k + 2]) {
                return true;
            }
        }

        return false;
    }

    /**
     * From `$from`, skip one balanced `( … )` group, then return the index of the next `{` — or null at a `;` first
     * (an abstract or interface method).
     *
     * @param list<mixed> $tokens
     */
    private static function bodyOpenAfterParens(array $tokens, int $from): ?int
    {
        $count = \count($tokens);
        $depth = 0;
        for ($k = $from; $k < $count; ++$k) {
            if ('(' === $tokens[$k]) {
                ++$depth;
            } elseif (')' === $tokens[$k]) {
                --$depth;
                if (0 === $depth) {
                    break;
                }
            }
        }
        for (++$k; $k < $count; ++$k) {
            if ('{' === $tokens[$k]) {
                return $k;
            }
            if (';' === $tokens[$k]) {
                return null;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function matchingClose(array $tokens, int $open): int
    {
        $count = \count($tokens);
        $depth = 0;
        for ($k = $open; $k < $count; ++$k) {
            $t = $tokens[$k];
            if ('{' === $t || (\is_array($t) && \in_array($t[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
            } elseif ('}' === $t) {
                --$depth;
                if (0 === $depth) {
                    return $k;
                }
            }
        }

        return $count - 1;
    }
}
