<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * **ARCHITECTURE.md §6.4 M-2, owed since it was written: a message payload holds no bare scalar** (Mail-C0; plan §4.2).
 * A queued envelope sits outside Postgres, readable by whoever holds the queue credential; a bare `string $customerName`
 * is PII in plain text there, where an identifier value object is not. Permitted constructor parameter types: a class
 * (identifier and `SharedKernel` value objects, typed collections, `DateTimeImmutable`) or a backed enum. Refused: a
 * builtin (`string`, `int`, `float`, `bool`, `array`, `mixed`, …), an untyped parameter, a union or intersection.
 *
 * In `tests/` rather than `src/`: it has no runtime caller, and a dead `src/` class would be the thing to explain.
 */
final class MessagePayloadRule
{
    /**
     * @param class-string $class
     *
     * @return list<string> one line per offending parameter, empty when the class complies
     */
    public static function violations(string $class): array
    {
        $violations = [];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType) {
                $violations[] = $class.'::$'.$parameter->getName().' has no single named type';
                continue;
            }
            if ($type->isBuiltin()) {
                $violations[] = $class.'::$'.$parameter->getName().' is a bare '.$type->getName();
                continue;
            }
            $name = $type->getName();
            if (enum_exists($name) && !is_subclass_of($name, \BackedEnum::class)) {
                $violations[] = $class.'::$'.$parameter->getName().' is a pure (unbacked) enum';
            }
        }

        return $violations;
    }
}
