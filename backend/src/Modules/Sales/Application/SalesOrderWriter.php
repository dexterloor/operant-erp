<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\ApiResource\CreateSalesOrderInput;
use App\Modules\Sales\ApiResource\SalesOrderResource;
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

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private WorkspaceSettings $settings,
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
     * `total_amount` is computed **inside this `INSERT`**, and the expression is throwaway
     * ===========================================================================
     * `sales_order.total_amount` is `numeric(19,4) NOT NULL DEFAULT 0` and no document says who maintains
     * it. The purchasing side recomputes it with an `UPDATE` after the lines land; **this side cannot, and
     * must not** — ruling R-B grants `INSERT` only, so the total is summed from a `VALUES` list of the
     * request's own lines in the order's own `INSERT`. It is `numeric` throughout, never a float, so S-12
     * is satisfied and the result is numerically identical to summing the stored rows.
     *
     * **It is sound only because no `sales_order_line` column is server-derived.** The line's columns are
     * `inventory_item_id`, `line_number`, `quantity`, `quantity_fulfilled` (default 0) and `unit_price` —
     * all from the request or defaulted (`Version20260906000100.php:964-981`). The purchase side's line
     * snapshots `lead_time_days` from the supplier catalogue, so a `VALUES` total would be wrong there.
     * **If a sales line ever gains a server-derived money column, this expression is wrong and the total
     * must come from the table** — which needs `UPDATE`, which needs a migration.
     *
     * **⚠ This expression is deliberate throwaway code, dead the day `CreateSalesOrderLine` ships.** That
     * operation must recompute from the table, because a line added to an existing order is not in any
     * request this method saw; so the commit that adds it grants `UPDATE`, writes one `recomputeTotal()`,
     * and **deletes {@see self::insertOrderInside()}'s two statement shapes down to one**. Saying so here is
     * what stops it becoming the second copy of a money rule — the thing that makes two totals drift.
     *
     * **Two statement shapes rather than one**, because an empty `VALUES` list is a **syntax error** in
     * Postgres. The empty-draft path takes a literal `0`. The alternative — a synthetic `(0, 0)` row inside
     * a money computation — is the dishonest one: it puts a fake line into an arithmetic expression to
     * avoid an `if`.
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
     * `MAX + 1` over the tenant's live rows, matching the partial unique, is `nextOrderNumber()`'s shape one
     * module over. **Reported as a decision taken here rather than presented as derived.**
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

                    $this->insertOrderInside($em, $id, $values, $shipTo, $currencyCode, $createdBy, $keyHash, $digest, $lines);

                    // D-C's shape: the lines, in the SAME transaction as the order. Any refusal below rolls
                    // the order back with them -- there is no savepoint and none is wanted, because a create
                    // that kept the order and dropped a line is the headless draft this step exists to make
                    // impossible.
                    foreach ($lines ?? [] as $index => $line) {
                        $this->insertLineInside($em, $id, $line, $index);
                    }

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
     * The order's `INSERT`, in the **two shapes** ruling R-B's `INSERT`-only grant forces.
     *
     * With lines, the total is `COALESCE(SUM(t.q * t.p), 0)` over a `VALUES` list built from the request;
     * without them it is a literal `0`, because an empty `VALUES` list is a syntax error in Postgres. Both
     * shapes write the same column list, in the same order, so the two cannot drift in what they store —
     * only in where the total comes from.
     *
     * **Everything about why this is an expression and not an `UPDATE` is in {@see self::create()}'s
     * docblock, including the condition it depends on and the commit that deletes it.** It is not repeated
     * here; a money rule written twice is a money rule that drifts.
     *
     * @param array<string, string|null>             $values
     * @param array{line: string, city: string, postalCode: string, countryCode: string} $shipTo
     * @param list<array<string, string|null>>|null  $lines
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
        ?array $lines,
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

        if (null === $lines || [] === $lines) {
            $em->getConnection()->executeStatement(
                $columns."\n".<<<'SQL'
                VALUES
                    (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, ?::uuid,
                     ?, ?, ?, ?, ?,
                     0, ?, ?)
                SQL,
                $parameters,
            );

            return;
        }

        // The `?` placeholders are positional, so the select list's thirteen come first and the VALUES
        // list's two-per-line follow, in the order the SQL text reads them.
        $rows = implode(', ', array_fill(0, \count($lines), '(?::numeric, ?::numeric)'));

        foreach ($lines as $line) {
            $parameters[] = $line['quantity'];
            $parameters[] = $line['unitPrice'];
        }

        $em->getConnection()->executeStatement(
            $columns."\n".\sprintf(<<<'SQL'
                SELECT ?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, ?::uuid,
                       ?, ?, ?, ?, ?,
                       COALESCE(SUM(t.q * t.p), 0), ?, ?
                  FROM (VALUES %s) AS t(q, p)
                SQL, $rows),
            $parameters,
        );
    }

    /**
     * One line of the nested create.
     *
     * **There is no server-derived column here, and that is the fact
     * {@see self::insertOrderInside()}'s total depends on.** `quantity_fulfilled` defaults to `0` in the
     * schema and is not written; everything else comes from the request.
     *
     * @param array<string, string|null> $values already shape-checked
     */
    private function insertLineInside(EntityManagerInterface $em, string $orderId, array $values, int $index): void
    {
        $connection = $em->getConnection();
        $itemId = (string) $values['inventoryItemId'];

        // ST-5's oracle: `inventory_item` is tenant-scoped, so this lookup is bounded by RLS and an id
        // belonging to another tenant is indistinguishable from one that never existed. That is why it is a
        // `validation-failed` on the *field* rather than a `not-found` on a row the caller never addressed.
        if (false === $connection->fetchOne('SELECT 1 FROM inventory_item WHERE id = ?::uuid AND deleted_at IS NULL', [$itemId])) {
            throw ApiProblem::validationFailed([
                self::lineError($index, 'inventoryItemId', 'unknown_reference', 'No live inventory item in this workspace has this id.'),
            ]);
        }

        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO sales_order_line
                (id, tenant_id, sales_order_id, inventory_item_id, line_number, quantity, unit_price)
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
                 ?::numeric, ?::numeric)
            SQL,
            [
                Uuid::v4()->toRfc4122(),
                // Bound explicitly rather than read back out of the GUC, so a mismatch with the
                // transaction's tenant is refused by the RLS WITH CHECK clause rather than accepted.
                $this->tenant->tenantId(),
                $orderId,
                $itemId,
                $orderId,
                $values['quantity'],
                $values['unitPrice'],
            ],
        );
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
     * Over **live** rows, matching `sales_order_tenant_number_live`'s own partiality, and bounded to numbers
     * this method could have minted — the regexp is what stops a hand-seeded `SO-CASCADE` turning the cast
     * into an error, and `DeletionPolicyTest` seeds exactly that. RLS supplies the tenant bound, so no
     * `tenant_id` predicate is written here.
     */
    private function nextOrderNumber(EntityManagerInterface $em): string
    {
        $next = (int) $em->getConnection()->fetchOne(<<<'SQL'
            SELECT COALESCE(MAX(substring(order_number from 4)::bigint), 0) + 1
              FROM sales_order
             WHERE deleted_at IS NULL AND order_number ~ '^SO-[0-9]+$'
            SQL);

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
     * @param array<string, string|null> $values
     */
    private function refuseUnresolvableCreateReferences(EntityManagerInterface $em, array $values): void
    {
        $connection = $em->getConnection();
        $errors = [];

        foreach (['customerId' => 'customer', 'warehouseId' => 'warehouse'] as $property => $table) {
            if (false === $connection->fetchOne("SELECT 1 FROM $table WHERE id = ?::uuid AND deleted_at IS NULL", [$values[$property]])) {
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
