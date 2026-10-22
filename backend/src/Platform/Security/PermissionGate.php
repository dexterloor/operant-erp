<?php

declare(strict_types=1);

namespace App\Platform\Security;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\AuditLog\Infrastructure\DenialAuditWriter;
use App\Platform\Http\Problem\ApiProblem;
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
 * operation and the caller and never from an id in the request body. *(G5-D0,
 * 2026-10-22: a derived operation's one code is resolved from the body's
 * top-level `resource` -- a kind of record, not an id -- by
 * `PermissionRequirements`, which reads no other field and no row;
 * `DerivedPermissionTest::oneDecisionBeforeAnyIdIsRead`.)* No bulk operation
 * ships yet, so E-14 is tested against a planted derived operation; what the
 * bulk operations inherit is a gate that cannot see an id list.
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

    /**
     * **P-0 (2026-10-22): the sentinel an operation declares as its {@see self::OPERATION_PERMISSION} when its one code is
     * resolved from the request** (G5-D0, `g5-g6-bulk-export-design.md` §5.1a). Never a real code: it is not §3-shaped, so
     * no principal can hold it. It is only meaningful beside {@see self::DERIVED_PERMISSION}. Parsed by
     * {@see PermissionRequirement}; resolved since G5-D0 (2026-10-22) by {@see PermissionRequirements::demandedBy()} into
     * the ONE code the body's `resource` names, so this gate still takes exactly one decision per request and its denial row
     * names that code -- never this sentinel -- with subject `(null, null)` (a derived path names no id).
     */
    public const DERIVED = '(derived)';

    /** P-0: the extra property naming a derived requirement's resolver service id -- a {@see DerivedPermission} (G5-D0). */
    public const DERIVED_PERMISSION = 'derived_permission';

    /**
     * **F-1 (2026-09-29): the extra property an operation carries when its denial's subject is NOT the permission's
     * resource** — `[<table>, <route variable>]`. Optional; absent means the default derivation below, which is right
     * for every path whose first id addresses the permission's own resource (`/items/{id}` under
     * `inventory_item.view`, and the line paths, whose first id is the order the permission names).
     *
     * The rule it serves (`docs/session13/roles-s2-spar.md` P16): *`subject_type` is the type of the resource the
     * recorded path variable addresses; the id stays unresolved (E-1); where no path variable addresses a resource of a
     * known table, neither half is written.* A nested create or read whose parent is a different resource — a receipt
     * under an order — must declare it, or its denial row names a type and an id from two different rows (the shipped
     * defect: `(goods_receipt, <purchase order id>)`). `DenialSubjectTest` derives the expectation from the registry,
     * never from this declaration, so the two are independent.
     */
    public const OPERATION_DENIAL_SUBJECT = 'denial_subject';

    public function __construct(
        private CurrentOperation $operations,
        private RequestIdentity $identities,
        private DenialAuditWriter $denials,
        private PermissionRequirements $requirements,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $operation = $this->operations->of($event->getRequest());

        if (!$operation instanceof HttpOperation) {
            return;
        }

        // P-0: the declaration is read in ONE place. An absent key is still a LogicException with the same text, and a
        // malformed one is too; the kinds are matched inside PermissionRequirements, never here. G5-D0: a derived
        // operation's 422 (unreadable body, missing/unknown `resource`) is raised there, before the decision below.
        $required = $this->requirements->demandedBy(PermissionRequirement::of($operation), $event->getRequest());

        if ([] === $required) {
            return;
        }

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
                implode('", "', $required),
            ));
        }

        // All of them, in byte order: the refusal names the FIRST missing code (F-L1, F-L7 -- a caller lacking several
        // learns them one at a time). With one code this is exactly the old single decision.
        $missing = null;
        foreach ($required as $code) {
            if (!$identity->holds($code)) {
                $missing = $code;

                break;
            }
        }

        if (null === $missing) {
            return;
        }

        [$subjectType, $subjectId] = self::denialSubjectOf($operation, $event->getRequest());

        $this->denials->record(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            permissionCode: $missing,
            subjectId: $subjectId,
            ipAddress: $event->getRequest()->getClientIp(),
            subjectType: $subjectType,
        );

        throw ApiProblem::permissionDenied($missing);
    }

    /**
     * **F-1:** the operation's declared denial subject, `[table, the value of its route variable]`, or — when none is
     * declared — `[null, the first uuid route parameter]`, leaving the type to `DenialAuditWriter`'s derivation from the
     * permission code (the default, unchanged). The id is never resolved here (E-1). A malformed declaration is a wiring
     * error and fails loudly, as a missing permission declaration does.
     *
     * **Public, and the ONE resolver** (roles slice 2, C2; `roles-s2-sec.md` Acknowledgement B.3 item 4): a permission
     * found missing UNDER A LOCK (`RevokeRole`'s re-check, S-2) is a denial too, and its row must name exactly the pair
     * this gate's row would name for the same path — so the lock-time writer asks this method rather than hand-writing
     * `'app_user'`. `RoleRevokeTest` holds the two rows equal for one path; hard-coding a type there goes red.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function denialSubjectOf(HttpOperation $operation, Request $request): array
    {
        $extra = $operation->getExtraProperties();

        if (!\array_key_exists(self::OPERATION_DENIAL_SUBJECT, $extra)) {
            return [null, self::subjectIdIn($request)];
        }

        $declared = $extra[self::OPERATION_DENIAL_SUBJECT];

        if (!\is_array($declared) || [0, 1] !== array_keys($declared) || !\is_string($declared[0]) || !\is_string($declared[1])) {
            throw new \LogicException(\sprintf(
                'Operation "%s" declares "%s" as something other than [<table>, <route variable>].',
                (string) $operation->getName(),
                self::OPERATION_DENIAL_SUBJECT,
            ));
        }

        $parameters = $request->attributes->get('_route_params');
        $value = \is_array($parameters) ? ($parameters[$declared[1]] ?? null) : null;

        return [$declared[0], \is_string($value) ? $value : null];
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
    private static function subjectIdIn(Request $request): ?string
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
