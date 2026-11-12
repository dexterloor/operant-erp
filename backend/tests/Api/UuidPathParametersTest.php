<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\UuidPathParameters;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Uuid;

/**
 * BK-18 R18-d, the metadata half: every uri variable of every `App\Modules\` operation is a Link whose schema is
 * `{type: string, format: uuid}` (set by {@see UuidPathParameters}), and none carries constraints or a default.
 *
 * The spec half is {@see OpenApiContractTest::everyRecordIdPathParameterIsServedAsAUuid}. This half holds the claim the
 * spec cannot show: that publishing `format: uuid` changes nothing on the wire. API Platform validates a Link only when
 * its `getConstraints()` is non-null (`ParameterValidatorProvider.php:59-71`) and reads only a schema's `default`
 * (`ParameterProvider.php:131`), so a Link with neither still lets a malformed id reach the reader, which answers it with
 * the read's 404 or an earlier refusal -- exactly as before the schema was set.
 *
 * The unit control builds the decorator over a stub factory, so its guard (a Link with its own schema is left alone) and
 * its scope (`App\Modules\` only) are tested by a red, not reviewed.
 *
 * Kernel only; no database.
 */
final class UuidPathParametersTest extends KernelTestCase
{
    private const UUID = ['type' => 'string', 'format' => 'uuid'];

    private const NO_CONSTRAINT_MESSAGE = 'a uri variable carrying constraints or a default would change what a malformed id answers (today: the read\'s 404 or an earlier refusal); BK-18 R18-d §3';

    #[Test]
    public function everyUriVariableIsAUuidAndCarriesNoConstraintOrDefault(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $names = $container->get(ResourceNameCollectionFactoryInterface::class);
        $collections = $container->get(ResourceMetadataCollectionFactoryInterface::class);
        \assert($names instanceof ResourceNameCollectionFactoryInterface);
        \assert($collections instanceof ResourceMetadataCollectionFactoryInterface);

        // The real container: every uri variable of every Module operation.
        $links = [];
        $failures = [];
        foreach ($names->create() as $resourceClass) {
            if (!str_starts_with($resourceClass, 'App\\Modules\\')) {
                continue;
            }
            foreach ($collections->create($resourceClass) as $resource) {
                foreach ($resource->getOperations() ?? [] as $operationName => $operation) {
                    if (!$operation instanceof HttpOperation) {
                        continue;
                    }
                    foreach ($operation->getUriVariables() ?? [] as $variable => $link) {
                        $key = $operationName.'.'.$variable;
                        if (!$link instanceof Link) {
                            $failures[] = $key.' is not a Link: '.get_debug_type($link);
                            continue;
                        }
                        $links[$key] = $link;
                    }
                }
            }
        }
        self::assertNotSame([], $links, 'Anti-vacuity: no App\\Modules\\ operation has a uri variable.');
        foreach ($links as $key => $link) {
            array_push($failures, ...self::problems($key, $link));
        }

        // The error resource is out of scope: its `status` is a status code, not a record id. Its subject is asserted
        // first, so the null-schema check cannot pass over zero Links.
        $status = null;
        foreach ($collections->create(ApiProblem::class) as $resource) {
            foreach ($resource->getOperations() ?? [] as $operationName => $operation) {
                if ('_api_problem' === $operationName && $operation instanceof HttpOperation) {
                    $status = $operation->getUriVariables()['status'] ?? null;
                }
            }
        }
        self::assertInstanceOf(Link::class, $status, 'ApiProblem\'s _api_problem operation has no `status` Link, so the scope check has no subject.');
        if (null !== $status->getSchema()) {
            $failures[] = 'ApiProblem\'s `status` gained a schema: the decorator reached outside App\\Modules\\.';
        }

        // Unit control: the decorator's guard and scope, over a stub factory.
        $length = [new Length(max: 36)];
        $a = new Link();
        $b = new Link(schema: ['type' => 'string']);
        $c = new Link(constraints: $length);
        $platform = new Link();
        $decorator = new UuidPathParameters(self::stubFactory([
            'App\\Modules\\Fake\\FakeResource' => ['a' => $a, 'b' => $b, 'c' => $c],
            'App\\Platform\\Fake\\FakeResource' => ['p' => $platform],
        ]));
        $module = self::uriVariablesOf($decorator->create('App\\Modules\\Fake\\FakeResource'));
        $outside = self::uriVariablesOf($decorator->create('App\\Platform\\Fake\\FakeResource'));
        foreach (['a', 'b', 'c'] as $name) {
            self::assertInstanceOf(Link::class, $module[$name] ?? null, 'unit: the decorator dropped uri variable '.$name.'.');
        }
        self::assertInstanceOf(Link::class, $outside['p'] ?? null, 'unit: the decorator dropped the Platform uri variable.');
        if (self::UUID !== $module['a']->getSchema()) {
            $failures[] = 'unit a: a schema-less Link did not gain exactly the uuid schema: '.json_encode($module['a']->getSchema());
        }
        if (null !== $module['a']->getConstraints()) {
            $failures[] = 'unit a: the decorator gave a Link constraints.';
        }
        if (\array_key_exists('default', $module['a']->getSchema() ?? [])) {
            $failures[] = 'unit a: the decorator gave a Link a default.';
        }
        if ($b !== $module['b']) {
            $failures[] = 'unit b: a Link that declares its own schema was changed: '.json_encode($module['b']->getSchema());
        }
        if ($length !== $module['c']->getConstraints()) {
            $failures[] = 'unit c: the decorator replaced a Link\'s own constraints.';
        }
        if (\array_key_exists('default', $module['c']->getSchema() ?? [])) {
            $failures[] = 'unit c: the decorator gave a Link a default.';
        }
        if ($platform !== $outside['p']) {
            $failures[] = 'unit p: the decorator reached a resource outside App\\Modules\\: '.json_encode($outside['p']->getSchema());
        }

        // One assertion over every check, so a regression is reported in full rather than at its first symptom.
        self::assertSame([], $failures, \count($links)." uri variables derived; the decorator or a Link breaks the rule:\n".implode("\n", $failures));

        // Positive control: a constraints predicate that cannot fail cannot pass.
        $firstKey = array_key_first($links);
        self::assertSame([$firstKey.': '.self::NO_CONSTRAINT_MESSAGE], self::problems($firstKey, $links[$firstKey]->withConstraints(new Uuid())),
            'The positive control: a uri variable given a Uuid constraint was not reported.');
    }

