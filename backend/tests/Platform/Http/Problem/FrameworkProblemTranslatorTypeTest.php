<?php

declare(strict_types=1);

namespace App\Tests\Platform\Http\Problem;

use App\Modules\Inventory\ApiResource\ItemResource;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\UserManagement\ApiResource\ChangePasswordInput;
use App\Modules\UserManagement\ApiResource\PasswordResource;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Problem\FrameworkProblemTranslator;
use App\Tests\Api\TransactionBoundaryTest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

/**
 * G-101's translation, without HTTP or a database: the exceptions are built the way the serializer and API Platform build
 * them (measured, `docs/session13/g101-red.md` M-3 and L-1), dispatched through the kernel's `kernel.exception` listeners,
 * and the `ApiProblem` read back.
 */
final class FrameworkProblemTranslatorTypeTest extends KernelTestCase
{
    private const WRAPPER = 'The input data is misformatted.';

    /** A wrapped field exception and the same exception unwrapped give byte-identical bodies. */
    #[Test]
    public function aWrappedAndAnUnwrappedFieldExceptionAnswerTheSame(): void
    {
        $field = self::field('reorderPoint', ['null', 'string'], 5);

        $wrapped = $this->errorsOf(new UnexpectedValueException(self::WRAPPER, 0, $field), 'POST', ItemResource::class, 'CreateItem');
        $bare = $this->errorsOf($field, 'POST', ItemResource::class, 'CreateItem');

        self::assertSame([['pointer' => '/reorder_point', 'code' => 'not_a_string', 'message' => 'This field must be a string.']], $wrapped);
        self::assertSame($wrapped, $bare);
    }

    /**
     * The real chain for a property field is two field exceptions deep: the outer one expects `null` or `string`, the inner
     * one only `null`. Unwrapping one level reads the outer one; unwrapping to the innermost (M13) would read `["null"]`
     * and answer the fallback.
     */
    #[Test]
    public function theRealTwoLevelChainIsReadAtItsOuterFieldException(): void
    {
        $inner = self::field('reorderPoint', ['null'], 5);
        $outer = self::field('reorderPoint', ['null', 'string'], 5, $inner);

        self::assertSame(
            [['pointer' => '/reorder_point', 'code' => 'not_a_string', 'message' => 'This field must be a string.']],
            $this->errorsOf(new UnexpectedValueException(self::WRAPPER, 0, $outer), 'POST', ItemResource::class, 'CreateItem'),
        );
    }

    /** Malformed JSON has no previous and stays `malformed_body`; a synthetic one with a JSON previous is not unwrapped. */
    #[Test]
    public function malformedJsonIsNotUnwrapped(): void
    {
        $malformed = [['pointer' => '', 'code' => 'malformed_body', 'message' => FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE]];

        self::assertSame($malformed, $this->errorsOf(new NotEncodableValueException('Syntax error'), 'POST', ItemResource::class, 'CreateItem'));
        self::assertSame($malformed, $this->errorsOf(new NotEncodableValueException('Syntax error', 0, new \JsonException('Syntax error')), 'POST', ItemResource::class, 'CreateItem'));
        // The wrapper class with a JSON previous (not a field exception) is not unwrapped: it is left to the framework.
        self::assertNull($this->problemOf(new UnexpectedValueException(self::WRAPPER, 0, new \JsonException('x')), 'POST', ItemResource::class, 'CreateItem'));
    }

    /**
     * `lines[0]` is `/lines/0`. A later named segment is not converted (the serializer emits it as a PHP name, here
     * `unitPrice`, and only the first level has a class to convert against), so the pointer stops at the deepest prefix it
     * can name truthfully; a non-integer bracket (`[a.b]`) stops it at the top-level field (C2 deviations RED L-3).
     */
    #[Test]
    public function aCollectionPathBecomesAPointer(): void
    {
        self::assertSame(
            [['pointer' => '/lines/0', 'code' => 'wrong_type', 'message' => FrameworkProblemTranslator::WRONG_TYPE_MESSAGE]],
            $this->errorsOf(self::field('lines[0]', ['int'], 'x'), 'POST', SalesOrderResource::class, 'CreateSalesOrder'),
        );
        self::assertSame(
            [['pointer' => '/lines/0', 'code' => 'not_a_string', 'message' => 'This field must be a string.']],
            $this->errorsOf(self::field('lines[0].unitPrice', ['string'], 5), 'POST', SalesOrderResource::class, 'CreateSalesOrder'),
        );
        self::assertSame(
            [['pointer' => '/lines', 'code' => 'not_a_string', 'message' => 'This field must be a string.']],
            $this->errorsOf(self::field('lines[a.b].unitPrice', ['string'], 5), 'POST', SalesOrderResource::class, 'CreateSalesOrder'),
        );
    }

