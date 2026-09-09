<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Api\Security\PermissionGate;
use App\Auth\Application\CurrentUserReader;
use PHPUnit\Framework\Attributes\Test;
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
            \App\Api\Security\IdentityReader::joinedTablesRequiringNoDeletionPredicate(),
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
