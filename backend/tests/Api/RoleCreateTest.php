<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\RoleCreation;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\RoleCompanions;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use App\Tests\Support\SqlRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateRole` — `POST /api/v1/roles`, body `{name, description, permission_codes}`** (roles slice 4, C1;
 * `docs/session13/roles-s4-design.md` §3.1, §4.1, §4.4–§4.6, §7; `SEC` D-4; `DATA` D-10.1; owner D-5a, D-5b, D-6 (1)).
 * One rule per test: the 201 and its body, the one `role.permissions_changed` row, the 428, the case-insensitive name rule,
 * the 422 field errors, E-15f's ceiling on the LOCK-time set (`SEC` D-4), PC-4 with no owner exemption, the companion rows,
 * PC-12's warning (never a refusal), and the composed `X(admin)` delegate (`SEC` D-4's second question). Every refusal's
 * sentence is pinned as a LITERAL — `UX`'s copy (`docs/session13/roles-s4-ux-copy.md` #1–#5), never `sprintf` of the
 * constant, so a wording change is a visible edit and a sentence that drops its `%s` cannot hide behind a surplus argument. **Nothing is written by any refusal** — each refusal test counts `role` and `audit_log`.
 *
 * The concurrency half (the positional lock-first case; the ceiling's demotion twin) lives in `PurchaseOrderRowLockTest`.
 */
final class RoleCreateTest extends ApiTestCase
{
    /** 201; `Location` = `GetRole`'s URI; the body IS `GetRole`'s body, read after the writes; one audit row, cell by cell. */
    #[Test]
    public function aRoleIsCreatedReadBackAndAudited(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->create('Stock clerk', ['inventory_item.view', 'goods_receipt.view', 'audit_log.view_record'], 'Counts things');

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('/api/v1/roles/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));
        self::assertSame([
            'id' => $body['id'],
            'name' => 'Stock clerk',
            'description' => 'Counts things',
            'permission_codes' => ['audit_log.view_record', 'goods_receipt.view', 'inventory_item.view'],
            'is_system' => false,
            'holder_count' => 0,
            'margin_exposure' => false,
            'archived_at' => null,
        ], $body);

        $this->send('GET', '/roles/'.$body['id']);
        self::assertSame($body, $this->json(), 'The 201 body is not GetRole\'s body.');

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor, subject_type, subject_id::text AS subject, ip_address IS NOT NULL AS has_ip, metadata::text AS metadata
              FROM audit_log WHERE event_type LIKE 'role.%'
            SQL);
        self::assertCount(1, $rows, 'Not exactly one role.* row for one creation.');
        self::assertSame(AuditEvent::ROLE_PERMISSIONS_CHANGED, $rows[0]['event_type']);
        self::assertSame((string) $this->actorId, $rows[0]['actor']);
        self::assertSame(['role', $body['id']], [$rows[0]['subject_type'], $rows[0]['subject']]);
        self::assertTrue($rows[0]['has_ip'], 'A security event carries the request IP (E-3).');
        $metadata = json_decode((string) $rows[0]['metadata'], true);
        \assert(\is_array($metadata));
        ksort($metadata); // jsonb stores keys in its own order
        self::assertSame([
            RoleEventMetadata::ADDED => ['audit_log.view_record', 'goods_receipt.view', 'inventory_item.view'],
            RoleEventMetadata::REMOVED => [],
            RoleEventMetadata::ROLE_ID => $body['id'],
        ], $metadata, 'E-15f: the creation event carries the full set, `removed` empty.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM role WHERE is_system'));
    }

    /** A drafted role is legal: `permission_codes: []` confers nothing; the event's `added` is `[]`. `description` defaults to `''`. */
    #[Test]
    public function anEmptyRoleIsADraft(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->create('Draft', []);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([[], ''], [$this->json()['permission_codes'], $this->json()['description']]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type = 'role.permissions_changed' AND metadata->'added' = '[]'::jsonb"));
    }

    /** API-14: a `unique:` cell demands the header — 428, nothing written. */
    #[Test]
    public function aCreateWithoutAnIdempotencyKeyIs428AndWritesNothing(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->send('POST', '/roles', ['name' => 'No key', 'permission_codes' => []]);

        self::assertSame(428, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->assertNothingWritten();
    }

    /**
     * Design §3.1 step 4 / `DATA` D-10.1 item 2: names collide ignoring case, NFKC form, runs of space/`_`/`-` and the ends —
     * so no composed role can be named like a default (`Purchasing Manager` vs `purchasing_manager`). A retry of the same
     * request meets the same rule (the `unique:` mechanism; nothing is stored). An ARCHIVED role's name is free.
     */
    #[Test]
    public function aNameCollidesWithAnyLiveRoleIgnoringCaseSpacingAndSeparators(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name, is_system) VALUES (?::uuid, ?::uuid, ?, true)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, 'purchasing_manager']);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name, deleted_at) VALUES (?::uuid, ?::uuid, ?, now())',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, 'Old clerks']);
        $before = $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM role');

        foreach (['Purchasing Manager', 'PURCHASING-MANAGER', ' purchasing   manager ', 'Purchasing_-Manager', "Purchasing\u{00A0}Manager", 'ｐｕｒｃｈａｓｉｎｇ manager'] as $name) {
            $this->create($name, []);
            self::assertSame([409, 'role.name_taken'], [$this->httpStatus(), $this->json()['rule'] ?? null], 'Not a collision: '.$name);
        }
        self::assertSame('There\'s already a role named "Purchasing Manager", or one that differs from it only in capitals, spaces, dashes or underscores. Choose a different name.', (function (): string {
            $this->create('Purchasing Manager', []);

            return (string) $this->json()['detail'];
        })());
        self::assertSame($before, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM role'));

        $this->create('Old clerks', []);
        self::assertSame(201, $this->httpStatus(), 'An archived role\'s name is not taken. '.(string) $this->client()->getResponse()->getContent());
        $this->create('old clerks', []);
        self::assertSame([409, 'role.name_taken'], [$this->httpStatus(), $this->json()['rule'] ?? null], 'A retry is told the name is taken.');
    }

    /**
     * Step 3's field errors (422, pointer + code) and step 5's unknown code; nothing written by any. The trimmed name is
     * what is stored (Unicode white space at the ends).
     *
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidBodies')]
    public function aMalformedBodyIs422AndWritesNothing(array $body, string $pointer, string $code): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $recorder = self::getContainer()->get('test.sql_recorder.app');
        \assert($recorder instanceof SqlRecorder);
        $recorder->start();
        $this->send('POST', '/roles', $body, [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        $recorder->stop();

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        // `DATA` D-10.1: the application refuses first, so `role_name_shape` / `role_description_shape` never fire on an API
        // write — the CHECKs are a backstop for SQL as `erp_app` (G-10), and their translated 422 must not be what answers.
        self::assertSame([], preg_grep('/INSERT\s+INTO\s+role\b/i', $recorder->statements()), 'A refused body reached the role INSERT.');
        self::assertSame([$pointer, $code], [$this->json()['errors'][0]['pointer'] ?? null, $this->json()['errors'][0]['code'] ?? null]);
        $this->assertNothingWritten();
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'name absent' => [['permission_codes' => []], '/name', 'required'];
        yield 'name not a string' => [['name' => 7, 'permission_codes' => []], '/name', 'not_a_string'];
        yield 'name blank' => [['name' => " \u{3000}\t", 'permission_codes' => []], '/name', 'blank'];
        yield 'name too long' => [['name' => str_repeat('n', 101), 'permission_codes' => []], '/name', 'too_long'];
        yield 'name zero-width' => [['name' => "Own\u{200B}er", 'permission_codes' => []], '/name', 'invalid_character'];
        yield 'name bidi override' => [['name' => "Clerk\u{202E}", 'permission_codes' => []], '/name', 'invalid_character'];
        yield 'name control' => [['name' => "Cl\u{0007}erk", 'permission_codes' => []], '/name', 'invalid_character'];
        yield 'description not a string' => [['name' => 'D', 'description' => [], 'permission_codes' => []], '/description', 'not_a_string'];
        yield 'description too long' => [['name' => 'D', 'description' => str_repeat('d', 501), 'permission_codes' => []], '/description', 'too_long'];
        yield 'description control' => [['name' => 'D', 'description' => "a\u{0001}b", 'permission_codes' => []], '/description', 'invalid_character'];
        yield 'codes absent' => [['name' => 'C'], '/permission_codes', 'required'];
        yield 'codes not a list' => [['name' => 'C', 'permission_codes' => 'role.view'], '/permission_codes', 'not_a_list'];
        yield 'codes an object' => [['name' => 'C', 'permission_codes' => ['a' => 'role.view']], '/permission_codes', 'not_a_list'];
        yield 'code not a string' => [['name' => 'C', 'permission_codes' => ['role.view', 3]], '/permission_codes/1', 'not_a_string'];
        yield 'code duplicated' => [['name' => 'C', 'permission_codes' => ['role.view', 'role.view']], '/permission_codes/1', 'duplicate_value'];
        yield 'code unknown' => [['name' => 'C', 'permission_codes' => ['role.view', 'role.fly']], '/permission_codes/1', 'unknown_reference'];
        yield 'code with a NUL' => [['name' => 'C', 'permission_codes' => ["role.view\u{0000}"]], '/permission_codes/0', 'unknown_reference'];
    }

    /** Unicode white space at the ends is trimmed before storing; the stored name passes `role_name_shape`. */
    #[Test]
    public function theNameIsStoredTrimmed(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->create("\u{3000} Night shift\u{00A0}", []);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('Night shift', $this->json()['name']);
    }

    /**
     * **E-15f's ceiling (`SEC` D-4, V2-R-7):** a composed actor holding `role.create` but not `invoice.void` cannot put
     * `invoice.void` in a role — 409 naming only the code it lacks, nothing written; within its set → 201. An actor without
     * `role.create` is the gate's 403.
     */
    #[Test]
    public function aRoleCannotHoldACodeTheCreatorLacks(): void
    {
        $this->actAs(self::TENANT_A, ['role.create', 'role.view', 'invoice.view', 'invoice.issue']);
        $this->create('Voider', ['invoice.view', 'invoice.void']);

        self::assertSame([409, 'role.exceeds_actor_permissions'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame('This role would include permissions you don\'t have yourself, and a role can only be given permissions its creator holds. Leave those out, or ask an owner to create it.', $this->json()['detail']);
        $this->assertNothingWritten();

        $this->create('Viewer of invoices', ['invoice.view']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->actAs(self::TENANT_A, ['role.view', 'role.edit']);
        $this->create('Anything', []);
        self::assertSame(403, $this->httpStatus());
    }

    /**
     * **`SEC` D-4, second question:** a composed delegate holding `role.create` and an `X(admin)` code (`invoice.void`) may
     * create an unheld role carrying it — 201 — and a non-owner's grant of it then routes to a proposal (E-15e), as it would
     * for any role meeting `X(admin)`. Nothing beyond the ceiling is needed at creation.
     */
    #[Test]
    public function aDelegateHoldingAnAdminExcludedCodeCreatesAnUnheldRoleThatStillRoutesThroughAnOwner(): void
    {
        $this->actAs(self::TENANT_A, ['role.create', 'role.view', 'invoice.view', 'invoice.void']);
        $this->create('Void desk', ['invoice.view', 'invoice.void']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $role = (string) $this->json()['id'];
        self::assertSame(0, $this->json()['holder_count']);

        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $role], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame([409, 'user_role.exceeds_actor_permissions'], [$this->httpStatus(), $this->json()['rule'] ?? null],
            'admin lacks invoice.void, so E-15c refuses it before routing: '.(string) $this->client()->getResponse()->getContent());

        $this->actAs(self::TENANT_A, ['role.assign', 'role.view', 'invoice.view', 'invoice.void']);
        $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $role], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame([409, 'user_role.needs_owner_confirmation'], [$this->httpStatus(), $this->json()['rule'] ?? null],
            'A non-owner\'s grant of a role meeting X(admin) is not routed to an owner (E-15e).');
    }

    /**
     * **PC-4 (ADR-008 §2) — no owner exemption** (design §4.5): an owner cannot compose a role holding both halves of a
     * split. The sentence names the work and the row ids (`RoleAssignment::SOD_CLAUSES`), never a code.
     */
    #[Test]
    public function aRoleSpanningASeparationOfDutyIsRefusedEvenToAnOwner(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->create('Buyer-approver', ['purchase_order.view', 'purchase_order.create', 'purchase_order.approve']);

        self::assertSame([409, 'role.separation_of_duty'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame('One role can\'t cover both sides of a job that has to be split: anyone given it could raise purchase orders and also approve or cancel them (SoD-1). Leave out the permissions for one side.', $this->json()['detail']);
        self::assertStringNotContainsString('purchase_order.', (string) $this->json()['detail']);
        $this->assertNothingWritten();

        $this->create('Co-owner', DefaultRoles::codesFor(DefaultRoles::OWNER));
        self::assertSame([409, 'role.separation_of_duty'], [$this->httpStatus(), $this->json()['rule'] ?? null], 'A "co-owner" spans every split.');
    }

    /**
     * **Companion rows (owner D-5a, D-5b, D-6 option (1); `RoleCompanions`)** — each row refused on its own, by an owner
     * (who holds every code, so the ceiling is not what refuses); the role with the companion added is created.
     *
     * @param list<string> $codes
     */
    #[Test]
    #[DataProvider('companionRows')]
    public function aRoleMissingACompanionIsRefused(array $codes, string $missing): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->create('Companion case', $codes);

        self::assertSame([409, 'role.missing_companion_view'], [$this->httpStatus(), $this->json()['rule'] ?? null], (string) $this->client()->getResponse()->getContent());
        self::assertSame(RoleCreation::missingCompanionMessage(RoleCompanions::missingFrom($codes)), $this->json()['detail']);
        self::assertSame([['holding' => $codes[0], 'requires' => $missing]], array_map(
            static fn (array $row): array => ['holding' => $row['holding'], 'requires' => $row['requires']],
            RoleCompanions::missingFrom($codes),
        ), 'The case breaks more than its own row.');
        self::assertDoesNotMatchRegularExpression('/[a-z_]+\.[a-z_]+/', (string) $this->json()['detail'], 'A permission code in the sentence (UX R-1).');
        $this->assertNothingWritten();

        $this->create('Companion case', [...$codes, $missing]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function companionRows(): iterable
    {
        foreach (RoleCompanions::rows() as $row) {
            // Every OTHER companion is present, TRANSITIVELY (users slice 1 C3: `role.delete` → `role.assign` →
            // `app_user.view`, owner Q-U3), so only this row can refuse and the role with this row's code added is complete.
            $codes = [$row['holding']];
            do {
                $before = $codes;
                foreach (RoleCompanions::rows() as $other) {
                    if (\in_array($other['holding'], [...$codes, $row['requires']], true) && $other !== $row && !\in_array($other['requires'], $codes, true)
                        && $other['requires'] !== $row['requires']) {
                        $codes[] = $other['requires'];
                    }
                }
            } while ($codes !== $before);
            yield $row['ruling'].' '.$row['holding'].' needs '.$row['requires'] => [$codes, $row['requires']];
        }
    }

    /**
     * **`UX` copy #5, pinned literally on its two-clause example** (`roles-s4-ux-copy.md` §3.1): `{role.delete}` alone breaks
     * D-5b and D-6 (1), in `RoleCompanions` order, joined with `; `.
     */
    #[Test]
    public function theCompanionSentenceNamesTheWorkOnBothSidesAndNoCode(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->create('Deleter', ['role.delete']);

        self::assertSame([409, 'role.missing_companion_view', 'Some permissions in this role only work alongside others it doesn\'t have: '
            .'deleting roles needs seeing roles; deleting roles needs giving roles to people and taking them away. '
            .'Add the missing ones, or leave out the ones that need them.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        $this->assertNothingWritten();
    }

    /**
     * **Every companion row has its clause, and no clause is orphaned** (`UX` copy §3.1 — the `RoleAssignment::SOD_CLAUSES`
     * precedent): a new `RoleCompanions` row cannot ship without its sentence, and the map holds no row the table lacks.
     */
    #[Test]
    public function everyCompanionRowHasItsClause(): void
    {
        $rows = array_map(static fn (array $row): string => $row['holding'].' -> '.$row['requires'], RoleCompanions::rows());
        $clauses = [];
        foreach (RoleCreation::COMPANION_CLAUSES as $holding => $byRequires) {
            foreach ($byRequires as $requires => $clause) {
                self::assertNotSame('', $clause);
                $clauses[] = $holding.' -> '.$requires;
            }
        }
        sort($rows);
        sort($clauses);
        self::assertSame($rows, $clauses);
    }

    /**
     * **`UX` copy #1 on `CreateRole` (RED B-L2: no test drove an inactive ACTOR here).** An actor holding `role.create` whose
     * account is not active is refused, and nothing is written. Until G4-C0 that was the 409 `user_role.actor_is_inactive`
     * with the role-definition sentence, because `IdentityReader` ignored `is_active`.
     * **G4-C0 (2026-10-22): API-O-22 CLOSED (a)** — `IdentityReader` refuses an inactive principal, so this actor-side
     * request is the constant 401 at the edge (`authentication-failed`). The under-lock `user_role.actor_is_inactive`
     * backstop is kept, reachable only when `is_active` is cleared outside `UpdateUser` (SQL) while the request waits on
     * the lock (G4-C1's race test).
     */
    #[Test]
    public function anInactiveActorCannotCreateARole(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$this->actorId]);
        $this->create('Stock clerk', ['inventory_item.view']);

        self::assertSame([401, 'urn:erp:problem:authentication-failed'], [$this->httpStatus(), $this->json()['type'] ?? null]);
        $this->assertNothingWritten();
    }

    /** **PC-12 clause 3:** a composed role holding a cost and a sale source is WARNED (`margin_exposure`), never refused. */
    #[Test]
    public function aMarginExposedRoleIsWarnedNeverRefused(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->create('Margin reader', ['purchase_order.view', 'invoice.view']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertTrue($this->json()['margin_exposure']);
    }

    /** Unaccepted members (`is_system`, `id`, …) are refused by the denormalizer — `is_system` can never be set here (E-15h). */
    #[Test]
    public function aBodyCannotSetIsSystem(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->send('POST', '/roles', ['name' => 'Sneaky', 'permission_codes' => [], 'is_system' => true], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

        self::assertContains($this->httpStatus(), [400, 422], (string) $this->client()->getResponse()->getContent());
        $this->assertNothingWritten();
    }

    /** @param list<mixed> $codes */
    private function create(string $name, array $codes, ?string $description = null): void
    {
        $body = ['name' => $name, 'permission_codes' => $codes];
        if (null !== $description) {
            $body['description'] = $description;
        }
        $this->send('POST', '/roles', $body, [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    private function assertNothingWritten(): void
    {
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type = 'role.permissions_changed'"),
            'A refused create wrote a role.permissions_changed row.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM role WHERE name NOT IN ('purchasing_manager', 'Old clerks') AND id NOT IN (SELECT role_id FROM user_role)"),
            'A refused create wrote a role.');
    }
}
