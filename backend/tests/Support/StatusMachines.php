<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * The status machines under `src/Entity/`, and the methods that drive them —
 * derived, never listed.
 *
 * ---------------------------------------------------------------------------
 * Why discovery is transitive, which is the correction that matters
 * ---------------------------------------------------------------------------
 * The first version of this scan matched an assignment to `$this->status` in a
 * **public method's own source**, and `RED` broke it by execution rather than by
 * argument: `GuardsStatusTransitions` already exists and is already imported by
 * all three order entities, so the obvious way for Output 9 to write four
 * transitions at once is one `transitionTo()` helper in the trait and four
 * one-line public methods that call it. Under the first scan
 * `PurchaseOrder::cancel()` would then be **invisible** — not in the transition
 * domain, so clause (a) never exercises it; not in `missingFromMatrix`, so the
 * matrix is never asked to declare it; and `STATUS_CANCELLED` still uncovered,
 * so the clause (b) pin stays green while the gap it pins has closed. Every one
 * of the three self-retiring properties `docs/API.md` §14.2 item 15 claims would
 * have been false for the most likely spelling of the thing they exist to catch.
 *
 * So a **status writer** is any method whose own source assigns to
 * `$this->status`, **or that calls a status writer** — a fixpoint over
 * `$this->…()`, `self::…()` and `static::…()`, which is the same resolution
 * `SkipInvariantTest` already does for its gate propagation. Visibility is not a
 * filter on the fixpoint's input, only on its output: a private helper is a
 * writer, and what makes its public caller a transition method is the call.
 */
final class StatusMachines
{
    /**
     * An assignment to `$this->status`, in every spelling that is statically
     * visible: plain, compound and null-coalescing. `!==`/`===` are excluded by
     * the negative lookahead.
     *
     * A **dynamic** assignment — `$this->{$property} = …` — is invisible to this
     * and to any other static scan, and is named as the one hole rather than
     * papered over. Nothing under `src/` writes a property that way.
     */
    public const ASSIGNMENT = '/\$this->status\s*(?:\?\?|\.|\+|-|\*|\/)?=(?!=)/';

    /** `$this->name(`, `self::name(`, `static::name(` — the edges of the fixpoint. */
    private const CALL = '/(?:\$this->|self::|static::)([A-Za-z_]\w*)\s*\(/';

    /**
     * Every class under `src/` that declares a `STATUS_*` constant **and** has a
     * status writer. Both halves are required: a vocabulary with no writer is
     * not a machine.
     *
     * Output 5 moved the entities out of one `src/Entity/` directory and into
     * the module that owns their table, so the scan walks `src/` and derives the
     * FQCN from the path rather than gluing a fixed namespace onto a basename.
     * That is deliberately wider than the directory it replaces: a status
     * machine that appears anywhere in production code is now in scope, and
     * `TransitionGuardTest` still holds the found set to §8.3's matrix, so
     * widening the scan cannot make either side pass vacuously.
     *
     * @return list<class-string>
     */
    public static function all(): array
    {
        $found = [];
        $src = \dirname(__DIR__, 2).'/src';

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $path = $file->getPathname();
            /** @var class-string $class */
            $class = 'App'.str_replace('/', '\\', substr($path, \strlen($src), -4));

            if (!class_exists($class)) {
                continue;
            }
            if ([] === self::statusesOf($class) || [] === self::transitionMethods($class)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    /**
     * The entity's status vocabulary — its `STATUS_*` constants, by reflection.
     *
     * @param class-string $entity
     *
     * @return list<string>
     */
    public static function statusesOf(string $entity): array
    {
        $statuses = [];

        foreach ((new \ReflectionClass($entity))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'STATUS_') && \is_string($value)) {
                $statuses[] = $value;
            }
        }

        return $statuses;
    }

    /**
     * The initial status: the default value of the mapped `status` property.
     *
     * @param class-string $entity
     */
    public static function initialStatusOf(string $entity): string
    {
        $default = (new \ReflectionClass($entity))->getDefaultProperties()['status'] ?? null;

        \assert(\is_string($default));

        return $default;
    }

