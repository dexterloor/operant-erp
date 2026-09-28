<?php

declare(strict_types=1);

namespace App\Tests\Meta;

use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **What the token walk records about methods, checked against PHP's own answer. Until 2026-09-28
 * nothing checked the walk itself, and it was wrong about 101 methods in `src/`.**
 *
 * `PhpSourceScan::scan()` collected `private`/`protected`/`public` tokens at class-body depth and
 * gave them to the next `function`. A constructor's parameters sit at that same depth, because a `(`
 * does not change it. So the modifiers of **promoted constructor parameters** reached the method
 * declared after the constructor: `public function create()` following
 * `__construct(private readonly Connection $c)` was recorded `'public' => false`.
 *
 * It was found by a planted control that stayed green (finding N-2, invoice chunk 2,
 * `docs/session13/output9-sales-7-progress.md`). Renaming a guard to `refuseUnless…()` inside
 * `InvoiceIssuer::issue()` did not turn `EditWindowGuardTest` clause (b) red. Clause (b) considers
 * only public methods, and the scan had told it `issue()` was not one.
 *
 * Measured before the fix: 101 of 929 methods in `src/` were misclassified, all in one direction
 * (public read as not public). 28 of them are in `src/Modules/*\/Application/`, including
 * `InvoiceIssuer::issue`, `SalesOrderCancellation::cancel` and `PurchaseOrderApproval::approve`.
 *
 * The `RED` review of the fix (`docs/session13/n2-red.md`) found that the first version of this test
 * took its domain from the scanner under test. So it could not see a method the scanner never records,
 * and it never recorded 7 in `src/`: every `Reader::list()`, because `list` tokenises as `T_LIST`, not
 * `T_STRING`. The comparison is now **two-way**, over classes PHP itself declares. It also found shapes
 * where the parameter list contains a `{` (a property hook on a promoted parameter, and a PHP 8.5 closure
 * default). In those shapes the constructor's real body was filed under `outside`. Each shape has a
 * fixture below.
 *
 * **Limits that remain.** These are also in the scanner's docblock:
 *  - `FIXTURE_PHP85` is tokenised, never loaded, because this PHP (8.4) cannot compile it. Its
 *    expectations are hand-written, not taken from reflection.
 *  - A literal inside a closure default is filed under `outside` but is not credited to the parameter's
 *    property, because a `{` ends a default's property tracking. That was already the scanner's
 *    behaviour and is unchanged.
 */
final class PhpSourceScanTest extends TestCase
{
    /** Valid on this PHP (8.4): tokenised by the scanner AND loaded, so reflection gives the answer. */
    private const FIXTURE = <<<'PHP'
        <?php

        namespace App\Tests\Meta\PhpSourceScanFixture;

        final class Promoted
        {
            private const LIMIT = 3;

            public function __construct(
                private readonly \stdClass $first,
                #[\SensitiveParameter] protected ?\stdClass $second = null,
                private string $label = 'fixture-label',
                public readonly int $count = self::LIMIT,
            ) {
                $unused = 'promoted-constructor-body';
            }

            public function firstAfterTheConstructor(): string
            {
                return $this->label;
            }

            private function helper(): void
            {
            }

            function implicitlyPublic(): void
            {
            }

            protected function guarded(): void
            {
            }

            public static function named(): void
            {
            }
        }

        final class OnOneLine
        {
            public function __construct(private readonly \stdClass $a, protected int $b = 1) {}

            public function run(): void
            {
            }
        }

        final class PublicOnlyParameters
        {
            public function __construct(public readonly int $a)
            {
            }

            private function hidden(): void
            {
            }

            public function shown(): void
            {
            }
        }

        abstract class WithAnAbstractSignature
        {
            abstract protected function sig(int $x): void;

            private function afterPrivately(): void
            {
            }

            public function afterTheSignature(): void
            {
            }
        }

        final class HookedPromotion
        {
            public function __construct(
                public string $name { set => strtolower($value); },
                private int $count = 0,
            ) {
                $unused = 'hooked-constructor-body';
            }

            public function afterTheHookedConstructor(): void
            {
            }
        }

        final class ClassLevelHook
        {
            private string $hooked { get => 'hooked-literal'; }

            public function explicitAfterHook(): void
            {
            }

            private string $other { get => 'other-literal'; }

            function implicitAfterHook(): void
            {
            }
        }

        final class KeywordNames
        {
            public function list(): array
            {
                return ['keyword-list-literal'];
            }

            private function match(): void
            {
            }

            public function &byReference(): array
            {
                static $held = [];

                return $held;
            }

            public function print(): void
            {
            }
        }
        PHP;

