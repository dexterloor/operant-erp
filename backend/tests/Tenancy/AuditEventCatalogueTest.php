<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\AuditLog\Infrastructure\Entity\AuditLogEntry;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * B-4 — the enumerated `event_type` catalogue, guarded in both directions.
 *
 * ---------------------------------------------------------------------------
 * Why this test class exists at all
 * ---------------------------------------------------------------------------
 * `audit_log.event_type` shipped as `text NOT NULL` with no `CHECK`, no enum and
 * no domain (`Version20260906000100`, the `audit_log` DDL), and no catalogue of
 * legal values existed anywhere in the project. Two rules already depended on
 * classifying this column and **one of them could not fire**: PC-11
 * (`SECURITY_MODEL.md` §3) forbids a `.view_record` query whose `event_type` predicate is
 * a `NOT LIKE` rather than an `IN`, and it pointed that `IN` at "§6.1's
 * catalogue" — which is the 60-permission catalogue and contains zero event
 * types.
 *
 * **Past tense on purpose.** `IA.md` re-pointed that clause on 2026-09-07, and
 * extended it again the same day after SEC-O-15. It takes no position on the
 * values; it names what it needs from B-4, and this class is where each of those
 * is checked, so they are listed by PC-11's own numbering rather than narrated:
 *
 *   1. *one addressable enumeration in code* — `AuditEvent::ALL`;
 *   2. *a classification on every member, union total and intersection empty* —
 *      `everyEventIsClassifiedExactlyOnce`;
 *   3. *a guard deriving the catalogue from the server rather than restating a
 *      constant, asserting (2) is total over what it derived* —
 *      `theServersLegalSetIsExactlyTheCodeCatalogue`;
 *   4. *a classification that can be shown to be **wrong**, not merely present*,
 *      because (1)–(3) all fire on an **unclassified** member and none of them
 *      fires on a **misclassified** one. Three parts, split by what can actually
 *      be tested:
 *      - **(4a)** the admission criterion. **Judgement, labelled as such by
 *        PC-11 itself (Rule 14) — there is deliberately no test here.** It is
 *        quoted on `AuditEvent::SECURITY`, which is where a filing gets argued.
 *      - **(4b)** the pin on the allowlist's exact membership —
 *        `theAllowlistIsExactlyThePinnedMembership`, against
 *        `PINNED_ALLOWLIST`, which is declared in this file and not in the
 *        catalogue class.
 *      - **(4c)** no admitted event names `app_user` as its subject —
 *        `noAdmittedEventNamesAppUserAsItsSubject`, resting on the per-event
 *        subject declaration `everyEventDeclaresItsSubjectExactlyOnce` proves
 *        total.
 *
 * **What (4) replaced, and why the order of this pass mattered.** The only
 * classification-side guard here was a `str_starts_with('auth.')` /
 * `str_starts_with('authz.')` filter over `BUSINESS` — a prefix denylist, the
 * exact construct PC-11 forbids, re-erected inside the test written to enforce
 * PC-11 and carrying a failure message claiming it covered "the disclosure PC-11
 * exists to prevent". It could not fire on `role.assigned` or `role.revoked`,
 * which is how SEC-O-15 survived it. It is gone, and (4b) landed **before** it
 * was removed: the other order leaves a window with no classification-side guard
 * at all.
 *
 * `IA.md` also records that a **closed list** is the stronger of the two shapes
 * it permits, because it makes totality decidable against `pg_constraint`. That
 * is the shape B-4 landed on, so (3) proves totality directly.
 *
 * ---------------------------------------------------------------------------
 * The trap this class is written to avoid
 * ---------------------------------------------------------------------------
 * S-17 and Amendment 1 §A1.5: **an exhaustive check must derive its domain from
 * the server rather than restate it.** A test that reads `AuditEvent::ALL` and
 * compares it to `AuditEvent::ALL` proves nothing. So the legal set is read back
 * out of `pg_constraint` and reconciled against the code catalogue in **both**
 * directions, and it is also probed **behaviourally** — every catalogue value is
 * inserted for real, and a non-catalogue value is inserted and must be refused
 * by the named constraint rather than by anything else.
 */
final class AuditEventCatalogueTest extends TenancyTestCase
{
    private const CONSTRAINT = 'audit_log_event_type_values';

