<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditEvent;
use App\Audit\DenialAuditWriter;
use App\Audit\PasswordChangeAuditWriter;
use App\Auth\Infrastructure\AuthTrailWriter;
use App\Tests\Tenancy\TenancyTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **API-16 / `ROADMAP.md` *Exit criteria — M0-b* — `ip_address` is written on
 * security events and on nothing else, and this is the test that goes red when a
 * business-event writer populates the column.**
 *
 * ===========================================================================
 * Why this file exists at all
 * ===========================================================================
 * M0-b's criterion has two clauses and only one of them was ever about code:
 *
 * > *fails if:* a writer on a business event passes a non-null `ip_address`,
 * > **or** the rule exists only as prose with no test that goes red when a
 * > business-event writer populates the column.
 *
 * The first clause held on inspection and the second did not: before this file,
 * `grep -rn 'ip_address' backend/tests` found no rule test at all, so the whole
 * of the control was two writers that happen to be narrow. A property nothing can
 * fail on is a property nobody will notice losing — which is the shape this
 * project has now found more than a dozen times.
 *
 * ===========================================================================
 * The split is DERIVED, and the pin is what stops it being a tautology
 * ===========================================================================
 * The security/business split comes from `AuditEvent::SECURITY` and
 * `AuditEvent::BUSINESS`, never from a list here — that is what makes an event
 * added to the catalogue tomorrow a subject of this file the same day.
 *
 * `API.md` §9.1 then names the trap in the obvious version of that: a test whose
 * expectation reads the same constant the writer reads compares a constant to
 * itself. **Neither writer here reads `AuditEvent::SECURITY`** — `AuthTrailWriter`
 * branches on the `auth.` prefix and `AuditEvent::SUBJECTLESS`, and
 * `DenialAuditWriter` writes one hard-coded event — so the derivation above is
 * already independent of the code under test. `PINNED_IP_BEARING_EVENTS` is
 * §9.1's layer 1a on top of that: membership declared **in this file**, compared
 * against `AuditEvent::SECURITY` in both directions, so moving an event between
 * the catalogue's two lists takes two deliberate edits in two files and neither
 * can quiet the other.
 *
 * ===========================================================================
 * What this file does NOT close, stated rather than left to be discovered
 * ===========================================================================
 * §9.1 specifies three enforcement layers. This is layers 1 and 1a. **Layer 3 —
 * `CHECK (ip_address IS NULL OR event_type IN (<the security literals>))` — does
 * not exist**: verified against the server, `audit_log` carries
 * `audit_log_event_type_values`, `audit_log_id_is_uuid_v4`,
 * `audit_log_permission_code_scope` and `audit_log_subject_pair`, and no
 * constraint mentioning `ip_address`. It needs a forward migration, which this
 * pass is not authorised to write (E4-1 and E4-2 are both scoped and neither
 * covers it), and `audit_log_permission_code_scope` is the precedent for its
 * exact shape. Until it lands, the control is PHP-side only: a writer that
 * bypasses both classes below and issues its own `INSERT` is caught by
 * `everyClassThatCanWriteTheTrailIsClassifiedHere` and by nothing at the database.
 */
final class AuditWriterTest extends TenancyTestCase
{
    /**
     * §9.1's layer 1a: the events that may carry an `ip_address`, **declared
     * here and never in `AuditEvent`**, and reconciled with the catalogue in both
     * directions by `thePinAndTheConstantAgreeInBothDirections`.
     *
     * Adding a member here without adding it to `AuditEvent::SECURITY` fails, and
     * so does the reverse. That is the whole job of the copy.
     *
     * @var list<string>
     */
    public const PINNED_IP_BEARING_EVENTS = [
        'auth.login.success',
        'auth.login.failure',
        'auth.login.tenant_inactive',
        // E4-1 clause (5) / API-O-18's sixth step, added 2026-09-09 with E-23's
        // `AUTH_PASSWORD_CHANGED`. `SEC` ruled `ip_address: yes` (§5.1.4) --
        // "the source address of a credential change is the forensic question
        // the event exists to answer" -- and API-16's pin is two-directional, so
        // a security event omitted here fails
        // `thePinAndTheConstantAgreeInBothDirections` rather than silently
        // sitting outside §9.1's layer 1a.
        'auth.password.changed',
        'authz.permission.denied',
        'data.export.completed',
        'role.assigned',
        'role.revoked',
    ];

