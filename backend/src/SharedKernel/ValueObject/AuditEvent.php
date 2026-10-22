<?php

declare(strict_types=1);

namespace App\SharedKernel\ValueObject;


/**
 * The enumerated `audit_log.event_type` catalogue — B-4.
 *
 * ---------------------------------------------------------------------------
 * Why this file exists
 * ---------------------------------------------------------------------------
 * `audit_log.event_type` shipped as `text NOT NULL` with no `CHECK`, no enum and
 * no domain (`Version20260906000100`, the `audit_log` DDL; `AuditLogEntry`
 * mapped it as bare `Types::TEXT`), and no catalogue of legal values existed
 * anywhere in the project — and every literal the documents did name was a
 * security event. (The count is not restated here, Rule 15: the documented
 * strings are pinned by
 * `AuditEventCatalogueTest::theEventTypesNamedInTheProjectDocumentsAreStillInTheCatalogue`,
 * which is the derivation. `DELIVERY_LEDGER.md`'s B-4 row says "three", and
 * `SEC`'s own E-12 added a fourth in the same pass.)
 *
 * `security-audit-log-design` (Instruction 2, and the "Structured logging format
 * example" note) requires an **enumerated `event_type` taxonomy defined in
 * code** so events can be queried consistently, and explicitly rejects free-text
 * event descriptions that vary between developers. This is that taxonomy.
 *
 * Two rules already depended on classifying this column and one of them could
 * not fire:
 *
 *   PC-11 (`SECURITY_MODEL.md` §3)
 *     `audit_log.view_record` returns only rows whose `event_type` is on a
 *     published allowlist of business events. It forbids a `NOT LIKE` predicate
 *     — a prefix denylist "fails open on the first event somebody names outside
 *     it" — and requires an `IN`. It pointed that `IN` at "§6.1's catalogue",
 *     but §6.1 is the 60-permission catalogue and contains zero event types.
 *     `self::BUSINESS` is the referent that clause needed.
 *
 *     PC-11 gained a fourth requirement on 2026-09-07 (SEC-O-15), and it is the
 *     one this file answers with more than a list: (4a) states the **admission
 *     criterion** and is labelled judgement; (4b) is a **pin on `BUSINESS`'s
 *     exact membership**, declared outside this class in
 *     `AuditEventCatalogueTest::PINNED_ALLOWLIST`; (4c) bars an admitted event
 *     from naming `app_user` as its subject, which is what `SUBJECT_TYPES` and
 *     its two companion lists exist to make decidable.
 *
 *   A retention/purge predicate (`SEC-O-3`)
 *     `audit_log` is append-only by grant, unpartitioned and permanently
 *     growing. Any purge or partition-routing rule has to classify events, and
 *     could not.
 *
 * ---------------------------------------------------------------------------
 * Why class constants and not a PHP enum or a Postgres enum type
 * ---------------------------------------------------------------------------
 * Because that is what this schema already does, in eight places, and the
 * convention is load-bearing rather than incidental.
 *
 * Every enumerated text column here is `text` + `CHECK (col IN (...))` named
 * `<table>_<col>_values`, mirrored by `public const` strings on the owning
 * class: `tenant.status` (`Tenant::STATUS_*`, five values),
 * `stock_movement.reason` (`StockMovement::REASON_*`, six),
 * `purchase_order.status`, `sales_order.status`, `invoice.status`,
 * `storage_location.kind`, `customer_address.kind`,
 * `unit_of_measure.dimension`. There is no PHP enum and no `CREATE TYPE … AS
 * ENUM` anywhere in the project.
 *
 *   - A **Postgres enum type** would also be extended by DDL, but `ALTER TYPE …
 *     ADD VALUE` has transaction restrictions a `CHECK` does not, values cannot
 *     be removed at all, and it would be the only one of nine enumerated columns
 *     shaped differently. A `CHECK` is readable via `pg_get_constraintdef`,
 *     which is what lets the guard derive the domain from the server.
 *   - A **PHP backed enum** would give call-site type safety and `::cases()`,
 *     and is the better tool in the abstract. It is not used here for two
 *     reasons. It would be the first enum in the codebase, against eight
 *     existing const-string columns. And `::cases()` would make the catalogue
 *     self-describing in a way that removes the independent `ALL` declaration
 *     the classification check depends on — see the note on `ALL` below.
 *
 * The shape this file actually follows is `SchemaManifest`/`DatabaseRoles`: a
 * declaration of *intent* that guards read, where the catalogue can only answer
 * "what is" and never "what was intended". `AuditEventCatalogueTest` reconciles
 * this file against `pg_constraint` in both directions, exactly as
 * `SchemaGuardTest::theRoleListCoversEveryLoginRoleInTheDatabase` reconciles
 * `DatabaseRoles` against `pg_roles`.
 *
 * ---------------------------------------------------------------------------
 * Closed list, and what that costs
 * ---------------------------------------------------------------------------
 * This is a **closed list**, not a pattern constraint, and the cost is real:
 * Output 9 cannot add a business event without a migration.
 *
 * The alternative — a shape constraint such as `event_type ~
 * '^[a-z_]+(\.[a-z_]+){1,2}$'` — is extensible without DDL and **classifies
 * nothing**, which fails the only two requirements this column has. PC-11 needs
 * an `IN` list and says so; a purge predicate needs to know which events are
 * security events. A pattern gives neither, so B-4 would be nominally closed and
 * actually open.
 *
 * The closed list also enforces **E-17** (`SECURITY_MODEL.md` §5 — "the trail records
 * actions taken, never routes visited") structurally rather than by policy: a
 * route-logging middleware cannot write a row, because no route event is on the
 * list and the database refuses the insert.
 *
 * **The B-4a deferral is OVER — ⚠ this paragraph said *"there is no `goods_receipt.*` event"* and, one
 * paragraph further, that `sales_order.confirmed` was deferred.** Both were true until 2026-09-29, when roles
 * slice 2's C1 minted the five reconciled B-4a carries, each with its writer: `goods_receipt.created`,
 * `sales_order.confirmed`, `fulfilment.created`, `sales_order.deleted` and `purchase_order.deleted` (all
 * **business**, `metadata` `{}`, `SEC`'s filing in `docs/session13/roles-s2-sec.md` §3). Posting a receipt, confirming
 * an order, shipping and discarding a draft now each leave one row naming the actor. **Re-derive `ALL` before quoting
 * any sentence about what this catalogue does or does not carry** — that is how the deferral paragraph went stale once
 * already, when `sales_order.cancelled` was minted the day after it was written.
 *
 * **Still NOT on the trail, stated narrowly so it cannot be read as wider:** edits to a draft (`Update*` and the
 * draft-line operations, sales and purchasing, and `UpdateItem`) record no actor — `updated_at` records when, not who.
 * That is owner-accepted residual **`RESIDUAL_GAPS.md` G-19** (roles slice 2, Q-B, 2026-09-29), whose closing work is
 * one event design covering both modules. `sales_order.restored` and `purchase_order.restored` are minted since order
 * restores C2 / C3 (2026-10-21); G-8 is closed.
 * Customer edits ARE on the trail since customers slice 1 C2 (`customer.updated`, field names only), supplier edits
 * since suppliers slice 1 C2 (`supplier.updated`, the same shape), and customer address edits since suppliers slice 1
 * C2b (`customer_address.created` / `.updated`, the same shape). Warehouse edits are on it since warehouses slice 1 C2
 * (`warehouse.created` / `.updated`) — and those carry **values** (old and new, over an explicit allowlist), a deliberate
 * departure from the names-only shape (owner Q-W2 (a); `SEC` to confirm). Storage-location edits since warehouses slice 1
 * C3 (`storage_location.created` / `.updated`, the same values shape, plus `held_stock` on a kind change).
 *
 * **Process for extending it** — every step, or the suite fails:
 *   1. add the constant here;
 *   2. add it to `ALL`;
 *   3. file it in `SECURITY` or `BUSINESS` — PC-11 names "an event type added
 *      … without a line saying which side of the allowlist it falls on" as a
 *      violation, and `everyEventIsClassifiedExactlyOnce` is where that fails.
 *      **Filing it in `BUSINESS` is a `SEC` decision under PC-11 (4a)**, not a
 *      `BE-LEAD` one, and it also needs the pin in
 *      `AuditEventCatalogueTest::PINNED_ALLOWLIST` edited deliberately —
 *      that pin is (4b) and it is the only thing here that fails on a
 *      *misclassified* event rather than an unclassified one;
 *   4. declare its subject: a table name in `SUBJECT_TYPES`, or membership of
 *      `SUBJECTLESS` or `SUBJECT_RESOLVED_AT_WRITE`. PC-11 (4c) cannot decide an
 *      event that declares none, and `everyEventDeclaresItsSubjectExactlyOnce`
 *      is where that fails;
 *   5. write a **forward** migration that drops `audit_log_event_type_values`
 *      and re-adds it with the wider **literal** list. Never edit a migration
 *      that already re-issued this constraint in place — as of this revision
 *      that is `Version20260907000200` **and** `Version20260909000300`, both
 *      recorded in `doctrine_migration_versions`, where an in-place edit is
 *      invisible to every database that already ran them. And never generate
 *      that list from this
 *      class: `AuditEventCatalogueTest` reconciles the constraint against
 *      `ALL`, and a constraint written from `ALL` cannot disagree with `ALL`,
 *      so the reconciliation becomes a tautology. Measured — a fabricated
 *      constant added here with no migration was fully green from a clean
 *      volume while the migration generated its list. This is the step the
 *      guard exists to enforce; do not remove its independence;
 *   6. **API-O-18** — if the new member is a *security* event, do **both** halves.
 *      Add it to `AuditWriterTest::PINNED_IP_BEARING_EVENTS`, or
 *      `thePinAndTheConstantAgreeInBothDirections` is red and the event is
 *      outside §9.1's layer 1a. **And write a forward migration re-issuing
 *      `audit_log_ip_address_scope` with the wider literal list** — the same
 *      drop-and-re-add shape as step 5, on the same new-file rule, and with the
 *      literals hand-written for the same reason. **This half had no subject
 *      until 2026-09-10**: the `ip_address` `CHECK` did not exist (API-O-23),
 *      so step 6 was the pin alone. It landed under entry condition **E4-5** as
 *      `Version20260910000300`, and step 6 is now both halves. Skipping the
 *      migration half leaves the new security event **silently refused an
 *      `ip_address` at the database** while every PHP guard accepts it —
 *      `theCheckConstraintAndTheConstantAgreeInBothDirections` is where that
 *      fails, and it is red on the omission rather than quiet about it.
 *
 * **Extension log, so the process above can be checked against what was done
 * rather than only read.** Twelve entries below — ⚠ **and the log is incomplete by four:** the `invoice.*`
 * extensions of 2026-09-28/29 were recorded on their own constants' docblocks and not here. *(This line read "Three"
 * until 2026-09-29, and before that "Two" until
 * 2026-09-19 — recount from the entries below, never restate this number.)*
 *
 *   - **`AUTH_PASSWORD_CHANGED`**, 2026-09-09, under entry condition **E4-1** —
 *     `SEC` ruled the name and the `SECURITY` filing (`SECURITY_MODEL.md`
 *     §5.1.4, steps 1–2) before any code existed; `BE-LEAD` did steps 3–5 in that
 *     order. `PINNED_ALLOWLIST` was **not** touched, which is what makes the
 *     filing falsifiable rather than asserted.
 *
 *   - **`PURCHASE_ORDER_CANCELLED`**, 2026-09-10, under `ADR-008` **Amendment 1**
 *     — `SEC` ruled the extension of DSoD-1 to cancellation and the `BUSINESS`
 *     filing under PC-11 (4a) before any code existed; `BE-LEAD` did steps 3–5 in
 *     that order. **Step 6 does not apply**: it is a business event, so it stays
 *     out of `audit_log_ip_address_scope` and out of
 *     `AuditWriterTest::PINNED_IP_BEARING_EVENTS`, and no re-issue of that
 *     constraint was written.
 *
 *     **The falsifiability argument is NOT the one above and must not be read as
 *     it.** `AUTH_PASSWORD_CHANGED` turns on `PINNED_ALLOWLIST` being left alone;
 *     for a `BUSINESS` event the pin **has** to be edited, so "untouched" is not
 *     available and copying that sentence here would be false. What carries the
 *     weight instead is **what the pin is made of**: it is a list of *string
 *     literals* declared in a different file from the catalogue, deliberately not
 *     written as `AuditEvent::` constants (`AuditEventCatalogueTest`'s own
 *     docblock says so). So the edit that admits this event to `.view_record` is
 *     a second, independent assertion of the string, made by hand, in a test that
 *     cannot be quieted from this class — and had it been written as
 *     `AuditEvent::PURCHASE_ORDER_CANCELLED` the pin would have agreed with the
 *     catalogue by construction and (4b) would have gone silent. **The pin being
 *     touched is not the weakness; the pin being an independent literal is the
 *     control.**
 *
 *   - **`SALES_ORDER_CANCELLED`**, 2026-09-19, under owner ruling **OI-2** (#2
 *     R1a) — the owner named the event and `SEC` filed it **`BUSINESS`** under
 *     PC-11 (4a) in `SECURITY_MODEL.md`, both **before any code existed**;
 *     `BE-LEAD` did steps 3–5 in that order, in the commit that built
 *     `CancelSalesOrder`. **Step 6 does not apply** — a business event, so no
 *     `PINNED_IP_BEARING_EVENTS` entry and no re-issue of
 *     `audit_log_ip_address_scope`. Step 5 is `Version20260919000400`.
 *     `PINNED_ALLOWLIST` was edited deliberately, which is what (4b) requires of
 *     a business event and is `PURCHASE_ORDER_CANCELLED`'s note above, unchanged.
 *
 *     **Unlike both entries above, this extension shipped its WRITER in the same
 *     commit** — `SalesOrderAuditWriter::recordCancelled()` — so the catalogue
 *     entry is not a forward declaration and must not be read as one.
 *     `PURCHASE_ORDER_CANCELLED`'s docblock had to carry *"Nothing writes this
 *     event today"* for a day; this one never did.
 *
 *     **Observed rather than assumed**, and this is the other half of it: with
 *     steps 1–2 done and steps 3–5 not, `everyEventIsClassifiedExactlyOnce` and
 *     `everyEventDeclaresItsSubjectExactlyOnce` were red — and so were
 *     `theServersLegalSetIsExactlyTheCodeCatalogue` and
 *     `everyCatalogueValueIsAcceptedByTheDatabase`, which is the migration's
 *     independence from `ALL` showing itself in the only direction that can prove
 *     it.
 *
 *   - **The five B-4a carries** (`GOODS_RECEIPT_CREATED`, `SALES_ORDER_CONFIRMED`, `FULFILMENT_CREATED`,
 *     `SALES_ORDER_DELETED`, `PURCHASE_ORDER_DELETED`), 2026-09-29, roles slice 2 C1 — `SEC` filed all five
 *     **`BUSINESS`**, `{}`, before the code (`roles-s2-sec.md` §3); `BE-LEAD` did steps 3–5, **each with its writer
 *     in the same commit**. Step 5 is `Version20260929000200`, whose literal list is the live constraint's sixteen
 *     members (read off `pg_constraint`, not generated) plus these five. **Step 6 does not apply.**
 *     `PINNED_ALLOWLIST` was edited deliberately with five literals.
 *
 *   - **`CUSTOMER_CREATED` and `CUSTOMER_UPDATED`**, 2026-10-09, customers slice 1 C2 — filed **`BUSINESS`** by the
 *     delegated `SEC`-role filing in `docs/session13/customers-s1-g35-judge.md` C-4 (`SEC` to confirm), before the code;
 *     `BE-A` did steps 3–5, **each with its writer in the same commit** (`CustomerAuditWriter`). Step 5 is
 *     `Version20261009000400`, whose literal list is the live constraint's thirty-one members (read off
 *     `pg_constraint`, not generated) plus these two. **Step 6 does not apply.** `PINNED_ALLOWLIST` edited by hand with
 *     two literals. `RESIDUAL_GAPS.md` G-35.
 *
 *   - **`SUPPLIER_CREATED` and `SUPPLIER_UPDATED`**, 2026-10-12, suppliers slice 1 C2 — filed **`BUSINESS`** on the
 *     `customer.*` filing's terms (owner Q-S5, `docs/session13/suppliers-s1-ruling.md`; `SEC`'s stated view in
 *     `owed-roles-recs.md` S2; `SEC` to confirm the supplier block), before the code; steps 3–5 done **each with its
 *     writer in the same commit** (`SupplierAuditWriter`). **Only these two**: `supplier.deleted` / `.restored` are minted
 *     with their writers in the later archived-view slice (final RED M-A). Step 5 is `Version20261012000300`, whose
 *     literal list is the live constraint's thirty-three members (read off `pg_constraint`, not generated) plus these
 *     two. **Step 6 does not apply.** `PINNED_ALLOWLIST` edited by hand with two literals.
 *
 *   - **`CUSTOMER_ADDRESS_CREATED` and `CUSTOMER_ADDRESS_UPDATED`**, 2026-10-12, suppliers slice 1 C2b — owner ruling
 *     F-12 / S4 "Record address changes" (`docs/session13/suppliers-s1-ruling.md`; `owed-roles-recs.md` S4 (a)), filed
 *     **`BUSINESS`** on the `customer.*` filing's terms (`SEC` to confirm the address block, incl. the subject), before
 *     the code; steps 3–5 done **each with its writer in the same commit** (`CustomerAddressAuditWriter`), in their own
 *     commit serialised after the supplier re-issue (final RED L-E; G-17). Step 5 is `Version20261012000400`, whose
 *     literal list is the live constraint's thirty-five members (read off `pg_constraint`, not generated) plus these two.
 *     **Step 6 does not apply.** `PINNED_ALLOWLIST` edited by hand with two literals.
 *
 *   - **`WAREHOUSE_CREATED` and `WAREHOUSE_UPDATED`**, 2026-10-13, warehouses slice 1 C2 — owner Q-W2 (a), adopted under
 *     delegation (`docs/session13/warehouses-s1-design.md` §3.2; final RED M-4: values ship, `SEC` confirmation is not a
 *     build gate), filed **`BUSINESS`** on the `supplier.*` filing's terms (`SEC` to confirm the warehouse block, incl.
 *     the values departure), before the code; steps 3–5 done **each with its writer in the same commit**
 *     (`WarehouseAuditWriter`). **Only these two**: `warehouse.deleted` / `.restored` come with `DeleteWarehouse` /
 *     `RestoreWarehouse` (API-O-34, G-7). Step 5 is `Version20261013000200`, whose literal list is the live constraint's
 *     thirty-seven members (read off `pg_constraint`, not generated) plus these two. **Step 6 does not apply.**
 *     `PINNED_ALLOWLIST` edited by hand with two literals.
 *
 *   - **`STORAGE_LOCATION_CREATED` and `STORAGE_LOCATION_UPDATED`**, 2026-10-13, warehouses slice 1 C3 — owner Q-W2 (a)
 *     and Q-W1 (d), adopted under delegation (design §4.5; final RED M-1, M-4), filed **`BUSINESS`** on the `warehouse.*`
 *     filing's terms (`SEC` to confirm the storage-location block, incl. values and `held_stock`), before the code; steps
 *     3–5 done **each with its writer in the same commit** (`StorageLocationAuditWriter`). **Only these two**:
 *     `.deleted` / `.restored` come with `DeleteStorageLocation` / `RestoreStorageLocation` (G-27). Step 5 is
 *     `Version20261013000400`, whose literal list is the live constraint's thirty-nine members (read off `pg_constraint`,
 *     not generated) plus these two. **Step 6 does not apply.** `PINNED_ALLOWLIST` edited by hand with two literals.
 *
 *   - **`SUPPLIER_RESTORED`**, 2026-10-13, C5 C2a — filed **`BUSINESS`** by `SEC` with `supplier.deleted`
 *     (`SECURITY_MODEL.md` supplier block; `docs/session13/c5-design.md` §6.1), before the code; steps 3–5 done **with its
 *     writer in the same commit** (`SupplierAuditWriter::recordRestored()`). **Only this one**: `supplier.deleted` comes
 *     with `DeleteSupplier` (C5 C2b). Step 5 is `Version20261013000600`, whose literal list is the live constraint's
 *     forty-one members (read off `pg_constraint`, not generated) plus this one. **Step 6 does not apply.**
 *     `PINNED_ALLOWLIST` edited by hand with one literal.
 *
 *   - **`SUPPLIER_DELETED`**, 2026-10-14, C5 C2b — filed **`BUSINESS`** by `SEC` with `supplier.restored`
 *     (`SECURITY_MODEL.md` supplier block; `docs/session13/c5-design.md` §6.1), before the code; steps 3–5 done **with its
 *     writer in the same commit** (`SupplierAuditWriter::recordDeleted()`). Step 5 is `Version20261014000100`, whose
 *     literal list is the live constraint's forty-two members (read off `pg_constraint`, not generated) plus this one.
 *     **Step 6 does not apply.** `PINNED_ALLOWLIST` edited by hand with one literal.
 *
 *   - **`CUSTOMER_RESTORED`**, 2026-10-14, C5 C4a — **filed `BUSINESS` by the builder on the supplier filing's terms**
 *     (`docs/session13/c5-design.md` §6.1 / §9 S-1: `SEC` filed the supplier pair only; `SEC` to confirm the customer
 *     pair), steps 3–5 done **with its writer in the same commit** (`CustomerAuditWriter::recordRestored()`). **Only this
 *     one**: `customer.deleted` comes with `DeleteCustomer` (C5 C4b). Step 5 is `Version20261014000300`, whose literal
 *     list is the live constraint's forty-three members (read off `pg_constraint`, not generated) plus this one. **Step 6
 *     does not apply.** `PINNED_ALLOWLIST` edited by hand with one literal.
 *
 *   - **`CUSTOMER_DELETED`**, 2026-10-14, C5 C4b — filed **`BUSINESS`** by the builder with `customer.restored`, on the
 *     supplier filing's terms (`SEC` to confirm the customer pair, design §9 S-1); steps 3–5 done **with its writer in
 *     the same commit** (`CustomerAuditWriter::recordDeleted()`). Step 5 is `Version20261014000400`, whose literal list
 *     is the live constraint's forty-four members (read off `pg_constraint`, not generated) plus this one. **Step 6 does
 *     not apply.** `PINNED_ALLOWLIST` edited by hand with one literal.
 *
 *   - **`CUSTOMER_CONTACT_CREATED` and `CUSTOMER_CONTACT_UPDATED`**, 2026-10-19, customers slice 2 C1 — owner ruling
 *     Q-2 "yes, record contact changes" (`docs/session13/s35-ruling.md`), filed **`BUSINESS`** by `SEC`
 *     (`customers-s2-sec-arch.md` S-4) on the address events' terms: subject the contact row, `{}` and `{"fields"}` —
 *     names, never values (a contact is personal data). Steps 3–5 done **with their writer in the same commit**
 *     (`CustomerContactAuditWriter`). Step 5 is `Version20261019000200`, whose literal list is the live constraint's
 *     forty-five members (read off `pg_constraint`, not generated, immediately before writing) plus these two; it
 *     supersedes `Version20261014000400`. **Step 6 does not apply.** `PINNED_ALLOWLIST` edited by hand with two literals.
 *
 *   - **`CUSTOMER_CONTACT_DELETED`**, 2026-10-19, customers slice 2 C2 — owner Q-2, filed **`BUSINESS`** by `SEC`
 *     (`customers-s2-sec-arch.md` S-4) with `{"customer_id"}` so the chain stays attributable once the row is gone. Steps
 *     3–5 done **with its writer in the same commit** (`CustomerContactAuditWriter::recordDeleted()`). Step 5 is
 *     `Version20261019000400`, whose literal list is the live constraint's forty-seven members (read off `pg_constraint`,
 *     not generated) plus this one; it supersedes `Version20261019000200`. **Step 6 does not apply.** `PINNED_ALLOWLIST`
 *     edited by hand with one literal.
 *
 *   - **`CUSTOMER_ADDRESS_DELETED`**, 2026-10-19, customers slice 2 C3 — inside owner ruling F-12 / S4 "Record address
 *     changes" (`SEC`, `customers-s2-sec-arch.md` S-4: not a new owner question), filed **`BUSINESS`** with
 *     `{"customer_id"}`. Steps 3–5 done **with its writer in the same commit** (`CustomerAddressAuditWriter::recordDeleted()`).
 *     Step 5 is `Version20261019000600`, whose literal list is the live constraint's forty-eight members (read off
 *     `pg_constraint`, not generated) plus this one; it supersedes `Version20261019000400`. **Step 6 does not apply.**
 *     `PINNED_ALLOWLIST` edited by hand with one literal.
 *
 *   - **`WAREHOUSE_RESTORED`**, 2026-10-20, warehouse/location delete-restore C1 — filed **`BUSINESS`**, subject the
 *     warehouse, `{}`, by `SEC` (`docs/session13/wh-loc-delete-roles.md` S-1), before the code; steps 3–5 done **with its
 *     writer in the same commit** (`WarehouseAuditWriter::recordRestored()`). **Only this one**: `warehouse.deleted` comes
 *     with `DeleteWarehouse` (C4). Step 5 is `Version20261020000200`, whose literal list is the live constraint's
 *     forty-nine members (read off `pg_constraint`, not generated) plus this one; it supersedes `Version20261019000600`.
 *     **Step 6 does not apply.** `PINNED_ALLOWLIST` edited by hand with one literal.
 *
 *   - **`STORAGE_LOCATION_RESTORED`**, 2026-10-20, warehouse/location delete-restore C2 — filed **`BUSINESS`**, subject the
 *     location, `{}`, by `SEC` (`wh-loc-delete-roles.md` S-1), before the code; steps 3–5 done **with its writer in the
 *     same commit** (`StorageLocationAuditWriter::recordRestored()`). **Only this one**: `storage_location.deleted` comes
 *     with `DeleteStorageLocation` (C3). Step 5 is `Version20261020000400`, whose literal list is the live constraint's
 *     fifty members (read off `pg_constraint`, not generated) plus this one; it supersedes `Version20261020000200`.
 *     **Step 6 does not apply.** `PINNED_ALLOWLIST` edited by hand with one literal.
 *
 *   - **`STORAGE_LOCATION_DELETED`**, 2026-10-20, warehouse/location delete-restore C3 — filed **`BUSINESS`**, subject the
 *     location, `{}`, by `SEC` (`wh-loc-delete-roles.md` S-1), before the code; steps 3–5 done **with its writer in the
 *     same commit** (`StorageLocationAuditWriter::recordDeleted()`). The storage-location pair is complete. Step 5 is
 *     `Version20261020000500`, whose literal list is the live constraint's fifty-one members (read off `pg_constraint`, not
 *     generated) plus this one; it supersedes `Version20261020000400`. **Step 6 does not apply.** `PINNED_ALLOWLIST` edited
 *     by hand with one literal.
 *
 *   - **`WAREHOUSE_DELETED`**, 2026-10-20, warehouse/location delete-restore C4 — filed **`BUSINESS`**, subject the
 *     warehouse, `{}`, by `SEC` (`wh-loc-delete-roles.md` S-1), before the code; steps 3–5 done **with its writer in the
 *     same commit** (`WarehouseAuditWriter::recordDeleted()`). The warehouse pair is complete. Step 5 is
 *     `Version20261020000600`, whose literal list is the live constraint's fifty-two members (read off `pg_constraint`, not
 *     generated) plus this one; it supersedes `Version20261020000500`. **Step 6 does not apply.** `PINNED_ALLOWLIST` edited
 *     by hand with one literal.
 *
 *   - **`SUPPLIER_PRODUCT_CREATED`, `SUPPLIER_PRODUCT_UPDATED` and `SUPPLIER_PRODUCT_DELETED`**, 2026-10-22, G3-C0 —
 *     `RESIDUAL_GAPS.md` G-41's closing work (owner Q-S5's condition for any screen that copies a catalogue cost;
 *     `docs/session13/g3-g7b-reorder-settings-design.md` §1, Revision 1 FINAL), filed **`BUSINESS`** by the builder on the
 *     `supplier.*` filing's terms (design D-C0-1/-2; `SEC` to confirm the catalogue block), before the code; steps 3–5 done
 *     **with their writer in the same commit** (`SupplierAuditWriter::recordCatalogue*()`). Step 5 is
 *     `Version20261022005000`, whose literal list is the live constraint's fifty-six members (read off `pg_constraint`, not
 *     generated) plus these three; it supersedes `Version20261022000100`. **Step 6 does not apply.** `PINNED_ALLOWLIST`
 *     edited by hand with three literals.
 *
 *   - **`INVENTORY_ITEM_DELETED` and `INVENTORY_ITEM_RESTORED`**, 2026-10-22, G5-C2a — a finding of the bulk design
 *     (`docs/session13/g5-g6-bulk-export-design.md` §1 "Why G5-C2a exists", §5.5): `DeleteItem` and `RestoreItem` wrote
 *     no audit row, and nothing recorded that an item archive was unattributed. Filed **`BUSINESS`** by the builder on the
 *     `customer.deleted` / `.restored` filing's terms (subject the item, `{}`; `SEC` to confirm), before the code; steps
 *     3–5 done **with their writer in the same commit** (`ItemAuditWriter`). Step 5 is `Version20261022010000`, whose
 *     literal list is the live constraint's fifty-nine members (read off `pg_constraint`, not generated) plus these two;
 *     it supersedes `Version20261022005000`. **Step 6 does not apply.** `PINNED_ALLOWLIST` edited by hand with two literals.
 *
 *   - **`TENANT_SETTINGS_UPDATED`**, 2026-10-22, G7b `UpdateSettings` — owner ruling RO-S1 (`docs/session13/
 *     remaining-ops-ruling.md`), designed in `g3-g7b-reorder-settings-design.md` Revision 1 FINAL (R1.2 M-5). Filed
 *     **`BUSINESS`** by the builder under PC-11 (4a) (`SEC` to confirm): about the settings row's own state, the
 *     `supplier.updated` reasoning. Its History is read under `tenant_settings.view` (`RecordHistorySubjects`) and is
 *     **unreachable from the product**: the singleton serves no id, so no screen can ask `ListAuditRecords` for it (final RED
 *     F-M4; `RESIDUAL_GAPS.md`); `ListAuditLogEntries` (owner / admin) shows it. Steps 3–5 done **with its writer in the same
 *     commit** (`SettingsAuditWriter`). Step 5 is `Version20261022020100`: the live constraint's sixty-one members (read off
 *     `pg_constraint`, not generated) plus this one; it supersedes `Version20261022010000`. **Step 6 does not apply.**
 *     `PINNED_ALLOWLIST` edited by hand with one literal.
 *
 *   - **`USER_DEACTIVATED`, `USER_REACTIVATED` and `USER_UPDATED`**, 2026-10-23, G4-C1 `UpdateUser` — `SEC` filed all three
 *     **`SECURITY`** before the code (`docs/session13/g4-users-design.md` §5.3, D-9, D-10): a liveness change is privilege
 *     history (PC-11 (4a)), and a rename of a principal names an `app_user` subject, which (4c) bars from the business
 *     allowlist structurally. Subject `('app_user', id)`. Steps 3–5 done **with their writer in the same commit**
 *     (`UserAuditWriter`). Step 5 is `Version20261023000200`: the live constraint's sixty-two members (read off
 *     `pg_constraint`, not generated) plus these three; it supersedes `Version20261022020100`. **Step 6 applies, both
 *     halves:** `AuditWriterTest::PINNED_IP_BEARING_EVENTS` gains the three, and the same migration re-issues
 *     `audit_log_ip_address_scope` (sixteen + three, superseding `Version20261022000100`'s IP list). `PINNED_ALLOWLIST` was
 *     **not** touched.
 *
 * @see security-audit-log-design, Instruction 2
 * @see SECURITY_MODEL.md §5 E-3, E-12, E-13, E-15, E-16, E-17, E-23; §5.1.4; §3 PC-11; §4 the default-role table (same file)
 * @see ADR-006 §2.2
 */
