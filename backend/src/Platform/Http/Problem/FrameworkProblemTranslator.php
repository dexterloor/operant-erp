<?php

declare(strict_types=1);

namespace App\Platform\Http\Problem;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Platform\Domain\IllegalTransition;
use App\Platform\Http\NonObjectBodyRefusal;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Four rows of §5.2's catalogue are produced by the framework rather than by
 * this application, and left alone every one of them answers with API
 * Platform's generic error shape and a `type` of `/errors/415` -- which is a
 * relative URI, not the URN API-13 fixes. This listener is what makes those rows
 * reachable in the form the catalogue declares.
 *
 * The second half is API-9's runtime limb. `allow_extra_attributes: false` on a
 * write operation makes the serializer raise `ExtraAttributesException`; without
 * a mapping that surfaces as a 400 with no `errors[]`, so the undeclared field
 * the caller sent is never named back to it.
 *
 * Scoped to the API base path. Outside it -- the profiler, a static asset, a
 * future non-API controller -- Symfony's own error handling is left alone; a
 * translator that fires on every 404 in the process is one that turns an
 * unrelated bug into a problem+json document.
 *
 * ---------------------------------------------------------------------------
 * One arm here is not a framework exception, and that is deliberate
 * ---------------------------------------------------------------------------
 * `App\Platform\Domain\IllegalTransition` is raised by an entity. API-15 requires the
 * status refusal to reach the caller as `business-rule-violated`, and §8.2 requires
 * the guard to sit on the entity -- so something has to join the two, and the
 * alternatives are worse: `ApiProblem` thrown from an entity inverts the
 * dependency, and a second `ExceptionEvent` listener adds a priority ordering
 * nobody would think to look at. **It is here because the day the first operation
 * calls `place()` is Output 9's, and its author has no reason to read §8.2:**
 * without this arm an entirely correct guard answers a legal request with a 500.
 *
 * `RoleGrantProposal`'s handlers rewrite this sentence for vocabulary only (UX F-1); the house choice of table + status
 * in `detail` stands (`RoleGrantProposalStatusRefusal`, roles slice 3 C2).
 *
 * ---------------------------------------------------------------------------
 * A value of the wrong JSON type (`RESIDUAL_GAPS.md` G-101, closed here)
 * ---------------------------------------------------------------------------
 * For an operation with an `input:` class, API Platform's `AbstractItemNormalizer::denormalize` catches the serializer's
 * field exception and rethrows it as `UnexpectedValueException('The input data is misformatted.')`, with the field
 * exception as `previous`. Left alone that was a bare 400 with no `errors[]`. `translate()` unwraps exactly ONE level --
 * never to the innermost exception: a property field's chain is two field exceptions deep, and the inner one expects only
 * `null` -- and {@see self::fieldTypeRefusal()} answers it at the field's WIRE pointer with a house code: `not_a_string`,
 * `not_a_list` for `lines`, `required` (a `null` on a `POST`) or `not_nullable` (on a `PATCH`), and `wrong_type` only for a
 * type no input declares. The body names no PHP class, no PHP type and no submitted value: the vendor message carries
 * all three, so it is never used. A constructor parameter left out (`MissingConstructorArgumentsException`) is one
 * `required` entry per missing field, at its wire pointer, like every other required field.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: 0)]
