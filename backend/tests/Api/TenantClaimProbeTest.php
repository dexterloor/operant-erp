<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\CountingKdf;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **R-29 (Critical) — a client-supplied `tenant_id` in a header, the query
 * string or the body does not change what any operation does.**
 *
 * ARCHITECTURE T-1, API-7 and ADR-005 D-5 say the tenant enters a request in one
 * place only: the `tenant_id` claim of a signed access token. Before this file
 * the only runtime check of that was one body field on one operation
 * (`AuthOperationContractTest::anUndeclaredFieldIsRefusedRatherThanIgnored`,
 * `IssueToken`). The register named `AuthTest::testTenantClaimIgnoresClientInput`,
 * which never existed.
 *
 * ---------------------------------------------------------------------------
 * What is sent
 * ---------------------------------------------------------------------------
 * The domain is the operation registry
 * (`TransactionBoundaryTest::contractOperations()`), never a list. For each
 * operation the request is sent once with no probe (the baseline) and then once
 * per probe, each carrying **tenant B's real id** while the token is tenant A's:
 *
 *   - three headers an implementation might plausibly honour: `X-Tenant-Id`,
 *     `Tenant-Id`, `X-Tenant`;
 *   - a `tenant_id` query parameter;
 *   - a `tenant_id` body field, on every operation that is not a `GET`.
 *
 * Every request runs against **freshly truncated and re-seeded state**, with
 * tenant B holding a mirror of tenant A's fixture — the same supplier, items,
 * warehouse, bin, draft order, placed order and receipt, under its own ids. So
 * an operation that served B instead of A would answer with B's rows (or a 404
 * for A's ids), and one that wrote into B would change B's rows; neither can
 * hide behind an empty tenant.
 *
 * ---------------------------------------------------------------------------
 * What must hold, per probe
 * ---------------------------------------------------------------------------
 *   1. **The outcome is the baseline's**: status, `Location` and body, with
 *      every fixture id replaced by its symbol (`A.item`, `B.draft`, …), other
 *      UUIDs and RFC 3339 instants masked, and a JWT reduced to its `tenant_id`
 *      and `sub` claims — plus the per-table row counts of tenant A after the
 *      request, so a write landing nowhere or twice is a different outcome.
 *   2. **Or it is the operation's existing refusal of an undeclared name** — and
 *      only for the query and body channels, where that refusal exists. It is
 *      not assumed: the same request is re-sent with the name
 *      `not_a_declared_name` instead of `tenant_id`, and the probe's outcome
 *      must equal that control's with the one name swapped, must be a 4xx, and
 *      the control must itself differ from the baseline (a refusal is only a
 *      refusal if the undeclared name is refused).
 *   3. **Tenant B's rows are untouched**: a digest of every tenant-scoped table
 *      (`SchemaManifest`, derived) read as B before and after the request.
 *   4. **No response names one of tenant B's rows** — baseline included.
 *
 * An operation this file does not know how to drive is a failure naming it, not
 * a skip; and a recipe for an operation the registry no longer declares is a
 * failure too. Every baseline must be a 2xx, so a probe is never compared with a
 * request that did nothing.
 */
final class TenantClaimProbeTest extends PurchaseOrderTestCase
{
    /** @var list<string> */
    private const HEADER_PROBES = ['X-Tenant-Id', 'Tenant-Id', 'X-Tenant'];

    private const PROBED_NAME = 'tenant_id';

    private const CONTROL_NAME = 'not_a_declared_name';

    private const PASSWORD = 'the-fixture-principal-password';

