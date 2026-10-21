<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * **The container-aware half of the permission seam (P-0, `g3-g7b-final-red.md` F-H1 points 2-3).**
 *
 * {@see PermissionRequirement} is built from operation metadata alone, so it cannot reach a service. Everything that
 * needs a derived requirement's codes asks this service instead, through ONE wiring: a `ServiceLocator` of the services
 * tagged {@see self::RESOLVER_TAG}. P-0 tags nothing, so the locator is empty and both DERIVED arms below throw.
 *
 * **What G5-D0 adds, and nothing else (`g5-g6-final-red.md` H-1 fix 1):** the `DerivedPermission` interface, its
 * instance method `codesByResource(): array<string,string>`, resolvers tagged {@see self::RESOLVER_TAG}, and the two
 * DERIVED arms -- {@see self::possibleCodes()} returns `array_values(codesByResource())` de-duplicated and sorted, and
 * {@see self::demandedBy()} resolves the one code the request names. No signature here changes, and no consumer does.
 *
 * Note the semantics a caller must not confuse (F-H1): for ALL_OF, `possibleCodes()` is a CONJUNCTION -- every code is
 * required. For DERIVED it will be a DISJUNCTION -- exactly one is required per request. A consumer that wants a
 * requirement SET (the margin derivation) must match on `kind()` and never take the union.
 */
final readonly class PermissionRequirements
{
    /** The tag a derived-permission resolver carries (G5-D0). */
    public const RESOLVER_TAG = 'app.derived_permission';

    public function __construct(
        /**
         * Typed as a provider, not a bare PSR container, so G5-D0's two-way test can list `getProvidedServices()`
         * (every DERIVED operation names a resolver the locator holds, and the locator holds none that nothing names).
         *
         * @var ServiceProviderInterface<object>
         */
        #[AutowireLocator(self::RESOLVER_TAG)]
        private ServiceProviderInterface $resolvers,
    ) {
    }

    /**
     * Every code the requirement can demand: NONE `[]`, ALL_OF its codes. For catalogue checks and the rig's
     * "holds everything" principal -- never as a requirement set.
     *
     * @return list<string>
     */
    public function possibleCodes(PermissionRequirement $requirement): array
    {
        return match ($requirement->kind()) {
            PermissionRequirement::NONE => [],
            PermissionRequirement::ALL_OF => $requirement->codes(),
            default => throw $this->derivedIsNotWired($requirement),
        };
    }

    /**
     * The codes THIS request must hold, all of them, in the order the 403 names the first missing one: NONE `[]`,
     * ALL_OF its codes. The gate's one question.
     *
     * G5-D0's DERIVED arm lands HERE, with no signature change (`g5-g6-final-red.md` H-1, M-3): it decodes the body's
     * top-level `resource`, raises the 422 for a missing or unknown one, stores the resolved `(resource, code)` as the
     * request attribute `_derived_permission`, and returns `[code]`.
     *
     * @return list<string>
     */
    public function demandedBy(PermissionRequirement $requirement, Request $request): array
    {
        return match ($requirement->kind()) {
            PermissionRequirement::NONE => [],
            PermissionRequirement::ALL_OF => $requirement->codes(),
            default => throw $this->derivedIsNotWired($requirement),
        };
    }

    private function derivedIsNotWired(PermissionRequirement $requirement): \LogicException
    {
        return new \LogicException(\sprintf(
            'A derived permission (resolver "%s"%s) cannot be resolved: G5-D0 adds the DERIVED arm to PermissionRequirements '
            .'through the "%s" locator. Until then a derived operation fails closed.',
            $requirement->resolver(),
            $this->resolvers->has($requirement->resolver()) ? '' : ', which no tagged service provides',
            self::RESOLVER_TAG,
        ));
    }
}
