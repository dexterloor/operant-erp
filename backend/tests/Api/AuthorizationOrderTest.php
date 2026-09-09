<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\Security\AuthenticationGate;
use App\Api\Security\PermissionGate;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * **E-1 and §5.3 — the order, which is the whole of it.**
 *
 * `API.md` §5.3's own *fails if* names three clauses and this file is written to
 * each of them:
 *
 *   1. a request for a row outside the caller's tenant returns anything but 404
 *      — `ItemReadTest::everyIdTheCallerCannotSeeGetsTheSameAnswer` (the caller
 *      there holds `.view`, which is the case that clause is about);
 *   2. a caller holding no `.view` receives 404 for an id in its own tenant and
 *      403 for one outside it — the oracle, and it is
 *      `aCallerWithoutThePermissionGetsTheSameStatusForEveryId` below;
 *   3. DSoD-1 refuses with 403 or 404 — E-19 is Output 9's, no operation reaches
 *      it, and no test here can have a subject.
 *
 * The document's own suggested mutation is *"move the voter call after the
 * repository fetch; the in-tenant id then answers 403 and the other two 404"*.
 * That mutation is run and recorded in each method's docblock.
 */
final class AuthorizationOrderTest extends ApiTestCase
{
    private const VIEW = 'inventory_item.view';

    /**
     * **The single most valuable assertion in this slice: a caller with no
     * permission cannot distinguish a real id from an invented one.**
     *
     * Three ids, chosen to be the three cases that could differ — one real row in
     * the caller's own tenant, one real row in another tenant, one that exists
     * nowhere. §5.2's `permission-denied` carries `required_permission` and
     * nothing instance-specific, so a difference anywhere in the response is a
     * channel.
     *
     * ---------------------------------------------------------------------------
     * What is compared, stated exactly, because the previous wording overclaimed
     * ---------------------------------------------------------------------------
     * This docblock said *"every byte of every response is compared"* while the
     * comparison built three keys — status, content type and body. It now compares
     * the **status, the full response header map and the body**, with the body's
     * length carried as its own key so a length difference is named in the failure
     * rather than inferred from a diff. Measured on this server before the
     * comparison was widened: all three responses are 403, 129 bytes, and carry an
     * identical header map (`vary`, `x-content-type-options`, `x-frame-options`,
     * `content-type`, `cache-control`, `date`, `x-robots-tag`) — so widening it
     * cost nothing and closed the gap between what the file claimed and what it
     * checked.
     *
     * **`date` is compared for presence and not for value, and it is the only
     * exemption.** Its value is a clock reading, so three requests that straddle a
     * second boundary would differ in it for a reason that carries no information
     * about the id — pinning it would make this a flaky clock test rather than a
     * stronger oracle check. Its **absence** on one response and not another would
     * still be a difference, and the key is kept in the compared map to catch that.
     *
     * ---------------------------------------------------------------------------
     * The one observable that is NOT compared here, and why it is not a defect
     * ---------------------------------------------------------------------------
     * **Statement counts on the `erp_app` connection differ across the three ids —
     * `{6, 6, 5}` — and that is E-3a, not an existence channel.** `RED` measured it
     * and then permuted the request order: the **5 follows the last request**, not
     * the invented id. It is the denial cap declining to write a third row for one
     * `(tenant, actor, code)` key in one UTC day (`DenialAuditWriter`), so the
     * short request is whichever one runs third.
     *
     * The consequence is structural: **a statement-count clause cannot be added to
     * this test without changing the cap**, because the cap makes the count a
     * function of request *order* rather than of the id. E-7's sixth observable —
     * the KDF invocation count, which `CountingPasswordVerifier` exists to expose
     * on the sign-in path — has **no analogue here by construction**: no password
     * is verified on an authenticated request, so there is no per-request
     * work counter for this path to be constant in. `theRowLoaderIsNotReachedOnADenial`
     * below is what covers the work side instead, and it asserts on *which* tables
     * were touched rather than on how many statements ran.
     *
     * **Mutation, run:** the permission check moved into `ItemProvider`, after
     * `ItemReader::get()` — i.e. §5.3's own suggested mutation. Result: the
     * in-tenant id answered **403** and the other two **404**, and this test went
     * red on `assertCount(1, ...)` with all three bodies printed. Restored.
     *
     * **Second mutation, run:** `$identity->holds()` changed to always return
     * true. All three become 200/404/404 — red on the first status assertion,
     * which is the anti-vacuity direction: a test where every response is
     * identical because nothing is ever refused would pass the uniqueness clause.
     */
    #[Test]
    public function aCallerWithoutThePermissionGetsTheSameStatusForEveryId(): void
    {
        // Seeded while the rig still holds every code, so the rows are real.
        $mine = $this->seedItem(self::TENANT_A, 'SKU-MINE');
        $theirs = $this->seedItem(self::TENANT_B, 'SKU-THEIRS');
        $nowhere = Uuid::v4()->toRfc4122();

        self::assertNotSame($mine, $theirs);

        // The caller E-1 is about: authenticated, in tenant A, holding nothing.
        $this->actAs(self::TENANT_A, []);

        $observed = [];

        foreach (['mine' => $mine, 'another tenant' => $theirs, 'nowhere' => $nowhere] as $label => $id) {
            $this->send('GET', '/items/'.$id);
            $response = $this->client()->getResponse();
            $body = (string) $response->getContent();

            $headers = $response->headers->all();
            ksort($headers);
            // The clock, reduced to its presence. See the docblock.
            if (\array_key_exists('date', $headers)) {
                $headers['date'] = ['<present>'];
            }

            $observed[$label] = [
                'status' => $response->getStatusCode(),
                'headers' => $headers,
                'body_length' => \strlen($body),
                'body' => $body,
            ];
        }

        // Read off the recorded response rather than off `$this->json()`, which
        // is whatever the last request in the loop returned: an assertion that
        // silently moves to a different subject is how a check stops being about
        // the case it names.
        $mineBody = json_decode($observed['mine']['body'], true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($mineBody));

        self::assertSame(403, $observed['mine']['status'],
            'A caller holding no permission was answered from the row rather than from the check.');
        self::assertSame('urn:erp:problem:permission-denied', $mineBody['type']);
        self::assertSame(self::VIEW, $mineBody['required_permission'],
            '§5.2: `permission-denied` carries `required_permission`, and ST-4b\'s screen needs it to say what is missing.');

        // Anti-vacuity on the exemption: the header the comparison stops short of
        // pinning by value must at least be there on all three, or "identical
        // headers" would be satisfied by a response that carries none.
        foreach ($observed as $label => $seen) {
            self::assertArrayHasKey('date', $seen['headers'], $label.' carries no date header.');
        }

        self::assertCount(1, array_unique(array_map(json_encode(...), $observed)),
            "The three ids are distinguishable to a caller with no permission — this is E-1's intra-tenant "
            ."existence oracle:\n".print_r($observed, true));
    }

