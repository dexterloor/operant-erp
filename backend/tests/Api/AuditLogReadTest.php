<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\ApiResource\AuditLogEntryResource;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Pagination\Cursor;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Support\SqlRecorder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Audit reads C1 — `ListAuditLogEntries`**, `GET /api/v1/audit-log`, `audit_log.view` (PC-11's whole-trail half;
 * `docs/session13/audit-reads-design.md` §3.1 and R1.3; `audit-reads-final-red.md`; `audit-reads-owner-q-ruling.md` §5 C1).
 *
 * Pins:
 *  - the `/audit-log` paths are exactly {@see self::PINNED_AUDIT_OPERATIONS}, joined into
 *    `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union;
 *  - the entry's members, and its inline wire schema equal to them;
 *  - newest first, `occurred_at DESC, id DESC`; a TIE GROUP (one transaction's rows share `now()` — unless the transaction
 *    was raised by `audit_log_monotonic_occurred_at`, which splits it) walked at `limit=1` both ways and a large one at
 *    `limit=50`, every row exactly once (M2, M3); two rows inside one millisecond in order; newest first across a backward
 *    clock step (T-H′);
 *  - each filter narrows; both together are 422 `one_filter_at_a_time` on both pointers; an `event_type` outside the
 *    catalogue is refused; `?sort` is undeclared (§14.2 item 30); a foreign cursor is `malformed_cursor`, never a 500;
 *  - `actor` is the LIVE name, `actor_label` the name as recorded (M6); a row with no actor serves `actor: null` (M5);
 *  - a security row serves its `ip_address`, a business row `null`; `metadata` is served as stored — an opaque object,
 *    bare `*_id` keys and decimals included, `{}` as `{}` (M8, the M-3 ruling);
 *  - E-1: only `audit_log.view` holders read (`viewer` holds `.view_record` only) → 403 and one E-3 row; on that 403 no
 *    statement of the reader's shape runs;
 *  - **G-35's control** ({@see self::theTrailShowsEveryPaymentTermsValueSet()}): every payment-terms value a `sales_rep`
 *    sets — at create, on update, and raised-then-put-back — is readable by the owner through the trail (M17, M18).
 *
 * V1-S-5b's reader half (the E-12 marker read back through this list) is in `LoginAuditCapTest`, which owns the rig.
 */
final class AuditLogReadTest extends PurchaseOrderTestCase
{
    /**
     * The `/audit-log` operations, held equal to the registry. `ListAuditRecords` (`/audit-log/records`) joined at C2.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_AUDIT_OPERATIONS = [
        'ListAuditLogEntries' => ['method' => 'GET', 'path' => '/audit-log', 'idem' => false],
        // Audit reads C2: one record's History (`AuditRecordReadTest`).
        'ListAuditRecords' => ['method' => 'GET', 'path' => '/audit-log/records', 'idem' => false],
    ];

    /** The reader's own page statement — `FROM (SELECT … FROM audit_log a …` — never the bare table name. */
    private const READER_SHAPE = '/FROM \\(SELECT a\\.id[^)]* FROM audit_log a[ )]/';

    /** `AuditLogEntryResource`'s serialized members — §4.4.3's AuditLogEntry row, plus `id`. */
    public const PINNED_ENTRY_FIELDS = ['id', 'occurred_at', 'event_type', 'actor', 'actor_label', 'subject_type', 'subject_id', 'metadata', 'ip_address'];

    #[Test]
    public function theAuditLogPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/audit-log(/|$)#', $path)) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $path];
            }
        }
        ksort($declared);
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_AUDIT_OPERATIONS);
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /** Independent of `docs/`: the DTO's serialized members, in order. */
    #[Test]
    public function theEntryShapeIsTheRowsAndNothingElse(): void
    {
        $members = [];
        foreach (new \ReflectionClass(AuditLogEntryResource::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attribute = new \ReflectionProperty(AuditLogEntryResource::class, $parameter->getName())->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $attribute ? $parameter->getName() : $attribute->newInstance()->serializedName;
        }

        self::assertSame(self::PINNED_ENTRY_FIELDS, $members);
        self::assertNotContains('permission_code', $members, 'permission_code is not served until SEC agrees (design §3.1).');
    }

    /** The inline item schema publishes exactly the DTO's members (the `StockLevelResource` precedent). */
    #[Test]
    public function theWireSchemaIsTheResourcesOwnMembers(): void
    {
        self::assertSame(self::PINNED_ENTRY_FIELDS, array_keys(AuditLogEntryResource::WIRE_SCHEMA['properties']));
        self::assertSame(self::PINNED_ENTRY_FIELDS, AuditLogEntryResource::WIRE_SCHEMA['required']);
    }

    /** Newest first; each filter narrows to its rows (M1-adjacent, M9). */
    #[Test]
    public function entriesAreNewestFirstAndEachFilterNarrows(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $old = $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-01-01 00:00:00+00', $alice);
        $mid = $this->entry(AuditEvent::SALES_ORDER_CANCELLED, '2026-02-01 00:00:00+00', $bob);
        $new = $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-03-01 00:00:00+00', $bob);

        $this->actAs(self::TENANT_A, ['audit_log.view']);

        $this->send('GET', '/audit-log');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$new, $mid, $old], $this->ids([$new, $mid, $old]));

        $this->send('GET', '/audit-log?actor_user_id='.$bob);
        self::assertSame([$new, $mid], array_column($this->json()['items'], 'id'));

        $this->send('GET', '/audit-log?event_type='.AuditEvent::PURCHASE_ORDER_PLACED);
        self::assertSame([$new, $old], array_column($this->json()['items'], 'id'));
    }

    /**
     * **The mutation that matters (M2, M3):** three rows at ONE instant — one transaction's rows share `now()` — walked at
     * `limit=1` forward, then backward from the last page, each seen exactly once and in `id DESC` order.
     */
    #[Test]
    public function aTieGroupIsWalkedOneRowAtATimeInBothDirectionsWithoutSkipOrRepeat(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-04-01 12:00:00+00');
        }
        $expected = $ids;
        usort($expected, static fn (string $a, string $b): int => strcmp($b, $a));
        $this->actAs(self::TENANT_A, ['audit_log.view']);

        [$forward, $lastBody] = $this->walk('/audit-log?event_type='.AuditEvent::PURCHASE_ORDER_PLACED, 1);
        self::assertSame($expected, $forward);

        $backward = array_column($lastBody['items'], 'id');
        $cursor = $lastBody['previous_cursor'];
        $pages = 0;
        while (null !== $cursor && $pages++ < 10) {
            $this->send('GET', '/audit-log?event_type='.AuditEvent::PURCHASE_ORDER_PLACED.'&limit=1&before='.urlencode($cursor));
            self::assertSame(200, $this->httpStatus());
            $backward = [...array_column($this->json()['items'], 'id'), ...$backward];
            $cursor = $this->json()['previous_cursor'];
        }
        self::assertSame($expected, $backward, 'The backward walk inside the tie group skipped or repeated a row.');
    }

    /** A large tie group (120 at one instant) walked at `limit=50`: three pages, every row once, `id DESC` inside. */
    #[Test]
    public function aLargeTieGroupIsWalkedAtTheDefaultPageSizeWithoutSkipOrRepeat(): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, event_type, actor_label, occurred_at)
            SELECT gen_random_uuid(), ?::uuid, 'purchase_order.placed', 'system', '2026-05-01 08:00:00+00'
            FROM generate_series(1, 120)
            SQL, [self::TENANT_A]);
        $this->actAs(self::TENANT_A, ['audit_log.view']);

        [$seen] = $this->walk('/audit-log?event_type='.AuditEvent::PURCHASE_ORDER_PLACED, 50);

        self::assertCount(120, $seen);
        self::assertCount(120, array_unique($seen), 'A row was repeated across pages inside the tie group.');
        $sorted = $seen;
        usort($sorted, static fn (string $a, string $b): int => strcmp($b, $a));
        self::assertSame($sorted, $seen, 'Inside a tie group the order is id DESC.');
    }

    /** Two rows 400 µs apart inside ONE millisecond walk in order: the cursor key is the column's raw text, not the wire's. */
    #[Test]
    public function twoEntriesInsideOneMillisecondWalkInOrder(): void
    {
        $earlier = $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-06-01 10:00:00.000100+00');
        $later = $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-06-01 10:00:00.000500+00');
        $this->actAs(self::TENANT_A, ['audit_log.view']);

        [$seen] = $this->walk('/audit-log?event_type='.AuditEvent::PURCHASE_ORDER_PLACED, 1);

        self::assertSame([$later, $earlier], $seen);
    }

    /** One filter at a time (design L-5): both → 422 `one_filter_at_a_time` on both pointers, before any read. */
    #[Test]
    public function bothFiltersAtOnceAreRefusedOnBothPointers(): void
    {
        $this->actAs(self::TENANT_A, ['audit_log.view']);
        $this->send('GET', '/audit-log?actor_user_id='.Uuid::v4()->toRfc4122().'&event_type='.AuditEvent::PURCHASE_ORDER_PLACED);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '?actor_user_id', 'code' => 'one_filter_at_a_time'], ['pointer' => '?event_type', 'code' => 'one_filter_at_a_time']],
            $this->errorCodes());
    }

    /** M9: an event type outside the catalogue is refused naming `?event_type` — never ignored, never a 500. */
    #[Test]
    public function anEventTypeOutsideTheCatalogueIsRefused(): void
    {
        $this->actAs(self::TENANT_A, ['audit_log.view']);

        foreach (['purchase_order.updated', 'auth.', "x' OR '1'='1"] as $unknown) {
            self::assertFalse(AuditEvent::isKnown($unknown));
            $this->send('GET', '/audit-log?event_type='.urlencode($unknown));
            self::assertSame(422, $this->httpStatus(), $unknown.': '.$this->client()->getResponse()->getContent());
            self::assertSame('?event_type', $this->json()['errors'][0]['pointer']);
        }
    }

    /** §14.2 item 30: `sort` is not declared. A cursor whose key is not a timestamp is refused, never cast (M10). */
    #[Test]
    public function sortIsUndeclaredAndAForeignCursorIsMalformed(): void
    {
        $this->actAs(self::TENANT_A, ['audit_log.view']);
        $this->send('GET', '/audit-log?sort=occurred_at');
        self::assertSame(422, $this->httpStatus());
        self::assertContains(['pointer' => '?sort', 'code' => 'undeclared_parameter'], $this->errorCodes());

        foreach (['after', 'before'] as $name) {
            $this->send('GET', '/audit-log?'.$name.'='.urlencode(new Cursor('SKU-1', Uuid::v4()->toRfc4122())->encode()));
            self::assertSame(422, $this->httpStatus(), $name.': '.$this->client()->getResponse()->getContent());
            self::assertSame([['pointer' => '?'.$name, 'code' => 'malformed_cursor']], $this->errorCodes());
        }

        $this->send('GET', '/audit-log?after=not-a-cursor');
        self::assertSame(422, $this->httpStatus());
    }

    /** M6: `actor.display_name` is the user's LIVE name; `actor_label` keeps the name as recorded. */
    #[Test]
    public function theActorIsTheLiveNameAndTheLabelIsTheNameAsRecorded(): void
    {
        $alice = $this->user('Alice Old');
        $id = $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-01-01 00:00:00+00', $alice, 'Alice Old');
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE app_user SET display_name = 'Alice New' WHERE id = ?::uuid", [$alice]);
        $this->actAs(self::TENANT_A, ['audit_log.view']);

        $this->send('GET', '/audit-log?actor_user_id='.$alice);

        self::assertSame(200, $this->httpStatus());
        $row = $this->json()['items'][0];
        self::assertSame($id, $row['id']);
        self::assertSame(['id' => $alice, 'display_name' => 'Alice New'], $row['actor']);
        self::assertSame('Alice Old', $row['actor_label']);
    }

    /** M5: a row with no actor (a system action) is served with `actor: null` — never dropped by an inner join. */
    #[Test]
    public function aRowWithNoActorServesActorNull(): void
    {
        $id = $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-01-01 00:00:00+00', null, 'system');
        $this->actAs(self::TENANT_A, ['audit_log.view']);

        $this->send('GET', '/audit-log?event_type='.AuditEvent::PURCHASE_ORDER_PLACED);

        self::assertSame(200, $this->httpStatus());
        self::assertSame([[
            'id' => $id, 'occurred_at' => '2026-01-01T00:00:00.000+00:00', 'event_type' => 'purchase_order.placed', 'actor' => null,
            'actor_label' => 'system', 'subject_type' => null, 'subject_id' => null, 'metadata' => [], 'ip_address' => null,
        ]], $this->json()['items']);
        self::assertStringContainsString('"metadata":{}', (string) $this->client()->getResponse()->getContent(),
            'An empty metadata object must be served as {}, never [].');
    }

    /**
     * A security row serves its `ip_address`; a business row serves `null`. `metadata` is served as stored, opaque: a bare
     * `*_id` key, a decimal and a nested list arrive verbatim (the M-3 ruling; M8 strips it → red).
     */
    #[Test]
    public function aSecurityRowServesItsAddressAndMetadataIsServedAsStored(): void
    {
        $alice = $this->user('Alice');
        $contact = Uuid::v4()->toRfc4122();
        $customer = Uuid::v4()->toRfc4122();
        $failure = $this->entry(AuditEvent::AUTH_LOGIN_FAILURE, '2026-01-01 00:00:00+00', $alice, 'Alice', null, null, '{}', '203.0.113.7');
        $deleted = $this->entry(AuditEvent::CUSTOMER_CONTACT_DELETED, '2026-01-02 00:00:00+00', $alice, 'Alice', 'customer_contact', $contact,
            json_encode(['customer_id' => $customer], \JSON_THROW_ON_ERROR));
        $count = $this->entry(AuditEvent::STOCK_MOVEMENT_ADJUSTED, '2026-01-03 00:00:00+00', $alice, 'Alice', 'stock_movement', Uuid::v4()->toRfc4122(),
            json_encode(['counted_quantity' => '7.000000', 'note' => 'shelf recount'], \JSON_THROW_ON_ERROR));
        $this->actAs(self::TENANT_A, ['audit_log.view']);

        $this->send('GET', '/audit-log?actor_user_id='.$alice);

        self::assertSame(200, $this->httpStatus());
        $byId = array_column($this->json()['items'], null, 'id');
        self::assertSame('203.0.113.7', $byId[$failure]['ip_address']);
        self::assertNull($byId[$deleted]['ip_address']);
        self::assertSame(['customer_id' => $customer], $byId[$deleted]['metadata']);
        self::assertSame(['subject_type' => 'customer_contact', 'subject_id' => $contact],
            ['subject_type' => $byId[$deleted]['subject_type'], 'subject_id' => $byId[$deleted]['subject_id']]);
        $counted = $byId[$count]['metadata'];
        ksort($counted); // jsonb stores object keys in its own order; the trail serves them as stored.
        self::assertSame(['counted_quantity' => '7.000000', 'note' => 'shelf recount'], $counted);
    }

    /** An unknown and another workspace's actor answer byte-equal empty pages; `tenant_has_rows` says the trail has rows. */
    #[Test]
    public function anUnknownAndAForeignActorAreTheSameEmptyPage(): void
    {
        $this->actAs(self::TENANT_B, ['audit_log.view']);
        $foreign = (string) $this->actorId;
        $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-01-01 00:00:00+00', $foreign, 'Fixture Actor', null, null, '{}', null, self::TENANT_B);
        $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-01-01 00:00:00+00');
        $this->actAs(self::TENANT_A, ['audit_log.view']);

        $this->send('GET', '/audit-log?actor_user_id='.$foreign);
        self::assertSame(200, $this->httpStatus());
        $theirs = (string) $this->client()->getResponse()->getContent();
        $this->send('GET', '/audit-log?actor_user_id='.Uuid::v4()->toRfc4122());
        self::assertSame($theirs, (string) $this->client()->getResponse()->getContent());
        self::assertSame(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => true], $this->json());
    }

    /**
     * E-1 (M4): only holders of `audit_log.view` read the whole trail — `owner` and `admin` of the default roles; `viewer`
     * holds `audit_log.view_record` only and is refused like every other role.
     */
    #[Test]
    public function onlyHoldersOfTheWholeTrailCodeReadIt(): void
    {
        $holders = [];
        foreach (DefaultRoles::names() as $role) {
            $codes = DefaultRoles::codesFor($role);
            $this->actAs(self::TENANT_A, $codes);
            $holds = \in_array('audit_log.view', $codes, true);
            $this->send('GET', '/audit-log');
            self::assertSame($holds ? 200 : 403, $this->httpStatus(), $role);
            if ($holds) {
                $holders[] = $role;
            }
        }
        sort($holders);

        self::assertSame(['admin', 'owner'], $holders, 'PC-11: the whole trail is the owner\'s and the admin\'s.');
        self::assertContains('audit_log.view_record', DefaultRoles::codesFor('viewer'));
        self::assertNotContains('audit_log.view', DefaultRoles::codesFor('viewer'));
    }

    /**
     * E-1 / E-3: a refused caller gets the house 403 naming `audit_log.view`, one E-3 row is written, and **no statement of
     * the reader's shape runs** — matched on the reader's own `FROM (SELECT … FROM audit_log a`, not on the table name:
     * `DenialAuditWriter`'s cap read-back mentions `audit_log` on every denial, so a table-name match would be vacuous.
     */
    #[Test]
    public function aRefusedCallerIsDeniedBeforeTheTrailIsRead(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor('viewer'));
        $recorder = self::getContainer()->get('test.sql_recorder.app');
        \assert($recorder instanceof SqlRecorder);

        $recorder->start();
        $this->send('GET', '/audit-log');
        $recorder->stop();

        self::assertSame(403, $this->httpStatus());
        self::assertSame([], $recorder->matching(self::READER_SHAPE), 'The trail was read for a caller the gate refused.');
        self::assertNotSame([], $recorder->matching('/audit_log/'), 'Control: the denial\'s own audit statements were not recorded, so the match above proves nothing.');

        $denials = $this->auditRowsOf(AuditEvent::AUTHZ_PERMISSION_DENIED);
        self::assertCount(1, $denials);
        self::assertSame('audit_log.view', $denials[0]['permission_code']);

        // Control for the match: a holder's read DOES run the reader's statement.
        $this->actAs(self::TENANT_A, ['audit_log.view']);
        $recorder->start();
        $this->send('GET', '/audit-log');
        $recorder->stop();
        self::assertSame(200, $this->httpStatus());
        self::assertNotSame([], $recorder->matching(self::READER_SHAPE), 'Control: the reader\'s statement is not recognised by its shape.');
    }

    /**
     * **G-35's control** (`audit-reads-owner-q-ruling.md` §5 C1; replaces final RED H-1's "read `GetCustomer`" check, which
     * a set-and-revert defeats). As `sales_rep`: create a customer at 365 days; move a second from 30 to 365; raise a third
     * from 30 to 365 and put it back. Then as `owner`, the trail filtered by `event_type` shows each value with the rep as
     * `actor` — `customer.created` `{payment_terms_days}`, `customer.updated` `{fields, payment_terms_days: {from, to}}` — so
     * a long term is visible from the log without finding the invoice, a term put back included.
     *
     * Held by `OwedAmendmentTripwireTest::noAuditReadShipsWithoutReRatingG35` (the G-35 implication): G-35 is Low only while
     * this test exists. Mutations M17 (strip `metadata`) and M18 (ignore the `event_type` filter) → red here.
     */
    #[Test]
    public function theTrailShowsEveryPaymentTermsValueSet(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));
        $rep = (string) $this->actorId;

        $long = $this->createCustomer('C-LONG', 365);
        $moved = $this->createCustomer('C-MOVED', 30);
        $reverted = $this->createCustomer('C-REVERT', 30);
        $this->patchCustomer($moved, ['payment_terms_days' => 365]);
        $this->patchCustomer($reverted, ['payment_terms_days' => 365]);
        $this->patchCustomer($reverted, ['payment_terms_days' => 30]);

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));

        $this->send('GET', '/audit-log?event_type='.AuditEvent::CUSTOMER_CREATED);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $created = $this->json()['items'];
        self::assertSame([AuditEvent::CUSTOMER_CREATED], array_values(array_unique(array_column($created, 'event_type'))));
        self::assertSame([
            $reverted => ['payment_terms_days' => 30],
            $moved => ['payment_terms_days' => 30],
            $long => ['payment_terms_days' => 365],
        ], array_column($created, 'metadata', 'subject_id'));
        foreach ($created as $row) {
            self::assertSame($rep, $row['actor']['id'] ?? null, 'The creator is not the actor of its customer.created row.');
        }

        $this->send('GET', '/audit-log?event_type='.AuditEvent::CUSTOMER_UPDATED);
        self::assertSame(200, $this->httpStatus());
        $updated = $this->json()['items'];
        self::assertSame([AuditEvent::CUSTOMER_UPDATED], array_values(array_unique(array_column($updated, 'event_type'))));
        self::assertSame([
            [$reverted, ['fields' => ['payment_terms_days'], 'payment_terms_days' => ['from' => 365, 'to' => 30]]],
            [$reverted, ['fields' => ['payment_terms_days'], 'payment_terms_days' => ['from' => 30, 'to' => 365]]],
            [$moved, ['fields' => ['payment_terms_days'], 'payment_terms_days' => ['from' => 30, 'to' => 365]]],
        ], array_map(static fn (array $row): array => [$row['subject_id'], self::sortedTerms($row['metadata'])], $updated));
        foreach ($updated as $row) {
            self::assertSame($rep, $row['actor']['id'] ?? null, 'The editor is not the actor of its customer.updated row.');
        }
    }

    // -----------------------------------------------------------------------

    /** @return string the new user's id, in tenant A, holding nothing */
    private function user(string $displayName): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', ?)",
            [$id, self::TENANT_A, 'audit-'.$id.'@example.test', $displayName]);

        return $id;
    }

    /** An audit row, as the owner. @return string its id */
    private function entry(string $eventType, string $occurredAt, ?string $actor = null, string $label = 'system', ?string $subjectType = null,
        ?string $subjectId = null, string $metadata = '{}', ?string $ip = null, string $tenant = self::TENANT_A): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenant, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, event_type, actor_user_id, actor_label, subject_type, subject_id, metadata, ip_address, occurred_at)
            VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?, ?, ?::uuid, ?::jsonb, ?::inet, ?::timestamptz)
            SQL, [$id, $tenant, $eventType, $actor, $label, $subjectType, $subjectId, $metadata, $ip, $occurredAt]);

        return $id;
    }

    /**
     * The listed ids among $among, in the list's order (rows the setup itself wrote are ignored).
     *
     * @param list<string> $among
     *
     * @return list<string>
     */
    private function ids(array $among): array
    {
        return array_values(array_filter(array_column($this->json()['items'], 'id'), static fn (string $id): bool => \in_array($id, $among, true)));
    }

    /**
     * **T-H′ — the trail stays in the order things happened when the database clock steps back** (`Version20261022004000`;
     * `docs/session13/audit-order-clock-design.md` R1.3; Move 702), over `ListAuditLogEntries`. A customer is created; the
     * owner plants an update row 5 s in the database's future (what the last row looks like to the next writer after a
     * backward step); the customer is changed over HTTP. Newest first: the change (raised to just after the planted row),
     * the planted row, the creation — and a `limit=1` walk forward and back sees each row once, in that order. Red without
     * the trigger (M1).
     */
    #[Test]
    public function theTrailStaysInOrderWhenTheClockStepsBack(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $customer = $this->createCustomer('C-ORDER', 30);
        [$created] = array_column($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid', [AuditEvent::CUSTOMER_CREATED, $customer]), 'id');

        $planted = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, event_type, actor_label, subject_type, subject_id, occurred_at)
            VALUES (?::uuid, ?::uuid, ?, 'Ahead', 'customer', ?::uuid, clock_timestamp() + interval '5 seconds')
            SQL, [$planted, self::TENANT_A, AuditEvent::CUSTOMER_UPDATED, $customer]);
        $this->patchCustomer($customer, ['payment_terms_days' => 45]);
        $changed = array_values(array_diff(array_column($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid', [AuditEvent::CUSTOMER_UPDATED, $customer]), 'id'), [$planted]));
        self::assertCount(1, $changed);
        $expected = [$changed[0], $planted, $created];

        $this->send('GET', '/audit-log');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($expected, $this->ids($expected),
            'The trail is out of order: the change made after the clock stepped back sorts below the event it followed.');

        [$seen, $last] = $this->walk('/audit-log', 1);
        self::assertSame(array_unique($seen), $seen, 'A forward walk repeated a row.');
        self::assertSame($expected, array_values(array_filter($seen, static fn (string $id): bool => \in_array($id, $expected, true))),
            'A forward walk skipped or reordered a row across the raised pair.');

        $backward = array_column($last['items'], 'id');
        $cursor = $last['previous_cursor'];
        $pages = 0;
        while (null !== $cursor && $pages++ < 500) {
            $this->send('GET', '/audit-log?limit=1&before='.urlencode($cursor));
            self::assertSame(200, $this->httpStatus());
            $backward = [...array_column($this->json()['items'], 'id'), ...$backward];
            $cursor = $this->json()['previous_cursor'];
        }
        self::assertSame($seen, $backward, 'A backward walk did not retrace the forward walk.');
    }

    /**
     * Follow `next_cursor` from the first page; return the ids seen and the last page's body.
     *
     * @return array{list<string>, array<string, mixed>}
     */
    private function walk(string $path, int $limit): array
    {
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $this->send('GET', $path.(str_contains($path, '?') ? '&' : '?').'limit='.$limit.(null === $cursor ? '' : '&after='.urlencode($cursor)));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $body = $this->json();
            $seen = [...$seen, ...array_column($body['items'], 'id')];
            $cursor = $body['next_cursor'];
        } while (null !== $cursor && ++$pages < 500);

        return [$seen, $body];
    }

    /** @return list<array{pointer: string, code: string}> */
    private function errorCodes(): array
    {
        return array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']);
    }

    /** @return string the created customer's id */
    private function createCustomer(string $code, int $terms): string
    {
        $this->send('POST', '/customers', ['code' => $code, 'name' => $code.' Ltd', 'country_code' => 'DE', 'currency_code' => 'EUR',
            'payment_terms_days' => $terms], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return (string) $this->json()['id'];
    }

    /** @param array<string, mixed> $body */
    private function patchCustomer(string $id, array $body): void
    {
        $this->send('PATCH', '/customers/'.$id, $body);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * jsonb stores object keys in its own order (`to` before `from`); the trail serves them as stored, so compare sorted.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private static function sortedTerms(array $metadata): array
    {
        if (\is_array($metadata['payment_terms_days'] ?? null)) {
            ksort($metadata['payment_terms_days']);
        }
        ksort($metadata);

        return $metadata;
    }
}
