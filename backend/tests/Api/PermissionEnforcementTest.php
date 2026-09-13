<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\UserManagement\Application\CurrentUserReader;
use App\Platform\Security\PermissionGate;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **PC-6 — *"the sidebar is not the control; every endpoint enforces
 * independently"*, driven over every endpoint there is.**
 *
 * The domain here is the **operation registry**, never a list. A `/warehouses`
 * slice added at Output 9 is covered by this file the day it is declared, and an
 * operation whose author forgot the check fails here rather than shipping.
 * `PermissionEnforcementContractTest` is the other half — it reconciles what the
 * registry declares against §4.2's `Perm` column, so "every operation enforces
 * what it declares" and "what it declares is what the contract says" are two
 * separate claims with two separate ways to go red.
 */
final class PermissionEnforcementTest extends ApiTestCase
{
    /**
     * The routes under `/api/v1` that answer without a token **and have been
     * looked at**, named individually because that is what "reviewed" means.
     *
     * Every one is API Platform's own: the OpenAPI document, the error and
     * validation-error response shapes, and the two `.well-known/genid` routes
     * the JSON-LD/Hydra formats mint identifiers on. None is an operation in
     * §4.2's table, none carries a `Perm` cell, and neither gate acts on any of
     * them. They are the subject of `API.md` §14.1 **API-O-20**, which is where
     * the decision about them lives; this list is what stops a *new* one joining
     * them in silence.
     *
     * @var list<string>
     */
    private const REVIEWED_UNAUTHENTICATED_ROUTES = [
        'api_doc',
        'api_genid',
        'api_validation_errors',
        '_api_errors',
        '_api_validation_errors_problem',
        '_api_validation_errors_hydra',
        '_api_validation_errors_jsonapi',
        '_api_validation_errors_xml',
        '_api_/.well-known/genid/{id}{._format}_get',
        // Added 2026-09-10 with the purchasing slice, and it is the same vendor
        // behaviour as `api_genid` above rather than a new class of route.
        // `PurchaseOrderLine` declares `PATCH` and `DELETE` at
        // `/purchase-orders/{id}/lines/{lineId}` and no item `GET` — which
        // §4.4.1's `AssignRole` paragraph rules legitimate — so API Platform's
        // `NotExposedOperationResourceMetadataCollectionFactory` mints an IRI
        // route at the pluralised default path. Its controller is
        // `api_platform.action.not_exposed`, which answers **404 for every
        // request, authenticated or not**, so it discloses nothing: no id
        // reaches a query and no row is read. `ApiConfigurationTest::
        // everyContractRouteIsUnderTheVersionedBasePath` asserts that this and
        // the genid route are the only two routes carrying that controller, and
        // `CreatedRowLocation` is what stops `Location` naming it.
        '_api_/purchase_order_lines/{id}{._format}_get',
    ];

    /**
     * Every operation that declares a permission code refuses a caller who holds
     * nothing, with §5.2's `permission-denied` naming the code it wanted.
     *
     * **Nothing is exempted and the loop is asserted to have had a subject**, so
     * a filter that quietly emptied the domain — the failure mode API-10a names —
     * fails rather than passing in silence.
     *
     * The write operations are driven **without** an `Idempotency-Key` and with an
     * empty body, which is a second claim inside the first: the 403 arrives ahead
     * of the 428 and ahead of validation, so a caller holding no permission learns
     * neither that the operation is idempotent nor which fields it accepts.
     *
     * *Mutation, run:* `PermissionGate`'s `$identity->holds()` branch inverted to
     * `return` unconditionally. Red on the first operation. *Second mutation,
     * run:* the `extraProperties` entry deleted from `UpdateItem`; the operation
     * leaves this loop's domain — and `PermissionEnforcementContractTest::
     * everyOperationDeclaresItsRequiredPermission` goes red, which is why that
     * check exists and why this one does not try to be it.
     */
    #[Test]
    public function everyOperationThatDeclaresAPermissionRefusesACallerWhoHoldsNothing(): void
    {
        $this->actAs(self::TENANT_A, []);

        $examined = 0;

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $required = $operation->getExtraProperties()[PermissionGate::OPERATION_PERMISSION] ?? null;

            if (!\is_string($required)) {
                continue;
            }

            ++$examined;

            $this->send(
                (string) $operation->getMethod(),
                $this->concretePathFor($operation),
                \in_array($operation->getMethod(), ['POST', 'PATCH', 'PUT'], true) ? [] : null,
            );

            self::assertSame(403, $this->httpStatus(),
                $name.' did not refuse a caller who holds no permission. Body: '
                .substr((string) $this->client()->getResponse()->getContent(), 0, 400));
            self::assertSame('urn:erp:problem:permission-denied', $this->json()['type'], $name);
            self::assertSame($required, $this->json()['required_permission'], $name);
        }

