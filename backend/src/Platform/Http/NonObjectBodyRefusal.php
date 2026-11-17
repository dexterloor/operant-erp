<?php

declare(strict_types=1);

namespace App\Platform\Http;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Problem\FrameworkProblemTranslator;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * A request body must be a JSON object (`RESIDUAL_GAPS.md` G-102, closed by this class).
 *
 * API Platform's item denormalizer treats a top-level JSON **string** body as a link (an IRI) and resolves it by running
 * the linked route's provider. That route's permission is never decided -- `PermissionGate` decides only the request's
 * own operation -- so until this class a caller holding `inventory_item.create` could tell which item and user ids exist
 * in its own workspace (400 for an existing id, 404 for a missing one), and an anonymous sign-in request with a link body
 * answered 500. Every other non-object body (a number, `true`/`false`, `null`, any list) was answered inconsistently: a
 * 400, a field 422 at `/0`, or, for `PATCH []`, a 200 that changed nothing.
 *
 * Every operation that reads a body now refuses one that is not a JSON object with one `validation-failed` entry,
 * `{pointer: "", code: not_an_object}`, before any field is read and before anything is looked up. The sentence is the
 * one the derived operations already answered with (`PermissionRequirements`), single-sourced in
 * {@see FrameworkProblemTranslator::NOT_AN_OBJECT_MESSAGE}.
 *
 * ---------------------------------------------------------------------------
 * Why a provider at 350, and why it calls its inner provider first
 * ---------------------------------------------------------------------------
 * The chain, outermost first: content negotiation (100) → parameter validation → `validate` (200) →
 * `BodylessWriteRefusal` (250) → `deserialize` (300) → **this (350)** → `UndeclaredQueryParameterRefusal` (400) → `read`
 * (500). `DeserializeProvider` calls its inner provider before it deserializes, so a decorator here runs after the row
 * load and before the link is resolved:
 *  - **after the row load:** the inner provider runs first, so an operation whose provider loads the path's row still
 *    answers 404 for a missing row before its body is looked at (the order `BodylessWriteRefusal` keeps for the same
 *    reason). On a `read: false` operation the row is found by its processor, which runs later; there a field refusal
 *    already came first, and this refusal does too.
 *  - **before the link is resolved:** at 250 (around `deserialize`) the link would already have been looked up. A
 *    `kernel.request` listener would refuse ahead of 401, 403 and 404 and change the shipped order on every write.
 *
 * ---------------------------------------------------------------------------
 * What it acts on, and what it leaves alone
 * ---------------------------------------------------------------------------
 *  - `POST`/`PUT`/`PATCH` on an operation whose `canDeserialize()` is `true`. The condition is kept as a statement of
 *    intent; it is equivalent today to the `input_format` condition, because content negotiation sets no input format
 *    when `canDeserialize()` is `false`, so no mutation of it can go red. Body-less writes are `BodylessWriteRefusal`'s.
 *  - Only when content negotiation resolved the input format to `json`. A missing `Content-Type` sets none, so
 *    `DeserializeProvider` still answers its own 415 ("The "Content-Type" header must exist."); a present non-JSON type is
 *    refused by content negotiation before this runs.
 *  - The body is decoded as `PermissionRequirements::resolve()` decodes it: object mode, `JSON_THROW_ON_ERROR`. On a
 *    `\JsonException` (unparseable bytes, a BOM, too deep, a property name starting with "\u0000") it returns and the
 *    deserializer answers `malformed_body` as before, so that answer keeps one source. One list is therefore answered as
 *    before: a list whose decoding fails on a NUL-led object key (`[{"\u0000":1}]`) is the deserializer's
 *    `undeclared_field` at `/0`. It names no record: only a top-level string is ever looked up, and a string always
 *    decodes.
 *  - A decoded value that is not an object is refused. `{}` and every object pass unchanged.
 *
 * The body is decoded twice (here and by the deserializer); bodies are small.
 */
#[AsDecorator(decorates: 'api_platform.state_provider.main', priority: self::PRIORITY)]
final readonly class NonObjectBodyRefusal implements ProviderInterface
{
    /** Inside `api_platform.state_provider.deserialize` (300), around `UndeclaredQueryParameterRefusal` (400) and `read` (500). */
    public const PRIORITY = 350;

    public const CODE = 'not_an_object';

    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * @param ProviderInterface<object> $inner
     */
    public function __construct(
        #[AutowireDecorated]
        private ProviderInterface $inner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $data = $this->inner->provide($operation, $uriVariables, $context);

        $request = $context['request'] ?? null;

        if (!$operation instanceof HttpOperation
            || !$request instanceof Request
            || true !== $operation->canDeserialize()
            || !\in_array($operation->getMethod(), self::BODY_METHODS, true)
            || 'json' !== $request->attributes->get('input_format')
        ) {
            return $data;
        }

        try {
            $body = json_decode((string) $request->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $data;
        }

        if (!$body instanceof \stdClass) {
            throw ApiProblem::validationFailed([[
                'pointer' => '',
                'code' => self::CODE,
                'message' => FrameworkProblemTranslator::NOT_AN_OBJECT_MESSAGE,
            ]]);
        }

        return $data;
    }
}
