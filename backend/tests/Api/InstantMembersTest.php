<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use App\Platform\Http\AlwaysPresentMembers;
use App\Platform\Http\Instant;
use App\Platform\Http\InstantMembers;
use App\Platform\Http\Rfc3339Timestamp;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BK-18 R18-f + R18-g, the metadata half: {@see InstantMembers} adds `format: date-time` to a property marked
 * {@see Instant} and the accepted shape ({@see Rfc3339Timestamp::PROFILE}) to a marked request field, and leaves
 * everything else untouched; every marker in `src/` sits where the decorator's output is served; and a hand
 * `'date-time'` appears only through {@see Instant::SCHEMA}.
 *
 * The spec half is `OpenApiContractTest::everyDateAndTimeIsDeclaredAsOne` (backstop by name) and the observation half is
 * `RequestBodySchemaProbeTest` probe 4 and `ResponseShapeTest` (d)-(f). No kernel, no database.
 */
final class InstantMembersTest extends TestCase
{
    #[Test]
    public function aMarkedPropertyGainsTheFormatAndARequestFieldTheShape(): void
    {
        $decorator = new InstantMembers(self::inner([
            'at' => ['type' => ['string', 'null']],
            'atInRequest' => ['type' => ['string', 'null']],
            'atDescribed' => ['type' => 'string', 'description' => 'By hand.'],
            'plain' => ['type' => 'string'],
            'otherFormat' => ['type' => 'string', 'format' => 'uuid'],
            'typeless' => ['description' => 'no type'],
        ]));
        $schema = static fn (string $property): ?array => $decorator->create(InstantMembersFixture::class, $property)->getSchema();

        // (a) a plain marker gains exactly the format; its type is unchanged.
        self::assertSame(['type' => ['string', 'null'], 'format' => 'date-time'], $schema('at'), '(a) a response marker');
        // (b) a request marker also gains the shape; a hand description is kept.
        self::assertSame(['type' => ['string', 'null'], 'format' => 'date-time', 'description' => Rfc3339Timestamp::PROFILE], $schema('atInRequest'), '(b) a request marker');
        self::assertSame(['type' => 'string', 'description' => 'By hand.', 'format' => 'date-time'], $schema('atDescribed'), '(b) a hand description is kept');
        // (c) an unmarked property is untouched.
        self::assertSame(['type' => 'string'], $schema('plain'), '(c) an unmarked property');
        // (d) a marker on a schema that already has another format is untouched.
        self::assertSame(['type' => 'string', 'format' => 'uuid'], $schema('otherFormat'), '(d) another format is kept');
        // (g) a schema with no type, or none at all, is untouched and nothing throws.
        self::assertSame(['description' => 'no type'], $schema('typeless'), '(g) a schema with no type');
        self::assertNull($schema('noSchema'), '(g) a null schema');
        self::assertNull($schema('missing'), '(g) a property that does not exist');
        self::assertSame(['type' => ['string', 'null']], $decorator->create(\stdClass::class, 'at')->getSchema(), '(g) a class outside App\\ is untouched');
    }

