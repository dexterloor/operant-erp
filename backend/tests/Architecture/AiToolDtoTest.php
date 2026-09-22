<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Tests\Tenancy\TenancyTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * **`ARCHITECTURE.md` AI-4's second sentence, as a check rather than a
 * sentence: *"AI tool DTOs expose no request-plumbing columns"*.**
 *
 * ---------------------------------------------------------------------------
 * Why the rule exists, and why it is currently true for a reason that will stop
 * being true
 * ---------------------------------------------------------------------------
 * D-B (2026-09-11) measured that `erp_ai` reads `purchase_order.idempotency_key`
 * and `purchase_order.request_digest` by **table-level** grant, and that a
 * column `REVOKE` does nothing under one. The answer taken was not to hide the
 * values — hashing makes them harmless to *read* — but to keep them out of an
 * **answer**: `erp_ai` is the one principal that can turn a value it read into
 * natural language, and a key handed to another tenant's user is a key that can
 * be used to collide a write. AI-4 is that rule, and `SchemaManifest`'s
 * `purchase_order` entry closes with *"step 9 adds the check"*.
 *
 * **There is no AI module.** `src/Modules/Ai` does not exist; `deptrac.yaml`
 * declares the four `Ai*` layers and they collect nothing. So a check written
 * the obvious way — *iterate the AI tool DTOs, assert none carries these
 * columns* — **passes because it iterates nothing**, which is the exact shape
 * step 9 exists to find. Two things are therefore asserted rather than one:
 *
 *  1. **the subject side is real** — the plumbing columns are read from the
 *     running catalogue, so *"or their counterparts on any later table"* is the
 *     server's list and not a sentence somebody has to remember to extend;
 *  2. **the emptiness is stated, not enjoyed** — {@see self::THE_AI_MODULE_IS_UNBUILT}
 *     is a two-sided pin in the same idiom as `TransitionGuardTest`'s
 *     `PINNED_UNREACHABLE`. It is red the day the module appears **and** red if
 *     it is flipped while the module is still absent, so it cannot be used to
 *     silence the check and it retires itself.
 *
 * ---------------------------------------------------------------------------
 * What is checked, and the hole named rather than papered over
 * ---------------------------------------------------------------------------
 * AI-4 says *DTOs*. There is no DTO convention to derive from yet — no class,
 * no marker interface, no namespace in use — so inventing one now would be a
 * check written against a guess. This scans **every class under
 * `src/Modules/Ai`** for a property or a promoted constructor parameter whose
 * name is one of the plumbing columns, in either spelling. That is deliberately
 * **wider** than AI-4: a value that reaches no class in the module reaches no
 * DTO in it either, and a wider rule cannot be satisfied by moving the field one
 * class along. When the module is built, narrow this to the DTOs if and only if
 * the wider rule turns out to cost something real.
 *
 * **The hole, stated:** a DTO could carry the value in a differently-named
 * field (`$key`, `$digest`, `$fingerprint`) and this scan would not see it, the
 * same way `StatusMachines::ASSIGNMENT` cannot see `$this->{$property} = …`.
 * A name scan is what is statically available; the day the module exists, the
 * mapping from column to field is the thing to derive from instead.
 */
final class AiToolDtoTest extends TenancyTestCase
{
    /**
     * **The pin, and it is two-sided.** `true` asserts `src/Modules/Ai` holds no
     * class at all — so the scan below finding nothing is a fact about the tree
     * rather than a fact about the scan.
     *
     * The day the module is written, `theAiModuleIsUnbuiltAndThatIsWhyTheScanIsEmpty`
     * is red; flipping this to `false` is the fix, and flipping it makes
     * `noClassInTheAiModuleCarriesARequestPlumbingColumn` demand a non-empty
     * scan, which is the point. Flipping it **early** is also red, for the same
     * reason: an empty module cannot satisfy the non-empty demand.
     */
    private const THE_AI_MODULE_IS_UNBUILT = true;

    /** The module AI-4 governs. */
    private const AI_NAMESPACE = 'App\\Modules\\Ai\\';