    /**
     * **PHP 8.5 only** (a closure in a constant expression). It is tokenised, never loaded: the
     * tokenizer does not validate the grammar, so the scanner sees exactly what it would see on 8.5.
     */
    private const FIXTURE_PHP85 = <<<'PHP'
        <?php

        namespace App\Tests\Meta\PhpSourceScanFixture85;

        final class ClosureDefault
        {
            public function __construct(
                private \Closure $make = static function (): string { return 'closure-default-literal'; },
                private int $count = 0,
            ) {
                $unused = 'closure-constructor-body';
            }

            public function afterTheClosureConstructor(): void
            {
            }
        }
        PHP;

    #[Test]
    public function aParameterListNeverLendsAModifierToTheNextMethod(): void
    {
        $flags = array_map(static fn (array $method): bool => $method['public'], PhpSourceScan::scan(self::FIXTURE)['methods']);

        self::assertSame([
            'Promoted::__construct' => true,
            'Promoted::firstAfterTheConstructor' => true,
            'Promoted::helper' => false,
            'Promoted::implicitlyPublic' => true,
            'Promoted::guarded' => false,
            'Promoted::named' => true,
            'OnOneLine::__construct' => true,
            'OnOneLine::run' => true,
            'PublicOnlyParameters::__construct' => true,
            'PublicOnlyParameters::hidden' => false,
            'PublicOnlyParameters::shown' => true,
            'WithAnAbstractSignature::afterPrivately' => false,
            'WithAnAbstractSignature::afterTheSignature' => true,
            'HookedPromotion::__construct' => true,
            'HookedPromotion::afterTheHookedConstructor' => true,
            'ClassLevelHook::explicitAfterHook' => true,
            'ClassLevelHook::implicitAfterHook' => true,
            'KeywordNames::list' => true,
            'KeywordNames::match' => false,
            'KeywordNames::byReference' => true,
            'KeywordNames::print' => true,
        ], $flags);
    }