    /**
     * (e) every marker in `src/` is served: a request marker on an input class
     * ({@see OpenApiContractTest::requestInputFields()}'s domain), a plain marker on a served resource
     * ({@see AlwaysPresentMembers::isServedResource()}). A marker on a nested view class would publish nothing.
     */
    #[Test]
    public function everyMarkerInSrcSitsWhereItIsServed(): void
    {
        $inputClasses = array_values(array_unique(array_column(OpenApiContractTest::requestInputFields(), 'class')));
        $requestMarkers = 0;
        $responseMarkers = 0;
        $misplaced = [];
        foreach (self::srcClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                foreach ($property->getAttributes(Instant::class) as $attribute) {
                    $where = $class.'::$'.$property->getName();
                    if ($attribute->newInstance()->inRequest) {
                        ++$requestMarkers;
                        if (!\in_array($class, $inputClasses, true)) {
                            $misplaced[] = $where.' is marked inRequest, but its class is no operation\'s input';
                        }
                    } else {
                        ++$responseMarkers;
                        if (!AlwaysPresentMembers::isServedResource($class)) {
                            $misplaced[] = $where.' is marked as a response member, but its class is not a served resource';
                        }
                    }
                }
            }
        }
        self::assertGreaterThanOrEqual(1, $requestMarkers, 'Anti-vacuity: no request marker in src/.');
        self::assertGreaterThanOrEqual(1, $responseMarkers, 'Anti-vacuity: no response marker in src/.');
        self::assertSame([], $misplaced, implode("\n", $misplaced));
    }

    /** (f) one mechanism: no `'date-time'` string literal in `src/` outside `Instant.php` (a token scan, not reflection). */
    #[Test]
    public function aHandDateTimeAppearsOnlyThroughTheMarkersSchema(): void
    {
        // Control: the scan sees a planted literal in code and ignores one in a comment.
        self::assertSame(1, self::dateTimeLiterals("<?php\nconst X = ['format' => 'date-time'];\n"), 'Control: a planted literal.');
        self::assertSame(1, self::dateTimeLiterals("<?php\nconst X = [\"format\" => \"date-time\"];\n"), 'Control: a planted double-quoted literal.');
        self::assertSame(0, self::dateTimeLiterals("<?php\n// 'date-time'\n/** 'date-time' */\nconst X = 1;\n"), 'Control: a comment is not code.');

        $src = \dirname(__DIR__, 2).'/src';
        $found = [];
        $scanned = 0;
        foreach (PhpSourceScan::phpFilesUnder($src) as $file) {
            ++$scanned;
            if (str_ends_with($file, '/Platform/Http/Instant.php')) {
                continue;
            }
            if (0 < ($n = self::dateTimeLiterals((string) file_get_contents($file)))) {
                $found[] = substr($file, \strlen($src) + 1).': '.$n;
            }
        }
        self::assertGreaterThan(100, $scanned, 'Anti-vacuity: the src/ scan read files.');
        self::assertSame([], $found, "A 'date-time' literal outside Instant.php; use Instant::SCHEMA or #[Instant]:\n".implode("\n", $found));
    }

    private static function dateTimeLiterals(string $source): int
    {
        $count = 0;
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \T_CONSTANT_ENCAPSED_STRING === $token[0] && 'date-time' === substr($token[1], 1, -1)) {
                ++$count;
            }
        }

        return $count;
    }

    /** @return list<class-string> */
    private static function srcClasses(): array
    {
        $classes = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $file) {
            $source = (string) file_get_contents($file);
            if (1 !== preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
                continue;
            }
            preg_match_all('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $found);
            foreach ($found[1] as $short) {
                if (class_exists($ns[1].'\\'.$short)) {
                    $classes[] = $ns[1].'\\'.$short;
                }
            }
        }
        self::assertGreaterThan(100, \count($classes), 'Anti-vacuity: the src/ scan found classes.');

        return $classes;
    }

    /** @param array<string, array<string, mixed>> $schemas */
    private static function inner(array $schemas): PropertyMetadataFactoryInterface
    {
        return new class($schemas) implements PropertyMetadataFactoryInterface {
            /** @param array<string, array<string, mixed>> $schemas */
            public function __construct(private readonly array $schemas)
            {
            }

            public function create(string $resourceClass, string $property, array $options = []): ApiProperty
            {
                return isset($this->schemas[$property]) ? (new ApiProperty())->withSchema($this->schemas[$property]) : new ApiProperty();
            }
        };
    }
}

/** @internal fixture for {@see InstantMembersTest}; outside API Platform's resource discovery (tests/ is not scanned). */
#[ApiResource(operations: [])]
final class InstantMembersFixture
{
    public function __construct(
        #[Instant]
        public ?string $at = null,
        #[Instant(inRequest: true)]
        public ?string $atInRequest = null,
        #[Instant(inRequest: true)]
        public string $atDescribed = '',
        public string $plain = '',
        #[Instant]
        public string $otherFormat = '',
        #[Instant]
        public ?string $typeless = null,
        #[Instant]
        public ?string $noSchema = null,
    ) {
    }
}
