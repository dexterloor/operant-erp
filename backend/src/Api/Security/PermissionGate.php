<?php

declare(strict_types=1);

namespace App\Api\Security;

use ApiPlatform\Metadata\HttpOperation;
use App\Api\Problem\ApiProblem;
use App\Audit\DenialAuditWriter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Uid\Uuid;

/**
 * **E-1 — the permission check runs before the row is loaded.**
 *
 * ===========================================================================
 * The ordering is structural, not intended
 * ===========================================================================
 * `api_platform.use_symfony_listeners` is **false** (the bundle's default, and
 * this project does not override it), so every provider and processor runs inside
 * `ApiPlatform\Symfony\Controller\MainController`. This listener is on
 * `kernel.controller`, which the HttpKernel dispatches *after* the controller is
 * resolved and *before* it is called. There is no ordering to get wrong: the row
 * cannot have been loaded, because the code that loads it has not run.
 *
 * Two checks hold that property down rather than describing it:
 *
 *   `AuthorizationOrderTest::theRowLoaderIsNotReachedOnADenial`
 *       records the `erp_app` connection across a denied request and asserts no
 *       statement mentions the resource's table. A voter moved behind the
 *       provider passes every response-shaped assertion and fails this one.
 *   `AuthorizationOrderTest::apiPlatformResolvesStateInsideTheControllerNotAListener`
 *       asserts the container parameter is still `false`. Flipping it moves the
 *       row load to a `kernel.request` listener at priority 4 — *ahead* of every
 *       `kernel.controller` listener — and E-1 would be silently gone with no
 *       other test changing colour.
 *
 * ===========================================================================
 * What E-1 buys, and why it is the whole point
 * ===========================================================================
 * Load the row first and a caller holding no permission gets 403 for an id inside
 * their tenant and 404 for one outside it — an **intra-tenant existence oracle**
 * for someone with no permission to know the id exists. Checking first makes all
 * three of "real id here", "real id elsewhere" and "id that exists nowhere"
 * byte-identical, which is what
 * `AuthorizationOrderTest::aCallerWithoutThePermissionGetsTheSameStatusForEveryId`
 * asserts on the wire.
 *
 * §5.3's remaining clauses are unchanged by this class and are already where they
 * belong: the row is then loaded under RLS and a row RLS does not return is
 * `not-found` (`ItemProvider`); once the caller holds `.view` and the row is on
 * screen, a state-machine refusal is `business-rule-violated`
 * (`FrameworkProblemTranslator`'s `IllegalTransition` arm), and a
 * separation-of-duty refusal is too (E-19).
 *
 * ===========================================================================
 * The domain is the operation registry, and an undeclared operation fails closed
 * ===========================================================================
 * §4.2's `Perm` column lives on the operation as an extra property, exactly as its
 * `Idem` column does. The key must be **present** on every operation: an absent
 * key is a `LogicException` and a 500, never a pass. That is the difference
 * between a gate and a decoration — an operation added tomorrow without a `Perm`
 * declaration fails loudly instead of shipping unchecked, and
 * `PermissionEnforcementContractTest::everyOperationDeclaresItsRequiredPermission`
 * turns the 500 into a red test before anybody deploys it.
 *
 * `null` is §4.2's `—`: authenticated, no code. It is a *declaration*, not the
 * absence of one.
 *
 * ===========================================================================
 * E-14 — a bulk action is one decision over a set
 * ===========================================================================
 * *"The permission is checked once, on the action; a caller lacking it is refused
 * wholesale before any id is examined."* That is what this class does for every
 * operation, single-row and bulk alike, because the decision is taken from the
 * operation and the caller and never from the request body. No bulk operation
 * ships this wave — §4.2's three are blocked on API-O-1's key store — so E-14 has
 * no subject to be tested against yet; what it will inherit is a gate that
 * already cannot see an id list.
 */
#[AsEventListener(event: ControllerEvent::class, priority: self::PRIORITY)]
final readonly class PermissionGate
{
    /** Behind `AuthenticationGate` (32) and ahead of `IdempotencyKeyGuard` (0). 401 → 403 → 428. */
    public const PRIORITY = 24;

    /**
     * The extra property an operation carries to declare §4.2's `Perm` cell.
     *
     * A permission code, or `null` for the column's `—`. The key itself is
     * mandatory; see the class docblock.
     */
    public const OPERATION_PERMISSION = 'required_permission';

    public function __construct(
        private CurrentOperation $operations,
        private RequestIdentity $identities,
        private DenialAuditWriter $denials,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $operation = $this->operations->of($event->getRequest());

        if (!$operation instanceof HttpOperation) {
            return;
        }

        $extra = $operation->getExtraProperties();

        if (!\array_key_exists(self::OPERATION_PERMISSION, $extra)) {
            throw new \LogicException(\sprintf(
                'Operation "%s" declares no "%s" extra property. Every operation must declare §4.2\'s '
                .'Perm cell — a permission code, or null for the column\'s "—". An operation that '
                .'declares nothing is an operation nobody decided about, and this gate refuses to '
                .'guess in the direction that ships it unchecked.',
                (string) $operation->getName(),
                self::OPERATION_PERMISSION,
            ));
        }

        $required = $extra[self::OPERATION_PERMISSION];

        if (null === $required) {
            return;
        }

        \assert(\is_string($required));

        $identity = $this->identities->current();

        if (null === $identity) {
            // An operation on a pre-authentication path that also declares a
            // permission code. §4.2 has no such row and this contract could not
            // express one: there is no principal to hold a code. It is a wiring
            // error, and it fails closed and loudly rather than as a 403 that
            // would imply somebody was asked.
            throw new \LogicException(\sprintf(
                'Operation "%s" requires "%s" but no identity was resolved. A pre-authentication '
                .'operation cannot require a permission code.',
                (string) $operation->getName(),
                $required,
            ));
        }

        if ($identity->holds($required)) {
            return;
        }

        $this->denials->record(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            permissionCode: $required,
            subjectId: $this->subjectIdIn($event->getRequest()),
            ipAddress: $event->getRequest()->getClientIp(),
        );

        throw ApiProblem::permissionDenied($required);
    }

    /**
     * The resource id the request named, taken from the **route's own**
     * variables rather than from a parameter name written down here.
     *
     * `_route_params` is what the router matched for this route and nothing else,
     * so a path with no id contributes none and a path whose variable is called
     * something other than `id` still contributes one. It is caller-supplied and
     * deliberately unresolved: E-1 has not loaded anything, and E-3a's cap is
     * keyed so that this value cannot widen it.
     */
    private function subjectIdIn(Request $request): ?string
    {
        $parameters = $request->attributes->get('_route_params');

        if (!\is_array($parameters)) {
            return null;
        }

        foreach ($parameters as $value) {
            if (\is_string($value) && Uuid::isValid($value)) {
                return $value;
            }
        }

        return null;
    }
}