    /**
     * Every file under `backend/src/` that can put a row in `audit_log`, pinned
     * so that a **new** one cannot arrive unclassified.
     *
     * This is the list `everyClassThatCanWriteTheTrailIsClassifiedHere`
     * reconciles against a scan of the tree. It is deliberately about *files*
     * rather than about classes: the scan is textual, and a name resolved from
     * the text would be a second thing that can be wrong.
     *
     * @var list<string>
     */
    private const DECLARED_WRITERS = [
        'Audit/DenialAuditWriter.php',
        'Audit/PasswordChangeAuditWriter.php',
        'Auth/Infrastructure/AuthTrailWriter.php',
    ];

    private const PROBE_IP = '203.0.113.7';

    /**
     * **The negative test M0-b's criterion asks for.**
     *
     * Every event in `AuditEvent::ALL` is offered to every writer that takes an
     * event type, with a non-null `ip_address` in hand, and what actually reached
     * the table is read back. The rule is then asserted against the **catalogue's
     * own split**:
     *
     *   - no member of `AuditEvent::BUSINESS` wrote a row carrying an IP;
     *   - every event that did carry one is on `PINNED_IP_BEARING_EVENTS`;
     *   - and at least one did, so the two clauses above cannot be satisfied by a
     *     writer that fell over and wrote nothing.
     *
     * `DenialAuditWriter` takes no event type — it can only write
     * `authz.permission.denied` — so it contributes one row rather than a loop,
     * and the assertion that the event it hard-codes is a security event is the
     * form of the rule that applies to it.
     *
     * *Mutation, run:* `AuthTrailWriter`'s guard (the `auth.` prefix plus
     * `AuditEvent::SUBJECTLESS`) removed, which is exactly *"a business-event
     * writer populates the column"* — `purchase_order.placed` and
     * `stock_movement.adjusted` then write rows carrying `203.0.113.7`, and this
     * is red naming both.
     *
     * *Second mutation, run, and it corrected a claim this docblock first made:*
     * the `?::inet` parameter replaced by a literal `NULL` in `AuthTrailWriter`
     * alone leaves this **green**, because `DenialAuditWriter` still writes one —
     * which is what the anti-vacuity clause is for and also its limit, since it is
     * satisfied by *any* writer carrying an IP. Nulled in **both** writers it is
     * red on *"No row carries an ip_address at all"*. Recorded rather than
     * rewritten to look tidier: the first form was assumed and the second was run.
     *
     * **Re-measured 2026-09-09 with a third writer, and the limit got wider — so
     * it is restated rather than left at "both".** With
     * `PasswordChangeAuditWriter` added, nulling the address in **that writer
     * alone** leaves this file green (measured, `OK (3 tests, 9 assertions)`);
     * nulling it in **all three** is red on the same anti-vacuity clause
     * (measured). The small mutation is caught, but by
     * `PasswordChangeTest::oneChangeWritesExactlyOneSecurityRow`, which asserts
     * that writer's address directly — **not by this file**.
     *
     * **The generalisation is worth more than the fix and it is the reason this
     * paragraph exists: this clause's blind spot grows by one writer every time a
     * writer is added.** *"At least one row carried an IP"* is satisfied by any
     * survivor, so with N writers a mutation has to hit all N before it fires
     * here. A mutation that does **not** fire can therefore mean the mutation was
     * too small rather than that the check is strong, and telling those apart
     * means running the larger one. The per-writer assertion belongs with the
     * writer; this file's job is the *rule over the split*, and it should not be
     * read as covering any individual writer's column.
     */
    #[Test]
    public function onlySecurityEventsCarryAnIpAddress(): void
    {
        $actor = $this->seedActor();
        $accepted = [];
        $refused = [];

        foreach (AuditEvent::ALL as $eventType) {
            try {
                $this->trailWriter()->record($eventType, self::TENANT_A, $actor, 'Probe Actor', self::PROBE_IP);
                $accepted[] = $eventType;
            } catch (\InvalidArgumentException) {
                // Refusing the event outright is one of the two ways this rule
                // can hold, and it is the one that holds for every business
                // event today.
                $refused[] = $eventType;
            }
        }

        self::assertNotSame([], $accepted,
            'The writer refused every event in the catalogue, so nothing below was measured on a real write.');

        // The two writers with no event-type parameter to loop over. Each writes
        // one hard-coded event, so the form of the rule that applies to them is
        // that the event they hard-code is a security event -- which the clauses
        // below assert against the catalogue's own split, not against a list.
        $this->denialWriter()->record(
            tenantId: self::TENANT_A,
            actorUserId: $actor,
            actorLabel: 'Probe Actor',
            permissionCode: 'inventory_item.view',
            subjectId: Uuid::v4()->toRfc4122(),
            ipAddress: self::PROBE_IP,
        );

        // E-23's writer, added 2026-09-09. `everyClassThatCanWriteTheTrailIsClassifiedHere`
        // requires every file that can write the trail to be declared here, and
        // its own failure message says why declaring is not enough: "a writer
        // that is not exercised by `onlySecurityEventsCarryAnIpAddress` is a
        // writer M0-b's ip_address rule is not being checked against." So it is
        // driven rather than merely listed.
        $this->passwordChangeWriter()->record(
            tenantId: self::TENANT_A,
            actorUserId: $actor,
            actorLabel: 'Probe Actor',
            ipAddress: self::PROBE_IP,
        );

        $withIp = [];
        $written = [];

        foreach ($this->trail() as $row) {
            $written[] = (string) $row['event_type'];

            if (null !== $row['ip_address']) {
                $withIp[] = (string) $row['event_type'];
            }
        }

        $withIp = array_values(array_unique($withIp));
        sort($withIp);

        self::assertNotSame([], $withIp,
            'No row carries an ip_address at all, so both clauses below would hold over an empty set.');

        self::assertSame([], array_values(array_intersect($withIp, AuditEvent::BUSINESS)),
            'A business event was written with a non-null ip_address. `ROADMAP.md` *Exit criteria — M0-b*: '
            .'ip_address is personal data and has no forensic value on the bulk business event, which the '
            .'project\'s own fixture makes the overwhelming majority of this table.');

        self::assertSame([], array_values(array_diff($withIp, self::PINNED_IP_BEARING_EVENTS)),
            'An event outside the pinned IP-bearing set carried an ip_address: '.implode(', ', $withIp));

        // Anti-vacuity from the other side: the loop must actually have reached
        // the table, or "no business event carried an IP" is a claim about a
        // writer that wrote nothing.
        self::assertNotSame([], array_intersect($written, AuditEvent::SECURITY),
            'No security event reached the table, so the writers under test wrote nothing at all.');

        // The same rule one step earlier, on the writer rather than on the row:
        // the trail writer is the pre-authentication one and E-13 scopes it to
        // `auth.*`, so a business event must not even be accepted by it. Asserted
        // after the row clauses deliberately — M0-b's criterion is about the
        // column, and it should be the failure a reader sees first.
        self::assertSame([], array_values(array_diff(AuditEvent::BUSINESS, $refused)),
            'A business event was accepted by the pre-authentication trail writer, which was handed an '
            .'ip_address. E-13 scopes that writer to `auth.*`.');
    }

