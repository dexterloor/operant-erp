<?php

declare(strict_types=1);

namespace App\Platform\Http;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Problem\ProblemType;
use App\Platform\Security\PermissionRequirement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

/**
 * The published spec lists, per operation, the refusal statuses it can really answer, each in the shape the server sends
 * (BK-18 R18-h C1).
 *
 * Until this, every refusal entry was API Platform's default: a `400 "Invalid input"` on every `POST`/`PATCH` (no
 * operation answers 400), a `422` in the vendor's `violations[]` shape (the server sends `errors[]`), and a `404` on every
 * non-`POST` item operation, lists included. None of the 132 operations listed its real set.
 *
 * ---------------------------------------------------------------------------
 * The rule (one fact per status, each already held by the code)
 * ---------------------------------------------------------------------------
 * - **401** — the path is outside `/auth/` (the authentication gate stands in front of every such operation); on an
 *   `/auth/` path only where the operation's own `openapi` attribute declares it.
 * - **403** — the operation has a permission gate: `PermissionRequirement::of()` is not `none`.
 * - **404** — the path names a record (its template has a `{param}`). A list or a singleton has no row to miss.
 * - **409** — the operation writes (`transaction.contention`: any write can lose a lock), unless it carries
 *   {@see self::QUEUED}.
 * - **422** — every operation: an undeclared query parameter is refused on every one, `/auth/` included.
 * - **428** — the operation demands an `Idempotency-Key` ({@see IdempotencyKeyGuard::OPERATION_FLAG}).
 * - Anything else (401 on an `/auth/` operation, 429) — only where the operation's own `openapi` attribute declares it,
 *   read from the metadata, never from the merged spec. Its description is kept; its shape is replaced by `Problem`.
 *
 * 405, 406 and 415 answer how a request was sent, not what it asked, and are stated once in the API's description
 * (`config/packages/api_platform.yaml`), not per operation. 500 is a fault, not a refusal, and is never listed.
 *
 * The runtime cannot read the contract document (it is not shipped), so `ErrorResponsesTest` holds this rule to it in
 * both directions, and the drivers that already provoke each status hold it to behaviour.
 *
 * ---------------------------------------------------------------------------
 * No runtime effect
 * ---------------------------------------------------------------------------
 * This decorates the OpenAPI factory only. The `OpenApi` object it returns is read by the docs endpoint and the
 * contract export; no provider, processor, listener or route reads it. No request is answered differently.
 */
