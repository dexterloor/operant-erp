<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\ApiResource\AuditRecordResource;
use App\Modules\AuditLog\Application\AuditRecordCriteria;
use App\Modules\AuditLog\Application\AuditRecordReader;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Support\SqlRecorder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Audit reads C2 — `ListAuditRecords`**, `GET /api/v1/audit-log/records`, `audit_log.view_record` — one record's History
 * (PC-11's colleague-readable half; `docs/session13/audit-reads-design.md` §3.2, §4, R1.5; `audit-reads-final-red.md`;
 * `audit-reads-owner-q-ruling.md` §3 and §5 C2, H-3 = (ii) code-level).
 *
 * Pins (the path family is pinned in `AuditLogReadTest::PINNED_AUDIT_OPERATIONS`):
 *  - the row's members (no `metadata`, no `ip_address`), and its inline wire schema equal to them (N6, N17);
 *  - **refusal order:** the gate's 403 first (a non-holder with no parameters, N9), then 422 `required` on each missing
 *    parameter, then the subject's own code (E-5): 403 naming it and one E-3 row on the query's pair (N1, N10), before
 *    any History statement runs;
 *  - **R-7:** a credit note's History under `invoice.view` (N2); a contact's under `customer.view`;
 *  - **the allowlist:** a denial and a sign-in naming the subject never appear (N3, N5); `role.*` on a user → empty page;
 *  - **the bound:** both halves of the subject pair (N7); another tenant's id → empty; an unknown id → empty, not 404;
 *  - newest first, a tie group walked at `limit=1` both ways (C1's M2/M3 on this read);
 *  - **H-3 (ii), code-level:** a hard-deleted contact's History is served to `customer.view` holders (N16; `RESIDUAL_GAPS.md`
 *    G-52 trigger (a)); a deleted draft order's History is its deletion row only (N18; G-52 trigger (b)); an archived
 *    customer's History is served.
 */
final class AuditRecordReadTest extends PurchaseOrderTestCase
{
    /** `AuditRecordResource`'s serialized members — §4.4.3's AuditRecord row, plus `id`. */
    public const PINNED_RECORD_FIELDS = ['id', 'occurred_at', 'event_type', 'actor', 'actor_label', 'subject_type', 'subject_id'];

    /** The reader's own page statement — `FROM (SELECT … FROM audit_log a WHERE a.subject_type` — never the bare table name. */
    private const READER_SHAPE = '/FROM \\(SELECT a\\.id[^)]* FROM audit_log a WHERE a\\.subject_type = /';

    private const VIEW_RECORD = 'audit_log.view_record';

