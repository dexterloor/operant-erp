<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\AuditLog\Infrastructure\SalesOrderAuditWriter;
use App\Modules\Sales\ApiResource\CreateSalesOrderInput;
use App\Modules\Sales\ApiResource\CreateSalesOrderLineInput;
use App\Modules\Sales\ApiResource\SalesOrderLineResource;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\ApiResource\UpdateSalesOrderInput;
use App\Modules\Sales\ApiResource\UpdateSalesOrderLineInput;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Application\Contract\WorkspaceSettings;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The write half of the `/sales-orders` slice — **`CreateSalesOrder` and nothing else**.
 *
 * ===========================================================================
 * This class issues **`INSERT` only** — and as of `ConfirmSalesOrder` the grant no longer says so
 * ===========================================================================
 * `Version20260919000200` granted `erp_app` `INSERT` on `sales_order` and `sales_order_line` and no
 * `UPDATE` (ruling R-B, ADR-002 A4.4 duty 1's *"exactly"*), and every statement below is still an
 * `INSERT` or a `SELECT`. ⚠ **But the sentence that used to follow — *"an `UPDATE` added to this class
 * without its own grant is a `42501` at runtime"* — is now FALSE and is struck rather than left to be
 * quoted.** `Version20260919000300` granted `UPDATE` on both tables for `SalesOrderConfirmation`, which
 * is a different caller on the same tables, so a stray `UPDATE` written here would now **succeed**. R-B's
 * narrowness bought exactly what it was argued to buy — one commit of loudness — and what replaces it is
 * this paragraph plus `PurchaseOrderRowLockTest`'s pinned `writes` list, which reddens on any statement
 * this method gains. A4.5's caller→grant check, the control that would catch it properly, is still
 * carried and unbuilt.
 *
 * ===========================================================================
 * W-5 and the lock this method does not take
 * ===========================================================================
 * `ARCHITECTURE.md` W-5 — *"Sales locks the order, then stock"* — binds every write edge that has an
 * existing row to serialise against. **`create()` has none**: the order is created by this transaction and
 * its id is a freshly minted UUID, so until `COMMIT` there is no second transaction that can address the
 * row or its lines, and a lock would have nothing to hold. That is a property of the write rather than a
 * claim about callers, and it is `PurchaseOrderWriter::create()`'s position verbatim.
 * `PurchaseOrderRowLockTest::everyMethodThatWritesASalesOrderIsCoveredByALockFirstCheck` is where that
 * exemption is recorded, with the writes it covers pinned — so a second statement added here reddens the
 * pin and whoever adds it has to re-read this paragraph. **That prediction has now happened:**
 * `ConfirmSalesOrder` is the first sales writer touching an existing order, it takes `SalesOrderRowLock`
 * as its first statement, and `Version20260919000300` gave it the `UPDATE` that `FOR NO KEY UPDATE` needs
 * on its own (A4.3 constraint 1). `CancelSalesOrder` and `CreateSalesOrderLine` are still owed the same.
 *
 * ===========================================================================
 * Where a constraint failure is caught
 * ===========================================================================
 * Outside `TenantContext::run()`, never inside it — `ItemWriter`'s rule, for its reason: a failed statement
 * leaves the Postgres transaction aborted (25P02), so catching within the block and continuing makes the
 * following `COMMIT` fail with an error nobody can trace back to the line that caused it.
 */
final readonly class SalesOrderWriter
{
    /** numeric(19,6) — quantities. Precision 19, scale 6, so at most 13 digits before the point. */
    private const QUANTITY = '/^\d{1,13}(\.\d{1,6})?$/';

    /** numeric(19,4) — money (S-12). Never a float, in either direction. */
    private const MONEY = '/^\d{1,15}(\.\d{1,4})?$/';

    /**
     * The exact scale of `quantity × unit_price` — numeric(19,6) times numeric(19,4). The overflow guard
     * accumulates here and rounds once, because bcmath truncates at its scale argument and Postgres does
     * not (RED exit audit F-2).
     */
    private const PRODUCT_SCALE = 10;

    /** One sentence for both overflow refusals, so they cannot drift apart. */
    private const TOTAL_OUT_OF_RANGE = 'This line would take the order\'s total past what it can be stored as: '
        .'a total carries at most 15 digits before the decimal point. Lower the quantity or the unit price, '
        .'or split the order.';

    /** The live-row unique a **replay** collides on — `Version20260919000100`. */
    private const IDEMPOTENCY_INDEX = 'sales_order_tenant_idem_live';

    /** The live-row unique a **race between two creates** collides on. */
    private const NUMBER_INDEX = 'sales_order_tenant_number_live';

    /** §4.2's operation id, digested with the request so no other operation's request can match it. */
    private const CREATE_OPERATION = 'CreateSalesOrder';

    /** §6.2's operation name in `CreateSalesOrderLine`'s request digest. */
    private const CREATE_LINE_OPERATION = 'CreateSalesOrderLine';

    /** `Version20260921000100`. The line's retry key — tenant-wide, partial on `deleted_at IS NULL`. */
    private const LINE_IDEMPOTENCY_INDEX = 'sales_order_line_tenant_idem_live';

    /** `UpdateSalesOrderLine`'s writable columns, keyed by input property, so no UPDATE restates the list. */
    private const LINE_COLUMNS = [
        'quantity' => 'quantity',
        'unitPrice' => 'unit_price',
    ];

    /** `CAST(:p AS t)`, never `:p::t` — DBAL's parser cannot tell a Postgres cast from a named placeholder. */
    private const LINE_CASTS = [
        'quantity' => 'numeric',
        'unitPrice' => 'numeric',
    ];

    /** `(tenant_id, sales_order_id, line_number) WHERE deleted_at IS NULL` — the line-number race. */
    private const LINE_NUMBER_INDEX = 'sales_order_line_unique';

    /**
     * §4.2's `lines[]` sub-fields that `CreateSalesOrder` accepts, as input properties. The field names the
     * client sends are derived from `POINTERS` by {@see self::nestedLineFields()}, so this set has one
     * spelling.
     */
    private const NESTED_LINE_PROPERTIES = ['inventoryItemId', 'quantity', 'unitPrice'];

    /**
     * **How many lines one `CreateSalesOrder` REQUEST may carry**, and it is
     * `PurchaseOrderWriter::MAXIMUM_LINES`'s number for that constant's one surviving reason: an unbounded
     * list puts an unbounded number of `INSERT`s and catalogue lookups inside a single transaction. It
     * bounds one request, not an order — `CreateSalesOrderLine` will be uncapped, and each of those calls
     * is its own transaction.
     *
     * **Public because the refusal message names the number.**
     */
    public const MAXIMUM_LINES = 200;

    /** Property name => JSON Pointer, so a refusal and the accepted-field set have one spelling. */
    private const POINTERS = [
        'customerId' => '/customer_id',
        'warehouseId' => '/warehouse_id',
        'currencyCode' => '/currency_code',
        'shipToAddressId' => '/ship_to_address_id',
        'inventoryItemId' => '/inventory_item_id',
        'quantity' => '/quantity',
        'unitPrice' => '/unit_price',
    ];

    /**
     * `UpdateSalesOrder`'s writable **columns**, keyed by input property.
     *
     * ⚠ **`shipToAddressId` is deliberately absent and that is not an oversight:** `sales_order` has no
     * `ship_to_address_id` column. That field re-resolves a **snapshot** into four columns, which
     * {@see self::update()} appends by hand.
     */
    private const UPDATE_COLUMNS = [
        'customerId' => 'customer_id',
        'warehouseId' => 'warehouse_id',
    ];

    /**
     * Written as `CAST(:p AS t)` rather than `:p::t` because DBAL's parser has to tell a Postgres cast
     * from a named placeholder, and `:customerId::uuid` is exactly the string where that goes wrong.
     */
    private const UPDATE_CASTS = [
        'customerId' => 'uuid',
        'warehouseId' => 'uuid',
    ];

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private WorkspaceSettings $settings,
        private SalesOrderAuditWriter $audit,
    ) {
    }

    /**
     * **`CreateSalesOrder` — the revenue-side mirror of `CreatePurchaseOrder`, and the first thing in
     * `src/` ever to write a `sales_order` row.**
     *
     * ===========================================================================
     * The retry key, and why the replay is a READ rather than a caught `23505`
     * ===========================================================================
     * The `Idempotency-Key` header's value — **hashed**, as D-B ruled — is written into
     * `sales_order.idempotency_key`, which sits on the live-row unique `sales_order_tenant_idem_live`. A
     * retried timeout therefore collides **at the database**, which is the only route by which an operation
     * whose response carries a server-minted `id` can be made retry-safe.
     *
     * The replay is recognised by a `SELECT` **before** the insert, because a constraint violation inside
     * `wrapInTransaction` closes the EntityManager — there is no unit of work left to re-read the row with
     * afterwards. The read answers every ordinary replay; the unique index is the concurrency backstop that
     * makes the read safe to rely on. `request_digest` decides between them: equal is a replay, different is
     * §6.2's `idempotency-key-conflict` (409).
     *
     * ⚠ **The residual, named rather than solved, and it is `create()`'s on both sides of the system:** two
     * identical retries in flight at the same instant. One loses at the index, its unit of work is gone, and
     * this method answers `business-rule-violated` — *try again* — instead of the order. A third attempt
     * reads the committed row and succeeds, so the client is never told anything false and never gets a
     * second order.
     *
     * ===========================================================================
     * `total_amount` comes from the TABLE, and the forward declaration that said so has FIRED
     * ===========================================================================
     * ⚠ **This block described a `VALUES`-list expression inside the order's own `INSERT` until
     * 2026-09-21, and called itself *"deliberate throwaway code, dead the day `CreateSalesOrderLine`
     * ships"*. That day is this commit**, so the expression is gone and
     * {@see self::recomputeTotal()} runs after the lines land — the same statement
     * {@see self::createLine()} runs, because a line added to an existing order is in no request this
     * method saw and two totals computed two ways are two totals that drift.
     *
     * ⚠ **One sentence of that declaration was already FALSE when it fired, and it is corrected rather
     * than quietly dropped.** It read *"the commit that adds it **grants `UPDATE`**"*. No grant is owed:
     * `e81cb35` (`ConfirmSalesOrder`, `Version20260919000300`) widened `erp_app` to `SELECT, INSERT,
     * UPDATE` on `sales_order` on 2026-09-20, and `has_table_privilege('erp_app','sales_order','UPDATE')`
     * was **re-measured `true`** in this commit rather than inherited. The sentence was written on
     * 2026-09-19 under ruling R-B's `INSERT`-only footprint and went stale the next day — which is why a
     * forward declaration must be re-measured when it fires, not read.
     *
     * The old expression's soundness condition is retired with it and recorded here because it explains
     * why the shape was available at all: **no `sales_order_line` column is server-derived** — the columns
     * are `inventory_item_id`, `line_number`, `quantity`, `quantity_fulfilled` (default 0) and
     * `unit_price`, all from the request or defaulted — where the purchase side's line snapshots
     * `lead_time_days` from the supplier catalogue and a `VALUES` total would have been wrong there.
     *
     * ===========================================================================
     * The ship-to snapshot (O-2) and V2-S-9
     * ===========================================================================
     * The four `ship_to_*` columns are copied from the `customer_address` row the request names, **at
     * create** (owner ruling, 2026-09-19). The order stores no address id, which is the whole reason
     * `customer_address` is deletion class `hard`. {@see self::shipToInside()} resolves the row, proves it
     * belongs to `customer_id` and proves it is a `shipping` address, in one statement.
     *
     * ===========================================================================
     * `created_by_user_id` and `order_number`
     * ===========================================================================
     * `created_by_user_id` is not an accepted field and is set server-side from the JWT subject on every
     * user-initiated create — §8.1's rule for the purchasing mirror.
     *
     * ⚠ **This paragraph said *"and DSoD-1's input on this side too"* when it shipped on 2026-09-19, and
     * that was FALSE.** No DSoD rule names `sales_order`: ADR-008 is purchase-only throughout, SEC-O-9
     * verified this column records *"the creator, not the confirmer"*, and IA-O-8 states that F-5 *"has no
     * mirror of this gap … there is no seam"*. The sentence is corrected here the day B1N-2 was ruled,
     * because the ruling's first amendment is that this justification must not be copied across.
     *
     * **The value is returned as of `GetSalesOrder`'s commit** — B1N-2 ruled it visible on PC-11 (4a)
     * ground (`SECURITY_MODEL.md` PC-15 **O-5**). It was written and withheld for exactly one commit, and
     * the withholding was always a disclosure question rather than a write one, which is why nothing in
     * this method changed when the ruling landed.
     *
     * `order_number` is minted here and **no document specifies its form** — the only property anything
     * relies on is that it is server-generated, which is precisely why it cannot serve as a retry key.
     * `MAX + 1` over the tenant's rows is `nextOrderNumber()`'s shape — over **all** of them since the owner's
     * Q4 ruling (2026-09-21), archived included, so a discarded order's number is never reissued; the purchase
     * twin does the same since the owner extended Q4 to purchasing the same day. **Reported as a decision taken here rather than
     * presented as derived.**
     */
    public function create(CreateSalesOrderInput $input, string $idempotencyKey): SalesOrderResource
    {
        $values = [
            'customerId' => $input->customerId,
            'warehouseId' => $input->warehouseId,
            'currencyCode' => $input->currencyCode,
            'shipToAddressId' => $input->shipToAddressId,
        ];

        // The header's shape is answered before the lines' shape, so a request malformed in both takes two
        // round trips. `PurchaseOrderWriter::create()` says why that is a decision: each pass collects
        // everything it can see, and merging them would make this the only operation in the contract that
        // collects across two different kinds of rule.
        $this->refuseMalformedCreateFields($values);
        $lines = self::nestedLines($input->lines);

        // D-B: what was SENT, normalised -- after the shape check, so every value here is one the
        // normalisers accept. `currency_code` is the client's value or null, never the workspace default it
        // resolves to below: that default can change between a timeout and its retry.
        $digestedFields = [
            'customer_id' => RequestDigest::uuid($values['customerId']),
            'warehouse_id' => RequestDigest::uuid($values['warehouseId']),
            'currency_code' => $values['currencyCode'],
            'ship_to_address_id' => RequestDigest::uuid($values['shipToAddressId']),
        ];

        // **The list keeps its order**, which `RequestDigest` does by design: `line_number` is minted from
        // the position, so the same two lines the other way round are a different order and must be a
        // conflict, not a replay.
        //
        // ⚠ Unlike the purchasing side, `lines` is added **unconditionally when it was sent and omitted
        // when it was not** for a reason that is about typing rather than about history: no sales order
        // predates this operation, so there is no stored digest to stay compatible with. The distinction
        // survives anyway because absent and `null` are one request (`?array $lines = null` cannot tell
        // them apart), and a key that is absent leaves the canonical form byte-identical.
        if (null !== $lines) {
            $digestedFields['lines'] = self::digestedLines($lines);
        }

        $digest = RequestDigest::of(self::CREATE_OPERATION, $digestedFields);

        // D-B: the column holds the header's hash, never its value. The replay read and the INSERT below
        // both use this, so they cannot disagree.
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);

        $id = Uuid::v4()->toRfc4122();
        // Read before the transaction opens: it is a property of the request, not of the write, and
        // `authenticated()` fails closed on its own.
        $createdBy = $this->identities->authenticated()->userId;

        try {
            return $this->tenants->run(
                $this->tenant->tenantId(),
                function (EntityManagerInterface $em) use ($values, $lines, $id, $createdBy, $keyHash, $digest): SalesOrderResource {
                    // The replay answer, and it is a read rather than a caught exception. See the method
                    // docblock.
                    $replay = $this->orderCreatedByKeyInside($em, $keyHash, $digest);

                    if (null !== $replay) {
                        return $replay;
                    }

                    $this->refuseUnresolvableCreateReferences($em, $values);

                    // V2-S-9 + O-2: resolve, prove ownership and prove the kind, then copy.
                    $shipTo = $this->shipToInside($em, (string) $values['customerId'], (string) $values['shipToAddressId']);

                    // Read INSIDE the transaction, for `ItemWriter`'s reason: `set_config(..., true)` and
                    // the tenant filter's parameter are both transaction-scoped. There is deliberately no
                    // `?? 'EUR'` -- a workspace with no settings row cannot raise an order, which is the
                    // refusal E4-3 exists to produce.
                    $currencyCode = $values['currencyCode'] ?? $this->settings->defaultCurrencyCode();

                    $this->insertOrderInside($em, $id, $values, $shipTo, $currencyCode, $createdBy, $keyHash, $digest);

                    // D-C's shape: the lines, in the SAME transaction as the order. Any refusal below rolls
                    // the order back with them -- there is no savepoint and none is wanted, because a create
                    // that kept the order and dropped a line is the headless draft this step exists to make
                    // impossible.
                    foreach ($lines ?? [] as $index => $line) {
                        $this->insertLineInside($em, $id, $line, $index);
                    }

                    // The money rule, in the one place it is now written. The pre-flight per-line bound in
                    // `malformedLineFields()` stays and is not redundant: it names the offending FIELD,
                    // which a sum over the stored rows cannot.
                    $this->recomputeTotal($em, $id, self::POINTERS['unitPrice']);

                    return $this->readOrderInside($em, $id)
                        ?? throw new \LogicException('The order just inserted is not visible to its own transaction.');
                },
            );
        } catch (UniqueConstraintViolationException $e) {
            // Caught OUTSIDE `TenantContext::run()`, never inside it: a failed statement leaves the Postgres
            // transaction aborted (25P02).
            if (self::violated($e, self::IDEMPOTENCY_INDEX)) {
                // The narrow race the read above cannot cover: a request with the same key committed between
                // that SELECT and this INSERT. The EntityManager is closed, so the order cannot be read back
                // here -- the client is told to try again, and the next attempt reads the committed row and
                // gets it. Never a second order.
                throw ApiProblem::businessRuleViolated(
                    'sales_order.idempotency_key_is_in_flight',
                    'A request with this Idempotency-Key is already being processed. Try again.',
                );
            }

            if (self::violated($e, self::NUMBER_INDEX)) {
                // Told apart BY NAME from the one above, and the distinction is load-bearing rather than
                // tidy: this is a race between two concurrent creates picking the same MAX + 1, which the
                // caller should retry, and answering it as a replay would hand one caller the *other*
                // caller's order.
                throw ApiProblem::businessRuleViolated(
                    'sales_order.order_number_is_unique_per_workspace',
                    'Another order was raised in this workspace at the same moment. Try again.',
                );
            }

            throw $e;
        } catch (ForeignKeyConstraintViolationException) {
            // `sales_order_customer_fk` and `sales_order_warehouse_fk` are composite on `(tenant_id, id)`,
            // so an id belonging to another tenant fails here rather than at a lookup -- and ST-5 means the
            // caller must not learn which of "wrong tenant" and "no such row" it was. Both fields are named
            // because the statement does not say which key failed.
            throw ApiProblem::validationFailed([
                self::error('customerId', 'unknown_reference', 'One of the ids sent names no row in this workspace.'),
                self::error('warehouseId', 'unknown_reference', 'One of the ids sent names no row in this workspace.'),
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // The statements
    // -----------------------------------------------------------------------

    /**
     * The order's `INSERT`, in **one shape**, with `total_amount` written as a literal `0`.
     *
     * ⚠ **Until 2026-09-21 this method had TWO statement shapes and computed the total itself**, from a
     * `VALUES` list of the request's own lines, because ruling R-B granted `INSERT` only and an empty
     * `VALUES` list is a syntax error in Postgres. {@see self::create()}'s docblock called that expression
     * *"deliberate throwaway code, dead the day `CreateSalesOrderLine` ships"* and named the deletion as
     * that commit's work. **This is that commit.** The total is now `recomputeTotal()`'s, read back from the
     * table after the lines land, which is the only source that can be right for an order whose lines did
     * not all arrive in one request — and it is the same statement `CreateSalesOrderLine` uses, so the
     * money rule lives in exactly one place instead of two that drift.
     *
     * @param array<string, string|null>             $values
     * @param array{line: string, city: string, postalCode: string, countryCode: string} $shipTo
     */
    private function insertOrderInside(
        EntityManagerInterface $em,
        string $id,
        array $values,
        array $shipTo,
        string $currencyCode,
        ?string $createdBy,
        string $keyHash,
        string $digest,
    ): void {
        $columns = <<<'SQL'
            INSERT INTO sales_order
                (id, tenant_id, order_number, customer_id, warehouse_id, created_by_user_id,
                 currency_code, ship_to_line, ship_to_city, ship_to_postal_code, ship_to_country_code,
                 total_amount, idempotency_key, request_digest)
            SQL;

        $parameters = [
            $id,
            // Bound explicitly rather than read back out of the GUC. The row still has to satisfy the RLS
            // WITH CHECK clause, so a mismatch between this value and the transaction's tenant is refused by
            // Postgres rather than accepted.
            $this->tenant->tenantId(),
            $this->nextOrderNumber($em),
            $values['customerId'],
            $values['warehouseId'],
            $createdBy,
            $currencyCode,
            $shipTo['line'],
            $shipTo['city'],
            $shipTo['postalCode'],
            $shipTo['countryCode'],
            $keyHash,
            $digest,
        ];

        $em->getConnection()->executeStatement(
            $columns."\n".<<<'SQL'
            VALUES
                (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, ?::uuid,
                 ?, ?, ?, ?, ?,
                 0, ?, ?)
            SQL,
            $parameters,
        );
    }

    /**
     * **`UpdateSalesOrder` — the second writer of `sales_order.ship_to_*`, and the first `UPDATE`
     * this class has ever issued.**
     *
     * =========================================================================
     * Three fields, all frozen at confirm, and the freeze is a row and not a sentence
     * =========================================================================
     * §4.2's cell is `customer_id`, `warehouse_id`, `ship_to_address_id`. All three are editable
     * **while the order is `draft` and at no other time**, which is owner ruling **O-2** for the
     * ship-to and a decision recorded in `API.md` §8.3a for the other two (OI-2's *"nothing issued is
     * edited"*, the uniform purchasing precedent, and the asymmetric cost — widening a refusal later is
     * additive and loud, narrowing a success later breaks a client).
     *
     * O-2 attached a condition: *"frozen at confirm is a PROMISE and must ship with a named guard and a
     * test in the commit that introduces it."* {@see self::refuseUnlessDraft()} is the guard and
     * **`EditWindowGuardTest`** is the test. It is **not** a §8.3 transition guard, and that is a
     * measured fact rather than a preference: `StatusMachines::ASSIGNMENT` selects methods that assign
     * `$this->status`, so a status-keyed guard on a non-transition method is invisible to every clause
     * of §8.3 — `RED` planted one in `SalesOrder::shipTo()` and the **whole suite stayed green**.
     * §8.3a exists because of that measurement.
     *
     * =========================================================================
     * The ship-to snapshot, and why a `customer_id` **change** drags it along
     * =========================================================================
     * `sales_order` holds no address id — it holds four copied columns — and
     * {@see self::shipToInside()} is the one place the rule *"this address belongs to this customer"*
     * is written. So if the customer moves and the address does not, the row keeps a shipping address
     * copied from the **previous** customer's address book, and **no constraint, index or trail row in
     * this system can ever detect it**. That is `RESIDUAL_GAPS.md` G-6's harm class reached through a
     * shipped operation.
     *
     * Therefore a `customer_id` that **changes** must carry `ship_to_address_id` in the same request.
     * ⚠ **On change, never on presence.** A client that PATCHes every field it rendered resubmits the
     * customer it already had; refusing that would 422 an ordinary, correct request. The comparison is
     * made **inside the transaction against the loaded row**, which is also why this refusal cannot live
     * in {@see self::refuseMalformedUpdateFields()} with the shape checks.
     *
     * **Which customer id the snapshot is resolved against** is the submitted one when the request
     * carries it, and otherwise the row's current one, read **under the row lock**. Reading it before
     * the lock would let a concurrent write move the customer between the read and the `UPDATE`, and the
     * snapshot would then belong to a customer the row no longer names. That ordering is load-bearing.
     *
     * =========================================================================
     * One raw `UPDATE`, and the statement shape is chosen so the scans can see it
     * =========================================================================
     * `SalesOrder::shipTo()` is **not** called — it no longer exists. G-6's own shape analysis measured
     * that an ORM write reached through an association (`$fulfilment->getSalesOrder()->shipTo(…)`)
     * reddens **neither** the write-ownership scan nor `PurchaseOrderRowLockTest`, while a raw
     * `UPDATE sales_order` reddens both. Choosing the statement shape the existing scans can read costs
     * nothing and is the cheapest part of holding a second writer to the rule.
     *
     * =========================================================================
     * No audit row, no idempotency key
     * =========================================================================
     * `AuditEvent::ALL` has no `*.updated` member of any kind and `audit_log_event_type_values` refuses
     * anything outside it, so an event here is not possible without a migration; `UpdatePurchaseOrder`
     * and `UpdateItem` write none either. **The cost is real and is recorded rather than hidden:**
     * nobody can answer *"who last moved this draft's shipping address"* — `updated_at` records when,
     * not who. It is owner-accepted residual `RESIDUAL_GAPS.md` **G-19** (roles slice 2, Q-B, 2026-09-29), not a
     * B-4a carry: the reconciled B-4a row never named it.
     *
     * §4.2's `Idem` cell reads `no` and stays `no`: a `PATCH` sets absolute values on a row that already
     * exists, so a replay converges on the same state and API-14 has no mechanism to name.
     * ⚠ **One wrinkle worth not "fixing" later:** a client that times out and retries *after* a
     * concurrent confirm gets **409**, not 200. That is correct — the second call is refused by the edit
     * window and the first one's effect stands. It is not a retry defect and must not be answered with a
     * key.
     */
    public function update(string $id, UpdateSalesOrderInput $input): SalesOrderResource
    {
        $submitted = $input->submitted();
        $this->refuseMalformedUpdateFields($submitted);

        return $this->translatingUpdateConstraintFailures(fn (): SalesOrderResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $submitted): SalesOrderResource {
                SalesOrderRowLock::holdInside($em, $id);
                $current = $this->readOrderInside($em, $id) ?? throw ApiProblem::notFound();

                // ST-9 / §5.2a: an archived row renders read-only, and the refusal is on the
                // business-rule branch rather than `not-found` -- the caller can see the row, so
                // pretending it is gone would be a different lie from the one it is being told.
                // It is before reference resolution deliberately: `ArchivedRowSubjects` drives this
                // operation with a body of ids and expects this refusal.
                $this->refuseIfArchived($current);
                $this->refuseUnlessDraft($current);

                // The customer the snapshot is proved against: the submitted one, or the row's.
                $customerId = \array_key_exists('customerId', $submitted)
                    ? (string) $submitted['customerId']
                    : $current->customer->id;
                $addressId = $submitted['shipToAddressId'] ?? null;

                if ($customerId !== $current->customer->id && null === $addressId) {
                    throw ApiProblem::validationFailed([
                        self::error('shipToAddressId', 'required',
                            'Changing the customer also changes which addresses are hers, so this request must '
                            .'name the shipping address to use.'),
                    ]);
                }

                $this->refuseUnresolvableUpdateReferences($em, $submitted);

                $columns = [];
                $parameters = ['id' => $id];

                foreach (self::UPDATE_COLUMNS as $property => $column) {
                    if (\array_key_exists($property, $submitted)) {
                        $columns[] = $column.' = '.self::bind($property, self::UPDATE_CASTS);
                        $parameters[$property] = $submitted[$property];
                    }
                }

                if (null !== $addressId) {
                    // The one place the ownership rule is written, reached by the second writer. G-6
                    // option (a), and `WriteOwnershipTest`'s `everyWriteOfAShipToColumnResolvesTheAddressFirst`
                    // is what holds a third writer to it.
                    $shipTo = $this->shipToInside($em, $customerId, $addressId);

                    $parameters['shipToLine'] = $shipTo['line'];
                    $parameters['shipToCity'] = $shipTo['city'];
                    $parameters['shipToPostalCode'] = $shipTo['postalCode'];
                    $parameters['shipToCountryCode'] = $shipTo['countryCode'];
                }

                // ⚠ **Two statement TEXTS, one statement ISSUED, and the split is not cosmetic.**
                // `WriteOwnershipTest::everyWriteOfAShipToColumnResolvesTheAddressFirst` matches on a
                // single string literal that spells **both** a write keyword and a `ship_to_` column.
                // The first version of this method appended `'ship_to_line = :shipToLine'` and its three
                // siblings to an array, so the column names lived in literals that carried no `UPDATE` —
                // and the check's own anti-vacuity control caught it immediately, finding **one** ship-to
                // writer in `src/` where G-6's trigger says there are two. **A scan this write is
                // invisible to is a scan that cannot hold the next writer to anything**, so the snapshot
                // branch spells its columns in the same literal as its keyword. That is
                // {@see self::insertOrderInside()}'s own pattern, for the same kind of reason.
                if (null !== $addressId) {
                    $em->getConnection()->executeStatement(
                        'UPDATE sales_order SET ship_to_line = :shipToLine, ship_to_city = :shipToCity, '
                        .'ship_to_postal_code = :shipToPostalCode, ship_to_country_code = :shipToCountryCode'
                        .([] === $columns ? '' : ', '.implode(', ', $columns))
                        .', updated_at = now() WHERE id = CAST(:id AS uuid)',
                        $parameters,
                    );
                } elseif ([] !== $columns) {
                    $em->getConnection()->executeStatement(
                        'UPDATE sales_order SET '.implode(', ', $columns)
                        .', updated_at = now() WHERE id = CAST(:id AS uuid)',
                        $parameters,
                    );
                }

                return $this->readOrderInside($em, $id) ?? throw ApiProblem::notFound();
            },
        ));
    }

    /**
     * `UpdateSalesOrder`'s shape rules — everything decidable without the database, so it runs before a
     * transaction is opened.
     *
     * ⚠ **The `customer_id`-implies-`ship_to_address_id` rule is NOT here**, and its absence is the
     * decision rather than an omission: that rule is about a **change**, which needs the row, which needs
     * the lock. It lives in {@see self::update()} inside the transaction.
     *
     * All three columns behind these fields are `NOT NULL`, so none may be cleared by a `PATCH`.
     * `ship_to_address_id` is not a column at all, but a `null` there would mean *"clear the snapshot"*,
     * and `sales_order.ship_to_country_code` is `NOT NULL` with no default and a foreign key onto
     * `country` — there is nothing to clear it to.
     *
     * @param array<string, string|null> $values
     */
    /**
     * **`CreateSalesOrderLine` — one line added to an order that already exists.**
     *
     * The order of the steps IS the design, ported from `PurchaseOrderWriter::createLine()` because that
     * ordering is itself a ruling (ST-5, §6.2, and the replay exemption):
     *
     *   1. shape check **outside** any transaction;
     *   2. the row lock, as the **first statement** inside it (the sales lock-first tripwire demands it);
     *   3. **not-found before the key lookup** — ST-5: a key lookup first would tell a caller that their
     *      key was used in this workspace;
     *   4. the **key lookup**: a different digest is 409 `idempotency-key-conflict`; the same digest is the
     *      line that key created, with **no insert and no total recompute** — §6.2's domain-key replay;
     *   5. only **then** the archived and draft guards, so a retry arriving after the order was confirmed
     *      is handed its line rather than refused for an act that already committed. This is §8.3a's
     *      **replay exemption**, and it is declared in that table rather than left in this comment;
     *   6. the insert, carrying the key hash and the digest;
     *   7. `recomputeTotal()`, from the table.
     *
     * **The digest includes the path's order id.** The unique is tenant-wide (a header supplies one
     * value), so without it the same key sent to another order would replay that other order's line. A
     * same-key request on another order that races past step 4 is refused at the index, and
     * {@see self::translatingConstraintFailures()} maps that violation **by name** to 409.
     *
     * **Uncapped.** `MAXIMUM_LINES` bounds one *request*, not an order, and each of these calls is its own
     * transaction — which is what that constant's own docblock says.
     */
    public function createLine(string $orderId, CreateSalesOrderLineInput $input, string $idempotencyKey): SalesOrderLineResource
    {
        $values = [
            'inventoryItemId' => $input->inventoryItemId,
            'quantity' => $input->quantity,
            'unitPrice' => $input->unitPrice,
        ];

        $this->refuseMalformedLineFields($values, required: self::NESTED_LINE_PROPERTIES);

        // D-B: the column holds the header's hash, never its value.
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);

        return $this->translatingConstraintFailures(fn (): SalesOrderLineResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($orderId, $values, $keyHash): SalesOrderLineResource {
                SalesOrderRowLock::holdInside($em, $orderId);
                $order = $this->readOrderInside($em, $orderId) ?? throw ApiProblem::notFound();

                // After not-found, so the path id is a valid UUID here and the digest's normaliser
                // accepts it.
                $digest = self::lineDigest($orderId, $values);

                $replay = $this->lineCreatedByKeyInside($em, $orderId, $keyHash, $digest);
                if (null !== $replay) {
                    return $replay;
                }

                $this->refuseIfArchived($order);
                $this->refuseUnlessDraft($order);

                $id = $this->insertLineInside($em, $orderId, $values, null, $keyHash, $digest);

                // This request sent exactly one line and both of its money fields, so the refusal names
                // the same field the pre-flight bound names.
                $this->recomputeTotal($em, $orderId, self::POINTERS['unitPrice']);

                return $this->readLineInside($em, $orderId, $id)
                    ?? throw new \LogicException('The line just inserted is not visible to its own transaction.');
            },
        ));
    }

    /**
     * **`UpdateSalesOrderLine` — `quantity` and `unit_price` on a draft order's line.**
     *
     * Lock the order first (W-5), then the order's guards, then the line's. An **empty `PATCH` is legal**:
     * no statement is issued, the row is read back and the answer is 200. `ArchivedRowSubjects` depends
     * on exactly that shape, because its write bodies are `static` and may carry no seeded id.
     *
     * ⚠ **`unit_price` is D-A's frozen column and the freeze is NOT written here.** It is carried by
     * §8.3a's draft-only edit window, which is strictly stronger — every request the freeze would refuse,
     * the window already refuses, from every non-draft status — and by
     * `EditWindowGuardTest::theUnitPriceFreezeIsCarriedByTheEditWindowOnlyWhileTheWindowIsDraftOnly`,
     * which asserts the window of **every row naming this operation**. A guard written here as well would
     * be a second copy of a rule, which is the shape this project keeps finding; what is not acceptable is
     * the rule living in neither place, and the pin is what makes that impossible.
     */
    public function updateLine(string $orderId, string $lineId, UpdateSalesOrderLineInput $input): SalesOrderLineResource
    {
        $submitted = $input->submitted();
        $this->refuseMalformedLineFields($submitted, required: []);

        return $this->translatingConstraintFailures(fn (): SalesOrderLineResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($orderId, $lineId, $submitted): SalesOrderLineResource {
                SalesOrderRowLock::holdInside($em, $orderId);
                $order = $this->readOrderInside($em, $orderId) ?? throw ApiProblem::notFound();
                $this->refuseIfArchived($order);
                $this->refuseUnlessDraft($order);

                $line = $this->readLineInside($em, $orderId, $lineId) ?? throw ApiProblem::notFound();

                // A line whose order is a draft cannot itself be archived -- the trigger only ever
                // archives lines with their order -- but the check is written rather than argued, because
                // it is one `UPDATE` away from being false and §5.2a requires the refusal either way.
                if (null !== $line->archivedAt) {
                    throw self::lineArchived();
                }

                $this->refuseIfQuantityWouldFallBelowWhatIsFulfilled($line, $submitted);

                if ([] !== $submitted) {
                    $em->getConnection()->executeStatement(
                        'UPDATE sales_order_line SET '.implode(', ', array_map(
                            static fn (string $property): string => self::LINE_COLUMNS[$property].' = '.self::bind($property, self::LINE_CASTS),
                            array_keys($submitted),
                        )).', updated_at = now() WHERE id = CAST(:id AS uuid)',
                        [...$submitted, 'id' => $lineId],
                    );
                }

                $this->recomputeTotal($em, $orderId, self::totalPointerFor($submitted));

                return $this->readLineInside($em, $orderId, $lineId) ?? throw ApiProblem::notFound();
            },
        ));
    }

    /**
     * **`DeleteSalesOrderLine` — API-21 branch 3: 204, and no body.** `sales_order_line` is
     * `SchemaManifest::SOFT`, so this is an `UPDATE` of `deleted_at`, not a `DELETE`.
     *
     * ⚠ **Order before line, and that ordering is a deadlock fix rather than a style.** It is recorded
     * on the purchase twin: before it, that path locked the line (its own `UPDATE`) and then the order
     * (`recomputeTotal()`), which is the reverse of archiving the order — whose cascade trigger locks the
     * order and then its lines. The two orders deadlocked. This path takes the order's row lock first.
     */
    public function archiveLine(string $orderId, string $lineId): void
    {
        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($orderId, $lineId): void {
            SalesOrderRowLock::holdInside($em, $orderId);
            $order = $this->readOrderInside($em, $orderId) ?? throw ApiProblem::notFound();
            $this->refuseIfArchived($order);
            $this->refuseUnlessDraft($order);

            $line = $this->readLineInside($em, $orderId, $lineId) ?? throw ApiProblem::notFound();

            if (null !== $line->archivedAt) {
                throw self::lineArchived();
            }

            $em->getConnection()->executeStatement(
                'UPDATE sales_order_line SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid',
                [$lineId],
            );

            // No pointer: a `DELETE` carries no body, so there is no field to name, and archiving a line
            // can only lower a sum of non-negative products that already fitted.
            $this->recomputeTotal($em, $orderId, null);
        });
    }

    /**
     * **`DeleteSalesOrder` — API-21 branch 3: 204, and no body.** `sales_order` is `SchemaManifest::SOFT`, so this is
     * an `UPDATE` of `deleted_at`, never a `DELETE` — and `erp_app` holds no `DELETE` on the table, measured before and
     * after this operation was built. The database's `sales_order_cascade_deletion` trigger takes the LIVE lines with
     * the order; since `Version20260921000200` a line discarded on its own keeps its own stamp.
     *
     * **The order of the guards is `PurchaseOrderWriter::archive()`'s:** row lock first (the lock-first tripwire
     * demands it), then not-found, then archived (a repeat `DELETE` is refused here — `Idem = no`), then **L-8** — a
     * document is deletable only while it is a draft, so disposing of a draft and repudiating a commitment stay two
     * acts with two permissions. `sales_order_archived_only_while_draft` (CD-4, ruled 2026-09-21) is the same rule at
     * the database: widening this window would turn the first archived non-draft into a `23514`, loudly.
     *
     * ⚠ **`clock_timestamp()`, not `now()`, and it is not a style.** The cascade identifies the lines to restore by
     * "same stamp as the order", so an order's stamp must be DISTINCT from any line's that was archived on its own.
     * Every line archive holds this row lock and commits before it is granted here, and `clock_timestamp()` is read
     * after the lock is held, so the two instants differ except across a backward wall-clock step. `now()` would
     * collide only if both transactions STARTED in the same microsecond — negligible, and this removes even that.
     * Do not "normalise" it. `total_amount` is left as it was: the order's lines leave with it.
     *
     * **One `sales_order.deleted` row, naming the deleter** — B-4a (roles slice 2, C1, 2026-09-29), which closed
     * `RESIDUAL_GAPS.md` G-9 for both modules together. ⚠ Until then this said *"No audit row"*, held by an absence
     * pin that is now `SalesOrderDeleteTest::aDeletionWritesExactlyOneSalesOrderDeletedRow`.
     */
    public function archive(string $id): void
    {
        $identity = $this->identities->authenticated();

        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $identity): void {
            SalesOrderRowLock::holdInside($em, $id);
            $order = $this->readOrderInside($em, $id) ?? throw ApiProblem::notFound();

            $this->refuseIfArchived($order);
            $this->refuseUnlessDraft($order, \in_array($order->status, [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED], true)
                ? ' Cancel it instead: only a draft can be deleted.'
                : ' Only a draft can be deleted.');

            $em->getConnection()->executeStatement(
                'UPDATE sales_order SET deleted_at = clock_timestamp(), updated_at = now() WHERE id = ?::uuid',
                [$id],
            );

            // In this transaction: the archive and the row naming who did it commit together or not at all. A repeat
            // is refused by `refuseIfArchived()` above, so its rollback writes nothing.
            $this->audit->recordDeleted(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                salesOrderId: $id,
            );
        });
    }

    /**
     * **`RestoreSalesOrderLine` — `guard:sales_order_line.deleted_at`, so a repeat is refused by the
     * guard itself and no retry key is needed** (API-14 as amended demands the header for `store` and
     * `unique:` only).
     *
     * The two order-level guards run **in front**, and they are what answers the coherence objection the
     * purchase twin's processor records: a cascade-archived line has an archived order, so
     * `refuseIfArchived()` refuses it and a line can never be restored inside an order that is not. The
     * only restorable line is one deleted individually while its order was a live draft.
     *
     * ⚠ **The total must RISE here, so this passes a pointer where `archiveLine()` passes null.** A
     * restore adds a term back to the sum, which is the one thing archiving cannot do, and a null pointer
     * would send an overflow down `recomputeTotal()`'s `LogicException` branch — a 500 where the caller
     * should be told which order they cannot restore into.
     *
     * ⚠ **V2-R-12b's hazard is REAL on this table and this is where it is translated.**
     * `sales_order_line_tenant_idem_live` is partial on `deleted_at IS NULL`, so this sequence is
     * reachable and was measured: create line L with key K → delete L → create L2 with key K again (the
     * index no longer constrains L, and `lineCreatedByKeyInside()` looks only at live rows) → restore L
     * → **two live rows on one key → `23505`**. Untranslated that is an HTTP 500 on an operation the
     * caller cannot possibly have understood. {@see self::translatingConstraintFailures()} maps it by
     * name, and a test drives all four steps rather than asserting it in a docblock.
     */
    public function restoreLine(string $orderId, string $lineId): SalesOrderLineResource
    {
        return $this->translatingConstraintFailures(fn (): SalesOrderLineResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($orderId, $lineId): SalesOrderLineResource {
                SalesOrderRowLock::holdInside($em, $orderId);
                $order = $this->readOrderInside($em, $orderId) ?? throw ApiProblem::notFound();
                $this->refuseIfArchived($order);
                $this->refuseUnlessDraft($order);

                $line = $this->readLineInside($em, $orderId, $lineId) ?? throw ApiProblem::notFound();

                // The declared mechanism: a repeat finds a LIVE row and is refused here, with no key.
                if (null === $line->archivedAt) {
                    throw ApiProblem::businessRuleViolated(
                        'sales_order_line.is_not_archived',
                        'This line is not archived, so there is nothing to restore.',
                    );
                }

                $em->getConnection()->executeStatement(
                    'UPDATE sales_order_line SET deleted_at = NULL, updated_at = now() WHERE id = ?::uuid',
                    [$lineId],
                );

                $this->recomputeTotal($em, $orderId, self::POINTERS['unitPrice']);

                return $this->readLineInside($em, $orderId, $lineId) ?? throw ApiProblem::notFound();
            },
        ), [
            // ⚠ **BOTH uniques, and the line-number one was found by RUNNING the four-step test, not by
            // predicting it.** The hazard was written up as an idempotency-key collision; in fact
            // `sales_order_line_unique` fires FIRST, because `line_number` is minted as `MAX + 1` over
            // **live** lines, so the replacement line takes the archived line's number as well as its key.
            // Left untranslated the caller would have been told *"another line was added at the same
            // moment, try again"* — advice into a wall, since nothing about a retry can change it.
            self::LINE_NUMBER_INDEX => static fn (): ApiProblem => ApiProblem::businessRuleViolated(
                'sales_order_line.line_number_is_taken_by_a_live_line',
                'A newer line on this order now has this line\'s number, so this line cannot be restored. '
                .'Delete the newer line first, or add this line again.',
            ),
            self::LINE_IDEMPOTENCY_INDEX => static fn (): ApiProblem => ApiProblem::businessRuleViolated(
                'sales_order_line.retry_key_is_held_by_a_live_line',
                'A newer line on this order was created with the same retry key as this one, so this line cannot be '
                .'restored. Delete the newer line first, or add this line again.',
            ),
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * **`sales_order_line_qty_sane`, refused in DOMAIN terms before the statement rather than translated
     * after it — and the shape was chosen by measurement, not by symmetry.**
     *
     * The constraint is `CHECK (quantity > 0 AND quantity_fulfilled >= 0 AND quantity_fulfilled <=
     * quantity)`. Lowering `quantity` below `quantity_fulfilled` violates its third clause, which is
     * SQLSTATE **`23514`**.
     *
     * ⚠ **A translator was the obvious answer and it is NOT available.** `RED` measured on 2026-09-21
     * that DBAL's PostgreSQL `ExceptionConverter` maps `23502`, `23503` and `23505` and nothing else in
     * the `23` class, so `23514` falls through to a plain `DriverException` — catching it by name would
     * mean catching **every** database error and re-throwing the rest. It also measured that the purchase
     * twin never translated its own `purchase_order_line_not_over_received` either: `GoodsReceiptWriter`
     * pre-checks in domain terms and leaves the CHECK as a backstop. **This is that shape**, and it is
     * also what `SchemaManifest`'s G-7 note instructs `CreateFulfilment` to do for
     * `stock_level_reserved_sane`.
     *
     * **Unreachable today, and that premise has a known expiry.** Nothing under `src/` writes
     * `quantity_fulfilled` (`RESIDUAL_GAPS.md` **G-7**, whose trigger list names `CreateFulfilment`
     * first), so the column is always `0` and `malformedLineFields()`'s `quantity > 0` rule already
     * refuses everything this could. It is written now rather than recorded, because *"unreachable
     * today"* is precisely the premise that expires at `CreateFulfilment`, in a commit whose author will
     * be thinking about stock rather than about a two-module-old CHECK. The line is read under the
     * order's row lock already, so it costs no round trip.
     *
     * @param array<string, string|null> $submitted
     */
    private function refuseIfQuantityWouldFallBelowWhatIsFulfilled(SalesOrderLineResource $line, array $submitted): void
    {
        $quantity = $submitted['quantity'] ?? null;

        if (null === $quantity || bccomp($quantity, $line->quantityFulfilled, 6) >= 0) {
            return;
        }

        throw ApiProblem::businessRuleViolated(
            'sales_order_line.quantity_is_not_below_what_is_fulfilled',
            \sprintf('This line has already been fulfilled to %s, so its quantity cannot be lowered to %s.',
                $line->quantityFulfilled, $quantity),
        );
    }

    /** §5.2a's refusal: the row is readable, the write is not. */
    private static function lineArchived(): ApiProblem
    {
        return ApiProblem::businessRuleViolated(
            'sales_order_line.is_archived',
            'This line is archived. Restore it before changing it.',
        );
    }

    /**
     * Which field {@see self::recomputeTotal()}'s refusal names on a `PATCH`, where the request decides
     * what the caller sent.
     *
     * §5.2's `errors[]` addresses a field of **this** request, so naming `/unit_price` on a `PATCH` that
     * sent only `quantity` would point at nothing the caller could edit. `unitPrice` wins when both were
     * sent, matching `malformedLineFields()`'s own choice.
     *
     * Null when the `PATCH` moved neither: such a request cannot move the total at all, so the refusal is
     * unreachable — which `recomputeTotal()`'s own branch states rather than papering over with a
     * pointer it invented.
     *
     * @param array<string, string|null> $submitted
     */
    private static function totalPointerFor(array $submitted): ?string
    {
        foreach (['unitPrice', 'quantity'] as $property) {
            if (\array_key_exists($property, $submitted)) {
                return self::POINTERS[$property];
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------

    /**
     * One line's own rules, thrown rather than collected — the single-line path has one line to report on.
     *
     * @param array<string, string|null> $values
     * @param list<string>               $required
     */
    private function refuseMalformedLineFields(array $values, array $required): void
    {
        $errors = self::malformedLineFields($values, $required);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * **`sales_order.total_amount`, summed from the table — the one place the money rule is written.**
     *
     * Read, judged in PHP, then written with a bound value. **Never one
     * `UPDATE … SET total = (SELECT SUM(…))`** — that single statement was the raise site of a shipped
     * HTTP 500 on the purchase side, because `22003` (numeric field overflow) is neither constraint class
     * any translator catches. Do not rebuild it.
     *
     * Throwing here is a clean rollback and not the aborted-transaction hazard the class docblock warns
     * about: no statement has failed.
     *
     * @param ?string $pointer the JSON Pointer the refusal names — a field of **this** request, so the
     *                         caller is told what to lower. Null on the paths that cannot raise the total
     *                         at all; see the branch below.
     */
    private function recomputeTotal(EntityManagerInterface $em, string $orderId, ?string $pointer): void
    {
        $total = (string) $em->getConnection()->fetchOne(
            <<<'SQL'
            SELECT round(COALESCE(SUM(quantity * unit_price), 0), 4)
              FROM sales_order_line
             WHERE sales_order_id = ?::uuid AND deleted_at IS NULL
            SQL,
            [$orderId],
        );

        if (!self::fitsInTheTotalColumn($total)) {
            // ⚠ **A 500, and it is the right answer, because this branch is unreachable and a refusal
            // here would be a lie.** The paths that pass no pointer are `archiveLine()` -- a `DELETE` with
            // no request body, so there is no field to name -- and an `updateLine()` whose `PATCH` moved
            // neither the quantity nor the price. Every line product is non-negative (`QUANTITY` refuses
            // zero and a sign, `MONEY` refuses a sign) and the order's previous total is a value
            // numeric(19,4) already held, so removing a term can only lower a sum that already fitted.
            // Reaching here means one of those sentences stopped being true, which is a defect in this
            // class and not in the request.
            if (null === $pointer) {
                throw new \LogicException(\sprintf(
                    'The total of order %s left numeric(19,4) on a write that cannot raise it (%s). '
                    .'Either a line product became negative or the order was already storing a total it could not hold.',
                    $orderId, $total,
                ));
            }

            throw ApiProblem::validationFailed([[
                'pointer' => $pointer,
                'code' => 'total_out_of_range',
                'message' => self::TOTAL_OUT_OF_RANGE,
            ]]);
        }

        $em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE sales_order
               SET total_amount = CAST(? AS numeric),
                   updated_at = now()
             WHERE id = ?::uuid
            SQL,
            [$total, $orderId],
        );
    }

    /**
     * The line this key already created, or null — `orderCreatedByKeyInside()`'s rules, one table down.
     *
     * `deleted_at IS NULL` matches the index's partiality. A digest mismatch — another body, **or another
     * order**, since the path id is digested — is §6.2's 409, thrown before anything is written. A keyed
     * row without a digest cannot exist (`sales_order_line_request_digest_with_key`) and is answered as a
     * conflict anyway, failing closed.
     */
    private function lineCreatedByKeyInside(EntityManagerInterface $em, string $orderId, string $keyHash, string $digest): ?SalesOrderLineResource
    {
        $row = $em->getConnection()->fetchAssociative(
            'SELECT id, request_digest FROM sales_order_line WHERE idempotency_key = ? AND deleted_at IS NULL',
            [$keyHash],
        );

        if (false === $row) {
            return null;
        }

        if (!\is_string($row['request_digest']) || !hash_equals($row['request_digest'], $digest)) {
            throw ApiProblem::idempotencyKeyConflict();
        }

        // The digest carries the order id, so a match is a line of THIS order.
        return $this->readLineInside($em, $orderId, (string) $row['id'])
            ?? throw new \LogicException('A line found by its key is not readable in the same transaction.');
    }

    /**
     * D-B's digest of a single-line create: the **path's order id** plus the three accepted fields,
     * normalised as `digestedLines()` normalises a nested line (UUID canonical form, quantity at scale 6,
     * price at scale 4), so `"2"`, `"2.0"` and `"2.000000"` are one request.
     *
     * @param array<string, string|null> $values already shape-checked
     */
    private static function lineDigest(string $orderId, array $values): string
    {
        return RequestDigest::of(self::CREATE_LINE_OPERATION, [
            'sales_order_id' => RequestDigest::uuid($orderId),
            'inventory_item_id' => RequestDigest::uuid($values['inventoryItemId']),
            'quantity' => bcadd((string) $values['quantity'], '0', 6),
            'unit_price' => bcadd((string) $values['unitPrice'], '0', 4),
        ]);
    }

    /** One line of this order, by id, including an archived one — §5.2a: the refusal is the writer's. */
    private function readLineInside(EntityManagerInterface $em, string $orderId, string $lineId): ?SalesOrderLineResource
    {
        if (!Uuid::isValid($lineId)) {
            return null;
        }

        $row = $em->getConnection()->fetchAssociative(
            'SELECT '.SalesOrderLineRow::COLUMNS.' '.SalesOrderLineRow::FROM
            .' WHERE sol.sales_order_id = ?::uuid AND sol.id = ?::uuid',
            [...SalesOrderLineRow::columnParameters(), $orderId, $lineId],
        );

        return false === $row ? null : SalesOrderLineRow::toResource($row);
    }

    /**
     * **The line paths' constraint translation, and it is a PORT this commit paid for rather than found.**
     *
     * ⚠ The slice-4 design named `translatingConstraintFailures()` as if it already existed on this
     * class, in three separate sections. It did not: `grep` returned `PurchaseOrderWriter` and `ItemWriter`
     * only, and this side had a bare `catch (UniqueConstraintViolationException)` in `create()` and
     * {@see self::translatingUpdateConstraintFailures()} for the update path. The port is written here,
     * declared as unbudgeted work rather than smuggled in.
     *
     * Outside `TenantContext::run()`, never inside it: a failed statement leaves the Postgres transaction
     * aborted (25P02).
     *
     * @template T
     *
     * ⚠ **`$onCollision` exists because a unique violation means DIFFERENT THINGS on different paths, and
     * that was found by running the V2-R-12b test rather than by design.** On a create, both of this
     * table's uniques are **races**: two requests picking the same `MAX + 1`, or the same key committing
     * on another order between the lookup and the insert. *Try again* is sound advice, because a later
     * attempt reads different state. **On a restore neither is a race and *try again* is advice into a
     * wall** — the archived line's number and key are held by a live line that is not going anywhere, and
     * retrying will fail identically forever. The caller has to be told what to do instead.
     *
     * @param callable(): T                      $write
     * @param array<string, callable(): ApiProblem> $onCollision index name => how to answer a violation of
     *                                                            it. An index not named here falls through
     *                                                            to the create-path answers below
     *
     * @return T
     */
    private function translatingConstraintFailures(callable $write, array $onCollision = []): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            // Told apart BY NAME, and anything else is rethrown. Answering every 23505 on this table the
            // same way would hand a line-number race the idempotency answer, or the reverse.
            if (self::violated($e, self::LINE_IDEMPOTENCY_INDEX)) {
                // ⚠ **Two different requests reach this branch and they get DIFFERENT answers**, which is
                // why the restore is told apart rather than folded in.
                //
                // **`restoreLine()` — V2-R-12b.** The archived line being restored holds a retry key that
                // a LIVE line has since taken, because the index is partial on `deleted_at IS NULL`. The
                // caller did nothing wrong and no retry is involved, so `idempotency-key-conflict` would
                // name a mechanism they never used. It is a business rule about these two rows.
                if (isset($onCollision[self::LINE_IDEMPOTENCY_INDEX])) {
                    throw $onCollision[self::LINE_IDEMPOTENCY_INDEX]();
                }

                // **`createLine()`.** The same key committed on ANOTHER order between this request's key
                // lookup and its INSERT: a same-order retry would have waited at the order's row lock and
                // then found the line. Another order is in the digest, so this is a key reused for a
                // different request -- §6.2's 409. "Try again" would be advice into a wall: the key is
                // spent.
                throw ApiProblem::idempotencyKeyConflict();
            }

            if (self::violated($e, self::LINE_NUMBER_INDEX)) {
                if (isset($onCollision[self::LINE_NUMBER_INDEX])) {
                    throw $onCollision[self::LINE_NUMBER_INDEX]();
                }

                // Two concurrent creates picking the same MAX + 1. A genuine conflict rather than a client
                // mistake; the answer is to retry.
                throw ApiProblem::businessRuleViolated(
                    'sales_order_line.line_number_is_unique_per_order',
                    'Another line was added to this order at the same moment. Try again.',
                );
            }

            throw $e;
        } catch (ForeignKeyConstraintViolationException) {
            // `sales_order_line`'s two foreign keys are composite on `(tenant_id, id)`, so an id belonging
            // to another tenant fails here rather than at a lookup -- and ST-5 means the caller must not
            // learn which of "wrong tenant" and "no such row" it was.
            throw ApiProblem::validationFailed([
                self::error('inventoryItemId', 'unknown_reference', 'One of the ids sent names no row in this workspace.'),
            ]);
        }
    }

    private function refuseMalformedUpdateFields(array $values): void
    {
        $errors = [];

        foreach (array_keys(self::POINTERS) as $property) {
            if (!\array_key_exists($property, $values)) {
                continue;
            }

            if (null === $values[$property]) {
                $errors[] = self::error($property, 'not_nullable', 'This field may not be cleared.');
            } elseif (!Uuid::isValid($values[$property])) {
                $errors[] = self::error($property, 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.');
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * ST-5's oracle for whichever parents this edit names, and the update-side twin of
     * {@see self::refuseUnresolvableCreateReferences()} — the same two statements, so a submitted
     * `customer_id` is read `FOR KEY SHARE` here too (C5 C3), after the order's row lock.
     *
     * The address is **not** checked here — {@see self::shipToInside()} owns it, because its refusal
     * depends on a `customer_id` and would otherwise be a second copy of that rule.
     *
     * @param array<string, string|null> $values
     */
    private function refuseUnresolvableUpdateReferences(EntityManagerInterface $em, array $values): void
    {
        $connection = $em->getConnection();
        $errors = [];

        foreach (self::PARENT_LIVENESS_READS as $property => [$table, $sql]) {
            if (!\array_key_exists($property, $values)) {
                continue;
            }

            if (false === $connection->fetchOne($sql, [$values[$property]])) {
                $errors[] = self::error($property, 'unknown_reference', 'No live '.$table.' in this workspace has this id.');
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    private function refuseIfArchived(SalesOrderResource $order): void
    {
        if (null !== $order->archivedAt) {
            throw ApiProblem::businessRuleViolated(
                'sales_order.is_archived',
                'This sales order is archived, so it can\'t be changed.',
            );
        }
    }

    /**
     * **The edit window, in the one place it is written — `API.md` §8.3a's `SalesOrder` row.**
     *
     * This is owner ruling **O-2**'s named guard. The legal status is read off the entity's own constant
     * rather than spelled as a literal, so a rename of `STATUS_DRAFT`'s value moves this rule with it —
     * the discipline API-15 requires of a transition guard, required here by §8.3a clause (c).
     */
    private function refuseUnlessDraft(SalesOrderResource $order, string $instead = ''): void
    {
        if (SalesOrder::STATUS_DRAFT !== $order->status) {
            // The status is NAMED, never assumed. Until 2026-09-21 this said *"has been confirmed"* of every
            // non-draft — false of a `cancelled` order, which `cancel()` admits from `draft` (`RED`, sales slice 5).
            throw ApiProblem::businessRuleViolated(
                'sales_order.is_editable_only_while_draft',
                \sprintf('This sales order is %s, not a draft, so it is read-only except for its transitions.%s', $order->status, $instead),
            );
        }
    }

    /**
     * The update path's constraint translation, outside `TenantContext::run()` for the class docblock's
     * reason: a failed statement leaves the Postgres transaction aborted (25P02).
     *
     * Only the foreign keys are reachable here — the update touches neither unique index — and both are
     * composite on `(tenant_id, id)`, so an id belonging to another tenant fails here rather than at the
     * lookup above. ST-5 means the caller must not learn which of *"wrong tenant"* and *"no such row"* it
     * was.
     */
    private function translatingUpdateConstraintFailures(callable $write): SalesOrderResource
    {
        try {
            return $write();
        } catch (ForeignKeyConstraintViolationException) {
            throw ApiProblem::validationFailed([
                self::error('customerId', 'unknown_reference', 'One of the ids sent names no row in this workspace.'),
                self::error('warehouseId', 'unknown_reference', 'One of the ids sent names no row in this workspace.'),
            ]);
        }
    }

    /**
     * One line of the nested create.
     *
     * **There is no server-derived column here, and that is the fact
     * {@see self::insertOrderInside()}'s total depends on.** `quantity_fulfilled` defaults to `0` in the
     * schema and is not written; everything else comes from the request.
     *
     * **Both write paths enter here**, and `$index` is what tells them apart. `CreateSalesOrder`'s nested
     * `lines[]` passes the line's 0-based position, which is the JSON Pointer base — `/lines/0/quantity`;
     * `CreateSalesOrderLine` passes **null**, pointing its errors at `/quantity` instead, because its
     * request is one line and has no array to index into.
     *
     * **`$keyHash` and `$digest` are the single-line path's retry key (V2-R-12) and are both null on the
     * nested path**: that request's key is the order's, and N lines sharing it would collide on
     * `sales_order_line_tenant_idem_live`. This is the whole reason `Version20260921000100`'s columns are
     * nullable — see that migration, and note that it inverts `sales_order`'s own decision deliberately.
     *
     * @param array<string, string|null> $values already shape-checked
     */
    private function insertLineInside(
        EntityManagerInterface $em,
        string $orderId,
        array $values,
        ?int $index,
        ?string $keyHash = null,
        ?string $digest = null,
    ): string {
        $connection = $em->getConnection();
        $itemId = (string) $values['inventoryItemId'];

        // ST-5's oracle: `inventory_item` is tenant-scoped, so this lookup is bounded by RLS and an id
        // belonging to another tenant is indistinguishable from one that never existed. That is why it is a
        // `validation-failed` on the *field* rather than a `not-found` on a row the caller never addressed.
        if (false === $connection->fetchOne('SELECT 1 FROM inventory_item WHERE id = ?::uuid AND deleted_at IS NULL', [$itemId])) {
            throw ApiProblem::validationFailed([
                null === $index
                    ? self::error('inventoryItemId', 'unknown_reference', 'No live inventory item in this workspace has this id.')
                    : self::lineError($index, 'inventoryItemId', 'unknown_reference', 'No live inventory item in this workspace has this id.'),
            ]);
        }

        $id = Uuid::v4()->toRfc4122();

        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO sales_order_line
                (id, tenant_id, sales_order_id, inventory_item_id, line_number, quantity, unit_price,
                 idempotency_key, request_digest)
            VALUES
                (?::uuid, ?::uuid, ?::uuid, ?::uuid,
                 -- The next free number on this order. `line_number` is NOT NULL, §4.2 accepts no field for
                 -- it, and `sales_order_line_unique` is partial on `deleted_at IS NULL` -- so a discarded
                 -- number is reusable and this MAX is over the live lines only, which is the same set the
                 -- index constrains. It is `$index + 1` on this path, the order being new; it is written as
                 -- the MAX because that is what `CreateSalesOrderLine` will need and one shape is better
                 -- than two.
                 (SELECT COALESCE(MAX(line_number), 0) + 1
                    FROM sales_order_line
                   WHERE sales_order_id = ?::uuid AND deleted_at IS NULL),
                 ?::numeric, ?::numeric, ?, ?)
            SQL,
            [
                $id,
                // Bound explicitly rather than read back out of the GUC, so a mismatch with the
                // transaction's tenant is refused by the RLS WITH CHECK clause rather than accepted.
                $this->tenant->tenantId(),
                $orderId,
                $itemId,
                $orderId,
                $values['quantity'],
                $values['unitPrice'],
                $keyHash,
                $digest,
            ],
        );

        return $id;
    }

    /**
     * **V2-S-9, O-2 and ST-5 in one statement.**
     *
     * The address must exist, **belong to the customer the order names**, and be a `shipping` address. All
     * three are predicates of the same `SELECT`, so there is one refusal and one round trip. The four
     * columns it returns are the snapshot.
     *
     * **It is an application check and a composite foreign key was rejected** (2026-09-19): this schema has
     * no three-column foreign key, `customer_address` carries no unique for one to reference, a `soft` row
     * may not reference a `hard` one (`SchemaManifest.php:70-71`), and `DeleteCustomerAddress` is a declared
     * operation the key would break forever.
     *
     * ⚠ **The residual this leaves is `RESIDUAL_GAPS.md` F-10, and the OWNER accepted it on 2026-09-19**
     * (`docs/session11/output9-sales-1-ruling.md`, section *"⚖ OWNER RULING — O-1 and F-10, 2026-09-19"*):
     * the check runs once, at create, and leaves **no residue** — the order stores no address id — so
     * nothing can ever detect afterwards that a snapshot came from another customer's address. Its trigger
     * is *the second writer of `sales_order.ship_to_*`*, because a second writer is the first thing that
     * could disagree with this one. **Both offered mitigations were refused by that ruling**: an audit
     * event (new scope, and `CreatePurchaseOrder` writes none either) and an id column with no foreign key
     * (whose only protection is a docblock — the promise-in-prose shape this session rejected twice — and
     * which proves identity, never content, so it would not catch this bug class anyway). **Do not add
     * either.**
     *
     * `region` is **not** snapshotted: `customer_address.region` exists and `sales_order` has no column for
     * it. Recorded as an accepted omission (O-5) rather than discovered later.
     *
     * @return array{line: string, city: string, postalCode: string, countryCode: string}
     */
    private function shipToInside(EntityManagerInterface $em, string $customerId, string $addressId): array
    {
        $row = $em->getConnection()->fetchAssociative(
            <<<'SQL'
            SELECT address_line, city, postal_code, country_code
              FROM customer_address
             WHERE id = ?::uuid AND customer_id = ?::uuid AND kind = 'shipping'
            SQL,
            [$addressId, $customerId],
        );

        if (false === $row) {
            // One refusal for all three failures, and that is ST-5 rather than laziness: an address of
            // another customer, an address of another tenant, a billing address and an id that never existed
            // must be indistinguishable, or the answer is an oracle over the workspace's address book.
            throw ApiProblem::validationFailed([
                self::error('shipToAddressId', 'unknown_reference',
                    'No shipping address of this customer has this id.'),
            ]);
        }

        return [
            'line' => (string) $row['address_line'],
            'city' => (string) $row['city'],
            'postalCode' => (string) $row['postal_code'],
            'countryCode' => (string) $row['country_code'],
        ];
    }

    /**
     * The next free document number for this workspace.
     *
     * Over **every** row, archived ones included — **the owner's Q4 ruling (2026-09-21): a deleted order's
     * number is never issued again.** Until then this counted live rows only, matching
     * `sales_order_tenant_number_live`'s partiality, so discarding the newest order handed its number to the
     * next one; the two then shared a number for good and the archived one could never be restored (that
     * unique refuses it). The unique stays partial — `SchemaManifest` requires it on a soft table — and is now
     * a race guard only. `MAX` over all rows cannot use the partial index; at this scale that is a scan of one
     * tenant's orders under RLS, and it is said here so nobody "restores" the predicate for speed.
     * `SalesOrderCreateTest::aDiscardedOrdersNumberIsNeverIssuedAgain` holds it.
     *
     * Bounded to numbers this method could have minted. The regexp was written to stop a hand-seeded
     * `SO-CASCADE` turning the cast into an error; since CD-7 (`sales_order_number_shape`, 2026-09-28) no such
     * row can exist and no test seeds one, so the regexp is now a belt over the CHECK and is kept because it
     * costs nothing. RLS supplies the tenant bound, so no `tenant_id` predicate is written here.
     *
     * **`PurchaseOrderWriter::nextOrderNumber()` does the same since the owner extended Q4 to purchasing
     * (2026-09-21).** Until then it counted live rows only, and its twin was held for the owner.
     */
    private function nextOrderNumber(EntityManagerInterface $em): string
    {
        $next = (int) $em->getConnection()->fetchOne(<<<'SQL'
            SELECT COALESCE(MAX(substring(order_number from 4)::bigint), 0) + 1
              FROM sales_order
             WHERE order_number ~ '^SO-[0-9]+$'
            SQL);

        // CD-7 (owner ruling CD7-1, 2026-09-28): `sales_order_number_shape` pins the number to exactly six digits, so the
        // millionth is refused here in domain terms rather than minted seven digits wide (which text-sorts beside
        // `…100000`) or left to the CHECK as a 23514 -> 500. Counted over every row, archived ones included (Q4:
        // numbers are never reissued). ⚠ This runs from the INSERT, i.e. AFTER the replay read, deliberately: a
        // retry of the 999,999th create must still get its own document back (D-B) -- do not hoist it into an
        // early pre-flight.
        if ($next > 999999) {
            throw ApiProblem::businessRuleViolated(
                'sales_order.number_series_is_exhausted',
                'This workspace has used every sales order number from SO-000001 to SO-999999, so no further order can be raised. Numbers are never reissued.',
            );
        }

        return 'SO-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The order this key already created, or null if it has created none.
     *
     * Read **inside the caller's transaction**, which is what makes it the replay answer rather than a
     * guess. `deleted_at IS NULL` matches the index's own partiality: an archived order does not answer a
     * replay, and that is correct — the index no longer holds its key.
     *
     * **D-B: a key is a replay only if the request is the same one.** A mismatch is §6.2's
     * `idempotency-key-conflict` (409), thrown before anything is written. A keyed row with **no** digest
     * cannot prove the requests match and is answered as a conflict too — failing closed. On this table the
     * branch is unreachable, both columns being `NOT NULL`; it is written rather than argued.
     *
     * The comparison is against the digest, **never against the row**: a later `UpdateSalesOrder` may have
     * moved the draft, and a late retry of a request that succeeded is still that request.
     */
    private function orderCreatedByKeyInside(EntityManagerInterface $em, string $keyHash, string $digest): ?SalesOrderResource
    {
        $row = $em->getConnection()->fetchAssociative(
            'SELECT id, request_digest FROM sales_order WHERE idempotency_key = ? AND deleted_at IS NULL',
            [$keyHash],
        );

        if (false === $row) {
            return null;
        }

        if (!\is_string($row['request_digest']) || !hash_equals($row['request_digest'], $digest)) {
            throw ApiProblem::idempotencyKeyConflict(
                'The lines are compared in the order they were sent, because a line\'s number comes from its position.',
            );
        }

        return $this->readOrderInside($em, (string) $row['id']);
    }

    private function readOrderInside(EntityManagerInterface $em, string $id): ?SalesOrderResource
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $row = $em->getConnection()->fetchAssociative(
            'SELECT '.SalesOrderRow::COLUMNS.' '.SalesOrderRow::FROM.' WHERE so.id = ?::uuid',
            [$id],
        );

        return false === $row ? null : SalesOrderRow::toResource($row);
    }

    // -----------------------------------------------------------------------
    // Shape, and only shape. Everything here is decidable without the database,
    // so it runs before a transaction is opened.
    // -----------------------------------------------------------------------

    /**
     * `CreateSalesOrder`'s shape rules.
     *
     * Three ids are required. `ship_to_address_id` is among them because
     * `sales_order.ship_to_country_code` is `NOT NULL` with **no default** — see `CreateSalesOrderInput` —
     * so an order with no address is a row the database refuses, and a named field error is a better answer
     * than a 500. `currency_code` is optional and falls back to the workspace's (E4-3).
     *
     * @param array<string, string|null> $values
     */
    private function refuseMalformedCreateFields(array $values): void
    {
        $errors = [];

        foreach (['customerId', 'warehouseId', 'shipToAddressId'] as $property) {
            if (null === ($values[$property] ?? null)) {
                $errors[] = self::error($property, 'required', 'This field is required.');
            } elseif (!Uuid::isValid($values[$property])) {
                $errors[] = self::error($property, 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.');
            }
        }

        // The `currency` table's primary key is a three-letter ISO 4217 code. Checked for shape here so a
        // malformed value is a named field defect rather than a foreign-key lookup that answers "unknown".
        $currency = $values['currencyCode'] ?? null;
        if (null !== $currency && 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors[] = self::error('currencyCode', 'not_a_currency_code', 'This field must be a three-letter ISO 4217 code.');
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * **ST-5's oracle, applied to the two tenant-scoped parents and the currency.**
     *
     * Resolved by lookup rather than left to the foreign keys, for the reason the purchasing create does it
     * that way: both tables are bounded by RLS, so an id belonging to another tenant is indistinguishable
     * from one that never existed, and the caller learns neither. A composite-FK violation would produce the
     * same refusal with no pointer at the field that caused it, and §5.2 wants the field named.
     *
     * The address is **not** checked here — {@see self::shipToInside()} owns it, because its refusal depends
     * on `customer_id` and would be a second copy of that rule.
     *
     * `currency` is **reference data** (ADR-002 §6) and is not tenant-scoped, so its lookup is an existence
     * check and discloses nothing.
     *
     * **The customer read takes `FOR KEY SHARE`; the warehouse read takes no lock** (C5 C3, owner ruling Q-2 of
     * 2026-10-13, `docs/session13/session29-c5-ruling.md` — the sales twin of Q-S2's *"supplier-only lock"*).
     * `DeleteCustomer` (C5 C4b) archives a customer under `FOR UPDATE` and refuses one with an open sales
     * order; KEY SHARE is the one lock that conflicts with that `FOR UPDATE` and with nothing `UpdateCustomer`
     * takes (`FOR NO KEY UPDATE`), so a create or an edit naming the customer either commits first and is
     * counted by the delete, or waits and then re-reads `deleted_at IS NULL` and is refused. Legal because
     * `erp_app` holds column UPDATE on `customer` (`Version20261009000300`; ADR-002 A4.3 constraint 1) —
     * narrowing that grant to no column makes this read 42501
     * (`GrantFootprintTest::theApplicationMayTakeThePartyRowLocksOnCustomer`). On the edit the order's
     * `SalesOrderRowLock` is taken first ({@see self::update()}), then this KEY SHARE; the customer writers
     * lock only the customer row, so no cycle. The warehouse branch stays unlocked by the same ruling —
     * locking it is `DeleteWarehouse`'s to order (API-O-34, G-7; carried by
     * `OwedAmendmentTripwireTest::OPERATIONS_OWING_THE_ARCHIVE_RULES`).
     *
     * @param array<string, string|null> $values
     */
    private function refuseUnresolvableCreateReferences(EntityManagerInterface $em, array $values): void
    {
        $connection = $em->getConnection();
        $errors = [];

        foreach (self::PARENT_LIVENESS_READS as $property => [$table, $sql]) {
            if (false === $connection->fetchOne($sql, [$values[$property]])) {
                $errors[] = self::error($property, 'unknown_reference', 'No live '.$table.' in this workspace has this id.');
            }
        }

        if (null !== ($values['currencyCode'] ?? null)
            && false === $connection->fetchOne('SELECT 1 FROM currency WHERE code = ?', [$values['currencyCode']])
        ) {
            $errors[] = self::error('currencyCode', 'unknown_reference', 'This is not a currency this system knows.');
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * The two parent liveness reads both create and edit make, one statement each (see
     * {@see self::refuseUnresolvableCreateReferences()}): the customer's locks `FOR KEY SHARE` (C5 C3, owner
     * Q-2), the warehouse's locks nothing.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PARENT_LIVENESS_READS = [
        'customerId' => ['customer', 'SELECT 1 FROM customer WHERE id = ?::uuid AND deleted_at IS NULL FOR KEY SHARE'],
        'warehouseId' => ['warehouse', 'SELECT 1 FROM warehouse WHERE id = ?::uuid AND deleted_at IS NULL'],
    ];

    /**
     * **§4.2's `lines[]` sub-fields, as the client spells them.**
     *
     * Derived from `POINTERS` rather than written out a second time, so the set the writer accepts and the
     * pointers it refuses with cannot disagree.
     *
     * @return list<string>
     */
    public static function nestedLineFields(): array
    {
        return array_map(static fn (string $property): string => ltrim(self::POINTERS[$property], '/'), self::NESTED_LINE_PROPERTIES);
    }

    /**
     * **The shape of `lines`, decidable without the database**, so it runs before a transaction is opened.
     *
     * Returns null when the field was **absent**, which is the empty draft and is legal; every other answer
     * is a non-empty list.
     *
     * ⚠ **`lines: []` is refused**, and the reason is not only that an order with nothing on it says nothing
     * omitting the field does not say. In canonical JSON an empty list and an empty object both encode as
     * `[]`, so admitting an empty list would put a value into the digest that cannot be told from a
     * different one. **Refusing an empty list at create is not the same rule as refusing to confirm an empty
     * order** — that one is V2-R-22 and is still open.
     *
     * Shape errors are collected across **every** line, because they are one problem type; the database
     * rules are not, and {@see self::insertLineInside()} throws at the first.
     *
     * @param array<mixed>|null $raw
     *
     * @return list<array<string, string|null>>|null
     */
    private static function nestedLines(?array $raw): ?array
    {
        if (null === $raw) {
            return null;
        }

        if ([] === $raw) {
            throw ApiProblem::validationFailed([[
                'pointer' => '/lines', 'code' => 'at_least_one_line',
                'message' => 'A sales order raised with lines must carry at least one. Omit the field to raise an empty draft.',
            ]]);
        }

        if (!array_is_list($raw)) {
            throw ApiProblem::validationFailed([[
                'pointer' => '/lines', 'code' => 'not_a_list', 'message' => 'This field must be a list of lines.',
            ]]);
        }

        if (\count($raw) > self::MAXIMUM_LINES) {
            throw ApiProblem::validationFailed([[
                'pointer' => '/lines', 'code' => 'too_many_lines',
                'message' => \sprintf('A sales order may be raised with at most %d lines in one request. '
                    .'Raise it with the first %d and add the rest through the lines sub-resource.', self::MAXIMUM_LINES, self::MAXIMUM_LINES),
            ]]);
        }

        $accepted = self::nestedLineFields();
        $errors = [];
        $lines = [];
        $total = '0.0000';

        foreach ($raw as $index => $line) {
            // An empty JSON object decodes to `[]`, which is also a list -- so an empty object is admitted
            // here and refused one clause down as three missing required fields, which is what it is.
            if (!\is_array($line) || array_is_list($line) && [] !== $line) {
                $errors[] = [
                    'pointer' => '/lines/'.$index, 'code' => 'not_an_object', 'message' => 'Each line must be an object.',
                ];
                continue;
            }

            $before = \count($errors);

            // API-9 at the nesting level the deserializer cannot reach: `lines` arrives as a decoded array,
            // so `additionalProperties: false` says nothing about its entries.
            foreach (array_diff(array_keys($line), $accepted) as $unknown) {
                $errors[] = [
                    'pointer' => '/lines/'.$index.'/'.$unknown, 'code' => 'undeclared', 'message' => 'This field is not accepted.',
                ];
            }

            // An unknown key does NOT stop this line's own fields being checked -- only a value that could
            // not be READ does, because a check on a value that is not there is a second, meaningless error
            // about the same defect.
            $unreadable = [];
            $values = [];
            foreach (self::NESTED_LINE_PROPERTIES as $property) {
                $value = $line[ltrim(self::POINTERS[$property], '/')] ?? null;

                // The top-level fields are typed by the input DTO; these are not, so a number where a
                // decimal string belongs is named here rather than coerced. S-12: money and quantities are
                // strings. **Load-bearing against a 500:** under `strict_types` an int reaching
                // `preg_match()`'s string parameter is a `TypeError`.
                if (null !== $value && !\is_string($value)) {
                    $errors[] = self::lineError($index, $property, 'not_a_string', 'This field must be sent as a string.');
                    $unreadable[] = $property;
                    continue;
                }

                $values[$property] = $value;
            }

            foreach (self::malformedLineFields($values, array_values(array_diff(self::NESTED_LINE_PROPERTIES, $unreadable))) as $error) {
                $error['pointer'] = '/lines/'.$index.$error['pointer'];
                $errors[] = $error;
            }

            if (\count($errors) > $before) {
                continue;
            }

            // The case the per-line bound cannot see: every line fits and the SUM does not. Accumulated over
            // the lines that are themselves sound, and reported against the one that tipped it -- the only
            // line the caller can act on. **This is also what keeps the VALUES-list total inside
            // numeric(19,4)**: without it the SUM would raise 22003 inside the order's own INSERT, which is
            // neither of the unique violations `create()` names and would reach the client as a 500.
            //
            // ⚠ **Scale 10, then one rounding, because that is what the database does** (RED exit audit
            // F-2). `quantity` is numeric(19,6) and `unit_price` numeric(19,4), so a product is exact at
            // scale 10 and bcmath's scale argument **truncates**: accumulating at 4 threw away up to
            // 0.0001 per line while Postgres sums the VALUES list at full precision and ROUNDS the cast
            // into numeric(19,4). Two lines were enough to make the guard pass and the INSERT raise 22003
            // -- measured over HTTP.
            // `SalesOrderCreateTest::aTotalThatOverflowsOnlyWhenTheDatabaseRoundsIsRefusedRatherThanAnsweringA500()`
            // holds this; the guard is now an upper bound on the stored total, never below it.
            $total = bcadd($total, bcmul((string) $values['quantity'], (string) $values['unitPrice'], self::PRODUCT_SCALE), self::PRODUCT_SCALE);
            if (!self::fitsInTheTotalColumn(self::roundedToMoney($total))) {
                $errors[] = self::lineError($index, 'unitPrice', 'total_out_of_range', self::TOTAL_OUT_OF_RANGE);
                continue;
            }

            $lines[] = $values;
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $lines;
    }

    /**
     * One line's own rules, returned rather than thrown so the nested create can collect them across every
     * line and re-point each one at `/lines/<n>/<field>`.
     *
     * @param array<string, string|null> $values
     * @param list<string>               $required
     *
     * @return list<array{pointer: string, code: string, message: string}>
     */
    private static function malformedLineFields(array $values, array $required): array
    {
        $errors = [];

        foreach ($required as $property) {
            if (null === ($values[$property] ?? null)) {
                $errors[] = self::error($property, 'required', 'This field is required.');
            }
        }

        if (null !== ($values['inventoryItemId'] ?? null) && !Uuid::isValid($values['inventoryItemId'])) {
            $errors[] = self::error('inventoryItemId', 'not_a_uuid', 'This field must be a UUID naming an item in this workspace.');
        }

        // `sales_order_line_qty_sane` is `quantity > 0`, so zero and negative are refused here rather than at
        // the database -- a CHECK violation is a 500 with no pointer, and §5.2 wants the field named.
        $quantity = $values['quantity'] ?? null;
        if (null !== $quantity && (1 !== preg_match(self::QUANTITY, $quantity) || 0 === bccomp($quantity, '0', 6))) {
            $errors[] = self::error('quantity', 'not_a_positive_decimal', 'This field must be a positive decimal string with at most 13 integer digits and 6 fractional digits.');
        }

        $unitPrice = $values['unitPrice'] ?? null;
        if (null !== $unitPrice && 1 !== preg_match(self::MONEY, $unitPrice)) {
            $errors[] = self::error('unitPrice', 'not_a_decimal', 'This field must be a non-negative decimal string with at most 15 integer digits and 4 fractional digits.');
        }

        // Two fields each inside their own column can still produce a total that is not: `quantity` admits
        // 13 integer digits and `unit_price` 15, and their product lands in `sales_order.total_amount`,
        // which is numeric(19,4) -- fifteen. **Rounded, not truncated**, for the reason `nestedLines()`
        // records: the database rounds, so a guard that truncates is not a bound.
        if ([] === $errors && null !== $quantity && null !== $unitPrice
            && !self::fitsInTheTotalColumn(self::roundedToMoney(bcmul($quantity, $unitPrice, self::PRODUCT_SCALE)))) {
            $errors[] = self::error('unitPrice', 'total_out_of_range', self::TOTAL_OUT_OF_RANGE);
        }

        return $errors;
    }

    /**
     * Whether a money value fits `sales_order.total_amount` — `numeric(19,4)`, so fifteen integer digits.
     *
     * **The bound is `MONEY` itself**, the expression that already states that column family's shape for
     * values arriving, rather than a second literal fifteen. It therefore takes a value **already rounded
     * to scale 4** — `MONEY` admits at most four fractional digits, so a scale-10 accumulator handed
     * straight to it would be refused for its scale rather than judged on its magnitude.
     */
    private static function fitsInTheTotalColumn(string $value): bool
    {
        return 1 === preg_match(self::MONEY, $value);
    }

    /**
     * A scale-10 running total as `numeric(19,4)` will store it: **rounded half away from zero**, which is
     * Postgres's own rule for the cast, rather than bcmath's truncation.
     *
     * Adding half a unit in the last place and letting `bcadd`'s scale argument truncate is round-half-up;
     * the sign test makes it half-away-from-zero, which costs one comparison and means this does not become
     * wrong the day a credit line is admitted. Values here are non-negative today (`MONEY` and `QUANTITY`
     * both refuse a sign), so that branch is unreached and deliberately so.
     */
    private static function roundedToMoney(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.00005' : '0.00005', 4);
    }

    /**
     * The lines as §6.2 digests them — **in the order they were sent**, because `line_number` is minted from
     * the position and two lines the other way round are a different order.
     *
     * Quantities at scale 6 and prices at scale 4, the columns' own scales, so `"2"`, `"2.0"` and
     * `"2.000000"` are one request.
     *
     * @param list<array<string, string|null>> $lines
     *
     * @return list<array<string, string|null>>
     */
    private static function digestedLines(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'inventory_item_id' => RequestDigest::uuid($line['inventoryItemId']),
            'quantity' => bcadd((string) $line['quantity'], '0', 6),
            'unit_price' => bcadd((string) $line['unitPrice'], '0', 4),
        ], $lines);
    }

    /**
     * Whether a unique violation names a particular index.
     *
     * Read out of the driver's message because that is where Postgres puts it — `duplicate key value
     * violates unique constraint "<name>"` — and DBAL's exception carries no structured constraint name.
     */
    private static function violated(UniqueConstraintViolationException $e, string $index): bool
    {
        return str_contains($e->getMessage(), $index);
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function bind(string $property, array $casts): string
    {
        return \sprintf('CAST(:%s AS %s)', $property, $casts[$property]);
    }

    private static function error(string $property, string $code, string $message): array
    {
        return ['pointer' => self::POINTERS[$property], 'code' => $code, 'message' => $message];
    }

    /**
     * The same refusal, addressed at a line of `lines[]`. **0-based**, matching the purchasing side and the
     * array index the pointer names.
     *
     * @return array{pointer: string, code: string, message: string}
     */
    private static function lineError(int $index, string $property, string $code, string $message): array
    {
        return ['pointer' => '/lines/'.$index.self::POINTERS[$property], 'code' => $code, 'message' => $message];
    }
}
