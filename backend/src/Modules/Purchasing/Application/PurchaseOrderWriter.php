<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\AuditLog\Infrastructure\PurchaseOrderAuditWriter;
use App\Modules\Purchasing\ApiResource\CreatePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\CreatePurchaseOrderLineInput;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\ApiResource\UpdatePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\UpdatePurchaseOrderLineInput;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\UserManagement\Application\Contract\WorkspaceSettings;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Rfc3339Timestamp;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The write half of the `/purchase-orders` slice, minus the approval.
 *
 * `ApprovePurchaseOrder` is `PurchaseOrderApproval`'s, in a file of its own,
 * because §8.1 fixes an ordering that has to be readable in one screen and
 * because it is the only path here that touches an entity rather than a table.
 *
 * ===========================================================================
 * L-5 and L-8 — the state machine closes editing, not a permission
 * ===========================================================================
 * **L-5:** *"For documents with a state machine the detail page is the editor
 * only while `status = 'draft'`. After the first transition the page is read-only
 * except for the transition actions themselves."* F-2 step 8 says it from the
 * flow's side: *"From here the document is read-only except for its
 * transitions."*
 *
 * **L-8:** *"A document is deletable only while `status = 'draft'`."* And L-8
 * states why it cannot be a permission test: *"`purchasing_manager` holds
 * `purchase_order.delete` **and** `.approve` **and** `.cancel`, so a permission
 * test alone would let it delete a placed order."* Disposing of a draft and
 * repudiating a placed commitment are different acts — `.delete` and `.cancel` —
 * and it is the **status** that decides which is available.
 *
 * Every write below therefore reads the order's status first and refuses on
 * anything but `draft`, with `business-rule-violated` (E-2's second case: the
 * caller holds the code and is looking at the row) and **never** 403 or 404.
 * `refuseUnlessDraft()` is the one place that rule is written.
 *
 * **And before it reads, it locks (step 6c).** Each write to an existing order
 * opens with `PurchaseOrderRowLock::holdInside()`, so the status it checks is
 * still the status when it writes: a concurrent approval either committed
 * first, and the read sees `ordered`, or waits. Without it, a line could be
 * added to or repriced on an order that had just been placed. `create()` has
 * no existing row to lock; its races are the two unique indexes it names.
 *
 * ===========================================================================
 * `total_amount` is recomputed on every line write, and nobody owned that
 * ===========================================================================
 * `purchase_order.total_amount` is `numeric(19,4) NOT NULL DEFAULT 0`, §4.4.3
 * declares it a field of the read shape, and **no document says who maintains
 * it**. Left alone it would read `0` on an order with lines, which is a number
 * the screen would show and the database would not have earned. It is recomputed
 * from the live lines inside the same transaction as the line write, so the two
 * cannot disagree. **Reported as a decision taken here rather than presented as
 * derived.**
 *
 * ===========================================================================
 * Where a constraint failure is caught
 * ===========================================================================
 * Outside `TenantContext::run()`, never inside it — `ItemWriter`'s rule, for its
 * reason: a failed statement leaves the Postgres transaction aborted (25P02), so
 * catching within the block and continuing makes the following `COMMIT` fail with
 * an error nobody can trace back to the line that caused it.
 */
