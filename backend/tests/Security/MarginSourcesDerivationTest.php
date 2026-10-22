<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Modules\AuditLog\Application\ExportPermission;
use App\Modules\AuditLog\Application\ExportSources;
use App\Modules\UserManagement\Application\OfferedRoleEvaluator;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\GrantConflict;
use App\Modules\UserManagement\Domain\MarginExposure;
use App\Modules\UserManagement\Domain\MarginSources;
use App\Modules\UserManagement\Domain\RoleCompanions;
use App\Modules\UserManagement\Domain\RoleDefinitionRules;
use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirement;
use App\Platform\Security\PermissionRequirements;
use App\Tests\Api\TransactionBoundaryTest;
use App\Tests\Support\GeneratedSpec;
use App\Tests\Tenancy\TenancyTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * **PC-12 clause 6's check, built** — roles slice 4 C0 (design §4.6; `SECURITY_MODEL.md` PC-12; `RESIDUAL_GAPS.md` G-28
 * items 2 and 3). Everything is derived from the server and the generated contract; the only typed lists are
 * {@see MarginSources} (what the runtime reads) and {@see self::WIRE_FIELDS} (which column each money FIELD carries), and
 * both are held two-way against what is derived here.
 *
 *  1. **Clause 1, both directions:** every `numeric(19,4)` column the server holds (read from `pg_attribute`, partitions
 *     excluded) is classified in `MarginSources::columns()`, and nothing is classified that the server does not hold.
 *  2. **Clause 2 and PC-17, derived:** the money fields every operation serves in a 2xx body are found two ways and
 *     unioned — the generated spec's response schemas (`$ref`s resolved), and `tests/Api/wire-scale.json`'s scale-4 entries,
 *     which see inside the arrays the spec leaves opaque (credit-note `lines[]`). Each must be a {@see self::WIRE_FIELDS} key
 *     (an unclassified money field fails); each key must be seen (a stale key fails). An operation serving a cost field is a
 *     cost source whose code set is its `Perm` (the `PermissionGate` declaration); likewise sale. The derived sources must
 *     EQUAL `MarginSources`' — so a write that returns money (PC-17) cannot ship without the table moving.
 *  3. **Clause 3, the strict half:** no DEFAULT role is exposed; a planted default holding both order views fails; a planted
 *     COMPOSED role holding both is exposed and is refused by none of the definition rules (warned, never refused).
 *  4. **Clause 4's pair list, derived:** the default pairs whose union is exposed while neither member is, minus the pairs
 *     PC-13 refuses — today seven (the clause's eight, less `finance` + `warehouse_operator`, which SoD-5 made a PC-13
 *     refusal). The warning at assignment is still unbuilt (G-28 item 1); this derives
 *     the domain it must cover, it does not build it.
 *
 * **Disclosed limits:** a money field is recognised by NAME (the money columns' names plus {@see self::WIRE_FIELDS}' field
 * names) in the spec, and by SCALE in `wire-scale.json` — which sees only the bodies `OperationSuccessRecipes` produce (CD-3).
 * A money field under a brand-new name inside an opaque array, on an operation whose recipe never fills it, escapes both.
 * Ask sources (clause 2's third bullet) are not derived: no AI module exists, and this test fails when one appears.
 */
final class MarginSourcesDerivationTest extends TenancyTestCase
{
    /**
     * Which classified column(s) each served money field carries, keyed `Resource.field` (the resource is the schema
     * component holding the property, `Collection` stripped; for a field inside an opaque array, the operation's tag).
     *
     * @var array<string, list<string>>
     */
    private const WIRE_FIELDS = [
        'CreditNote.amount' => ['credit_note_line.amount'],
        'CreditNote.total_amount' => ['credit_note.total_amount'],
        'Invoice.amount_credited' => ['credit_note.total_amount'],
        'Invoice.amount_due' => ['invoice.total_amount', 'credit_note.total_amount'],
        'Invoice.total_amount' => ['invoice.total_amount'],
        'InvoiceLine.amount_billed' => ['sales_order_line.unit_price'],
        'InvoiceLine.amount_credited' => ['credit_note_line.amount'],
        'InvoiceLine.unit_price' => ['sales_order_line.unit_price'],
        'PurchaseOrder.total_amount' => ['purchase_order.total_amount'],
        'PurchaseOrderLine.unit_cost' => ['purchase_order_line.unit_cost'],
        'SalesOrder.total_amount' => ['sales_order.total_amount'],
        'SalesOrderLine.unit_price' => ['sales_order_line.unit_price'],
        // Suppliers slice 1 C3 (2026-10-12): the catalogue's cost, served by `ListSupplierProducts` (`supplier_product.view`)
        // and by `CreateSupplierProduct` / `UpdateSupplierProduct`'s 200/201 (`supplier_product.edit`, PC-17).
        'SupplierProduct.unit_cost' => ['supplier_product.unit_cost'],
        // G3-C1 (2026-10-22): the preferred supplier's catalogue cost, copied into each reorder suggestion and served by
        // `ListReorderSuggestions` under its six codes (a source reduced away by {@see self::smallest()}). Keyed by the
        // operation's tag (`ReorderSuggestionCollection` → `ReorderSuggestion`): the item schema is inline.
        'ReorderSuggestion.unit_cost' => ['supplier_product.unit_cost'],
    ];

    /**
     * The default pairs PC-12 clause 4's warning must cover (sorted by name inside each pair) — re-derived below, never
     * trusted. **SEVEN, not the clause's eight:** `finance` + `warehouse_operator` is refused outright by PC-13 since SoD-5
     * (2026-10-01: `sales_return.create` with `credit_note.issue`), so no warning is ever shown for it. Found by running
     * this derivation at C0; the clause's text predates SoD-5.
     */
    private const CLAUSE_4_PAIRS = [
        'buyer+finance',
        'buyer+sales_rep',
        'buyer+viewer',
        'finance+purchasing_manager',
        'purchasing_manager+sales_rep',
        'purchasing_manager+viewer',
        'viewer+warehouse_operator',
    ];

    #[Test]
    public function everyMoneyColumnOnTheServerIsClassified(): void
    {
        $server = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname || '.' || a.attname
              FROM pg_attribute a
              JOIN pg_class c ON c.oid = a.attrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
               AND a.attnum > 0 AND NOT a.attisdropped
               AND format_type(a.atttypid, a.atttypmod) = 'numeric(19,4)'
             ORDER BY 1
            SQL);
        self::assertNotSame([], $server, 'The server holds no numeric(19,4) column, so the classification has no subject.');

        $classified = array_keys(MarginSources::columns());
        sort($classified);
        self::assertSame($server, $classified, 'PC-12 clause 1: every money column is classified cost, sale or neither, and only those.');
        foreach (MarginSources::columns() as $column => $class) {
            self::assertContains($class, [MarginSources::COST, MarginSources::SALE, MarginSources::NEITHER], $column);
        }
        foreach (self::WIRE_FIELDS as $field => $columns) {
            foreach ($columns as $column) {
                self::assertArrayHasKey($column, MarginSources::columns(), $field.' names a column that is not classified.');
            }
        }
    }

    #[Test]
    public function theCommittedSourcesAreWhatTheContractDerives(): void
    {
        self::assertDirectoryDoesNotExist(\dirname(__DIR__, 2).'/src/Modules/Ai', 'An AI module exists. PC-12 clause 2: Ask sources are the codes '
            .'PC-8 requires for each table a whitelisted tool reads (AI-SQL-1). Derive them here before it ships.');

        $derived = $this->derivedSources();
        self::assertNotSame([], $derived[MarginSources::COST], 'No cost source was derived, so the walk is blind.');
        self::assertNotSame([], $derived[MarginSources::SALE], 'No sale source was derived, so the walk is blind.');

        self::assertSame(self::sortedSets(MarginSources::costSources()), $derived[MarginSources::COST], 'PC-12 clause 2: the committed COST sources are not what the contract derives. Move MarginSources with the operation.');
        self::assertSame(self::sortedSets(MarginSources::saleSources()), $derived[MarginSources::SALE], 'PC-12 clause 2: the committed SALE sources are not what the contract derives. Move MarginSources with the operation.');
    }

    #[Test]
    public function noDefaultRoleIsExposedAndAComposedOneIsWarnedNeverRefused(): void
    {
        $holdsCost = $holdsSale = false;
        foreach (DefaultRoles::all() as $codes) {
            $holdsCost = $holdsCost || self::holdsAny(MarginSources::costSources(), $codes);
            $holdsSale = $holdsSale || self::holdsAny(MarginSources::saleSources(), $codes);
        }
        self::assertTrue($holdsCost && $holdsSale, 'No default role holds a cost or a sale source, so the strict half has no subject.');

        self::assertSame([], self::exposedDefaults(DefaultRoles::all()), 'PC-12 clause 3: a DEFAULT role holds a cost and a sale source without every .view.');
        self::assertFalse(MarginExposure::of(DefaultRoles::codesFor(DefaultRoles::ADMIN)), 'admin holds every .view and is exempt.');
        self::assertFalse(MarginExposure::of(DefaultRoles::codesFor(DefaultRoles::OWNER)), 'owner holds every .view and is exempt.');

        $bothOrderViews = ['purchase_order.view', 'sales_order.view'];
        self::assertSame(['planted'], self::exposedDefaults(['planted' => $bothOrderViews]), 'A planted default holding both order views did not fail.');

        self::assertTrue(MarginExposure::of($bothOrderViews), 'A composed role holding both order views raised no warning.');
        self::assertSame([], RoleDefinitionRules::separationOfDutyRowsSpanned($bothOrderViews), 'A margin pair was refused as a split.');
        self::assertSame([], RoleCompanions::missingFrom($bothOrderViews), 'A margin pair was refused as a missing companion.');
    }

    #[Test]
    public function theDefaultPairsAWarningMustCoverAreDerived(): void
    {
        $roles = DefaultRoles::all();
        $names = array_keys($roles);
        sort($names);
        $pairs = [];
        foreach ($names as $i => $a) {
            foreach (\array_slice($names, $i + 1) as $b) {
                if (MarginExposure::of($roles[$a]) || MarginExposure::of($roles[$b])) {
                    continue;
                }
                if (!MarginExposure::of(array_values(array_unique([...$roles[$a], ...$roles[$b]])))) {
                    continue;
                }
                if ([] !== GrantConflict::newRows($roles[$a], $roles[$b])) {
                    continue; // PC-13 refuses the pair outright, so no warning is ever shown for it.
                }
                $pairs[] = $a.'+'.$b;
            }
        }

        self::assertSame(self::CLAUSE_4_PAIRS, $pairs, 'PC-12 clause 4: the derived default pairs moved. Re-derive the clause and the warning\'s domain (G-28 item 1).');
    }

    /**
     * **The picker's pair rule IS this derivation** (users slice 1 C3, design §2.2; `SEC` §2.2 co-sign): over the default
     * roles, `ListOfferedRoles`' `margin_exposure_with` — computed by `OfferedRoleEvaluator`, the shipped function — names
     * exactly {@see self::CLAUSE_4_PAIRS}. Dropping its `newRows = ∅` exclusion adds `finance` + `warehouse_operator` and
     * `sales_rep` + `warehouse_operator` (mutation 4).
     */
    #[Test]
    public function thePickersMarginPartnersOverTheDefaultsAreTheClause4Pairs(): void
    {
        $live = [];
        foreach (DefaultRoles::all() as $name => $codes) {
            $live[] = ['id' => $name, 'name' => $name, 'codes' => $codes];
        }
        $items = array_map(static fn (array $r): array => $r + ['is_system' => true, 'live' => true], $live);
        $views = OfferedRoleEvaluator::evaluate($items, $live, ['deleted' => false, 'is_active' => true],
            '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002', DefaultRoles::codesFor(DefaultRoles::OWNER), [], []);

        $pairs = [];
        foreach ($views as $view) {
            foreach ($view->marginExposureWith as $partner) {
                $pair = [$view->id, $partner['id']];
                sort($pair);
                $pairs[] = implode('+', $pair);
            }
        }
        $pairs = array_values(array_unique($pairs));
        sort($pairs);

        self::assertSame(self::CLAUSE_4_PAIRS, $pairs, 'ListOfferedRoles\' margin_exposure_with over the defaults is not PC-12 clause 4\'s derived pairs.');
    }

    /**
     * **`SEC` P-4 — every margin source is ONE code** (users slice 1 C3; design §5.2). While that holds, any exposed union
     * contains an exposing pair or a role exposed alone, so the pair warning plus `User.margin_exposure` is complete.
     */
    #[Test]
    public function everyMarginSourceIsOneCode(): void
    {
        $sources = [...MarginSources::costSources(), ...MarginSources::saleSources()];
        self::assertNotSame([], $sources, 'No margin source, so the pin has no subject.');
        foreach ($sources as $source) {
            self::assertCount(1, $source, \sprintf(
                'A margin source now needs more than one code (`%s`). PC-12 clause 4\'s pair warning (`ListOfferedRoles.margin_exposure_with[]`) is no longer complete: a union of three or more roles can expose with no exposing pair, and only `User.margin_exposure` catches it, after the grant. Re-open G-28 item 1 with `SEC` before moving this pin.',
                implode(' + ', $source)));
        }
    }

    /** @return array{cost: list<list<string>>, sale: list<list<string>>} */
    private function derivedSources(): array
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $schemas = $spec['components']['schemas'] ?? [];
        $names = $this->moneyNames();

        $fieldsByOperation = [];
        foreach (GeneratedSpec::operations($spec) as $operation) {
            $id = (string) ($operation['operation']['operationId'] ?? '');
            $tag = self::resourceOf((string) ($operation['operation']['tags'][0] ?? ''));
            $fieldsByOperation[$id] ??= [];
            foreach ($operation['operation']['responses'] ?? [] as $status => $response) {
                if (!str_starts_with((string) $status, '2')) {
                    continue;
                }
                foreach ($response['content'] ?? [] as $type => $content) {
                    if (str_contains((string) $type, 'json') && !str_contains((string) $type, 'problem')) {
                        self::walk($content['schema'] ?? [], $tag, '$', $schemas, [], $fieldsByOperation[$id]);
                    }
                }
            }
        }
        self::assertArrayHasKey('GetInvoice', $fieldsByOperation, 'The spec does not carry GetInvoice, so the walk is not over the built surface.');

        $money = [];
        foreach ($fieldsByOperation as $id => $fields) {
            foreach ($fields as $path => $resource) {
                $leaf = (string) preg_replace('/^.*\./', '', $path);
                if (\in_array($leaf, $names, true)) {
                    $money[$id][$resource.'.'.$leaf] = true;
                }
            }
        }

        $wire = json_decode((string) file_get_contents(\dirname(__DIR__).'/Api/wire-scale.json'), true);
        self::assertIsArray($wire, 'wire-scale.json is not readable.');
        $tags = [];
        foreach (GeneratedSpec::operations($spec) as $operation) {
            $tags[(string) $operation['operation']['operationId']] = self::resourceOf((string) ($operation['operation']['tags'][0] ?? ''));
        }
        foreach ($wire as $key => $scales) {
            if (!\in_array(4, $scales, true)) {
                continue;
            }
            [$id, $path] = explode(' ', (string) $key, 2);
            $resource = $fieldsByOperation[$id][$path] ?? ($tags[$id] ?? null);
            self::assertNotNull($resource, $key.' is money on the wire for an operation the spec does not carry.');
            $leaf = (string) preg_replace('/^.*\./', '', $path);
            $money[$id][$resource.'.'.$leaf] = true;
        }

        $permissions = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $permissions[$name] = PermissionRequirement::of($operation);
        }

        $seen = [];
        $sources = [MarginSources::COST => [], MarginSources::SALE => []];
        foreach ($money as $id => $fields) {
            foreach (array_keys($fields) as $field) {
                self::assertArrayHasKey($field, self::WIRE_FIELDS, $id.' serves the money field '.$field.', which carries no classified column. Classify it (PC-12 clause 1).');
                $seen[$field] = true;
                foreach (self::WIRE_FIELDS[$field] as $column) {
                    $class = MarginSources::columns()[$column];
                    if (MarginSources::NEITHER === $class) {
                        continue;
                    }
                    // P-0 (F-H1 point 4): by kind, and NEVER the union of a derived requirement's possible codes -- for
                    // DERIVED that set is a disjunction, and as a source it would read as all-of.
                    $requirement = $permissions[$id] ?? null;
                    $set = match ($requirement?->kind()) {
                        PermissionRequirement::ALL_OF => $requirement->codes(),
                        PermissionRequirement::DERIVED => self::fail($id.' serves '.$field.' under a derived permission: wire per-resource codes (PC-12 cl. 2 bullet 2) before a derived operation serves money.'),
                        default => self::fail($id.' serves '.$field.' with no Perm code, so it cannot be a source.'),
                    };
                    $sources[$class][implode(',', $set)] = $set;
                }
            }
        }

        foreach ($this->derivedArm($permissions, $money) as $class => $sets) {
            foreach ($sets as $set) {
                $sources[$class][implode(',', $set)] = $set;
            }
        }

        $unseen = array_values(array_diff(array_keys(self::WIRE_FIELDS), array_keys($seen)));
        self::assertSame([], $unseen, 'These classified fields are served by no operation any more; remove them.');

        return [
            MarginSources::COST => self::sortedSets(self::smallest(array_values($sources[MarginSources::COST]))),
            MarginSources::SALE => self::sortedSets(self::smallest(array_values($sources[MarginSources::SALE]))),
        ];
    }

    /**
     * **PC-12 clause 2's "smallest set"** (G3-C1, 2026-10-22; `docs/session13/g3-g7b-red.md` H-1, adopted in the design's
     * Response to RED and Revision 1 FINAL; final RED F-L2): a derived set that is a STRICT superset of another derived set of
     * the same class is not a source — every role holding it already holds the smaller one, and `holdsAny` is monotone, so
     * dropping it changes no exposure. `ListReorderSuggestions`' six codes reduce away because `['purchase_order.view']` and
     * `['supplier_product.view']` are each a single-code cost source already; if NEITHER is derived, the six-code set comes
     * back on its own (and then trips {@see self::everyMarginSourceIsOneCode()}, which re-opens G-28 item 1 — correctly).
     *
     * @param list<list<string>> $sets
     *
     * @return list<list<string>>
     */
    private static function smallest(array $sets): array
    {
        return array_values(array_filter($sets, static function (array $set) use ($sets): bool {
            foreach ($sets as $other) {
                if (\count($other) < \count($set) && [] === array_diff($other, $set)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * The reviewed `resource → List operationId` pairing of every export family (G6-C0: Inventory; G6-C1: the rest). Written by
     * hand on purpose — the derived arm's independent anchor (`g6-c0-red.md` M-5); see {@see self::derivedArm()}.
     */
    private const EXPORTED_THROUGH = [
        'app_user' => 'ListUsers',
        'credit_note' => 'ListCreditNotes',
        'customer' => 'ListCustomers',
        'fulfilment' => 'ListFulfilments',
        'goods_receipt' => 'ListGoodsReceipts',
        'inventory_item' => 'ListItems',
        'invoice' => 'ListInvoices',
        'permission' => 'ListPermissions',
        'purchase_order' => 'ListPurchaseOrders',
        'role' => 'ListRoles',
        'sales_order' => 'ListSalesOrders',
        'sales_return' => 'ListSalesReturns',
        'stock_level' => 'ListStockLevels',
        'stock_movement' => 'ListStockMovements',
        'supplier' => 'ListSuppliers',
        'warehouse' => 'ListWarehouses',
    ];

    /**
     * **The DERIVED arm (G6-C0; `g5-g6-final-red.md` M-4; PC-12 cl. 2 bullet 2, "the code it resolves", per resource).**
     *
     * A derived operation's money cannot be seen on its own wire: `CreateExport` answers `text/csv`, which has no schema.
     * So it is classified PER RESOURCE, through the List it renders: for each `(resource, code)` of the export resolver's
     * map, the List operation that resource's `ExportSource` names must be gated on exactly that ONE code (a single-code
     * source — never the union of the map, which is a disjunction), and the export's money classes for that code are that
     * List's. They are added as sources of the same class, so the committed `MarginSources` stays what the List side
     * already derives. Any OTHER derived operation (the bulk ones) must serve no money on its own wire: asserted, never
     * skipped. *Mutation:* the resolver returns another code for a resource → it is not the code its List is gated on → red.
     * **Independent anchor (`g6-c0-red.md` M-5):** `ExportPermission` reads its codes off the very List each source names,
     * so the code comparison alone cannot see a source pointed at a DIFFERENT List. {@see self::EXPORTED_THROUGH} is the
     * reviewed `resource → List` pairing, written here and never read from a source: the List a source names must be the
     * one this map pairs with its resource. *Mutation:* point `ItemExportSource` at another List → red here (as well as in
     * `ExportPermissionTest::PINNED_MAP`, `ExportContractTest::theExportRowSetEqualsTheListRowSet` and
     * `ExportCsvTest::theHeaderEqualsTheListItemSchemasLeaves`). A new export family adds its pairs here deliberately.
     *
     * @param array<string, PermissionRequirement>      $permissions operation name → requirement
     * @param array<string, array<string, true>>        $money       operationId → the money fields it serves
     *
     * @return array{cost: list<list<string>>, sale: list<list<string>>}
     */
    private function derivedArm(array $permissions, array $money): array
    {
        $requirements = self::getContainer()->get(PermissionRequirements::class);
        $sources = self::getContainer()->get(ExportSources::class);
        \assert($requirements instanceof PermissionRequirements && $sources instanceof ExportSources);

        $classes = [MarginSources::COST => [], MarginSources::SALE => []];
        $derivedOperations = 0;
        $visited = 0;
        $paired = [];
        foreach ($permissions as $name => $requirement) {
            if (PermissionRequirement::DERIVED !== $requirement->kind()) {
                continue;
            }
            ++$derivedOperations;

            if (ExportPermission::class !== $requirement->resolver()) {
                self::assertSame([], $money[$name] ?? [], $name.' is derived and serves money on its own wire; wire its per-resource classification here before it ships.');
                continue;
            }

            foreach ($requirements->codesByResource($requirement) as $resource => $code) {
                ++$visited;
                $paired[] = $resource;
                $list = $sources->get($resource)->listOperationName();
                self::assertSame(self::EXPORTED_THROUGH[$resource] ?? null, $list, \sprintf(
                    '%s exports %s through %s; the reviewed pairing (EXPORTED_THROUGH) says %s. Pair a new family here deliberately.',
                    $name, $resource, $list, self::EXPORTED_THROUGH[$resource] ?? '(none)'));
                $listRequirement = $permissions[$list] ?? null;
                self::assertNotNull($listRequirement, $name.' exports '.$resource.' through '.$list.', which the registry does not hold.');
                self::assertSame([PermissionRequirement::ALL_OF, [$code]], [$listRequirement->kind(), $listRequirement->codes()],
                    \sprintf('%s resolves %s to %s, but %s is gated on %s: the export is not classified as its List is (PC-12 cl. 2 bullet 2).',
                        $name, $resource, $code, $list, $listRequirement->describe()));

                foreach (array_keys($money[$list] ?? []) as $field) {
                    foreach (self::WIRE_FIELDS[$field] ?? [] as $column) {
                        $class = MarginSources::columns()[$column];
                        if (MarginSources::NEITHER !== $class) {
                            $classes[$class][$code] = [$code];
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(0, $derivedOperations, 'No derived operation is in the registry, so the DERIVED arm holds over nothing (`'.PermissionGate::DERIVED.'`).');
        self::assertGreaterThan(0, $visited, 'The export arm compared no (resource, code) pair, so it holds over nothing.');
        sort($paired, \SORT_STRING);
        $pinned = array_keys(self::EXPORTED_THROUGH);
        sort($pinned, \SORT_STRING);
        self::assertSame($pinned, $paired, 'EXPORTED_THROUGH pairs a resource the export no longer serves, or misses one it does.');

        return [MarginSources::COST => array_values($classes[MarginSources::COST]), MarginSources::SALE => array_values($classes[MarginSources::SALE])];
    }

    /** @return list<string> the leaf names a money field is recognised by */
    private function moneyNames(): array
    {
        $names = [];
        foreach (array_keys(MarginSources::columns()) as $column) {
            $names[] = (string) preg_replace('/^.*\./', '', $column);
        }
        foreach (array_keys(self::WIRE_FIELDS) as $field) {
            $names[] = (string) preg_replace('/^.*\./', '', $field);
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, mixed>                $node
     * @param array<string, array<string, mixed>> $schemas
     * @param list<string>                        $seen
     * @param array<string, string>               $fields  path → the resource holding the leaf
     */
    private static function walk(array $node, string $resource, string $path, array $schemas, array $seen, array &$fields): void
    {
        if (isset($node['$ref'])) {
            $name = (string) preg_replace('#^.*/#', '', (string) $node['$ref']);
            if (\in_array($name, $seen, true)) {
                return;
            }
            self::walk($schemas[$name] ?? [], self::resourceOf($name), $path, $schemas, [...$seen, $name], $fields);

            return;
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $combinator) {
            foreach ($node[$combinator] ?? [] as $branch) {
                self::walk((array) $branch, $resource, $path, $schemas, $seen, $fields);
            }
        }
        if (isset($node['items']) && \is_array($node['items'])) {
            self::walk($node['items'], $resource, $path.'[]', $schemas, $seen, $fields);
        }
        foreach ($node['properties'] ?? [] as $property => $schema) {
            $fields[$path.'.'.$property] = $resource;
            self::walk((array) $schema, $resource, $path.'.'.$property, $schemas, $seen, $fields);
        }
    }

    /** `InvoiceLineCollection` → `InvoiceLine`; `PurchaseOrderLine.jsonld` → `PurchaseOrderLine`. */
    private static function resourceOf(string $component): string
    {
        $base = (string) preg_replace('/\..*$/', '', $component);

        return str_ends_with($base, 'Collection') ? substr($base, 0, -\strlen('Collection')) : $base;
    }

    /**
     * @param array<string, list<string>> $roles
     *
     * @return list<string> the roles PC-12 clause 3 fails
     */
    private static function exposedDefaults(array $roles): array
    {
        return array_keys(array_filter($roles, static fn (array $codes): bool => MarginExposure::of($codes)));
    }

    /**
     * @param list<list<string>> $sources
     * @param list<string>       $codes
     */
    private static function holdsAny(array $sources, array $codes): bool
    {
        foreach ($sources as $source) {
            if ([] === array_diff($source, $codes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<list<string>> $sets
     *
     * @return list<list<string>>
     */
    private static function sortedSets(array $sets): array
    {
        $sets = array_map(static function (array $set): array {
            sort($set);

            return $set;
        }, $sets);
        usort($sets, static fn (array $a, array $b): int => strcmp(implode(',', $a), implode(',', $b)));

        return $sets;
    }
}
