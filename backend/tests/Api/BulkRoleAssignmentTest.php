<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\BulkRoleAssignment;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Idempotency\IdempotencyEnvelopeStore;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateBulkRoleAssignment` over HTTP** (G5-C1, 2026-10-23; `g5-g6-bulk-export-design.md` §4.3 as amended by R1.2 and
 * `g5-g6-final-red.md`; builder's decisions `g5-c1-decisions.md`).
 *
 * **Parity is DIFFERENTIAL** ({@see self::everyMemberVerdictMatchesTheSingleAssignRole}): one fixture per member verdict,
 * seeded twice — `AssignRole` alone runs on one copy, the bulk on the other; the bulk must apply EXACTLY the members whose
 * twin answered 201, write `role.assigned` exactly for them, and count the rest as one undifferentiated `requested −
 * applied` (E-14). The twin's statuses are asserted, so a fixture that stopped exercising its rule cannot pass silently.
 * The wholesale rules (archived role, E-15b, E-15c, E-15e routing) answer once, before any member id is read.
 */
final class BulkRoleAssignmentTest extends PurchaseOrderTestCase
{
    use RoleGrantFixtures;
    use BulkRaceHolder;

    /** The `/bulk` path family's second member (joined into `AuthOperationContractTest`'s shipped-surface union). */
    public const PINNED_BULK_OPERATIONS = [
        'CreateBulkRoleAssignment' => ['method' => 'POST', 'path' => '/bulk/role-assignments', 'idem' => true],
    ];

    // -----------------------------------------------------------------------
    // Parity.
    // -----------------------------------------------------------------------

    /**
     * Design §4.3 `::everyMemberVerdictMatchesTheSingleAssignRole`: deleted, inactive, self, already held, pending live,
     * pending expired, SoD conflict, absent id, another workspace's user, and two good ones — by an OWNER, so slot B (R-21) is
     * reached and no routing applies. *Mutations:* drop PC-13, drop slot B, drop the self check, drop V2-R-6 in the member
     * path → `applied` grows past the twins' 201 count, red.
     */
    #[Test]
    public function everyMemberVerdictMatchesTheSingleAssignRole(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $actor = (string) $this->actorId;
        $role = $this->composedRole(['purchase_order.view', 'purchase_order.create']);
        $approver = $this->composedRole(['purchase_order.approve']);
        $proposer = $this->principal(DefaultRoles::ADMIN);

        $bulkSet = $this->memberFixtures($role, $approver, $proposer, $actor);
        $singleSet = $this->memberFixtures($role, $approver, $proposer, $actor);
        self::assertSame(array_keys($bulkSet), array_keys($singleSet));
        $proposalsBefore = \count($this->proposalRows());

        $expectedApplied = [];
        foreach ($singleSet as $label => [$id, $expected]) {
            $this->send('POST', '/users/'.$id.'/roles', ['role_id' => $role], $this->key());
            self::assertSame($expected, $this->httpStatus(), $label.': the single AssignRole\'s fixture no longer exercises its rule. '.$this->responseContent());
            $expectedApplied[$label] = 201 === $this->httpStatus();
        }
        $assignedBefore = \count($this->auditRowsOf('role.assigned'));

        $this->bulk($role, array_column($bulkSet, 0));

        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame(['requested' => \count($bulkSet), 'applied' => \count(array_filter($expectedApplied)), 'margin_exposure' => false], $this->json());

        $applied = [];
        foreach ($bulkSet as $label => [$id]) {
            if (\in_array($label, ['absent', 'foreign', 'held'], true)) {
                continue;
            }
            self::assertSame($expectedApplied[$label] ? 1 : 0, $this->grantsOf($id, $role), $label);
            if ($expectedApplied[$label]) {
                $applied[] = $id;
            }
        }
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$bulkSet['foreign'][0]]));

        $rows = \array_slice($this->auditRowsOf('role.assigned'), $assignedBefore);
        sort($applied);
        self::assertSame($applied, array_column($rows, 'subject_id'), 'One role.assigned per applied member, in id order, none for the rest.');
        foreach ($rows as $row) {
            self::assertSame([$actor, 'app_user', '{"role_id": "'.$role.'"}'], [$row['actor_user_id'], $row['subject_type'], $row['metadata']]);
        }
        self::assertCount($proposalsBefore, $this->proposalRows(), 'A bulk wrote a proposal.');
    }

    /** R-21 by name: an owner's bulk counts a member with a pending same-pair proposal as not applied. *Mutation:* drop slot B. */
    #[Test]
    public function anOwnersBulkSkipsAMemberWithAPendingSamePairProposal(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $role = $this->composedRole(['role.view']);
        $pending = $this->seedPrincipal(self::TENANT_A, []);
        $this->seedPendingProposal($pending, $role, $this->principal(DefaultRoles::ADMIN));
        $free = $this->seedPrincipal(self::TENANT_A, []);

        $this->bulk($role, [$pending, $free]);

        self::assertSame('{"requested":2,"applied":1,"margin_exposure":false}', $this->responseContent());
        self::assertSame([0, 1], [$this->grantsOf($pending, $role), $this->grantsOf($free, $role)]);
        self::assertSame(['pending'], array_column(array_filter($this->proposalRows(), static fn (array $r): bool => $r['user_id'] === $pending), 'status'));
    }

    // -----------------------------------------------------------------------
    // The wholesale rules: one answer, before any member id is read.
    // -----------------------------------------------------------------------

    /**
     * Design §4.3: a non-owner's bulk of a role needing an owner's confirmation is refused WHOLESALE with the bulk sentence;
     * no statement binds a member id and no proposal is written. *Mutation:* move routing into the member loop → the
     * statement log binds member ids (and the answer becomes a 200 with `applied: 0`), red.
     */
    #[Test]
    public function aNonOwnersBulkOfAnXAdminRoleIsRefusedBeforeAnyMemberIsRead(): void
    {
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $members = [$this->seedPrincipal(self::TENANT_A, []), $this->seedPrincipal(self::TENANT_A, [])];
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->recordedBulk($finance, $members);

        self::assertSame([409, 'user_role.needs_owner_confirmation', BulkRoleAssignment::NEEDS_OWNER_CONFIRMATION],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        $this->assertNoMemberIdWasBound($members);
        self::assertSame([], $this->proposalRows(), 'Bulk never proposes.');
        self::assertSame([0, 0], [$this->grantsOf($members[0], $finance), $this->grantsOf($members[1], $finance)]);
    }

    /**
     * The other wholesale rules, each with `AssignRole`'s own sentence: an unknown role (422), an archived role, E-15b (a
     * non-owner giving a role that makes its holder an owner), E-15c (a composed role beyond the actor's codes). None reads
     * a member. *Mutation:* run the role checks per member → member ids bound, red.
     */
    #[Test]
    public function theRoleIsJudgedOnceBeforeAnyMemberIsRead(): void
    {
        $members = [$this->seedPrincipal(self::TENANT_A, []), $this->seedPrincipal(self::TENANT_A, [])];
        $archived = $this->composedRole(['role.view']);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $ownerRole = $this->systemRole(DefaultRoles::OWNER);
        $beyond = $this->composedRole(['invoice.void']);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->recordedBulk(Uuid::v4()->toRfc4122(), $members);
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame([['/role_id', 'unknown_reference']], array_map(static fn (array $e): array => [$e['pointer'], $e['code']], $this->json()['errors']));
        $this->assertNoMemberIdWasBound($members);

        foreach ([
            'archived' => [$archived, 'user_role.role_is_archived', 'This role is archived, so it can\'t be given to anyone. Choose another role.'],
            'marker' => [$ownerRole, 'user_role.marker_needs_owner', 'Only an owner can give someone this role, because it makes them an owner.'],
            'beyond' => [$beyond, 'user_role.exceeds_actor_permissions', 'This role includes permissions you don\'t have yourself, so you can\'t give it to anyone. Ask an owner, or someone who holds all of its permissions.'],
        ] as $label => [$roleId, $rule, $sentence]) {
            $this->recordedBulk($roleId, $members);
            self::assertSame([409, $rule, $sentence], [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null], $label);
            $this->assertNoMemberIdWasBound($members);
        }
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id IN (?::uuid, ?::uuid)', $members));
        self::assertSame(0, $this->envelopes(), 'A refusal is never stored (D-8: store only 200).');
        self::assertSame([], $this->proposalRows(), 'Bulk never proposes.');
    }

    /**
     * E-1 / E-14 (`BulkOperationTest::thePermissionIsCheckedOnceBeforeAnyIdIsRead`): a caller without `role.assign` naming 50
     * people is refused 403 naming that code, with ONE denial row `(null, null)`, and nothing binds a member id.
     */
    #[Test]
    public function thePermissionIsCheckedOnceBeforeAnyIdIsRead(): void
    {
        $role = $this->composedRole(['role.view']);
        $members = [];
        for ($i = 0; $i < BulkArchival::MAXIMUM_MEMBERS; ++$i) {
            $members[] = Uuid::v4()->toRfc4122();
        }
        $this->actAs(self::TENANT_A, ['app_user.view']);
        $denialsBefore = \count($this->auditRowsOf('authz.permission.denied'));

        $this->recordedBulk($role, $members);

        self::assertSame(403, $this->httpStatus(), $this->responseContent());
        self::assertSame('role.assign', $this->json()['required_permission'] ?? null);
        $rows = $this->auditRowsOf('authz.permission.denied');
        self::assertCount($denialsBefore + 1, $rows, 'One decision, one E-3 row.');
        $row = $rows[\count($rows) - 1];
        self::assertSame([null, null, 'role.assign'], [$row['subject_type'], $row['subject_id'], $row['permission_code']]);
        $this->assertNoMemberIdWasBound($members);
    }

    /**
     * §5.2.5: a caller who LOST `role.assign` while waiting on the role lock meets the gate's 403, byte for byte, committed
     * with one denial row `(null, null)` — never the stored envelope of an earlier same-key request, never a grant.
     */
    #[Test]
    public function aCallerDemotedWhileWaitingIsRefusedAndNeverReplayed(): void
    {
        $role = $this->composedRole(['role.view']);
        $member = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $key = Uuid::v4()->toRfc4122();
        $this->bulk($role, [$member], $key);
        self::assertSame('{"requested":1,"applied":1,"margin_exposure":false}', $this->responseContent());
        $this->executeAsOwnerForTenant(self::TENANT_A, 'DELETE FROM user_role WHERE user_id = ?::uuid AND role_id = ?::uuid', [$member, $role]);
        $denialsBefore = \count($this->auditRowsOf('authz.permission.denied'));

        $holder = $this->raceAgainstTheRoleLock([['DELETE FROM user_role WHERE user_id = ?::uuid', [(string) $this->actorId]]],
            fn () => $this->bulk($role, [$member], $key));

        self::assertTrue($holder['waiter_seen'] && $holder['committed'], json_encode($holder));
        self::assertSame([403, 'role.assign'], [$this->httpStatus(), $this->json()['required_permission'] ?? null], $this->responseContent());
        $rows = $this->auditRowsOf('authz.permission.denied');
        self::assertCount($denialsBefore + 1, $rows);
        // `g5-c1-decisions-red.md` L-1: the lock-time row names the gate's own pair, `(null, null)` + `role.assign`.
        $row = $rows[\count($rows) - 1];
        self::assertSame([null, null, 'role.assign'], [$row['subject_type'], $row['subject_id'], $row['permission_code']]);
        self::assertSame(0, $this->grantsOf($member, $role));
        self::assertSame([], $this->proposalRows(), 'Bulk never proposes.');
    }

    // -----------------------------------------------------------------------
    // The answer.
    // -----------------------------------------------------------------------

    /**
     * E-14 / V2-C-6 closed by refusal: the body's keys are exactly `requested`, `applied`, `margin_exposure`; and
     * `margin_exposure` is the role's PC-12 clause-3 warning (a cost and a sale source), the same for every member.
     * *Mutation:* add a `refused` count → red.
     */
    #[Test]
    public function theResponseNamesNothingButCarriesTheRolesMarginWarning(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $margin = $this->composedRole(['purchase_order.create', 'invoice.issue']);
        $members = [$this->seedPrincipal(self::TENANT_A, []), Uuid::v4()->toRfc4122()];

        $this->bulk($margin, $members);

        self::assertSame([200, BulkArchival::CONTENT_TYPE], [$this->httpStatus(), $this->contentType()]);
        self::assertSame(['requested', 'applied', 'margin_exposure'], array_keys($this->json()));
        self::assertSame('{"requested":2,"applied":1,"margin_exposure":true}', $this->responseContent());
    }

    // -----------------------------------------------------------------------
    // Races.
    // -----------------------------------------------------------------------

    /**
     * Design §4.3: an out-of-band writer (owner SQL takes no role lock) inserts the pair while the bulk is past its read;
     * the bulk's INSERT waits at `user_role_unique` and, once the writer commits, meets a `23505` — rolled back to the
     * member's savepoint, NOT applied, and the other member still applied. *Mutation:* remove the savepoint → 500, red.
     */
    #[Test]
    public function anOutOfBandGrantRaceIsNotAppliedAndNot500(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $role = $this->composedRole(['role.view']);
        [$raced, $other] = $this->sortedUsers();

        $holder = $this->raceAgainstAHolder([[
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)',
            [self::TENANT_A, $raced, $role],
        ]], fn () => $this->bulk($role, [$raced, $other]));

        self::assertTrue($holder['waiter_seen'] && $holder['committed'], json_encode($holder));
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame('{"requested":2,"applied":1,"margin_exposure":false}', $this->responseContent());
        self::assertSame([1, 1], [$this->grantsOf($raced, $role), $this->grantsOf($other, $role)]);
        self::assertSame([$other], array_column($this->auditRowsOf('role.assigned'), 'subject_id'), 'The raced member wrote an audit row.');
        self::assertSame(1, $this->envelopes(), 'The request committed one envelope.');
        self::assertSame([], $this->proposalRows(), 'Bulk never proposes.');
    }

    /**
     * Final RED L-3: a `40P01` inside a member is NEVER "not applied". The holder inserts the higher pair; the bulk inserts the
     * lower, waits on the higher; the holder then inserts the lower — a cycle. Exactly one side is aborted (PostgreSQL picks;
     * never assert which): the request → 409 `bulk.contention`, nothing of it left, nothing stored; or the holder → the bulk
     * applies both. *Mutation:* catch the deadlock per member as "not applied" → a 200 with `applied: 1` after the request was
     * the victim, red.
     */
    #[Test]
    public function aDeadlockInsideAMemberIsContentionNeverNotApplied(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $role = $this->composedRole(['role.view']);
        [$low, $high] = $this->sortedUsers();
        $insert = 'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)';

        $holder = $this->raceAgainstAHolder(
            [[$insert, [self::TENANT_A, $high, $role]]],
            fn () => $this->bulk($role, [$low, $high]),
            [[$insert, [self::TENANT_A, $low, $role]]],
        );

        self::assertTrue($holder['waiter_seen'], json_encode($holder));
        $requestAborted = 409 === $this->httpStatus();
        fwrite(\STDERR, \sprintf("\n[G5-C1 deadlock measurement] bulk role assignment: victim = %s\n", $requestAborted ? 'the request' : 'the holder'));
        if ($requestAborted) {
            self::assertSame(BulkArchival::CONTENTION_RULE, $this->json()['rule'] ?? null, $this->responseContent());
            self::assertTrue($holder['committed'], json_encode($holder));
            self::assertSame([], $this->auditRowsOf('role.assigned'), 'The aborted request left an audit row.');
            self::assertSame(0, $this->envelopes(), 'An aborted request stored an envelope.');

            return;
        }
        self::assertSame('40P01', $holder['sqlstate'], json_encode($holder));
        self::assertSame('{"requested":2,"applied":2,"margin_exposure":false}', $this->responseContent());
    }

    // -----------------------------------------------------------------------
    // The body and the header.
    // -----------------------------------------------------------------------

    #[Test]
    public function duplicateIdsTooManyIdsAndBadUuidsAre422(): void
    {
        $id = Uuid::v4()->toRfc4122();
        $role = Uuid::v4()->toRfc4122();
        $cases = [
            'absent' => [$role, null, [['/user_ids', 'at_least_one_id']]],
            'empty' => [$role, [], [['/user_ids', 'at_least_one_id']]],
            'object' => [$role, ['a' => $id], [['/user_ids', 'not_a_list']]],
            'too many' => [$role, array_map(static fn (): string => Uuid::v4()->toRfc4122(), range(0, BulkArchival::MAXIMUM_MEMBERS)), [['/user_ids', 'too_many_ids']]],
            'not a uuid' => [$role, [$id, 'nope', 7], [['/user_ids/1', 'not_a_uuid'], ['/user_ids/2', 'not_a_uuid']]],
            'duplicate (case-insensitive)' => [$role, [$id, strtoupper($id)], [['/user_ids/1', 'duplicate_id']]],
            'no role' => [null, [$id], [['/role_id', 'required']]],
            'role not a uuid, and no ids' => ['nope', [], [['/role_id', 'not_a_uuid'], ['/user_ids', 'at_least_one_id']]],
        ];

        foreach ($cases as $label => [$roleId, $ids, $expected]) {
            $body = [];
            if (null !== $roleId) {
                $body['role_id'] = $roleId;
            }
            if (null !== $ids) {
                $body['user_ids'] = $ids;
            }
            $this->send('POST', '/bulk/role-assignments', $body, $this->key());

            self::assertSame(422, $this->httpStatus(), $label.': '.$this->responseContent());
            self::assertSame($expected, array_map(static fn (array $e): array => [$e['pointer'], $e['code']], $this->json()['errors']), $label);
        }
    }

    /** §6.2 / P-5: the header is required (428), checked after the permission (E-1). */
    #[Test]
    public function theRetryKeyIsRequiredAfterThePermission(): void
    {
        $body = ['role_id' => Uuid::v4()->toRfc4122(), 'user_ids' => [Uuid::v4()->toRfc4122()]];
        $this->send('POST', '/bulk/role-assignments', $body);
        self::assertSame(428, $this->httpStatus(), $this->responseContent());

        $this->actAs(self::TENANT_A, ['app_user.view']);
        $this->send('POST', '/bulk/role-assignments', $body);
        self::assertSame(403, $this->httpStatus(), $this->responseContent());
    }

    // -----------------------------------------------------------------------
    // The replay store.
    // -----------------------------------------------------------------------

    /**
     * §6.2: the same key and request answer the stored envelope byte for byte. Between the two calls the role is ARCHIVED
     * and one grant is taken away (as the owner), so a recomputation would answer a 409 or re-grant. *Mutation:* recompute on
     * replay → red. Control: a fresh key over the same request does recompute (the archived role's 409).
     */
    #[Test]
    public function aReplayIsByteIdenticalOverHttp(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $role = $this->composedRole(['role.view']);
        [$a, $b] = $this->sortedUsers();
        $key = Uuid::v4()->toRfc4122();

        $this->bulk($role, [$a, $b], $key);
        $first = [$this->httpStatus(), $this->responseContent(), $this->contentType()];
        self::assertSame([200, '{"requested":2,"applied":2,"margin_exposure":false}', BulkArchival::CONTENT_TYPE], $first);

        $this->executeAsOwnerForTenant(self::TENANT_A, 'DELETE FROM user_role WHERE user_id = ?::uuid AND role_id = ?::uuid', [$a, $role]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);

        $this->recordedBulk($role, [$b, $a], $key);
        self::assertSame($first, [$this->httpStatus(), $this->responseContent(), $this->contentType()], 'A replay (ids reordered) is the stored answer.');
        $this->assertNoMemberIdWasBound([$a, $b]);
        self::assertSame(0, $this->grantsOf($a, $role), 'The replay re-ran the work.');
        self::assertCount(2, $this->auditRowsOf('role.assigned'));

        $this->bulk($role, [$a, $b]);
        self::assertSame([409, 'user_role.role_is_archived'], [$this->httpStatus(), $this->json()['rule'] ?? null], 'Control: a new key recomputes.');
    }

    /** §6.2: the same key with another request is 409; with another actor (L-1, the actor in the digest) also 409. */
    #[Test]
    public function aKeyReusedForAnotherRequestOrByAnotherPersonIsAConflict(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $role = $this->composedRole(['role.view']);
        [$a, $b] = $this->sortedUsers();
        $key = Uuid::v4()->toRfc4122();

        $this->bulk($role, [$a], $key);
        self::assertSame(200, $this->httpStatus());

        $this->bulk($role, [$b], $key);
        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame(0, $this->grantsOf($b, $role));

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->bulk($role, [$a], $key);
        self::assertSame(409, $this->httpStatus(), 'Another person\'s reuse of a key is a conflict, never their colleague\'s counts (L-1). '.$this->responseContent());
    }

    /** RO-B1 (a′): an expired key is refused at read (422 `key_expired`) and its row is kept. */
    #[Test]
    public function anExpiredKeyIsRefusedAndKept(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $role = $this->composedRole(['role.view']);
        $member = $this->seedPrincipal(self::TENANT_A, []);
        $key = Uuid::v4()->toRfc4122();
        $this->bulk($role, [$member], $key);
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE idempotency_key SET created_at = now() - interval '25 hours' WHERE key = ?", [IdempotencyKeyHash::of($key)]);

        $this->bulk($role, [$member], $key);

        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame(IdempotencyEnvelopeStore::EXPIRED_CODE, $this->json()['errors'][0]['code']);
        self::assertSame(1, $this->envelopes());
    }

    /**
     * **Design C-1, the race** (R1.2 G5-C1): a same-key, same-request transaction (connection A, which takes no role lock)
     * holds its uncommitted envelope; this request does its member work, its `record()` WAITS at the unique and inserts
     * nothing once A commits. It must roll its work back to `bulk_work` and answer A's envelope byte for byte: no grant, no
     * audit row. A's body (`applied: 0`) is one this request could never compute, so the answer is provably the winner's.
     */
    #[Test]
    public function aConcurrentDuplicateReplaysTheWinnerByteForByteAndLeavesNoLoserWrites(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $role = $this->composedRole(['role.view']);
        $member = $this->seedPrincipal(self::TENANT_A, []);
        $key = Uuid::v4()->toRfc4122();
        $digest = IdempotencyEnvelopeStore::requestDigest(BulkRoleAssignment::OPERATION, (string) $this->actorId, ['role_id' => $role, 'user_ids' => [$member]]);
        $winner = '{"requested":1,"applied":0,"margin_exposure":false}';

        $holder = $this->raceAgainstAHolder([[
            "INSERT INTO idempotency_key (id, tenant_id, operation_id, key, request_digest, response_status, response_digest, response_body, response_headers)
             VALUES (gen_random_uuid(), ?::uuid, ?, ?, decode(?, 'hex'), 200, sha256(convert_to(?, 'UTF8')), convert_to(?, 'UTF8'), ?::jsonb)",
            [self::TENANT_A, BulkRoleAssignment::OPERATION, IdempotencyKeyHash::of($key), $digest, $winner, $winner, '{"content-type":"'.BulkArchival::CONTENT_TYPE.'"}'],
        ]], fn () => $this->bulk($role, [$member], $key));

        self::assertTrue($holder['waiter_seen'] && $holder['committed'], json_encode($holder));
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame($winner, $this->responseContent(), 'The loser must answer the winner\'s envelope.');
        self::assertSame(0, $this->grantsOf($member, $role), 'The loser\'s member work stood.');
        self::assertSame([], $this->auditRowsOf('role.assigned'), 'The loser\'s audit row stood.');
    }

    // -----------------------------------------------------------------------

    /**
     * One fixture per member verdict, seeded fresh: label => [user id, the single AssignRole's status].
     *
     * @return array<string, array{0: string, 1: int}>
     */
    private function memberFixtures(string $role, string $approver, string $proposer, string $actor): array
    {
        $deleted = $this->seedPrincipal(self::TENANT_A, []);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$deleted]);
        $inactive = $this->seedPrincipal(self::TENANT_A, []);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$inactive]);
        $pendingLive = $this->seedPrincipal(self::TENANT_A, []);
        $this->seedPendingProposal($pendingLive, $role, $proposer);
        $pendingExpired = $this->seedPrincipal(self::TENANT_A, []);
        $this->seedPendingProposal($pendingExpired, $role, $proposer, 400);

        return [
            'deleted' => [$deleted, 409],
            'inactive' => [$inactive, 409],
            'self' => [$actor, 409],
            'held' => [$this->holderOf([$role]), 409],
            'pending live' => [$pendingLive, 409],
            'pending expired' => [$pendingExpired, 409],
            'separation of duty' => [$this->holderOf([$approver]), 409],
            'absent' => [Uuid::v4()->toRfc4122(), 404],
            'foreign' => [$this->seedPrincipal(self::TENANT_B, []), 404],
            'good' => [$this->seedPrincipal(self::TENANT_A, []), 201],
            'good too' => [$this->holderOf([$this->composedRole(['inventory_item.view'])]), 201],
        ];
    }

    /** @param list<string> $userIds */
    private function bulk(string $roleId, array $userIds, ?string $key = null): void
    {
        $this->send('POST', '/bulk/role-assignments', ['role_id' => $roleId, 'user_ids' => $userIds], [IdempotencyKeyGuard::HEADER => $key ?? Uuid::v4()->toRfc4122()]);
    }

    /** @param list<string> $userIds */
    private function recordedBulk(string $roleId, array $userIds, ?string $key = null): void
    {
        $this->recorder()->start();
        try {
            $this->bulk($roleId, $userIds, $key);
        } finally {
            $this->recorder()->stop();
        }
    }

    /**
     * E-14's statement-level form: no statement of the recorded request bound any of `$members` (the actor's own reads bind
     * the actor's id, never a member's).
     *
     * @param list<string> $members
     */
    private function assertNoMemberIdWasBound(array $members): void
    {
        $executions = $this->recorder()->executions();
        self::assertNotSame([], $executions, 'Positive control: the recorder saw no bound statement at all.');
        foreach ($executions as $execution) {
            foreach ($execution['params'] as $value) {
                self::assertNotContains(\is_string($value) ? strtolower($value) : $value, $members, 'A member id was read: '.$execution['sql']);
            }
        }
    }

    /**
     * `raceAgainstAHolder`'s shape on the role-administration lock itself (`hold_order_lock.php`'s `role_administration`
     * mode, which issues `RoleAdministrationLock::SQL`): the request waits on the lock while the holder runs `$after`.
     *
     * @param list<array{0: string, 1: list<string|int|null>}> $after
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstTheRoleLock(array $after, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_bulkrole_');
        file_put_contents($readyFile, '');
        $proc = proc_open([PHP_BINARY, __DIR__.'/support/hold_order_lock.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
            'HOLD_TENANT' => self::TENANT_A,
            'HOLD_ORDER' => Uuid::v4()->toRfc4122(),
            'HOLD_TABLE' => 'role_administration',
            'HOLD_BEFORE' => '[]',
            'HOLD_AFTER' => json_encode($after, \JSON_THROW_ON_ERROR),
            'HOLD_READY_FILE' => $readyFile,
            'HOLD_DEADLINE_SECONDS' => '10',
            'PATH' => (string) getenv('PATH'),
        ]);
        self::assertIsResource($proc);
        try {
            $deadline = microtime(true) + 10.0;
            while ('' === trim((string) file_get_contents($readyFile))) {
                if (microtime(true) > $deadline) {
                    self::fail('The holder never took the lock: '.stream_get_contents($pipes[2]));
                }
                usleep(1000);
            }
            $request();
            $out = (string) stream_get_contents($pipes[1]);
        } finally {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($proc);
            @unlink($readyFile);
        }
        $report = json_decode(trim($out), true);
        self::assertIsArray($report, 'The holder printed no report: '.$out);

        return $report;
    }

    /** @return array{0: string, 1: string} two fresh users of `TENANT_A`, byte-sorted ids (the bulk's member order) */
    private function sortedUsers(): array
    {
        $ids = [$this->seedPrincipal(self::TENANT_A, []), $this->seedPrincipal(self::TENANT_A, [])];
        sort($ids, \SORT_STRING);

        return [$ids[0], $ids[1]];
    }

    private function grantsOf(string $userId, string $roleId): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid AND role_id = ?::uuid', [$userId, $roleId]);
    }

    private function envelopes(): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM idempotency_key', []);
    }
}
