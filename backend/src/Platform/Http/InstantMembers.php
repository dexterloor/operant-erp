<?php

declare(strict_types=1);

namespace App\Platform\Http;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;

/**
 * Every instant in a request or response body says it is one in the published spec (BK-18 R18-f + R18-g).
 *
 * The rule: a public, non-static property of a class under `App\` that carries {@see Instant}, and whose schema has a
 * `type` and no `format`, gains `format: date-time`; when the marker says `inRequest: true` and the schema has no
 * `description`, it also gains {@see Rfc3339Timestamp::PROFILE}. Anything else is returned untouched, and it never
 * throws: a missing schema is read as empty, and a schema with no `type` is left alone (a `format` alone would publish a
 * member with no type, because the json-schema factory stops at either key).
 *
 * ---------------------------------------------------------------------------
 * Why a marker and not the name
 * ---------------------------------------------------------------------------
 * PHP types say nothing (every instant is a `string`) and the `_at` name is a convention, not a fact. The marker is placed
 * where a reader formats or a writer parses an instant; `OpenApiContractTest::everyDateAndTimeIsDeclaredAsOne` checks the
 * name only as a backstop, and the truth is held by `RequestBodySchemaProbeTest` probe 4 (the format ⇔ `not_a_timestamp`)
 * and `ResponseShapeTest` (every served instant parses and keeps its shape; every instant-shaped value is declared).
 * `InstantMembersTest` holds the placement: a request marker sits on an input class, a response marker on a served
 * resource.
 *
 * ---------------------------------------------------------------------------
 * Order
 * ---------------------------------------------------------------------------
 * Priority 1, like {@see AlwaysPresentMembers}: outside the json-schema property factory (10), which builds the `type`
 * this reads, and inside the cache (-10). The two commute: that one sets `required`, this one only the schema.
 * `ListDeclarations::isFreeText()` reads `format` from **parameter** schemas; this decorator touches no parameter, so the
 * export trail is unchanged (`ListSalesReturns?decided_since` is served without a format on purpose).
 */
final class InstantMembers implements PropertyMetadataFactoryInterface
{
    public function __construct(private readonly PropertyMetadataFactoryInterface $inner)
    {
    }

    public function create(string $resourceClass, string $property, array $options = []): ApiProperty
    {
        $metadata = $this->inner->create($resourceClass, $property, $options);
        if (!str_starts_with($resourceClass, 'App\\') || !property_exists($resourceClass, $property)) {
            return $metadata;
        }
        $reflection = new \ReflectionProperty($resourceClass, $property);
        $markers = $reflection->getAttributes(Instant::class);
        if ([] === $markers || !$reflection->isPublic() || $reflection->isStatic()) {
            return $metadata;
        }
        $schema = $metadata->getSchema() ?? [];
        if (!isset($schema['type']) || isset($schema['format'])) {
            return $metadata;
        }
        $schema['format'] = Instant::FORMAT;
        if ($markers[0]->newInstance()->inRequest && !isset($schema['description'])) {
            $schema['description'] = Rfc3339Timestamp::PROFILE;
        }

        return $metadata->withSchema($schema);
    }
}
