<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ErrorResource;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\State\ApiResource\Error;
use App\Platform\Http\AlwaysPresentMembers;
use App\Platform\Http\Problem\ApiProblem;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * BK-18 R18-c, the metadata half: {@see AlwaysPresentMembers} marks a served resource's member `required` exactly when it
 * is not both nullable and dropped when null, leaves every other class untouched, and reads the same null default the
 * serializer is configured with.
 *
 * The spec half is {@see OpenApiContractTest::everyServedListOfObjectsIsItsElementClass} (rule by reflection) and
 * {@see ResponseShapeTest::everyServedBodyCarriesItsRequiredMembers} (rule by observation). This half holds what neither
 * can show: the decorator's scope (an `#[ErrorResource]`, a plain class), that its scope is exactly the walk's domain
 * (agreement over every class under `src/`, enumerated with no attribute prefilter), and that its hard-coded default is
 * the configured one.
 *
 * Kernel only; no database. ONE method (R18-d precedent).
 */
final class AlwaysPresentMembersTest extends KernelTestCase
{
    #[Test]
    public function aServedMemberIsRequiredUnlessItIsDroppedWhenNull(): void
    {
        $inner = new class implements PropertyMetadataFactoryInterface {
            public function create(string $resourceClass, string $property, array $options = []): ApiProperty
            {
                return new ApiProperty();
            }
        };
        $decorator = new AlwaysPresentMembers($inner);

        // Fixtures: a served resource, an error resource, a plain class.
        $expected = ['name' => true, 'note' => true, 'flag' => false, 'kept' => true];
        foreach ($expected as $member => $required) {
            self::assertSame($required, $decorator->create(AlwaysPresentMembersServedFixture::class, $member)->isRequired(), 'served fixture member '.$member);
        }
        self::assertNull($decorator->create(AlwaysPresentMembersPlainFixture::class, 'note')->isRequired(), 'a plain class is returned untouched');
        self::assertNull($decorator->create(AlwaysPresentMembersErrorFixture::class, 'note')->isRequired(), 'an #[ErrorResource] is returned untouched (F-2)');
        self::assertNull($decorator->create(AlwaysPresentMembersServedFixture::class, 'missing')->isRequired(), 'a property that does not exist is returned untouched');

        // Real classes out of scope (F-2).
        self::assertFalse(AlwaysPresentMembers::isServedResource(ApiProblem::class), 'ApiProblem is an #[ErrorResource]');
        self::assertFalse(AlwaysPresentMembers::isServedResource(Error::class), 'API Platform\'s own Error is not App\\');

        // Agreement (FR-3): every class declared under src/, no attribute-string prefilter.
        $declared = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $file) {
            $source = (string) file_get_contents($file);
            if (1 !== preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
                continue;
            }
            preg_match_all('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $classes);
            foreach ($classes[1] as $short) {
                $declared[] = $ns[1].'\\'.$short;
            }
        }
        self::assertGreaterThan(100, \count($declared), 'Anti-vacuity: the src/ scan found classes.');
        $served = array_values(array_filter($declared, static fn (string $class): bool => AlwaysPresentMembers::isServedResource($class)));
        sort($served);
        $walked = array_keys(OpenApiContractTest::apiResourceClasses());
        sort($walked);
        self::assertSame($walked, $served, 'AlwaysPresentMembers::isServedResource() and OpenApiContractTest\'s walk domain must be the same set of classes.');

        // The real container (F-6, FR-7): the configured default is the constant.
        self::bootKernel();
        $defaults = self::getContainer()->getParameter('api_platform.defaults');
        self::assertIsArray($defaults);
        $context = $defaults['normalization_context'] ?? null;
        self::assertIsArray($context, 'api_platform.defaults[normalization_context] is missing: flip AlwaysPresentMembers::DEFAULT_SKIPS_NULL with api_platform.yaml:66');
        self::assertArrayHasKey(AbstractObjectNormalizer::SKIP_NULL_VALUES, $context, 'skip_null_values is absent (absent = true at runtime): flip AlwaysPresentMembers::DEFAULT_SKIPS_NULL with api_platform.yaml:66');
        self::assertSame(AlwaysPresentMembers::DEFAULT_SKIPS_NULL, $context[AbstractObjectNormalizer::SKIP_NULL_VALUES], 'flip AlwaysPresentMembers::DEFAULT_SKIPS_NULL with api_platform.yaml:66');
    }
}

/** @internal fixture for {@see AlwaysPresentMembersTest}; outside API Platform's resource discovery (tests/ is not scanned). */
#[ApiResource(operations: [])]
final class AlwaysPresentMembersServedFixture
{
    public function __construct(
        public string $name,
        public ?string $note,
        #[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?bool $flag = null,
        #[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => false])]
        public ?string $kept = null,
    ) {
    }
}

/** @internal fixture for {@see AlwaysPresentMembersTest} */
final class AlwaysPresentMembersPlainFixture
{
    public ?string $note = null;
}

/** @internal fixture for {@see AlwaysPresentMembersTest} */
#[ErrorResource(operations: [])]
final class AlwaysPresentMembersErrorFixture
{
    public ?string $note = null;
}
