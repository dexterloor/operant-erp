<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use App\Platform\Http\PathParameterDescriptions;
use App\Platform\Http\UuidPathParameters;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;

/**
 * BK-18 R18-e, the unit half: {@see PathParameterDescriptions} over a stub factory, so its rule, its guard (a Link with
 * its own description is left alone), its scope (`App\Modules\` only) and its word list are each held by a red, not
 * reviewed.
 *
 * The spec half is {@see OpenApiContractTest::everyPathParameterSaysWhoseIdItIs}, which computes the expected words by the
 * inverse rule and never reads this decorator. This test is where the decorator's {@see PathParameterDescriptions::WORD_FOR_SEGMENT}
 * is pinned to T-1's {@see OpenApiContractTest::RECORD_WORDS_BY_UX} (U-8), so the two declarations cannot drift.
 *
 * No kernel, no database.
 */
final class PathParameterDescriptionsTest extends TestCase
{
    private const MODULE = 'App\\Modules\\Fake\\FakeResource';

    #[Test]
    public function theDecoratorDescribesEachIdByTheUrlWordItFollows(): void
    {
        $failures = [];
        $expect = static function (string $case, mixed $expected, mixed $actual) use (&$failures): void {
            if ($expected !== $actual) {
                $failures[] = $case.': expected '.var_export($expected, true).', got '.var_export($actual, true);
            }
        };

        // U-1: the parent and the child variable, kebab case read as words, the `sses` rule.
        $u1 = self::decorated('/fakes/{id}/sub-addresses/{subId}', ['id' => new Link(), 'subId' => new Link()]);
        $expect('U-1 id', 'The fake\'s id.', $u1['id']->getDescription());
        $expect('U-1 subId', 'The sub address\'s id.', $u1['subId']->getDescription());

        // U-2: a Link that declares its own description is the same instance.
        $own = new Link(description: 'x');
        $u2 = self::decorated('/fakes/{id}', ['id' => $own]);
        $expect('U-2 untouched', true, $own === $u2['id']);

        // U-3: a resource outside App\Modules\ is returned unchanged.
        $platform = new Link();
        $u3 = self::uriVariablesOf((new PathParameterDescriptions(self::stubFactory('/fakes/{id}', ['id' => $platform])))->create('App\\Platform\\Fake\\FakeResource'));
        $expect('U-3 untouched', true, $platform === $u3['id']);

        // U-4: no literal segment before the variable → no description (the vendor text, and T-1's red).
        $u4 = self::decorated('/{id}', ['id' => new Link()]);
        $expect('U-4 null', null, $u4['id']->getDescription());

        // U-5: the served name is the Link's parameter name when it has one (OpenApiFactory names it by key; T-1 sees both).
        $u5 = self::decorated('/things/{thingId}', ['id' => new Link(parameterName: 'thingId')]);
        $expect('U-5 parameterName', 'The thing\'s id.', $u5['id']->getDescription());

        // U-6: the singular rule and the listed word.
        foreach (['warehouses' => 'warehouse', 'addresses' => 'address', 'categories' => 'category', 'goods-receipts' => 'goods receipt',
            'role-grant-proposals' => 'role grant proposal', 'items' => 'item', 'batches' => 'batch', 'taxes' => 'tax',
            'products' => 'catalogue entry', 'pick-list' => null] as $segment => $noun) {
            $expect('U-6 noun('.$segment.')', $noun, PathParameterDescriptions::noun($segment));
        }

        // U-7: schema and constraints are kept, and the two decorators commute.
        $length = [new Length(max: 36)];
        $withSchema = ['type' => 'string', 'format' => 'uuid'];
        $u7 = self::decorated('/fakes/{id}', ['id' => new Link(schema: ['type' => 'string'], constraints: $length)]);
        $expect('U-7 schema kept', ['type' => 'string'], $u7['id']->getSchema());
        $expect('U-7 constraints kept', $length, $u7['id']->getConstraints());
        $stub = self::stubFactory('/fakes/{id}', ['id' => new Link()]);
        $descriptionsThenUuid = self::uriVariablesOf((new UuidPathParameters(new PathParameterDescriptions($stub)))->create(self::MODULE))['id'];
        $uuidThenDescriptions = self::uriVariablesOf((new PathParameterDescriptions(new UuidPathParameters($stub)))->create(self::MODULE))['id'];
        $expect('U-7 schema, descriptions then uuid', $withSchema, $descriptionsThenUuid->getSchema());
        $expect('U-7 schema, uuid then descriptions', $withSchema, $uuidThenDescriptions->getSchema());
        $expect('U-7 description, descriptions then uuid', 'The fake\'s id.', $descriptionsThenUuid->getDescription());
        $expect('U-7 description, uuid then descriptions', 'The fake\'s id.', $uuidThenDescriptions->getDescription());

        // U-8: the decorator's words and T-1's own copy are one list.
        $decorator = PathParameterDescriptions::WORD_FOR_SEGMENT;
        $test = array_map(static fn (array $e): string => $e[0], OpenApiContractTest::RECORD_WORDS_BY_UX);
        ksort($decorator);
        ksort($test);
        if ($decorator !== $test) {
            $failures[] = 'The decorator\'s words and T-1\'s RECORD_WORDS_BY_UX differ: change both, with UX\'s reason. Decorator '
                .json_encode($decorator).', T-1 '.json_encode($test);
        }

        self::assertSame([], $failures, "PathParameterDescriptions breaks its rule:\n".implode("\n", $failures));
    }

    /**
     * @param array<string, Link> $uriVariables
     *
     * @return array<string, Link>
     */
    private static function decorated(string $template, array $uriVariables): array
    {
        return self::uriVariablesOf((new PathParameterDescriptions(self::stubFactory($template, $uriVariables)))->create(self::MODULE));
    }

    /** @param array<string, Link> $uriVariables */
    private static function stubFactory(string $template, array $uriVariables): ResourceMetadataCollectionFactoryInterface
    {
        return new class($template, $uriVariables) implements ResourceMetadataCollectionFactoryInterface {
            /** @param array<string, Link> $uriVariables */
            public function __construct(private readonly string $template, private readonly array $uriVariables)
            {
            }

            public function create(string $resourceClass): ResourceMetadataCollection
            {
                return new ResourceMetadataCollection($resourceClass, [
                    new ApiResource(class: $resourceClass, operations: [
                        'GetFake' => new Get(name: 'GetFake', uriTemplate: $this->template, uriVariables: $this->uriVariables),
                    ]),
                ]);
            }
        };
    }

    /** @return array<string, Link> the uri variables of the one operation in the collection */
    private static function uriVariablesOf(ResourceMetadataCollection $collection): array
    {
        $found = [];
        foreach ($collection as $resource) {
            foreach ($resource->getOperations() ?? [] as $operation) {
                \assert($operation instanceof HttpOperation);
                $found[] = $operation->getUriVariables() ?? [];
            }
        }
        self::assertCount(1, $found, 'The stub collection should hold exactly one operation.');

        return $found[0];
    }
}