    /**
     * The request-plumbing column names. `idempotency_key` is taken from the
     * class that enforces the header rather than spelled again; the two digests
     * have no such constant and are held to the catalogue by
     * `theRequestPlumbingColumnsAreTheServersAndNotAList` instead — if either is
     * ever renamed, that test is red rather than this list going quietly stale.
     *
     * ⚠ **`response_digest` was found by this check on its first run, 2026-09-12,
     * and AI-4 did not name it.** `idempotency_key.response_digest bytea` is a
     * digest of the stored **response envelope** (§6.2's store for the three
     * bulk operations) and is the same species as the other two: a value that
     * describes a request/response pair and that no business question is
     * answered by. It is **not** a live leak — `SchemaManifest` grants that table
     * to `erp_app` alone, and its own comment says in terms *"erp_ai: no natural-
     * language question is answered by request digests"* — so this is a gap in
     * the rule's coverage rather than an exposure. `ARCHITECTURE.md` AI-4 was
     * amended to name it in the same change.
     *
     * @var list<string>
     */
    private const PLUMBING_COLUMNS = [IdempotencyKeyGuard::COLUMN, 'request_digest', 'response_digest'];

    /**
     * **The subject side, read from the running catalogue.** AI-4 names two
     * columns *"and their counterparts on any later table"*; the counterparts
     * are found here rather than remembered, so `goods_receipt` joined the rule
     * at step 8-i without anybody editing it, and so will the next table.
     *
     * **This is a decision-forcing check, not a claim that every `*_digest`
     * column is request plumbing.** It asks the server what *looks* like
     * plumbing and requires the answer to be a set somebody has ruled on. A
     * future `file_digest` on an attachment table would turn it red, and the
     * right fix would be to name it here as considered-and-excluded — which is a
     * sentence somebody has to write, and that is the point. It found
     * `response_digest` this way on its first run.
     *
     * **Mutation:** drop `request_digest` from `PLUMBING_COLUMNS`; red, because
     * a column the server carries is no longer covered by the rule. **Reverse
     * mutation:** add a name no table has; red, because a rule about a column
     * that does not exist protects nothing.
     */
    #[Test]
    public function theRequestPlumbingColumnsAreTheServersAndNotAList(): void
    {
        $rows = $this->owner()->fetchAllAssociative(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND (column_name LIKE '%idempotency%' OR column_name LIKE '%digest%')
             ORDER BY table_name, column_name",
        );

        self::assertNotSame([], $rows, 'The catalogue returned no plumbing column at all, so this rule has no subject.');

        $found = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['column_name'], $rows)));
        sort($found);
        $declared = self::PLUMBING_COLUMNS;
        sort($declared);

        self::assertSame($declared, $found, \sprintf(
            "The columns the server carries that look like request plumbing are not the ones AI-4 names.\n%s\n"
            .'If a new one is legitimate, add it here and to ARCHITECTURE.md AI-4 together; if one was renamed, this '
            .'list is stale and the rule has stopped covering it.',
            implode("\n", array_map(static fn (array $r): string => '  '.$r['table_name'].'.'.$r['column_name'], $rows)),
        ));

        $tables = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['table_name'], $rows)));

        self::assertGreaterThan(1, \count($tables), \sprintf(
            'Only %s carries a plumbing column, so "or their counterparts on any later table" is not being exercised '
            .'by anything and the derivation has nothing to prove.', implode(', ', $tables)));
    }

    /**
     * The pin. See {@see self::THE_AI_MODULE_IS_UNBUILT}.
     *
     * **Mutation:** create `src/Modules/Ai/Application/Probe.php` with one class
     * in it; red here, naming the class, and the scan below stops being empty.
     */
    #[Test]
    public function theAiModuleIsUnbuiltAndThatIsWhyTheScanIsEmpty(): void
    {
        $classes = self::aiClasses();

        self::assertSame(self::THE_AI_MODULE_IS_UNBUILT, [] === $classes, \sprintf(
            "AI-4's subject and this file's pin disagree. %s\n"
            .'Found: %s',
            self::THE_AI_MODULE_IS_UNBUILT
                ? 'The Ai module now exists, so the scan below is no longer vacuous -- but do NOT flip '
                    .'THE_AI_MODULE_IS_UNBUILT yet. ALSO DUE BEFORE THE FIRST ASK TOOL (owner ruling R8 of 2026-09-22, '
                    .'V2-S-1b clause 4, owner SEC): the pick read GetPickList confers NO sales_order or sales_order_line '
                    .'table under PC-8 (SECURITY_MODEL.md PC-8 addendum, PC-12 clause 5). Ask must not map either table to '
                    .'fulfilment.view, must not expose GetPickList as a tool, and PC-8\'s table-to-code intersection must be '
                    .'built as a check (AI-SQL-1) before any tool reads a sales table. The same moment owes PC-12 clause 1\'s '
                    .'inventory_item.unit_cost exclusion. ONLY once SEC has confirmed both: set THE_AI_MODULE_IS_UNBUILT to '
                    .'false and make sure the scan covers the DTOs the tools return. Clearing this pin clears that carry -- '
                    .'the residual every sibling tripwire carries.'
                : 'The pin says the Ai module is built and there is no class under '.self::AI_NAMESPACE.'. A pin '
                    .'flipped ahead of the code would make the check below demand a non-empty scan it can never get.',
            [] === $classes ? '(nothing)' : implode(', ', $classes),
        ));
    }

    /**
     * AI-4 itself.
     *
     * **Mutation:** add a class under `src/Modules/Ai` with a
     * `public string $idempotencyKey` (or a promoted `$requestDigest`); red,
     * naming the class and the property — and red *twice*, because the pin above
     * goes with it.
     */
    #[Test]
    public function noClassInTheAiModuleCarriesARequestPlumbingColumn(): void
    {
        $classes = self::aiClasses();

        if (self::THE_AI_MODULE_IS_UNBUILT) {
            self::assertSame([], $classes,
                'The Ai module exists while the pin says it does not. Flip THE_AI_MODULE_IS_UNBUILT — do not leave '
                .'this clause deciding nothing.');

            return;
        }

        self::assertNotSame([], $classes,
            'THE_AI_MODULE_IS_UNBUILT is false and the scan found no class, so AI-4 would be asserted over an empty '
            .'set — which is the state this file was written to make impossible.');

        $names = [];
        foreach (self::PLUMBING_COLUMNS as $column) {
            $names[] = $column;
            $names[] = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column))));
        }

        $offenders = [];

        foreach ($classes as $class) {
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                continue; // a file with no loadable class-like: the pin above counts it, this scan has nothing to read
            }
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                if (\in_array($property->getName(), $names, true)) {
                    $offenders[] = $class.'::$'.$property->getName();
                }
            }
        }

        self::assertSame([], $offenders,
            "A class in the Ai module carries a request-plumbing column:\n  ".implode("\n  ", $offenders)
            ."\nARCHITECTURE.md AI-4: these never reach an AI tool DTO. erp_ai reads them by table-level grant and is "
            .'the one principal that can put a value it read into a sentence, which would hand one tenant\'s user '
            ."another's key. Hashing makes them harmless to read; this rule is what keeps them out of an answer.");
    }

    /**
     * Every class under `src/Modules/Ai`, derived from the directory rather than
     * from a list — the same walk `StatusMachines::all()` does, and for the same
     * reason.
     *
     * @return list<class-string>
     */
    private static function aiClasses(): array
    {
        $src = \dirname(__DIR__, 2).'/src/Modules/Ai';

        if (!is_dir($src)) {
            return [];
        }

        $found = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $relative = substr($file->getPathname(), \strlen($src) + 1, -4);
            /** @var class-string $class */
            $class = self::AI_NAMESPACE.str_replace('/', '\\', $relative);

            // ⚠ `class_exists()` alone is FALSE for an interface and a trait (measured, `-sec-red.md` F-4), and
            // this codebase's contracts are interfaces (`ReceiveStock`): the most likely first Ai file,
            // `Application/Contract/<Tool>.php`, would have left the pin green and V2-S-1b clause 4's carry with
            // no trigger. Fixed 2026-09-22 under the standing rule for shipped test defects. And a `.php` file that
            // declares no loadable class-like at all still counts, by its path, so nothing under src/Modules/Ai is
            // invisible to the pin.
            $found[] = class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)
                ? $class
                : $class.' (no class-like of that name loads from '.$file->getFilename().')';
        }

        sort($found);

        return $found;
    }
}
