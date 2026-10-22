<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Problem\FrameworkProblemTranslator;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * **The container-aware half of the permission seam (P-0, `g3-g7b-final-red.md` F-H1 points 2-3; DERIVED arm G5-D0).**
 *
 * {@see PermissionRequirement} is built from operation metadata alone, so it cannot reach a service. Everything that
 * needs a derived requirement's codes asks this service instead, through ONE wiring: a `ServiceLocator` of the services
 * tagged {@see self::RESOLVER_TAG} -- every {@see DerivedPermission}, tagged by the interface itself.
 *
 * **G5-D0 (2026-10-22, `g5-g6-final-red.md` H-1 fix 1, M-1, L-4; design Revision 1 FINAL + M-3):**
 * - {@see self::possibleCodes()} DERIVED = the resolver's `codesByResource()` values, de-duplicated and sorted.
 * - {@see self::demandedBy()} DERIVED = the ONE code the request's body names. It decodes only the body's top-level
 *   `resource` string -- never an id -- so the gate takes exactly one decision before any row is read (E-1, E-14). A body
 *   it cannot read, a missing, non-string or unknown `resource` is a 422 here, ahead of the 403 (precedence
 *   401 → 422 → 403 → 415 / 428 → body 422; content negotiation runs in the controller, after the gate).
 *   The resolved pair is stored as the request attribute {@see self::REQUEST_ATTRIBUTE}; the operation's processor acts
 *   on it through {@see self::resolvedFor()}, which is a 500 if it disagrees with the DTO.
 * - A denial of a derived operation is recorded by the gate exactly as any other: a derived operation's path names no id,
 *   so the row is `(null, null)` with `permission_code` = the resolved code, never the sentinel (M-1;
 *   `DenialSubjectTest::aDerivedDenialNamesTheResolvedCode`).
 *
 * Note the semantics a caller must not confuse (F-H1): for ALL_OF, `possibleCodes()` is a CONJUNCTION -- every code is
 * required. For DERIVED it is a DISJUNCTION -- exactly one is required per request. A consumer that wants a requirement
 * SET (the margin derivation) must match on `kind()` and never take the union.
 */
