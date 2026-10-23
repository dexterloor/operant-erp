<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\ApiResource\UserResource;
use App\Modules\UserManagement\Domain\DefaultRoles;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Users slice 1 — `GetUser` (C1) and `ListUsers` (C2)** (`docs/session13/users-s1-design.md` rev 3 §2.1, §2.3, §2.4,
 * §8; `SEC` `users-s1-sec.md` §1.3, §3, §4, E-3, E-4, E-5; owner ruling Q-U2, 2026-10-08).
 *
 * What is pinned, and the mutation each one answers:
 *  - the `/users` and `/users/{id}` paths are exactly {@see self::PINNED_USER_OPERATIONS} (the `/users/{id}/…` family is
 *    `RoleRevokeTest`'s);
 *  - the body, whole: `roles[]` = grants on LIVE roles, ordered `name, id` (mutation 8); `[]` for a soft-deleted user
 *    (mutation 9); an inactive user's grants listed; `margin_exposure` = PC-12 clause 3's union warning (mutation 14);
 *  - the shape is {@see self::PINNED_USER_FIELDS} by reflection, independent of `docs/`, and names no privilege history;
 *  - the read is the same for every caller (Q-U2: the picker's caller-relative data is `ListOfferedRoles`');
 *  - 404s: non-uuid, absent and another workspace's id byte-equal (mutation 13); 403 before existence (`SEC` E-4);
 *  - the reads touch only {@see self::USER_READ_TABLES} (EXPLAIN ∪ text; mutation 5) and read only
 *    {@see self::APP_USER_COLUMNS} of `app_user` (EXPLAIN VERBOSE, with in-test controls; mutation 6);
 *  - a pending proposal leaves every body byte-identical (`SEC` V-4).
 */
final class UserReadTest extends ApiTestCase
{
    /**
     * The `/users` operations outside the `/users/{id}/…` family, held equal to the registry and joined into
     * `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_USER_OPERATIONS = [
        'GetUser' => ['method' => 'GET', 'path' => '/users/{id}', 'idem' => false],
        // Users slice 1 C2.
        'ListUsers' => ['method' => 'GET', 'path' => '/users', 'idem' => false],
        // G4-C1: rename / switch off / switch on. `idem`: §4.2's `no` (a repeat computes an empty delta).
        'UpdateUser' => ['method' => 'PATCH', 'path' => '/users/{id}', 'idem' => false],
        // G4-C2. `idem`: §4.2's `no` (a repeat meets a deleted person, 409).
        'DeleteUser' => ['method' => 'DELETE', 'path' => '/users/{id}', 'idem' => false],
    ];

    /** `UserResource`'s serialized members — §4.4.3's `User` row as rewritten at C1 (K-1), pinned against the DTO. */
    public const PINNED_USER_FIELDS = ['id', 'email', 'display_name', 'is_active', 'archived_at', 'roles', 'margin_exposure'];

    /** Every relation a user read may touch. An allowlist: `role_grant_proposal` (pending state) under any name is red. */
    public const USER_READ_TABLES = ['app_user', 'role', 'role_permission', 'user_role'];

    /**
     * The `app_user` columns a user read may read (`SEC` E-5; RED M-5). `tenant_id` and `id` are the join keys;
     * `password_hash`, `last_login_at`, `created_at` and `updated_at` are never read.
     */
    public const APP_USER_COLUMNS = ['deleted_at', 'display_name', 'email', 'id', 'is_active', 'tenant_id'];

    /** A `*` select in statement text: `<alias>.*` or a bare `SELECT *`. */
    private const STAR = '/\\w\\.\\*|\\bSELECT\\s+\\*/i';

    /** @var list<string> `app_user`'s columns, read from the server by the column test. */
    private static array $appUserColumns = [];

    /** A member name matching this is privilege history, which no user read may serve. */
    private const PRIVILEGE_HISTORY = '/pending|proposal|propos|decided|inserted/';

    #[Test]
    public function theUserPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/users(/\{id\})?$#', $path)) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $path];
            }
        }
        ksort($declared);
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_USER_OPERATIONS);
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /** The whole body, exactly: live roles ordered `name, id`, `{id, name}` only. */
    #[Test]
    public function aUserIsReadWithTheRolesTheyHold(): void
    {
        $user = $this->seedUser('Ada Example');
        $zeta = $this->seedRole('Zeta', ['role.view']);
        $alpha = $this->seedRole('Alpha', ['inventory_item.view']);
        $this->grant($user, $zeta);
        $this->grant($user, $alpha);

        $this->send('GET', '/users/'.$user);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            'id' => $user,
            'email' => 'user-'.$user.'@example.test',
            'display_name' => 'Ada Example',
            'is_active' => true,
            'archived_at' => null,
            'roles' => [['id' => $alpha, 'name' => 'Alpha'], ['id' => $zeta, 'name' => 'Zeta']],
            'margin_exposure' => false,
        ], $this->json());
    }

    /**
     * Mutation 8: a grant on an ARCHIVED role confers nothing, so it is not in `roles[]` and its codes do not count
     * towards `margin_exposure` — the archived role here would expose on its own.
     */
    #[Test]
    public function aGrantOnAnArchivedRoleIsNotListed(): void
    {
        $user = $this->seedUser('Holder');
        $live = $this->seedRole('Live', ['role.view']);
        $archived = $this->seedRole('Archived exposing', ['purchase_order.view', 'invoice.view']);
        $this->grant($user, $live);
        $this->grant($user, $archived);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->send('GET', '/users/'.$user);

        self::assertSame(200, $this->httpStatus());
        self::assertSame([['id' => $live, 'name' => 'Live']], $this->json()['roles'], 'A grant on an archived role is listed.');
        self::assertFalse($this->json()['margin_exposure'], 'An archived role\'s codes counted towards margin_exposure.');
    }

    /**
     * Mutation 9 / `SEC` §4: a soft-deleted user is read as a record (`archived_at` set, API-13a) and holds nothing —
     * its stored `user_role` rows confer nothing while it is deleted. An INACTIVE user's grants are listed (`SEC` §4's
     * contrast). *(Text only, G4-C0: until then "its live token still authenticates, API-O-22"; since API-O-22 closed (a)
     * the token is refused at the edge, and the rows are what a reactivation would bring back into force.)*
     */
    #[Test]
    public function aRemovedUserHoldsNothingAndAnInactiveOneKeepsItsRoles(): void
    {
        $role = $this->seedRole('Held', ['role.view']);
        $removed = $this->seedUser('Removed');
        $inactive = $this->seedUser('Inactive');
        $this->grant($removed, $role);
        $this->grant($inactive, $role);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$removed]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$inactive]);

        $this->send('GET', '/users/'.$removed);
        self::assertSame(200, $this->httpStatus());
        $body = $this->json();
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/', (string) $body['archived_at']);
        self::assertSame([], $body['roles'], 'A soft-deleted user is shown holding roles.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$removed]),
            'Fixture: the removed user\'s stored grant is gone, so the empty roles[] proves nothing.');

        $this->send('GET', '/users/'.$inactive);
        self::assertSame(200, $this->httpStatus());
        self::assertFalse($this->json()['is_active']);
        self::assertSame([['id' => $role, 'name' => 'Held']], $this->json()['roles'], 'An inactive user\'s grants are hidden.');
    }

    /**
     * Mutation 14 (second half): `margin_exposure` is PC-12 clause 3's warning over the UNION of the held roles — a
     * `buyer` + `viewer` holder is exposed though neither role is alone (`MarginSourcesDerivationTest::CLAUSE_4_PAIRS`);
     * each alone is not; the owner, holding every `.view`, is not.
     */
    #[Test]
    public function marginExposureIsTheWarningOverTheHeldUnion(): void
    {
        $buyer = $this->systemRole(DefaultRoles::BUYER);
        $viewer = $this->systemRole(DefaultRoles::VIEWER);
        $owner = $this->systemRole(DefaultRoles::OWNER);
        $cases = [
            'buyer + viewer' => [[$buyer, $viewer], true],
            'buyer alone' => [[$buyer], false],
            'viewer alone' => [[$viewer], false],
            'owner + buyer + viewer' => [[$owner, $buyer, $viewer], false],
        ];
        foreach ($cases as $case => [$roles, $expected]) {
            $user = $this->seedUser($case);
            foreach ($roles as $role) {
                $this->grant($user, $role);
            }
            $this->send('GET', '/users/'.$user);
            self::assertSame(200, $this->httpStatus());
            self::assertSame($expected, $this->json()['margin_exposure'], $case);
        }
    }

    /** IDOR / mutation 13: another workspace's real user id answers byte-equal to a random one; a non-uuid is 404. */
    #[Test]
    public function anotherWorkspacesUserIsNotFoundLikeOneThatNeverExisted(): void
    {
        $theirs = $this->seedPrincipal(self::TENANT_B, []);

        $this->send('GET', '/users/'.$theirs);
        self::assertSame(404, $this->httpStatus());
        $foreign = (string) $this->client()->getResponse()->getContent();

        $this->send('GET', '/users/'.Uuid::v4()->toRfc4122());
        self::assertSame(404, $this->httpStatus());
        self::assertSame((string) $this->client()->getResponse()->getContent(), $foreign);

        $this->send('GET', '/users/not-a-uuid');
        self::assertSame(404, $this->httpStatus());
    }

    /**
     * `SEC` E-4 (and R1-1's condition, extended at C3 to `ListOfferedRoles`): a seat without `app_user.view` gets
     * byte-equal 403s for a real user id and a random one — the gate refuses before any read (E-1), so the 403 is no
     * existence oracle. Control: the same seat WITH the code reads the real user.
     */
    #[Test]
    public function aSeatWithoutTheViewGetsTheSame403ForARealAndARandomId(): void
    {
        $real = $this->seedUser('Real');
        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.view', 'role.assign']));

        $bodies = [];
        foreach ([$real, Uuid::v4()->toRfc4122()] as $id) {
            $this->send('GET', '/users/'.$id);
            self::assertSame(403, $this->httpStatus());
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1], 'The 403 differs for a real and a random user id: an existence oracle.');
        self::assertSame('{"required_permission":"app_user.view","type":"urn:erp:problem:permission-denied","title":"Permission denied","status":403}', $bodies[0]);

        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['app_user.view']));
        $this->send('GET', '/users/'.$real);
        self::assertSame(200, $this->httpStatus(), 'Control: an app_user.view holder could not read the user.');
    }

    /** The shape, by reflection against the DTO (independent of `docs/`), names no privilege history. */
    #[Test]
    public function theUserShapeIsPinnedAndCarriesNoPrivilegeHistory(): void
    {
        self::assertMatchesRegularExpression(self::PRIVILEGE_HISTORY, 'pending_roles', 'Control: the history pattern misses pending_roles.');
        self::assertMatchesRegularExpression(self::PRIVILEGE_HISTORY, 'inserted_by', 'Control: the history pattern misses inserted_by.');

        $fields = [];
        foreach ((new \ReflectionClass(UserResource::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $renamed = $property->getAttributes(SerializedName::class)[0] ?? null;
            $fields[] = null === $renamed ? $property->getName() : (string) $renamed->newInstance()->serializedName;
        }
        self::assertSame(self::PINNED_USER_FIELDS, $fields, 'UserResource\'s members are not §4.4.3\'s User row as rewritten at C1.');
        self::assertSame([], preg_grep(self::PRIVILEGE_HISTORY, $fields), 'The User shape names privilege history.');
        foreach (['password_hash', 'last_login_at', 'created_at', 'updated_at', 'tenant_id', 'roles_offered', 'permission_codes'] as $never) {
            self::assertNotContains($never, $fields);
        }
    }

    /**
     * Q-U2: the user read is byte-identical for a caller holding only `app_user.view` and one holding every declared
     * code (the picker's caller-relative data is its own `role.assign` read, never this one).
     */
    #[Test]
    public function theUserReadIsTheSameForEveryCaller(): void
    {
        $user = $this->seedUser('Compared');
        $this->grant($user, $this->seedRole('Compared role', ['purchase_order.view', 'invoice.view']));
        $narrow = $this->tokenFor(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['app_user.view']));
        $everything = $this->tokenFor(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, self::everyDeclaredPermission()));

        foreach ($this->userReadPaths($user) as $path) {
            $bodies = [];
            foreach ([$narrow, $everything] as $token) {
                $this->send('GET', $path, headers: ['Authorization' => 'Bearer '.$token]);
                self::assertSame(200, $this->httpStatus(), $path.': '.(string) $this->client()->getResponse()->getContent());
                $bodies[] = (string) $this->client()->getResponse()->getContent();
            }
            // The two callers are themselves users, so a list differs by their own rows; compare the subject's item.
            self::assertSame(self::subjectOf($bodies[0], $user), self::subjectOf($bodies[1], $user),
                $path.' answers differently to an app_user.view-only caller and to one holding everything.');
        }
    }

    /**
     * `SEC` V-4: a pending proposal for the user leaves every user read byte-identical — no reader names
     * `role_grant_proposal` ({@see self::theUserReadsTouchOnlyTheirOwnTables()} is the structural half).
     */
    #[Test]
    public function aPendingProposalLeavesTheUserReadsByteIdentical(): void
    {
        $user = $this->seedUser('Subject');
        $role = $this->seedRole('Proposed', ['invoice.pay', 'role.view']);
        $proposer = $this->seedUser('Proposer');

        $read = function () use ($user): array {
            $bodies = [];
            foreach ($this->userReadPaths($user) as $path) {
                $this->send('GET', $path);
                self::assertSame(200, $this->httpStatus(), $path);
                $bodies[$path] = (string) $this->client()->getResponse()->getContent();
            }

            return $bodies;
        };

        $before = $read();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
            SELECT gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, '["invoice.pay", "role.view"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
              FROM (SELECT clock_timestamp() AS t) c
            SQL, [self::TENANT_A, $user, $role, $proposer]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM role_grant_proposal WHERE status = 'pending'"),
            'Fixture: the pending proposal was not seeded.');

        self::assertSame($before, $read(), 'A user read changed when a pending proposal was added: it serves privilege history.');
    }

    /**
     * Mutation 5: the user reads touch only {@see self::USER_READ_TABLES} — the union of the statement's text relations
     * (`RoleReadTest::textRelations`) and its plan's, with `GET /me`'s statements subtracted as the baseline.
     */
    #[Test]
    public function theUserReadsTouchOnlyTheirOwnTables(): void
    {
        self::assertContains('role_grant_proposal', RoleReadTest::textRelations('SELECT u.id FROM app_user u JOIN role_grant_proposal p ON p.user_id = u.id'),
            'Control: the text party missed a joined relation.');
        self::assertContains('role_grant_proposal', RoleReadTest::textRelations('WITH x AS (SELECT p.id FROM role_grant_proposal p) SELECT r.id FROM role r, x'),
            'Control: the text party missed a relation read inside a CTE body.');

        $user = $this->seedUser('Traced');
        $this->grant($user, $this->seedRole('Traced role', ['role.view']));
        $baseline = $this->recordedDrive('/me');
        self::assertNotSame([], $baseline['statements'], 'GET /me issued no statement, so there is no baseline.');

        $defects = [];
        foreach ($this->readerPaths($user) as $path) {
            $drive = $this->recordedDrive($path);
            $own = array_values(array_unique(array_diff($drive['statements'], $baseline['statements'])));
            $namesAppUser = false;
            foreach ($own as $sql) {
                // A CTE the statement itself defines is a name, not a relation (its body is read like any other text).
                preg_match_all('/(?:\bWITH|,)\s*([a-z_][a-z0-9_]*)\s+AS\s*\(/i', $sql, $ctes);
                $text = array_values(array_diff(RoleReadTest::textRelations($sql), array_map(strtolower(...), $ctes[1])));
                $relations = array_values(array_unique([...$text, ...self::planRelations($this->plan($sql, $drive['params'][$sql] ?? []))]));
                $namesAppUser = $namesAppUser || \in_array('app_user', $relations, true);
                foreach (array_diff($relations, self::USER_READ_TABLES) as $stranger) {
                    $defects[] = \sprintf('%s touches %s: %s', $path, $stranger, $sql);
                }
            }
            if ([] === $own || !$namesAppUser) {
                $defects[] = $path.': the reader issued no statement of its own naming app_user';
            }
        }

        self::assertSame([], $defects, 'A user read touches a relation outside the allowlist (pending state is role.assign\'s only, Q-1).');
    }

    /**
     * Mutation 6 (`SEC` E-5; RED M-5; in-test controls RED-2 M-5): the `app_user` columns the reader statements read,
     * derived from `EXPLAIN (VERBOSE)` — `Output`, `Filter`, `Index Cond`, `Join Filter` (and every other `* Cond`) of
     * every plan node, parsed together — are a subset of {@see self::APP_USER_COLUMNS}; a whole-row `<alias>.*` anywhere
     * is red; the join keys the statement text writes against `app_user` are exactly `id` and `tenant_id`.
     * `ResponseShapeTest` is the primary wire control; this is the fetch-side backstop.
     */
    #[Test]
    public function theUserReadsReadOnlyTheirAllowedColumns(): void
    {
        self::$appUserColumns = array_map(strval(...), $this->owner()->fetchFirstColumn(
            "SELECT attname FROM pg_attribute WHERE attrelid = 'public.app_user'::regclass AND attnum > 0 AND NOT attisdropped"));
        self::assertContains('password_hash', self::$appUserColumns, 'Control: app_user\'s columns were not read from the server.');

        // Control 4: a planted statement through the same parser has `password_hash` flagged, and `u.*` too.
        $planted = self::appUserColumns($this->plan("SELECT u.id, u.password_hash FROM app_user u WHERE u.email = 'x'", []));
        self::assertContains('password_hash', $planted, 'Control: the column parser missed a planted password_hash.');
        // A whole-row `u.*` is expanded by the planner into every column, so it is caught as `password_hash`; the statement
        // text is ALSO refused for any `*` select (control 3), so a planner that kept `u.*` cannot hide one.
        self::assertContains('password_hash', self::appUserColumns($this->plan('SELECT u.* FROM app_user u', [])), 'Control: the parser missed a whole-row u.*.');
        self::assertSame(1, preg_match(self::STAR, 'SELECT u.* FROM app_user u'), 'Control: the text check missed u.*.');
        // A column a join's parent reads is seen even when the scan below returns its physical tuple.
        self::assertContains('password_hash', self::appUserColumns($this->plan(
            'SELECT ur.role_id, u.password_hash FROM user_role ur JOIN app_user u ON u.tenant_id = ur.tenant_id AND u.id = ur.user_id', [])),
            'Control: a joined app_user column read by the parent was not seen.');
        self::assertSame(1, preg_match(self::STAR, 'SELECT * FROM app_user'), 'Control: the text check missed SELECT *.');

        $user = $this->seedUser('Columns');
        $this->grant($user, $this->seedRole('Columns role', ['role.view']));
        $baseline = $this->recordedDrive('/me');

        $observed = [];
        $joinKeys = [];
        $strangers = [];
        foreach ($this->readerPaths($user) as $path) {
            $drive = $this->recordedDrive($path);
            foreach (array_values(array_unique(array_diff($drive['statements'], $baseline['statements']))) as $sql) {
                $plan = $this->plan($sql, $drive['params'][$sql] ?? []);
                if (!\in_array('app_user', self::planRelations($plan), true)) {
                    continue;
                }
                $columns = self::appUserColumns($plan);
                $observed = [...$observed, ...$columns];
                foreach (array_diff($columns, self::APP_USER_COLUMNS) as $stranger) {
                    $strangers[] = $path.' reads app_user.'.$stranger.': '.$sql;
                }
                self::assertSame(0, preg_match(self::STAR, $sql), 'A user read selects `*`: '.$sql);
                preg_match_all('/=\s*u\.([a-z_]+)/', $sql, $m);
                $joinKeys = [...$joinKeys, ...$m[1]];
            }
        }
        $observed = array_values(array_unique($observed));
        sort($observed);
        $joinKeys = array_values(array_unique($joinKeys));
        sort($joinKeys);

        // Controls 1 and 5.
        self::assertNotSame([], $observed, 'No app_user column was observed: the parser read nothing.');
        self::assertContains('email', $observed);
        self::assertContains('deleted_at', $observed);
        self::assertSame(['id', 'tenant_id'], $joinKeys, 'The statement joins app_user on columns other than exactly id and tenant_id.');

        self::assertSame([], $strangers,
            'A user read reads an app_user column outside the allowlist (password_hash, last_login_at, … never; * never).');
    }

    // -----------------------------------------------------------------------

    /**
     * The paths every user read in the registry is driven at for one user: `GetUser` always, and `ListUsers` (live,
     * and with `q` matching the user's own email) once it is built.
     *
     * @return list<string>
     */
    private function userReadPaths(string $user): array
    {
        $paths = ['/users/'.$user];
        if (\array_key_exists('ListUsers', TransactionBoundaryTest::contractOperations())) {
            array_push($paths, '/users', '/users?q=user-'.$user);
        }

        return $paths;
    }

    /**
     * The paths whose reader statements the table and column allowlists examine: the user reads, and — since users slice 1
     * C3 — the picker read `ListOfferedRoles` for the same user (`SEC` V-4's structural half and E-5 extended to the
     * offered-role reader). The rig's actor holds every declared code, `role.assign` included.
     *
     * @return list<string>
     */
    private function readerPaths(string $user): array
    {
        $paths = $this->userReadPaths($user);
        if (\array_key_exists('ListOfferedRoles', TransactionBoundaryTest::contractOperations())) {
            array_push($paths, '/users/'.$user.'/offered-roles', '/users/'.$user.'/offered-roles?archived=true&q=T');
        }

        return $paths;
    }

    /** The subject's own JSON, from a `GetUser` body or out of a `ListUsers` page. */
    private static function subjectOf(string $body, string $user): string
    {
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        if (!\array_key_exists('items', $decoded)) {
            return $body;
        }
        foreach ($decoded['items'] as $item) {
            if ($item['id'] === $user) {
                return json_encode($item, \JSON_THROW_ON_ERROR);
            }
        }
        self::fail('The subject is not on the page.');
    }

    /**
     * `EXPLAIN (VERBOSE, FORMAT JSON)` as `erp_app` under tenant A.
     *
     * @param array<int|string, mixed> $params
     *
     * @return array<string, mixed> the root plan node
     */
    private function plan(string $sql, array $params): array
    {
        if (1 !== preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            return [];
        }
        $json = $this->tenantContext()->run(self::TENANT_A,
            static fn (EntityManagerInterface $em): string => (string) $em->getConnection()->fetchOne('EXPLAIN (VERBOSE, FORMAT JSON) '.$sql, $params));
        $plan = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($plan));

        return $plan[0]['Plan'] ?? [];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<string>
     */
    private static function planRelations(array $node): array
    {
        $relations = isset($node['Relation Name']) ? [(string) $node['Relation Name']] : [];
        foreach ($node['Plans'] ?? [] as $child) {
            $relations = [...$relations, ...self::planRelations($child)];
        }

        return array_values(array_unique($relations));
    }

    /**
     * The `app_user` columns a plan reads: every `<alias>.<column>` in every node's `Output`, `Filter`, `Join Filter`
     * and `* Cond`, for each alias a scan of `app_user` carries; `*` for a whole-row `<alias>.*`.
     *
     * @param array<string, mixed> $root
     *
     * @return list<string>
     */
    private static function appUserColumns(array $root): array
    {
        $aliases = [];
        $texts = [];
        $ownTexts = [];
        $walk = static function (array $node) use (&$walk, &$aliases, &$texts, &$ownTexts): void {
            $physical = false;
            if ('app_user' === ($node['Relation Name'] ?? null)) {
                $alias = (string) ($node['Alias'] ?? 'app_user');
                $aliases[] = $alias;
                // **The physical-tlist optimisation**: a scan under a join may return the WHOLE stored tuple to skip a
                // projection — every column in table order — although the statement reads only some (measured under
                // `erp_app`, users slice 1 C3: `JOIN`/`EXISTS` on `app_user` inside `ListOfferedRoles`' CTEs). That
                // Output is not a read; the columns actually read are the ones its parents reference, which the walk
                // collects qualified. A projected scan (a single-table read) keeps its Output, so a planted
                // `SELECT u.password_hash` is still seen, and a `u.*` select is refused on the statement text.
                $physical = array_map(static fn (string $c): string => $alias.'.'.$c, self::$appUserColumns) === ($node['Output'] ?? null);
                // A scan node writes its own columns UNQUALIFIED (`"Output": ["id", "password_hash"]`, `Index Cond: (id = …)`).
                foreach ($node as $key => $value) {
                    if ('Output' === $key && \is_array($value) && !$physical) {
                        $ownTexts = [...$ownTexts, ...array_map(strval(...), $value)];
                    } elseif (\is_string($key) && \is_string($value) && ('Filter' === $key || str_ends_with($key, ' Cond'))) {
                        $ownTexts[] = $value;
                    }
                }
            }
            foreach ($node as $key => $value) {
                if ('Output' === $key && \is_array($value) && !$physical) {
                    $texts = [...$texts, ...array_map(strval(...), $value)];
                } elseif (\is_string($key) && \is_string($value) && ('Filter' === $key || 'Join Filter' === $key || str_ends_with($key, ' Cond'))) {
                    $texts[] = $value;
                }
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($root);

        $columns = [];
        foreach (array_unique($aliases) as $alias) {
            foreach ($texts as $text) {
                preg_match_all('/\b'.preg_quote($alias, '/').'\.(\*|"?[a-z_]+"?)/', $text, $m);
                foreach ($m[1] as $column) {
                    $columns[] = trim($column, '"');
                }
            }
        }

        // The scan node's own unqualified identifiers that name an `app_user` column (read from the server), and `*`.
        foreach ($ownTexts as $text) {
            $text = (string) preg_replace("/'(?:[^']|'')*'/", "''", $text);
            preg_match_all('/(?<![.\\w"])("?[a-z_]+"?|\*)(?![\\w"(])/', $text, $m);
            foreach ($m[1] as $token) {
                $token = trim($token, '"');
                if ('*' === $token || \in_array($token, self::$appUserColumns, true)) {
                    $columns[] = $token;
                }
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * One request's statements on the `app` connection, and the parameters each prepared text ran with.
     *
     * @return array{statements: list<string>, params: array<string, array<int|string, mixed>>}
     */
    private function recordedDrive(string $path): array
    {
        $recorder = self::getContainer()->get('test.sql_recorder.app');
        \assert($recorder instanceof \App\Tests\Support\SqlRecorder);

        $recorder->start();
        $this->send('GET', $path);
        $recorder->stop();
        self::assertSame(200, $this->httpStatus(), $path.': '.(string) $this->client()->getResponse()->getContent());

        $params = [];
        foreach ($recorder->executions() as $execution) {
            $params[$execution['sql']] = $execution['params'];
        }

        return ['statements' => $recorder->statements(), 'params' => $params];
    }

    private function seedUser(string $displayName): string
    {
        $user = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
            VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', ?)
            SQL, [$user, self::TENANT_A, 'user-'.$user.'@example.test', $displayName]);

        return $user;
    }

    /** @param list<string> $codes */
    private function seedRole(string $name, array $codes): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)', [$role, self::TENANT_A, $name]);
        foreach ($codes as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $role, $code]);
        }

        return $role;
    }

    /** A system role holding `$name`'s default codes. */
    private function systemRole(string $name): string
    {
        $role = $this->seedRole($name.' '.substr(Uuid::v4()->toRfc4122(), 0, 8), DefaultRoles::codesFor($name));
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET is_system = true WHERE id = ?::uuid', [$role]);

        return $role;
    }

    private function grant(string $user, string $role): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)',
            [self::TENANT_A, $user, $role]);
    }
}