    /**
     * How to drive each operation so that its baseline succeeds. The method and
     * the path template are read off the registry; a recipe only fills the
     * template's variables and supplies the body. `@name` is replaced by the
     * acting tenant's fixture id of that name.
     *
     * @var array<string, array{vars?: array<string, string>, body?: array<string, mixed>|null, setup?: string, authenticated?: bool}>
     */
    private const RECIPES = [
        'IssueToken' => [
            'body' => ['workspace' => 'tenant-a', 'email' => '@actor_email', 'password' => self::PASSWORD],
            'authenticated' => false,
        ],
        'GetCurrentUser' => [],
        'ChangePassword' => [
            'body' => ['current_password' => self::PASSWORD, 'new_password' => 'a-different-password-of-some-length'],
        ],
        'ListItems' => [],
        'GetItem' => ['vars' => ['id' => 'item']],
        'CreateItem' => ['body' => ['sku' => 'SKU-NEW', 'name' => 'Widget', 'unit_of_measure_code' => 'EA']],
        'UpdateItem' => ['vars' => ['id' => 'item'], 'body' => ['name' => 'Renamed']],
        'DeleteItem' => ['vars' => ['id' => 'loose_item']],
        'RestoreItem' => ['vars' => ['id' => 'archived_item']],
        'ListPurchaseOrders' => [],
        'GetPurchaseOrder' => ['vars' => ['id' => 'draft']],
        'CreatePurchaseOrder' => ['body' => ['supplier_id' => '@supplier', 'warehouse_id' => '@warehouse']],
        'UpdatePurchaseOrder' => ['vars' => ['id' => 'draft'], 'body' => ['expected_at' => '2026-12-01T00:00:00+00:00']],
        'DeletePurchaseOrder' => ['vars' => ['id' => 'draft']],
        'ApprovePurchaseOrder' => ['vars' => ['id' => 'draft'], 'body' => []],
        'CancelPurchaseOrder' => ['vars' => ['id' => 'draft'], 'body' => []],
        'ListPurchaseOrderLines' => ['vars' => ['id' => 'draft']],
        'CreatePurchaseOrderLine' => [
            'vars' => ['id' => 'draft'],
            'body' => ['inventory_item_id' => '@catalogued_item', 'quantity' => '3.500000', 'unit_cost' => '4.5000'],
        ],
        'UpdatePurchaseOrderLine' => ['vars' => ['id' => 'draft', 'lineId' => 'draft_line'], 'body' => ['quantity' => '7.000000']],
        'DeletePurchaseOrderLine' => ['vars' => ['id' => 'draft', 'lineId' => 'draft_line']],
        'CreateGoodsReceipt' => [
            'vars' => ['id' => 'placed'],
            'body' => ['lines' => [['purchase_order_line_id' => '@placed_line', 'quantity' => '1', 'storage_location_id' => '@bin']]],
        ],
        'GetGoodsReceipt' => ['vars' => ['id' => 'receipt'], 'setup' => 'receipt'],
    ];

    /** @var array<string, string> uuid => symbol, for the state the current request runs against */
    private array $symbols = [];

    /** @var array<string, array<string, string>> tenant letter => fixture name => id */
    private array $fixture = [];

    private ?string $passwordHash = null;

    private int $requestsSent = 0;

