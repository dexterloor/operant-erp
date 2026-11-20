<?php

declare(strict_types=1);

namespace App\Platform\Http;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\OpenApi;

/**
 * Every operation in the published spec says what it does, instead of the framework's default text (2b, S2).
 *
 * Until this, every summary was API Platform's "Creates a X resource." (keyed on the HTTP method alone, so a restore, an
 * approval or a sign-in "created" something), every description was that summary copied, and every request body was
 * "The new X resource". This writes:
 *
 * - **summary**: the operation's own `openapi` attribute summary, else its name in words (`ListCustomers` → "List
 *   customers"), so the heading and the name a client codes against never disagree;
 * - **description**: the attribute's own, else the one sentence of {@see self::TEMPLATES} the operation's facts select
 *   ({@see self::templateOf()}): its verb, whether it pages (an `after` parameter), whether its path names a record,
 *   whether it answers 201, and for a `Delete…` whether a `Restore…` of the same thing exists (wording owner: `UX`);
 * - **request body**: the attribute's own, else {@see self::PATCH_BODY} on a `PATCH`, `The {object} to create.` (or
 *   `to issue.`) on a create that answers 201, and no description on any other body (the schema says what it holds).
 *
 * An empty own text counts as none. An operation that has no own description and that no template is true of fails
 * the build, naming the operation ({@see ProblemResponses}' rule for success texts): the docs endpoint would answer 500,
 * and `make contracts` and every spec test fail before a commit can carry it.
 *
 * `PublishedSpecTextTest` holds the published texts to its own copy of the selectors and templates, on purpose: a
 * change to this class alone is red there.
 *
 * **No runtime effect.** This decorates the OpenAPI factory only; nothing that answers a request reads it.
 */
