<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\ApiResource\RoleResource;
use App\Modules\UserManagement\Domain\PermissionCatalogue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * `GetRole` (C3) and `ListRoles` (C4) — roles slice 1 (`docs/session13/roles-s1-design.md` §2.2, §2.3, §4).
 *
 * Besides the behaviour, this class carries the three parts of the rule that **no pending-proposal state
 * leaks through the role reads** (IA §6.8, PC-16 condition 1, response R-10) — `viewer` holds `role.view`.
 * No proposal storage exists today (`pg_class` holds no `%proposal%` relation), so a behavioural test of
 * invisibility would pass by having no subject — the empty-set trap. Each part is honest about its reach:
 *
 *   (a) {@see self::theRoleShapeCarriesNoPrivilegeHistory()} — a shape pin `docs/` cannot move;
 *   (b) {@see self::theRoleReadsTouchOnlyTheirOwnTables()} — a source allowlist, by EXPLAIN **and** by text,
 *       guarded against function bodies by {@see self::noCallableFunctionExistsForTheRoleReadsToHideBehind()};
 *   (c) {@see self::proposalStorageArmsTheInvisibilityTest()} — a two-sided tripwire that brings the
 *       behavioural test with the table.
 *
 * The tripwire lives here, not in `OwedAmendmentTripwireTest`, on purpose: the parallel sales track edits
 * that file (R-39 fires on `VoidInvoice`), and a new method there would be a gratuitous merge conflict.
 */
final class RoleReadTest extends ApiTestCase
{
    /**
     * The operations shipped under `/roles`, joined into
     * `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_ROLE_OPERATIONS = [
        'GetRole' => ['method' => 'GET', 'path' => '/roles/{id}', 'idem' => false],
        'ListRoles' => ['method' => 'GET', 'path' => '/roles', 'idem' => false],
        // Roles slice 4 C1. `idem`: §4.2's `unique:role_tenant_name_live` — the header is demanded (428 without it).
        'CreateRole' => ['method' => 'POST', 'path' => '/roles', 'idem' => true],
        // Roles slice 4 C2. `idem`: §4.2's `no` — the edit is a set; a repeat is an empty delta and writes nothing.
        'UpdateRole' => ['method' => 'PATCH', 'path' => '/roles/{id}', 'idem' => false],
        // Roles slice 4 C3. `idem`: §4.2's `no` — a repeat meets the deleted role and is 409 `role.is_archived`.
        'DeleteRole' => ['method' => 'DELETE', 'path' => '/roles/{id}', 'idem' => false],
        // G2. `idem`: §4.2's `guard:role.deleted_at` — a repeat meets a live role and is 409 `role.is_not_archived`.
        'RestoreRole' => ['method' => 'POST', 'path' => '/roles/{id}/restoration', 'idem' => false],
    ];

    /**
     * `RoleResource`'s serialized members — a second party to `ResponseShapeTest`'s derivation that `docs/`
     * cannot move. A joint edit to §4.4.3's additions row and the DTO would satisfy `ResponseShapeTest`
     * alone; this makes it a third, deliberate edit, to a file whose docblock says why.
     *
     * **`margin_exposure` added deliberately 2026-10-07 (roles slice 4 C1a; design §4.6, owner notice in
     * `session22-owner-ruling.md`):** PC-12 clause 3's definition-time warning, a pure function of `permission_codes` — no
     * privilege history, no pending state. The invisibility tripwire below is untouched.
     */
    public const PINNED_ROLE_FIELDS = ['id', 'name', 'description', 'permission_codes', 'is_system', 'holder_count', 'margin_exposure', 'archived_at'];

    /** Every relation a role read may touch. An allowlist: a proposal table under any name is red. */
    public const ROLE_READ_TABLES = ['app_user', 'role', 'role_permission', 'user_role'];

    /**
     * Flipped by the slice-3 migration that creates proposal storage, and never before: the tripwire is
     * red in both directions (see {@see self::proposalStorageArmsTheInvisibilityTest()}). **Flipped 2026-10-04 by roles slice 3
     * C1** (`Version20261004000100`, `role_grant_proposal`), with {@see self::aPendingProposalIsInvisibleToTheRoleReads()}.
     */
    public const PROPOSAL_STORAGE_EXISTS = true;