final class AuditEvent
{
    // ---------------------------------------------------------------------
    // Security events. Never readable through `audit_log.view_record` (PC-11);
    // the whole tenant trail is `audit_log.view`, which most default roles do
    // not hold — L-7 (`IA.md` §4) names the ones that do, and `SECURITY_MODEL.md` §4's role table
    // is the source. Not restated here: an earlier draft named two roles it is
    // withheld from, which reads as though the rest held it.
    //
    // **This heading is a description, not the criterion.** The criterion is
    // PC-11 (4a) and it is stated on `SECURITY` itself: an event is *admitted*
    // to `.view_record` only if it is about the subject row's own business
    // state, and everything else is here. "Events about principals and
    // privileges" was the old wording, and SEC-O-15 records what it cost — it
    // reads as a definition, it does not cover `data.export.completed`, which
    // is filed here on separate grounds, and it did not visibly settle
    // `role.*`, which is about a principal and a privilege and sat on the
    // allowlist anyway.
    // ---------------------------------------------------------------------

    /** ADR-006 §2.2 table, "Success" row. */
    public const AUTH_LOGIN_SUCCESS = 'auth.login.success';

    /**
     * ADR-006 §2.2: slug and email both resolve, password wrong — "a real
     * principal, a real security event". Written via `erp_authlog`. The one
     * literal that was already load-bearing in a committed test.
     */
    public const AUTH_LOGIN_FAILURE = 'auth.login.failure';

