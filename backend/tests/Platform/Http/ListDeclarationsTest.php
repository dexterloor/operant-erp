<?php

declare(strict_types=1);

namespace App\Tests\Platform\Http;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;
use App\Platform\Http\Export\ListDeclarations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Validation;

/**
 * **`CreateExport` validates each filter with the List's OWN parameter constraints (G6-C0; `g6-c0-red.md` M-3).**
 *
 * No Inventory List declares an explicit `constraints:` or a `required` parameter today, so the HTTP tests cannot tell
 * "the List's constraints" from "constraints recomputed from the schema". This planted List can: one parameter carries an
 * explicit `Length(max: 3)` (lost by a recomputation from the schema), one a `NotNull` (fires only if an ABSENT member is
 * validated as `null`, as `ParameterValidatorProvider` does), and a second List switches query-parameter validation off.
 * *Mutations:* validate only the members present → the `NotNull` case goes green-when-it-should-refuse → red; recompute
 * from the schema → the `Length` case passes → red; ignore the enabled flag → the switched-off List refuses → red.
 */
final class ListDeclarationsTest extends TestCase
{
    #[Test]
    public function aDeclaredConstraintAndAnAbsentRequiredMemberAreTheListsRefusals(): void
    {
        $declarations = self::declarations(validationEnabled: true);

        self::assertSame([], $declarations->violations(self::source(), ['code' => 'abc', 'needed' => 'x']));

        $found = $declarations->violations(self::source(), ['code' => 'abcd']);
        self::assertSame(['code', 'needed'], array_column($found, 0), 'The explicit Length and the absent NotNull are both the List\'s own refusals.');
    }

    #[Test]
    public function aListThatSwitchesValidationOffValidatesNothing(): void
    {
        self::assertSame([], self::declarations(validationEnabled: false)->violations(self::source(), ['code' => 'abcd']));
    }

    #[Test]
    public function pagingAndOrderingAreNeverFilterMembers(): void
    {
        self::assertSame(['code', 'needed'], array_keys(self::declarations(validationEnabled: true)->filterMembers(self::source())));
    }

    private static function declarations(bool $validationEnabled): ListDeclarations
    {
        $operation = new GetCollection(
            uriTemplate: '/planted',
            name: 'ListPlanted',
            openapi: new OpenApiOperation(operationId: 'ListPlanted'),
            parameters: [
                'needed' => new QueryParameter(key: 'needed', schema: ['type' => 'string'], constraints: [new NotNull()]),
                'code' => new QueryParameter(key: 'code', schema: ['type' => 'string'], constraints: [new Length(max: 3)]),
                'limit' => new QueryParameter(key: 'limit', schema: ['type' => 'integer'], constraints: [new NotNull()]),
                'sort' => new QueryParameter(key: 'sort', schema: ['type' => 'string', 'enum' => ['code']], constraints: [new NotNull()]),
            ],
            queryParameterValidationEnabled: $validationEnabled,
        );
        $collection = new ResourceMetadataCollection(\stdClass::class, [
            new ApiResource(class: \stdClass::class, operations: ['ListPlanted' => $operation]),
        ]);
        $factory = new class($collection) implements ResourceMetadataCollectionFactoryInterface {
            public function __construct(private readonly ResourceMetadataCollection $collection)
            {
            }

            public function create(string $resourceClass): ResourceMetadataCollection
            {
                return $this->collection;
            }
        };

        return new ListDeclarations($factory, Validation::createValidator());
    }

    private static function source(): ExportSource
    {
        return new class implements ExportSource {
            public function resource(): string
            {
                return 'planted';
            }

            public function listResourceClass(): string
            {
                return \stdClass::class;
            }

            public function listOperationName(): string
            {
                return 'ListPlanted';
            }

            public function rowClass(): string
            {
                return \stdClass::class;
            }

            public function prepare(array $filters, int $cap): \Closure
            {
                return static fn (): ExportedRows => ExportedRows::capped([], $cap);
            }
        };
    }
}
