<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * B-3's guard, asserted on the outcome it exists for: **no row is ever written
 * at an id the client chose**, whatever the create answered.
 *
 * ---------------------------------------------------------------------------
 * Why this is a separate test from `ItemWriteTest::aCreateCarryingAnIdIsRefused`
 * ---------------------------------------------------------------------------
 * That test asserts the refusal first (422, `/id`) and looks for the row last,
 * so the only mutation that could reach its row check — a writer that honours
 * the input id — turns it red on `201 is identical to 422` and the row check
 * never runs (R4, `docs/session9/progress-R4C6.md`). The guard owner decision (c)
 * ruled is **the writers' server-side id minting**, and a check on that guard
 * has to be able to fail on the row, not on the status in front of it.
 *
 * So nothing here asserts a status. Every probe is sent and every lookup is
 * taken first; the no-row assertion is the first assertion that can fail on a
 * probe's outcome; the controls come after it.
 *
 * ---------------------------------------------------------------------------
 * The domain, derived
 * ---------------------------------------------------------------------------
 * **Every registry operation whose method is `POST`**
 * (`TransactionBoundaryTest::contractOperations()`), driven through its success
 * recipe (`OperationSuccessRecipes`) with an `id` planted into the body the
 * recipe sends. `POST` is the only method that mints a row: `PATCH` and `DELETE`
 * address an existing row by path, and `aPatchCarryingAnIdIsRefused` covers the
 * body `id` there. Taking every `POST` rather than only branch-4 creates costs
 * five more requests and means a transition that starts minting rows tomorrow is
 * already probed. Today that is `CreateItem`, `CreatePurchaseOrder`,
 * `CreatePurchaseOrderLine`, `CreateGoodsReceipt`, `ApprovePurchaseOrder`,
 * `CancelPurchaseOrder`, `RestoreItem`, `ChangePassword` and `IssueToken`.
 *
 * The lookup is **by table, not by operation**: every table the server lists
 * with a `uuid` column named `id`, read as the owner once per seeded tenant
 * (FORCE ROW LEVEL SECURITY binds the owner too). No operation-to-table mapping
 * is kept, so a writer that lands the chosen id in a table nobody expected is
 * still found.
 *
 * Controls, inside each method: the domain equals the recipes' `POST` keys and is
 * non-empty; the planting counter shows an id was actually sent; the table list
 * is non-empty; and **the same lookup finds the id of every row a `POST` created
 * without a plant** (at least one) — a lookup that cannot see a row the writer
 * produced would make the no-row assertion a constant.
 *
 * ---------------------------------------------------------------------------
 * Mutation — C4, 2026-09-15 (`docs/session10/progress-C4C7.md`)
 * ---------------------------------------------------------------------------
 * `backend/var/cache/test` deleted before the mutated run and before the revert
 * run (Rule 17). `CreateItem`'s writer made to honour the input id, **with every
 * refusal layer R4 names removed**: `public ?string $id` added to
 * `CreateItemInput`; `allow_extra_attributes` removed from `CreateItem`; the
 * `isset($data['id'])` block cut from the vendor `ItemNormalizer::denormalize`;
 * `ItemWriter::create()` reading `$input->id ?? Uuid::v4()`. RED on the no-row
 * assertion of `aTopLevelIdAClientChoseIsNeverWhereARowLands`, naming
 * `CreateItem id` and `inventory_item`; the sibling method stayed green (its
 * `CreateItem` body carries no list to plant into). Revert: GREEN.
 */
final class ClientChosenIdTest extends PurchaseOrderTestCase
{
    use OperationSuccessRecipes;

    private const TOP_LEVEL = 'top-level';
    private const NESTED = 'nested';

    /** Which plant `send()` applies to the next `POST`, or null for none. */
    private ?string $plant = null;

    /** @var list<array{label: string, id: string}> the ids the last planted request carried */
    private array $planted = [];

    private string $plantingFor = '';

    #[Test]
    public function aTopLevelIdAClientChoseIsNeverWhereARowLands(): void
    {
        $this->assertNoPlantedIdLandsAnywhere(self::TOP_LEVEL);
    }

    /**
     * The same, one level down: `lines[].id` on a create that writes its lines
     * with the header. The domain is every `POST` whose recipe body carries a
     * list of objects (`CreateGoodsReceipt` today); a top-level refusal would
     * mask a nested one, so this is a separate request and a separate method.
     */
    #[Test]
    public function aNestedIdAClientChoseIsNeverWhereARowLands(): void
    {
        $this->assertNoPlantedIdLandsAnywhere(self::NESTED);
    }