    /**
     * E-12 (`SECURITY_MODEL.md` §5): a **verified** credential presented against a
     * non-active tenant. Written synchronously via `erp_authlog` with the same
     * statement count as the wrong-password branch, and suppressed by the same
     * daily cap on the same key, so the two branches fall silent together.
     */
    public const AUTH_LOGIN_TENANT_INACTIVE = 'auth.login.tenant_inactive';

    /**
     * E-3 (`SECURITY_MODEL.md` §5): every permission denial that reaches the API. Capped
     * by E-3a at **at most two** rows per
     * `(tenant_id, actor_user_id, permission_code)` per UTC day — the second
     * exists to record that a *second resource* was tried, and E-3a's own text
     * records the ceiling moving 1 → 2. `SECURITY_MODEL.md` §5 is "the single register for `E-*`",
     * so E-3a is where Output 4 reads the ceiling; SEC-O-3 now states the bound's
     * existence and deliberately does not restate its value.
     */
    public const AUTHZ_PERMISSION_DENIED = 'authz.permission.denied';

    /**
     * **E-23** (`SECURITY_MODEL.md` §5, ruled in §5.1.4): a change to a principal's
     * own credential, written **synchronously on the request that made the
     * change**, by **`erp_app`** — which already holds `SELECT, INSERT` on
     * `audit_log` and needed no new grant. `erp_authlog` is the pre-authentication
     * principal and is deliberately not reachable from this path.
     *
     * **The `auth.` prefix is load-bearing and was `SEC`'s reason for the name.**
     * E-13 is written over `auth.*`, so membership of `SUBJECTLESS` below is
     * *structural* under a rule that already exists and already has a passing
     * guard, rather than a fresh declaration somebody has to remember to make
     * correctly. `app_user.password_changed` was rejected for the opposite
     * reason: it puts the resource in the name and invites
     * `subject_type = 'app_user'`, which is the shape PC-11 (4c) exists to stop.
     *
     * **A security event under PC-11 (4a), and it is not close** — (4a) excludes
     * *"an event about a principal's credentials, sessions or privileges"* in its
     * own words, and a password change is the credentials case. That filing is
     * judgement and (4a) says so (Rule 14); what makes it *checkable* is that
     * `AuditEventCatalogueTest::PINNED_ALLOWLIST` was **not** edited when this
     * member landed, so moving it to the business side later is red on (4b)
     * until somebody edits the pin deliberately.
     *
     * **`metadata` is empty and there is no parameter that could fill it.** E-23
     * forbids any credential-derived value there — not the password, not a hash,
     * a prefix, a length, a strength score or a *"same as previous"* flag —
     * because `metadata` is free-form `jsonb` on an append-only table no role can
     * `UPDATE` or `DELETE`, so a value written there is written permanently.
     * `PasswordChangeAuditWriter` has no metadata parameter, and
     * `PasswordChangeAuditTest::itsMetadataIsEmpty` pins the key set **at empty**
     * rather than denylisting credential-shaped names.
     *
     * **No cap, and the asymmetry with E-3a is the point.** E-3a caps
     * `authz.permission.denied` because the server cannot refuse the *action*; a
     * credential change **can** be refused, so the limit belongs on the operation
     * and the record of a change that actually happened is never suppressed. A
     * daily cap on this event is a violation of E-23 in terms.
     *
     * **The re-derivation trigger, carried from §5.1.4 rather than left there:**
     * the day an actor may change **another** principal's credential — an
     * administrative reset, or `RedeemPasswordReset` acting on a token — actor
     * and subject diverge and a subjectless row loses *whose* password changed.
     * That is a **different catalogue member** whose subject is `app_user` in
     * `SUBJECT_TYPES` on E-15's precedent, and minting it is a fresh E4-1 pass
     * and a fresh `SEC` ruling, not an edit to this constant.
     */
    public const AUTH_PASSWORD_CHANGED = 'auth.password.changed';

    /**
     * E-16 (`SECURITY_MODEL.md` §5): one row per bulk CSV export recording the resource,
     * the filter state and the row count — never the rows.
     *
     * Filed as a **security** event, not a business one, on two grounds. The
     * skill names bulk export as a first-class security event class and E-16
     * cites it for exactly that. And the event has no single subject row — it
     * describes a filtered list — so `subject_type`/`subject_id` are NULL and it
     * could never surface through `.view_record` anyway, whose query is bound to
     * one `subject_id` (E-5). Filing it here makes that explicit instead of
     * incidental.
     *
     * **Name assigned here** — E-16 specifies the write path and not the string.
     */
    public const DATA_EXPORT_COMPLETED = 'data.export.completed';

    /**
     * E-15 (`SECURITY_MODEL.md` §5): one row per `(actor, subject user, role)`. A bulk
     * assignment writes N rows, never one — L-9's reversal register rests on the
     * grant history living in `audit_log`, and "who made Alice an admin" cannot
     * be answered by one row naming a set.
     *
     * **A security event, ruled by `SEC` in SEC-O-15, and it was filed as a
     * business event until then.** The ground is PC-11 (4a): an event is admitted
     * to `.view_record` only if it is about the **subject row's own business
     * state**, and an event about a principal's **privileges** is excluded
     * whatever its name begins with. `role.*` begins with neither `auth.` nor
     * `authz.`, which is why the prefix check that stood here caught nothing.
     *
     * **The disclosure was concrete.** This event's subject is the person whose
     * privileges changed (`SUBJECT_TYPES`, `app_user`), `viewer` holds
     * `app_user.view` **and** `audit_log.view_record`, and L-7 puts a History
     * section on `/admin/users/[id]` — so on the allowlist, any colleague read a
     * named person's full role-grant and revocation history. That is PC-11's own
     * worked example with privilege history in place of login history.
     *
     * **What the move costs, stated rather than discovered:** the History section
     * of `/admin/users/[id]` renders empty for **every** role, not merely for the
     * ones without `audit_log.view` — §3.1 gates that surface on `.view_record`
     * alone. Grant history is read in *Administration → Audit Log*, which is
     * where `audit_log.view` points, and that permission is held by `owner` and
     * `admin`. Under PC-11 (4c) the section stays empty permanently unless that
     * clause records a named exception.
     *
     * **Name assigned here.**
     */
    public const ROLE_ASSIGNED = 'role.assigned';

    /**
     * E-15's other half: revocation. `user_role` is deletion class `hard`, so
     * this row is the only record. A security event on the same ground as
     * `ROLE_ASSIGNED` — see there. **Name assigned here.**
     */
    public const ROLE_REVOKED = 'role.revoked';

    /**
     * **A grant of a role proposed, pending an owner's confirmation** — E-15e, minted 2026-10-04 with
     * `CreateRoleGrantProposal` (roles slice 3, C1). **Filed security by `SEC`** (`SECURITY_MODEL.md` §5.2.8): a pending
     * grant is privilege history, PC-11 (4a) excludes it from `.view_record` whatever its name, and its subject is the
     * `app_user` whose privileges would change — so PC-11 (4c) bars it from the business allowlist structurally. Actor the
     * proposer; the request's IP (API-O-18: `audit_log_ip_address_scope` re-issued, `Version20261004000200`); `metadata`
     * ids only — `RoleEventMetadata::ROLE_ID`, `::PROPOSAL_ID` and, on a proposal that lapsed an expired one,
     * `::SUPERSEDES_PROPOSAL_ID`. **Name assigned by `BE-LEAD`** (§5.2.8: *"the names are `BE-LEAD`'s"*).
     */
    public const ROLE_ASSIGNMENT_PROPOSED = 'role.assignment_proposed';