final readonly class ProblemResponses implements OpenApiFactoryInterface
{
    /**
     * The operation extra property saying the request is queued, not performed: it answers 202 for every well-formed
     * input and its request path takes no row lock that could be lost, so it cannot answer the write-wide 409.
     *
     * Today only `CreatePasswordReset`, whose request path issues two single-row autocommit upserts on the rate-limit
     * connection (no `lock_timeout` is set there, and one row lock cannot form a cycle) and hands the rest to the queue.
     * `ResponseStatusTest` holds the set of operations carrying it to its own queued-path list.
     */
    public const QUEUED = 'queued';

    public const SCHEMA = 'Problem';

    public const FIELD_ERROR_SCHEMA = 'ProblemFieldError';

    public const MEDIA_TYPE = 'application/problem+json';

    /** The vendor's error schemas, dropped once nothing references them. */
    private const VENDOR_SCHEMAS = ['Error', 'ConstraintViolation'];

    /** The path prefix of the operations that sign in, outside the authentication gate (§4.2's path rule). */
    private const AUTH_PREFIX = '/auth/';

    /**
     * One sentence per status, true of every operation it lands on (wording owner: UX). An operation's own attribute may
     * give its own.
     *
     * @var array<int, string>
     */
    public const DESCRIPTIONS = [
        401 => 'No usable sign-in. The bearer token is missing, not valid or expired, or is no longer accepted, for example because the account was deactivated or deleted, or its password was changed or reset after the token was issued. A new token comes from signing in (`IssueToken`).',
        403 => 'The signed-in account does not hold the permission this request needs. `required_permission` names it.',
        404 => 'Not found: what this address names does not exist in this workspace. An archived record is still found.',
        409 => 'The request was not carried out. A business rule does not allow it now (for example because of the record\'s current state), or another change to the same data happened at the same moment, in which case trying again may work. `rule` names the reason and `message` says it in plain words. If this operation also lists 428, a 409 can instead mean the `Idempotency-Key` was already used for a different request. If its request body has a `version`, a 409 can instead mean the figures changed since they were read; `current` then holds the new ones. Neither of these two carries `rule` or `message`.',
        422 => 'Something in the request cannot be accepted as sent. `errors` lists what was refused, each with where it is (`pointer`), a code (`code`) and a sentence (`message`). It may not list every problem at once.',
        428 => 'This request needs an `Idempotency-Key` header, and it was missing or empty. The key lets the request be sent again safely: a repeat with the same key and the same body is not carried out twice.',
        429 => 'Too many attempts. Wait before trying again; no wait time is given.',
    ];

    /**
     * `Problem`'s member schemas, keyed by `ApiProblem`'s PHP property name — never by the serialized name, which is
     * read from the serializer's own metadata ({@see self::problemProperties()}), so no wire member name is written here.
     * Order is the published order. `instance` is absent: it is never set, so never sent.
     *
     * @var array<string, array<string, string>>
     */
    private const MEMBERS = [
        'type' => ['type' => 'string', 'description' => 'Which kind of refusal this is. Read this, not `title`.'],
        'title' => ['type' => 'string', 'description' => 'A short summary of the kind, the same for every refusal of that kind.'],
        'status' => ['type' => 'integer', 'description' => 'The HTTP status, repeated.'],
        'detail' => ['type' => 'string', 'description' => 'A sentence about this refusal, when there is one.'],
        'errors' => ['type' => 'array', 'description' => 'On 422: what was refused.'],
        'rule' => ['type' => 'string', 'description' => 'On a 409 refused by a rule: the rule\'s name, which stays the same if its wording changes.'],
        'ruleMessage' => ['type' => 'string', 'description' => 'On a 409 refused by a rule: the rule in plain words, fit to show a person.'],
        'requiredPermission' => ['type' => 'string', 'description' => 'On 403: the permission the request needed.'],
        'current' => ['type' => 'object', 'description' => 'On a 409 about stock figures that changed: the figures as they are now.'],
        'allow' => ['type' => 'string', 'description' => 'On 405: the methods this address serves.'],
    ];

    /** The `ApiProblem` member that is never set, so never sent, and so never published. */
    private const NEVER_SENT = 'instance';

    public function __construct(
        private OpenApiFactoryInterface $inner,
        private ResourceNameCollectionFactoryInterface $resourceNames,
        private ResourceMetadataCollectionFactoryInterface $resourceMetadata,
        private ClassMetadataFactoryInterface $serializerMetadata,
        #[Autowire(service: 'api_platform.name_converter')]
        private NameConverterInterface $nameConverter,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->inner)($context);
        $operations = $this->operationsByName();

        $paths = new Paths();
        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            $paths->addPath($path, $this->withRefusals($pathItem, $operations));
        }

        $schemas = new \ArrayObject((array) ($openApi->getComponents()->getSchemas() ?? new \ArrayObject()));
        foreach (self::VENDOR_SCHEMAS as $vendor) {
            unset($schemas[$vendor]);
        }
        $schemas[self::SCHEMA] = $this->problemSchema();
        $schemas[self::FIELD_ERROR_SCHEMA] = self::fieldErrorSchema();
        $schemas->ksort();

        return $openApi
            ->withPaths($paths)
            ->withComponents($openApi->getComponents()->withSchemas($schemas));
    }

    /**
     * The statuses one operation can be refused with, by the rule in the class docblock.
     *
     * @return array<int, string> status => description
     */
    public static function refusalsOf(HttpOperation $operation): array
    {
        $template = (string) $operation->getUriTemplate();
        $statuses = [];

        foreach (($operation->getOpenapi() instanceof OpenApiOperation ? $operation->getOpenapi()->getResponses() : null) ?? [] as $status => $response) {
            if ((int) $status >= 400) {
                $statuses[(int) $status] = $response instanceof Response && null !== $response->getDescription()
                    ? $response->getDescription()
                    : self::DESCRIPTIONS[(int) $status] ?? throw new \LogicException(\sprintf('Operation "%s" declares a %s with no description, and no house sentence exists for it.', (string) $operation->getName(), $status));
            }
        }

        $derived = [];
        if (!str_starts_with($template, self::AUTH_PREFIX)) {
            $derived[] = 401;
        }
        if (PermissionRequirement::NONE !== PermissionRequirement::of($operation)->kind()) {
            $derived[] = 403;
        }
        if (str_contains($template, '{')) {
            $derived[] = 404;
        }
        if ('GET' !== $operation->getMethod() && true !== ($operation->getExtraProperties()[self::QUEUED] ?? null)) {
            $derived[] = 409;
        }
        $derived[] = 422;
        if (true === ($operation->getExtraProperties()[IdempotencyKeyGuard::OPERATION_FLAG] ?? null)) {
            $derived[] = 428;
        }

        foreach ($derived as $status) {
            $statuses[$status] ??= self::DESCRIPTIONS[$status];
        }
        ksort($statuses);

        return $statuses;
    }

    /**
     * @param array<string, HttpOperation> $operations
     */
    private function withRefusals(PathItem $pathItem, array $operations): PathItem
    {
        foreach (['Get', 'Post', 'Patch', 'Put', 'Delete'] as $method) {
            $openapiOperation = $pathItem->{'get'.$method}();
            if (!$openapiOperation instanceof OpenApiOperation) {
                continue;
            }
            $operation = $operations[(string) $openapiOperation->getOperationId()] ?? null;
            if (null === $operation) {
                // Not an operation of this application; `ErrorResponsesTest` requires that none exists.
                continue;
            }

            $responses = [];
            foreach ($openapiOperation->getResponses() ?? [] as $status => $response) {
                if (str_starts_with((string) $status, '2')) {
                    $responses[(string) $status] = $response;
                }
            }
            foreach (self::refusalsOf($operation) as $status => $description) {
                $responses[(string) $status] = self::refusal($status, $description);
            }

            $pathItem = $pathItem->{'with'.$method}($openapiOperation->withResponses($responses));
        }

        return $pathItem;
    }

    private static function refusal(int $status, string $description): Response
    {
        $headers = 401 === $status
            ? new \ArrayObject(['WWW-Authenticate' => new \ArrayObject([
                'description' => 'Always `Bearer`.',
                'schema' => new \ArrayObject(['type' => 'string']),
            ])])
            : null;

        return new Response(
            $description,
            new \ArrayObject([self::MEDIA_TYPE => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/'.self::SCHEMA]))]),
            $headers,
        );
    }

    /**
     * Every application operation, keyed by its name: the spec's `operationId` is the operation's name (§12.3), which
     * `ErrorResponsesTest` measures.
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
                foreach ($resource->getOperations() ?? [] as $name => $operation) {
                    if ($operation instanceof HttpOperation && !str_starts_with((string) $name, '_api_')) {
                        $operations[(string) $name] = $operation;
                    }
                }
            }
        }

        return $operations;
    }

    /** The members `ApiProblem` serializes (group `jsonproblem`, nulls skipped); `instance` is never set, so never sent. */
    private function problemSchema(): \ArrayObject
    {
        return new \ArrayObject([
            'type' => 'object',
            'description' => 'A refusal (RFC 9457 problem details). `type`, `title` and `status` are always present; each other member is present only on the refusals that set it.',
            'additionalProperties' => false,
            'required' => ['type', 'title', 'status'],
            'properties' => $this->problemProperties(),
        ]);
    }

    /**
     * Each member `ApiProblem` serializes in the `jsonproblem` group, under the name the serializer gives it (its
     * `#[SerializedName]`, else the name converter's), with the schema {@see self::MEMBERS} keeps for its PHP property.
     * A member serialized without a schema here, or a schema for a member not serialized, is a build failure: the
     * published shape must be what the server sends.
     */
    private function problemProperties(): \ArrayObject
    {
        $serialized = [];
        foreach ($this->serializerMetadata->getMetadataFor(ApiProblem::class)->getAttributesMetadata() as $name => $attribute) {
            if (\in_array('jsonproblem', $attribute->getGroups(), true) && self::NEVER_SENT !== $name) {
                $serialized[$name] = $attribute->getSerializedName() ?? $this->nameConverter->normalize($name, ApiProblem::class, 'json');
            }
        }
        $unmatched = array_merge(array_diff(array_keys($serialized), array_keys(self::MEMBERS)), array_diff(array_keys(self::MEMBERS), array_keys($serialized)));
        if ([] !== $unmatched) {
            throw new \LogicException(\sprintf('ApiProblem serializes members this spec does not describe, or the spec describes members it does not serialize: %s.', implode(', ', $unmatched)));
        }

        $properties = new \ArrayObject();
        foreach (self::MEMBERS as $name => $schema) {
            $member = new \ArrayObject($schema);
            if ('type' === $name) {
                $member['enum'] = array_map(static fn (ProblemType $type): string => $type->uri(), ProblemType::cases());
            }
            if ('array' === $schema['type']) {
                $member['items'] = new \ArrayObject(['$ref' => '#/components/schemas/'.self::FIELD_ERROR_SCHEMA]);
            }
            $properties[$serialized[$name]] = $member;
        }

        return $properties;
    }

    private static function fieldErrorSchema(): \ArrayObject
    {
        return new \ArrayObject([
            'type' => 'object',
            'description' => 'One field defect.',
            'additionalProperties' => false,
            'required' => ['pointer', 'code', 'message'],
            'properties' => new \ArrayObject([
                'pointer' => new \ArrayObject(['type' => 'string', 'description' => 'Where the problem is: a JSON pointer into the body (`/name`, or an empty string for the whole body), `?` and a name for a query parameter (`?limit`), or `#` and a name for a header (`#Idempotency-Key`).']),
                'code' => new \ArrayObject(['type' => 'string', 'description' => 'A short name for the problem that does not change.']),
                'message' => new \ArrayObject(['type' => 'string', 'description' => 'A sentence fit to show a person.']),
            ]),
        ]);
    }
}
