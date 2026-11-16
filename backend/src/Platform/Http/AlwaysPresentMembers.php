<?php

declare(strict_types=1);

namespace App\Platform\Http;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ErrorResource;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * Every member of a served resource is always present, and the published spec says so (BK-18 R18-c).
 *
 * The rule: a served member of a resource is `required` exactly when it is not both nullable and dropped when null; its
 * type admits `null` exactly when it is nullable and not dropped. Nulls are serialized by default
 * (`config/packages/api_platform.yaml:57-66`, `skip_null_values: false`), so only a member whose own `#[Context]` sets
 * `SKIP_NULL_VALUES` can be absent. This decorator sets `required` only: **a member dropped when null must also declare its
 * non-null type by hand** (`#[ApiProperty(schema: […])]`), or the spec says it may be `null` when it is never sent as one.
 *
 * ---------------------------------------------------------------------------
 * Why it computes and never merges
 * ---------------------------------------------------------------------------
 * By the time this runs (priority 1, outside the json-schema factory at 10 and inside the cache at -10), the validator
 * factory at priority 20 has already defaulted `required` to `false` (`ValidatorPropertyMetadataFactory.php:136`), so a
 * declared `false` cannot be told from the default. A hand `required:` on a served resource is therefore dead metadata;
 * `OpenApiContractTest::noServedResourceDeclaresRequiredByHand` forbids it.
 *
 * ---------------------------------------------------------------------------
 * Scope
 * ---------------------------------------------------------------------------
 * `isServedResource()`: a class under `App\` carrying exactly `#[ApiResource]` (no `IS_INSTANCEOF`) and no
 * `#[ErrorResource]` — the same domain as `OpenApiContractTest::apiResourceClasses()`, which
 * `AlwaysPresentMembersTest` checks it against. `ApiProblem` and API Platform's own `Error` are out: their nulls are
 * skipped by an operation-level context this class does not read (`ApiProblem.php:42-45`). Input classes carry no
 * `#[ApiResource]` and keep their per-property `required` (R18-b). Nested reference and view classes declare `required`
 * by hand, held by `OpenApiContractTest`'s walk.
 */
final class AlwaysPresentMembers implements PropertyMetadataFactoryInterface
{
    /**
     * `api_platform.yaml:66`. Flip it with that line; `AlwaysPresentMembersTest` fails until you do. Absent from the
     * context, API Platform skips nulls (`SerializerContextBuilder.php:62`); `api_platform.yaml:66` is what makes it
     * `false`.
     */
    public const DEFAULT_SKIPS_NULL = false;

    /** @var array<string, bool> */
    private static array $served = [];

    public function __construct(private readonly PropertyMetadataFactoryInterface $inner)
    {
    }

    public function create(string $resourceClass, string $property, array $options = []): ApiProperty
    {
        $metadata = $this->inner->create($resourceClass, $property, $options);
        if (!self::isServedResource($resourceClass) || !property_exists($resourceClass, $property)) {
            return $metadata;
        }
        $reflection = new \ReflectionProperty($resourceClass, $property);
        if (!$reflection->isPublic() || $reflection->isStatic()) {
            return $metadata;
        }
        $nullable = $reflection->getType()?->allowsNull() ?? true;

        return $metadata->withRequired(!($nullable && self::skipsNull($reflection)));
    }

    public static function isServedResource(string $class): bool
    {
        if (isset(self::$served[$class])) {
            return self::$served[$class];
        }
        if (!str_starts_with($class, 'App\\') || !class_exists($class)) {
            return self::$served[$class] = false;
        }
        $reflection = new \ReflectionClass($class);

        return self::$served[$class] = [] !== $reflection->getAttributes(ApiResource::class)
            && [] === $reflection->getAttributes(ErrorResource::class);
    }

    private static function skipsNull(\ReflectionProperty $property): bool
    {
        foreach ($property->getAttributes(Context::class) as $attribute) {
            $context = $attribute->newInstance();
            foreach ([$context->context, $context->normalizationContext] as $values) {
                if (\array_key_exists(AbstractObjectNormalizer::SKIP_NULL_VALUES, $values)) {
                    return (bool) $values[AbstractObjectNormalizer::SKIP_NULL_VALUES];
                }
            }
        }

        return self::DEFAULT_SKIPS_NULL;
    }
}
