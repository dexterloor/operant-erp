<?php

declare(strict_types=1);

namespace App\Platform\Http;

use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;

/**
 * Every record id in a path is a uuid (API.md D-12), and the published spec says so.
 *
 * Without this, each path parameter (`{id}`, `{lineId}`, `{roleId}` …) is served as
 * `{type: string}`: `OpenApiFactory.php:309` falls back to that when a uri variable's
 * Link declares no schema, and no Link in `src/` declares one. This decorator gives
 * every uri variable of every operation of every resource under `App\Modules\` the
 * schema `{type: string, format: uuid}` when its Link declares none, so the rule is
 * stated once and covers the next operation on the day it is declared.
 *
 * ---------------------------------------------------------------------------
 * Why a malformed id still answers exactly as before
 * ---------------------------------------------------------------------------
 * A Link's schema is read in two places in API Platform: `OpenApiFactory.php:309`
 * (the spec) and `ParameterProvider.php:131` (only its `default` key, which this
 * schema does not carry). Validation constraints are derived from a schema only for
 * an operation's query/header parameters
 * (`ParameterValidationResourceMetadataCollectionFactory.php:51-54`), never for uri
 * variables, and `ParameterValidatorProvider.php:59-71` checks a Link only when its
 * `getConstraints()` is non-null -- which this class never sets. So a non-uuid id
 * still reaches the reader and gets the read's 404 (or the earlier 401/403 or owner
 * refusal), as it did before. `UuidPathParametersTest` holds that no uri variable
 * carries constraints or a default.
 *
 * Route `requirements` were rejected: the router would refuse a malformed id before
 * the firewall runs, turning "sign in" and "not allowed" into "not found".
 *
 * ---------------------------------------------------------------------------
 * Exceptions
 * ---------------------------------------------------------------------------
 * A Link that declares its own `schema:` is left untouched. Such a Link is an
 * exception to the rule and must be listed, with its reason, in
 * `OpenApiContractTest::PATH_PARAMETERS_THAT_ARE_NOT_UUIDS`; the test fails both ways
 * otherwise. Resources outside `App\Modules\` (the error resource's `status`) are
 * returned unchanged.
 */
final readonly class UuidPathParameters implements ResourceMetadataCollectionFactoryInterface
{
    private const UUID = ['type' => 'string', 'format' => 'uuid'];

    private const SCOPE = 'App\\Modules\\';

    public function __construct(private ResourceMetadataCollectionFactoryInterface $inner)
    {
    }

    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->inner->create($resourceClass);
        if (!str_starts_with($resourceClass, self::SCOPE)) {
            return $collection;
        }

        foreach ($collection as $i => $resource) {
            if (null === $resource->getOperations()) {
                continue;
            }
            // Never mutate the inner Operations in place: build a new one, as the
            // vendor's UriTemplateResourceMetadataCollectionFactory does.
            $operations = new Operations();
            foreach ($resource->getOperations() as $name => $operation) {
                $uriVariables = $operation->getUriVariables();
                if (null !== $uriVariables && [] !== $uriVariables) {
                    $operation = $operation->withUriVariables(array_map(
                        static fn (mixed $link): mixed => $link instanceof Link && null === $link->getSchema()
                            ? $link->withSchema(self::UUID)
                            : $link,
                        $uriVariables,
                    ));
                }
                $operations->add($name, $operation);
            }
            $collection[$i] = $resource->withOperations($operations);
        }

        return $collection;
    }
}