    /**
     * **PC-11 (4b)'s pin: the exact membership of the `.view_record` allowlist,
     * declared independently of `AuditEvent`.**
     *
     * Written as string literals and not as `AuditEvent::` constants on purpose.
     * A pin expressed in the catalogue's own constants still detects a *move*
     * across the split, but it cannot detect a rename, and it reads as though
     * the class were its own authority. The independence is the whole mechanism:
     * "the redundancy is the check", the same shape `AuditEvent::ALL` already
     * uses one level down.
     *
     * **It pins the admitted side only, and that asymmetry is deliberate** —
     * PC-11 (4b) states it so it is not read as symmetric cover. An event filed
     * as a security event that should have been a business event stays silent
     * here; it costs an empty History section, which is fail-closed for
     * disclosure. An event that reaches the allowlist without a deliberate edit
     * to this constant is the failure this exists to catch, because that is the
     * direction that discloses.
     *
     * Editing this list is a `SEC` decision under PC-11 (4a) — the admission
     * criterion is *about the subject row's own business state, never a
     * principal's credentials, sessions or privileges* — not a `BE-LEAD` one.
     *
     * @var list<string>
     */
    private const PINNED_ALLOWLIST = [
        'purchase_order.placed',
        'purchase_order.cancelled',
        // Added 2026-09-19 with `CancelSalesOrder`, and the edit is the second,
        // independent assertion of the string that (4b) exists to be — made by
        // hand, in this file, as a literal and never as an `AuditEvent::`
        // constant. `SEC` had already filed it **business** under PC-11 (4a) in
        // `SECURITY_MODEL.md` before any code existed, which is what this edit
        // ratifies rather than decides. It is about the sales order's own
        // business state and about no principal's credentials, sessions or
        // privileges; its subject is `sales_order`, so (4c) does not reach it.
        'sales_order.cancelled',
        // Added 2026-09-28 with `IssueInvoice` (sales step 6, C3), by hand and as a literal, ratifying `SEC`'s filing
        // (`output9-sales-7-sec.md` §5.1: business, subject the invoice, metadata `{}`), which preceded the code.
        // About the invoice's own business state; its subject is `invoice`, so (4c) does not reach it.
        'invoice.issued',
        // Added 2026-09-28 with `PayInvoice` (sales step 6, C5), by hand and as a literal, ratifying `SEC`'s filing
        // (`output9-sales-7-sec.md` §5.1: business, subject the invoice, actor the payer, metadata `{"paid_at"}`).
        // About the invoice's own payment state, not a principal's privileges; subject `invoice`, so (4c) does not reach it.
        'invoice.paid',
        // Added 2026-09-28 with `ReverseInvoicePayment` (sales step 6, C6), by hand, ratifying `SEC`'s filing (§5.1:
        // business, subject the invoice, actor the reverser, metadata `{"reason"}`). The invoice's own payment state.
        'invoice.payment_reversed',
        // Added 2026-09-29 with `VoidInvoice` (sales step 6, C7), by hand, ratifying `SEC`'s filing (§5.1: business,
        // subject the invoice, actor the voider, metadata `{"reason"}`). The invoice's own state, not a principal's.
        'invoice.voided',
        'stock_movement.adjusted',
        // Added 2026-09-29 with B-4a (roles slice 2, C1), by hand and as literals, ratifying `SEC`'s filing of all five
        // (`docs/session13/roles-s2-sec.md` §3: business, subject the document, metadata `{}`, no IP), which preceded
        // the code. Each is about its document's own business state -- a receipt recorded, an order confirmed, a
        // shipment recorded, a draft discarded -- and about no principal's credentials, sessions or privileges; no
        // subject is `app_user`, so (4c) does not reach them. The deletes are filed together (G-9's fallback).
        'goods_receipt.created',
        'sales_order.confirmed',
        'fulfilment.created',
        'sales_order.deleted',
        'purchase_order.deleted',
        // Edited deliberately 2026-10-01 (sales step 7 R2) on `SEC`'s filing (`output9-sales-8-sec.md` S-5 and final
        // position item 5: BUSINESS, subject the return, `{"reason", "deducts"}`, no IP), which preceded the code. A return
        // recorded is the return document's own business state; its subject is `sales_return`, so (4c) does not reach it.
        'sales_return.created',
        // Edited deliberately 2026-10-01 (sales step 7 C1) on `SEC`'s filing (S-5: BUSINESS, subject the credit note,
        // `{"reason"}`, no IP), which preceded the code; its subject is `credit_note`, so (4c) does not reach it.
        'credit_note.issued',
        // Edited deliberately 2026-10-05 (G-29 / G-30 part 3 C1) on `SEC`'s filing (`g29-g30-sec.md` S-4, F-S1: BUSINESS,
        // subject the credit note of each row's own adjustment line — never the return — ids-only metadata, no IP),
        // which preceded the code; its subject is `credit_note`, so (4c) does not reach it.
        'credit_note.return_decided',
        // Edited by hand 2026-10-09 (customers slice 1 C2) on the delegated `SEC`-role filing in
        // `docs/session13/customers-s1-g35-judge.md` C-4 (`SEC` to confirm; BUSINESS, subject the customer, `{}` and
        // `{"fields"}` — names, never values — no IP), which preceded the code. A customer created or changed is the
        // customer row's own state; its subject is `customer`, so (4c) does not reach either. `RESIDUAL_GAPS.md` G-35.
        'customer.created',
        'customer.updated',
        // Edited by hand 2026-10-12 (suppliers slice 1 C2) on owner Q-S5 (`docs/session13/suppliers-s1-ruling.md`) and the
        // `customer.*` filing's terms (`SEC`'s stated view, `owed-roles-recs.md` S2; `SEC` to confirm the supplier block):
        // BUSINESS, subject the supplier, `{}` and `{"fields"}` — names, never values — no IP. Its subject is `supplier`, so
        // (4c) does not reach either. ONLY these two: `.deleted`/`.restored` come with their writers (final RED M-A).
        'supplier.created',
        'supplier.updated',
        // Edited by hand 2026-10-12 (suppliers slice 1 C2b) on owner ruling F-12 / S4 "Record address changes"
        // (`docs/session13/suppliers-s1-ruling.md`) and the `customer.*` filing's terms (`SEC` to confirm the address block):
        // BUSINESS, subject the address row, `{}` and `{"fields"}` — names, never values (an address is personal data) — no
        // IP. Its subject is `customer_address`, so (4c) does not reach either.
        'customer_address.created',
        'customer_address.updated',
        // Edited by hand 2026-10-19 (customers slice 2 C1) on owner Q-2 "yes, record contact changes"
        // (`docs/session13/s35-ruling.md`) and `SEC`'s filing (`customers-s2-sec-arch.md` S-4): BUSINESS, subject the contact
        // row, `{}` and `{"fields"}` — names, never values (a contact is personal data) — no IP. Its subject is
        // `customer_contact`, so (4c) does not reach either. ONLY these two: `.deleted` comes with its writer (C2).
        'customer_contact.created',
        'customer_contact.updated',
        // Edited by hand 2026-10-19 (customers slice 2 C2) on the same owner Q-2 and `SEC` S-4 filing: `customer_contact.deleted`,
        // with its writer (`CustomerContactWriter::delete()`), business, subject the deleted contact, `{"customer_id"}`, no IP.
        'customer_contact.deleted',
        // Edited by hand 2026-10-13 (warehouses slice 1 C2) on owner Q-W2 (a) (adopted under delegation,
        // `docs/session13/warehouses-s1-design.md` §3.2) and the `supplier.*` filing's terms (`SEC` to confirm the warehouse
        // block): BUSINESS, subject the warehouse, `{values}` and `{fields, from, to}` — operational values over an explicit
        // allowlist — no IP. Its subject is `warehouse`, so (4c) does not reach either. ONLY these two: `.deleted`/`.restored`
        // come with their writers.
        'warehouse.created',
        'warehouse.updated',
        // Edited by hand 2026-10-13 (warehouses slice 1 C3) on owner Q-W2 (a) / Q-W1 (d) (adopted under delegation, design
        // §4.5) and the `warehouse.*` filing's terms (`SEC` to confirm the storage-location block): BUSINESS, subject the
        // location, `{values}` and `{fields, from, to[, held_stock]}`, no IP. ONLY these two: `.deleted`/`.restored` come with
        // their writers.
        'storage_location.created',
        'storage_location.updated',
        // Edited by hand 2026-10-13 (C5 C2a) on `SEC`'s supplier filing (`supplier.deleted` / `.restored`, business, subject
        // the supplier, `{}`, no IP; design §6.1): ONLY `.restored` — `.deleted` comes with `DeleteSupplier` (C2b).
        'supplier.restored',
        // Edited by hand 2026-10-14 (C5 C2b) on the same `SEC` filing: `supplier.deleted`, with its writer
        // (`SupplierWriter::delete()`), business, subject the supplier, `{}`, no IP.
        'supplier.deleted',
        // Edited by hand 2026-10-14 (C5 C4a), filed by the builder on `SEC`'s supplier terms (design §6.1 / §9 S-1; `SEC`
        // to confirm): ONLY `customer.restored` — `customer.deleted` comes with `DeleteCustomer` (C4b).
        'customer.restored',
        // Edited by hand 2026-10-14 (C5 C4b) on the same builder filing (`SEC` to confirm): `customer.deleted`, with its
        // writer (`CustomerWriter::delete()`), business, subject the customer, `{}`, no IP.
        'customer.deleted',
    ];