    /**
     * The fixture is loaded as well as scanned, so the answer above is PHP's, not only this file's.
     */
    #[Test]
    public function theFixtureAgreesWithReflection(): void
    {
        if (!class_exists('App\Tests\Meta\PhpSourceScanFixture\Promoted', false)) {
            eval(substr(self::FIXTURE, \strlen('<?php')));
        }

        $scan = PhpSourceScan::scan(self::FIXTURE);
        $expected = [];
        foreach (['Promoted', 'OnOneLine', 'PublicOnlyParameters', 'WithAnAbstractSignature', 'HookedPromotion', 'ClassLevelHook', 'KeywordNames'] as $short) {
            $class = new \ReflectionClass('App\Tests\Meta\PhpSourceScanFixture\\'.$short);
            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === $class->getName() && !$method->isAbstract()) {
                    $expected[$short.'::'.$method->getName()] = $method->isPublic();
                }
            }
        }
        ksort($expected);
        $actual = array_map(static fn (array $method): bool => $method['public'], $scan['methods']);
        ksort($actual);

        self::assertCount(21, $expected, 'The fixture loaded fewer methods than it declares, so reflection answered for part of it.');
        self::assertSame($expected, $actual);
    }

    /**
     * **A `{` inside a parameter list is not the method's body.** A property hook on a promoted
     * parameter (8.4) and a closure default (8.5) each open one. Before 2026-09-28 the first such `{`
     * was bound as the constructor's body, and the real body, with any write in it, went to `outside`,
     * which the lock-first scan ignores.
     */
    #[Test]
    public function aConstructorsBodyIsFiledUnderTheConstructorWhateverItsParametersHold(): void
    {
        $scan = PhpSourceScan::scan(self::FIXTURE);
        $scan85 = PhpSourceScan::scan(self::FIXTURE_PHP85);

        foreach ([
            [$scan, 'Promoted::__construct', "'promoted-constructor-body'"],
            [$scan, 'HookedPromotion::__construct', "'hooked-constructor-body'"],
            [$scan85, 'ClosureDefault::__construct', "'closure-constructor-body'"],
        ] as [$scanned, $key, $literal]) {
            self::assertSame([$literal], array_column($scanned['methods'][$key]['literals'], 'text'), $key.' does not hold its own body.');
            self::assertNotContains($literal, array_column($scanned['outside'], 'text'), $key."'s body leaked outside any method.");
        }

        self::assertSame(
            ['ClosureDefault::__construct' => true, 'ClosureDefault::afterTheClosureConstructor' => true],
            array_map(static fn (array $method): bool => $method['public'], $scan85['methods']),
        );
        self::assertContains("'closure-default-literal'", array_column($scan85['outside'], 'text'));
        self::assertContains("'keyword-list-literal'", array_column($scan['methods']['KeywordNames::list']['literals'], 'text'));
    }

    /**
     * The fix must not cost the scans that read a promoted parameter's default: that default is
     * still a property literal, and a constant it names is still a reference.
     */
    #[Test]
    public function aPromotedParametersDefaultIsStillRecordedAsItsProperty(): void
    {
        $scan = PhpSourceScan::scan(self::FIXTURE);

        self::assertSame(["'fixture-label'"], array_column($scan['properties']['Promoted::$label']['literals'], 'text'));
        self::assertSame(['Promoted::LIMIT'], $scan['properties']['Promoted::$count']['refs']);
        self::assertContains("'fixture-label'", array_column(PhpSourceScan::literalsReachedBy($scan, 'Promoted::firstAfterTheConstructor'), 'text'));
    }

    /**
     * **Every method of every class PHP declares from `src/` and `tests/`, compared both ways.**
     *
     * The domain comes from PHP, not from the scanner. Every file is autoloaded by its PSR-4 name, and
     * then every class-like PHP has declared from that file is taken. So a method the scanner never
     * records is reported **missing**, and a key the scanner invents is reported **extra**. Only
     * methods with a body declared in the file itself count, because the scanner records bodies; trait
     * methods are compared in the trait's own file.
     */
    #[Test]
    public function everyMethodPhpDeclaresUnderSrcAndTestsIsScannedWithItsVisibility(): void
    {
        $root = \dirname(__DIR__, 2);
        $files = [];
        foreach (['src' => 'App\\', 'tests' => 'App\\Tests\\'] as $dir => $prefix) {
            foreach (PhpSourceScan::phpFilesUnder($root.'/'.$dir) as $path) {
                $files[$path] = true;
                // A file not named like a class is a script (`bootstrap.php`, the `support/hold_*.php`
                // lock holders): autoloading it would RUN it. It is not loaded, so PHP declares nothing
                // from it, and any method the scan records there is reported as extra below.
                if (1 !== preg_match('#/[A-Z]\w*\.php$#', $path)) {
                    continue;
                }
                $name = $prefix.str_replace('/', '\\', substr($path, \strlen($root.'/'.$dir.'/'), -4));
                class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);
            }
        }

        $declared = [];
        foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $name) {
            $class = new \ReflectionClass($name);
            $file = $class->getFileName();
            if (!$class->isAnonymous() && \is_string($file) && isset($files[$file])) {
                $declared[$file][] = $class;
            }
        }

        $compared = 0;
        $afterAPromotedConstructor = 0;
        $wrong = [];
        foreach (array_keys($files) as $path) {
            $expected = [];
            foreach ($declared[$path] ?? [] as $class) {
                $constructor = $class->getConstructor();
                $promoted = null !== $constructor && [] !== array_filter($constructor->getParameters(), static fn (\ReflectionParameter $p): bool => $p->isPromoted());
                foreach ($class->getMethods() as $method) {
                    if ($method->getDeclaringClass()->getName() !== $class->getName() || $method->getFileName() !== $path || $method->isAbstract()) {
                        continue;
                    }
                    $expected[$class->getShortName().'::'.$method->getName()] = $method->isPublic();
                    if ($promoted && !$method->isConstructor()) {
                        ++$afterAPromotedConstructor;
                    }
                }
            }

            $scanned = array_map(static fn (array $method): bool => $method['public'], PhpSourceScan::scan((string) file_get_contents($path))['methods']);
            $at = substr($path, \strlen($root) + 1);
            foreach ($expected as $key => $public) {
                if (!\array_key_exists($key, $scanned)) {
                    $wrong[] = $at.' '.$key.': PHP declares it, the scan never recorded it';
                } elseif ($public !== $scanned[$key]) {
                    $wrong[] = \sprintf('%s %s: scan says %s, PHP says %s', $at, $key, var_export($scanned[$key], true), var_export($public, true));
                } else {
                    ++$compared;
                }
            }
            foreach (array_diff_key($scanned, $expected) as $key => $public) {
                $wrong[] = $at.' '.$key.': the scan recorded it, PHP declares no such method in this file';
            }
        }

        self::assertGreaterThan(1000, $compared, 'Almost no method was compared, so this check compares nothing.');
        self::assertGreaterThan(100, \count($declared), 'Almost no file under src/ or tests/ declared a class PHP could load, so the domain is empty.');
        self::assertGreaterThan(0, $afterAPromotedConstructor,
            'No method belongs to a class with a promoted constructor, so the shape this check exists for is absent.');
        self::assertSame([], $wrong, \sprintf("PhpSourceScan::scan() disagrees with PHP on %d method(s):\n%s",
            \count($wrong), implode("\n", $wrong)));
    }
}
