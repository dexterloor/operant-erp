<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Application\CurrentUserReader;
use App\Modules\UserManagement\Infrastructure\AccessTokens;
use App\Platform\Tenancy\Entity\Tenant;
use App\Platform\Tenancy\SchemaManifest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/v1/me` — §4.2's `GetCurrentUser` and §4.4.3's `CurrentUser` row.
 *
 * ---------------------------------------------------------------------------
 * What these tests are, and what they are deliberately not
 * ---------------------------------------------------------------------------
 * `permission_codes` is **advisory**: nothing in this wave enforces a permission,
 * and PC-6's load-bearing half is *"the sidebar is not the control; every
 * endpoint enforces independently"*. So nothing here asserts that holding a code
 * grants access to anything — there is nothing to grant access to yet. What is
 * asserted is that the set is **what `role_permission` holds**, resolved on this
 * request, and the mutation that proves it is a row added to that table with **no
 * code edit**.
 */
final class CurrentUserTest extends AuthTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private const EMAIL = 'alice@example.com';

    /**
     * The whole shape §4.4.3 declares, and API-6/T-1's *"no response carries
     * `tenant_id`, at any depth"*.
     *
     * *Mutation, run:* add a `tenantId` property to `CurrentUserResource`; the
     * recursive walk finds it and this is red.
     */
    #[Test]
    public function meReturnsTheDeclaredShapeAndNoTenantIdentifier(): void
    {
        [$tenantId, $userId, $token] = $this->signedIn();
        $roleId = $this->seedRole($tenantId, 'Buyer', ['purchase_order.view', 'purchase_order.create']);
        $this->grant($tenantId, $userId, $roleId);

        $this->get('/me', $token);

        self::assertSame(200, $this->httpStatus());

        $body = $this->json();
        self::assertSame(['id', 'email', 'display_name', 'roles', 'permission_codes'], array_keys($body));
        self::assertSame($userId, $body['id']);
        self::assertSame(self::EMAIL, $body['email']);
        self::assertSame('Fixture User', $body['display_name']);
        self::assertSame([['id' => $roleId, 'name' => 'Buyer']], $body['roles'],
            'API-23b: a reference is expanded as {id, display field} and carries nothing else.');

        self::assertSame([], self::keysNamedLikeATenant($body),
            'A response carries a tenant identifier: '.implode(', ', self::keysNamedLikeATenant($body)));
    }

    /**
     * The derivation, pinned. The response is compared against what
     * `role_permission` **actually holds**, read back from the table rather than
     * restated, and then a row is added and the response changes.
     *
     * *Mutation, run:* replace `CurrentUserReader`'s permission query with a
     * constant list, or derive the set from the role's `name`. The first
     * assertion may still pass by luck on the seed; the second — *the response
     * changed after a row was inserted, with no code edit* — cannot, and is red.
     *
     * *Second mutation, run:* drop the `DISTINCT`; two roles sharing a code
     * return it twice and the set comparison is red.
     */
    #[Test]
    public function thePermissionSetIsWhatRolePermissionHolds(): void
    {
        [$tenantId, $userId, $token] = $this->signedIn();

        $buyer = $this->seedRole($tenantId, 'Buyer', ['purchase_order.view', 'purchase_order.create']);
        $viewer = $this->seedRole($tenantId, 'Viewer', ['purchase_order.view', 'inventory_item.view']);
        $this->grant($tenantId, $userId, $buyer);
        $this->grant($tenantId, $userId, $viewer);

        $this->get('/me', $token);
        self::assertSame(200, $this->httpStatus());

        $held = array_column($this->fetchAsOwnerForTenant($tenantId, <<<'SQL'
            SELECT DISTINCT rp.permission_code
            FROM user_role ur
            JOIN role_permission rp ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?::uuid
            ORDER BY 1
            SQL, [$userId]), 'permission_code');

        self::assertNotSame([], $held, 'The fixture granted nothing, so the comparison below is vacuous.');
        self::assertSame($held, $this->json()['permission_codes']);

        // The half a hardcoded set cannot survive: one row, no code edit.
        $this->seedPermission('supplier.view');
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
            [Uuid::v4()->toRfc4122(), $tenantId, $buyer, 'supplier.view']);

        $this->get('/me', $token);

        self::assertContains('supplier.view', $this->json()['permission_codes'],
            'A row added to role_permission did not change the response, so the set is not derived from it.');
    }

    /**
     * `role` is deletion class `soft`, so a deleted role grants nothing and
     * appears in no list. The two joined tables are class `hard` and carry no
     * `deleted_at`, which is why the query tests the predicate on `role` alone.
     *
     * *Mutation, run:* drop `AND r.deleted_at IS NULL` from both queries in
     * `CurrentUserReader`; the deleted role and its codes come back and this is
     * red on both halves.
     */
    #[Test]
    public function aDeletedRoleGrantsNothingAndAppearsInNoList(): void
    {
        [$tenantId, $userId, $token] = $this->signedIn();

        $live = $this->seedRole($tenantId, 'Buyer', ['purchase_order.view']);
        $gone = $this->seedRole($tenantId, 'Retired', ['supplier.view']);
        $this->grant($tenantId, $userId, $live);
        $this->grant($tenantId, $userId, $gone);

        $this->insertAsOwnerForTenant($tenantId, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$gone]);

        $this->get('/me', $token);

        self::assertSame(['purchase_order.view'], $this->json()['permission_codes']);
        self::assertSame([['id' => $live, 'name' => 'Buyer']], $this->json()['roles']);
    }

    /**
     * Guards the query above: it is correct only while `user_role` and
     * `role_permission` carry no `deleted_at` of their own.
     *
     * *Mutation, run:* change `user_role`'s manifest entry to
     * `SchemaManifest::SOFT`; red, and the reader needs a second predicate before
     * it can go green.
     */
    #[Test]
    public function theJoinedTablesAreStillHardDeleted(): void
    {
        self::assertSame(
            ['user_role' => SchemaManifest::HARD, 'role_permission' => SchemaManifest::HARD],
            CurrentUserReader::joinedTablesRequiringNoDeletionPredicate(),
        );
    }

    /**
     * §4.2: *"`/auth/*` is pre-authentication; everything else requires a valid
     * token."* Every failure is the same failure.
     *
     * *Mutation, run:* return the resource when the header is absent; the first
     * case is red. *Second mutation, run:* drop the algorithm argument from
     * `JWT::decode()`; a token signed `alg: none` is accepted and the
     * `unsigned` case is red.
     */
    #[Test]
    public function everyTokenFailureIsTheSameRefusal(): void
    {
        [, , $token] = $this->signedIn();

        $unsigned = implode('.', [
            rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '='),
            rtrim(strtr(base64_encode('{"sub":"'.Uuid::v4()->toRfc4122().'","tenant_id":"'.Uuid::v4()->toRfc4122().'","exp":'.(time() + 60).'}'), '+/', '-_'), '='),
            '',
        ]);

        $cases = [
            'absent' => null,
            'wrong scheme' => 'Basic '.base64_encode('a:b'),
            'malformed' => 'Bearer not-a-token',
            'tampered signature' => 'Bearer '.substr($token, 0, -3).'AAA',
            'unsigned' => 'Bearer '.$unsigned,
        ];

        $observed = [];

        foreach ($cases as $label => $header) {
            $this->get('/me', null, null === $header ? [] : ['Authorization' => $header]);
            $observed[$label] = [$this->httpStatus(), (string) $this->client()->getResponse()->getContent()];
        }

        self::assertCount(1, array_unique(array_map(json_encode(...), $observed)),
            "Token failures are distinguishable:\n".print_r($observed, true));
        self::assertSame(401, $observed['absent'][0]);
        self::assertStringContainsString('authentication-failed', $observed['absent'][1]);

        // Anti-vacuity: the same request with the real token is a 200.
        $this->get('/me', $token);
        self::assertSame(200, $this->httpStatus());
    }

    /**
     * A soft-deleted principal holding a live token stops being able to read
     * `/me`. There is no revocation (API-O-13) and this is not one — it is the
     * read applying `deleted_at IS NULL`.
     *
     * *Mutation, run:* drop that predicate from the `app_user` read in
     * `CurrentUserReader`; red.
     */
    #[Test]
    public function aSoftDeletedPrincipalsLiveTokenNoLongerReads(): void
    {
        [$tenantId, $userId, $token] = $this->signedIn();

        $this->get('/me', $token);
        self::assertSame(200, $this->httpStatus());

        $this->insertAsOwnerForTenant($tenantId, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$userId]);

        $this->get('/me', $token);
        self::assertSame(401, $this->httpStatus());
    }

    /**
     * The claim is what selects the tenant, and RLS is what enforces it: a token
     * minted for tenant A cannot read a user id that lives in tenant B, even
     * though the id is real.
     *
     * *Mutation, run:* have `CurrentUserReader::read()` skip `TenantContext::run`
     * and query directly; the read returns nothing at all (no context, no rows)
     * — which fails closed, so the mutation that actually bites is passing the
     * *token's* tenant through while reading a foreign user id, and that is what
     * this drives.
     */
    #[Test]
    public function aTokenReadsOnlyInsideTheTenantItsClaimNames(): void
    {
        [$tenantA, , ] = $this->signedIn();

        $tenantB = $this->seedTenant(self::SLUG_PREFIX.'other', Tenant::STATUS_ACTIVE);
        $foreignUser = $this->seedUser($tenantB, 'bob@example.com', self::PASSWORD);

        $tokens = self::getContainer()->get('test.auth.access_tokens');
        \assert($tokens instanceof AccessTokens);

        // A perfectly valid signature over a pair that does not belong together.
        $forged = $tokens->issue($tenantA, $foreignUser, new \DateTimeImmutable())->jwt;

        $this->get('/me', $forged);
        self::assertSame(401, $this->httpStatus(), 'A user id from another tenant resolved under this tenant context.');
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $path, ?string $token, array $headers = []): void
    {
        if (null !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $this->send('GET', $path, null, $headers);
    }

    /** @return array{0: string, 1: string, 2: string} tenant id, user id, access token */
    private function signedIn(): array
    {
        $slug = self::SLUG_PREFIX.'me';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $userId = $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $this->signIn($slug, self::EMAIL, self::PASSWORD);
        self::assertSame(200, $this->httpStatus(), 'The fixture could not sign in, so every /me assertion would be about the wrong thing.');

        return [$tenantId, $userId, (string) $this->json()['access_token']];
    }

    /**
     * @param list<string> $permissionCodes
     *
     * @return string the new role's id
     */
    private function seedRole(string $tenantId, string $name, array $permissionCodes): string
    {
        $roleId = Uuid::v4()->toRfc4122();

        foreach ($permissionCodes as $code) {
            $this->seedPermission($code);
        }

        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$roleId, $tenantId, $name]);

        foreach ($permissionCodes as $code) {
            $this->insertAsOwnerForTenant($tenantId,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [Uuid::v4()->toRfc4122(), $tenantId, $roleId, $code]);
        }

        return $roleId;
    }

    /**
     * `permission` is a non-tenant reference table the migrations leave empty,
     * and `role_permission` carries a foreign key onto it. Seeding the codes a
     * fixture uses is therefore part of building the fixture, not part of the
     * claim: every assertion in this file compares the response against what
     * `role_permission` holds, never against this list.
     */
    private function seedPermission(string $code): void
    {
        $this->owner()->executeStatement(
            "INSERT INTO permission (code, module, description) VALUES (?, ?, '') ON CONFLICT (code) DO NOTHING",
            [$code, explode('.', $code)[0]],
        );
    }

    private function grant(string $tenantId, string $userId, string $roleId): void
    {
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [Uuid::v4()->toRfc4122(), $tenantId, $userId, $roleId]);
    }

    /**
     * Every key anywhere in the response whose name names a tenant, at any
     * nesting depth.
     *
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private static function keysNamedLikeATenant(array $body): array
    {
        $found = [];

        array_walk_recursive($body, static function (mixed $value, string|int $key) use (&$found): void {
            if (\in_array($key, ['tenant_id', 'tenantId'], true)) {
                $found[] = (string) $key;
            }
        });

        return $found;
    }
}