    /**
     * **PC-11 (4c)'s exception list, and it is empty because the clause says
     * "Exceptions today: none".**
     *
     * (4c) is a *rebuttable* default: an event about the person's own record — a
     * deactivation, a display-name change — is about the subject row's business
     * state, and (4a) admits it. Constructing this list empty rather than
     * omitting it is what keeps `noAdmittedEventNamesAppUserAsItsSubject` from
     * being an absolute bar that would fire on a correct artifact.
     *
     * A row added here without the matching row in PC-11 (4c) is the same defect
     * this whole pass exists to close, one document over.
     *
     * @var list<string>
     */
    private const APP_USER_SUBJECT_EXCEPTIONS = [];

    /**
     * The constraint exists, is a CHECK, is on `audit_log` — **and is validated**.
     *
     * Read from the catalogue, not from the migration file: a migration that was
     * edited after it ran is invisible to every database that already ran it.
     *
     * **`convalidated` is asserted because the migration is non-transactional.**
     * `Version20260907000200::isTransactional()` returns `false` so that
     * `ADD CONSTRAINT … NOT VALID` releases its `AccessExclusiveLock` before
     * `VALIDATE CONSTRAINT` runs. The cost of that is that the three statements
     * commit independently: a `VALIDATE` that fails — or is dropped from the
     * migration — leaves the column carrying a `NOT VALID` constraint, which is
     * enforced for new rows and **silent about every row already there**.
     *
     * Measured, because "no other guard would notice" is a claim and not an
     * argument: with the `VALIDATE CONSTRAINT` line deleted from `up()` and the
     * volume destroyed and rebuilt, `pg_constraint` reported `convalidated = f`
     * and this class was still fully green. **The class has grown since that
     * measurement and the figures it carried are deliberately gone rather than
     * updated** (Rule 15) — what the mutation established is that nothing else
     * in the project reads `convalidated`, and that is not a number. This
     * assertion is the whole of that coverage.
     *
     * `convalidated::text` rather than the bare column so the comparison is
     * against a value PostgreSQL produced, not a driver's idea of a boolean.
     * Note the spelling: `boolean::text` yields **`'true'`/`'false'`**, not the
     * `'t'`/`'f'` that psql prints — psql shows the type's output function, and
     * the cast does not. The first draft of this line asserted `'t'` and would
     * have failed against a correctly validated constraint; it was caught by
     * running the GREEN half of the mutation and not only the RED half.
     */
    #[Test]
    public function theEventTypeConstraintExistsOnAuditLogAndIsValidated(): void
    {
        $row = $this->owner()->fetchAssociative(
            'SELECT pg_get_constraintdef(oid) AS definition, convalidated::text AS validated
             FROM pg_constraint
             WHERE conrelid = ?::regclass AND conname = ? AND contype = ?',
            ['audit_log', self::CONSTRAINT, 'c'],
        );

        self::assertIsArray($row,
            self::CONSTRAINT.' is not a CHECK constraint on audit_log. `event_type` has no domain, '
            .'so PC-11 has nothing to point its IN at.');
        self::assertStringContainsString('event_type', (string) $row['definition'],
            'The constraint exists but does not mention event_type: '.$row['definition']);
        self::assertSame('true', $row['validated'],
            self::CONSTRAINT.' is NOT VALID. It constrains new rows only, and every row written '
            .'before it was added is unchecked -- which is exactly what the migration\'s '
            .'VALIDATE CONSTRAINT step exists to rule out: '.$row['definition']);
    }