    /**
     * **A pending proposal closed with no decision** — a lapse caused by a committed request: in C1, an expired same-pair
     * proposal lapsed by a new proposal (`lapse_reason` `expired`); later E-15g's deactivation and deletion paths
     * (`subject_removed`, `proposer_removed`). Security, on `ROLE_ASSIGNMENT_PROPOSED`'s ground (§5.2.8). Actor the
     * requester; `metadata` `role_id`, `proposal_id`, `lapse_reason` (the enum value only).
     */
    public const ROLE_ASSIGNMENT_LAPSED = 'role.assignment_lapsed';

    /**
     * **A pending proposal refused by an owner** — E-15e, minted 2026-10-05 with `RejectRoleGrantProposal` (roles slice 3,
     * C3). Security, on `ROLE_ASSIGNMENT_PROPOSED`'s ground (§5.2.8). Actor the rejecter (an owner who manages roles,
     * Q-6 (c′)); subject the `app_user` the role was proposed for; the request's IP; `metadata` ids only —
     * `RoleEventMetadata::ROLE_ID`, `::PROPOSAL_ID`, `::PROPOSED_BY_USER_ID`. **Never the reason text** (PC-16 condition
     * 3: free text stays on the proposal row, under `role.assign`). Name assigned by `BE-LEAD`.
     */
    public const ROLE_ASSIGNMENT_REJECTED = 'role.assignment_rejected';

    /**
     * **A pending proposal withdrawn by its proposer** — E-15e, minted 2026-10-05 with `CancelRoleGrantProposal` (roles
     * slice 3, C3; the copy says "withdraw"). Security, on the same ground. Actor the proposer; `metadata` `role_id`,
     * `proposal_id`. Name assigned by `BE-LEAD`.
     */
    public const ROLE_ASSIGNMENT_CANCELLED = 'role.assignment_cancelled';

    /**
     * E-15f's one row per role definition change (roles slice 4, C1; `docs/session13/roles-s4-design.md` §7,
     * `roles-s4-sec-data.md` D-7.3): `CreateRole` with the full set, `UpdateRole` (C2) when the code set changes. Subject
     * `('role', id)`; `metadata` `{role_id, added, removed}` — codes, bounded by the catalogue. SECURITY: it changes what
     * every holder may do (PC-11 (4a)); IP-bearing.
     */
    public const ROLE_PERMISSIONS_CHANGED = 'role.permissions_changed';

    /**
     * Roles slice 4 C2 (`UpdateRole`; `SEC` D-7.3, `roles-s4-sec-data.md`): an edit that changes ONLY a role's name and/or
     * description. Subject `('role', id)`; `metadata` `{role_id, fields}` — field names, never values. SECURITY: a held
     * role's name is what an owner reads when granting and confirming (E-15h treats name-keyed trust as the violation), so
     * a rename is security-relevant on its own ground; IP-bearing. An edit that changes the code set writes
     * {@see self::ROLE_PERMISSIONS_CHANGED} alone, naming any changed fields.
     */
    public const ROLE_UPDATED = 'role.updated';

    /**
     * Roles slice 4 C3 (`DeleteRole`; design §7; `SEC` D-7.3's three-event set): a composed role soft-deleted. Subject
     * `('role', id)`; `metadata` `{role_id}` only. SECURITY: E-15h's whole concern is role deletion changing privileges —
     * with the holder and pending refusals it changes none, and this row is the record that a definition left the set;
     * IP-bearing.
     */
    public const ROLE_DELETED = 'role.deleted';

    /**
     * G2 (`RestoreRole`, 2026-10-22; `docs/session13/g2-restore-role-design.md` §3 S-3): a composed role brought back from
     * the archive — only one nobody holds and nobody has a pending proposal for. Subject `('role', id)`; `metadata`
     * `{role_id}` only. SECURITY, by `role.deleted`'s ground: it puts a definition back in the set that can be granted;
     * IP-bearing.
     */
    public const ROLE_RESTORED = 'role.restored';

    /**
     * G4-C1 (`UpdateUser`, 2026-10-23; `docs/session13/g4-users-design.md` §5.3, D-10): a person switched off — `is_active`
     * true → false. Subject `('app_user', id)`; `metadata` `{}`. Written FIRST in its transaction, before the
     * `role.revoked` row per stripped grant (each carrying `revoke_reason: subject_removed`) and the `role.assignment_lapsed`
     * row per lapsed proposal (E-15g). SECURITY: a liveness change is privilege history (PC-11 (4a)); IP-bearing.
     */
    public const USER_DEACTIVATED = 'user.deactivated';

    /**
     * G4-C1 (`UpdateUser`): a person switched back on — `is_active` false → true. No role comes back (E-15g); any leftover
     * grant (a database edit) is stripped and recorded as `role.revoked` with `revoke_reason`. Subject `('app_user', id)`;
     * `metadata` `{}`. SECURITY, IP-bearing.
     */
    public const USER_REACTIVATED = 'user.reactivated';

    /**
     * G4-C1 (`UpdateUser`, owner ruling RO-U1 (a)): a person renamed. Subject `('app_user', id)`; `metadata`
     * `{"fields": ["display_name"]}` — **field names only, never the old or new name** (D-9: personal data in an append-only
     * table; the SQL route H0 closed and C1 re-opens writes no row either way, `RESIDUAL_GAPS.md` G-12). SECURITY: (4c)
     * bars an `app_user` subject from the business allowlist; IP-bearing.
     */
    public const USER_UPDATED = 'user.updated';

    // ---------------------------------------------------------------------
    // Business events. These are the PC-11 allowlist: the rows a colleague may
    // read through `audit_log.view_record` in the History section of a detail
    // page (L-7), in addition to holding the subject row's own `.view`.
    //
    // Naming follows the project's existing two-segment `<table>.<verb>`
    // vocabulary — the same shape as the `SECURITY_MODEL.md` §3 permission codes
    // (`purchase_order.approve`, `stock_movement.adjust`,
    // `audit_log.view_record`) — rather than inventing a third convention.
    // ---------------------------------------------------------------------

    /**
     * `FLOWS.md` **F-2 — Raise a purchase order**, step 8:
     * `PurchaseOrder::place()`, `draft → ordered`,
     * "writes an `audit_log` row naming the approver". FLOWS is explicit that
     * placing *is* approving and that there is no `approved` status, so this is
     * one event and not two.
     *
     * **Name assigned here** — the flow specifies the write and not the string.
     */
    public const PURCHASE_ORDER_PLACED = 'purchase_order.placed';

    /**
     * `FLOWS.md` **F-6 — Correct or move stock**, the "where that written reason
     * goes" paragraph: a stock adjustment's written reason. `stock_movement`
     * has eleven columns (nine until stock count C0, 2026-10-16, added the two
     * retry-key hashes) and none is free text — `reason` is a six-value `CHECK`
     * of which exactly one applies — so the operator's words live in
     * `audit_log.metadata` (`jsonb`) with `subject_type = 'stock_movement'`, and
     * surface on `/stock-movements/[id]` through L-7.
     *
     * This is also what keeps L-9's F-6a row honest: the compensating action for
     * a bad adjustment is another adjustment, and the two are indistinguishable
     * in `stock_movement` itself — the trail is the only thing that can tell
     * them apart.
     *
     * **Name assigned here.**
     */
    public const STOCK_MOVEMENT_ADJUSTED = 'stock_movement.adjusted';

    /**
     * `ADR-008` **Amendment 1** (2026-09-10) — DSoD-1 extends to cancellation, so
     * `CancelPurchaseOrder` is a controlled transition and the trail has to carry
     * it. `SECURITY_MODEL.md` **E-19** is the enforcement rule; §2's table puts
     * `purchase_order.cancel` on SoD-1's controlled half beside `.approve`, which
     * is what makes the cancellation the *other end* of the commitment
     * `PURCHASE_ORDER_PLACED` records rather than an ordinary edit.
     *
     * **A business event under PC-11 (4a), and it is the clause's central case
     * rather than a borderline one:** it is about the subject row's own business
     * state — a `purchase_order` moving to `cancelled` — and about no principal's
     * credentials, sessions or privileges. `PURCHASE_ORDER_PLACED` is filed the
     * same way on the same ground, and a trail that shows a colleague the placing
     * and hides the cancelling would answer *"what happened to this order"* with
     * half of it.
     *
     * **Subject: `purchase_order`, in `SUBJECT_TYPES`, mirroring
     * `PURCHASE_ORDER_PLACED`.** The other two declarations were considered and
     * neither survives: `SUBJECT_RESOLVED_AT_WRITE` can never satisfy PC-11 (4c)
     * by that constant's own docblock, so an admitted event cannot sit there; and
     * `SUBJECTLESS` would pass (4c) but could never surface in L-7's History,
     * because E-5 binds `.view_record` to one `subject_id` — filing it `BUSINESS`
     * would then buy exactly nothing. The subject is fixed by the event type and
     * it is the order.
     *
     * **The cancellation's written reason lands in `audit_log.metadata`, and this
     * is recorded here so the operation's author inherits it rather than deciding
     * it.** The precedent is `STOCK_MOVEMENT_ADJUSTED` above: `purchase_order` has
     * no free-text column for a reason, `status` is a five-value `CHECK` of which
     * exactly one applies, and `metadata` is `jsonb` on an append-only table — so
     * an operator's words live there and surface on `/purchase-orders/[id]`
     * through L-7. **Nothing writes this event today** and this is a catalogue
     * entry only: `CancelPurchaseOrder` does not exist, `PurchaseOrder` has no
     * `cancel()` method, and X9-1 with §8.3's reachability clause are what find
     * that. **Do not read this paragraph as a claim that the metadata write is
     * built.**
     *
     * **Name assigned here**, on `PURCHASE_ORDER_PLACED`'s `<table>.<verb>`
     * convention and on the entity's own `STATUS_CANCELLED` value, so the event
     * name and the status it records read the same.
     */
    public const PURCHASE_ORDER_CANCELLED = 'purchase_order.cancelled';

    /**
     * **The first `sales_order.*` event this catalogue has ever held** — minted
     * 2026-09-19 with `CancelSalesOrder`, under owner ruling **OI-2** (#2 R1a),
     * which names it among the four events the ruling owes.
     *
     * **Name and filing were ruled before any code existed**, which is steps 1–2
     * of the process above and is `SEC`'s, not `BE-LEAD`'s: `SECURITY_MODEL.md`
     * files it *"sales_order.cancelled — **business**. Subject the sales order. It
     * matches purchase_order.cancelled, which is already on the business side."*
     * `BE-LEAD` did steps 3–5 in that order. **Step 6 does not apply**: it is a
     * business event, so it stays out of `audit_log_ip_address_scope` and out of
     * `AuditWriterTest::PINNED_IP_BEARING_EVENTS`, and no re-issue of that
     * constraint was written.
     *
     * =======================================================================
     * Why this one was BUILT and `sales_order.confirmed` was DEFERRED
     * =======================================================================
     * The two are one commit apart and got opposite answers, so the ground is
     * recorded here rather than left to look like inconsistency.
     *
     * **ADR-008 §2 names `audit_log` as the compensating control for this exact
     * transition.** Declining the sales duty split, it says a rep who cancels
     * confirmed orders can conceal lost sales *"and the compensating control is
     * `audit_log`, not a second pair of hands."* A cancellation that wrote no row
     * would ship a knowingly-weaker control with its named compensation absent.
     * Nothing in ADR-008 says that of *confirming* — on its own reasoning
     * confirming *"moves nothing out"*.
     *
     * Three further asymmetries, each independent: the **owner named this event**
     * and named no confirm event, so B-4a had to be widened to hold
     * `sales_order.confirmed` and did not have to be widened for this one; **`SEC`
     * has classified this one** and has classified no confirm event; and §4.2
     * accepts a **`reason`** here, where `ConfirmSalesOrder` accepts nothing and so
     * had no field to drop.
     *
     * **The cancellation's written reason lands in `audit_log.metadata`**, on
     * `PURCHASE_ORDER_CANCELLED`'s ruling above and `STOCK_MOVEMENT_ADJUSTED`'s
     * precedent behind it. Measured against the live cluster rather than assumed:
     * `sales_order` has **nineteen columns and not one free-text column**, exactly
     * as `purchase_order` has none, so `metadata` is not a preference but the only
     * destination that exists.
     *
     * **Name assigned on `PURCHASE_ORDER_CANCELLED`'s `<table>.<verb>` convention**
     * and on the entity's own `STATUS_CANCELLED` value, so the event name and the
     * status it records read the same. Unlike that constant, this one **is written**
     * — `SalesOrderAuditWriter::recordCancelled()` — and `SalesOrderCancellationTest`
     * reads the row back cell by cell.
     */
    public const SALES_ORDER_CANCELLED = 'sales_order.cancelled';

    /**
     * **The first `invoice.*` event this catalogue holds** — minted 2026-09-28 with `IssueInvoice` (sales step 6,
     * C3), under owner ruling **Q5** of that step (*"Column + audit event"*, 2026-09-23): the issuer is recorded twice,
     * as `invoice.issued_by_user_id` (NOT NULL, C2) and as this row's actor.
     *
     * **Name and filing were ruled before any code existed** (steps 1–2, `SEC`'s): `output9-sales-7-sec.md` §5.1 files
     * it **business**, subject the invoice, and rules its `metadata` **`{}`** — the number, customer and fulfilment are
     * PC-15 display data (condition 6 forbids copying them into `metadata`), `fulfilment_number` would reach `finance`,
     * who lacks `fulfilment.view`, and a copy of `total_amount` adds no detection (SQL able to forge the invoice can
     * forge this row too — G-10's premise). `BE-LEAD` did steps 3–5; **step 6 does not apply** (a business event: no
     * IP, no entry in `audit_log_ip_address_scope`, none in `AuditWriterTest::PINNED_IP_BEARING_EVENTS`).
     *
     * Written by `InvoiceAuditWriter::recordIssued()` in the issuing transaction; `InvoiceIssueTest` reads the row
     * back cell by cell, `metadata` included.
     */
    public const INVOICE_ISSUED = 'invoice.issued';