    /**
     * The public entry points into the status machine: every status writer that
     * a caller outside the class can reach. The constructor is excluded because
     * the status it produces is the initial one, which
     * {@see self::initialStatusOf()} accounts for.
     *
     * @param class-string $entity
     *
     * @return list<string>
     */
    public static function transitionMethods(string $entity): array
    {
        $methods = [];

        foreach (self::writers($entity) as $name => $_) {
            $reflection = new \ReflectionMethod($entity, $name);

            if ($reflection->isPublic() && !$reflection->isStatic() && !$reflection->isConstructor()) {
                $methods[] = $name;
            }
        }

        sort($methods);

        return $methods;
    }

    /**
     * Every method of the class that can leave `$this->status` changed, at any
     * visibility, whether declared on the entity or flattened in from a trait.
     *
     * @param class-string $entity
     *
     * @return array<string, true>
     */
    public static function writers(string $entity): array
    {
        $sources = [];
        foreach ((new \ReflectionClass($entity))->getMethods() as $method) {
            $sources[$method->getName()] = self::sourceOf($entity, $method->getName());
        }

        $writers = [];
        foreach ($sources as $name => $source) {
            if (1 === preg_match(self::ASSIGNMENT, $source)) {
                $writers[$name] = true;
            }
        }

        do {
            $changed = false;

            foreach ($sources as $name => $source) {
                if (isset($writers[$name])) {
                    continue;
                }

                preg_match_all(self::CALL, $source, $calls);

                foreach ($calls[1] as $called) {
                    if (isset($writers[$called])) {
                        $writers[$name] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        return $writers;
    }

    /**
     * The methods reachable from outside the class: every public method, plus
     * everything they call, transitively.
     *
     * A **status writer that is not in this set is dead code that writes the
     * status** — the shape a half-finished refactor leaves behind when the caller
     * goes and the writer stays. The containment scan alone cannot see it, because
     * such a method contains its own assignment and therefore contains it
     * legitimately; this is the assertion that catches it, and it was added because
     * a mutation stating otherwise came back green.
     *
     * @param class-string $entity
     *
     * @return array<string, true>
     */
    public static function reachableFromPublic(string $entity): array
    {
        $sources = [];
        $reachable = [];

        foreach ((new \ReflectionClass($entity))->getMethods() as $method) {
            $name = $method->getName();
            $sources[$name] = self::sourceOf($entity, $name);

            if ($method->isPublic()) {
                $reachable[$name] = true;
            }
        }

        do {
            $changed = false;

            foreach ($sources as $name => $source) {
                if (!isset($reachable[$name])) {
                    continue;
                }

                preg_match_all(self::CALL, $source, $calls);

                foreach ($calls[1] as $called) {
                    if (isset($sources[$called]) && !isset($reachable[$called])) {
                        $reachable[$called] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return $reachable;
    }

    /**
     * The files that contribute methods to the class — its own, plus every trait
     * file behind a flattened method. A trait method reports the using class as
     * its declaring class and the **trait's** file as its file, so a line range
     * taken from one and applied to the other whitelists an arbitrary window.
     *
     * @param class-string $entity
     *
     * @return list<string>
     */
    public static function filesOf(string $entity): array
    {
        $reflection = new \ReflectionClass($entity);
        $files = [];

        $own = $reflection->getFileName();
        if (\is_string($own)) {
            $files[] = $own;
        }

        foreach ($reflection->getMethods() as $method) {
            $file = $method->getFileName();
            if (\is_string($file)) {
                $files[] = $file;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /** @param class-string $entity */
    public static function sourceOf(string $entity, string $method): string
    {
        $reflection = new \ReflectionMethod($entity, $method);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if (!\is_string($file) || !\is_int($start) || !\is_int($end)) {
            return ''; // An internal method has no source; it cannot assign a mapped property.
        }

        return implode("\n", \array_slice(self::lines($file), $start - 1, $end - $start + 1));
    }

    /**
     * A file's lines, memoised. Both the writer scan and the containment scan
     * walk the same handful of files.
     *
     * @return list<string>
     */
    public static function lines(string $file): array
    {
        static $cache = [];

        return $cache[$file] ??= explode("\n", (string) file_get_contents($file));
    }
}