    /** The table: string, array, a type no input declares, and `null` on each method. */
    #[Test]
    public function eachExpectedTypeHasItsCode(): void
    {
        self::assertSame(
            [['pointer' => '/lines', 'code' => 'not_a_list', 'message' => FrameworkProblemTranslator::NOT_A_LIST_OF_LINES]],
            $this->errorsOf(self::field('lines', ['array', 'null'], 5), 'POST', SalesOrderResource::class, 'CreateSalesOrder'),
        );
        self::assertSame(
            [['pointer' => '/reorder_point', 'code' => 'wrong_type', 'message' => FrameworkProblemTranslator::WRONG_TYPE_MESSAGE]],
            $this->errorsOf(self::field('reorderPoint', ['int'], 'x'), 'POST', ItemResource::class, 'CreateItem'),
        );
        self::assertSame(
            [['pointer' => '/current_password', 'code' => 'required', 'message' => 'This field is required.']],
            $this->errorsOf(self::field('currentPassword', ['string'], null), 'POST', PasswordResource::class, 'ChangePassword'),
        );
        // Unit only: no PATCH member is a non-nullable PHP type today (final RED L-4).
        self::assertSame(
            [['pointer' => '/reorder_point', 'code' => 'not_nullable', 'message' => 'This field may not be cleared.']],
            $this->errorsOf(self::field('reorderPoint', ['string'], null), 'PATCH', ItemResource::class, 'UpdateItem'),
        );
    }

    /** Path `null`: a non-object current type is `not_an_object`, an array `malformed_body` (both unreachable since G-102). */
    #[Test]
    public function aPathlessFieldExceptionIsTheWholeBody(): void
    {
        self::assertSame(
            [['pointer' => '', 'code' => 'not_an_object', 'message' => FrameworkProblemTranslator::NOT_AN_OBJECT_MESSAGE]],
            $this->errorsOf(NotNormalizableValueException::createForUnexpectedDataType('x', 5, ['array']), 'POST', ItemResource::class, 'CreateItem'),
        );
        self::assertSame(
            [['pointer' => '', 'code' => 'malformed_body', 'message' => FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE]],
            $this->errorsOf(NotNormalizableValueException::createForUnexpectedDataType('x', [1], ['string']), 'POST', ItemResource::class, 'CreateItem'),
        );
    }

    /** With no operation on the request, the pointer is `""` and the code `wrong_type`: no PHP name reaches the wire (L-3). */
    #[Test]
    public function withNoOperationThePhpNameIsNotPrinted(): void
    {
        $problem = $this->problemOf(self::field('reorderPoint', ['null', 'string'], 5), 'POST', ItemResource::class, null);

        self::assertInstanceOf(ApiProblem::class, $problem);
        self::assertSame([['pointer' => '', 'code' => 'wrong_type', 'message' => FrameworkProblemTranslator::WRONG_TYPE_MESSAGE]], $problem->getErrors());
    }

    /** Collected errors give one entry each, in order. */
    #[Test]
    public function aPartialDenormalizationGivesOneEntryPerField(): void
    {
        $e = new PartialDenormalizationException(null, [
            self::field('reorderPoint', ['null', 'string'], 5),
            self::field('sku', ['null', 'string'], true),
        ]);

        self::assertSame([
            ['pointer' => '/reorder_point', 'code' => 'not_a_string', 'message' => 'This field must be a string.'],
            ['pointer' => '/sku', 'code' => 'not_a_string', 'message' => 'This field must be a string.'],
        ], $this->errorsOf($e, 'POST', ItemResource::class, 'CreateItem'));
    }

    /** Missing constructor arguments: one `required` per field, at its wire name. */
    #[Test]
    public function missingConstructorArgumentsAreRequiredAtTheirWireNames(): void
    {
        $e = new MissingConstructorArgumentsException('missing', 0, null, ['currentPassword', 'newPassword'], ChangePasswordInput::class);

        self::assertSame([
            ['pointer' => '/current_password', 'code' => 'required', 'message' => 'This field is required.'],
            ['pointer' => '/new_password', 'code' => 'required', 'message' => 'This field is required.'],
        ], $this->errorsOf($e, 'POST', PasswordResource::class, 'ChangePassword'));
    }