    /**
     * The other half of the same rule, and it is what stops the assertion above
     * being satisfied by an API that refuses everything.
     *
     * Once the caller **holds** `.view`, the row is legitimately theirs to
     * enumerate and existence is disclosed: the in-tenant id is a 200 and the
     * other two are the same 404 (§5.3 clauses 1 and 2). So the 403/404 split is
     * a function of the *permission*, which is the property E-1 buys.
     *
     * **Mutation, run:** the permission check moved after the row load, as above.
     * This test stays green — which is exactly why it is not the only one here:
     * the oracle is invisible from the permitted caller's side. The pair is the
     * check, not either half.
     */
    #[Test]
    public function aCallerHoldingThePermissionSeesExistenceOnlyInsideItsOwnTenant(): void
    {
        $mine = $this->seedItem(self::TENANT_A, 'SKU-MINE');
        $theirs = $this->seedItem(self::TENANT_B, 'SKU-THEIRS');
        $nowhere = Uuid::v4()->toRfc4122();

        $this->actAs(self::TENANT_A, [self::VIEW]);

        $this->send('GET', '/items/'.$mine);
        self::assertSame(200, $this->httpStatus());

        $refusals = [];

        foreach (['another tenant' => $theirs, 'nowhere' => $nowhere] as $label => $id) {
            $this->send('GET', '/items/'.$id);
            $refusals[$label] = [$this->httpStatus(), (string) $this->client()->getResponse()->getContent()];
        }

        self::assertSame(404, $refusals['another tenant'][0]);
        self::assertCount(1, array_unique(array_map(json_encode(...), $refusals)),
            "A row in another tenant is distinguishable from one that never existed:\n".print_r($refusals, true));
        self::assertSame('urn:erp:problem:not-found', $this->json()['type']);
    }

    /**
     * **E-1's ordering, witnessed rather than described.**
     *
     * The response-shaped assertion above proves the *outcome*; this proves the
     * *mechanism*. Every statement the `erp_app` connection issues during a denied
     * request is recorded, and none of them may name the resource's table. A
     * denial that reached the row and then discarded it would produce identical
     * responses and fail here.
     *
     * The table name is derived from the permission code, which is how
     * `DenialAuditWriter` derives `subject_type` — not written down twice.
     *
     * **Mutation, run:** permission check moved into `ItemProvider`. The recorder
     * then contains `SELECT ... FROM inventory_item ...` and this is red, while
     * the response is still a 403. That is the pair of assertions this slice
     * needed: one on the answer, one on the work.
     *
     * **Anti-vacuity:** the same window is asserted to contain the denial's own
     * `audit_log` statements, so a recorder that was never wired — which would
     * satisfy the clause above trivially — fails.
     */
    #[Test]
    public function theRowLoaderIsNotReachedOnADenial(): void
    {
        $mine = $this->seedItem(self::TENANT_A, 'SKU-MINE');
        $this->actAs(self::TENANT_A, []);

        $table = strstr(self::VIEW, '.', true);
        self::assertIsString($table);

        $this->recorder()->start();
        $this->send('GET', '/items/'.$mine);
        $this->recorder()->stop();

        self::assertSame(403, $this->httpStatus());
        self::assertSame([], $this->recorder()->matching('/\b'.preg_quote($table, '/').'\b/i'),
            "The row was read on a request that was refused before it should have been:\n"
            .implode("\n", $this->recorder()->statements()));
        self::assertNotSame([], $this->recorder()->matching('/audit_log/i'),
            'Nothing at all was recorded on the application connection, so the clause above passed over an empty log.');
    }