final readonly class OperationTexts implements OpenApiFactoryInterface
{
    /**
     * One sentence per selected kind (wording owner: `UX`). `{object}` is the operation's name less its verb, in words;
     * `{Object}` the same in the operation name's own case (`RestoreCustomer`'s "Customer").
     *
     * @var array<string, string>
     */
    public const TEMPLATES = [
        'list paged' => 'Lists {object}, one page at a time.',
        'list' => 'Lists {object}.',
        'get one' => 'Returns one {object}.',
        'get the' => 'Returns the {object}.',
        'create' => 'Creates one {object}.',
        'issue' => 'Issues one {object}.',
        'update one' => 'Changes one {object}.',
        'update the' => 'Changes the {object}.',
        'archive' => 'Archives one {object}. `Restore{Object}` brings it back.',
        'restore' => 'Restores one archived {object}.',
        'act' => '{Verbs} one {object}.',
    ];

    /** The verbs the `act` template conjugates, with their third-person form. */
    public const ACTIONS = ['Approve' => 'Approves', 'Cancel' => 'Cancels', 'Confirm' => 'Confirms', 'Reject' => 'Rejects', 'Void' => 'Voids', 'Reverse' => 'Reverses'];

    public const PATCH_BODY = 'Only the fields to change. A field left out is not changed.';

    /** The create body, by verb (`{object}` as in {@see self::TEMPLATES}). */
    public const CREATE_BODIES = ['Create' => 'The {object} to create.', 'Issue' => 'The {object} to issue.'];

    private const METHODS = ['Get', 'Post', 'Patch', 'Put', 'Delete'];

    public function __construct(
        private OpenApiFactoryInterface $inner,
        private ResourceNameCollectionFactoryInterface $resourceNames,
        private ResourceMetadataCollectionFactoryInterface $resourceMetadata,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->inner)($context);
        $operations = $this->operationsByName();
        $names = array_keys($operations);

        $paths = new Paths();
        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            $paths->addPath($path, $this->withTexts($pathItem, $operations, $names));
        }

        return $openApi->withPaths($paths);
    }

    /**
     * The template an operation's facts select, or null when none is true of it.
     *
     * @param list<string> $names every operation name in the application
     */
    public static function templateOf(string $name, bool $pages, bool $namesARecord, bool $answers201, array $names): ?string
    {
        [$verb, $object] = self::split($name);

        return match (true) {
            'List' === $verb => $pages ? 'list paged' : 'list',
            'Get' === $verb => $namesARecord ? 'get one' : 'get the',
            'Create' === $verb && $answers201 => 'create',
            'Issue' === $verb && $answers201 => 'issue',
            'Update' === $verb => $namesARecord ? 'update one' : 'update the',
            'Delete' === $verb && \in_array('Restore'.$object, $names, true) => 'archive',
            'Restore' === $verb => 'restore',
            isset(self::ACTIONS[$verb]) => 'act',
            default => null,
        };
    }

    /** A template with the operation's name filled in. */
    public static function fill(string $template, string $name): string
    {
        [$verb, $object] = self::split($name);

        return strtr($template, ['{object}' => self::words($object), '{Object}' => $object, '{Verbs}' => self::ACTIONS[$verb] ?? $verb]);
    }

    /** An operation name in words: `CreatePurchaseOrderLine` → "Create purchase order line". */
    public static function summaryOf(string $name): string
    {
        return ucfirst(self::words($name));
    }

    /** `PickQueueEntries` → "pick queue entries". */
    public static function words(string $camel): string
    {
        return strtolower(trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $camel)));
    }

    /** @return array{0: string, 1: string} the leading verb and the rest */
    private static function split(string $name): array
    {
        return 1 === preg_match('/^([A-Z][a-z]+)(.*)\z/', $name, $m) ? [$m[1], $m[2]] : [$name, ''];
    }

    /**
     * @param array<string, HttpOperation> $operations
     * @param list<string>                 $names
     */
    private function withTexts(PathItem $pathItem, array $operations, array $names): PathItem
    {
        foreach (self::METHODS as $method) {
            $openapiOperation = $pathItem->{'get'.$method}();
            if (!$openapiOperation instanceof OpenApiOperation) {
                continue;
            }
            $name = (string) $openapiOperation->getOperationId();
            $operation = $operations[$name] ?? null;
            if (null === $operation) {
                continue;
            }
            $own = $operation->getOpenapi() instanceof OpenApiOperation ? $operation->getOpenapi() : null;

            $pages = false;
            foreach ($openapiOperation->getParameters() ?? [] as $parameter) {
                $pages = $pages || ($parameter instanceof Parameter && 'after' === $parameter->getName() && 'query' === $parameter->getIn());
            }
            $answers201 = \array_key_exists('201', (array) $openapiOperation->getResponses()) || \array_key_exists(201, (array) $openapiOperation->getResponses());
            $namesARecord = str_contains((string) $operation->getUriTemplate(), '{');

            $summary = self::nonEmpty($own?->getSummary()) ?? self::summaryOf($name);
            $description = self::nonEmpty($own?->getDescription());
            if (null === $description) {
                $template = self::templateOf($name, $pages, $namesARecord, $answers201, $names)
                    ?? throw new \LogicException(\sprintf('Operation "%s" has no description of its own and no template is true of it: give it a summary and a description on its own openapi attribute.', $name));
                $description = self::fill(self::TEMPLATES[$template], $name);
            }
            $openapiOperation = $openapiOperation->withSummary($summary)->withDescription($description);

            $body = $openapiOperation->getRequestBody();
            if ($body instanceof RequestBody) {
                $text = self::nonEmpty($own?->getRequestBody()?->getDescription()) ?? self::bodyText($name, (string) $operation->getMethod(), $answers201);
                // A new body, not `withDescription()`: that takes no null, and an empty string would be published.
                $openapiOperation = $openapiOperation->withRequestBody(new RequestBody($text, $body->getContent(), $body->getRequired()));
            }

            $pathItem = $pathItem->{'with'.$method}($openapiOperation);
        }

        return $pathItem;
    }

    private static function bodyText(string $name, string $method, bool $answers201): ?string
    {
        if ('PATCH' === $method) {
            return self::PATCH_BODY;
        }
        [$verb] = self::split($name);
        if ('POST' === $method && $answers201 && isset(self::CREATE_BODIES[$verb])) {
            return self::fill(self::CREATE_BODIES[$verb], $name);
        }

        return null;
    }

    private static function nonEmpty(?string $text): ?string
    {
        return null === $text || '' === trim($text) ? null : $text;
    }

    /**
     * Every application operation, keyed by its name (the spec's `operationId`).
     *
     * @return array<string, HttpOperation>
     */
    private function operationsByName(): array
    {
        $operations = [];
        foreach ($this->resourceNames->create() as $resourceClass) {
            if (!str_starts_with($resourceClass, 'App\\')) {
                continue;
            }
            foreach ($this->resourceMetadata->create($resourceClass) as $resource) {
                foreach ($resource->getOperations() ?? [] as $operationName => $operation) {
                    if ($operation instanceof HttpOperation && !str_starts_with((string) $operationName, '_api_')) {
                        $operations[(string) $operationName] = $operation;
                    }
                }
            }
        }

        return $operations;
    }
}