    /**
     * The exception's class is nullable: without one the operation's input class names the fields, and without either the
     * listener answers one `required` at `""` instead of throwing (C2 deviations RED L-4).
     */
    #[Test]
    public function missingConstructorArgumentsWithoutAClassStillAnswer(): void
    {
        $e = new MissingConstructorArgumentsException('missing', 0, null, ['currentPassword'], null);

        self::assertSame(
            [['pointer' => '/current_password', 'code' => 'required', 'message' => 'This field is required.']],
            $this->errorsOf($e, 'POST', PasswordResource::class, 'ChangePassword'),
        );
        $problem = $this->problemOf($e, 'POST', PasswordResource::class, null);
        self::assertInstanceOf(ApiProblem::class, $problem);
        self::assertSame([['pointer' => '', 'code' => 'required', 'message' => 'This field is required.']], $problem->getErrors());
    }

    /** API Platform's client-supplied-id refusal still wins over the generic field arm. */
    #[Test]
    public function theIdArmStillWins(): void
    {
        $e = NotNormalizableValueException::createForUnexpectedDataType('Update is not allowed for this operation.', 'x', ['string'], 'id');

        self::assertSame('/id', $this->errorsOf($e, 'POST', ItemResource::class, 'CreateItem')[0]['pointer']);
        self::assertSame('undeclared_field', $this->errorsOf($e, 'POST', ItemResource::class, 'CreateItem')[0]['code']);
    }

    /**
     * Final RED L-5: the wrong-type sentence for a natively typed array says "lines", so every native array member of every
     * `input:` class must be the wire field `lines`.
     */
    #[Test]
    public function everyNativeArrayInputMemberIsWireNamedLines(): void
    {
        self::bootKernel();
        $names = self::getContainer()->get('api_platform.name_converter');
        \assert($names instanceof NameConverterInterface);

        $seen = 0;
        $offenders = [];
        foreach (TransactionBoundaryTest::contractOperations() as $operation) {
            $input = $operation->getInput();
            $class = \is_array($input) ? ($input['class'] ?? null) : null;
            if (!\is_string($class) || !class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            // Public properties (promoted constructor parameters included) and every constructor parameter: the serializer
            // types a non-promoted parameter by its parameter type (C2 deviations RED L-6). A union containing `array` counts.
            $members = [];
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $members[$property->getName()] = $property->getType();
            }
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $members[$parameter->getName()] ??= $parameter->getType();
            }
            foreach ($members as $name => $type) {
                if (!self::admitsANativeArray($type)) {
                    continue;
                }
                ++$seen;
                if ('lines' !== $names->normalize($name, $class)) {
                    $offenders[] = \sprintf('`%s::%s` is a native array; the wrong-type sentence for arrays says "lines" — give this field its own sentence in `fieldTypeRefusal()`.', $class, $name);
                }
            }
        }

        self::assertGreaterThan(0, $seen, 'Anti-vacuity: no native array input member was found.');
        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    private static function admitsANativeArray(?\ReflectionType $type): bool
    {
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $member) {
            if ($member instanceof \ReflectionNamedType && 'array' === $member->getName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $expected
     */
    private static function field(string $path, array $expected, mixed $data, ?\Throwable $previous = null): NotNormalizableValueException
    {
        return NotNormalizableValueException::createForUnexpectedDataType('The type of the "'.$path.'" attribute for class "App\\X" must be one of "'.implode('", "', $expected).'".', $data, $expected, $path, false, 0, $previous);
    }

    /** @return list<array{pointer: string, code: string, message: string}> */
    private function errorsOf(\Throwable $e, string $method, string $resourceClass, ?string $operationName): array
    {
        $problem = $this->problemOf($e, $method, $resourceClass, $operationName);
        self::assertInstanceOf(ApiProblem::class, $problem, 'Not translated: '.$e::class);
        self::assertSame(422, $problem->getStatus());

        return $problem->getErrors() ?? [];
    }

    private function problemOf(\Throwable $e, string $method, string $resourceClass, ?string $operationName): ?ApiProblem
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $kernel = self::getContainer()->get('http_kernel');
        \assert($kernel instanceof HttpKernelInterface);

        $request = Request::create('/api/v1/probe', $method);
        $request->attributes->set('_api_resource_class', $resourceClass);
        if (null !== $operationName) {
            $request->attributes->set('_api_operation_name', $operationName);
        }

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $e);
        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
        $thrown = $event->getThrowable();

        return $thrown instanceof ApiProblem ? $thrown : null;
    }
}