    /**
     * §9.1's layer 1a, in both directions.
     *
     * *Mutation, run:* `PURCHASE_ORDER_PLACED` moved from `AuditEvent::BUSINESS`
     * into `AuditEvent::SECURITY`. `onlySecurityEventsCarryAnIpAddress` stays
     * **green** — measured, not assumed: nothing writes that event either way, so
     * the split it derives moved underneath it in silence — and this method is the
     * one that goes red. That is the whole reason the pin is a second copy rather
     * than a reference.
     */
    #[Test]
    public function thePinAndTheConstantAgreeInBothDirections(): void
    {
        $pinned = self::PINNED_IP_BEARING_EVENTS;
        $catalogue = AuditEvent::SECURITY;
        sort($pinned);
        sort($catalogue);

        self::assertSame($catalogue, $pinned,
            'The events this file pins as IP-bearing and `AuditEvent::SECURITY` disagree. §9.1 requires two '
            .'deliberate edits in two files, so whichever side moved, the other has to be argued rather than '
            .'made to follow.');
    }

    /**
     * **The domain of writers is derived from the source tree, so a new one
     * cannot arrive unnoticed.**
     *
     * `onlySecurityEventsCarryAnIpAddress` can only drive the writers it knows
     * about. This is what makes that domain honest: every file under `src/` that
     * issues an `INSERT INTO audit_log`, or constructs an `AuditLogEntry` for the
     * entity manager to flush, must be on `DECLARED_WRITERS` — and adding one is
     * therefore a deliberate act that also has to answer the rule above.
     *
     * *Mutation, run:* a file added under `src/` containing
     * `INSERT INTO audit_log`; this names it and is red, while every other test
     * in the suite stays green.
     */
    #[Test]
    public function everyClassThatCanWriteTheTrailIsClassifiedHere(): void
    {
        $root = \dirname(__DIR__, 2).'/src';
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (1 === preg_match('/INSERT\s+INTO\s+audit_log/i', $source)
                || 1 === preg_match('/new\s+AuditLogEntry\s*\(/', $source)) {
                $found[] = str_replace($root.'/', '', $file->getPathname());
            }
        }