    /** A member name matching this is privilege history, which no role read may serve. */
    private const PRIVILEGE_HISTORY = '/pending|proposal|propos|decided/';

    /** The registry's `/roles` operations are exactly the pin, method and path included. */
    #[Test]
    public function theRolePathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if (str_starts_with((string) $operation->getUriTemplate(), '/roles')) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $operation->getUriTemplate()];
            }
        }
        ksort($declared);
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_ROLE_OPERATIONS);
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /** The whole body, exactly — codes sorted ascending whatever order they were granted in. */
    #[Test]
    public function aRoleIsReadWithItsSortedCodes(): void
    {
        $role = $this->seedRole(self::TENANT_A, 'Buyer', ['purchase_order.view', 'inventory_item.view', 'purchase_order.create'], 'Buys things');

        $this->send('GET', '/roles/'.$role);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            'id' => $role,
            'name' => 'Buyer',
            'description' => 'Buys things',
            'permission_codes' => ['inventory_item.view', 'purchase_order.create', 'purchase_order.view'],
            'is_system' => false,
            'holder_count' => 0,
            'margin_exposure' => false,
            'archived_at' => null,
        ], $this->json());
    }

    /**
     * **`margin_exposure` is PC-12 clause 3's warning at definition** (roles slice 4 C1a; design §4.6): a composed role
     * holding a cost source (`purchase_order.view`) AND a sale source (`invoice.view`) without every `.view` reads `true`;
     * each source alone reads `false`; and the every-`.view` seat (the `owner`/`admin` exemption) reads `false` although it
     * holds both. Served on `GetRole` and, as the same DTO, on each `ListRoles` item — a warning, never a refusal (the read
     * succeeds either way).
     */
    #[Test]
    public function marginExposureWarnsARoleHoldingBothSourcesWithoutEveryView(): void
    {
        $exposed = $this->seedRole(self::TENANT_A, 'Exposed', ['purchase_order.view', 'invoice.view']);
        $costOnly = $this->seedRole(self::TENANT_A, 'Cost only', ['purchase_order.view']);
        $saleOnly = $this->seedRole(self::TENANT_A, 'Sale only', ['invoice.view']);
        $everyView = $this->seedRole(self::TENANT_A, 'Every view', PermissionCatalogue::viewCodes());

        foreach ([$exposed => true, $costOnly => false, $saleOnly => false, $everyView => false] as $role => $expected) {
            $this->send('GET', '/roles/'.$role);
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame($expected, $this->json()['margin_exposure'], 'margin_exposure is wrong for role '.$this->json()['name']);
        }

        $this->send('GET', '/roles?q=Exposed');
        self::assertSame(200, $this->httpStatus());
        self::assertTrue($this->json()['items'][0]['margin_exposure'], 'ListRoles served a different margin_exposure from GetRole.');
    }

    /**
     * `holder_count` counts `user_role` rows whose user is not deleted, **regardless of `is_active`** (a
     * deactivated user still holds the grant until E-15g's stripping is built, and regains it on
     * reactivation). Three holders — live, inactive, deleted — read **2**, and each exclusion is shown to
     * matter: the inactive one is counted (so the answer is not 1) and the deleted one is not (not 3).
     *
     * ⚠ The deleted-user-holding-a-role state is **seeded as the owner**. Once E-15g ships it is
     * unreachable through the API, so a green run here says nothing about what the API can produce
     * (G-7's shape). And this is a display predicate only: it does not define E-15h's "live holder" for
     * `DeleteRole`, which must fail closed and is `SEC`'s to rule (RED F10).
     */
    #[Test]
    public function holderCountCountsLiveUsersAndIgnoresDeletedOnes(): void
    {
        $role = $this->seedRole(self::TENANT_A, 'Holders', []);
        $live = $this->seedUser(self::TENANT_A);
        $inactive = $this->seedUser(self::TENANT_A);
        $deleted = $this->seedUser(self::TENANT_A);
        foreach ([$live, $inactive, $deleted] as $user) {
            $this->grant(self::TENANT_A, $user, $role);
        }
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$inactive]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$deleted]);

        $this->send('GET', '/roles/'.$role);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, $this->json()['holder_count'], 'Three holders — live, inactive, deleted — must read 2.');
    }

    #[Test]
    public function aSystemRoleSaysSo(): void
    {
        $role = $this->seedRole(self::TENANT_A, 'Owner', ['role.view']);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET is_system = true WHERE id = ?::uuid', [$role]);

        $this->send('GET', '/roles/'.$role);

        self::assertSame(200, $this->httpStatus());
        self::assertTrue($this->json()['is_system']);
    }

    /** API-13a / §5.2a: a soft-deleted role in the caller's tenant is read as a record, not refused. */
    #[Test]
    public function anArchivedRoleIsReadWithArchivedAt(): void
    {
        $role = $this->seedRole(self::TENANT_A, 'Retired', ['role.view']);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);

        $this->send('GET', '/roles/'.$role);

        self::assertSame(200, $this->httpStatus());
        $body = $this->json();
        self::assertNotNull($body['archived_at']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/', $body['archived_at']);
        self::assertSame(['role.view'], $body['permission_codes'], 'An archived role is read as a record, codes included.');
    }

    /** IDOR: tenant B's real role id answers exactly as a uuid that never existed. RLS decides it. */
    #[Test]
    public function anotherTenantsRoleIsNotFoundLikeOneThatNeverExisted(): void
    {
        $theirs = $this->seedRole(self::TENANT_B, 'Their role', ['role.view']);

        $this->send('GET', '/roles/'.$theirs);
        self::assertSame(404, $this->httpStatus());
        $foreign = (string) $this->client()->getResponse()->getContent();

        $this->send('GET', '/roles/'.Uuid::v4()->toRfc4122());
        self::assertSame(404, $this->httpStatus());

        self::assertSame((string) $this->client()->getResponse()->getContent(), $foreign);
    }

    #[Test]
    public function aNonUuidIsNotFound(): void
    {
        $this->send('GET', '/roles/not-a-uuid');

        self::assertSame(404, $this->httpStatus());
    }

    /**
     * §4(a): `RoleResource`'s serialized members equal {@see self::PINNED_ROLE_FIELDS}, and none names
     * privilege history. The comparison is against the DTO by reflection, independent of `docs/`, so a
     * joint doc-and-DTO edit adding `pending_proposal_count` is still red.
     *
     * Controls inside the test: a planted field list carrying `pending_proposal_count` is refused on both
     * counts (not the pin, and matches the history pattern); the pin itself is clean.
     */
    #[Test]
    public function theRoleShapeCarriesNoPrivilegeHistory(): void
    {
        $planted = [...self::PINNED_ROLE_FIELDS, 'pending_proposal_count'];
        self::assertContains('carries `pending_proposal_count`, which is not in PINNED_ROLE_FIELDS', self::shapeDefects($planted),
            'The shape check accepted a planted pending_proposal_count as a pinned field.');
        self::assertContains('`pending_proposal_count` names privilege history', self::shapeDefects($planted),
            'The history pattern did not flag a planted pending_proposal_count.');
        self::assertSame([], self::shapeDefects(self::PINNED_ROLE_FIELDS), 'The pin itself names privilege history.');

        self::assertSame([], self::shapeDefects(self::serializedFields(RoleResource::class)),
            'RoleResource carries a field outside the pin, or one naming privilege history. viewer holds role.view '
            .'(PC-16 condition 1, IA §6.8); response R-10 removed pending_proposal_count for everyone.');
    }

    /**
     * R-10's reason made a behaviour: the role read is byte-identical for a principal holding only
     * `role.view` and one holding every declared code. A shape that varied by caller would break
     * `ResponseShapeTest`'s one-DTO contract and would be the leak §4 forbids.
     */
    #[Test]
    public function theRoleReadIsTheSameForEveryCaller(): void
    {
        $role = $this->seedRole(self::TENANT_A, 'Compared', ['inventory_item.view', 'role.view']);
        $viewer = $this->tokenFor(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.view']));
        $everything = $this->tokenFor(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, self::everyDeclaredPermission()));

        foreach ($this->roleReadPaths($role) as $path) {
            $bodies = [];
            foreach ([$viewer, $everything] as $token) {
                $this->send('GET', $path, headers: ['Authorization' => 'Bearer '.$token]);
                self::assertSame(200, $this->httpStatus(), $path.': '.(string) $this->client()->getResponse()->getContent());
                $bodies[] = (string) $this->client()->getResponse()->getContent();
            }
            self::assertSame($bodies[0], $bodies[1], $path.' answers differently to a role.view-only caller and to one holding everything.');
        }
    }

    /**
     * §4(b) — the role reads touch only {@see self::ROLE_READ_TABLES}.
     *
     * Recorded on `test.sql_recorder.app`, the `app` connection the default entity manager (and so
     * `TenantContext`) uses. `IdentityReader` runs on the same connection on every authenticated request
     * and touches exactly the allowlist, so **`GET /me` is driven first as a baseline and its statements
     * are subtracted by exact text** (RED F1) — otherwise the check would pass before the reader ran.
     *
     * Relations are the **union** of two parties (RED F8, LEAD D1): `EXPLAIN (FORMAT JSON)` of each
     * remaining statement as `erp_app` under the tenant, walking the whole plan tree (SubPlans and
     * InitPlans included, RED-2 R2-4); and a text extractor over `FROM`/`JOIN`/comma lists. The planner
     * **drops unused joins** — measured: an unused unique-key `LEFT JOIN`, `ON false`, a sub-select under
     * `WHERE false`, `WHERE false AND EXISTS (…)` (plants A, B, E, F in `roles-s1-red-2.md` §1.5) never reach
     * the plan — and each is one edit from a used read, so only the text party sees it the day it is
     * written. The text party is blind to function bodies, which is why
     * {@see self::noCallableFunctionExistsForTheRoleReadsToHideBehind()} exists; each party is blind where
     * the other sees.
     *
     * **Named limit:** a relation reached only inside a SQL function the read calls is invisible to both
     * parties. The reads call no user-defined function, and the `pg_proc` tripwire keeps that true.
     */
    #[Test]
    public function theRoleReadsTouchOnlyTheirOwnTables(): void
    {
        // Text-party controls.
        foreach ([
            'SELECT r.id FROM role r LEFT JOIN role_grant_proposal p ON p.role_id = r.id',
            'SELECT r.id, (SELECT count(*) FROM role_grant_proposal p WHERE p.role_id = r.id) FROM role r',
            'SELECT r.id FROM public.role_grant_proposal p JOIN role r ON r.id = p.role_id',
            'SELECT r.id FROM role r, role_grant_proposal p WHERE p.role_id = r.id',
            'SELECT r.id FROM role r JOIN "role_grant_proposal" p ON p.role_id = r.id',
        ] as $planted) {
            self::assertContains('role_grant_proposal', self::textRelations($planted), 'The text extractor missed the relation in: '.$planted);
        }
        self::assertSame(['role'], self::textRelations("SELECT extract(epoch from now()), r.id FROM role r WHERE r.name LIKE 'from x'"),
            'The text extractor read a function call or a string literal as a relation.');

        // EXPLAIN-party controls, as erp_app under the tenant: a comma join, and a relation reached only in a SubPlan.
        self::assertContains('role_permission', $this->planRelations('SELECT r.id FROM role r, "role_permission" p WHERE p.role_id = r.id', []),
            'The plan collector missed a comma-joined, quoted relation.');
        self::assertContains('user_role', $this->planRelations('SELECT r.id, CASE WHEN EXISTS (SELECT 1 FROM user_role ur WHERE ur.role_id = r.id) THEN 1 ELSE 0 END FROM role r', []),
            'The plan collector missed a relation reached only inside a SubPlan.');
        // ⚠ The SubPlan control above does NOT prove the walk is whole: the SubPlan is the top node's FIRST child,
        // so a collector reading only the top node and `Plans[0]` passes it (mutation M9d, measured 2026-09-23).
        // A join inside the SubPlan puts its relations at depth two, where only a recursive walk reaches both.
        $deep = $this->planRelations('SELECT r.id, CASE WHEN EXISTS (SELECT 1 FROM user_role ur JOIN app_user u ON u.tenant_id = ur.tenant_id AND u.id = ur.user_id WHERE ur.role_id = r.id) THEN 1 ELSE 0 END FROM role r', []);
        foreach (['user_role', 'app_user'] as $nested) {
            self::assertContains($nested, $deep, 'The plan collector missed a relation two levels down, under a join inside a SubPlan: it does not walk the whole plan tree.');
        }

        // Seed a role with a holder and a code, so every read touches all its joins.
        $role = $this->seedRole(self::TENANT_A, 'Fixture Search Role', ['role.view']);
        $this->grant(self::TENANT_A, $this->seedUser(self::TENANT_A), $role);

        $baseline = $this->recordedDrive('/me');
        self::assertNotSame([], $baseline['statements'], 'GET /me issued no statement on the app connection, so there is no baseline to subtract.');

        // Planted-data control (RED-2 R2-1): the baseline fed back as a drive has no reader statement of its own.
        self::assertSame([], self::readerStatements($baseline['statements'], $baseline['statements']),
            'The baseline subtraction does not remove the baseline from itself.');
        self::assertSame('the reader issued no statement of its own', self::anchorDefect([], []),
            'The anti-vacuity check accepted a drive with no reader statement.');

        $defects = [];
        foreach ($this->roleReadPaths($role) as $path) {
            $drive = $this->recordedDrive($path);
            $own = self::readerStatements($drive['statements'], $baseline['statements']);

            $relationsByStatement = [];
            $parties = [];
            foreach ($own as $sql) {
                $text = self::textRelations($sql);
                $plan = $this->planRelations($sql, $drive['params'][$sql] ?? []);
                $relationsByStatement[$sql] = array_values(array_unique([...$text, ...$plan]));
                foreach ($relationsByStatement[$sql] as $relation) {
                    $parties[$sql][$relation] = implode('+', array_keys(array_filter(['text' => \in_array($relation, $text, true), 'plan' => \in_array($relation, $plan, true)])));
                }
            }

            $anchor = self::anchorDefect($own, $relationsByStatement);
            if (null !== $anchor) {
                $defects[] = $path.': '.$anchor;
            }
            foreach ($relationsByStatement as $sql => $relations) {
                foreach (array_diff($relations, self::ROLE_READ_TABLES) as $stranger) {
                    $defects[] = \sprintf('%s touches %s (seen by %s), which is not in ROLE_READ_TABLES: %s', $path, $stranger, $parties[$sql][$stranger], $sql);
                }
            }
        }

        self::assertSame([], $defects,
            'A role read touches a relation outside the allowlist, or issued nothing of its own. Nothing served under '
            .'role.view may carry privilege history — viewer holds it (PC-16 condition 1, IA §6.8).');
    }

    /**
     * §4(b)'s named limit, guarded from the server (LEAD D2): no function in `public` that a read could call
     * — anything but a trigger function — exists, because a relation read inside one is invisible to both
     * parties of {@see self::theRoleReadsTouchOnlyTheirOwnTables()}. The threat is an **owner-run
     * migration** (a slice-3 `role_pending_count()` helper), not `erp_app`, which has no `CREATE` on
     * `public`. Idiom: `GrantFootprintTest::noSecurityDefinerFunctionExistsInAnyNonSystemSchema`.
     *
     * Positive control: a planted `LANGUAGE sql` function, created as the owner inside `BEGIN … ROLLBACK`,
     * is found by the same query.
     */
    #[Test]
    public function noCallableFunctionExistsForTheRoleReadsToHideBehind(): void
    {
        $owner = $this->owner();
        $owner->beginTransaction();
        try {
            $owner->executeStatement("CREATE FUNCTION public.zz_role_pending(uuid) RETURNS bigint LANGUAGE sql AS 'SELECT count(*) FROM user_role WHERE role_id = \$1'");
            self::assertContains('zz_role_pending', self::callableFunctions($owner), 'The pg_proc query missed a planted callable function.');
        } finally {
            $owner->rollBack();
        }

        self::assertSame([], self::callableFunctions($owner),
            'A callable (non-trigger) function exists in public. The role reads\' table allowlist cannot see inside a '
            .'function, so before this pin moves the function must be proved not to read proposal state, or be given '
            .'a check of its own.');
    }

    /**
     * §4(c) — two-sided, in `AiToolDtoTest`'s `THE_AI_MODULE_IS_UNBUILT` idiom. The server's `public`
     * relations whose name contains `proposal` (case-insensitive — a quoted mixed-case name escapes `LIKE`,
     * RED-2 R2-3; tables, partitioned tables, views, materialised views and foreign tables) must agree with
     * {@see self::PROPOSAL_STORAGE_EXISTS}. Red the day slice 3's migration creates proposal storage, and
     * red if the flag is flipped while none exists.
     *
     * Once storage exists, the behavioural test must exist too — its failure message carries it in full.
     * **Name-keyed:** a proposal table named without `proposal` does not fire this, but §4(b)'s allowlist
     * still reds the first role read that joins it.
     */
    #[Test]
    public function proposalStorageArmsTheInvisibilityTest(): void
    {
        $owner = $this->owner();
        $owner->beginTransaction();
        try {
            $owner->executeStatement('CREATE TABLE public.zz_proposal_probe ()');
            $owner->executeStatement('CREATE VIEW public.zz_proposal_view AS SELECT 1 AS one');
            $owner->executeStatement('CREATE TABLE public."ZZ_Proposal_Upper" ()');
            $owner->executeStatement('CREATE TABLE public.zz_proposal_part (k int) PARTITION BY RANGE (k)');
            $owner->executeStatement('CREATE MATERIALIZED VIEW public.zz_proposal_mat AS SELECT 1 AS one');
            $found = self::relationsNamed($owner, 'proposal');
            foreach (['zz_proposal_probe', 'zz_proposal_view', 'ZZ_Proposal_Upper', 'zz_proposal_part', 'zz_proposal_mat'] as $planted) {
                self::assertContains($planted, $found, 'The proposal-storage query missed a planted relation: '.$planted);
            }
        } finally {
            $owner->rollBack();
        }

        $permission = self::relationsNamed($owner, 'permission');
        sort($permission);
        self::assertSame(['permission', 'role_permission'], $permission, 'The relation query does not find the two relations it must.');

        $exists = [] !== self::relationsNamed($owner, 'proposal');
        self::assertSame(self::PROPOSAL_STORAGE_EXISTS, $exists, $exists
            ? 'Proposal storage now exists in public ('.implode(', ', self::relationsNamed($owner, 'proposal')).'). Flip '
                .'RoleReadTest::PROPOSAL_STORAGE_EXISTS to true in the same commit AND write '
                .'RoleReadTest::aPendingProposalIsInvisibleToTheRoleReads: seed a PENDING proposal on role R for user U; as a '
                .'principal holding ONLY role.view, GET /roles/{R} and GET /roles before and after the insert and assert the '
                .'bodies are byte-identical; repeat as a role.assign holder (R-10 dropped the field for everyone); assert '
                .'holder_count does not move (a proposal confers nothing, SECURITY_MODEL §5.2.2).'
            : 'PROPOSAL_STORAGE_EXISTS is true but no proposal relation exists in public — the flag was flipped early.');

        if (self::PROPOSAL_STORAGE_EXISTS) {
            self::assertTrue(method_exists(self::class, 'aPendingProposalIsInvisibleToTheRoleReads'),
                'Proposal storage exists and RoleReadTest::aPendingProposalIsInvisibleToTheRoleReads does not.');
        }
    }

    /**
     * **IA §6.8 / PC-16 condition 1 — a pending proposal is invisible to every role read** (roles slice 3 C1; design §9
     * item 3; the spec is `proposalStorageArmsTheInvisibilityTest`'s failure message). A PENDING proposal on role R for user
     * U is seeded by owner INSERT; as a principal holding ONLY `role.view`, and again as a `role.assign` holder (R-10
     * dropped the field for everyone), every role read is byte-identical before and after the insert, and `holder_count`
     * does not move (a proposal confers nothing, SECURITY_MODEL §5.2.2). Mutation: add a `pending_*` member to `RoleView`
     * (or join `role_grant_proposal` into `RoleReader`) → red.
     */
    #[Test]
    public function aPendingProposalIsInvisibleToTheRoleReads(): void
    {
        $role = $this->seedRole(self::TENANT_A, 'Proposed', ['invoice.pay', 'role.view']);
        $holder = $this->seedUser(self::TENANT_A);
        $this->grant(self::TENANT_A, $holder, $role);
        $subject = $this->seedUser(self::TENANT_A);
        $readers = [
            'role.view only' => $this->tokenFor(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.view'])),
            'role.assign holder' => $this->tokenFor(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.view', 'role.assign'])),
        ];

        $read = function () use ($role, $readers): array {
            $bodies = [];
            foreach ($readers as $who => $token) {
                foreach ($this->roleReadPaths($role) as $path) {
                    $this->send('GET', $path, headers: ['Authorization' => 'Bearer '.$token]);
                    self::assertSame(200, $this->httpStatus(), $who.' '.$path);
                    $bodies[$who.' '.$path] = (string) $this->client()->getResponse()->getContent();
                }
            }

            return $bodies;
        };

        $before = $read();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
            SELECT gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, '["invoice.pay", "role.view"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
              FROM (SELECT clock_timestamp() AS t) c
            SQL, [self::TENANT_A, $subject, $role, $holder]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM role_grant_proposal WHERE status = 'pending'"),
            'Fixture: the pending proposal was not seeded.');

        self::assertSame($before, $read(), 'A role read changed when a pending proposal was added: it serves privilege history.');
        $this->send('GET', '/roles/'.$role, headers: ['Authorization' => 'Bearer '.$readers['role.view only']]);
        self::assertSame(1, $this->json()['holder_count'], 'A pending proposal moved holder_count: it conferred the role.');
    }

    /**
     * The paths every role read in the registry is driven at, for the given role: `GetRole` always, and
     * `ListRoles` (live, archived, and with `q`) once it is built.
     *
     * @return list<string>
     */
    private function roleReadPaths(string $role): array
    {
        $paths = ['/roles/'.$role];
        if (\array_key_exists('ListRoles', TransactionBoundaryTest::contractOperations())) {
            array_push($paths, '/roles', '/roles?archived=true', '/roles?q=F');
        }

        return $paths;
    }

    /**
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private static function shapeDefects(array $fields): array
    {
        $defects = [];
        foreach ($fields as $field) {
            if (!\in_array($field, self::PINNED_ROLE_FIELDS, true)) {
                $defects[] = 'carries `'.$field.'`, which is not in PINNED_ROLE_FIELDS';
            }
            if (1 === preg_match(self::PRIVILEGE_HISTORY, $field)) {
                $defects[] = '`'.$field.'` names privilege history';
            }
        }
        foreach (array_diff(self::PINNED_ROLE_FIELDS, $fields) as $missing) {
            $defects[] = 'lacks `'.$missing.'`';
        }

        return $defects;
    }

    /**
     * The drive's statements that `GET /me` did not issue — a set difference over statement text. DBAL
     * records prepared text with `?` placeholders, so parameters do not defeat the match. A reader statement
     * whose text equals a `/me` statement is subtracted too, which is harmless: it touches only baseline tables.
     *
     * @param list<string> $drive
     * @param list<string> $baseline
     *
     * @return list<string>
     */
    private static function readerStatements(array $drive, array $baseline): array
    {
        return array_values(array_unique(array_diff($drive, $baseline)));
    }

    /**
     * Anti-vacuity: at least one reader statement, and one of them names `role`.
     *
     * @param list<string>                $own
     * @param array<string, list<string>> $relationsByStatement
     */
    private static function anchorDefect(array $own, array $relationsByStatement): ?string
    {
        if ([] === $own) {
            return 'the reader issued no statement of its own';
        }
        foreach ($relationsByStatement as $relations) {
            if (\in_array('role', $relations, true)) {
                return null;
            }
        }

        return 'no reader statement names `role`';
    }

    /**
     * Relations named in a statement's text: each `JOIN <rel>`, and each comma-separated item of a `FROM`
     * list up to its next clause keyword. Quoted names keep their case and lose their quotes; unquoted names
     * are lower-cased; a `public.` qualifier is dropped (any other schema is kept, and so is not allowlisted).
     * A name followed by `(` is a function call, not a relation; string literals are removed first.
     *
     * @return list<string>
     */
    public static function textRelations(string $sql): array
    {
        $sql = (string) preg_replace("/'(?:[^']|'')*'/", "''", $sql);
        $name = '((?:"[^"]+"|[A-Za-z_][A-Za-z0-9_$]*)(?:\s*\.\s*(?:"[^"]+"|[A-Za-z_][A-Za-z0-9_$]*))?)(\s*\()?';
        $found = [];

        preg_match_all('/\bJOIN\s+'.$name.'/i', $sql, $joins, \PREG_SET_ORDER);
        foreach ($joins as $m) {
            if ('' === ($m[2] ?? '')) {
                $found[] = $m[1];
            }
        }

        preg_match_all('/\bFROM\s+(.*?)(?=\b(?:WHERE|JOIN|LEFT|RIGHT|INNER|FULL|CROSS|GROUP|ORDER|LIMIT|HAVING|UNION|ON|WINDOW|FOR)\b|\)|;|$)/is', $sql, $froms);
        foreach ($froms[1] as $list) {
            foreach (explode(',', $list) as $item) {
                if (1 === preg_match('/^\s*'.$name.'/', $item, $m) && '' === ($m[2] ?? '')) {
                    $found[] = $m[1];
                }
            }
        }

        $normalised = [];
        foreach ($found as $relation) {
            $parts = array_map(static fn (string $p): string => str_starts_with($p, '"') ? trim($p, '"') : strtolower($p),
                preg_split('/\s*\.\s*/', $relation) ?: []);
            if (2 === \count($parts) && 'public' === $parts[0]) {
                array_shift($parts);
            }
            $normalised[] = implode('.', $parts);
        }

        return array_values(array_unique($normalised));
    }

    /**
     * Every `Relation Name` in the statement's plan, as `erp_app` under tenant A — the whole tree, so a
     * relation under a SubPlan or InitPlan is found. Non-queries (anything not `SELECT`/`WITH`) have no plan
     * to read and yield nothing.
     *
     * @param array<int|string, mixed> $params
     *
     * @return list<string>
     */
    private function planRelations(string $sql, array $params): array
    {
        if (1 !== preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            return [];
        }

        $json = $this->tenantContext()->run(self::TENANT_A,
            static fn (EntityManagerInterface $em): string => (string) $em->getConnection()->fetchOne('EXPLAIN (FORMAT JSON) '.$sql, $params));
        $plan = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($plan));

        $relations = [];
        self::collectRelations($plan[0]['Plan'] ?? [], $relations);

        return array_values(array_unique($relations));
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>         $relations
     */
    private static function collectRelations(array $node, array &$relations): void
    {
        if (isset($node['Relation Name'])) {
            $relations[] = (string) $node['Relation Name'];
        }
        foreach ($node['Plans'] ?? [] as $child) {
            self::collectRelations($child, $relations);
        }
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

    /** @return list<string> */
    private static function callableFunctions(Connection $connection): array
    {
        return array_map(strval(...), $connection->fetchFirstColumn(
            "SELECT proname FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND prorettype <> 'trigger'::regtype ORDER BY proname"));
    }

    /** @return list<string> */
    private static function relationsNamed(Connection $connection, string $fragment): array
    {
        return array_map(strval(...), $connection->fetchFirstColumn(
            "SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind IN ('r','p','v','m','f') AND relname ILIKE ? ORDER BY relname",
            ['%'.$fragment.'%']));
    }

    /**
     * @param list<string> $codes
     */
    private function seedRole(string $tenantId, string $name, array $codes, string $description = ''): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, 'INSERT INTO role (id, tenant_id, name, description) VALUES (?::uuid, ?::uuid, ?, ?)',
            [$role, $tenantId, $name, $description]);
        foreach ($codes as $code) {
            $this->insertAsOwnerForTenant($tenantId, 'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [Uuid::v4()->toRfc4122(), $tenantId, $role, $code]);
        }

        return $role;
    }

    private function seedUser(string $tenantId): string
    {
        return $this->seedPrincipal($tenantId, []);
    }

    private function grant(string $tenantId, string $user, string $role): void
    {
        $this->insertAsOwnerForTenant($tenantId, 'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [Uuid::v4()->toRfc4122(), $tenantId, $user, $role]);
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function serializedFields(string $class): array
    {
        $fields = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $renamed = $property->getAttributes(SerializedName::class)[0] ?? null;
            $fields[] = null === $renamed ? $property->getName() : (string) $renamed->newInstance()->serializedName;
        }

        return $fields;
    }
}