        self::assertGreaterThan(0, $examined,
            'No operation in the registry declares a permission code, so this loop asserted nothing.');
    }

    /**
     * The same loop from the other side: with the code held, the operation is no
     * longer refused **for lack of permission**.
     *
     * It deliberately does not assert a success status — an empty `POST` body is
     * a 422 and a `PATCH` at an invented id is a 404, and both are correct. What
     * it asserts is that the answer is no longer 403, which is the only thing the
     * permission grant is responsible for. Without this, the loop above is
     * satisfied by an API that refuses everybody.
     */
    #[Test]
    public function theSameOperationsStopRefusingOnceTheCodeIsHeld(): void
    {
        $this->actAs(self::TENANT_A, self::everyDeclaredPermission());

        $examined = 0;

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $required = $operation->getExtraProperties()[PermissionGate::OPERATION_PERMISSION] ?? null;

            if (!\is_string($required)) {
                continue;
            }

            ++$examined;

            $this->send(
                (string) $operation->getMethod(),
                $this->concretePathFor($operation),
                \in_array($operation->getMethod(), ['POST', 'PATCH', 'PUT'], true) ? [] : null,
                // The idempotent operations need their header here, or the 428
                // would mask what this is asking about.
                true === ($operation->getExtraProperties()['idempotency_key_required'] ?? null)
                    ? ['Idempotency-Key' => Uuid::v4()->toRfc4122()]
                    : [],
            );

            self::assertNotSame(403, $this->httpStatus(),
                $name.' still refuses a caller who holds '.$required.'. Body: '
                .substr((string) $this->client()->getResponse()->getContent(), 0, 400));
        }

        self::assertGreaterThan(0, $examined, 'The loop had no subject.');
    }

    /**
     * **The loop between the two above: every code but the one required is not
     * enough.** Five-calls follow-up, owner ruling on call 2 (2026-09-13).
     *
     * The two loops above hold **nothing** and **everything**, and a gate that
     * matched too loosely passes both: holding nothing matches nothing, and
     * holding everything matches the right code anyway. So a `holds()` that
     * accepted `purchase_order.approve` from a caller holding
     * `purchase_order.create` — a prefix match, a module match, a `str_contains`
     * — shipped green. With no role-granting path in production, the permission
     * gate is the only separation between codes there is, which is what makes
     * this the check to have.
     *
     * Here each operation is driven by a fresh principal holding **every declared
     * code except that operation's own**, and must still be refused with that code
     * named. The operation set and the code set are derived exactly as the two
     * loops above derive them — the registry and `everyDeclaredPermission()` —
     * and nothing is restated.
     *
     * The mutation below is only evidence if the fixture can express it: a
     * module-prefix match can only be caught on an operation whose module
     * declares a second code. The loop counts those and requires at least one,
     * so a registry where every module had exactly one code would fail here
     * rather than pass vacuously.
     *
     * *Mutation, run 2026-09-13:* `Identity::holds()` rewritten to match on the
     * module prefix (the text before the first `.`). Red here — and both loops
     * above stay green under it, which is the proof the gap was real **in the
     * loops**. Run against the whole suite it is red in exactly one other place:
     * `theSetTheApiEnforcesIsTheSetMeAdvertises`, whose principal holds
     * `inventory_item.edit` and expects `DELETE /items` refused. *Second mutation,
     * run the same day:* match on the action suffix instead (`supplier.view`
     * satisfies `inventory_item.view`); whole suite red here and in exactly one
     * other place, `GoodsReceiptCreateTest::aCallerWithoutTheReceiptCodeIsRefused`.
     * So the gap was not "nothing can see a loose match" — each loose match was
     * caught by **one hand-picked pair elsewhere, by accident of its fixture**,
     * and a different loose match could miss both. What this loop adds is the
     * domain: every operation, each against every sibling code, derived.
     */
    #[Test]
    public function everyOperationRefusesACallerWhoHoldsEveryCodeExceptTheOneItRequires(): void
    {
        $everyCode = self::everyDeclaredPermission();

        $examined = 0;
        $aSiblingCodeWasHeld = 0;

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $required = $operation->getExtraProperties()[PermissionGate::OPERATION_PERMISSION] ?? null;

            if (!\is_string($required)) {
                continue;
            }

            ++$examined;

            $held = array_values(array_diff($everyCode, [$required]));
            $module = strstr($required, '.', true);

            foreach ($held as $code) {
                if (false !== $module && str_starts_with($code, $module.'.')) {
                    ++$aSiblingCodeWasHeld;

                    break;
                }
            }

            $this->actAs(self::TENANT_A, $held);

            $this->send(
                (string) $operation->getMethod(),
                $this->concretePathFor($operation),
                \in_array($operation->getMethod(), ['POST', 'PATCH', 'PUT'], true) ? [] : null,
            );

            self::assertSame(403, $this->httpStatus(),
                $name.' did not refuse a caller who holds every declared code except '.$required.'. Body: '
                .substr((string) $this->client()->getResponse()->getContent(), 0, 400));
            self::assertSame('urn:erp:problem:permission-denied', $this->json()['type'], $name);
            self::assertSame($required, $this->json()['required_permission'], $name);
        }

        self::assertGreaterThan(0, $examined,
            'No operation in the registry declares a permission code, so this loop asserted nothing.');
        self::assertGreaterThan(0, $aSiblingCodeWasHeld,
            'No operation\'s module declares a second code, so no caller here held a sibling of the code it lacked '
            .'and a loosely-matching holds() could not have been caught.');
    }

    /**
     * §14.2 item 25's first half — the request-level authentication gate.
     *
     * Every operation outside `/auth/*` refuses a request with no token, and it
     * refuses it **before** the permission check: a caller who cannot
     * authenticate must not learn that a permission exists, and 401 is what §4.2's
     * path rule requires (*"everything else requires a valid token"*).
     *
     * *Mutation, run:* `AuthenticationGate::PRIORITY` lowered to 16, behind
     * `PermissionGate`. The unauthenticated request then reaches a gate with no
     * identity and raises the wiring `LogicException` — a 500 rather than a 401 —
     * and this is red on every operation that declares a code.
     *
     * *Second mutation, run:* `isPreAuthentication()` changed to return `true`
     * unconditionally. Every operation stops requiring a token and this is red;
     * `CurrentUserTest::everyTokenFailureIsTheSameRefusal` is red too.
     */
    #[Test]
    public function everyOperationOutsideAuthRefusesARequestCarryingNoToken(): void
    {
        $this->actAsNobody();

        $authenticated = 0;
        $preAuth = 0;

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = $this->pathWithoutBase($operation);
            $body = \in_array($operation->getMethod(), ['POST', 'PATCH', 'PUT'], true) ? [] : null;

            $this->send((string) $operation->getMethod(), $this->concretePathFor($operation), $body);

            if (str_starts_with($path, '/auth/')) {
                ++$preAuth;
                self::assertNotSame(401, $this->httpStatus(),
                    $name.' is under /auth/ and §4.2 makes it pre-authentication, but it demanded a token.');

                continue;
            }

            ++$authenticated;
            self::assertSame(401, $this->httpStatus(),
                $name.' answered a request with no token. Body: '
                .substr((string) $this->client()->getResponse()->getContent(), 0, 400));
            self::assertSame('urn:erp:problem:authentication-failed', $this->json()['type'], $name);
        }

        self::assertGreaterThan(0, $authenticated, 'No authenticated operation was examined.');
        self::assertGreaterThan(0, $preAuth,
            'No pre-authentication operation was examined, so the /auth/ branch of the rule was never exercised.');
    }

    /**
     * **The domain here is the ROUTER, and that is the whole point of the method.**
     *
     * `everyOperationOutsideAuthRefusesARequestCarryingNoToken` above claims a
     * property about *"every operation outside `/auth`"* and derives its domain
     * from `TransactionBoundaryTest::contractOperations()`, which keeps only
     * `App\` resource classes and drops every `_api_`-prefixed name. That is the
     * right domain for a claim about §4.2's table and the **wrong** one for a
     * claim about what answers under `/api/v1`: it is a domain restated smaller
     * than the property the name asserts, and the routes it cannot see are
     * exactly the ones neither gate acts on — `CurrentOperation::of()` returns
     * `null` for them (no `_api_operation_name`), so both gates return without
     * acting.
     *
     * So this method takes its domain from `RouterInterface::getRouteCollection()`
     * — everything that answers, whoever declared it — and requires every path
     * under `%app.api_base_path%` to be one of three things:
     *
     *   1. an operation in the registry (the set the loops above cover);
     *   2. under `/auth/`, which §4.2 makes pre-authentication;
     *   3. on `REVIEWED_UNAUTHENTICATED_ROUTES` — an explicit list of routes that
     *      have been looked at and accepted as reachable without a token.
     *
     * **The exemption list is pinned by cardinality and reconciled both ways**, so
     * a new exemption cannot appear silently: a route that leaves the router fails
     * the reconciliation, and a route that arrives fails clause (3) by not being
     * on the list. Neither can be quieted by editing one side.
     *
     * **What (3) currently costs is recorded, not hidden — `API.md` §14.1
     * API-O-20.** Measured on this server rather than carried: `GET /api/v1/docs`
     * under an `Accept` header that admits any media type answers **200** with 12,078 bytes of
     * `application/vnd.openapi+json` and no token — the whole spec, advertising
     * every path — and `GET /api/v1/errors/500` answers **200** unauthenticated.
     * No tenant data is reachable through any of them today, which is why this is
     * a residual and not a refusal.
     *
     * This method asserts the **domain**, not the status: what closes API-O-20 is
     * a decision about those routes, and a status assertion written now would pin
     * today's behaviour as if it had been decided.
     *
     * *Mutation, run:* a new unauthenticated route declared under the base path
     * (`config/routes.yaml`, `GET /api/v1/probe`). This method names it and is
     * red — **and every other test in this file stays green**, including
     * `everyOperationOutsideAuthRefusesARequestCarryingNoToken`, which is the gap
     * this exists to close.
     *
     * *Second mutation, run:* `_api_validation_errors_xml` removed from
     * `REVIEWED_UNAUTHENTICATED_ROUTES`; red, at the unclassified clause — the
     * route is still in the router and nothing now says it was reviewed.
     *
     * *Third mutation, run:* a name added to the list that no route carries; red
     * at the reconciliation, which is the direction the other two cannot reach.
     * A stale exemption is a route somebody reviewed and that no longer exists,
     * and it is how a list stops describing the thing it exempts.
     */
    #[Test]
    public function everyRouteUnderTheApiBasePathIsAnOperationAPreAuthPathOrAReviewedExemption(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        $base = (string) self::getContainer()->getParameter('app.api_base_path');
        $operations = TransactionBoundaryTest::contractOperations();

        $underTheBasePath = [];
        $unclassified = [];
        $exempt = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            if (!str_starts_with($path, $base)) {
                continue;
            }

            $underTheBasePath[(string) $name] = $path;

            if (\array_key_exists((string) $name, $operations)) {
                continue;
            }

            if (str_starts_with(substr($path, \strlen($base)), '/auth/')) {
                continue;
            }

            if (\in_array((string) $name, self::REVIEWED_UNAUTHENTICATED_ROUTES, true)) {
                $exempt[] = (string) $name;

                continue;
            }

            $unclassified[(string) $name] = $path;
        }

        self::assertNotSame([], $underTheBasePath,
            'The router declares no route under '.$base.', so this check had no subject.');

        self::assertSame([], $unclassified,
            "A route answers under $base and is neither an operation in the registry, nor a pre-authentication "
            ."path, nor a reviewed exemption. Neither gate acts on a route the registry does not know — "
            ."`CurrentOperation::of()` returns null — so this one is reachable with no token and nobody "
            ."decided that:\n".print_r($unclassified, true));

        // The pin, both ways. The list is a record of what was reviewed, so a
        // member that no longer exists is as much a defect as one that appeared.
        sort($exempt);
        $declared = self::REVIEWED_UNAUTHENTICATED_ROUTES;
        sort($declared);

        self::assertSame($declared, $exempt,
            'The reviewed-exemption list and the router disagree. Every entry is a route somebody looked at '
            .'under API-O-20; a route that has left the router is a stale exemption, and one that has arrived '
            .'is an unreviewed one.');
        self::assertCount(\count(self::REVIEWED_UNAUTHENTICATED_ROUTES), $exempt);
    }

    /**
     * **PC-6 at the boundary: the set the API enforces is the set `/me` publishes.**
     *
     * Both are read on one request pair against one principal. A sidebar rendered
     * from `/me` and an endpoint refusing a call cannot disagree, and the reason
     * they cannot is that `IdentityReader` and `CurrentUserReader` run the same
     * derivation — which is asserted here rather than inferred from the two
     * queries looking similar.
     *
     * *Mutation, run:* `IdentityReader`'s permission query changed to drop the
     * `role.deleted_at IS NULL` predicate; the deleted role's code is enforced but
     * not advertised, and this is red on the set comparison. `CurrentUserTest::
     * aDeletedRoleGrantsNothing` stays green throughout, which is the point:
     * before this test, a divergence between the two readers had nowhere to show.
     */
    #[Test]
    public function theSetTheApiEnforcesIsTheSetMeAdvertises(): void
    {
        // Sorted, because both readers `ORDER BY 1` and the comparison is about
        // the set's membership, not about an order neither of them promises.
        $granted = ['inventory_item.edit', 'inventory_item.view'];
        $this->actAs(self::TENANT_A, $granted);

        // A second role, then deleted: the two readers must agree about it too.
        $deadRole = Uuid::v4()->toRfc4122();
        $this->owner()->executeStatement(
            "INSERT INTO permission (code, module, description) VALUES ('inventory_item.delete', 'Fixture', 'x') ON CONFLICT (code) DO NOTHING");
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)', [$deadRole, self::TENANT_A, 'Gone']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $deadRole, 'inventory_item.delete']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, (string) $this->actorId, $deadRole]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$deadRole]);

        $this->send('GET', '/me');
        self::assertSame(200, $this->httpStatus());
        $advertised = $this->json()['permission_codes'];

        self::assertSame($granted, $advertised,
            'What /me publishes is not what was granted, so the comparison below would be about the wrong set.');

        // Enforced: the advertised codes pass, and the one on the deleted role
        // does not — decided by the gate, on a real request.
        $this->send('GET', '/items');
        self::assertSame(200, $this->httpStatus(), 'An advertised code was not enforced as held.');

        $this->send('DELETE', '/items/'.Uuid::v4()->toRfc4122());
        self::assertSame(403, $this->httpStatus(),
            'A code on a soft-deleted role was enforced as held while /me does not advertise it.');
        self::assertSame('inventory_item.delete', $this->json()['required_permission']);

        // And the two readers are still the two this claim is about, so a
        // refactor that gave one of them a second source fails here.
        self::assertSame(
            ['user_role' => 'hard', 'role_permission' => 'hard'],
            CurrentUserReader::joinedTablesRequiringNoDeletionPredicate(),
            'The tables both readers join without a deletion predicate changed class.');
        self::assertSame(
            CurrentUserReader::joinedTablesRequiringNoDeletionPredicate(),
            \App\Platform\Security\IdentityReader::joinedTablesRequiringNoDeletionPredicate(),
            'The two readers no longer make the same assumption about the same joins.');
    }

    /**
     * The tenant a request acts as is the **token's** claim, and no longer
     * anything the rig can set behind it.
     *
     * The rig mints a token for tenant B while the container still holds an
     * identity for tenant A. The rows that come back are B's, because
     * `AuthenticationGate` overwrites the holder before the controller runs.
     * Before the enforcement slice this was unwritable: `FixedTenant` answered
     * `tenantId()` and no request could contradict it.
     */
    #[Test]
    public function theTenantAResourceActsAsIsTheOneInsideTheToken(): void
    {
        $this->actAs(self::TENANT_A, ['inventory_item.view']);
        $this->seedItem(self::TENANT_A, 'SKU-A');

        $this->send('GET', '/items');
        self::assertCount(1, $this->json()['items']);

        // A principal in B, and the container's holder deliberately left pointing
        // at A until the request overwrites it.
        $inB = $this->seedPrincipal(self::TENANT_B, ['inventory_item.view']);
        $this->seedItem(self::TENANT_B, 'SKU-B1');
        $this->seedItem(self::TENANT_B, 'SKU-B2');

        $this->send('GET', '/items', null, ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_B, $inB)]);

        self::assertSame(200, $this->httpStatus());
        self::assertCount(2, $this->json()['items'],
            'The request was served in the tenant the container held rather than the one the token claimed.');
    }

    /**
     * The path a request can actually be sent to: the operation's own URI
     * template with each variable filled by a UUID that names nothing.
     *
     * An id that resolves to no row is the right probe for both loops above — the
     * 403 must not depend on the row existing (E-1), and the non-403 must not
     * depend on it either.
     */
    private function concretePathFor(HttpOperation $operation): string
    {
        return (string) preg_replace_callback(
            '/\{[^}]+\}/',
            static fn (): string => Uuid::v4()->toRfc4122(),
            $this->pathWithoutBase($operation),
        );
    }

    private function pathWithoutBase(HttpOperation $operation): string
    {
        $path = (string) $operation->getUriTemplate();

        return str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path;
    }
}