    /**
     * **The payment, recorded** — minted 2026-09-28 with `PayInvoice` (sales step 6, C5). OI-2 (owner ruling #2,
     * 2026-09-14) named it: *"no correction ships without its audit event"*, and this is the act
     * `invoice.payment_reversed` (C6) undoes.
     *
     * **Filed by `SEC` before the code** (`output9-sales-7-sec.md` §5.1): **business**, subject the invoice, actor the
     * payer (= `invoice.paid_by_user_id`), and `metadata` **`{"paid_at": <the value written>}`** — the invoice's own
     * column, because a reversal nulls `paid_at` and a back-dated payment later reversed would otherwise leave no record
     * of the date it claimed; the row's `occurred_at` is when the payment was *recorded*, and the pair is what makes
     * back-dating visible. Nothing else is copied (PC-15 condition 6). **Step 6 does not apply** (a business event: no
     * IP). Written by `InvoiceAuditWriter::recordPaid()` in the paying transaction; `InvoicePaymentTest` reads the row
     * back cell by cell.
     */
    public const INVOICE_PAID = 'invoice.paid';

    /**
     * **A recorded payment, undone** — minted 2026-09-28 with `ReverseInvoicePayment` (sales step 6, C6): `paid →
     * issued`, under `invoice.pay`, by someone other than the recorder unless an owner (ADR-008 A2.3; the owner is the
     * owner-marker holder, `OwnerPredicate`). OI-2 named it with the other corrections.
     *
     * **Filed by `SEC` before the code** (`output9-sales-7-sec.md` §5.1): **business**, subject the invoice, actor the
     * reverser, `metadata` **`{"reason": <string>}`** — required, so always present (`{}` is not a legal shape); it does
     * NOT copy the reversed recorder's id or `paid_at`, which the `invoice.paid` row it undoes already holds. **Step 6
     * does not apply** (business: no IP). Written by `InvoiceAuditWriter::recordPaymentReversed()` in the reversing
     * transaction; `InvoicePaymentReversalTest` reads it back cell by cell.
     */
    public const INVOICE_PAYMENT_REVERSED = 'invoice.payment_reversed';

    /**
     * **An issued invoice, withdrawn** — minted 2026-09-29 with `VoidInvoice` (sales step 6, C7): `issued`/`overdue` →
     * `void`, under `invoice.void` (held by `owner` alone among the default roles since ADR-008 A2.1a); an invoice whose
     * payment was ever reversed only by an owner (B′). OI-2 named it with the other corrections.
     *
     * **Filed by `SEC` before the code** (`output9-sales-7-sec.md` §5.1): **business**, subject the invoice, actor the
     * voider, `metadata` **`{"reason": <string>}`** — required, so always present (`{}` is not a legal shape). **Step 6
     * does not apply** (business: no IP). Written by `InvoiceAuditWriter::recordVoided()` in the voiding transaction;
     * `InvoiceVoidTest` reads it back cell by cell.
     */
    public const INVOICE_VOIDED = 'invoice.voided';

    // ---------------------------------------------------------------------
    // B-4a (roles slice 2, C1, 2026-09-29): the five carries `DELIVERY_LEDGER.md` B-4a reconciled on 2026-09-22,
    // each minted WITH its writer in shipped code. Filed by `SEC` before the code (`docs/session13/roles-s2-sec.md`
    // §3): all five **business**, `metadata` `{}`, no IP, `occurred_at = now()` (the business-writer precedent;
    // the `clock_timestamp()` rule is for role events; either stamp may be raised by the audit-order trigger,
    // `Version20261022004000`). **Step 6 does not apply to any of them.**
    // ---------------------------------------------------------------------

    /**
     * **A delivery recorded** — `CreateGoodsReceipt` (F-3 step 5). Filed business by `SEC` long before the code
     * (`SECURITY_MODEL.md` §4.1: *"business, under B-4a. Subject the receipt."*), confirmed in `roles-s2-sec.md` §3.
     * Actor the receiver (= `goods_receipt.received_by_user_id`, API-O-33). Written by
     * `GoodsReceiptAuditWriter::recordCreated()` on the **insert** branch only: a key replay hands back the existing
     * receipt and writes no second row.
     */
    public const GOODS_RECEIPT_CREATED = 'goods_receipt.created';

    /**
     * **A sales order confirmed** — `ConfirmSalesOrder` (F-5 step 3), the customer commitment. Deferred to B-4a by
     * V2-R-22's O-C on 2026-09-19 and minted here. The twin of `sales_order.cancelled` and `purchase_order.placed`: the
     * order's own state transition, so business under PC-11 (4a). Actor the confirmer — which
     * `sales_order.created_by_user_id` never was (SEC-O-9). Written by `SalesOrderAuditWriter::recordConfirmed()` in
     * the confirming transaction.
     */
    public const SALES_ORDER_CONFIRMED = 'sales_order.confirmed';

    /**
     * **A shipment recorded** — `CreateFulfilment` (F-5 step 5). Business: the shipment document's creation, and
     * `shipped_by` is already exposed under `fulfilment.view` (PC-11 (4a), owner ruling R6). With
     * `sales_order.confirmed` it makes SoD-4's comparison (*shipper ≠ confirmer*) answerable from the trail —
     * **evidence, not enforcement**. Written by `FulfilmentAuditWriter::recordCreated()` on the **insert** branch only.
     */
    public const FULFILMENT_CREATED = 'fulfilment.created';

    /**
     * **A draft sales order discarded** — `DeleteSalesOrder` (an archive: `deleted_at` set). `RESIDUAL_GAPS.md` G-9's
     * event, minted **together with its purchase twin** as G-9's fallback requires. Business: archiving a draft is a
     * lifecycle state of the order, exactly where cancellation sits (`roles-s2-sec.md` §3.2). No reason field, so
     * `{}`. Written by `SalesOrderAuditWriter::recordDeleted()` in the archiving transaction.
     */
    public const SALES_ORDER_DELETED = 'sales_order.deleted';

    /**
     * **A draft purchase order discarded** — `DeletePurchaseOrder`. `SALES_ORDER_DELETED`'s twin, on its ground.
     * Written by `PurchaseOrderAuditWriter::recordDeleted()` in the archiving transaction.
     *
     * The matching `*.restored` events are **filed** (`SEC`: business, subject the order, `{}`) and both are minted:
     * `sales_order.restored` at order restores C2 and `purchase_order.restored` at C3 (2026-10-21, below). G-8 is closed.
     */
    public const PURCHASE_ORDER_DELETED = 'purchase_order.deleted';

    /**
     * **A discarded draft sales order brought back** — `RestoreSalesOrder` (order restores C2, 2026-10-21;
     * `docs/session13/order-restores-design.md` §5.2). `SALES_ORDER_DELETED`'s inverse, on its ground: **business** under
     * PC-11 (4a), subject `('sales_order', id)`, no IP, no permission code, `metadata` `{}` — a restore changes only
     * `deleted_at`, which the row shows as `archived_at` (`SEC`'s filing, `SECURITY_MODEL.md` §4.1;
     * `docs/session13/roles-s2-sec.md` §3). Written by `SalesOrderAuditWriter::recordRestored()` in the restoring
     * transaction, after the UPDATE; none on a refused restore (not archived, an archived customer or warehouse, or a
     * live order or line now holding a retry key). **Step 6 does not apply.**
     */
    public const SALES_ORDER_RESTORED = 'sales_order.restored';

    /**
     * **A discarded draft purchase order brought back** — `RestorePurchaseOrder` (order restores C3, 2026-10-21).
     * `SALES_ORDER_RESTORED`'s twin, on its ground: **business**, subject `('purchase_order', id)`, no IP, `metadata` `{}`
     * (`SEC`'s filing, `SECURITY_MODEL.md` §4.1). Written by `PurchaseOrderAuditWriter::recordRestored()` in the restoring
     * transaction, after the UPDATE and the total's recompute; none on a refused restore. **Step 6 does not apply.**
     */
    public const PURCHASE_ORDER_RESTORED = 'purchase_order.restored';

    /**
     * **Goods a customer sent back, recorded** — minted 2026-10-01 with `CreateSalesReturn` (sales step 7, R2): a
     * `sales_return` and its lines, stock raised through `ReturnStock` (W-3's third edge), under `sales_return.create`.
     *
     * **Filed by `SEC` before the code** (`output9-sales-8-sec.md` S-5, and final position item 5): **business**, subject
     * the return, actor the recorder (= `sales_return.recorded_by_user_id`, PC-11 (4a)), no IP, `metadata`
     * **`{"reason": <enum value>, "deducts": <boolean>}`** — `deducts` marks in the trail a return that lowered an
     * invoice with no credit note (owner ruling Q-L (a′)); it does not single out a fake one (G-24). **Step 6 does not
     * apply.** Written by `SalesReturnAuditWriter::recordCreated()` on the **insert** branch only.
     */
    public const SALES_RETURN_CREATED = 'sales_return.created';

    /**
     * **A credit note issued against an invoice** — minted 2026-10-01 with `IssueCreditNote` (sales step 7, C1): a
     * `credit_note` and its lines, the only reduction of an issued invoice (OI-2), under `credit_note.issue`.
     *
     * **Filed by `SEC` before the code** (`output9-sales-8-sec.md` S-5): **business**, subject the credit note, actor the
     * issuer (= `credit_note.issued_by_user_id`, PC-11 (4a)), no IP, no permission code, `metadata`
     * **`{"reason": <enum value>}`** — the trail says why without opening the document (R-7 and B′'s delegated
     * adjustments are controlled only by the trail). One document type, one event: `IssueAdjustmentCreditNote` (C1b)
     * writes it too. **Step 6 does not apply.** Written by `CreditNoteAuditWriter::recordIssued()` on the **insert**
     * branch only.
     */
    public const CREDIT_NOTE_ISSUED = 'credit_note.issued';

    /**
     * **A decision recorded about which returned units an adjustment already credited** — minted 2026-10-05 with
     * `CreateAdjustmentDecision` (G-29 / G-30 part 3, C1, `docs/session13/g29-g30-identity-design.md` §3): one row per
     * `adjustment_decision` row written — a `same`, a `distinct`, a reversal, each row a reversal cascades to, each record
     * row it writes, and (C2) each `distinct` row the adjust path writes for a named pending return.
     *
     * **Filed by `SEC` before the code** (`g29-g30-sec.md` S-4, F-S1): **business**, admitted to `.view_record` under
     * PC-11 (4a). **The subject is the CREDIT NOTE of the row's own adjustment line, never the return** — and that is why
     * the name is `credit_note.`-prefixed (`SEC`'s recommendation; the `role.*` events are the house precedent for a name
     * chosen to match its subject). With the return as subject, `ListAuditRecords` would tell a `warehouse_operator`
     * (`sales_return.view`, no `invoice.view`) that the return was matched to a credit without a return, which PC-9's
     * third exception forbids. The History of a credit note is gated by PC-9's mapping (`credit_note` read →
     * `invoice.view`), never by a `credit_note.view` code (none exists; R-7). A cascaded reversal row names ITS OWN
     * adjustment's note, which may differ from the reversed row's (R-1). Actor the caller (= `recorded_by_user_id`), **no
     * IP**, no permission code, ids-only `metadata` **`{"kind", "adjustment_decision_id", "sales_return_line_id",
     * "credit_note_line_id", "quantity"?, "reverses_decision_id"?}`** — `quantity` only for `same`, at scale 6;
     * `reverses_decision_id` only for a reversal. **Step 6 does not apply.** Written by
     * `CreditNoteAuditWriter::recordReturnDecided()` on the **insert** branch only.
     */
    public const CREDIT_NOTE_RETURN_DECIDED = 'credit_note.return_decided';

    /**
     * **A customer created** — `CreateCustomer` (customers slice 1 C2, 2026-10-09; `RESIDUAL_GAPS.md` G-35). Subject
     * `('customer', id)`. **Business** under PC-11 (4a): about the customer row's own state, and (4c) does not reach it
     * (subject `customer`) — the delegated `SEC`-role filing in `docs/session13/customers-s1-g35-judge.md` C-4 (`SEC` to
     * confirm). No IP, no permission code, `metadata` `{}`. Load-bearing, not decoration: `CreateCustomer` accepts
     * `payment_terms_days`, so a customer can be opened at the maximum terms without any update (judge C-6). Written by
     * `CustomerAuditWriter::recordCreated()` in `CustomerWriter`'s transaction, after the INSERT. **Step 6 does not
     * apply.**
     */
    public const CUSTOMER_CREATED = 'customer.created';

    /**
     * **A customer changed** — `UpdateCustomer` (customers slice 1 C2, 2026-10-09; G-35). Subject `('customer', id)`.
     * **Business** under PC-11 (4a), the same delegated filing (`SEC` to confirm). No IP, no permission code, `metadata`
     * `{"fields": [...]}` — the wire names of the changed fields among `code`, `name`, `country_code`, `currency_code`,
     * `payment_terms_days`, sorted, **never values**; **no row when nothing changed**. The diff is taken under a row lock
     * (`FOR NO KEY UPDATE` on `CustomerWriter`'s first read), so a concurrent change cannot go unattributed (judge C-3).
     * Written by `CustomerAuditWriter::recordUpdated()` in `CustomerWriter`'s transaction, after the UPDATE. **Step 6
     * does not apply.**
     */
    public const CUSTOMER_UPDATED = 'customer.updated';

    /**
     * **An archived customer restored** — `RestoreCustomer` (C5 C4a, 2026-10-14; `docs/session13/c5-design.md` §6.1;
     * filed by the builder on `SEC`'s supplier-pair terms, `SEC` to confirm). Subject `('customer', id)`. **Business**
     * under PC-11 (4a). No IP, no permission code, `metadata` `{}`. Written by `CustomerAuditWriter::recordRestored()` in
     * `CustomerWriter::restore()`'s transaction, after the UPDATE; a refused restore (not archived, or a live customer
     * took its code) writes none. **Step 6 does not apply.**
     */
    public const CUSTOMER_RESTORED = 'customer.restored';