final readonly class PermissionRequirements
{
    /** The tag a derived-permission resolver carries (G5-D0); {@see DerivedPermission} carries it for every implementation. */
    public const RESOLVER_TAG = 'app.derived_permission';

    /** The request attribute holding a derived operation's resolved `['resource' => …, 'code' => …]` (design M-3). */
    public const REQUEST_ATTRIBUTE = '_derived_permission';

    /** The one body field a derived operation's permission is resolved from (`API.md` §4.2, "The three `*(derived)*` permissions"). */
    public const RESOURCE_FIELD = 'resource';

    /** §3's `<resource>.<action>` shape (PC-1) -- a resolver's codes are checked against it, as a declaration's are. */
    private const CODE_SHAPE = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    public function __construct(
        /**
         * Typed as a provider, not a bare PSR container, so `DerivedPermissionWiringTest` can list `getProvidedServices()`
         * (every DERIVED operation names a resolver the locator holds, and the locator holds none that nothing names).
         *
         * @var ServiceProviderInterface<object>
         */
        #[AutowireLocator(self::RESOLVER_TAG)]
        private ServiceProviderInterface $resolvers,
    ) {
    }

    /**
     * Every code the requirement can demand: NONE `[]`, ALL_OF its codes, DERIVED its resolver's values (unique, sorted).
     * For catalogue checks and the rig's "holds everything" principal -- never as a requirement set.
     *
     * @return list<string>
     */
    public function possibleCodes(PermissionRequirement $requirement): array
    {
        return match ($requirement->kind()) {
            PermissionRequirement::NONE => [],
            PermissionRequirement::ALL_OF => $requirement->codes(),
            default => self::sortedUnique(array_values($this->codesByResource($requirement))),
        };
    }

    /**
     * The codes THIS request must hold, all of them, in the order the 403 names the first missing one: NONE `[]`,
     * ALL_OF its codes, DERIVED the one code its body's `resource` resolves to. The gate's one question.
     *
     * @return list<string>
     */
    public function demandedBy(PermissionRequirement $requirement, Request $request): array
    {
        return match ($requirement->kind()) {
            PermissionRequirement::NONE => [],
            PermissionRequirement::ALL_OF => $requirement->codes(),
            default => [$this->resolve($requirement, $request)],
        };
    }

    /**
     * A DERIVED requirement's whole map, keys byte-sorted (L-4: a sweep drives EVERY key, in a defined order), checked
     * against {@see DerivedPermission}'s contract. LogicException for any other kind, an unwired resolver id, a service
     * that is not a {@see DerivedPermission}, or a map outside the contract.
     *
     * @return array<string, string>
     */
    public function codesByResource(PermissionRequirement $requirement): array
    {
        $map = $this->resolverFor($requirement)->codesByResource();

        if ([] === $map) {
            throw new \LogicException(\sprintf('The derived-permission resolver "%s" resolves no resource.', $requirement->resolver()));
        }

        foreach ($map as $resource => $code) {
            if (!\is_string($resource) || '' === $resource || !\is_string($code) || 1 !== preg_match(self::CODE_SHAPE, $code)) {
                throw new \LogicException(\sprintf(
                    'The derived-permission resolver "%s" maps %s to %s; a resolver maps a non-empty resource name to one '
                    .'§3-shaped code, never to the derived sentinel.',
                    $requirement->resolver(),
                    var_export($resource, true),
                    var_export($code, true),
                ));
            }
        }

        ksort($map, \SORT_STRING);

        return $map;
    }

    /**
     * The service ids the locator provides, sorted. For `DerivedPermissionWiringTest`'s locator ↔ operations check.
     *
     * @return list<string>
     */
    public function resolverIds(): array
    {
        return self::sortedUnique(array_map('strval', array_keys($this->resolvers->getProvidedServices())));
    }

    /**
     * **The processor's side of M-3:** the code the gate resolved for this request, provided the DTO's `resource` is the
     * one the gate resolved it from. Absent (the gate did not run, or the operation is not derived) or different (the
     * body was read two ways) is a LogicException -- a 500, never an action taken under a code nobody checked.
     */
    public static function resolvedFor(Request $request, string $resource): string
    {
        $resolved = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (!\is_array($resolved) || !\is_string($resolved['resource'] ?? null) || !\is_string($resolved['code'] ?? null)) {
            throw new \LogicException('No derived permission was resolved for this request, so nothing may act on its resource.');
        }

        if ($resolved['resource'] !== $resource) {
            throw new \LogicException(\sprintf(
                'The permission was resolved for resource "%s" and the request is acting on "%s".',
                $resolved['resource'],
                $resource,
            ));
        }

        return $resolved['code'];
    }

    private function resolve(PermissionRequirement $requirement, Request $request): string
    {
        $resolver = $this->resolverFor($requirement);
        $map = $this->codesByResource($requirement);

        // Object mode, so `[]` (a list) and `{}` (an object) stay distinguishable; a duplicate key resolves to its LAST
        // value, exactly as the deserializer's own `json_decode` does, and the processor's resolvedFor() holds the two
        // equal. A UTF-8 BOM is not JSON to json_decode either, so both refuse it the same way. One stated divergence
        // (`g5-d0-red.md` L-3): a property name starting with "\u0000" cannot be an object property, so it is
        // `malformed_body` here, where the deserializer (associative) would read the key and refuse a missing field --
        // a 422 either way. The body is decoded whatever its Content-Type: content negotiation (415) runs in the
        // controller, after this listener, so for a derived operation the order is 401 → 422 → 403 → 415 / 428 (L-2).
        try {
            $body = json_decode($request->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiProblem::validationFailed([self::error('', 'malformed_body', FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE)]);
        }

        if (!$body instanceof \stdClass) {
            throw ApiProblem::validationFailed([self::error('', 'not_an_object', 'The request body must be a JSON object.')]);
        }

        $fields = get_object_vars($body);

        if (!\array_key_exists(self::RESOURCE_FIELD, $fields)) {
            throw ApiProblem::validationFailed([self::error('/'.self::RESOURCE_FIELD, 'required', 'Say which kind of record this is for.')]);
        }

        $resource = $fields[self::RESOURCE_FIELD];

        if (!\is_string($resource)) {
            throw ApiProblem::validationFailed([self::error('/'.self::RESOURCE_FIELD, 'not_a_string', 'The kind of record must be given as text.')]);
        }

        if (!\array_key_exists($resource, $map)) {
            throw ApiProblem::validationFailed([self::error('/'.self::RESOURCE_FIELD, 'unknown_resource', $resolver->unknownResourceMessage())]);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, ['resource' => $resource, 'code' => $map[$resource]]);

        return $map[$resource];
    }

    private function resolverFor(PermissionRequirement $requirement): DerivedPermission
    {
        $id = $requirement->resolver();

        if (!$this->resolvers->has($id)) {
            throw new \LogicException(\sprintf(
                'A derived permission names resolver "%s", which no service tagged "%s" provides. A derived operation fails '
                .'closed until its resolver is wired.',
                $id,
                self::RESOLVER_TAG,
            ));
        }

        $resolver = $this->resolvers->get($id);

        if (!$resolver instanceof DerivedPermission) {
            throw new \LogicException(\sprintf('The service "%s" is tagged "%s" and is not a %s.', $id, self::RESOLVER_TAG, DerivedPermission::class));
        }

        return $resolver;
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $pointer, string $code, string $message): array
    {
        return ['pointer' => $pointer, 'code' => $code, 'message' => $message];
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private static function sortedUnique(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        sort($codes, \SORT_STRING);

        return $codes;
    }
}