final readonly class FrameworkProblemTranslator
{
    private const VENDOR_UPDATE_REFUSAL = 'Update is not allowed for this operation.';

    /**
     * The deserializer's refusal of a body it cannot read. Public and single-sourced (G5-D0): `PermissionRequirements`
     * reads a derived operation's `resource` BEFORE the deserializer runs and must refuse an unreadable body with exactly
     * this body, so a caller cannot tell which of the two read it.
     */
    public const MALFORMED_BODY_MESSAGE = 'The request body could not be read as the declared JSON shape.';

    /**
     * The refusal of `lines` that is not a list. Public and single-sourced (G-101): the six line writers answer
     * `lines: {"a":1}` with it, and this translator answers a `lines` of another JSON type with it, so one field gets one
     * sentence whichever layer refuses it.
     */
    public const NOT_A_LIST_OF_LINES = 'This field must be a list of lines.';

    /** G-101's fallback, for a JSON type no input member declares. */
    public const WRONG_TYPE_MESSAGE = 'This field has a value of the wrong type.';

    private const REQUIRED_MESSAGE = 'This field is required.';

    private const NOT_NULLABLE_MESSAGE = 'This field may not be cleared.';

    private const NOT_A_STRING_MESSAGE = 'This field must be a string.';

    /**
     * The refusal of a body that is not a JSON object. Public and single-sourced (G-102): `NonObjectBodyRefusal` refuses
     * one on every operation that reads a body, and `PermissionRequirements` on the derived operations, before the
     * deserializer runs, with exactly this sentence.
     */
    public const NOT_AN_OBJECT_MESSAGE = 'The request body must be a JSON object.';

    public function __construct(
        #[Autowire('%app.api_base_path%')]
        private string $basePath,
        private NormalizerInterface $normalizer,
        private ResourceMetadataCollectionFactoryInterface $operations,
        #[Autowire(service: 'api_platform.name_converter')]
        private NameConverterInterface $nameConverter,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), $this->basePath)) {
            return;
        }

        $problem = $this->translate($event->getThrowable(), $request);
        if (null === $problem) {
            return;
        }

        // Routing failures -- a 404 at a path no operation answers, a 405 at a
        // path that answers a different verb -- are raised before any route
        // matched, so `_api_resource_class` is absent and API Platform's error
        // listener hands the request to Symfony's error controller, which
        // renders its own problem document with `class` and `trace` in it. Those
        // two refusals are rendered here instead, from the same `#[Groups]` the
        // API path serialises, so §5.2's shape holds on both routes into it.
        if (!$request->attributes->has('_api_resource_class')) {
            $event->setResponse($this->render($problem));
            $event->stopPropagation();

            return;
        }

        $event->setThrowable($problem);
    }

    private function render(ApiProblem $problem): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = $this->normalizer->normalize($problem, 'json', [
            'groups' => ['jsonproblem'],
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);

        return new JsonResponse(
            $body,
            $problem->getStatusCode(),
            ['Content-Type' => 'application/problem+json; charset=utf-8'] + $problem->getHeaders(),
        );
    }

    private function translate(\Throwable $e, Request $request): ?ApiProblem
    {
        // G-101: API Platform's input wrapper, unwrapped exactly one level (never to the innermost field exception).
        if ($e instanceof UnexpectedValueException
            && !$e instanceof NotNormalizableValueException
            && $e->getPrevious() instanceof NotNormalizableValueException
        ) {
            $e = $e->getPrevious();
        }

        return match (true) {
            $e instanceof ApiProblem => null,

            // --- API-15, the one application refusal translated here ---------
            $e instanceof IllegalTransition => ApiProblem::businessRuleViolated($e->rule(), $e->clientMessage()),

            // --- G-65's closing work (2026-10-23): a transaction that lost a lock cycle or a lock wait ----------------
            // Every API write, by construction (`docs/session13/g65-design.md` §2): PostgreSQL has already rolled the whole
            // transaction back, so "nothing was saved; try again" is true and a 500 was not. Keyed on the contention
            // SQLSTATEs only; any other driver failure falls through to the 500 path.
            $e instanceof DriverException && TransactionContention::is($e) => ApiProblem::contention(
                TransactionContention::RULE,
                TransactionContention::MESSAGE,
                $e,
            ),

            // --- the catalogue's framework-produced rows ---------------------
            $e instanceof MethodNotAllowedHttpException => ApiProblem::methodNotAllowed(
                array_values(array_filter(array_map(trim(...), explode(',', $e->getHeaders()['Allow'] ?? '')))),
            ),
            $e instanceof NotAcceptableHttpException => ApiProblem::notAcceptable($e->getMessage()),
            $e instanceof UnsupportedMediaTypeHttpException => ApiProblem::unsupportedMediaType($e->getMessage()),
            $e instanceof NotFoundHttpException => ApiProblem::notFound(),

            // --- API-9's runtime limb, and the deserializer's other refusals --
            $e instanceof ExtraAttributesException => ApiProblem::validationFailed(
                array_map(
                    static fn (string $name): array => [
                        'pointer' => '/'.$name,
                        'code' => 'undeclared_field',
                        'message' => \sprintf('The field "%s" is not accepted by this operation.', $name),
                    ],
                    array_values($e->getExtraAttributes()),
                ),
            ),
            $e instanceof MissingConstructorArgumentsException => ApiProblem::validationFailed($this->missingArguments($e, $request)),
            $e instanceof NotEncodableValueException => ApiProblem::validationFailed([[
                'pointer' => '',
                'code' => 'malformed_body',
                'message' => self::MALFORMED_BODY_MESSAGE,
            ]]),
            // API Platform's own client-supplied-id refusal, which fires in
            // `ItemNormalizer::denormalize` before `allow_extra_attributes`
            // ever sees the field. It is the layer of B-3 that answers today
            // -- with this vendor block cut, `allow_extra_attributes` does
            // refuse `id` with 422 (R4, layer L3b, 2026-09-14; `2c25895` had
            // only tried switching the block off by `api_allow_update`) --
            // and its message names neither the field nor the reason, so it
            // is re-labelled here. The coupling to the
            // vendor's wording is deliberate and pinned:
            // `ItemWriteTest::aCreateCarryingAnIdIsRefused` asserts the `/id`
            // pointer, so a message change upstream turns the test red rather
            // than silently degrading the body to a bare 422.
            $e instanceof NotNormalizableValueException && self::VENDOR_UPDATE_REFUSAL === $e->getMessage() => ApiProblem::validationFailed([[
                'pointer' => '/id',
                'code' => 'undeclared_field',
                'message' => 'The field "id" is not accepted by this operation. Row identifiers are generated by the server.',
            ]]),
            $e instanceof NotNormalizableValueException => ApiProblem::validationFailed([$this->fieldTypeRefusal($e, $request)]),
            $e instanceof PartialDenormalizationException => ApiProblem::validationFailed(
                array_map(
                    fn (NotNormalizableValueException $v): array => $this->fieldTypeRefusal($v, $request),
                    $e->getErrors(),
                ),
            ),

            // API Platform validates a declared parameter `schema` -- the
            // `sort` enum, the `limit` bounds -- before any provider runs, and
            // raises its own `ValidationException`, whose `type` is
            // `/validation_errors/{uuid}`: a relative URI where API-13 requires
            // a URN, and a body with no `errors[]`. Translating it here rather
            // than removing the schema keeps the generated spec documenting the
            // enum and the bounds, which is half of what API-19 is for.
            $e instanceof ValidationException => ApiProblem::validationFailed(
                array_map(
                    fn (mixed $violation): array => [
                        'pointer' => $this->pointerFor((string) $violation->getPropertyPath(), $request),
                        'code' => 'invalid_parameter',
                        'message' => (string) $violation->getMessage(),
                    ],
                    iterator_to_array($e->getConstraintViolationList()),
                ),
            ),

            default => null,
        };
    }

    /**
     * G-101: one `errors[]` entry for a field exception, at the field's wire pointer, in house vocabulary. Messages never
     * come from the exception (it names the PHP class, the PHP types and, on some branches, the submitted value).
     *
     * @return array{pointer: string, code: string, message: string}
     */
    private function fieldTypeRefusal(NotNormalizableValueException $e, Request $request): array
    {
        $path = $e->getPath();

        // The whole body was the problem. Unreachable over HTTP since G-102 (a non-object body is refused before the
        // deserializer runs); kept as a backstop.
        if (null === $path || '' === $path) {
            return 'array' !== $e->getCurrentType()
                ? ['pointer' => '', 'code' => NonObjectBodyRefusal::CODE, 'message' => self::NOT_AN_OBJECT_MESSAGE]
                : ['pointer' => '', 'code' => 'malformed_body', 'message' => self::MALFORMED_BODY_MESSAGE];
        }

        $class = $this->inputClassOf($request);
        if (null === $class) {
            return ['pointer' => '', 'code' => 'wrong_type', 'message' => self::WRONG_TYPE_MESSAGE];
        }

        $pointer = $this->wirePointer($path, $class);

        if ('null' === $e->getCurrentType()) {
            return 'POST' === $request->getMethod()
                ? ['pointer' => $pointer, 'code' => 'required', 'message' => self::REQUIRED_MESSAGE]
                : ['pointer' => $pointer, 'code' => 'not_nullable', 'message' => self::NOT_NULLABLE_MESSAGE];
        }

        $expected = array_values(array_diff($e->getExpectedTypes() ?? [], ['null']));

        return match ($expected) {
            ['string'] => ['pointer' => $pointer, 'code' => 'not_a_string', 'message' => self::NOT_A_STRING_MESSAGE],
            ['array'] => ['pointer' => $pointer, 'code' => 'not_a_list', 'message' => self::NOT_A_LIST_OF_LINES],
            default => ['pointer' => $pointer, 'code' => 'wrong_type', 'message' => self::WRONG_TYPE_MESSAGE],
        };
    }

    /**
     * One `required` per missing constructor argument, at its wire name. The exception's class is nullable
     * (`MissingConstructorArgumentsException::getClass(): ?string`): without one, the operation's input class names the
     * fields; without either, one entry at `""`, the field arm's no-class rule (G-101 C2 deviations RED L-4).
     *
     * @return list<array{pointer: string, code: string, message: string}>
     */
    private function missingArguments(MissingConstructorArgumentsException $e, Request $request): array
    {
        $class = $e->getClass() ?? $this->inputClassOf($request);
        if (null === $class) {
            return [['pointer' => '', 'code' => 'required', 'message' => self::REQUIRED_MESSAGE]];
        }

        return array_map(
            fn (string $name): array => ['pointer' => '/'.$this->wireName($name, $class), 'code' => 'required', 'message' => self::REQUIRED_MESSAGE],
            array_values($e->getMissingConstructorArguments()),
        );
    }

    /**
     * The serializer's path as a JSON pointer in wire names, as deep as it can be named truthfully: the first segment
     * through the name converter, then any directly following `[n]` integer indices (`reorderPoint` → `/reorder_point`,
     * `lines[0]` → `/lines/0`). It stops at the first later named or non-integer segment: the serializer emits PHP names
     * at every level (`lines[0].unitPrice`), and only the first level has a class to convert against, so a deeper name
     * would put a PHP name on the wire (G-101 C2 deviations RED L-3). Unreachable today: no input member is a typed
     * collection or a class.
     */
    private function wirePointer(string $path, string $class): string
    {
        preg_match('/\A([^.\[]*)((?:\[\d+\])*)/', $path, $match);
        $pointer = '' === ($match[1] ?? '') ? '' : '/'.$this->wireName($match[1], $class);
        preg_match_all('/\[(\d+)\]/', $match[2] ?? '', $indices);
        foreach ($indices[1] as $index) {
            $pointer .= '/'.$index;
        }

        return $pointer;
    }

    private function wireName(string $phpName, string $class): string
    {
        return $this->nameConverter->normalize($phpName, $class);
    }

    /** The class the operation deserializes into: its `input:` class, else its resource class; null outside an operation. */
    private function inputClassOf(Request $request): ?string
    {
        $resourceClass = $request->attributes->get('_api_resource_class');
        $operationName = $request->attributes->get('_api_operation_name');

        if (!\is_string($resourceClass) || !\is_string($operationName)) {
            return null;
        }

        $operation = $this->operations->create($resourceClass)->getOperation($operationName);
        $input = $operation->getInput();
        $class = \is_array($input) ? ($input['class'] ?? null) : null;

        return \is_string($class) ? $class : ($operation->getClass() ?? $resourceClass);
    }

    /**
     * A JSON pointer addresses a request body. A query parameter is not in one,
     * so it is named with the `?` form -- and which of the two a path is gets
     * decided against the operation's **declared** parameters rather than
     * guessed from the string.
     */
    private function pointerFor(string $propertyPath, Request $request): string
    {
        $resourceClass = $request->attributes->get('_api_resource_class');
        $operationName = $request->attributes->get('_api_operation_name');

        if (\is_string($resourceClass) && \is_string($operationName)) {
            $operation = $this->operations->create($resourceClass)->getOperation($operationName);

            if ($operation instanceof HttpOperation) {
                foreach ($operation->getParameters() ?? [] as $key => $parameter) {
                    if ($propertyPath === (string) ($parameter->getKey() ?? $key)) {
                        return '?'.$propertyPath;
                    }
                }
            }
        }

        return '/'.$propertyPath;
    }
}