    /** @return list<string> */
    private static function problems(string $key, Link $link): array
    {
        $problems = [];
        if (self::UUID !== $link->getSchema() && !\array_key_exists($key, OpenApiContractTest::PATH_PARAMETERS_THAT_ARE_NOT_UUIDS)) {
            $problems[] = $key.' has schema '.json_encode($link->getSchema()).', not {type: string, format: uuid}';
        }
        if (null !== $link->getConstraints() || \array_key_exists('default', $link->getSchema() ?? [])) {
            $problems[] = $key.': '.self::NO_CONSTRAINT_MESSAGE;
        }

        return $problems;
    }

    /** @param array<string, array<string, Link>> $uriVariablesByClass */
    private static function stubFactory(array $uriVariablesByClass): ResourceMetadataCollectionFactoryInterface
    {
        return new class($uriVariablesByClass) implements ResourceMetadataCollectionFactoryInterface {
            /** @param array<string, array<string, Link>> $uriVariablesByClass */
            public function __construct(private readonly array $uriVariablesByClass)
            {
            }

            public function create(string $resourceClass): ResourceMetadataCollection
            {
                return new ResourceMetadataCollection($resourceClass, [
                    new ApiResource(class: $resourceClass, operations: [
                        'GetFake' => new Get(name: 'GetFake', uriTemplate: '/fakes/{a}', uriVariables: $this->uriVariablesByClass[$resourceClass]),
                    ]),
                ]);
            }
        };
    }

    /** @return array<string, mixed> the uri variables of the one operation in the collection */
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