    /**
     * **A customer archived** — `DeleteCustomer` (C5 C4b, 2026-10-14; `docs/session13/c5-design.md` §3.4, §6.1; filed by
     * the builder with `customer.restored` on `SEC`'s supplier-pair terms, `SEC` to confirm). Subject `('customer', id)`.
     * **Business** under PC-11 (4a). No IP, no permission code, `metadata` `{}` (G-9's closure shape). Written by
     * `CustomerAuditWriter::recordDeleted()` in `CustomerWriter::delete()`'s transaction, after the UPDATE; a refused
     * delete (already archived, or open work — ruling Q-1 (b′)) writes none. **Step 6 does not apply.**
     */
    public const CUSTOMER_DELETED = 'customer.deleted';

    /**
     * **A supplier created** — `CreateSupplier` (suppliers slice 1 C2, 2026-10-12; owner Q-S5). Subject
     * `('supplier', id)`. **Business** under PC-11 (4a): about the supplier row's own state; (4c) does not reach it
     * (subject `supplier`). No IP, no permission code, `metadata` `{}`. `customer.created`'s twin: `CreateSupplier`
     * accepts `payment_terms_days`, so a supplier can be opened at any terms without an update. Written by
     * `SupplierAuditWriter::recordCreated()` in `SupplierWriter`'s transaction, after the INSERT. **Step 6 does not
     * apply.**
     */
    public const SUPPLIER_CREATED = 'supplier.created';

    /**
     * **A supplier changed** — `UpdateSupplier` (suppliers slice 1 C2, 2026-10-12; owner Q-S5). Subject
     * `('supplier', id)`. **Business** under PC-11 (4a). No IP, no permission code, `metadata` `{"fields": [...]}` — the
     * wire names of the changed fields among `code`, `name`, `country_code`, `currency_code`, `payment_terms_days`,
     * sorted, **never values**; **no row when nothing changed**. The diff is taken under a row lock (`FOR NO KEY UPDATE`
     * on `SupplierWriter`'s first read). Written by `SupplierAuditWriter::recordUpdated()` in `SupplierWriter`'s
     * transaction, after the UPDATE. **Step 6 does not apply.**
     */
    public const SUPPLIER_UPDATED = 'supplier.updated';

    /**
     * **An archived supplier restored** — `RestoreSupplier` (C5 C2a, 2026-10-13; `docs/session13/c5-design.md` §6.1; filed
     * by `SEC` with `supplier.deleted`, `SECURITY_MODEL.md` supplier block). Subject `('supplier', id)`. **Business** under
     * PC-11 (4a). No IP, no permission code, `metadata` `{}`. Written by `SupplierAuditWriter::recordRestored()` in
     * `SupplierWriter::restore()`'s transaction, after the UPDATE; a refused restore (not archived, or a live supplier took
     * its code or name) writes none. **Step 6 does not apply.**
     */
    public const SUPPLIER_RESTORED = 'supplier.restored';

    /**
     * **A supplier archived** — `DeleteSupplier` (C5 C2b, 2026-10-14; `docs/session13/c5-design.md` §2.4, §6.1; filed by
     * `SEC` with `supplier.restored`, `SECURITY_MODEL.md` supplier block). Subject `('supplier', id)`. **Business** under
     * PC-11 (4a). No IP, no permission code, `metadata` `{}` (G-9's closure shape). Written by
     * `SupplierAuditWriter::recordDeleted()` in `SupplierWriter::delete()`'s transaction, after the UPDATE; a refused delete
     * (already archived, or open purchase orders) writes none. **Step 6 does not apply.**
     */
    public const SUPPLIER_DELETED = 'supplier.deleted';

    /**
     * **An item added to a supplier's catalogue** — `CreateSupplierProduct` (G3-C0, 2026-10-22; `RESIDUAL_GAPS.md` G-41's
     * closing work). Subject `('supplier_product', id)` — the catalogue entry, never the supplier (design D-C0-1: a
     * supplier-subject row would show catalogue changes in the supplier's History to a `supplier.view` holder who lacks
     * `supplier_product.view`, a PC-9 widening; History is read under `supplier_product.view`). **Business** under PC-11 (4a):
     * about the entry row's own state. No IP, no permission code. `metadata` `{"supplier_id", "inventory_item_id"}` — bare ids,
     * allowed (`API.md` §4.4.3), never a value. **A named deviation from `customer_contact.created`'s `{}`** (RED L-6): the
     * entry is HARD-deleted, so once it is gone these ids are what makes its chain findable through `ListAuditLogEntries`.
     * Written by `SupplierAuditWriter::recordCatalogueCreated()` in `SupplierProductWriter::create()`'s transaction, after the
     * INSERT; a refused create (the duplicate 409, an archived supplier, a malformed body, a 404) writes none. **Step 6 does
     * not apply.** `SEC` to confirm.
     */
    public const SUPPLIER_PRODUCT_CREATED = 'supplier_product.created';

    /**
     * **A catalogue entry's terms changed** — `UpdateSupplierProduct` (G3-C0, 2026-10-22; G-41). Subject
     * `('supplier_product', id)`. **Business** under PC-11 (4a). No IP, no permission code, `metadata` `{"fields": [...]}` —
     * the wire names of the changed fields among `unit_cost`, `lead_time_days`, `minimum_order_quantity`, `supplier_sku`,
     * sorted, **never values: `unit_cost` is a money amount, and `AuditLogEntry.metadata` never carries one (PC-12)**; lead
     * time and MOQ follow `supplier.updated`'s names-only shape. **No row when nothing changed**: the diff is taken under the
     * entry's row lock (`FOR NO KEY UPDATE`, after the parent's `FOR KEY SHARE`) and compares numerically at column scale.
     * Written by `SupplierAuditWriter::recordCatalogueUpdated()` in `SupplierProductWriter::update()`'s transaction, after the
     * UPDATE. **Step 6 does not apply.** `SEC` to confirm.
     */
    public const SUPPLIER_PRODUCT_UPDATED = 'supplier_product.updated';

    /**
     * **An item removed from a supplier's catalogue** — `DeleteSupplierProduct` (G3-C0, 2026-10-22; G-41). Subject
     * `('supplier_product', id)` — the row that is gone; its History stays readable by id (G-52's precedent). **Business**
     * under PC-11 (4a). No IP, no permission code, `metadata` `{"supplier_id", "inventory_item_id"}` (as `.created`, for the
     * same reason). Written by `SupplierAuditWriter::recordCatalogueDeleted()` after a one-row DELETE, in its transaction;
     * none on a 0-row DELETE (404) or a refusal. **Step 6 does not apply.** `SEC` to confirm.
     */
    public const SUPPLIER_PRODUCT_DELETED = 'supplier_product.deleted';

    /**
     * **An item archived** — `DeleteItem` (G5-C2a, 2026-10-22; `docs/session13/g5-g6-bulk-export-design.md` §5.5). Subject
     * `('inventory_item', id)`. **Business** under PC-11 (4a): about the item row's own state; its History is read under
     * `inventory_item.view` (`RecordHistorySubjects`). No IP, no permission code, `metadata` `{}`: an archive changes only
     * `deleted_at`, which the row shows as `archived_at` (`customer.deleted`'s shape). Written by
     * `ItemAuditWriter::recordDeleted()` in `ItemWriter::archive()`'s transaction, after the one-row UPDATE; none on a
     * refused delete (already archived — including the second of two concurrent deletes — or an unknown id). **Step 6 does
     * not apply.** `SEC` to confirm.
     */
    public const INVENTORY_ITEM_DELETED = 'inventory_item.deleted';

    /**
     * **An archived item restored** — `RestoreItem` (G5-C2a, 2026-10-22). Subject `('inventory_item', id)`. **Business**
     * under PC-11 (4a). No IP, no permission code, `metadata` `{}`. Written by `ItemAuditWriter::recordRestored()` in
     * `ItemWriter::restore()`'s transaction, after the one-row UPDATE; none on a refused restore (not archived — including
     * the second of two concurrent restores — or a live item took its SKU, whose 23505 rolls the transaction back).
     * **Step 6 does not apply.** `SEC` to confirm.
     */
    public const INVENTORY_ITEM_RESTORED = 'inventory_item.restored';

    /**
     * **The workspace's display name changed** — `UpdateSettings` (G7b, 2026-10-22; owner ruling RO-S1). Subject
     * `('tenant_settings', id)`, the settings row whose own state changed. **Business** under PC-11 (4a) (`SEC` to confirm):
     * its History is read under `tenant_settings.view`, and no screen can ask for it (the singleton serves no id; final RED
     * F-M4). No IP, no permission code. `metadata` `{"fields": ["display_name"], "to": <the stored name>}` — the NEW value
     * only, no `from` (design M-5): the chain is reconstructed from the value the migration backfilled from `tenant.name`,
     * which RO-S1 never rewrites. A workspace name is not personal data and not money. Written by
     * `SettingsAuditWriter::recordUpdated()` in `SettingsWriter::rename()`'s transaction, after the one-row UPDATE and under
     * the settings row lock, so two concurrent renames write two rows in commit order; none on a rename that changes nothing
     * or is refused. **Step 6 does not apply.**
     */
    public const TENANT_SETTINGS_UPDATED = 'tenant_settings.updated';

    /**
     * **A customer address added** — `CreateCustomerAddress` (suppliers slice 1 C2b, 2026-10-12; owner ruling F-12 / S4,
     * `docs/session13/suppliers-s1-ruling.md`). Subject `('customer_address', id)` — the row whose own state changed; the
     * customer is derivable from it while the row lives; once `DeleteCustomerAddress` removes it, the
     * `customer_address.deleted` row's `customer_id` carries the link (`SEC` confirmed the subject 2026-10-19, customers
     * slice 2 S-4). *(Replaced 2026-10-19, customers slice 2 C3: this parenthesis read "the customer is derivable from it,
     * not the reverse (`SEC` to confirm; `owed-roles-recs.md` S4 leaned `customer`)".)*
     * **Business** under PC-11 (4a). No IP, no permission code, `metadata` `{}` — an address is personal data, so no
     * value is ever carried. Written by `CustomerAddressAuditWriter::recordCreated()` in `CustomerAddressWriter`'s
     * transaction, after the INSERT; a replayed create writes none. **Step 6 does not apply.**
     */
    public const CUSTOMER_ADDRESS_CREATED = 'customer_address.created';

    /**
     * **A customer address changed** — `UpdateCustomerAddress` (suppliers slice 1 C2b, 2026-10-12; owner ruling F-12 /
     * S4). Subject `('customer_address', id)`. **Business** under PC-11 (4a). No IP, no permission code, `metadata`
     * `{"fields": [...]}` — the wire names of the changed fields among `kind`, `line`, `city`, `postal_code`,
     * `country_code`, sorted, **never values**; **no row when nothing changed**. The diff is taken under a row lock
     * (`FOR NO KEY UPDATE` on the address). Written by `CustomerAddressAuditWriter::recordUpdated()` in
     * `CustomerAddressWriter`'s transaction, after the UPDATE. **Step 6 does not apply.**
     */
    public const CUSTOMER_ADDRESS_UPDATED = 'customer_address.updated';

    /**
     * **A customer address removed** — `DeleteCustomerAddress` (customers slice 2 C3, 2026-10-19; inside owner ruling F-12 /
     * S4 "Record address changes", `SEC` `customers-s2-sec-arch.md` S-4). Subject `('customer_address', id)` — the row that
     * is gone. **Business** under PC-11 (4a). No IP, no permission code, `metadata` `{"customer_id": "<uuid>"}`: once the
     * row is deleted every earlier `customer_address.*` row names an id that joins to nothing, so this row carries the link.
     * The abuse it detects: a `customer.edit` seat deletes the legitimate shipping address so a colleague picks the
     * remaining (attacker-edited) one. Written by `CustomerAddressAuditWriter::recordDeleted()` after a one-row DELETE;
     * none on a 0-row DELETE or a refusal. **Step 6 does not apply.**
     */
    public const CUSTOMER_ADDRESS_DELETED = 'customer_address.deleted';

    /**
     * **A customer contact added** — `CreateCustomerContact` (customers slice 2 C1, 2026-10-19; owner Q-2 "yes",
     * `docs/session13/s35-ruling.md`; filed by `SEC`, `customers-s2-sec-arch.md` S-4). Subject `('customer_contact', id)` —
     * the row whose own state changed. **Business** under PC-11 (4a). No IP, no permission code, `metadata` `{}` — a
     * contact is personal data, so no value is ever carried. Written by `CustomerContactAuditWriter::recordCreated()` in
     * `CustomerContactWriter`'s transaction, after the INSERT (and, when the request asked for primary, after the
     * set-primary UPDATE); a replayed create or a lost race writes none. **Step 6 does not apply.**
     */
    public const CUSTOMER_CONTACT_CREATED = 'customer_contact.created';

    /**
     * **A customer contact changed** — `UpdateCustomerContact`, and every contact DEMOTED because another was marked
     * primary (`CreateCustomerContact` or `UpdateCustomerContact`; owner Q-1 (a)) (customers slice 2 C1, 2026-10-19).
     * Subject `('customer_contact', id)`. **Business** under PC-11 (4a). No IP, no permission code, `metadata`
     * `{"fields": [...]}` — the wire names of the changed fields among `name`, `email`, `phone`, `is_primary`, sorted,
     * **never values**; a demoted sibling's row names `["is_primary"]`; **no row when nothing changed**. Written by
     * `CustomerContactAuditWriter::recordUpdated()` in `CustomerContactWriter`'s transaction, after the statement.
     * **Step 6 does not apply.**
     */
    public const CUSTOMER_CONTACT_UPDATED = 'customer_contact.updated';