    #[Test]
    public function theRecordShapeIsTheRowsMinusMetadataAndAddress(): void
    {
        $members = [];
        foreach (new \ReflectionClass(AuditRecordResource::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attribute = new \ReflectionProperty(AuditRecordResource::class, $parameter->getName())->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $attribute ? $parameter->getName() : $attribute->newInstance()->serializedName;
        }

        self::assertSame(self::PINNED_RECORD_FIELDS, $members);
        self::assertSame(self::PINNED_RECORD_FIELDS, array_keys(AuditRecordResource::WIRE_SCHEMA['properties']));
        self::assertSame(self::PINNED_RECORD_FIELDS, AuditRecordResource::WIRE_SCHEMA['required']);
    }

    /** A History row is served without `metadata` and `ip_address`, even when the row carries both (N6, N17; literal). */
    #[Test]
    public function aRowIsServedWithoutItsMetadataOrAddress(): void
    {
        $order = Uuid::v4()->toRfc4122();
        $row = $this->entry(AuditEvent::PURCHASE_ORDER_CANCELLED, '2026-05-01 10:00:00+00', null, 'Ann', 'purchase_order', $order, '{"reason":"secret reason"}');
        $this->actAs(self::TENANT_A, [self::VIEW_RECORD, 'purchase_order.view']);

        $this->send('GET', '/audit-log/records?subject_type=purchase_order&subject_id='.$order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $items = $this->json()['items'];
        self::assertSame([$row], array_column($items, 'id'));
        self::assertSame(self::PINNED_RECORD_FIELDS, array_keys($items[0]));
        $raw = (string) $this->client()->getResponse()->getContent();
        self::assertStringNotContainsString('secret reason', $raw, 'metadata leaked onto the History.');
        self::assertStringNotContainsString('ip_address', $raw);
        self::assertStringNotContainsString('metadata', $raw);
        self::assertSame(['purchase_order', $order, 'Ann', null], [$items[0]['subject_type'], $items[0]['subject_id'], $items[0]['actor_label'], $items[0]['actor']]);
    }

    /** Newest first; the live actor name beside the label as recorded. */
    #[Test]
    public function rowsAreNewestFirstWithTheLiveActorName(): void
    {
        $order = Uuid::v4()->toRfc4122();
        $alice = $this->user('Alice Live');
        $old = $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-01-01 00:00:00+00', $alice, 'Alice Then', 'purchase_order', $order);
        $new = $this->entry(AuditEvent::PURCHASE_ORDER_CANCELLED, '2026-02-01 00:00:00+00', null, 'system', 'purchase_order', $order);
        $this->actAs(self::TENANT_A, [self::VIEW_RECORD, 'purchase_order.view']);

        $this->send('GET', '/audit-log/records?subject_type=purchase_order&subject_id='.$order);

        self::assertSame(200, $this->httpStatus());
        $items = $this->json()['items'];
        self::assertSame([$new, $old], array_column($items, 'id'));
        self::assertNull($items[0]['actor']);
        self::assertSame(['id' => $alice, 'display_name' => 'Alice Live'], $items[1]['actor']);
        self::assertSame('Alice Then', $items[1]['actor_label']);
    }

    /**
     * **Refusal order (N9):** a caller without `audit_log.view_record` gets the gate's 403 even with no parameters; a holder
     * missing either parameter gets 422 `required` on that pointer — never a 500, never a whole-tenant History.
     */
    #[Test]
    public function theGateRefusesFirstThenEachMissingParameterIsRequired(): void
    {
        $this->actAs(self::TENANT_A, ['purchase_order.view']);
        $this->send('GET', '/audit-log/records');
        self::assertSame(403, $this->httpStatus(), 'A non-holder must be refused by the gate before any parameter is read.');

        $this->actAs(self::TENANT_A, [self::VIEW_RECORD, 'purchase_order.view']);
        $this->send('GET', '/audit-log/records');
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '?subject_type', 'code' => 'required'], ['pointer' => '?subject_id', 'code' => 'required']], $this->errorCodes());