    /**
     * *Mutation, run 2026-09-14 (`var/cache/test` deleted before each run):*
     * `AuthenticationGate` builds the request's `Identity` with the
     * `X-Tenant-Id` header's value as its tenant when the header is present.
     * Red on the first operation, "ListItems probed via header X-Tenant-Id
     * answered with one of tenant B's rows (B.item)"; with the loop narrowed
     * to two writes, red at "CreateItem probed via header X-Tenant-Id changed
     * tenant B's rows". The rest of the suite, with this test excluded, stayed
     * green under it: OK (717 tests, 6327 assertions).
     *
     * *Second mutation, run the same day:* `ItemProvider` (`GetItem`) switches
     * the identity's tenant to a `tenant_id` query parameter. Red, "GetItem with
     * tenant B's id in query tenant_id changed the outcome, and the operation
     * does NOT refuse an undeclared name there" — 200 with `A.item` against a
     * 404. The rest of the suite stayed green under it too: OK (717 tests,
     * 6327 assertions). Both reverted; green.
     *
     * ⚠ **That second note went stale at `4e99cdd` (step F2, 2026-09-15)** and is
     * kept only as history (V1-R-7, V4 B4b). `UndeclaredQueryParameterRefusal`
     * now refuses `?tenant_id` on every operation before `read`, so the provider
     * is never reached. **Re-run 2026-09-16** (Rule 17, cache deleted before and
     * after): the same `ItemProvider` switch → `OK (1 test, 1296 assertions)` —
     * GREEN, the correct outcome, and no longer evidence. **The query-channel
     * mutation that still reaches this check** is to *declare* the name and read
     * it: `parameters: ['tenant_id' => new QueryParameter(key: 'tenant_id')]` on
     * `GetItem`, plus the same `ItemProvider` switch → RED, "GetItem with tenant
     * B's id in query tenant_id was refused, but not the way the operation
     * refuses any undeclared name. R-29." (404 `not-found` against the control's
     * 422 `undeclared_parameter`). Reverted; green. Since F2 the query and body
     * channels stop at refusal gates on every built operation; only the header
     * channel reaches operation logic undeclared.
     */
    #[Test]
    public function aClientSuppliedTenantIdInAHeaderTheQueryOrTheBodyChangesNoOperationsOutcome(): void
    {
        $operations = TransactionBoundaryTest::contractOperations();

        // The positive control, inside the test that depends on it (a sibling
        // would not protect a --filter run): the registry is not empty, and the
        // recipes and the registry name the same operations, both ways.
        self::assertNotSame([], $operations, 'The registry produced no operations, so this test would assert nothing.');

        $unrecipedOperations = array_values(array_diff(array_keys($operations), array_keys(self::RECIPES)));
        self::assertSame([], $unrecipedOperations,
            'These operations are in the registry and this test does not know how to drive them, so no probe would '
            .'ever reach them. Add a recipe (fixture ids for the path, a body the baseline succeeds with).');
        $staleRecipes = array_values(array_diff(array_keys(self::RECIPES), array_keys($operations)));
        self::assertSame([], $staleRecipes, 'These recipes name operations the registry no longer declares.');

        $driven = [];
        $probesPerChannel = ['header' => 0, 'query' => 0, 'body' => 0];

        foreach ($operations as $name => $operation) {
            $name = (string) $name;
            $baseline = $this->drive($name, $operation, null);

            self::assertGreaterThanOrEqual(200, $baseline['status'], $name.' baseline: '.$this->describe($baseline));
            self::assertLessThan(300, $baseline['status'],
                $name.'\'s baseline did not succeed, so a probe compared with it would be compared with nothing: '
                .$this->describe($baseline));

            foreach ($this->probesFor($operation) as [$channel, $where]) {
                $probed = $this->drive($name, $operation, [$channel, $where, self::PROBED_NAME]);
                ++$probesPerChannel[$channel];

                if ($probed === $baseline) {
                    continue;
                }

                $label = $name.' with tenant B\'s id in '.$channel.' '.$where;

                self::assertNotSame('header', $channel,
                    $label.' changed the outcome. R-29: the tenant enters only as a signed claim.'
                    ."\nBaseline: ".$this->describe($baseline)."\nProbed:   ".$this->describe($probed));

                $control = $this->drive($name, $operation, [$channel, $where === self::PROBED_NAME ? self::CONTROL_NAME : $where, self::CONTROL_NAME]);

                self::assertNotSame($baseline, $control,
                    $label.' changed the outcome, and the operation does NOT refuse an undeclared name there, so the '
                    .'change is the tenant id being read. R-29.'
                    ."\nBaseline: ".$this->describe($baseline)."\nProbed:   ".$this->describe($probed));
                self::assertGreaterThanOrEqual(400, $probed['status'], $label.': '.$this->describe($probed));
                self::assertLessThan(500, $probed['status'], $label.': '.$this->describe($probed));
                self::assertSame(
                    $this->describe($control),
                    str_replace(self::PROBED_NAME, self::CONTROL_NAME, $this->describe($probed)),
                    $label.' was refused, but not the way the operation refuses any undeclared name. R-29.',
                );
            }

            $driven[] = $name;
        }

        sort($driven);
        $declared = array_keys($operations);
        sort($declared);

        self::assertSame($declared, $driven, 'Not every operation in the registry was driven.');
        self::assertGreaterThan(0, $probesPerChannel['header']);
        self::assertGreaterThan(0, $probesPerChannel['query']);
        self::assertGreaterThan(0, $probesPerChannel['body'], 'No write operation was probed through its body.');
        self::assertSame(\count($operations) * \count(self::HEADER_PROBES), $probesPerChannel['header']);
        self::assertSame(\count($operations), $probesPerChannel['query']);
        self::assertGreaterThanOrEqual(\count($operations) * 5, $this->requestsSent - $probesPerChannel['body'],
            'Fewer requests went out than one baseline, three header probes and one query probe per operation.');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function probesFor(HttpOperation $operation): array
    {
        $probes = [];

        foreach (self::HEADER_PROBES as $header) {
            $probes[] = ['header', $header];
        }

        $probes[] = ['query', self::PROBED_NAME];

        if ('GET' !== $operation->getMethod()) {
            $probes[] = ['body', self::PROBED_NAME];
        }

        return $probes;
    }

    /**
     * One request against fresh state.
     *
     * @param array{0: string, 1: string, 2: string}|null $probe channel, where (header name or field name), the name placed
     *
     * @return array{status: int, location: string|null, body: mixed, tenant_a_rows: array<string, int>}
     */
    private function drive(string $name, HttpOperation $operation, ?array $probe): array
    {
        $recipe = self::RECIPES[$name];

        $this->freshState();

        if ('receipt' === ($recipe['setup'] ?? null)) {
            $this->seedReceipt('A');
            $this->seedReceipt('B');
        }

        $before = $this->tenantBDigest();

        $path = (string) $operation->getUriTemplate();
        $path = str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path;
        foreach ($recipe['vars'] ?? [] as $variable => $fixtureName) {
            $path = str_replace('{'.$variable.'}', $this->id('A', $fixtureName), $path);
        }
        self::assertStringNotContainsString('{', $path, $name.'\'s recipe leaves a path variable unfilled.');

        $body = \array_key_exists('body', $recipe) ? $this->resolve('A', $recipe['body']) : null;
        $headers = [];

        if (true === ($operation->getExtraProperties()['idempotency_key_required'] ?? null)) {
            $headers['Idempotency-Key'] = Uuid::v4()->toRfc4122();
        }

        if (false === ($recipe['authenticated'] ?? true)) {
            // Pre-authentication: the request carries no bearer at all.
            $headers['Authorization'] = '';
        }

        if (null !== $probe) {
            [$channel, $where, $placed] = $probe;

            match ($channel) {
                'header' => $headers[$where] = self::TENANT_B,
                'query' => $path .= '?'.http_build_query([$placed => self::TENANT_B]),
                'body' => $body = [...($body ?? []), $placed => self::TENANT_B],
            };
        }

        $this->send((string) $operation->getMethod(), $path, $body, $headers);
        ++$this->requestsSent;

        $response = $this->client()->getResponse();
        $content = (string) $response->getContent();
        $decoded = '' === $content ? null : json_decode($content, true);

        $outcome = [
            'status' => $response->getStatusCode(),
            'location' => $this->normalise($response->headers->get('Location')),
            'body' => $this->normalise(null === $decoded && '' !== $content ? $content : $decoded),
            'tenant_a_rows' => $this->tenantARowCounts(),
        ];

        $label = $name.(null === $probe ? ' (baseline)' : ' probed via '.$probe[0].' '.$probe[1]);

        self::assertSame($before, $this->tenantBDigest(),
            $label.' changed tenant B\'s rows. R-29: a request authenticated as A wrote into B.');

        $rendered = json_encode($outcome, \JSON_THROW_ON_ERROR);
        self::assertSame(0, preg_match('/\bB\.(?!tenant\b)[a-z_]+\b/', $rendered, $leak),
            $label.' answered with one of tenant B\'s rows ('.($leak[0] ?? '').'). R-29. '.$this->describe($outcome));

        return $outcome;
    }

    private function freshState(): void
    {
        $this->truncateTenantTables();
        // Not tenant-scoped, so the manifest truncate does not reach them, and
        // the sign-in budget is per IP: `AuthTestCase::setUp()` does the same.
        $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');
        $this->seedTenantRegistry();

        $this->symbols = [self::TENANT_A => 'A.tenant', self::TENANT_B => 'B.tenant'];
        $this->fixture = [];

        // B first, so that the rig's bearer and identity holder end on A.
        foreach (['B' => self::TENANT_B, 'A' => self::TENANT_A] as $letter => $tenantId) {
            $this->seedMirror($letter, $tenantId);
        }
    }

    /** The same shape for either tenant, so a request served from B has somewhere to find a row. */
    private function seedMirror(string $letter, string $tenantId): void
    {
        $this->actAs($tenantId);
        $actor = (string) $this->actorId;
        $this->remember($letter, 'actor', $actor);
        $this->fixture[$letter]['actor_email'] = 'actor-'.$actor.'@example.test';
        $this->fixture[$letter]['token'] = $this->tokenFor($tenantId, $actor);

        // A real hash, so `IssueToken` and `ChangePassword` can succeed; and one
        // role name, because `seedPrincipal()` puts part of a random id in it.
        $this->passwordHash ??= $this->kdf()->hash(self::PASSWORD);
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE app_user SET password_hash = ? WHERE id = ?::uuid', [$this->passwordHash, $actor]);
        $this->insertAsOwnerForTenant($tenantId, "UPDATE role SET name = 'Fixture Role'");
        foreach ($this->fetchAsOwnerForTenant($tenantId, 'SELECT id::text AS id FROM role') as $row) {
            $this->remember($letter, 'role', (string) $row['id']);
        }

        $supplier = $this->remember($letter, 'supplier', $this->seedSupplier($tenantId, 'Acme'));

        $warehouse = $this->remember($letter, 'warehouse', Uuid::v4()->toRfc4122());
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, 'WH1', 'Main', 'DE')",
            [$warehouse, $tenantId]);
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01')",
            [$this->remember($letter, 'bin', Uuid::v4()->toRfc4122()), $tenantId, $warehouse]);

        $item = $this->remember($letter, 'item', $this->seedItem($tenantId, 'SKU-1', $supplier));
        $catalogued = $this->remember($letter, 'catalogued_item', $this->seedItem($tenantId, 'SKU-2', $supplier));
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO supplier_product
                (id, tenant_id, supplier_id, inventory_item_id, supplier_sku, lead_time_days, unit_cost, currency_code)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 'SP-2', 7, 1.0000, 'EUR')
            SQL, [$this->remember($letter, 'supplier_product', Uuid::v4()->toRfc4122()), $tenantId, $supplier, $catalogued]);
        $this->remember($letter, 'loose_item', $this->seedItem($tenantId, 'SKU-LOOSE'));
        $archived = $this->remember($letter, 'archived_item', $this->seedItem($tenantId, 'SKU-ARCHIVED'));
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $draft = $this->remember($letter, 'draft', $this->seedOrder($tenantId, 'PO-1', $supplier, $warehouse));
        $this->remember($letter, 'draft_line', $this->seedLine($tenantId, $draft, $item));

        $placed = $this->remember($letter, 'placed',
            $this->seedOrder($tenantId, 'PO-2', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED));
        $this->remember($letter, 'placed_line', $this->seedLine($tenantId, $placed, $item));

        foreach ($this->fetchAsOwnerForTenant($tenantId, 'SELECT id::text AS id FROM tenant_settings') as $row) {
            $this->remember($letter, 'tenant_settings', (string) $row['id']);
        }
    }

    /** A receipt booked through the operation itself, in the tenant's own token, so `GetGoodsReceipt` has a subject. */
    private function seedReceipt(string $letter): void
    {
        $this->send('POST', '/purchase-orders/'.$this->id($letter, 'placed').'/goods-receipts', [
            'lines' => [['purchase_order_line_id' => $this->id($letter, 'placed_line'), 'quantity' => '2', 'storage_location_id' => $this->id($letter, 'bin')]],
        ], [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
            'Authorization' => 'Bearer '.$this->id($letter, 'token'),
        ]);

        self::assertSame(201, $this->httpStatus(), 'The receipt fixture for tenant '.$letter.' was not booked: '
            .substr((string) $this->client()->getResponse()->getContent(), 0, 400));

        $this->remember($letter, 'receipt', (string) $this->json()['id']);
    }

    private function remember(string $letter, string $name, string $id): string
    {
        $this->fixture[$letter][$name] = $id;
        $this->symbols[strtolower($id)] = $letter.'.'.$name;

        return $id;
    }

    private function id(string $letter, string $name): string
    {
        return $this->fixture[$letter][$name] ?? throw new \LogicException('No fixture named '.$letter.'.'.$name);
    }

    private function resolve(string $letter, mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->resolve($letter, $v), $value);
        }

        return \is_string($value) && str_starts_with($value, '@') ? $this->id($letter, substr($value, 1)) : $value;
    }

    /** Fixture ids to symbols, other ids and instants masked, a JWT reduced to the two claims that name a principal. */
    private function normalise(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->normalise($v), $value);
        }

        if (!\is_string($value)) {
            return $value;
        }

        if (1 === preg_match('/^[A-Za-z0-9_-]+\.([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]+$/', $value, $jwt)) {
            $claims = json_decode((string) base64_decode(strtr($jwt[1], '-_', '+/'), true), true);

            if (\is_array($claims)) {
                return '{jwt tenant_id='.$this->normalise((string) ($claims['tenant_id'] ?? '')).' sub='.$this->normalise((string) ($claims['sub'] ?? '')).'}';
            }
        }

        $value = (string) preg_replace_callback(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            fn (array $m): string => $this->symbols[strtolower($m[0])] ?? '{uuid}',
            $value,
        );

        return (string) preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})/', '{instant}', $value);
    }

    /** @return array<string, string> table => row count and content digest, read as tenant B */
    private function tenantBDigest(): array
    {
        $digest = [];
        foreach ($this->fetchAsOwnerForTenant(self::TENANT_B, $this->perTableSql(true)) as $row) {
            $digest[(string) $row['t']] = $row['n'].':'.$row['d'];
        }

        return $digest;
    }

    /** @return array<string, int> */
    private function tenantARowCounts(): array
    {
        $counts = [];
        foreach ($this->fetchAsOwnerForTenant(self::TENANT_A, $this->perTableSql(false)) as $row) {
            $counts[(string) $row['t']] = (int) $row['n'];
        }

        return $counts;
    }

    private function perTableSql(bool $withDigest): string
    {
        $tables = SchemaManifest::tenantScopedTables();
        self::assertNotSame([], $tables, 'The manifest lists no tenant-scoped table, so the row checks would see nothing.');

        return implode(' UNION ALL ', array_map(
            static fn (string $table): string => \sprintf(
                "SELECT '%1\$s' AS t, count(*) AS n, %2\$s AS d FROM %1\$s x",
                $table,
                $withDigest ? "md5(coalesce(string_agg(x::text, '|' ORDER BY x::text), ''))" : "''",
            ),
            $tables,
        ));
    }

    /** @param array<string, mixed> $outcome */
    private function describe(array $outcome): string
    {
        return json_encode($outcome, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private function kdf(): CountingKdf
    {
        $kdf = self::getContainer()->get(CountingKdf::class);
        \assert($kdf instanceof CountingKdf);

        return $kdf;
    }
}
