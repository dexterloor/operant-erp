<?php

declare(strict_types=1);

namespace App\Tests\Support;

use ApiPlatform\Metadata\HttpOperation;
use App\Platform\Security\PermissionRequirement;
use App\Platform\Security\PermissionRequirements;

/**
 * **How a permission sweep drives one operation (G5-D0, final RED L-4).** One place, so the four HTTP sweeps
 * (`PermissionEnforcementTest` ×3, `AuditWriterTest`'s catalogue sweep) cannot each invent their own derived arm -- and
 * none can skip a derived operation in silence, which is the fake-green the old `\is_string()` filter was.
 *
 * - NONE: no case.
 * - ALL_OF: one case, its codes in byte order (a caller holding nothing is refused naming the first), the body the sweeps
 *   always sent (`[]` for a write, `null` otherwise).
 * - DERIVED: one case per key of the resolver's map, byte-sorted -- EVERY key, not the first, so a wrong code for any one
 *   resource is red in the sweep -- each with body `{"resource": key}` and the one resolved code. The gate reads nothing
 *   else of the body, so nothing else is sent (the deserializer's own 422 after the gate is what a "held" sweep sees).
 */
final class PermissionSweepCases
{
    /**
     * @return list<array{label: string, required: list<string>, body: array<string, mixed>|null, resource: string|null}>
     */
    public static function for(HttpOperation $operation, PermissionRequirements $requirements): array
    {
        $requirement = PermissionRequirement::of($operation);
        $name = (string) $operation->getName();
        $write = \in_array($operation->getMethod(), ['POST', 'PATCH', 'PUT'], true);

        return match ($requirement->kind()) {
            PermissionRequirement::NONE => [],
            PermissionRequirement::ALL_OF => [['label' => $name, 'required' => $requirement->codes(), 'body' => $write ? [] : null, 'resource' => null]],
            default => self::derived($name, $requirements->codesByResource($requirement)),
        };
    }

    /**
     * @param array<string, string> $map
     *
     * @return list<array{label: string, required: list<string>, body: array<string, mixed>, resource: string}>
     */
    private static function derived(string $name, array $map): array
    {
        $cases = [];
        foreach ($map as $resource => $code) {
            $cases[] = [
                'label' => $name.' {resource: '.$resource.'}',
                'required' => [$code],
                'body' => [PermissionRequirements::RESOURCE_FIELD => $resource],
                'resource' => $resource,
            ];
        }

        return $cases;
    }
}