        $this->send('GET', '/audit-log/records?subject_type=purchase_order');
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '?subject_id', 'code' => 'required']], $this->errorCodes());

        $this->send('GET', '/audit-log/records?subject_id='.Uuid::v4()->toRfc4122());
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '?subject_type', 'code' => 'required']], $this->errorCodes());
    }

    /** The schema refuses a subject type with no History and a malformed id (422, never a 500). */
    #[Test]
    public function anUnknownSubjectTypeAndAMalformedIdAreRefused(): void
    {
        $this->actAs(self::TENANT_A, [self::VIEW_RECORD, 'purchase_order.view']);

        $this->send('GET', '/audit-log/records?subject_type=role_grant_proposal&subject_id='.Uuid::v4()->toRfc4122());
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertContains('?subject_type', array_column($this->json()['errors'], 'pointer'));

        $this->send('GET', '/audit-log/records?subject_type=purchase_order&subject_id=not-a-uuid');
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertContains('?subject_id', array_column($this->json()['errors'], 'pointer'));
    }

    /**
     * **E-5 (N1, N10):** `.view_record` without the subject's own code → 403 naming THAT code, exactly one E-3 row naming
     * the code and the query's `(subject_type, subject_id)`, and no History statement runs.
     */
    #[Test]
    public function aHolderWithoutTheSubjectsCodeIsRefusedBeforeTheHistoryIsRead(): void
    {
        $order = Uuid::v4()->toRfc4122();
        $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-03-01 00:00:00+00', null, 'system', 'purchase_order', $order);
        $this->actAs(self::TENANT_A, [self::VIEW_RECORD, 'sales_order.view']);
        $recorder = self::getContainer()->get('test.sql_recorder.app');
        \assert($recorder instanceof SqlRecorder);

        $recorder->start();
        $this->send('GET', '/audit-log/records?subject_type=purchase_order&subject_id='.$order);
        $recorder->stop();

        self::assertSame(403, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertStringContainsString('purchase_order.view', (string) $this->client()->getResponse()->getContent());
        self::assertSame([], $recorder->matching(self::READER_SHAPE), 'The History was read for a caller E-5 refused.');
        self::assertNotSame([], $recorder->matching('/audit_log/'), 'Control: the denial\'s own statements were not recorded, so the match above proves nothing.');

        $denials = $this->auditRowsOf(AuditEvent::AUTHZ_PERMISSION_DENIED);
        self::assertCount(1, $denials);
        self::assertSame(['purchase_order.view', 'purchase_order', $order, $this->actorId],
            [$denials[0]['permission_code'], $denials[0]['subject_type'], $denials[0]['subject_id'], $denials[0]['actor_user_id']]);

        // Control for the match: a holder's read DOES run the reader's statement.
        $this->actAs(self::TENANT_A, [self::VIEW_RECORD, 'purchase_order.view']);
        $recorder->start();
        $this->send('GET', '/audit-log/records?subject_type=purchase_order&subject_id='.$order);
        $recorder->stop();
        self::assertSame(200, $this->httpStatus());
        self::assertNotSame([], $recorder->matching(self::READER_SHAPE), 'Control: the reader\'s statement is not recognised by its shape.');
    }

    /**
     * **R-7 (N2):** a credit note's History is read under `invoice.view` — no `credit_note.view` exists — and a contact's
     * under `customer.view`. Default roles: `finance` (holds `invoice.view`) and `viewer` (holds `customer.view`).
     */
    #[Test]
    public function aChildIsReadOnItsParentsCodeThroughPc9(): void
    {
        $credit = Uuid::v4()->toRfc4122();
        $issued = $this->entry(AuditEvent::CREDIT_NOTE_ISSUED, '2026-03-01 00:00:00+00', null, 'system', 'credit_note', $credit);
        $contact = Uuid::v4()->toRfc4122();
        $added = $this->entry(AuditEvent::CUSTOMER_CONTACT_CREATED, '2026-03-02 00:00:00+00', null, 'system', 'customer_contact', $contact);

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::FINANCE));
        self::assertContains(self::VIEW_RECORD, DefaultRoles::codesFor(DefaultRoles::FINANCE), 'Control: finance does not hold .view_record.');
        $this->send('GET', '/audit-log/records?subject_type=credit_note&subject_id='.$credit);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$issued], array_column($this->json()['items'], 'id'));

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $this->send('GET', '/audit-log/records?subject_type=customer_contact&subject_id='.$contact);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$added], array_column($this->json()['items'], 'id'));
    }

    /**
     * **The allowlist (N3, N5):** a denial and a failed sign-in naming this purchase order are never on its History; the
     * order's business row is. `role.*` rows subjecting a user (SECURITY) make a user's History an empty page, never a 422.
     */
    #[Test]
    public function onlyAllowlistedEventsAppear(): void
    {
        $order = Uuid::v4()->toRfc4122();
        $placed = $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-03-01 00:00:00+00', null, 'system', 'purchase_order', $order);
        $this->entry(AuditEvent::AUTHZ_PERMISSION_DENIED, '2026-03-02 00:00:00+00', null, 'system', 'purchase_order', $order, '{}', '203.0.113.7');
        $user = $this->user('Subject User');
        $this->entry(AuditEvent::ROLE_ASSIGNED, '2026-03-03 00:00:00+00', null, 'system', 'app_user', $user);

        $this->actAs(self::TENANT_A, [self::VIEW_RECORD, 'purchase_order.view', 'app_user.view']);
        $this->send('GET', '/audit-log/records?subject_type=purchase_order&subject_id='.$order);
        self::assertSame(200, $this->httpStatus());
        self::assertSame([$placed], array_column($this->json()['items'], 'id'), 'A security row reached a History.');

        $this->send('GET', '/audit-log/records?subject_type=app_user&subject_id='.$user);
        self::assertSame(200, $this->httpStatus(), 'A user\'s History is an empty page, not a refusal.');
        self::assertSame([], $this->json()['items']);
        self::assertTrue($this->json()['tenant_has_rows']);
    }

    /** N3's source half: the statement admits by an `IN` built from the method — no exclusion form anywhere in the reader. */
    #[Test]
    public function theAllowlistIsAnInclusionBuiltFromTheMethod(): void
    {
        [$sql, $parameters] = AuditRecordReader::listStatement(new AuditRecordCriteria('purchase_order', Uuid::v4()->toRfc4122()));
        $source = (string) file_get_contents((string) new \ReflectionClass(AuditRecordReader::class)->getFileName());

        foreach (['/NOT\s+IN\b/i', '/NOT\s+LIKE\b/i', '/<>\s*ALL\b/i', '/!=\s*ALL\b/i'] as $exclusion) {
            self::assertDoesNotMatchRegularExpression($exclusion, $sql, 'The History statement excludes rather than admits.');
            self::assertDoesNotMatchRegularExpression($exclusion, (string) preg_replace('#/\*.*?\*/#s', '', $source), 'The reader\'s source carries an exclusion form.');
        }
        self::assertStringContainsString('a.event_type IN (', $sql);
        $admitted = array_values(array_filter(AuditEvent::ALL, AuditEvent::isReadableAsRecordHistory(...)));
        self::assertSame($admitted, \array_slice($parameters, 2, \count($admitted)), 'The bound IN list is not the method\'s answer over the catalogue.');
        self::assertSame(AuditEvent::BUSINESS, $admitted, 'Control: the method and BUSINESS disagree, so the comparison above is about the wrong set.');
    }

    /**
     * **The bound (N7):** the same uuid under two types is two Histories; another tenant's subject is an empty page; an
     * unknown id is an empty page, not a 404.
     */
    #[Test]
    public function theSubjectPairAndTheTenantBoundTheHistory(): void
    {
        $shared = Uuid::v4()->toRfc4122();
        $asCustomer = $this->entry(AuditEvent::CUSTOMER_UPDATED, '2026-03-01 00:00:00+00', null, 'system', 'customer', $shared);
        $this->entry(AuditEvent::SUPPLIER_UPDATED, '2026-03-02 00:00:00+00', null, 'system', 'supplier', $shared);
        $foreign = Uuid::v4()->toRfc4122();
        $this->entry(AuditEvent::CUSTOMER_UPDATED, '2026-03-03 00:00:00+00', null, 'system', 'customer', $foreign, tenant: self::TENANT_B);

        $this->actAs(self::TENANT_A, [self::VIEW_RECORD, 'customer.view', 'supplier.view']);

        $this->send('GET', '/audit-log/records?subject_type=customer&subject_id='.$shared);
        self::assertSame([$asCustomer], array_column($this->json()['items'], 'id'), 'A row of another subject type with the same id leaked across.');

        foreach ([$foreign, Uuid::v4()->toRfc4122()] as $id) {
            $this->send('GET', '/audit-log/records?subject_type=customer&subject_id='.$id);
            self::assertSame(200, $this->httpStatus());
            self::assertSame([], $this->json()['items']);
            self::assertTrue($this->json()['tenant_has_rows']);
        }
    }

    /** A tie group (one transaction's rows share `now()`) walked at `limit=1` forward and backward, each row once. */
    #[Test]
    public function aTieGroupIsWalkedOneRowAtATimeInBothDirections(): void
    {
        $order = Uuid::v4()->toRfc4122();
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->entry(AuditEvent::PURCHASE_ORDER_PLACED, '2026-04-01 12:00:00+00', null, 'system', 'purchase_order', $order);
        }
        usort($ids, static fn (string $a, string $b): int => strcmp($b, $a));
        $this->actAs(self::TENANT_A, [self::VIEW_RECORD, 'purchase_order.view']);
        $path = '/audit-log/records?subject_type=purchase_order&subject_id='.$order;

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $this->send('GET', $path.'&limit=1'.(null === $cursor ? '' : '&after='.urlencode($cursor)));
            self::assertSame(200, $this->httpStatus());
            $body = $this->json();
            $seen = [...$seen, ...array_column($body['items'], 'id')];
            $cursor = $body['next_cursor'];
        } while (null !== $cursor && ++$pages < 10);
        self::assertSame($ids, $seen);

        $backward = array_column($body['items'], 'id');
        $cursor = $body['previous_cursor'];
        $pages = 0;
        while (null !== $cursor && $pages++ < 10) {
            $this->send('GET', $path.'&limit=1&before='.urlencode($cursor));
            self::assertSame(200, $this->httpStatus());
            $backward = [...array_column($this->json()['items'], 'id'), ...$backward];
            $cursor = $this->json()['previous_cursor'];
        }
        self::assertSame($ids, $backward);
    }

    /** `?sort` is undeclared; a cursor whose key is not a timestamp is `malformed_cursor`, never a 500. */
    #[Test]
    public function sortIsUndeclaredAndAForeignCursorIsMalformed(): void
    {
        $this->actAs(self::TENANT_A, [self::VIEW_RECORD, 'purchase_order.view']);
        $path = '/audit-log/records?subject_type=purchase_order&subject_id='.Uuid::v4()->toRfc4122();

        $this->send('GET', $path.'&sort=occurred_at');
        self::assertSame(422, $this->httpStatus());
        self::assertSame('undeclared_parameter', $this->json()['errors'][0]['code'] ?? null);

        $this->send('GET', $path.'&after='.urlencode(new \App\Platform\Http\Pagination\Cursor('SKU-1', Uuid::v4()->toRfc4122())->encode()));
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '?after', 'code' => 'malformed_cursor']], $this->errorCodes());
    }

    /**
     * ⚖ **H-3 (ii), code-level — `RESIDUAL_GAPS.md` G-52** (`audit-reads-owner-q-ruling.md` §3, judge ruling under the owner's
     * delegation): a contact added, changed and then HARD-deleted keeps its History, readable by id to a `customer.view`
     * holder — `viewer` included. Mutation N16 (filter to subjects that still exist) → red here.
     */
    #[Test]
    public function aDeletedChildsHistoryIsServedToItsViewHolders(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'History Customer');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));
        $this->send('POST', '/customers/'.$customer.'/contacts', ['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'phone' => null, 'is_primary' => false],
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $contact = (string) $this->json()['id'];
        $this->send('PATCH', '/customers/'.$customer.'/contacts/'.$contact, ['name' => 'Ada King']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('DELETE', '/customers/'.$customer.'/contacts/'.$contact);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM customer_contact WHERE id = ?::uuid', [$contact]), 'Control: the contact is not gone.');

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $this->send('GET', '/audit-log/records?subject_type=customer_contact&subject_id='.$contact);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(
            [AuditEvent::CUSTOMER_CONTACT_DELETED, AuditEvent::CUSTOMER_CONTACT_UPDATED, AuditEvent::CUSTOMER_CONTACT_CREATED],
            array_column($this->json()['items'], 'event_type'),
            'A deleted contact\'s History must stay readable by id to customer.view holders: History is code-level (H-3 (ii)), and '
            .'RESIDUAL_GAPS.md G-52 records the exposure. If this went empty because a row-scoped check was added, that is G-52\'s '
            .'trigger (a) — the first row-scoped permission — and the ruling must move with it, deliberately.',
        );
    }

    /**
     * `RESIDUAL_GAPS.md` G-52 trigger (b)'s pin: a deleted draft sales order's History is exactly its deletion row — no
     * `sales_order.created`/`.updated` event exists. Mutation N18 (mint and write a `sales_order.created`) → red here.
     */
    #[Test]
    public function aDeletedDraftOrdersHistoryIsItsDeletionRowOnly(): void
    {
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-152000');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));
        $this->send('DELETE', '/sales-orders/'.$order);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/audit-log/records?subject_type=sales_order&subject_id='.$order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([AuditEvent::SALES_ORDER_DELETED], array_column($this->json()['items'], 'event_type'),
            'A deleted draft order\'s History is its deletion row only (G-52\'s "only its deletion row"). A second row means an order '
            .'event now carries more of the record: re-check G-52 trigger (b) and its Low rating.');
    }

    /** An archived customer's History is served — created, then deleted (archive) — to `customer.view` holders. */
    #[Test]
    public function anArchivedCustomersHistoryIsServed(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->send('POST', '/customers', ['code' => 'C-HIST', 'name' => 'History Ltd', 'country_code' => 'DE', 'currency_code' => 'EUR',
            'payment_terms_days' => 30], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $customer = (string) $this->json()['id'];
        $this->send('DELETE', '/customers/'.$customer);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $this->send('GET', '/audit-log/records?subject_type=customer&subject_id='.$customer);

        self::assertSame(200, $this->httpStatus());
        self::assertSame([AuditEvent::CUSTOMER_DELETED, AuditEvent::CUSTOMER_CREATED], array_column($this->json()['items'], 'event_type'));
    }

    // -----------------------------------------------------------------------

    /** @return string the new user's id, in tenant A */
    private function user(string $displayName): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', ?)",
            [$id, self::TENANT_A, 'history-'.$id.'@example.test', $displayName]);

        return $id;
    }

    private function entry(string $eventType, string $occurredAt, ?string $actor, string $label, string $subjectType, string $subjectId,
        string $metadata = '{}', ?string $ip = null, string $tenant = self::TENANT_A): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenant, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, event_type, actor_user_id, actor_label, subject_type, subject_id, metadata, ip_address, occurred_at)
            VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?, ?, ?::uuid, ?::jsonb, ?::inet, ?::timestamptz)
            SQL, [$id, $tenant, $eventType, $actor, $label, $subjectType, $subjectId, $metadata, $ip, $occurredAt]);

        return $id;
    }

    /** @return list<array{pointer: string, code: string}> */
    private function errorCodes(): array
    {
        return array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']);
    }
}