    /**
     * **A customer contact removed** — `DeleteCustomerContact` (customers slice 2 C2, 2026-10-19; owner Q-2; filed by `SEC`,
     * `customers-s2-sec-arch.md` S-4). Subject `('customer_contact', id)` — the row that is gone. **Business** under PC-11
     * (4a). No IP, no permission code, `metadata` `{"customer_id": "<uuid>"}`: once the row is deleted, every earlier
     * `customer_contact.*` row names an id that joins to nothing, so this row carries the link to the customer (a uuid, not
     * personal data). Written by `CustomerContactAuditWriter::recordDeleted()` after a one-row DELETE, in its transaction;
     * none on a 0-row DELETE or a refusal. **Step 6 does not apply.**
     */
    public const CUSTOMER_CONTACT_DELETED = 'customer_contact.deleted';

    /**
     * **A warehouse created** — `CreateWarehouse` (warehouses slice 1 C2, 2026-10-13; owner Q-W2 (a)). Subject
     * `('warehouse', id)`. **Business** under PC-11 (4a): about the warehouse row's own state; (4c) does not reach it. No
     * IP, no permission code. `metadata` `{"values": {"code", "country_code", "name"}}` — the values as stored, the anchor
     * the first `warehouse.updated`'s `from` is checked against (an out-of-band SQL edit shows as a break in the chain).
     * Operational fields, not personal data; the allowlist is `WarehouseAuditWriter::VALUE_FIELDS`. Written by
     * `WarehouseAuditWriter::recordCreated()` in `WarehouseWriter`'s transaction, after the INSERT. **Step 6 does not
     * apply.**
     */
    public const WAREHOUSE_CREATED = 'warehouse.created';

    /**
     * **A warehouse changed** — `UpdateWarehouse` (warehouses slice 1 C2, 2026-10-13; owner Q-W2 (a)). Subject
     * `('warehouse', id)`. **Business** under PC-11 (4a). No IP, no permission code. `metadata` `{"fields": [...], "from":
     * {...}, "to": {...}}` — the wire names of the changed fields among `code`, `name`, `country_code`, sorted, and their
     * old and new values (**values, unlike `customer.*` / `supplier.*`**: a rename relabels issued documents,
     * `RESIDUAL_GAPS.md` G-43, and the old label survives here); **no row when nothing changed**. The diff is taken under a
     * row lock (`FOR NO KEY UPDATE` on `WarehouseWriter`'s first read). Written by `WarehouseAuditWriter::recordUpdated()`
     * in `WarehouseWriter`'s transaction, after the UPDATE. **Step 6 does not apply.**
     */
    public const WAREHOUSE_UPDATED = 'warehouse.updated';

    /**
     * **A warehouse restored** — `RestoreWarehouse` (warehouse/location delete-restore C1, 2026-10-20; `SEC`
     * `wh-loc-delete-roles.md` S-1). Subject `('warehouse', id)`. **Business** under PC-11 (4a). No IP, no permission code,
     * `metadata` `{}`: a restore changes only `deleted_at`, which the row itself shows as `archived_at`, so the values chain
     * of `.created` / `.updated` is not extended. Written by `WarehouseAuditWriter::recordRestored()` in `WarehouseWriter`'s
     * transaction, after the UPDATE; none on a refused restore (not archived, or a live warehouse took its code).
     * **Step 6 does not apply.**
     */
    public const WAREHOUSE_RESTORED = 'warehouse.restored';

    /**
     * **A warehouse archived** — `DeleteWarehouse` (warehouse/location delete-restore C4, 2026-10-20; `SEC`
     * `wh-loc-delete-roles.md` S-1; API-O-34). Subject `('warehouse', id)`. **Business** under PC-11 (4a). No IP, no
     * permission code, `metadata` `{}`: an archive changes only `deleted_at`, which the row shows as `archived_at`; its
     * locations are left as they are (owner Q-2 (a)). Written by `WarehouseAuditWriter::recordDeleted()` in
     * `WarehouseWriter`'s transaction, after the UPDATE; none on a refused delete (already archived, open purchase or sales
     * orders, or stock in any of its locations, live or archived). **Step 6 does not apply.**
     */
    public const WAREHOUSE_DELETED = 'warehouse.deleted';

    /**
     * **A storage location created** — `CreateStorageLocation` (warehouses slice 1 C3, 2026-10-13; owner Q-W2 (a)).
     * Subject `('storage_location', id)`. **Business** under PC-11 (4a). No IP, no permission code. `metadata`
     * `{"values": {"code", "kind"}}` — the values as stored (the kind the database defaulted, when none was sent), the
     * anchor the first `storage_location.updated`'s `from` is checked against. Operational fields; the allowlist is
     * `StorageLocationAuditWriter::VALUE_FIELDS`. Written by `StorageLocationAuditWriter::recordCreated()` in
     * `StorageLocationWriter`'s transaction, after the INSERT. **Step 6 does not apply.**
     */
    public const STORAGE_LOCATION_CREATED = 'storage_location.created';

    /**
     * **A storage location restored** — `RestoreStorageLocation` (warehouse/location delete-restore C2, 2026-10-20; `SEC`
     * `wh-loc-delete-roles.md` S-1). Subject `('storage_location', id)`. **Business** under PC-11 (4a). No IP, no permission
     * code, `metadata` `{}`: a restore changes only `deleted_at`, which the row shows as `archived_at`; `warehouse_id` is
     * frozen, so no parent id is needed. Written by `StorageLocationAuditWriter::recordRestored()` in `StorageLocationWriter`'s
     * transaction, after the UPDATE; none on a refused restore (its warehouse archived, not archived, or a live location
     * of the same warehouse took its code). **Step 6 does not apply.**
     */
    public const STORAGE_LOCATION_RESTORED = 'storage_location.restored';

    /**
     * **A storage location archived** — `DeleteStorageLocation` (warehouse/location delete-restore C3, 2026-10-20; `SEC`
     * `wh-loc-delete-roles.md` S-1). Subject `('storage_location', id)`. **Business** under PC-11 (4a). No IP, no permission
     * code, `metadata` `{}`: an archive changes only `deleted_at`, which the row shows as `archived_at`; no `held_stock` flag,
     * because the delete refuses a location holding stock (owner Q-1), so it would always be false. Written by
     * `StorageLocationAuditWriter::recordDeleted()` in `StorageLocationWriter`'s transaction, after the UPDATE; none on a
     * refused delete (its warehouse archived, already archived, or holding stock). **Step 6 does not apply.**
     */
    public const STORAGE_LOCATION_DELETED = 'storage_location.deleted';

    /**
     * **A storage location changed** — `UpdateStorageLocation` (warehouses slice 1 C3, 2026-10-13; owner Q-W2 (a), Q-W1
     * (d)). Subject `('storage_location', id)`. **Business** under PC-11 (4a). No IP, no permission code. `metadata`
     * `{"fields": [...], "from": {...}, "to": {...}[, "held_stock": bool]}` — the changed fields among `code`, `kind`,
     * sorted, their old and new values, and **`held_stock` exactly when `kind` changed**: whether the location held stock
     * (`stock_level.qty_on_hand > 0`), read under the row lock in a statement after it (final RED M-1), so the trail tells a
     * hold or release of a stocked location from an empty relabel. A release of a stocked quarantine location is the
     * owner's alone (`storage_location.release_needs_owner`; `RESIDUAL_GAPS.md` G-44). **No row when nothing changed.**
     * Written by `StorageLocationAuditWriter::recordUpdated()` in `StorageLocationWriter`'s transaction, after the UPDATE.
     * **Step 6 does not apply.**
     */
    public const STORAGE_LOCATION_UPDATED = 'storage_location.updated';

    /**
     * Every legal value of `audit_log.event_type`.
     *
     * **Declared independently of `SECURITY` and `BUSINESS`, deliberately.**
     * Deriving it — `[...self::SECURITY, ...self::BUSINESS]` — would make
     * `everyEventIsClassifiedExactlyOnce` compare a list to itself and pass
     * unconditionally. That is the precise fake-green shape `SchemaManifest`
     * already records finding once: "a list here defines the *coverage* of the
     * exhaustive check, and nothing was checking the list."
     *
     * The redundancy is the check. An event added to `ALL` and left unclassified
     * fails; an event classified and left out of `ALL` fails; an event in either
     * and not in the migration fails against `pg_constraint`.
     *
     * @var list<string>
     */
    public const ALL = [
        self::AUTH_LOGIN_SUCCESS,
        self::AUTH_LOGIN_FAILURE,
        self::AUTH_LOGIN_TENANT_INACTIVE,
        self::AUTH_PASSWORD_CHANGED,
        self::AUTHZ_PERMISSION_DENIED,
        self::DATA_EXPORT_COMPLETED,
        self::PURCHASE_ORDER_PLACED,
        self::PURCHASE_ORDER_CANCELLED,
        self::SALES_ORDER_CANCELLED,
        self::INVOICE_ISSUED,
        self::INVOICE_PAID,
        self::INVOICE_PAYMENT_REVERSED,
        self::INVOICE_VOIDED,
        self::STOCK_MOVEMENT_ADJUSTED,
        self::ROLE_ASSIGNED,
        self::ROLE_REVOKED,
        self::ROLE_ASSIGNMENT_PROPOSED,
        self::ROLE_ASSIGNMENT_LAPSED,
        self::ROLE_ASSIGNMENT_REJECTED,
        self::ROLE_ASSIGNMENT_CANCELLED,
        self::ROLE_PERMISSIONS_CHANGED,
        self::ROLE_UPDATED,
        self::ROLE_DELETED,
        self::ROLE_RESTORED,
        self::GOODS_RECEIPT_CREATED,
        self::SALES_ORDER_CONFIRMED,
        self::FULFILMENT_CREATED,
        self::SALES_ORDER_DELETED,
        self::PURCHASE_ORDER_DELETED,
        self::SALES_RETURN_CREATED,
        self::CREDIT_NOTE_ISSUED,
        self::CREDIT_NOTE_RETURN_DECIDED,
        self::CUSTOMER_CREATED,
        self::CUSTOMER_UPDATED,
        self::SUPPLIER_CREATED,
        self::SUPPLIER_UPDATED,
        self::SUPPLIER_RESTORED,
        self::SUPPLIER_DELETED,
        self::SUPPLIER_PRODUCT_CREATED,
        self::SUPPLIER_PRODUCT_UPDATED,
        self::SUPPLIER_PRODUCT_DELETED,
        self::INVENTORY_ITEM_DELETED,
        self::INVENTORY_ITEM_RESTORED,
        self::TENANT_SETTINGS_UPDATED,
        self::CUSTOMER_RESTORED,
        self::CUSTOMER_DELETED,
        self::CUSTOMER_ADDRESS_CREATED,
        self::CUSTOMER_ADDRESS_UPDATED,
        self::CUSTOMER_ADDRESS_DELETED,
        self::CUSTOMER_CONTACT_CREATED,
        self::CUSTOMER_CONTACT_UPDATED,
        self::CUSTOMER_CONTACT_DELETED,
        self::WAREHOUSE_CREATED,
        self::WAREHOUSE_UPDATED,
        self::WAREHOUSE_RESTORED,
        self::WAREHOUSE_DELETED,
        self::SALES_ORDER_RESTORED,
        self::PURCHASE_ORDER_RESTORED,
        self::STORAGE_LOCATION_CREATED,
        self::STORAGE_LOCATION_UPDATED,
        self::STORAGE_LOCATION_RESTORED,
        self::STORAGE_LOCATION_DELETED,
        self::USER_DEACTIVATED,
        self::USER_REACTIVATED,
        self::USER_UPDATED,
    ];

    /**
     * Everything PC-11 (4a) does not admit to `.view_record`. Readable only
     * through `audit_log.view` — the whole tenant trail — never through the
     * History section of a detail page.
     *
     * **The criterion, quoted, because a filing has to be arguable against
     * something:** an event is admitted only if it is about the **subject row's
     * own business state**; an event about a principal's credentials, sessions
     * or **privileges** is excluded, *whatever its name begins with*. PC-11
     * labels that clause **judgement** rather than a check (Rule 14) — no test
     * decides it. What the tests decide is (4b), the pin on the other list's
     * membership, and (4c), the structural bar on an `app_user` subject.
     *
     * @var list<string>
     */
    public const SECURITY = [
        self::AUTH_LOGIN_SUCCESS,
        self::AUTH_LOGIN_FAILURE,
        self::AUTH_LOGIN_TENANT_INACTIVE,
        self::AUTH_PASSWORD_CHANGED,
        self::AUTHZ_PERMISSION_DENIED,
        self::DATA_EXPORT_COMPLETED,
        self::ROLE_ASSIGNED,
        self::ROLE_REVOKED,
        self::ROLE_ASSIGNMENT_PROPOSED,
        self::ROLE_ASSIGNMENT_LAPSED,
        self::ROLE_ASSIGNMENT_REJECTED,
        self::ROLE_ASSIGNMENT_CANCELLED,
        self::ROLE_PERMISSIONS_CHANGED,
        self::ROLE_UPDATED,
        self::ROLE_DELETED,
        self::ROLE_RESTORED,
        self::USER_DEACTIVATED,
        self::USER_REACTIVATED,
        self::USER_UPDATED,
    ];