    /**
     * The load-bearing one. **The domain is derived from the server**, by reading
     * the constraint back and extracting its literals, then reconciled with the
     * code catalogue in both directions.
     *
     * Drift either way fails: a value added to `AuditEvent` without a migration
     * fails, and a value added to the migration without `AuditEvent` fails.
     */
    #[Test]
    public function theServersLegalSetIsExactlyTheCodeCatalogue(): void
    {
        $definition = (string) $this->owner()->fetchOne(
            'SELECT pg_get_constraintdef(oid) FROM pg_constraint
             WHERE conrelid = ?::regclass AND conname = ?',
            ['audit_log', self::CONSTRAINT],
        );

        // PC-11 requires membership against a literal list, not a pattern. Asserted
        // in the form **the server stores**, which is not the form the migration
        // wrote: PostgreSQL normalises `IN (a, b)` to `= ANY (ARRAY[a, b])` before
        // it reaches pg_get_constraintdef. The first draft of this assertion looked
        // for `\bIN\b` and failed against a correct constraint -- the same class of
        // defect as A1.5's information_schema check, caught here because the test
        // reads the catalogue instead of the migration file.
        //
        // **`= ANY (ARRAY[` is a deparse artefact of PostgreSQL 17, not a
        // documented contract.** `pg_get_constraintdef` renders whatever the
        // planner's normalised parse tree holds, and that rendering is a server
        // implementation detail no release note promises to keep. It is pinned
        // today -- infra/docker-compose.yml runs `postgres:17-alpine` -- so this is
        // latent rather than fragile, but a major-version bump can break this line
        // against a perfectly correct constraint. If it does, the fix is to widen
        // the accepted spellings here; it is **not** to drop the assertion, because
        // "membership, not pattern" is PC-11's actual requirement and the two
        // assertStringNotContainsString checks below only cover the spellings we thought of.
        self::assertStringContainsString('= ANY (ARRAY[', $definition,
            'The constraint is not equality-membership over a literal list: '.$definition);

        foreach ([' LIKE ', ' ~ ', 'NOT LIKE'] as $patternForm) {
            self::assertStringNotContainsString($patternForm, $definition,
                'PC-11 forbids a pattern predicate here -- a prefix rule fails open on the first '
                .'event somebody names outside it: '.$definition);
        }

        preg_match_all("/'([^']+)'::text/", $definition, $matches);
        $fromServer = $matches[1];

        self::assertNotSame([], $fromServer,
            'No literals could be read out of '.self::CONSTRAINT.'. If this test cannot read the '
            .'server it is not deriving anything, and it must fail rather than pass vacuously: '
            .$definition);

        sort($fromServer);
        $inCode = AuditEvent::ALL;
        sort($inCode);

        self::assertSame($inCode, $fromServer,
            "The database's legal set and AuditEvent::ALL disagree.\n"
            ."  only in the database: ".implode(', ', array_diff($fromServer, $inCode))."\n"
            ."  only in the code:     ".implode(', ', array_diff($inCode, $fromServer)));
    }

    /**
     * Behavioural, not catalogue-derived: every value the code offers is actually
     * accepted by the running database. Parsing a constraint definition proves
     * the text matches; this proves the constraint *admits* what the code will
     * write.
     */
    #[Test]
    public function everyCatalogueValueIsAcceptedByTheDatabase(): void
    {
        $rejected = [];

        foreach (AuditEvent::ALL as $eventType) {
            // The kernel is rebooted per iteration, and that is not tidiness.
            // TenantContext::run goes through EntityManager::wrapInTransaction,
            // which **closes the EntityManager** on any failure. Sharing one
            // kernel across the loop means the first genuine rejection makes
            // every later iteration throw "The EntityManager is closed." --
            // measured, with a fabricated value placed first in `ALL`: **one
            // real rejection and nine fabricated ones**, every catalogue value
            // in the file named as refused by the database. A failing test that
            // names innocent values is worse than one that names none, because
            // the list is what gets acted on. With the reboot, the same
            // mutation reports exactly the one value that is really refused.
            //
            // Rebooting rather than opening a raw connection keeps this test on
            // the sanctioned tenant-context API: a direct set_config here would
            // have to be argued into
            // TenantContextContainmentTest::KNOWN_WRITERS_OUTSIDE_SRC, and that
            // list is a control, not a convenience.
            self::ensureKernelShutdown();
            self::bootKernel();

            try {
                $this->tenantContext()->run(self::TENANT_A, function () use ($eventType): void {
                    $this->app()->executeStatement(
                        'INSERT INTO audit_log (id, tenant_id, event_type, actor_label) VALUES (?::uuid, ?::uuid, ?, ?)',
                        [Uuid::v4()->toRfc4122(), self::TENANT_A, $eventType, 'system'],
                    );
                });
            } catch (\Throwable $e) {
                $rejected[] = $eventType.' => '.$e->getMessage();
            }
        }

        self::assertSame([], $rejected,
            "A value in AuditEvent::ALL is refused by the database:\n".implode("\n", $rejected));
        self::assertNotSame([], AuditEvent::ALL, 'An empty catalogue would pass this test vacuously.');
    }