    /**
     * Collect every probe, then assert. See the class docblock for the order.
     */
    private function assertNoPlantedIdLandsAnywhere(string $mode): void
    {
        $posts = array_keys(array_filter(
            TransactionBoundaryTest::contractOperations(),
            static fn ($operation): bool => 'POST' === $operation->getMethod(),
        ));
        $recipes = array_intersect_key($this->successRecipes(), array_flip($posts));
        sort($posts);
        $recipeNames = array_keys($recipes);
        sort($recipeNames);

        $tables = $this->tablesWithAUuidId();

        /** @var list<array{label: string, id: string}> $chosen */
        $chosen = [];
        $answers = [];
        $minted = [];

        // Every fixture before any request. A request that trips a constraint
        // closes the entity manager the fixtures write through, and a probe the
        // collection loop cannot finish is an error, not the red this test owes.
        $fixtures = [];
        foreach ($recipes as $name => [$prepare]) {
            $fixtures[$name] = $prepare();
        }

        foreach ($recipes as $name => [, $drive]) {
            $this->plant = $mode;
            $this->planted = [];
            $this->plantingFor = $name;
            $drive($fixtures[$name]); // The recipe's own expected status is deliberately not read.
            $this->plant = null;

            $probe = $this->planted;
            $status = $this->client()->getResponse()->getStatusCode();
            $answers[] = $name.' '.$status;

            // A row the request reports creating — planted or not — is a row the
            // lookup must be able to see.
            $minted = [...$minted, ...$this->mintedBy($name)];

            if ([] === $probe) {
                continue; // Nothing to plant into (a nested pass over a flat body): that request was the control.
            }

            $chosen = [...$chosen, ...$probe];

            // The control drive, on the same fixture and without a plant, only
            // where the probe was refused: re-driving an accepted create would
            // collide with the row the probe wrote.
            if ($status >= 400) {
                $drive($fixtures[$name]);
                $minted = [...$minted, ...$this->mintedBy($name)];
            }
        }

        $landed = $this->rowsAt($tables, array_column($chosen, 'id'));
        $found = $this->rowsAt($tables, array_values($minted));

        // ---- Assertions. The no-row assertion is the first that reads a probe.
        self::assertSame($recipeNames, $posts, 'Every registry POST must have a success recipe to probe it with.');
        self::assertNotSame([], $tables, 'The server listed no table with a uuid id, so the lookup had no subject.');
        self::assertNotSame([], $chosen, 'No planted id was sent, so the no-row assertion had no subject.');

        $labels = [];
        foreach ($chosen as $probe) {
            $labels[$probe['id']] = $probe['label'];
        }
        self::assertSame(
            [],
            array_map(static fn (array $row): string => $labels[$row['id']].' → '.$row['tbl'].' '.$row['id'], $landed),
            'B-3: a row was written at an id the client chose. Answers: '.implode(', ', $answers),
        );

        // ---- Controls.
        self::assertNotSame([], $minted, 'No POST created a row without a plant, so the lookup was never shown to see one.');
        $seen = array_values(array_unique(array_column($found, 'id')));
        sort($seen);
        $expected = array_values(array_unique(array_values($minted)));
        sort($expected);
        self::assertSame($expected, $seen, 'The lookup did not find a row a POST created, so it cannot be trusted to find a planted one.');
    }

    /**
     * Plants the probe's id into the body of a `POST`, and nothing else.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function send(string $method, string $path, ?array $body = null, array $headers = [], string $accept = 'application/json'): void
    {
        if (null !== $this->plant && 'POST' === $method) {
            if (self::TOP_LEVEL === $this->plant) {
                $id = Uuid::v4()->toRfc4122();
                $body = [...($body ?? []), 'id' => $id];
                $this->planted[] = ['label' => $this->plantingFor.' id', 'id' => $id];
            } else {
                foreach ($body ?? [] as $field => $value) {
                    if (!\is_array($value) || !array_is_list($value)) {
                        continue;
                    }
                    foreach ($value as $index => $element) {
                        if (!\is_array($element) || array_is_list($element)) {
                            continue;
                        }
                        $id = Uuid::v4()->toRfc4122();
                        \assert(\is_array($body));
                        $body[$field][$index] = [...$element, 'id' => $id];
                        $this->planted[] = ['label' => \sprintf('%s %s[%d].id', $this->plantingFor, $field, $index), 'id' => $id];
                    }
                }
            }
        }

        parent::send($method, $path, $body, $headers, $accept);
    }

    /**
     * The id of the row the last request created, when it answered 201.
     *
     * @return array<string, string>
     */
    private function mintedBy(string $name): array
    {
        $response = $this->client()->getResponse();
        if (201 !== $response->getStatusCode()) {
            return [];
        }

        $id = $this->json()['id'] ?? null;

        return \is_string($id) ? [$name.' '.$id => $id] : [];
    }

    /**
     * Every ordinary or partitioned table in `public` with a `uuid` column named
     * `id`, from the catalogue rather than from the manifest — an exhaustive
     * lookup derives its domain from the server.
     *
     * @return list<string>
     */
    private function tablesWithAUuidId(): array
    {
        return array_map(strval(...), $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'id' AND a.attnum > 0 AND NOT a.attisdropped
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
              AND a.atttypid = 'uuid'::regtype
            ORDER BY c.relname
            SQL));
    }

    /**
     * Rows at any of `$ids`, in any of `$tables`, under either seeded tenant.
     *
     * @param list<string> $tables
     * @param list<string> $ids
     *
     * @return list<array{tbl: string, id: string}>
     */
    private function rowsAt(array $tables, array $ids): array
    {
        if ([] === $tables || [] === $ids) {
            return [];
        }

        $union = implode(' UNION ALL ', array_map(
            static fn (string $table): string => \sprintf("SELECT '%1\$s' AS tbl, id::text AS id FROM \"%1\$s\" WHERE id = ANY (?::uuid[])", $table),
            $tables,
        ));
        $parameters = array_fill(0, \count($tables), '{'.implode(',', $ids).'}');

        $rows = [];
        foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
            foreach ($this->fetchAsOwnerForTenant($tenant, $union, $parameters) as $row) {
                $rows[$row['tbl'].' '.$row['id']] = ['tbl' => (string) $row['tbl'], 'id' => (string) $row['id']];
            }
        }
        ksort($rows);

        return array_values($rows);
    }
}