    /**
     * **PC-11's published allowlist.** `audit_log.view_record` returns only rows
     * whose `event_type` is in this list, as an `IN` and never a `NOT LIKE`.
     *
     * It fails closed: an event type nobody has classified is not readable by a
     * colleague until somebody decides it is.
     *
     * **Adding a member here is a `SEC` decision, and the code says so rather
     * than asking.** Its membership is pinned independently by
     * `AuditEventCatalogueTest::PINNED_ALLOWLIST` (PC-11 (4b)) and its subjects
     * are barred from naming `app_user` by
     * `noAdmittedEventNamesAppUserAsItsSubject` ((4c)), so an addition that has
     * not been argued fails two tests and neither can be quieted from this file.
     * That is the point: (1)–(3) all fire on an **unclassified** event and none
     * of them fires on a **misclassified** one, which is how `role.assigned` and
     * `role.revoked` sat here — readable by any colleague holding
     * `app_user.view` — through a green suite (SEC-O-15).
     *
     * @var list<string>
     */
    public const BUSINESS = [
        self::PURCHASE_ORDER_PLACED,
        self::PURCHASE_ORDER_CANCELLED,
        self::SALES_ORDER_CANCELLED,
        self::INVOICE_ISSUED,
        self::INVOICE_PAID,
        self::INVOICE_PAYMENT_REVERSED,
        self::INVOICE_VOIDED,
        self::STOCK_MOVEMENT_ADJUSTED,
        self::GOODS_RECEIPT_CREATED,
        self::SALES_ORDER_CONFIRMED,
        self::FULFILMENT_CREATED,
        self::SALES_ORDER_DELETED,
        self::PURCHASE_ORDER_DELETED,
        self::SALES_RETURN_CREATED,
        self::CREDIT_NOTE_ISSUED,
        self::CREDIT_NOTE_RETURN_DECIDED,
        self::CUSTOMER_CREATED,
        self::CUSTOMER_UPDATED,
        self::SUPPLIER_CREATED,
        self::SUPPLIER_UPDATED,
        self::SUPPLIER_RESTORED,
        self::SUPPLIER_DELETED,
        self::SUPPLIER_PRODUCT_CREATED,
        self::SUPPLIER_PRODUCT_UPDATED,
        self::SUPPLIER_PRODUCT_DELETED,
        self::INVENTORY_ITEM_DELETED,
        self::INVENTORY_ITEM_RESTORED,
        self::TENANT_SETTINGS_UPDATED,
        self::CUSTOMER_RESTORED,
        self::CUSTOMER_DELETED,
        self::CUSTOMER_ADDRESS_CREATED,
        self::CUSTOMER_ADDRESS_UPDATED,
        self::CUSTOMER_ADDRESS_DELETED,
        self::CUSTOMER_CONTACT_CREATED,
        self::CUSTOMER_CONTACT_UPDATED,
        self::CUSTOMER_CONTACT_DELETED,
        self::WAREHOUSE_CREATED,
        self::WAREHOUSE_UPDATED,
        self::WAREHOUSE_RESTORED,
        self::WAREHOUSE_DELETED,
        self::SALES_ORDER_RESTORED,
        self::PURCHASE_ORDER_RESTORED,
        self::STORAGE_LOCATION_CREATED,
        self::STORAGE_LOCATION_UPDATED,
        self::STORAGE_LOCATION_RESTORED,
        self::STORAGE_LOCATION_DELETED,
    ];

    // ---------------------------------------------------------------------
    // The per-event subject — PC-11 (4c)'s prerequisite, B-4b.
    //
    // (4c) says "no admitted event names `app_user` as its subject", and until
    // this pass the catalogue declared no subject at all, so that clause could
    // not run against anything. PC-11 names the gap itself: "the catalogue
    // declares no per-event `subject_type` today, so (4c) needs one declaration
    // per member before its guard can derive anything. `BE-LEAD` owns that
    // shape". This is that shape.
    //
    // Three declarations rather than one map with a sentinel, because there are
    // genuinely three cases and a sentinel string would have to be a class
    // constant — which `everyDeclaredConstantIsInTheCatalogue` would then read
    // as an event type. They partition `ALL` the same way `SECURITY` and
    // `BUSINESS` do, and `everyEventDeclaresItsSubjectExactlyOnce` is where a
    // member left out of all three fails.
    //
    // A `subject_type` is a **table name**, matching what `audit_log` already
    // stores and what `audit_log_tenant_subject_idx` is keyed on;
    // `everyDeclaredSubjectTypeNamesATableInTheDatabase` reads the table list
    // back off the server rather than restating one here (S-17).
    // ---------------------------------------------------------------------

    /**
     * Events whose subject row is fixed by the event type itself.
     *
     * `role.*` names **`app_user`**: E-15 writes one row per
     * `(actor, subject user, role)` and the subject is the person whose
     * privileges changed — "who made Alice an admin" is a question about Alice.
     * The role is carried in `metadata`, which is where it has to live: a row
     * has one `(subject_type, subject_id)` pair and the pair is what L-7 queries
     * on. SEC-O-15 left this shape undeclared and marked its own reading of it
     * as an inference; it is declared here, and the ruling it supports holds
     * under either shape.
     *
     * That declaration is also what gives PC-11 (4c) something to bite on: with
     * `role.*` naming `app_user`, moving either constant back onto the allowlist
     * fails **two** guards — the (4b) pin and (4c) — rather than none.
     *
     * @var array<string, string>
     */
    public const SUBJECT_TYPES = [
        self::PURCHASE_ORDER_PLACED => 'purchase_order',
        self::PURCHASE_ORDER_CANCELLED => 'purchase_order',
        self::SALES_ORDER_CANCELLED => 'sales_order',
        self::INVOICE_ISSUED => 'invoice',
        self::INVOICE_PAID => 'invoice',
        self::INVOICE_PAYMENT_REVERSED => 'invoice',
        self::INVOICE_VOIDED => 'invoice',
        self::STOCK_MOVEMENT_ADJUSTED => 'stock_movement',
        self::GOODS_RECEIPT_CREATED => 'goods_receipt',
        self::SALES_ORDER_CONFIRMED => 'sales_order',
        self::FULFILMENT_CREATED => 'fulfilment',
        self::SALES_ORDER_DELETED => 'sales_order',
        self::PURCHASE_ORDER_DELETED => 'purchase_order',
        self::SALES_RETURN_CREATED => 'sales_return',
        self::CREDIT_NOTE_ISSUED => 'credit_note',
        self::CREDIT_NOTE_RETURN_DECIDED => 'credit_note',
        // Customers slice 1 C2: the customer row whose own state changed (G-35).
        self::CUSTOMER_CREATED => 'customer',
        self::CUSTOMER_UPDATED => 'customer',
        // Suppliers slice 1 C2: the supplier row whose own state changed (owner Q-S5).
        self::SUPPLIER_CREATED => 'supplier',
        self::SUPPLIER_UPDATED => 'supplier',
        // C5 C2a: the supplier row whose own state changed.
        self::SUPPLIER_RESTORED => 'supplier',
        // C5 C2b: the supplier row whose own state changed.
        self::SUPPLIER_DELETED => 'supplier',
        // G3-C0 (G-41): the catalogue entry, never the supplier (D-C0-1; `supplier_product` keeps its own `.view`).
        self::SUPPLIER_PRODUCT_CREATED => 'supplier_product',
        self::SUPPLIER_PRODUCT_UPDATED => 'supplier_product',
        self::SUPPLIER_PRODUCT_DELETED => 'supplier_product',
        // G5-C2a: the item row whose own state changed (`customer.deleted` / `.restored`'s shape).
        self::INVENTORY_ITEM_DELETED => 'inventory_item',
        self::INVENTORY_ITEM_RESTORED => 'inventory_item',
        // G7b: the settings row whose own state changed (`supplier.updated`'s shape).
        self::TENANT_SETTINGS_UPDATED => 'tenant_settings',
        // C5 C4a: the customer row whose own state changed.
        self::CUSTOMER_RESTORED => 'customer',
        // C5 C4b: the customer row whose own state changed.
        self::CUSTOMER_DELETED => 'customer',
        // Suppliers slice 1 C2b: the address row whose own state changed (owner F-12 / S4; `SEC` confirmed 2026-10-19).
        self::CUSTOMER_ADDRESS_CREATED => 'customer_address',
        self::CUSTOMER_ADDRESS_UPDATED => 'customer_address',
        // Customers slice 2 C3: the deleted address row; `metadata.customer_id` keeps the chain attributable (SEC S-4).
        self::CUSTOMER_ADDRESS_DELETED => 'customer_address',
        // Customers slice 2 C1: the contact row whose own state changed (owner Q-2; `SEC` S-4 confirmed the child subject).
        self::CUSTOMER_CONTACT_CREATED => 'customer_contact',
        self::CUSTOMER_CONTACT_UPDATED => 'customer_contact',
        // Customers slice 2 C2: the deleted contact row; `metadata.customer_id` keeps the chain attributable (SEC S-4).
        self::CUSTOMER_CONTACT_DELETED => 'customer_contact',
        // Warehouses slice 1 C2: the warehouse row whose own state changed (owner Q-W2 (a)).
        self::WAREHOUSE_CREATED => 'warehouse',
        self::WAREHOUSE_UPDATED => 'warehouse',
        // Warehouse/location delete-restore C1: the warehouse row whose own state changed (`SEC` S-1).
        self::WAREHOUSE_RESTORED => 'warehouse',
        // Warehouse/location delete-restore C4: the warehouse row whose own state changed (`SEC` S-1).
        self::WAREHOUSE_DELETED => 'warehouse',
        self::SALES_ORDER_RESTORED => 'sales_order',
        self::PURCHASE_ORDER_RESTORED => 'purchase_order',
        // Warehouses slice 1 C3: the location row whose own state changed (owner Q-W2 (a)).
        self::STORAGE_LOCATION_CREATED => 'storage_location',
        self::STORAGE_LOCATION_UPDATED => 'storage_location',
        // Warehouse/location delete-restore C2: the location row whose own state changed (`SEC` S-1).
        self::STORAGE_LOCATION_RESTORED => 'storage_location',
        // Warehouse/location delete-restore C3: the location row whose own state changed (`SEC` S-1).
        self::STORAGE_LOCATION_DELETED => 'storage_location',
        self::ROLE_ASSIGNED => 'app_user',
        self::ROLE_REVOKED => 'app_user',
        self::ROLE_ASSIGNMENT_PROPOSED => 'app_user',
        self::ROLE_ASSIGNMENT_LAPSED => 'app_user',
        self::ROLE_ASSIGNMENT_REJECTED => 'app_user',
        self::ROLE_ASSIGNMENT_CANCELLED => 'app_user',
        // Roles slice 4 C1: the ROLE is the subject — a definition change touches every holder at once, so no single
        // person is its subject. `role` is a table (`everyDeclaredSubjectTypeNamesATableInTheDatabase`).
        self::ROLE_PERMISSIONS_CHANGED => 'role',
        // Roles slice 4 C2: the same subject as the code-set event, for the same reason.
        self::ROLE_UPDATED => 'role',
        // Roles slice 4 C3: the role whose definition left the set.
        self::ROLE_DELETED => 'role',
        // G2: the role whose definition came back.
        self::ROLE_RESTORED => 'role',
        // G4-C1: the person whose liveness or name changed.
        self::USER_DEACTIVATED => 'app_user',
        self::USER_REACTIVATED => 'app_user',
        self::USER_UPDATED => 'app_user',
    ];

    /**
     * Events that carry no subject at all: `subject_type` and `subject_id` are
     * both `NULL`, which is one of the two shapes `audit_log_subject_pair`
     * permits and which `AuditLogEntry`'s constructor already enforces.
     *
     * `auth.*` is **E-13**, structurally: the event is about a principal and the
     * principal is already `actor_user_id`. `data.export.completed` is E-16 and
     * describes a filtered list, so there is no single row to name — its own
     * docblock made that argument before this constant existed and this is where
     * the argument becomes checkable. **E-16's resource, filter state and row
     * count therefore live in `metadata`**, which is the only place left for
     * them; PC-11's `metadata` clause does not reach them, because that clause
     * binds rows reachable by `.view_record` and this event is not one.
     *
     * @var list<string>
     */
    public const SUBJECTLESS = [
        self::AUTH_LOGIN_SUCCESS,
        self::AUTH_LOGIN_FAILURE,
        self::AUTH_LOGIN_TENANT_INACTIVE,
        self::AUTH_PASSWORD_CHANGED,
        self::DATA_EXPORT_COMPLETED,
    ];

    /**
     * Events whose subject is not fixed by the catalogue: it is whatever
     * resource the request was denied, resolved at write time.
     *
     * E-3 requires the resource type and id on a denial, and a permission code
     * spans the whole resource vocabulary, so no single table can be declared
     * here. E-3a is the reason this is called out rather than folded into
     * `SUBJECT_TYPES` with a guess: **E-1 puts the permission check before the
     * row is loaded**, so a denial's `subject_id` is caller-supplied and
     * unresolved — which is exactly why E-3a refuses to key its cap on it.
     *
     * A member of this list can never satisfy PC-11 (4c), which is why (4c)'s
     * guard fails closed on one rather than skipping it.
     *
     * @var list<string>
     */
    public const SUBJECT_RESOLVED_AT_WRITE = [
        self::AUTHZ_PERMISSION_DENIED,
    ];

    /** Not instantiable: this is a catalogue, not a service. */
    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::ALL;
    }

    public static function isKnown(string $eventType): bool
    {
        return \in_array($eventType, self::ALL, true);
    }

    /**
     * PC-11's predicate: the one place `BUSINESS` becomes behaviour.
     *
     * `theRecordHistoryPredicateAdmitsExactlyTheAllowlist` derives this method's
     * answer over `ALL` and compares it to `BUSINESS`, which is what makes the
     * lists above worth anything — before that test existed, this body could be
     * changed to `self::ALL` and the whole suite stayed green.
     *
     * **Keeping every caller on this method rather than on a hand-written
     * predicate is a convention, and it is labelled judgement (Rule 14):**
     * nothing here can stop Output 4 writing its own `NOT LIKE`. What can be
     * tested is this method, and it is.
     */
    public static function isReadableAsRecordHistory(string $eventType): bool
    {
        return \in_array($eventType, self::BUSINESS, true);
    }
}