    /**
     * The other half: a value nobody classified is refused, and refused **by this
     * constraint** rather than by a grant, a NOT NULL or a type error. A test
     * that only asserts "the insert failed" cannot tell those apart.
     */
    #[Test]
    public function aValueOutsideTheCatalogueIsRejectedByTheNamedConstraint(): void
    {
        $thrown = null;

        try {
            $this->tenantContext()->run(self::TENANT_A, function (): void {
                $this->app()->executeStatement(
                    'INSERT INTO audit_log (id, tenant_id, event_type, actor_label) VALUES (?::uuid, ?::uuid, ?, ?)',
                    [Uuid::v4()->toRfc4122(), self::TENANT_A, 'route.visited', 'system'],
                );
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'An unclassified event_type was accepted. The column has no domain.');
        self::assertStringContainsString(self::CONSTRAINT, $thrown->getMessage(),
            'The insert failed, but not because of '.self::CONSTRAINT.': '.$thrown->getMessage());
    }

    /**
     * PC-11's actual requirement: **every event is on exactly one side of the
     * allowlist.** "An event type added to the catalogue without a line saying
     * which side of the allowlist it falls on" is the violation it names, and
     * this is the check that makes that violation fail rather than merely be
     * discouraged.
     *
     * `ALL` is declared independently of `SECURITY` and `BUSINESS` on purpose.
     * Deriving it (`[...self::SECURITY, ...self::BUSINESS]`) would make this test
     * a tautology — the exact fake-green shape SchemaManifest's own comment
     * records finding, where "a list defines the coverage and nothing was
     * checking the list".
     */
    #[Test]
    public function everyEventIsClassifiedExactlyOnce(): void
    {
        $union = array_merge(AuditEvent::SECURITY, AuditEvent::BUSINESS);
        $overlap = array_intersect(AuditEvent::SECURITY, AuditEvent::BUSINESS);

        self::assertSame([], array_values($overlap),
            'An event is both a security event and a business event, so PC-11 cannot decide it.');

        sort($union);
        $all = AuditEvent::ALL;
        sort($all);

        self::assertSame($all, $union,
            "SECURITY + BUSINESS does not partition ALL.\n"
            ."  unclassified: ".implode(', ', array_diff($all, $union))."\n"
            ."  classified but not in ALL: ".implode(', ', array_diff($union, $all)));
    }

    /**
     * **PC-11 (4b).** `everyEventIsClassifiedExactlyOnce` fails on an
     * **unclassified** member and passes on a **misclassified** one — moving an
     * event from `BUSINESS` to `SECURITY` or back keeps the partition total and
     * disjoint, so nothing above notices. That gap is not hypothetical: it is
     * SEC-O-15, where `role.assigned` and `role.revoked` sat on the allowlist
     * and every gate in this file was green.
     *
     * This is the only construct here that turns a silent side-change into a red
     * test. It compares `AuditEvent::BUSINESS` against `PINNED_ALLOWLIST`, which
     * is declared in this file from literals — see that constant for why the
     * independence and the one-sidedness are both deliberate.
     *
     * Mutation-proven in both directions it claims to cover (Rule 14, "a fix to a
     * fake-green check is itself mutation-proven"): with `ROLE_ASSIGNED` and
     * `ROLE_REVOKED` still on `BUSINESS` this test failed naming both, and with
     * `PURCHASE_ORDER_PLACED` moved to `SECURITY` it failed naming that.
     */
    #[Test]
    public function theAllowlistIsExactlyThePinnedMembership(): void
    {
        $pinned = self::PINNED_ALLOWLIST;
        sort($pinned);
        $admitted = AuditEvent::BUSINESS;
        sort($admitted);

        self::assertSame($pinned, $admitted,
            "AuditEvent::BUSINESS is not the pinned allowlist, and every row it admits is readable "
            ."through audit_log.view_record by any colleague holding the subject row's .view.\n"
            ."  admitted but not pinned: ".implode(', ', array_diff($admitted, $pinned))."\n"
            ."  pinned but not admitted: ".implode(', ', array_diff($pinned, $admitted))."\n"
            .'If the change is intended, edit PINNED_ALLOWLIST deliberately -- and admission is a SEC '
            .'decision under PC-11 (4a), not a BE-LEAD one.');
    }

    /**
     * **The per-event subject declaration is total over the catalogue** — the
     * same partition argument `everyEventIsClassifiedExactlyOnce` makes about
     * `SECURITY`/`BUSINESS`, applied to `SUBJECT_TYPES`, `SUBJECTLESS` and
     * `SUBJECT_RESOLVED_AT_WRITE`.
     *
     * Without this, an event added to `ALL` with no subject declaration would
     * silently fall through PC-11 (4c)'s guard below: the loop would not see it
     * and the guard would pass. An undeclared subject is a violation, not a
     * default — the same reason (2) exists one level up.
     */
    #[Test]
    public function everyEventDeclaresItsSubjectExactlyOnce(): void
    {
        $declared = array_merge(
            array_keys(AuditEvent::SUBJECT_TYPES),
            AuditEvent::SUBJECTLESS,
            AuditEvent::SUBJECT_RESOLVED_AT_WRITE,
        );

        self::assertNotSame([], $declared,
            'No event declares a subject at all. Both sides of the next comparison would be empty '
            .'and this check would pass vacuously.');

        $duplicated = array_keys(array_filter(
            array_count_values($declared),
            static fn (int $times): bool => $times > 1,
        ));

        self::assertSame([], $duplicated,
            'An event declares its subject twice, so PC-11 (4c) cannot decide it: '.implode(', ', $duplicated));

        sort($declared);
        $all = AuditEvent::ALL;
        sort($all);

        self::assertSame($all, $declared,
            "The subject declarations do not partition ALL.\n"
            ."  no subject declared: ".implode(', ', array_diff($all, $declared))."\n"
            ."  declared but not in ALL: ".implode(', ', array_diff($declared, $all)));
    }

    /**
     * A `subject_type` is a table name, and this reads the table list **off the
     * server** rather than restating one (S-17, and Amendment 1 §A1.5).
     *
     * A typo here would not fail anything else: `subject_type` is free `text`
     * with no foreign key — deliberately, because the trail has to outlive the
     * row it describes (`AuditLogEntry`'s docblock) — so nothing in the database
     * would ever refuse `'purchse_order'`, and L-7's History section would
     * silently return nothing for that event for the life of the product.
     */
    #[Test]
    public function everyDeclaredSubjectTypeNamesATableInTheDatabase(): void
    {
        $tables = $this->owner()->fetchFirstColumn(
            "SELECT c.relname FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p')",
        );

        self::assertNotSame([], $tables,
            'No tables were read back from the server, so this check is deriving nothing and must '
            .'fail rather than pass vacuously.');

        self::assertNotSame([], AuditEvent::SUBJECT_TYPES,
            'No event declares a fixed subject_type, so this check compares an empty set against the '
            .'server and proves nothing.');

        $unknown = array_values(array_diff(array_unique(array_values(AuditEvent::SUBJECT_TYPES)), $tables));

        self::assertSame([], $unknown,
            'A declared subject_type names no table in the database, so L-7 would query a subject '
            .'nothing writes: '.implode(', ', $unknown));
    }

    /**
     * **PC-11 (4c): no admitted event names `app_user` as its subject.**
     *
     * `.view_record`'s only rendering surface for an `app_user` subject is the
     * History section of `/admin/users/[id]` (L-7), and every row it shows there
     * is a **colleague's** history — the actor surveillance `SECURITY_MODEL.md` §4 withholds
     * `audit_log.view` to prevent. This is E-13's move generalised: E-13 keeps
     * `auth.*` out structurally rather than by prefix, and (4c) does the same for
     * any event about a person.
     *
     * **It is a rebuttable default, not an absolute bar**, and PC-11 says why:
     * an event recording a change to the person's *own record* — a deactivation,
     * a display-name change — is about the subject row's business state and (4a)
     * admits it. So the exception list below is part of the mechanism rather
     * than a hole in it. Adding a row to it is an edit to PC-11 (4c) and a `SEC`
     * decision; today the clause reads "Exceptions today: none", and this
     * constant is that sentence in a form that can fail.
     *
     * **It fails closed on an event whose subject is resolved at write time**
     * (`authz.permission.denied` is the only one today, and it is a security
     * event): such an event *may* name `app_user` at write time, so it cannot be
     * shown to satisfy (4c) and is refused rather than skipped. Only the
     * `app_user` finding is exemptible — PC-11 (4c)'s exceptions are about
     * events whose subject *is* the person, so an exception cannot buy an event
     * past the write-time-resolved refusal.
     *
     * **It also refuses a subjectless event on the allowlist, which is broader
     * than (4c)'s own text and is `BE-LEAD`'s addition rather than `SEC`'s.**
     * `.view_record` binds on `subject_id` (E-5), so a row with none can never
     * be returned by it: admitting one grants nothing, and refusing one costs
     * nothing. It is here because it is the case the deleted prefix denylist
     * used to cover — an `auth.*` event is `SUBJECTLESS` by E-13, so without
     * this branch an `auth.*` event filed onto `BUSINESS` by someone who also
     * edited the pin would pass every gate in this file. Found by review, not
     * by the author.
     */
    #[Test]
    public function noAdmittedEventNamesAppUserAsItsSubject(): void
    {
        $offending = [];

        foreach (AuditEvent::BUSINESS as $event) {
            if (\in_array($event, AuditEvent::SUBJECTLESS, true)) {
                $offending[] = $event.' => no subject at all, so .view_record can never return it and '
                    .'the allowlist admits it for nothing';
                continue;
            }

            if (!\array_key_exists($event, AuditEvent::SUBJECT_TYPES)) {
                $offending[] = $event.' => subject resolved at write time, so it cannot be shown not to name app_user';
                continue;
            }

            // The exception applies here and only here: PC-11 (4c) rebuts the
            // app_user bar, not the two refusals above.
            if ('app_user' === AuditEvent::SUBJECT_TYPES[$event]
                && !\in_array($event, self::APP_USER_SUBJECT_EXCEPTIONS, true)) {
                $offending[] = $event.' => app_user';
            }
        }

        self::assertSame([], $offending,
            "An event on the .view_record allowlist names a person as its subject, which puts a "
            ."colleague's history into the History section of /admin/users/[id] for anyone holding "
            ."app_user.view:\n  ".implode("\n  ", $offending)."\n"
            .'If that event is about the person\'s own record rather than their privileges or '
            .'credentials, PC-11 (4c) admits it as a named exception -- which is a SEC edit to that '
            .'clause and to APP_USER_SUBJECT_EXCEPTIONS, not a BE-LEAD one.');
    }

    /**
     * **The predicate, not the list.** `AuditEvent::isReadableAsRecordHistory()`
     * is the only thing that turns `BUSINESS` into behaviour, and until this
     * test it had no coverage and no callers — so every guard in this file
     * watched the data while the code below it was free.
     *
     * **Measured, by the Rule 9 reviewer, and this test exists because of it:**
     * with that method's body changed from `self::BUSINESS` to `self::ALL`, the
     * **full suite** returned `OK` — `auth.login.failure` and `role.assigned`
     * readable as record history, every gate green. That is SEC-O-15's own shape
     * ("fires on unclassified, silent on misclassified") moved one layer down,
     * from the classification to the code that reads it.
     *
     * The admitted set is **derived by running the predicate over `ALL`** rather
     * than restated, so the comparison cannot become a tautology: the two sides
     * are the code's behaviour and the code's declaration.
     */
    #[Test]
    public function theRecordHistoryPredicateAdmitsExactlyTheAllowlist(): void
    {
        $readable = array_values(array_filter(
            AuditEvent::ALL,
            AuditEvent::isReadableAsRecordHistory(...),
        ));
        sort($readable);

        $admitted = AuditEvent::BUSINESS;
        sort($admitted);

        self::assertSame($admitted, $readable,
            "isReadableAsRecordHistory() does not admit exactly AuditEvent::BUSINESS, and it is the "
            ."predicate .view_record will be built on -- the lists above it are only as good as this.\n"
            ."  readable but not admitted: ".implode(', ', array_diff($readable, $admitted))."\n"
            ."  admitted but not readable: ".implode(', ', array_diff($admitted, $readable)));

        self::assertFalse(AuditEvent::isReadableAsRecordHistory('route.visited'),
            'An event type outside the catalogue is readable as record history. The predicate fails '
            .'open, which is the one thing PC-11 says the allowlist must never do.');
    }

    /**
     * A constant declared on the class and left out of `ALL` would be a value the
     * code offers, the database refuses, and no other test in this file notices —
     * because every other test iterates `ALL`.
     */
    #[Test]
    public function everyDeclaredConstantIsInTheCatalogue(): void
    {
        $declared = array_filter(
            (new \ReflectionClass(AuditEvent::class))->getConstants(),
            static fn (mixed $v): bool => \is_string($v),
        );

        self::assertNotSame([], $declared, 'AuditEvent declares no event constants.');

        $missing = array_diff(array_values($declared), AuditEvent::ALL);

        self::assertSame([], array_values($missing),
            'Declared on AuditEvent but absent from AuditEvent::ALL: '.implode(', ', $missing));
    }

    /**
     * The literals the project documents name in prose. If one is renamed in
     * code, the documents silently stop describing the system.
     *
     * A restatement, and deliberately so: it is a regression pin on four
     * externally-cited strings, not an exhaustiveness check. The exhaustiveness
     * check is theServersLegalSetIsExactlyTheCodeCatalogue, which derives.
     */
    #[Test]
    public function theEventTypesNamedInTheProjectDocumentsAreStillInTheCatalogue(): void
    {
        $documented = [
            // ADR-006 §2.2, the outcome table's "Success" row
            'auth.login.success',
            // ADR-006 §2.2, the wrong-password row; SCHEMA.md's audit_log index list;
            // SchemaQueryPlanTest
            'auth.login.failure',
            // SECURITY_MODEL.md §5, E-12
            'auth.login.tenant_inactive',
            // SECURITY_MODEL.md §5, E-3
            'authz.permission.denied',
        ];

        self::assertSame([], array_values(array_diff($documented, AuditEvent::ALL)),
            'A documented event type is missing from the catalogue: '
            .implode(', ', array_diff($documented, AuditEvent::ALL)));
    }

    /**
     * `AuditLogEntry` already enforces `audit_log_subject_pair` in its constructor
     * "so the half-populated pair fails at construction rather than at flush"
     * (its own docblock). The same argument applies to `event_type`, and this is
     * the test for it.
     */
    #[Test]
    public function theEntityRefusesAnEventTypeOutsideTheCatalogue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditLogEntry(
            Uuid::v4(),
            Uuid::fromString(self::TENANT_A),
            new \DateTimeImmutable(),
            'route.visited',
            'system',
        );
    }

    /** The same constructor still accepts a catalogue value. A guard that refuses everything is not a guard. */
    #[Test]
    public function theEntityAcceptsAnEventTypeInTheCatalogue(): void
    {
        $entry = new AuditLogEntry(
            Uuid::v4(),
            Uuid::fromString(self::TENANT_A),
            new \DateTimeImmutable(),
            AuditEvent::AUTH_LOGIN_FAILURE,
            'system',
        );

        self::assertSame(AuditEvent::AUTH_LOGIN_FAILURE, $entry->getEventType());
    }

    /**
     * **`SEC` §5.2 rule 4 — the catalogue history is monotone, checked at source level** (sales step 6, C3; required,
     * not conditional, after SEC-RED S-RED-14). Two tracks re-issue `audit_log_event_type_values` (the invoice chain and
     * roles slice 2's B-4a), and a rebase can drop the other side's member from the list a later file writes. On a
     * destroyed volume `theServersLegalSetIsExactlyTheCodeCatalogue` catches that; on a persistent database an
     * earlier-versioned file landing later runs out of order (`RESIDUAL_GAPS.md` R-E), and nothing else sees it.
     *
     * The rule, over every migration whose **`up()`** issues `ADD CONSTRAINT … audit_log_event_type_values CHECK
     * (event_type IN (…))` — `down()` bodies and files that only mention the name (`Version20260910000300` re-issues
     * the IP-scope constraint) are excluded:
     *   1. the **highest-versioned** re-issue's literal set **equals** `AuditEvent::ALL`;
     *   2. every re-issue's set is a **superset** of every earlier one's (no member is ever removed or renamed).
     *
     * Measured when written (M-27): four re-issues — 9, 10, 11, 12 members — plus this commit's 13, no removal.
     * **Anti-vacuity:** at least five re-issues are found. **Planted control, same test:** a scratch re-issue appended
     * after the newest, missing one member, must produce violations naming that member under both rules.
     */
    #[Test]
    public function theNewestCatalogueReissueIsTheCodeCatalogueAndASupersetOfEveryEarlierOne(): void
    {
        $sources = [];
        foreach (glob(\dirname(__DIR__, 2).'/migrations/Version*.php') ?: [] as $file) {
            $sources[basename($file, '.php')] = (string) file_get_contents($file);
        }
        ksort($sources);

        $reissues = self::catalogueReissues($sources);
        self::assertGreaterThanOrEqual(5, \count($reissues),
            'Fewer catalogue re-issues were found than exist (M-27 counted five with invoice.issued), so the parser is blind.');

        self::assertSame([], self::catalogueHistoryViolations($reissues),
            "The catalogue migrations are not monotone, or the newest is not AuditEvent::ALL:\n  "
            .implode("\n  ", self::catalogueHistoryViolations($reissues)));

        // Planted control: a later re-issue that dropped a member.
        $dropped = AuditEvent::INVOICE_ISSUED;
        $plant = $sources;
        $plant['Version99991231000000'] = "<?php final class Version99991231000000 { public function up(\$s): void { \$this->addSql(sprintf(<<<'SQL'\n"
            ."ALTER TABLE audit_log ADD CONSTRAINT %s CHECK (event_type IN (\n"
            .implode(",\n", array_map(static fn (string $e): string => "'".$e."'", array_values(array_diff(AuditEvent::ALL, [$dropped]))))
            ."\n)) NOT VALID\nSQL, self::CONSTRAINT)); } public function down(\$s): void {} private const CONSTRAINT = 'audit_log_event_type_values'; }";
        $violations = self::catalogueHistoryViolations(self::catalogueReissues($plant));
        self::assertCount(2, $violations, 'A planted re-issue that dropped a member did not fail both rules: '.implode(' | ', $violations));
        foreach ($violations as $violation) {
            self::assertStringContainsString($dropped, $violation);
        }
    }

    /**
     * @param array<string, string> $sources version => file contents, in version order
     *
     * @return array<string, list<string>> version => the literal set its `up()` installs, in version order
     */
    private static function catalogueReissues(array $sources): array
    {
        $reissues = [];
        foreach ($sources as $version => $source) {
            if (!str_contains($source, 'audit_log_event_type_values')) {
                continue;
            }
            if (1 !== preg_match('/function up\(.*?(?=function down\()/s', $source, $up)) {
                continue;
            }
            if (1 !== preg_match('/ADD CONSTRAINT (?:%s|audit_log_event_type_values) CHECK \(event_type IN \((.*?)\)\)/s', $up[0], $list)) {
                continue;
            }
            // A `%s` constraint name is this family only if the file's CONSTRAINT constant says so.
            if (!str_contains($up[0], 'ADD CONSTRAINT audit_log_event_type_values')
                && 1 !== preg_match("/const CONSTRAINT = 'audit_log_event_type_values'/", $source)) {
                continue;
            }
            preg_match_all("/'([a-z_.]+)'/", $list[1], $literals);
            $set = array_values(array_unique($literals[1]));
            sort($set);
            $reissues[$version] = $set;
        }

        return $reissues;
    }

    /**
     * @param array<string, list<string>> $reissues
     *
     * @return list<string>
     */
    private static function catalogueHistoryViolations(array $reissues): array
    {
        $violations = [];
        $seen = [];
        foreach ($reissues as $version => $set) {
            $lost = array_values(array_diff($seen, $set));
            if ([] !== $lost) {
                $violations[] = \sprintf('%s drops %s, which an earlier re-issue admitted', $version, implode(', ', $lost));
            }
            $seen = array_values(array_unique([...$seen, ...$set]));
        }

        if ([] !== $reissues) {
            $newest = (string) array_key_last($reissues);
            $all = AuditEvent::ALL;
            sort($all);
            $missing = array_values(array_diff($all, $reissues[$newest]));
            $extra = array_values(array_diff($reissues[$newest], $all));
            if ([] !== $missing || [] !== $extra) {
                $violations[] = \sprintf('the newest re-issue, %s, is not AuditEvent::ALL: missing [%s], extra [%s]',
                    $newest, implode(', ', $missing), implode(', ', $extra));
            }
        }

        return $violations;
    }
}