final readonly class PurchaseOrderWriter
{
    /** numeric(19,6) — quantities. Precision 19, scale 6, so at most 13 digits before the point. */
    private const QUANTITY = '/^\d{1,13}(\.\d{1,6})?$/';

    /** numeric(19,4) — money (S-12). Never a float, in either direction. */
    private const MONEY = '/^\d{1,15}(\.\d{1,4})?$/';

    /**
     * The exact scale of `quantity_ordered × unit_cost` — `numeric(19,6)` times `numeric(19,4)`, measured
     * on the cluster (`scale(0.000100::numeric(19,6) * 0.5000::numeric(19,4))` = 10). The pre-flight bounds
     * multiply and accumulate here and round **once**, because bcmath truncates at its scale argument and
     * Postgres does not. Ported from `SalesOrderWriter` (`98643f9`), which found it for the same columns.
     */
    private const PRODUCT_SCALE = 10;

    /** One sentence for every overflow refusal, so they cannot drift apart. */
    private const TOTAL_OUT_OF_RANGE = 'This line would take the order\'s total past what it can be stored as: '
        .'a total carries at most 15 digits before the decimal point. Lower the quantity or the unit cost, '
        .'or split the order.';

    /** The live-row unique a **replay** collides on — `Version20260911000100`. */
    private const IDEMPOTENCY_INDEX = 'purchase_order_tenant_idem_live';

    /** The live-row unique a **race between two creates** collides on. */
    private const NUMBER_INDEX = 'purchase_order_tenant_number_live';

    /** §4.2's operation id, digested with the request so no other operation's request can match it. */
    private const CREATE_OPERATION = 'CreatePurchaseOrder';

    /** The live-row unique a **line-create replay race** collides on — `Version20260916000200` (V2-R-12). */
    private const LINE_IDEMPOTENCY_INDEX = 'purchase_order_line_tenant_idem_live';

    /** The live-row unique two concurrent line creates picking the same `MAX + 1` collide on. */
    private const LINE_NUMBER_INDEX = 'purchase_order_line_unique';

    /** §4.2's operation id for the single-line create, digested with its request. */
    private const CREATE_LINE_OPERATION = 'CreatePurchaseOrderLine';

    /**
     * §4.2's `lines[]` sub-fields that `CreatePurchaseOrder` accepts, as input
     * properties. The field names the client sends are derived from `POINTERS`
     * by {@see self::nestedLineFields()}, so this set has one spelling.
     *
     * **`expectedAt` is absent:** a purchase order line has no expected date
     * (owner ruling Q2, 2026-09-15). `PurchaseOrderOperationContractTest::
     * theNestedLineSetIsExactlyTheLineCreateSet` holds this set to
     * `CreatePurchaseOrderLine`'s.
     */
    private const NESTED_LINE_PROPERTIES = ['inventoryItemId', 'quantity', 'unitCost'];

    /**
     * **How many lines one `CreatePurchaseOrder` REQUEST may carry. No document
     * sets a number, so this is a decision taken here and reported as one.**
     *
     * **It bounds one request, not an order.** `CreatePurchaseOrderLine` is
     * uncapped, so an order still reaches any size one call at a time — which is
     * the right place for that question, because each of those calls is its own
     * transaction.
     *
     * **The reason, and it is one reason rather than three.** An unbounded list
     * puts an unbounded number of INSERTs and catalogue lookups inside a single
     * transaction. That is the whole of it.
     *
     * ⚠ **Two other reasons were written here on 2026-09-12 and both were false.
     * They are recorded rather than deleted, because each is the kind of thing
     * that reads as a derivation and is not:**
     *
     *   - *"the reason usually given for such a cap"* — that an unbounded insert
     *     would hold the order's row lock — does not apply: `create()` takes no
     *     lock, there being no row yet;
     *   - *"200 is §4.2's ceiling on what this API returns in one page, so an
     *     order with more lines than a page is past what the read side was
     *     designed for"* — the number is **§10.1 / API-17**'s, not §4.2's, and
     *     `ListPurchaseOrderLines` is a `(bounded)` sub-list that takes no
     *     `limit` at all. There is no page a 201-line order would fail to fit in,
     *     because that list has no pages.
     *
     * **What the tie to `PurchaseOrderCollectionProvider::MAXIMUM_LIMIT` is
     * therefore worth, stated honestly:** not a derivation — the two bound
     * different things — but a statement that the largest batch this API is
     * willing to hand over in one response and the largest it is willing to
     * accept in one request are **the same magnitude, and are to be reviewed
     * together**. `PurchaseOrderOperationContractTest::theNestedLineCapIsThePageCeiling`
     * is what forces that review when either moves.
     *
     * **Public because the refusal message names the number** and because that
     * test reads it.
     */
    public const MAXIMUM_LINES = 200;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private WorkspaceSettings $settings,
        private PurchaseOrderAuditWriter $audit,
    ) {
    }

    // -----------------------------------------------------------------------
    // The order
    // -----------------------------------------------------------------------

    /**
     * **`CreatePurchaseOrder` — the operation E9-2 blocked for a month, and the
     * first in this project whose retry key is a domain column.**
     *
     * ===========================================================================
     * What the key does, and the one place §6.2 had to be amended for it
     * ===========================================================================
     * The `Idempotency-Key` header's value — **hashed**, as D-B ruled
     * (`IdempotencyKeyHash`) — is written into
     * `purchase_order.idempotency_key`, which sits on the live-row unique
     * `purchase_order_tenant_idem_live`. A retried timeout therefore collides **at
     * the database**, exactly as a repeated `CreateItem` collides on `sku` — and
     * that is the only route by which an operation whose response carries a
     * server-minted `id` can be made retry-safe, because §2.2 serializes the
     * response after commit and the envelope store cannot hold what does not yet
     * exist.
     *
     * **A replay is recognised by a READ, before the insert — not by catching the
     * `23505`.** That ordering is forced by a property this project already
     * measured and wrote down: *"a constraint violation inside
     * `wrapInTransaction` closes the EntityManager … a refused cross-tenant write
     * does not leave a usable unit of work behind"*
     * (`TwoParentCompositeKeyTest`, whose control case exists to establish it).
     * So the obvious shape — insert, catch the unique violation, re-read the row
     * the key created — **cannot work**: by the time the exception is caught
     * there is no manager left to read with, and the caller gets a 500 instead of
     * its order. Measured here too, on the first run of
     * `aRetriedCreateYieldsOneOrderAndNotTwo`.
     *
     * The read and the index are therefore **two mechanisms with two jobs**, and
     * neither replaces the other:
     *
     *   - the **read** answers every ordinary replay — the retry of a timed-out
     *     request, which is the case P-5 is about and the only one a client can
     *     produce on purpose;
     *   - the **unique index** is the concurrency backstop, and it is what makes
     *     the read safe to rely on: between the `SELECT` and the `INSERT` another
     *     transaction can commit the same key, and the database refuses the
     *     second row rather than letting the gap widen into a duplicate order.
     *
     * **What a replay returns makes §6.2's *"byte for byte"* false for the
     * `unique:` route**, and §6.2 now says so in its own sentence rather than
     * being quietly contradicted here: it is *the operation's response recomputed
     * from the row the key created*. The difference is observable —
     * `total_amount` moves as lines are added, so a replay arriving after a line
     * was created returns the current total rather than the one the first
     * response carried. The adversarial round priced that as the cost of the
     * domain-column ruling; smuggling it would have been the failure.
     *
     * ===========================================================================
     * D-B: the same key with a different request is a 409, decided by a digest
     * ===========================================================================
     * The row also stores `request_digest` — `RequestDigest::of()` of what the
     * client **sent**, normalised — and the replay read compares it: equal is a
     * replay, different is §6.2's `idempotency-key-conflict`. Until 2026-09-11
     * the read matched on the key alone, and answered a key reused for a
     * different order with the first order, as a 201. `orderCreatedByKeyInside()`
     * says why the comparison is never against the row.
     *
     * ⚠ **The residual, named rather than solved: two identical retries in flight
     * at the same instant.** One loses at the index, its unit of work is gone,
     * and this method answers `business-rule-violated` — *try again* — instead of
     * the order. A third attempt reads the committed row and succeeds, so the
     * client is never told anything false and never gets a second order; what it
     * gets is one avoidable round trip in a window measured in milliseconds.
     * Closing it properly needs a re-readable unit of work after a failed
     * statement, which is a `TenantContext` change and not this operation's to
     * make.
     *
     * The two uniques are told apart **by name**, and the distinction is
     * load-bearing rather than tidy: `purchase_order_tenant_number_live` is a race
     * between two concurrent creates picking the same `MAX + 1`, which is a
     * genuine conflict the caller should retry, and answering it as a replay would
     * hand one caller the *other* caller's order.
     *
     * ===========================================================================
     * `created_by_user_id`, and why it is not an accepted field
     * ===========================================================================
     * §8.1: *"`CreatePurchaseOrder` does not accept `created_by_user_id` and sets
     * it server-side from the JWT subject on every user-initiated create. A NULL
     * `created_by_user_id` on a row created over HTTP converts DSoD-1 from a
     * control into a suggestion one `INSERT` at a time."* Since Amendment 1 a
     * single missing write disarms **two** checks rather than one — approval and
     * cancellation — so the value comes from `RequestIdentity` and there is no
     * parameter, no field and no branch by which it can arrive as NULL.
     *
     * ===========================================================================
     * `order_number` is minted here, and no document says how
     * ===========================================================================
     * `purchase_order.order_number` is `NOT NULL`, §4.2 accepts no field for it,
     * and **nothing in the contract specifies its form** — the only property any
     * document relies on is that it is server-generated, which is precisely why it
     * cannot serve as a retry key. `MAX + 1` over the tenant's live rows is
     * `createLine()`'s own `line_number` shape one table up, and it inherits that
     * method's race and its answer: two concurrent creates can pick the same
     * number, one loses on the unique, and the refusal tells it to try again.
     * **Reported as a decision taken here rather than presented as derived.**
     *
     * The scan is over **live** rows, matching the partial unique, so a number
     * freed by archiving an order is reusable — the same property
     * `purchase_order_line_unique` gives line numbers.
     */
    public function create(CreatePurchaseOrderInput $input, string $idempotencyKey): PurchaseOrderResource
    {
        $values = [
            'supplierId' => $input->supplierId,
            'warehouseId' => $input->warehouseId,
            'currencyCode' => $input->currencyCode,
            'expectedAt' => $input->expectedAt,
        ];

        // ⚠ **The header's shape is answered BEFORE the lines' shape, so a
        // request malformed in both takes two round trips. That is a decision,
        // not an oversight.** Merging the two passes would make this the only
        // operation in the contract that collects across them:
        // `GoodsReceiptWriter::create()` calls `receivedAt()` and then `lines()`
        // in exactly this order and throws at the first, and the fix one clause
        // down in `nestedLines()` exists precisely because two collection rules
        // for one shape is the drift this project keeps finding. Each pass
        // collects everything it can see -- every malformed header field
        // together, then every malformed line together -- which is where the
        // round trips actually get saved.
        $this->refuseMalformedCreateFields($values);
        $lines = self::nestedLines($input->lines);

        // D-B: what was SENT, normalised -- after the shape check, so every value
        // here is one the normalisers accept. `currency_code` is the client's
        // value or null, never the workspace default it resolves to below: that
        // default can change between a timeout and its retry.
        $digestedFields = [
            'supplier_id' => RequestDigest::uuid($values['supplierId']),
            'warehouse_id' => RequestDigest::uuid($values['warehouseId']),
            'currency_code' => $values['currencyCode'],
            'expected_at' => RequestDigest::instant($values['expectedAt']),
        ];

        // ⚠ **`lines` enters the digest only when it was SENT, and that is the
        // whole of 8a's backward compatibility.** Every order created before the
        // nested create shipped stored a digest computed from the four fields
        // above; adding a fifth key unconditionally -- even as an explicit null --
        // changes the canonical JSON of a request that omits it, so every one of
        // those rows would answer its next honest retry with a 409. A key that is
        // absent leaves the canonical form byte-identical.
        // `theStoredDigestOfAFixedRequestIsPinned` is the constant that says so,
        // and `aRetryOfARequestRaisedBeforeTheNestedCreateIsStillAReplay` drives it
        // over HTTP.
        //
        // **The list keeps its order**, which `RequestDigest` does by design and
        // this is the caller its docblock names: `line_number` is minted from the
        // position, so the same two lines the other way round are a different
        // order and must be a conflict, not a replay. That is the opposite of
        // `GoodsReceiptWriter::digestedLines()`, which sorts because a receipt has
        // no line order at all.
        if (null !== $lines) {
            $digestedFields['lines'] = self::digestedLines($lines);
        }

        $digest = RequestDigest::of(self::CREATE_OPERATION, $digestedFields);

        // D-B: the column holds the header's hash, never its value. The replay
        // read and the INSERT below both use this, so they cannot disagree.
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);

        $id = Uuid::v4()->toRfc4122();
        // Read before the transaction opens: it is a property of the request, not
        // of the write, and `authenticated()` fails closed on its own.
        $createdBy = $this->identities->authenticated()->userId;

        try {
            return $this->tenants->run(
                $this->tenant->tenantId(),
                function (EntityManagerInterface $em) use ($values, $lines, $id, $createdBy, $keyHash, $digest): PurchaseOrderResource {
                    // **The replay answer, and it is a read rather than a caught
                    // exception.** See the method docblock: a constraint
                    // violation inside `wrapInTransaction` closes the
                    // EntityManager, so there is no unit of work left to re-read
                    // the row with afterwards. The unique index below is still
                    // what makes this safe -- it refuses the duplicate that
                    // could commit between this SELECT and the INSERT.
                    $replay = $this->orderCreatedByKeyInside($em, $keyHash, $digest);

                    if (null !== $replay) {
                        return $replay;
                    }

                    $this->refuseUnresolvableCreateReferences($em, $values);

                    // Read INSIDE the transaction, for `ItemWriter`'s reason:
                    // `set_config(..., true)` and the tenant filter's parameter
                    // are both transaction-scoped, so this is the only place the
                    // workspace's own currency resolves. There is deliberately no
                    // `?? 'EUR'` -- a workspace with no settings row cannot raise
                    // an order, which is the refusal E4-3 exists to produce.
                    $currencyCode = $values['currencyCode'] ?? $this->settings->defaultCurrencyCode();

                    $em->getConnection()->executeStatement(
                        <<<'SQL'
                        INSERT INTO purchase_order
                            (id, tenant_id, order_number, supplier_id, warehouse_id,
                             created_by_user_id, currency_code, expected_at, idempotency_key,
                             request_digest)
                        VALUES
                            (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid,
                             ?::uuid, ?, ?::timestamptz, ?,
                             ?)
                        SQL,
                        [
                            $id,
                            // Bound explicitly rather than read back out of the
                            // GUC. The row still has to satisfy the RLS WITH
                            // CHECK clause, so a mismatch between this value and
                            // the transaction's tenant is refused by Postgres
                            // rather than accepted.
                            $this->tenant->tenantId(),
                            $this->nextOrderNumber($em),
                            $values['supplierId'],
                            $values['warehouseId'],
                            $createdBy,
                            $currencyCode,
                            $values['expectedAt'],
                            $keyHash,
                            $digest,
                        ],
                    );

                    // D-C: the lines, in the SAME transaction as the order. Any
                    // refusal below rolls the order back with them -- there is no
                    // savepoint and none is wanted, because a create that kept the
                    // order and dropped a line is the headless draft this step
                    // exists to make impossible.
                    foreach ($lines ?? [] as $index => $line) {
                        $this->insertLineInside($em, $id, $line, $index);
                    }

                    if (null !== $lines) {
                        // `/lines` and not `/lines/<n>`: this backstop fires only
                        // where `nestedLines()`'s per-line bound already passed,
                        // and the two now do the same arithmetic, so there is no
                        // honest way to say which line tipped it -- only that the
                        // lines, taken together, do. Naming an index here would
                        // be a guess dressed as a measurement. If it ever fires,
                        // the two have drifted and that is the bug to fix.
                        $this->recomputeTotal($em, $id, '/lines');
                    }

                    return $this->readOrderInside($em, $id)
                        ?? throw new \LogicException('The order just inserted is not visible to its own transaction.');
                },
            );
        } catch (UniqueConstraintViolationException $e) {
            // Caught OUTSIDE `TenantContext::run()`, never inside it: a failed
            // statement leaves the Postgres transaction aborted (25P02), so
            // catching within the block and continuing makes the following
            // COMMIT fail with an error nobody can trace back to its cause.
            if (self::violated($e, self::IDEMPOTENCY_INDEX)) {
                // The narrow race the read above cannot cover: a request with
                // the same key committed between that SELECT and this INSERT.
                // Its body may differ -- the next attempt reads its row and
                // gets the replay or the 409, whichever is true. The
                // EntityManager is closed, so the order cannot be read back
                // here -- the client is told to try again, and the next attempt
                // reads the committed row and gets it. Never a second order.
                throw ApiProblem::businessRuleViolated(
                    'purchase_order.idempotency_key_is_in_flight',
                    'A request with this Idempotency-Key is already being processed. Try again.',
                );
            }

            if (self::violated($e, self::NUMBER_INDEX)) {
                throw ApiProblem::businessRuleViolated(
                    'purchase_order.order_number_is_unique_per_workspace',
                    'Another order was raised in this workspace at the same moment. Try again.',
                );
            }

            throw $e;
        }
    }

    public function update(string $id, UpdatePurchaseOrderInput $input): PurchaseOrderResource
    {
        $submitted = $input->submitted();
        $this->refuseMalformedOrderFields($submitted);

        return $this->translatingConstraintFailures(fn (): PurchaseOrderResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $submitted): PurchaseOrderResource {
                PurchaseOrderRowLock::holdInside($em, $id);
                $current = $this->readOrderInside($em, $id) ?? throw ApiProblem::notFound();

                // ST-9 / §5.2a: an archived row renders read-only, and the
                // refusal is on the business-rule branch rather than
                // `not-found` -- the caller can see the row, so pretending it is
                // gone would be a different lie from the one it is being told.
                $this->refuseIfArchived($current);
                $this->refuseUnlessDraft($current);

                // The same liveness the create demands, for whichever parent
                // this edit names. The composite foreign key alone admits an
                // archived supplier or warehouse -- the row still exists -- so
                // without this a draft could be moved onto a closed site or a
                // retired supplier (five-calls follow-up review, 2026-09-13).
                $this->refuseParentsThatAreNotLive($em, $submitted);

                if ([] !== $submitted) {
                    $em->getConnection()->executeStatement(
                        'UPDATE purchase_order SET '.implode(', ', array_map(
                            static fn (string $property): string => self::ORDER_COLUMNS[$property].' = '.self::bind($property, self::ORDER_CASTS),
                            array_keys($submitted),
                        )).', updated_at = now() WHERE id = CAST(:id AS uuid)',
                        [...$submitted, 'id' => $id],
                    );
                }

                return $this->readOrderInside($em, $id) ?? throw ApiProblem::notFound();
            },
        ));
    }

    /**
     * `DeletePurchaseOrder` — API-12: `purchase_order` is `SchemaManifest::SOFT`,
     * so this sets `deleted_at`, and `purchase_order_cascade_deletion` carries it
     * to the lines in the database. API-21 branch 3: **204, and no body**.
     *
     * **One `purchase_order.deleted` row, naming the deleter** — B-4a (roles slice 2, C1, 2026-09-29), minted with
     * its sales twin as `RESIDUAL_GAPS.md` G-9's fallback required; G-9 is closed for both modules.
     */
    public function archive(string $id): void
    {
        $identity = $this->identities->authenticated();

        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $identity): void {
            PurchaseOrderRowLock::holdInside($em, $id);
            $current = $this->readOrderInside($em, $id) ?? throw ApiProblem::notFound();

            $this->refuseIfArchived($current);
            // L-8, and it is the whole of the difference between this operation
            // and `CancelPurchaseOrder`.
            $this->refuseUnlessDraft($current);

            $em->getConnection()->executeStatement(
                'UPDATE purchase_order SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid',
                [$id],
            );

            // In this transaction, `SalesOrderWriter::archive()`'s shape: the archive and its row commit together.
            $this->audit->recordDeleted(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                purchaseOrderId: $id,
            );
        });
    }

    // -----------------------------------------------------------------------
    // The lines
    // -----------------------------------------------------------------------

    /**
     * **`CreatePurchaseOrderLine` — and since V2-R-12 (2026-09-16) a retry writes one line, not two.**
     *
     * Until then every request minted a new id and `MAX(line_number) + 1`, so a retried timeout added a second line and
     * raised the total an approver would approve. The mechanism is `create()`'s, one table down: the header's hash in
     * `purchase_order_line.idempotency_key` on `purchase_order_line_tenant_idem_live`, and D-B's `request_digest`.
     *
     * **The order of the steps is the design:**
     *
     *   1. the order's row lock — which also serialises every same-order retry, so the in-flight "try again" residual of
     *      `create()` does not arise here;
     *   2. not-found, before anything the request carries is judged (ST-5: a key lookup first would tell a caller that
     *      their key was used in this workspace);
     *   3. the **key lookup**: a different digest is 409 `idempotency-key-conflict`; the same digest is the line that key
     *      created, recomputed, with **no insert and no total recompute** — 201 + the same `Location` (§6.2's
     *      domain-key replay);
     *   4. only then the archived and draft guards, so a retry that arrives after the order was approved is handed its
     *      line rather than refused for an act that committed;
     *   5. the insert, carrying the key hash and the digest.
     *
     * **The digest includes the path's order id.** The unique is tenant-wide (a header supplies one value), so without
     * it the same key sent to another order would replay another order's line. A same-key request on another order that
     * races past step 3 is refused at the index, and {@see self::translatingConstraintFailures()} maps that violation
     * **by name** to 409.
     */
    public function createLine(string $orderId, CreatePurchaseOrderLineInput $input, string $idempotencyKey): PurchaseOrderLineResource
    {
        $values = [
            'inventoryItemId' => $input->inventoryItemId,
            'quantity' => $input->quantity,
            'unitCost' => $input->unitCost,
        ];

        $this->refuseMalformedLineFields($values, required: self::NESTED_LINE_PROPERTIES);

        // D-B: the column holds the header's hash, never its value.
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);

        return $this->translatingConstraintFailures(fn (): PurchaseOrderLineResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($orderId, $values, $keyHash): PurchaseOrderLineResource {
                PurchaseOrderRowLock::holdInside($em, $orderId);
                $order = $this->readOrderInside($em, $orderId) ?? throw ApiProblem::notFound();

                // After not-found, so the path id is a valid UUID here and the
                // digest's normaliser accepts it.
                $digest = self::lineDigest($orderId, $values);

                $replay = $this->lineCreatedByKeyInside($em, $orderId, $keyHash, $digest);
                if (null !== $replay) {
                    return $replay;
                }

                $this->refuseIfArchived($order);
                $this->refuseUnlessDraft($order);

                $id = $this->insertLineInside($em, $orderId, $values, null, $keyHash, $digest);

                // This request sent exactly one line and both of its fields, so
                // the refusal names the same field the pre-flight bound names.
                $this->recomputeTotal($em, $orderId, self::POINTERS['unitCost']);

                return $this->readLineInside($em, $orderId, $id)
                    ?? throw new \LogicException('The line just inserted is not visible to its own transaction.');
            },
        ));
    }


    /**
     * **F-2 step 3's line rules, in the one place either of them is written.**
     *
     * Both write paths enter here — `CreatePurchaseOrderLine` with `$index` null,
     * and `CreatePurchaseOrder`'s nested `lines[]` with the line's position — so
     * `line_number` minting and the `lead_time_days` snapshot exist once. The
     * input docblock says why that mattered enough to refactor rather than copy:
     * a second copy of a snapshot rule is how the two drift.
     *
     * **`$index` is the JSON Pointer base.** Null points errors
     * at `/inventory_item_id`; an integer points them at
     * `/lines/0/inventory_item_id`. It is **0-based**, matching
     * `GoodsReceiptWriter` and the array index the pointer addresses. The one
     * refusal with no pointer names the line as `$index + 1`, the number it
     * would carry (V2-C-19).
     *
     * ⚠ **The two refusals below throw on the FIRST offending line rather than
     * collecting every bad line.**
     *
     * **The two cannot be collected with EACH OTHER**, which is narrower than
     * the claim this paragraph made until 2026-09-12: an unknown item is
     * `validation-failed` with a pointer, a catalogue miss is
     * `business-rule-violated` and carries no errors array at all, so there is no
     * one problem the pair could be reported in. **The unknown-item half could
     * be collected across lines on its own**, with a pre-pass resolving every
     * item id at once the way `GoodsReceiptWriter::refuseUnknownBins()` does, and
     * saying otherwise was wrong. It is not done here because the pre-pass would
     * have to live outside this method and the two rules would stop being written
     * in one place, which is the property that made this refactor worth doing —
     * a trade, and now a stated one rather than an impossibility.
     *
     * Shape errors, which are one problem type, ARE collected across every line
     * by {@see self::nestedLines()}.
     *
     * **`$keyHash` and `$digest` are the single-line path's retry key (V2-R-12)** and are both null on the nested path:
     * that request's key is the order's, and N lines sharing it would collide on `purchase_order_line_tenant_idem_live`.
     *
     * @param array<string, string|null> $values  `inventoryItemId`, `quantity`, `unitCost`, already shape-checked
     * @param int|null                   $index   the line's position in `lines[]`, or null for the single-line path
     * @param string|null                $keyHash `IdempotencyKeyHash::of()` of the header, single-line path only
     * @param string|null                $digest  `self::lineDigest()`, single-line path only
     *
     * @return string the new line's id
     */
    private function insertLineInside(EntityManagerInterface $em, string $orderId, array $values, ?int $index, ?string $keyHash = null, ?string $digest = null): string
    {
        $connection = $em->getConnection();
        $id = Uuid::v4()->toRfc4122();
        $itemId = (string) $values['inventoryItemId'];
        // ST-5's oracle: `inventory_item` is tenant-scoped, so this
        // lookup is bounded by RLS and an id belonging to another tenant
        // is indistinguishable from one that never existed. That is why
        // it is a `validation-failed` on the *field* rather than a
        // `not-found` on a row the caller never addressed.
        if (false === $connection->fetchOne('SELECT 1 FROM inventory_item WHERE id = ?::uuid AND deleted_at IS NULL', [$itemId])) {
            throw ApiProblem::validationFailed([
                self::lineError($index, 'inventoryItemId', 'unknown_reference', 'No live inventory item in this workspace has this id.'),
            ]);
        }

        // F-2 step 3 -- lead time is **snapshotted** onto the line from
        // the supplier's catalogue, never referenced, because
        // `supplier_product` is deletion class `hard` *"precisely so
        // current terms can change without rewriting a placed order"*.
        // `purchase_order_line.lead_time_days` is NOT NULL with no
        // default and §4.2 accepts no field for it, so this read is the
        // only source there is.
        $leadTime = $connection->fetchOne(
            <<<'SQL'
            SELECT sp.lead_time_days
            FROM supplier_product sp
            JOIN purchase_order po ON po.tenant_id = sp.tenant_id AND po.supplier_id = sp.supplier_id
            WHERE po.id = ?::uuid AND sp.inventory_item_id = ?::uuid
            SQL,
            [$orderId, $itemId],
        );

        if (false === $leadTime) {
            // A rule about *this* order and *this* item rather than a
            // defect in a field the caller sent -- the supplier comes
            // from the order, not from the request -- so E-2's second
            // case and §5.2's `business-rule-violated`.
            // A `business-rule-violated` problem carries no pointer, so on the
            // nested path the line is named in the MESSAGE, by the line number
            // it would carry (V2-C-19, 2026-09-16). That is well defined here:
            // the nested lines belong to an order this transaction is creating,
            // so the MAX + 1 below mints `$index + 1` for each in turn. Until
            // then it was named by its 0-based pointer, which is not the user's
            // terms.
            throw ApiProblem::businessRuleViolated(
                'purchase_order_line.supplier_lists_the_item',
                'This order\'s supplier has no catalogue entry for '
                .(null === $index ? 'that item' : 'the item on line '.($index + 1))
                .', so the line has no lead time to record.',
            );
        }

        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO purchase_order_line
                (id, tenant_id, purchase_order_id, inventory_item_id, line_number,
                 quantity_ordered, unit_cost, lead_time_days, idempotency_key, request_digest)
            VALUES
                (?::uuid, ?::uuid, ?::uuid, ?::uuid,
                 -- The next free number on this order. `line_number` is
                 -- NOT NULL, §4.2 accepts no field for it, and
                 -- `purchase_order_line_unique` is partial on
                 -- `deleted_at IS NULL` -- so a discarded number is
                 -- reusable and this MAX is over the live lines only,
                 -- which is the same set the index constrains.
                 (SELECT COALESCE(MAX(line_number), 0) + 1
                    FROM purchase_order_line
                   WHERE purchase_order_id = ?::uuid AND deleted_at IS NULL),
                 ?::numeric, ?::numeric, ?, ?, ?)
            SQL,
            [
                $id,
                // Bound explicitly rather than read back out of the GUC.
                // The row still has to satisfy the RLS WITH CHECK
                // clause, so a mismatch between this value and the
                // transaction's tenant is refused by Postgres rather
                // than accepted.
                $this->tenant->tenantId(),
                $orderId,
                $itemId,
                $orderId,
                $values['quantity'],
                $values['unitCost'],
                (int) $leadTime,
                $keyHash,
                $digest,
            ],
        );

        return $id;
    }

    public function updateLine(string $orderId, string $lineId, UpdatePurchaseOrderLineInput $input): PurchaseOrderLineResource
    {
        $submitted = $input->submitted();
        $this->refuseMalformedLineFields($submitted, required: []);

        return $this->translatingConstraintFailures(fn (): PurchaseOrderLineResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($orderId, $lineId, $submitted): PurchaseOrderLineResource {
                PurchaseOrderRowLock::holdInside($em, $orderId);
                $order = $this->readOrderInside($em, $orderId) ?? throw ApiProblem::notFound();
                $this->refuseIfArchived($order);
                $this->refuseUnlessDraft($order);

                $line = $this->readLineInside($em, $orderId, $lineId) ?? throw ApiProblem::notFound();

                // A line whose order is a draft cannot itself be archived --
                // the trigger only ever archives lines with their order -- but
                // the check is written rather than argued, because it is one
                // `UPDATE` away from being false and §5.2a requires the refusal
                // either way.
                if (null !== $line->archivedAt) {
                    throw self::lineArchived();
                }

                if ([] !== $submitted) {
                    $em->getConnection()->executeStatement(
                        'UPDATE purchase_order_line SET '.implode(', ', array_map(
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

    /** API-21 branch 3: **204, and no body**. `purchase_order_line` is `SchemaManifest::SOFT`. */
    public function archiveLine(string $orderId, string $lineId): void
    {
        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($orderId, $lineId): void {
            // Order before line, and that ordering is the deadlock fix: before
            // 6c this path locked the line (its UPDATE) and then the order
            // (`recomputeTotal()`), the reverse of archiving the order, whose
            // cascade trigger locks the order and then its lines.
            PurchaseOrderRowLock::holdInside($em, $orderId);
            $order = $this->readOrderInside($em, $orderId) ?? throw ApiProblem::notFound();
            $this->refuseIfArchived($order);
            $this->refuseUnlessDraft($order);

            $line = $this->readLineInside($em, $orderId, $lineId) ?? throw ApiProblem::notFound();

            if (null !== $line->archivedAt) {
                throw self::lineArchived();
            }

            $em->getConnection()->executeStatement(
                'UPDATE purchase_order_line SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid',
                [$lineId],
            );

            // No pointer: a `DELETE` carries no body, and archiving a line can
            // only lower a sum of non-negative products that already fitted.
            $this->recomputeTotal($em, $orderId, null);
        });
    }

    // -----------------------------------------------------------------------

    /** Property name => column, so no UPDATE restates the field list. */
    private const ORDER_COLUMNS = [
        'supplierId' => 'supplier_id',
        'warehouseId' => 'warehouse_id',
        'expectedAt' => 'expected_at',
    ];

    /**
     * Written as `CAST(:p AS t)` rather than `:p::t` because DBAL's parser has to
     * tell a Postgres cast from a named placeholder, and `:supplierId::uuid` is
     * exactly the string where that goes wrong.
     */
    private const ORDER_CASTS = [
        'supplierId' => 'uuid',
        'warehouseId' => 'uuid',
        'expectedAt' => 'timestamptz',
    ];

    private const LINE_COLUMNS = [
        'inventoryItemId' => 'inventory_item_id',
        'quantity' => 'quantity_ordered',
        'unitCost' => 'unit_cost',
    ];

    private const LINE_CASTS = [
        'inventoryItemId' => 'uuid',
        'quantity' => 'numeric',
        'unitCost' => 'numeric',
    ];

    private const POINTERS = [
        'supplierId' => '/supplier_id',
        'warehouseId' => '/warehouse_id',
        'currencyCode' => '/currency_code',
        'expectedAt' => '/expected_at',
        'inventoryItemId' => '/inventory_item_id',
        'quantity' => '/quantity',
        'unitCost' => '/unit_cost',
    ];

    /** @param array<string, string> $casts */
    private static function bind(string $property, array $casts): string
    {
        return \sprintf('CAST(:%s AS %s)', $property, $casts[$property]);
    }

    /**
     * §4.4.3 declares `total_amount` and no document says who maintains it. See
     * the class docblock: this is a decision taken here.
     *
     * The sum is over **live** lines, which is the same set `archiveLine()`
     * removes a row from and the same set the cascade trigger empties when the
     * order is archived.
     *
     * ---------------------------------------------------------------------
     * **It reads the sum, judges it, and only then writes it (RED probe, 2026-09-19)**
     * ---------------------------------------------------------------------
     * Until then this was one `UPDATE … SET total_amount = (SELECT SUM(…))`, and
     * that statement was **the** raise site for four shipped operations:
     * `SUM(quantity_ordered * unit_cost)` is unconstrained `numeric`, the
     * assignment into `numeric(19,4)` rounds and range-checks, and `22003` is
     * neither of the constraint classes {@see self::translatingConstraintFailures()}
     * or `create()`'s own catch translate — so it reached the client as an
     * **HTTP 500 with no pointer** on a request whose every field was inside the
     * range this API publishes.
     *
     * **Why the check lives here and not in another PHP guard.** The PHP
     * pre-flight bounds can only see the lines a request *sent*.
     * `CreatePurchaseOrderLine` and `UpdatePurchaseOrderLine` send one line and
     * the order already has others; a `PATCH` naming only `quantity` sends no
     * cost at all, so `malformedLineFields()`'s bound is skipped entirely. No
     * arrangement of per-line checking can see that sum. This site can, and it is
     * the **one** site all four write paths funnel through — `create()`,
     * `createLine()`, `updateLine()` and `archiveLine()`, which is a fact about
     * this class that `PurchaseOrderRowLockTest`'s `'via recomputeTotal()'` entry
     * already depended on.
     *
     * **Why the sum is rounded by the server.** `round(x, 4)` on `numeric` is
     * Postgres's own half-away-from-zero, the same rule the cast into
     * `numeric(19,4)` applies — measured on the cluster, including the exact-half
     * and negative cases ({@see self::roundedToMoney()}). So the value judged and
     * the value stored are the same number by construction, and the guard cannot
     * be reopened by a change to a bcmath scale. The `UPDATE` then binds the value
     * that was judged, rather than recomputing it, for the same reason.
     *
     * **Why it is race-free.** Every path but `create()` has taken
     * `PurchaseOrderRowLock::holdInside()` on this order as the first statement
     * of its transaction, so no concurrent line write can commit between the
     * read and the write. `create()` holds no lock and needs none: the order is
     * a freshly minted uuid that has not committed, so no other transaction can
     * address it or its lines — the reason `PurchaseOrderRowLockTest`'s
     * `ALLOWED_WITHOUT_A_LOCK` already gives for it.
     *
     * **Throwing here is a clean rollback, not the aborted-transaction hazard**
     * the class docblock warns about: no statement failed. `refuseUnlessDraft()`
     * throws from inside the same closure for the same reason.
     *
     * @param ?string $pointer the JSON Pointer the refusal names — a field of
     *                         **this** request, so the caller is told what to
     *                         lower. Null on the paths that cannot raise the
     *                         total at all; see the branch below.
     */
    private function recomputeTotal(EntityManagerInterface $em, string $orderId, ?string $pointer): void
    {
        $total = (string) $em->getConnection()->fetchOne(
            <<<'SQL'
            SELECT round(COALESCE(SUM(quantity_ordered * unit_cost), 0), 4)
              FROM purchase_order_line
             WHERE purchase_order_id = ?::uuid AND deleted_at IS NULL
            SQL,
            [$orderId],
        );

        if (!self::fitsInTheTotalColumn($total)) {
            // ⚠ **A 500, and it is the right answer, because this branch is
            // unreachable and a refusal here would be a lie.** The two paths
            // that pass no pointer are `archiveLine()` -- a `DELETE` with no
            // request body, so there is no field to name -- and an `updateLine()`
            // whose `PATCH` changed neither the quantity nor the cost. Every
            // line product is non-negative (`QUANTITY` refuses zero and a sign,
            // `MONEY` refuses a sign), and the order's previous total is a value
            // `numeric(19,4)` already held, so removing a term or changing the
            // item can only lower or hold a sum that already fitted. Reaching
            // here means one of those sentences stopped being true, which is a
            // defect in this class and not in the request.
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
            UPDATE purchase_order
               SET total_amount = CAST(? AS numeric),
                   updated_at = now()
             WHERE id = ?::uuid
            SQL,
            [$total, $orderId],
        );
    }

    /**
     * Which field {@see self::recomputeTotal()}'s refusal names on
     * `UpdatePurchaseOrderLine`, where the `PATCH` decides what the caller sent.
     *
     * §5.2's `errors[]` addresses a field of **this** request, so naming
     * `/unit_cost` on a `PATCH` that sent only `quantity` would point at nothing
     * the caller could edit. `unit_cost` wins when both were sent, matching
     * `malformedLineFields()`'s own choice of *"the field that is almost always
     * the one that is wrong"*.
     *
     * Null when the `PATCH` changed neither: the sum over the order's lines does
     * not depend on `inventory_item_id`, so such a request cannot move the total
     * and the refusal is unreachable — which the branch in `recomputeTotal()`
     * states rather than papering over with a pointer it invented.
     *
     * @param array<string, string|null> $submitted
     */
    private static function totalPointerFor(array $submitted): ?string
    {
        foreach (['unitCost', 'quantity'] as $property) {
            if (\array_key_exists($property, $submitted)) {
                return self::POINTERS[$property];
            }
        }

        return null;
    }

    private function readOrderInside(EntityManagerInterface $em, string $id): ?PurchaseOrderResource
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $row = $em->getConnection()->fetchAssociative(
            'SELECT '.PurchaseOrderRow::COLUMNS.' '.PurchaseOrderRow::FROM.' WHERE po.id = ?::uuid',
            [$id],
        );

        return false === $row ? null : PurchaseOrderRow::toResource($row);
    }

    /**
     * The line is addressed by **both** path variables, so a `lineId` that names
     * a line of a different order is `not-found` rather than an edit of somebody
     * else's line. §5.3 clause 2 applies to the pair, not to the last segment.
     */
    private function readLineInside(EntityManagerInterface $em, string $orderId, string $lineId): ?PurchaseOrderLineResource
    {
        if (!Uuid::isValid($orderId) || !Uuid::isValid($lineId)) {
            return null;
        }

        $row = $em->getConnection()->fetchAssociative(
            'SELECT '.PurchaseOrderLineRow::COLUMNS.' '.PurchaseOrderLineRow::FROM
            .' WHERE pol.id = ?::uuid AND pol.purchase_order_id = ?::uuid',
            [$lineId, $orderId],
        );

        return false === $row ? null : PurchaseOrderLineRow::toResource($row);
    }

    // -----------------------------------------------------------------------
    // Shape, and only shape. Everything here is decidable without the database,
    // so it runs before a transaction is opened.
    // -----------------------------------------------------------------------

    /**
     * `CreatePurchaseOrder`'s shape rules, which are **not** `update()`'s with a
     * required-list bolted on.
     *
     * Both parent ids are required here and merely un-clearable there; a create
     * has no "absent means leave it alone" case at all, because there is nothing
     * to leave alone. `currency_code` is optional and falls back to the
     * workspace's, and `expected_at` is optional and nullable in the schema.
     *
     * @param array<string, string|null> $values
     */
    private function refuseMalformedCreateFields(array $values): void
    {
        $errors = [];

        foreach (['supplierId', 'warehouseId'] as $property) {
            if (null === ($values[$property] ?? null)) {
                $errors[] = self::error($property, 'required', 'This field is required.');
            } elseif (!Uuid::isValid($values[$property])) {
                $errors[] = self::error($property, 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.');
            }
        }

        // The `currency` table's primary key is a three-letter ISO 4217 code.
        // Checked for shape here so a malformed value is a named field defect
        // rather than a foreign-key lookup that answers "unknown".
        $currency = $values['currencyCode'] ?? null;
        if (null !== $currency && 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors[] = self::error('currencyCode', 'not_a_currency_code', 'This field must be a three-letter ISO 4217 code.');
        }

        if (null !== ($values['expectedAt'] ?? null) && null === self::parseTimestamp($values['expectedAt'])) {
            $errors[] = self::error('expectedAt', 'not_a_timestamp', 'This field must be an RFC 3339 timestamp.');
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * **ST-5's oracle, applied to the three references a create carries.**
     *
     * Resolved by lookup rather than left to the foreign keys, for the reason
     * `createLine()` resolves `inventory_item_id` that way: all three tables are
     * bounded by RLS, so an id belonging to another tenant is indistinguishable
     * from one that never existed, and the caller learns neither. A composite-FK
     * violation would produce the same refusal with no pointer at the field that
     * caused it, and §5.2 wants the field named.
     *
     * `currency` is **reference data** (ADR-002 §6) and is not tenant-scoped, so
     * its lookup is an existence check and discloses nothing.
     *
     * @param array<string, string|null> $values
     */
    private function refuseUnresolvableCreateReferences(EntityManagerInterface $em, array $values): void
    {
        $connection = $em->getConnection();
        $errors = self::notLiveParentErrors($em, $values);

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
     * `UpdatePurchaseOrder`'s half of the check above: the parents this edit
     * names must be live, exactly as a create's must. A parent the edit does not
     * name is not re-checked here — the order already points at it, and whether
     * that is still acceptable is the approval's question (`API.md` §8.1 step 6
     * re-checks the warehouse).
     *
     * @param array<string, string|null> $submitted
     */
    private function refuseParentsThatAreNotLive(EntityManagerInterface $em, array $submitted): void
    {
        $errors = self::notLiveParentErrors($em, $submitted);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * One home for "a live supplier / warehouse in this workspace", shared by
     * the create and the edit so the two cannot drift apart again. Only the keys
     * present in `$values` are checked; RLS makes another tenant's id and a
     * missing one the same answer (ST-5).
     *
     * @param array<string, string|null> $values
     *
     * @return list<array{pointer: string, code: string, message: string}>
     */
    private static function notLiveParentErrors(EntityManagerInterface $em, array $values): array
    {
        $errors = [];

        foreach (['supplierId' => 'supplier', 'warehouseId' => 'warehouse'] as $property => $table) {
            if (!\array_key_exists($property, $values)) {
                continue;
            }

            if (false === $em->getConnection()->fetchOne("SELECT 1 FROM $table WHERE id = ?::uuid AND deleted_at IS NULL", [$values[$property]])) {
                $errors[] = self::error($property, 'unknown_reference', 'No live '.$table.' in this workspace has this id.');
            }
        }

        return $errors;
    }

    /**
     * The next free document number for this workspace.
     *
     * Over **every** row, archived ones included — **the owner's Q4 ruling,
     * extended to purchasing on 2026-09-21: a deleted purchase order's number is
     * never issued again.** Until then this counted live rows only, matching
     * `purchase_order_tenant_number_live`'s partiality, so deleting the newest
     * draft (`DeletePurchaseOrder`, shipped 2026-09-10) handed its number to the
     * next order; the two then shared a number for good and a future
     * `RestorePurchaseOrder` could never bring the archived one back. The unique
     * stays partial (`SchemaManifest` requires it on a soft table) and is now a
     * race guard only. `MAX` over all rows cannot use the partial index; said
     * here so nobody "restores" the predicate for speed. The sales twin is
     * `SalesOrderWriter::nextOrderNumber()`; both are held by
     * `…CreateTest::a…NumberIsNeverIssuedAgain`.
     *
     * Bounded to numbers this method could have minted. The regexp was written
     * to stop a hand-seeded `PO-DRAFT-1` turning the cast into an error; since
     * CD-7 (`purchase_order_number_shape`, 2026-09-28) no such row can exist, so
     * it is a belt over the CHECK, kept because it costs nothing. RLS supplies
     * the tenant bound, so no `tenant_id` predicate is written here.
     */
    private function nextOrderNumber(EntityManagerInterface $em): string
    {
        $next = (int) $em->getConnection()->fetchOne(<<<'SQL'
            SELECT COALESCE(MAX(substring(order_number from 4)::bigint), 0) + 1
              FROM purchase_order
             WHERE order_number ~ '^PO-[0-9]+$'
            SQL);

        // CD-7 (owner ruling CD7-1, 2026-09-28): `purchase_order_number_shape` pins the number to exactly six digits, so the
        // millionth is refused here in domain terms rather than minted seven digits wide (which text-sorts beside
        // `…100000`) or left to the CHECK as a 23514 -> 500. Counted over every row, archived ones included (Q4:
        // numbers are never reissued). ⚠ This runs from the INSERT, i.e. AFTER the replay read, deliberately: a
        // retry of the 999,999th create must still get its own document back (D-B) -- do not hoist it into an
        // early pre-flight.
        if ($next > 999999) {
            throw ApiProblem::businessRuleViolated(
                'purchase_order.number_series_is_exhausted',
                'This workspace has used every purchase order number from PO-000001 to PO-999999, so no further order can be raised. Numbers are never reissued.',
            );
        }

        return 'PO-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The order this key already created, or null if it has created none.
     *
     * Read **inside the caller's transaction**, which is what makes it the
     * replay answer rather than a guess: the same transaction then either
     * returns this row or writes a new one, and the unique index adjudicates
     * anything that commits in between.
     *
     * `deleted_at IS NULL` matches the index's own partiality. An archived order
     * therefore does not answer a replay — and that is correct: the index no
     * longer holds its key, so a retry after an archive genuinely may create a
     * new order.
     *
     * **D-B: a key is a replay only if the request is the same one.** The stored
     * `request_digest` is compared with this request's; a mismatch is §6.2's
     * `idempotency-key-conflict` (409), thrown before anything is written, so the
     * transaction rolls back clean. A keyed row with **no** digest cannot prove
     * the requests match and is answered as a conflict too — failing closed,
     * because the alternative hands out an order the caller may not have asked
     * for. `purchase_order_request_digest_with_key` makes that row impossible;
     * the branch is written anyway rather than argued.
     *
     * The comparison is against the digest, **never against the row**:
     * `UpdatePurchaseOrder` may have moved the draft's supplier, warehouse or
     * `expected_at` since, and a late retry of a request that succeeded is still
     * that request.
     */
    private function orderCreatedByKeyInside(EntityManagerInterface $em, string $keyHash, string $digest): ?PurchaseOrderResource
    {
        $row = $em->getConnection()->fetchAssociative(
            'SELECT id, request_digest FROM purchase_order WHERE idempotency_key = ? AND deleted_at IS NULL',
            [$keyHash],
        );

        if (false === $row) {
            return null;
        }

        if (!\is_string($row['request_digest']) || !hash_equals($row['request_digest'], $digest)) {
            // The hint exists for one difference a developer will not think to
            // look for: **the order of `lines` is part of this request**, because
            // `line_number` is minted from the position. Everything else that
            // makes two requests different -- a changed supplier, a changed
            // quantity -- is the kind of thing somebody diffing two bodies spots
            // immediately. It is this operation's and not the shared sentence's,
            // because the same reorder is a replay on `CreateGoodsReceipt`.
            throw ApiProblem::idempotencyKeyConflict(
                'The lines are compared in the order they were sent, because a line\'s number comes from its position.',
            );
        }

        return $this->readOrderInside($em, (string) $row['id']);
    }

    /**
     * The line this key already created, or null — `orderCreatedByKeyInside()`'s rules, one table down (V2-R-12).
     *
     * `deleted_at IS NULL` matches the index's partiality. A digest mismatch — another body, **or another order**, since
     * the path id is digested — is §6.2's 409, thrown before anything is written. A keyed row without a digest cannot
     * exist (`purchase_order_line_request_digest_with_key`) and is answered as a conflict anyway, failing closed.
     */
    private function lineCreatedByKeyInside(EntityManagerInterface $em, string $orderId, string $keyHash, string $digest): ?PurchaseOrderLineResource
    {
        $row = $em->getConnection()->fetchAssociative(
            'SELECT id, request_digest FROM purchase_order_line WHERE idempotency_key = ? AND deleted_at IS NULL',
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
     * D-B's digest of a single-line create: the **path's order id** plus the three accepted fields, normalised as
     * `digestedLines()` normalises a nested line (UUID canonical form, quantity at scale 6, cost at scale 4).
     *
     * @param array<string, string|null> $values already shape-checked
     */
    private static function lineDigest(string $orderId, array $values): string
    {
        return RequestDigest::of(self::CREATE_LINE_OPERATION, [
            'purchase_order_id' => RequestDigest::uuid($orderId),
            'inventory_item_id' => RequestDigest::uuid($values['inventoryItemId']),
            'quantity' => bcadd((string) $values['quantity'], '0', 6),
            'unit_cost' => bcadd((string) $values['unitCost'], '0', 4),
        ]);
    }

    /**
     * Whether a unique violation names a particular index.
     *
     * Read out of the driver's message because that is where Postgres puts it —
     * `duplicate key value violates unique constraint "<name>"` — and DBAL's
     * exception carries no structured constraint name. The alternative is
     * treating every `23505` on this table the same, which would answer a
     * document-number race by handing the loser somebody else's order.
     */
    private static function violated(UniqueConstraintViolationException $e, string $index): bool
    {
        return str_contains($e->getMessage(), $index);
    }

    /** @param array<string, string|null> $values */
    private function refuseMalformedOrderFields(array $values): void
    {
        $errors = [];

        // Both parent columns are NOT NULL, so neither may be cleared by a
        // PATCH. `expected_at` is nullable and `null` legitimately means "clear
        // the expected date".
        foreach (['supplierId', 'warehouseId'] as $property) {
            if (\array_key_exists($property, $values) && null === $values[$property]) {
                $errors[] = self::error($property, 'not_nullable', 'This field may not be cleared.');
            }
        }

        foreach (['supplierId', 'warehouseId'] as $property) {
            $value = $values[$property] ?? null;
            if (null !== $value && !Uuid::isValid($value)) {
                $errors[] = self::error($property, 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.');
            }
        }

        if (null !== ($values['expectedAt'] ?? null) && null === self::parseTimestamp($values['expectedAt'])) {
            $errors[] = self::error('expectedAt', 'not_a_timestamp', 'This field must be an RFC 3339 timestamp.');
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * @param array<string, string|null> $values
     * @param list<string>               $required
     */
    private function refuseMalformedLineFields(array $values, array $required): void
    {
        if ([] !== ($errors = self::malformedLineFields($values, $required))) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * The same rules, returned rather than thrown, so the nested create can
     * collect them across every line and re-point each one at
     * `/lines/<n>/<field>`. **There is no second copy of any rule below**: the
     * nested path's pointers are these pointers with a prefix, which is what
     * makes a mutation that drops the prefix visible as a failing pointer
     * assertion rather than as a silently different message.
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

        // Every column a line write touches is NOT NULL, so none may be cleared.
        foreach (array_keys($values) as $property) {
            if (null === $values[$property] && !\in_array($property, $required, true)) {
                $errors[] = self::error($property, 'not_nullable', 'This field may not be cleared.');
            }
        }

        if (null !== ($values['inventoryItemId'] ?? null) && !Uuid::isValid($values['inventoryItemId'])) {
            $errors[] = self::error('inventoryItemId', 'not_a_uuid', 'This field must be a UUID naming an item in this workspace.');
        }

        // `purchase_order_line_qty_sane` is `quantity_ordered > 0`, so zero and
        // negative are refused here rather than at the database -- a CHECK
        // violation is a 500 with no pointer, and §5.2 wants the field named.
        $quantity = $values['quantity'] ?? null;
        if (null !== $quantity && (1 !== preg_match(self::QUANTITY, $quantity) || 0 === bccomp($quantity, '0', 6))) {
            $errors[] = self::error('quantity', 'not_a_positive_decimal', 'This field must be a positive decimal string with at most 13 integer digits and 6 fractional digits.');
        }

        $unitCost = $values['unitCost'] ?? null;
        if (null !== $unitCost && 1 !== preg_match(self::MONEY, $unitCost)) {
            $errors[] = self::error('unitCost', 'not_a_decimal', 'This field must be a non-negative decimal string with at most 15 integer digits and 4 fractional digits.');
        }

        // ⚠ **Two fields each inside their own column can still produce a total
        // that is not.** `quantity_ordered` admits 13 integer digits and
        // `unit_cost` 15, and their product lands in
        // `purchase_order.total_amount`, which is `numeric(19,4)` — fifteen. One
        // line at the top of `unit_cost`'s range therefore made
        // `recomputeTotal()`'s UPDATE raise 22003, which is neither of the two
        // unique violations `create()` names, so it reached the client as a
        // **500 with no pointer** for a request whose every field was inside the
        // range this API published. Checked here, where both values are known and
        // no transaction is open, and on the field that is almost always the one
        // that is wrong.
        //
        // ⚠ **This bound is about ONE line and it always was.** It reads nothing
        // else on the order, so two lines each inside it still sum past the
        // column — measured over HTTP as a 500 on `CreatePurchaseOrderLine` with
        // a second line of `1 × 1` (RED probe P1). What closes that is
        // `recomputeTotal()`'s server-derived backstop, not this clause. This one
        // survives for the pointer it can give: it names the offending line
        // *before* any transaction opens, which the backstop cannot do for a
        // nested create.
        //
        // **Scale 10, rounded once, for the reason `recomputeTotal()` records:**
        // the product is exact at scale 10 and bcmath truncates, so a bound taken
        // at scale 4 was below the value the database would store rather than
        // above it. It cannot refuse divergently — every product here is
        // non-negative, so a line whose own rounded product exceeds the column
        // makes a sum that exceeds it too, and `round()` is monotonic.
        if ([] === $errors && null !== $quantity && null !== $unitCost
            && !self::fitsInTheTotalColumn(self::roundedToMoney(bcmul($quantity, $unitCost, self::PRODUCT_SCALE)))) {
            $errors[] = self::error('unitCost', 'total_out_of_range', self::TOTAL_OUT_OF_RANGE);
        }

        return $errors;
    }

    /**
     * Whether a money value fits `purchase_order.total_amount` — `numeric(19,4)`,
     * so fifteen integer digits.
     *
     * **The bound is `MONEY` itself**, the expression that already states that
     * column family's shape for values arriving, rather than a second literal
     * fifteen. It therefore takes a value **already rounded to scale 4**, and
     * every caller must round before calling.
     *
     * ⚠ **That "must" used to read as a feature, and it was the defect.** This
     * docblock said *"`bcmul`/`bcadd` at scale 4 always produce four decimals and
     * never a sign here, so the two sides of the column are checked by one
     * expression"*. The regex sentence is true; the reasoning was not. bcmath's
     * scale argument **truncates** and Postgres **rounds**, so a caller that
     * produced its four decimals by truncating handed this method a number
     * strictly *below* what would be stored — a lower bound presented as an upper
     * one. Scale 4 is what `MONEY` can judge, and it is the caller's job to reach
     * it the way the database does: {@see self::roundedToMoney()}, or
     * `round(…, 4)` on the server as {@see self::recomputeTotal()} does.
     */
    private static function fitsInTheTotalColumn(string $value): bool
    {
        return 1 === preg_match(self::MONEY, $value);
    }

    /**
     * A scale-10 value as `numeric(19,4)` will store it: **rounded half away from
     * zero**, which is Postgres's own rule for the cast, rather than bcmath's
     * truncation.
     *
     * Adding half a unit in the last place and letting `bcadd`'s scale argument
     * truncate is round-half-up; the sign test makes it half-away-from-zero, which
     * costs one comparison and means this does not become wrong the day a credit
     * line is admitted. Values here are non-negative today (`MONEY` and `QUANTITY`
     * both refuse a sign), so that branch is unreached and deliberately so.
     *
     * **Measured against the cluster rather than assumed** (2026-09-19):
     * `round(0.00005, 4)` → `0.0001`, `round(0.00025, 4)` → `0.0003` (so
     * half-away-from-zero, not half-to-even), `round(-0.00005, 4)` → `-0.0001`.
     * Ported from `SalesOrderWriter::roundedToMoney()` (`98643f9`).
     */
    private static function roundedToMoney(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.00005' : '0.00005', 4);
    }

    /**
     * **§4.2's `lines[]` sub-fields, as the client spells them.**
     *
     * Derived from `POINTERS` rather than written out a second time, so the set
     * the writer accepts and the pointers it refuses with cannot disagree.
     * `PurchaseOrderOperationContractTest` reads this to close the blind spot
     * `ContractDocument::acceptedFields()` leaves: that reader drops every
     * `lines[].*` token, so nothing compared the nested cell with the code until
     * this method existed.
     *
     * @return list<string>
     */
    public static function nestedLineFields(): array
    {
        return array_map(static fn (string $property): string => ltrim(self::POINTERS[$property], '/'), self::NESTED_LINE_PROPERTIES);
    }

    /**
     * **The shape of `lines`, decidable without the database**, so it runs before
     * a transaction is opened — `GoodsReceiptWriter::lines()`'s posture, for its
     * reason.
     *
     * Returns null when the field was **absent**, which is F-2's empty draft and
     * is legal; every other answer is a non-empty list. The two are kept apart all
     * the way to the digest, where absent means "no `lines` key at all" and
     * therefore digests exactly as a pre-8a request did.
     *
     * ⚠ **`lines: []` is refused**, and the reason is not only that an order with
     * nothing on it says nothing omitting the field does not say. 6b named the
     * residual: in canonical JSON an empty list and an empty object both encode as
     * `[]`, so admitting an empty list would put a value into the digest that
     * cannot be told from a different one. Refusing it means the digest never sees
     * an empty list and the question does not arise. 6a's approval refusal is the
     * separate rule that stops an empty order being placed.
     *
     * Shape errors are collected across **every** line, because they are one
     * problem type; the two database rules are not, and
     * {@see self::insertLineInside()} says why.
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
                'message' => 'A purchase order raised with lines must carry at least one. Omit the field to raise an empty draft.',
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
                'message' => \sprintf('A purchase order may be raised with at most %d lines in one request. '
                    .'Raise it with the first %d and add the rest through the lines sub-resource.', self::MAXIMUM_LINES, self::MAXIMUM_LINES),
            ]]);
        }

        $accepted = self::nestedLineFields();
        $errors = [];
        $lines = [];
        $total = '0.0000';

        foreach ($raw as $index => $line) {
            // An empty JSON object decodes to `[]`, which is also a list -- so an
            // empty object is admitted here and refused one clause down as three
            // missing required fields, which is what it is.
            if (!\is_array($line) || array_is_list($line) && [] !== $line) {
                $errors[] = [
                    'pointer' => '/lines/'.$index, 'code' => 'not_an_object', 'message' => 'Each line must be an object.',
                ];
                continue;
            }

            $before = \count($errors);

            // API-9 at the nesting level the deserializer cannot reach: `lines`
            // arrives as a decoded array, so `additionalProperties: false` says
            // nothing about its entries. **`expected_at` is refused here** as
            // undeclared (Trap 3): a line has no expected date (owner ruling Q2).
            foreach (array_diff(array_keys($line), $accepted) as $unknown) {
                $errors[] = [
                    'pointer' => '/lines/'.$index.'/'.$unknown, 'code' => 'undeclared', 'message' => 'This field is not accepted.',
                ];
            }

            // ⚠ **An unknown key does NOT stop this line's own fields being
            // checked.** It did until 2026-09-12, because the skip below tested
            // the whole error list: a line carrying one stray key was answered
            // with that key alone, the caller fixed it, and the same line came
            // back refused for a second reason it could have been told the first
            // time. `GoodsReceiptWriter` gets this right and the two nested-line
            // creates now collect alike. **Only a value that could not be READ
            // stops the checks**, because a check on a value that is not there is
            // a second, meaningless error about the same defect.
            $unreadable = [];
            $values = [];
            foreach (self::NESTED_LINE_PROPERTIES as $property) {
                $value = $line[ltrim(self::POINTERS[$property], '/')] ?? null;

                // The top-level fields are typed by the input DTO; these are not,
                // so a number where a decimal string belongs is named here rather
                // than coerced. S-12: money and quantities are strings.
                // **Load-bearing against a 500:** under `strict_types` an int
                // reaching `preg_match()`'s string parameter is a `TypeError`.
                if (null !== $value && !\is_string($value)) {
                    $errors[] = self::lineError($index, $property, 'not_a_string', 'This field must be sent as a string.');
                    $unreadable[] = $property;
                    continue;
                }

                $values[$property] = $value;
            }

            // A field whose value could not be read is dropped from BOTH lists,
            // so the shape check neither re-reports it as required nor as
            // un-clearable -- two more sentences about one defect. The line's
            // other fields are still checked, which is the whole of the
            // collection rule above.
            foreach (self::malformedLineFields($values, array_values(array_diff(self::NESTED_LINE_PROPERTIES, $unreadable))) as $error) {
                $error['pointer'] = self::nestedPointerPrefix($index).$error['pointer'];
                $errors[] = $error;
            }

            if (\count($errors) > $before) {
                continue;
            }

            // The case the per-line bound cannot see: every line fits and the
            // SUM does not. Accumulated over the lines that are themselves
            // sound, and reported against the one that tipped it -- which is the
            // only line the caller can act on.
            //
            // ⚠ **"Accumulated over the lines" was false while this ran at scale
            // 4**, and the sentence above said it anyway. The exact product of
            // `numeric(19,6) × numeric(19,4)` has scale 10 and bcmath's scale
            // argument **truncates**, so any line whose true product was under
            // `0.0001` moved this accumulator by **nothing** -- it was not
            // accumulated at all -- while Postgres carried it at full precision
            // and rounded it into the cast. `[1 × 999999999999999.9999,
            // 0.0001 × 0.5]` passed here and made `recomputeTotal()` raise 22003:
            // an HTTP 500 on the very sentence that says it cannot happen (RED
            // probe P3, and `98643f9` for the identical sales defect).
            //
            // At `PRODUCT_SCALE` every product and every addition is **exact**,
            // and {@see self::roundedToMoney()} applies the database's own
            // rounding once, at the check. So this is now an upper bound on what
            // will be stored, never a value below it, and it agrees with
            // `recomputeTotal()`'s server-derived backstop by construction rather
            // than by coincidence.
            $total = bcadd($total, bcmul((string) $values['quantity'], (string) $values['unitCost'], self::PRODUCT_SCALE), self::PRODUCT_SCALE);
            if (!self::fitsInTheTotalColumn(self::roundedToMoney($total))) {
                $errors[] = self::lineError($index, 'unitCost', 'total_out_of_range', self::TOTAL_OUT_OF_RANGE);
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
     * The lines as §6.2 digests them — **in the order they were sent**, because
     * `line_number` is minted from the position and two lines the other way round
     * are a different order. `RequestDigest`'s docblock names this operation as
     * the caller that makes list order contract.
     *
     * Quantities at scale 6 and costs at scale 4, the columns' own scales, so
     * `"2"`, `"2.0"` and `"2.000000"` are one request.
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
            'unit_cost' => bcadd((string) $line['unitCost'], '0', 4),
        ], $lines);
    }

    /**
     * `Rfc3339Timestamp`'s, so this validator and the request digest share one
     * idea of a timestamp. It refuses an impossible date (30 February) and the
     * values Postgres would roll over (`24:00`, `:60`) instead of accepting the
     * day they roll to.
     */
    private static function parseTimestamp(string $value): ?\DateTimeImmutable
    {
        return Rfc3339Timestamp::parse($value);
    }

    private function refuseIfArchived(PurchaseOrderResource $order): void
    {
        if (null !== $order->archivedAt) {
            throw ApiProblem::businessRuleViolated(
                'purchase_order.is_archived',
                'This purchase order is archived. Restore it before changing it.',
            );
        }
    }

    /**
     * **L-5 and L-8, in the one place either is written.**
     *
     * The legal status is read off the entity's own constant rather than spelled
     * as a literal, so a rename of `STATUS_DRAFT`'s value moves this rule with it
     * — the same discipline API-15 requires of a transition guard.
     */
    private function refuseUnlessDraft(PurchaseOrderResource $order): void
    {
        if (PurchaseOrder::STATUS_DRAFT !== $order->status) {
            throw ApiProblem::businessRuleViolated(
                'purchase_order.is_editable_only_while_draft',
                'This purchase order has been placed, so it is read-only except for its transitions.',
            );
        }
    }

    private static function lineArchived(): ApiProblem
    {
        return ApiProblem::businessRuleViolated(
            'purchase_order_line.is_archived',
            'This line is archived, and a line is restored with the order it belongs to.',
        );
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $property, string $code, string $message): array
    {
        return self::lineError(null, $property, $code, $message);
    }

    /**
     * The same refusal, addressed at a line of `lines[]` when the nested create
     * raised it. **0-based**, matching `GoodsReceiptWriter` and the array index
     * the pointer names.
     *
     * @return array{pointer: string, code: string, message: string}
     */
    private static function lineError(?int $index, string $property, string $code, string $message): array
    {
        return [
            'pointer' => self::nestedPointerPrefix($index).self::POINTERS[$property],
            'code' => $code,
            'message' => $message,
        ];
    }

    /** `''` for a top-level field, `/lines/<n>` for one inside the nested create. */
    private static function nestedPointerPrefix(?int $index): string
    {
        return null === $index ? '' : '/lines/'.$index;
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    private function translatingConstraintFailures(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            // Told apart BY NAME, and anything else is rethrown (V2-R-12). Until
            // 2026-09-16 every unique violation here was answered as the
            // line-number race, which was true only while that was the table's
            // one unique a write could reach.
            if (self::violated($e, self::LINE_IDEMPOTENCY_INDEX)) {
                // The same key committed on ANOTHER order between this request's
                // key lookup and its INSERT: a same-order retry would have waited
                // at the order's row lock and then found the line. Another order
                // is in the digest, so this is a key reused for a different
                // request -- §6.2's 409, as `GoodsReceiptWriter` answers the same
                // race. "Try again" would be advice into a wall: the key is spent.
                throw ApiProblem::idempotencyKeyConflict();
            }

            if (self::violated($e, self::LINE_NUMBER_INDEX)) {
                // Two concurrent creates picking the same MAX+1. A genuine
                // conflict rather than a client mistake; the answer is to retry.
                throw ApiProblem::businessRuleViolated(
                    'purchase_order_line.line_number_is_unique_per_order',
                    'Another line was added to this order at the same moment. Try again.',
                );
            }

            throw $e;
        } catch (ForeignKeyConstraintViolationException) {
            // `purchase_order_supplier_fk` and `purchase_order_warehouse_fk` are
            // composite on `(tenant_id, id)`, so an id belonging to another
            // tenant fails here rather than at a lookup -- and ST-5 means the
            // caller must not learn which of "wrong tenant" and "no such row" it
            // was. Both fields are named because the statement does not say
            // which key failed.
            throw ApiProblem::validationFailed([
                self::error('supplierId', 'unknown_reference', 'One of the ids sent names no row in this workspace.'),
                self::error('warehouseId', 'unknown_reference', 'One of the ids sent names no row in this workspace.'),
            ]);
        }
    }
}