    /**
     * **The ordering is structural, and this is the parameter it rests on.**
     *
     * With `api_platform.use_symfony_listeners: false` — the bundle default, and
     * this project sets no override — every provider and processor runs inside
     * `MainController`, so a `kernel.controller` listener cannot run after one.
     * Flip it to `true` and the row load moves to a `kernel.request` listener at
     * priority 4, **ahead of every `kernel.controller` listener**, and E-1 would
     * be gone with no response and no SQL changing shape in any other test here.
     *
     * **Mutation, run:** `use_symfony_listeners: true` in
     * `config/packages/api_platform.yaml`. This test is red — and, measured,
     * `theRowLoaderIsNotReachedOnADenial` above is **also** red, which is the
     * confirmation that this parameter is the thing holding the order up rather
     * than a coincidence worth asserting on its own.
     */
    #[Test]
    public function apiPlatformResolvesStateInsideTheControllerNotAListener(): void
    {
        self::assertFalse(self::getContainer()->getParameter('api_platform.use_symfony_listeners'),
            'API Platform is resolving state in kernel.request listeners. Those run at priority 4, before every '
            .'kernel.controller listener, so the row is loaded before PermissionGate — E-1 inverted by a '
            .'configuration flag.');
    }

    /**
     * The two gates are on `kernel.controller` and in the order 401 → 403, and
     * the priorities are read off the listener registration rather than off the
     * constants they are written with.
     *
     * A caller who cannot authenticate must not learn that an operation exists
     * which requires a permission, so the authentication gate has to be first;
     * `IdempotencyKeyGuard` has to be last, or a caller holding no permission
     * learns from a 428 that the operation is idempotent.
     *
     * **Mutation, run:** `PermissionGate::PRIORITY` raised to 48. This is red on
     * the ordering assertion, and
     * `aCallerWithoutTheTokenIsRefusedBeforeThePermissionCheck` in
     * `PermissionEnforcementTest` is red too — the unauthenticated request then
     * reaches a gate with no identity and raises the wiring `LogicException`.
     */
    #[Test]
    public function theGatesRunInTheOrderTheRefusalsHaveToArriveIn(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof \Symfony\Component\EventDispatcher\EventDispatcherInterface);

        $ordered = [];

        foreach ($dispatcher->getListeners(KernelEvents::CONTROLLER) as $listener) {
            $service = \is_array($listener) ? $listener[0] : $listener;
            $ordered[] = \is_object($service) ? $service::class : (string) $service;
        }

        $positions = [];
        foreach ([AuthenticationGate::class, PermissionGate::class, \App\Api\Idempotency\IdempotencyKeyGuard::class] as $gate) {
            $at = array_search($gate, $ordered, true);
            self::assertIsInt($at, $gate.' is not registered on kernel.controller at all. Listeners: '.implode(', ', $ordered));
            $positions[$gate] = $at;
        }

        self::assertTrue(
            $positions[AuthenticationGate::class] < $positions[PermissionGate::class]
            && $positions[PermissionGate::class] < $positions[\App\Api\Idempotency\IdempotencyKeyGuard::class],
            '401 -> 403 -> 428 is not the order these listeners run in: '.implode(', ', $ordered));
    }

    /** §5.3's clauses, as the document states them, so a reader of this file can see what it is written against. */
    #[Test]
    public function theRuleThisFileIsWrittenAgainstIsStillTheOneTheContractStates(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout, so the assertions above are checked against the code only. §5.3\'s three clauses are quoted in this class\'s own docblock, which is in the checkout, and each test method names the mutation it was proved with.');
        }

        $section = ContractDocument::sectionBetween('### 5.3 ', '## 6.');
        self::assertNotSame('', $section, '§5.3 was not found, so every clause below would be checked against nothing.');

        foreach ([
            'the intra-tenant existence oracle E-1 exists to close',
            'check runs **before** the row is loaded (E-1)',
            'refusal is `business-rule-violated` (E-2)',
        ] as $clause) {
            self::assertStringContainsString($clause, $section,
                '§5.3 no longer states: '.$clause);
        }
    }
}