        sort($found);
        $declared = self::DECLARED_WRITERS;
        sort($declared);

        self::assertNotSame([], $found, 'The scan found no writer at all, so this check had no subject.');
        self::assertSame($declared, $found,
            'The set of files that can write `audit_log` is not the set this file drives. A writer that is '
            .'not exercised by `onlySecurityEventsCarryAnIpAddress` is a writer M0-b\'s ip_address rule is '
            .'not being checked against.');
    }

    private function trailWriter(): AuthTrailWriter
    {
        $writer = self::getContainer()->get(AuthTrailWriter::class);
        \assert($writer instanceof AuthTrailWriter);

        return $writer;
    }

    private function denialWriter(): DenialAuditWriter
    {
        $writer = self::getContainer()->get(DenialAuditWriter::class);
        \assert($writer instanceof DenialAuditWriter);

        return $writer;
    }

    private function passwordChangeWriter(): PasswordChangeAuditWriter
    {
        $writer = self::getContainer()->get(PasswordChangeAuditWriter::class);
        \assert($writer instanceof PasswordChangeAuditWriter);

        return $writer;
    }

    /** `audit_log_actor_fk` is composite on `(tenant_id, actor_user_id)`, so the principal has to be real. */
    private function seedActor(): string
    {
        $actor = Uuid::v4()->toRfc4122();

        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
            VALUES (?::uuid, ?::uuid, 'ip-probe@example.test', 'not-a-hash', 'Probe Actor')
            SQL, [$actor, self::TENANT_A]);

        return $actor;
    }

    /** @return list<array<string, mixed>> */
    private function trail(): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT event_type, host(ip_address) AS ip_address FROM audit_log ORDER BY occurred_at, id');
    }
}
