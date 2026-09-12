<?php

declare(strict_types=1);

namespace App\Platform\Http\Problem;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Platform\Domain\IllegalTransition;
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
 */
#[AsEventListener(event: ExceptionEvent::class, priority: 0)]
final readonly class FrameworkProblemTranslator
{
    private const VENDOR_UPDATE_REFUSAL = 'Update is not allowed for this operation.';

    public function __construct(
        #[Autowire('%app.api_base_path%')]
        private string $basePath,
        private NormalizerInterface $normalizer,
        private ResourceMetadataCollectionFactoryInterface $operations,
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
        return match (true) {
            $e instanceof ApiProblem => null,

            // --- API-15, the one application refusal translated here ---------
            $e instanceof IllegalTransition => ApiProblem::businessRuleViolated($e->rule(), $e->clientMessage()),

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
            $e instanceof MissingConstructorArgumentsException,
            $e instanceof NotEncodableValueException => ApiProblem::validationFailed([[
                'pointer' => '',
                'code' => 'malformed_body',
                'message' => 'The request body could not be read as the declared JSON shape.',
            ]]),
            // API Platform's own client-supplied-id refusal, which fires in
            // `ItemNormalizer::denormalize` before `allow_extra_attributes`
            // ever sees the field. It is the right refusal -- B-3 twice over --
            // with a message that names neither the field nor the reason, so it
            // is re-labelled here. The coupling to the vendor's wording is
            // deliberate and pinned: `ItemApiWriteTest::aCreateCarryingAnIdIsRefused`
            // asserts the `/id` pointer, so a message change upstream turns the
            // test red rather than silently degrading the body to a bare 422.
            $e instanceof NotNormalizableValueException && self::VENDOR_UPDATE_REFUSAL === $e->getMessage() => ApiProblem::validationFailed([[
                'pointer' => '/id',
                'code' => 'undeclared_field',
                'message' => 'The field "id" is not accepted by this operation. Row identifiers are generated by the server.',
            ]]),
            $e instanceof NotNormalizableValueException => ApiProblem::validationFailed([[
                'pointer' => '/'.($e->getPath() ?? ''),
                'code' => 'wrong_type',
                'message' => \sprintf('Expected %s.', implode(' or ', $e->getExpectedTypes() ?? ['a value this operation accepts'])),
            ]]),
            $e instanceof PartialDenormalizationException => ApiProblem::validationFailed(
                array_map(
                    static fn (NotNormalizableValueException $v): array => [
                        'pointer' => '/'.($v->getPath() ?? ''),
                        'code' => 'wrong_type',
                        'message' => \sprintf('Expected %s.', implode(' or ', $v->getExpectedTypes() ?? ['a different type'])),
                    ],
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
