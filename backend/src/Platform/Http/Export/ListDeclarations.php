<?php

declare(strict_types=1);

namespace App\Platform\Http\Export;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Parameter;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Platform\Security\PermissionRequirement;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * **What the List an {@see ExportSource} names declares, as plain values (G6-C0; `g6-c0-red.md` M-2 (a), M-3).**
 *
 * The export's permission, its `filters` members, its `sort` values and the validation of each filter value are all READ
 * off the List operation, never kept beside it. This port is the one place that reads API Platform's metadata for them, so
 * the module that runs the export (`AuditLog\Application`) consumes plain values and depends on Platform contracts only
 * (A-7), never on the framework.
 *
 * Contract (a violation is a LogicException — a wiring fault, never a request's): the named operation exists, is a GET,
 * is named `List*`, and declares that name as its operationId (§12.3).
 *
 * **Validation is the List's own** (M-3): each declared member is validated with the parameter's OWN constraints
 * (`Parameter::getConstraints()`, as API Platform's `ParameterValidationResourceMetadataCollectionFactory` set them —
 * derived from the schema, plus any declared `constraints:`), an ABSENT member as `null` (so a `required` one is refused),
 * and nothing at all when the List switches query-parameter validation off — exactly `ParameterValidatorProvider`'s loop.
 */
final readonly class ListDeclarations
{
    /** Query parameters a List declares that page or order it, never a filter (API-14a). */
    public const NOT_FILTERS = ['limit', 'after', 'before', 'sort'];

    public const SORT = 'sort';

    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $resources,
        private ValidatorInterface $validator,
    ) {
    }

    public function operationId(ExportSource $source): string
    {
        $this->operation($source);

        return $source->listOperationName();
    }

    /** The List's path, without the format suffix. */
    public function path(ExportSource $source): string
    {
        return str_replace('{._format}', '', (string) $this->operation($source)->getUriTemplate());
    }

    public function requirement(ExportSource $source): PermissionRequirement
    {
        return PermissionRequirement::of($this->operation($source));
    }

    /**
     * The members `filters` may carry: the List's declared query parameters minus paging and ordering, byte-sorted.
     *
     * @return array<string, array<string, mixed>> name => its declared schema
     */
    public function filterMembers(ExportSource $source): array
    {
        $members = [];
        foreach ($this->filterParameters($source) as $name => $parameter) {
            $members[$name] = $parameter->getSchema() ?? [];
        }

        return $members;
    }

    /**
     * The List's `sort` values, or `[]` when it declares no `sort` (it serves one order).
     *
     * @return list<string>
     */
    public function sortValues(ExportSource $source): array
    {
        foreach ($this->operation($source)->getParameters() ?? [] as $key => $parameter) {
            if (self::SORT === (string) ($parameter->getKey() ?? $key)) {
                return array_values(array_map('strval', $parameter->getSchema()['enum'] ?? []));
            }
        }

        return [];
    }

    /**
     * The List's own parameter validation over `$filters` (absent members as `null`), as `[name, message]` pairs.
     *
     * @param array<string, string> $filters
     *
     * @return list<array{0: string, 1: string}>
     */
    public function violations(ExportSource $source, array $filters): array
    {
        if (!($this->operation($source)->getQueryParameterValidationEnabled() ?? true)) {
            return [];
        }

        $found = [];
        foreach ($this->filterParameters($source) as $name => $parameter) {
            $constraints = $parameter->getConstraints();
            if (!$constraints) {
                continue;
            }
            foreach ($this->validator->validate($filters[$name] ?? null, $constraints) as $violation) {
                $found[] = [$name, (string) $violation->getMessage()];
            }
        }

        return $found;
    }

    /** A filter the trail may not record the text of: a string with no enum and no format (design L-5; E-17). */
    public static function isFreeText(array $schema): bool
    {
        return 'string' === ($schema['type'] ?? null) && !isset($schema['enum']) && !isset($schema['format']);
    }

    /** @return array<string, Parameter> */
    private function filterParameters(ExportSource $source): array
    {
        $parameters = [];
        foreach ($this->operation($source)->getParameters() ?? [] as $key => $parameter) {
            $name = (string) ($parameter->getKey() ?? $key);
            if (!\in_array($name, self::NOT_FILTERS, true)) {
                $parameters[$name] = $parameter;
            }
        }
        ksort($parameters, \SORT_STRING);

        return $parameters;
    }

    private function operation(ExportSource $source): HttpOperation
    {
        $name = $source->listOperationName();
        $operation = $this->resources->create($source->listResourceClass())->getOperation($name);
        $openapi = $operation instanceof HttpOperation ? $operation->getOpenapi() : null;

        if (!$operation instanceof HttpOperation
            || 'GET' !== $operation->getMethod()
            || !str_starts_with($name, 'List')
            || !$openapi instanceof \ApiPlatform\OpenApi\Model\Operation
            || $name !== $openapi->getOperationId()
        ) {
            throw new \LogicException(\sprintf('The export source for "%s" names "%s", which is not a GET List* operation declaring that operationId.', $source->resource(), $name));
        }

        return $operation;
    }
}
