<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\SharedKernel\Reference\UnitsOfMeasure;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;

/**
 * **BK-22 — an item's `unit_of_measure_code` is served as a fixed list** (`docs/design/owed-to-backend.md` BK-22; items
 * batch C2, `docs/session13/items-batch-design.md` "Revision 1 — FINAL"). The web app's unit picker reads the five units
 * from the generated surface and never holds a copy, so the served schema must be the backend's one list, and that list
 * must be the server's `unit_of_measure` table. `StorageLocationKindSchemaTest` is the shape this follows (BK-19).
 *
 * Held here:
 *  - on the six sites in {@see self::SITES} — `Item`, `Item.CreateItemInput`, the update patch, the supplier catalogue
 *    entry `SupplierProduct` (BK-31), the purchase-order line `PurchaseOrderLine` and the goods-receipt line
 *    `GoodsReceipt.lines[]` (BK-33; response schemas, so `REQUEST_ENUMS` is not involved) — the served `enum` is exactly
 *    {@see UnitsOfMeasure::CODES}, in order, and neither the type nor the enum admits `null` (create refuses a `null` unit
 *    `required`, update refuses it `not_nullable` — the writer accepts `null` for neither, so neither is served nullable;
 *    the catalogue entry and both purchasing lines read the item's `NOT NULL` column through an inner join);
 *  - those six are EVERY site: {@see self::everyServedUnitIsAPinnedSite()} walks the whole regenerated spec
 *    (`components.schemas` and `paths`) generically and pins the set of nodes serving `unit_of_measure_code` two-way, so
 *    a seventh site added without a pin is red, and so is a pinned site the spec stops serving (`SECURITY_MODEL.md` PC-9's
 *    eighth exception: the unit on any other resource needs its own exception there). `GoodsReceipt.lines[]` is a
 *    hand-written inline schema (`GoodsReceiptResource::LINE_SCHEMA`), so `OpenApiContractTest`'s walk holds its
 *    `required` and nullability, and this class holds its enum;
 *  - no schema serves a `default` (a required picker starts unselected; an absent unit on a patch changes nothing);
 *  - the list is the set of codes in `unit_of_measure`, READ FROM THE SERVER as the application principal: a unit added to
 *    the table without the list, or to the list without the table, is red here in the same run;
 *  - `OpenApiContractTest::REQUEST_ENUMS` names both request enums, so the two-way request-enum check covers them.
 */
final class ItemUnitSchemaTest extends ApiTestCase
{
    /** label => path under `components.schemas` (the frontend's `MIRRORED` path convention). */
    private const SITES = [
        'Item' => ['Item'],
        'Item.CreateItemInput' => ['Item.CreateItemInput'],
        'Item.UpdateItemInput.jsonMergePatch' => ['Item.UpdateItemInput.jsonMergePatch'],
        'SupplierProduct' => ['SupplierProduct'],
        'PurchaseOrderLine' => ['PurchaseOrderLine'],
        'GoodsReceipt.lines[]' => ['GoodsReceipt', 'properties', 'lines', 'items'],
    ];

    #[Test]
    public function theServedUnitIsTheBackendsListOnEverySchemaAndNeverNull(): void
    {
        $schemas = $this->spec()['components']['schemas'];

        foreach (self::SITES as $name => $path) {
            $node = $schemas;
            foreach ($path as $key) {
                self::assertIsArray($node, $name.' is not served.');
                self::assertArrayHasKey($key, $node, $name.' is not served.');
                $node = $node[$key];
            }
            self::assertIsArray($node, $name.' is not served.');
            $unit = $node['properties']['unit_of_measure_code'] ?? null;
            self::assertIsArray($unit, $name.'.unit_of_measure_code is not served.');
            self::assertSame('string', $unit['type'] ?? null, $name.'.unit_of_measure_code is not served as a plain (non-null) string.');
            self::assertSame(UnitsOfMeasure::CODES, $unit['enum'] ?? null,
                $name.'.unit_of_measure_code does not serve UnitsOfMeasure::CODES as its enum, in order.');
            self::assertArrayNotHasKey('anyOf', $unit, $name.'.unit_of_measure_code admits a second branch (null?).');
            self::assertArrayNotHasKey('oneOf', $unit, $name.'.unit_of_measure_code admits a second branch (null?).');
            self::assertFalse($unit['nullable'] ?? false, $name.'.unit_of_measure_code is marked nullable.');
            self::assertArrayNotHasKey('default', $unit,
                $name.'.unit_of_measure_code serves a default: a required picker starts unselected, and an absent unit on a patch changes nothing.');
        }
    }

    /**
     * Every node of the regenerated spec whose `properties` carry `unit_of_measure_code` is a pinned site, and every pinned
     * site is served (BK-33, final RED L-2 and RED L-4). The walk is generic — it recurses into every array value and never
     * follows a `$ref` (a string), so a collection does not count its element twice — over `components.schemas` AND over
     * `paths`, where no inline schema may carry the field.
     */
    #[Test]
    public function everyServedUnitIsAPinnedSite(): void
    {
        $spec = $this->spec();
        $found = self::sites($spec);

        $pinned = array_values(array_map(static fn (array $path): string => json_encode($path, \JSON_THROW_ON_ERROR), self::SITES));
        $served = array_map(static fn (array $path): string => json_encode($path, \JSON_THROW_ON_ERROR), $found['components']);
        sort($pinned);
        sort($served);
        self::assertSame($pinned, $served,
            'The set of components.schemas nodes serving unit_of_measure_code is not ItemUnitSchemaTest::SITES. A new site needs '
            .'its own SECURITY_MODEL.md PC-9 exception and a SITES entry in the same commit; a pinned site the spec stopped '
            .'serving must be removed from both.');
        self::assertSame([], $found['paths'], 'An inline schema under paths serves unit_of_measure_code; it has no PC-9 exception and no SITES entry.');
        self::assertSame(\count(self::SITES), \count($found['components']) + \count($found['paths']),
            'Anti-vacuity: the walk did not record exactly the pinned number of sites.');

        // Positive control (components): a planted site beyond the pins is reported.
        $planted = $spec;
        self::assertArrayHasKey('Warehouse', $planted['components']['schemas'], 'Control: Warehouse is not served, so the plant below would prove nothing.');
        $planted['components']['schemas']['Warehouse']['properties']['unit_of_measure_code'] = ['type' => 'string'];
        self::assertContains(['Warehouse'], self::sites($planted)['components'],
            'Control: a unit_of_measure_code planted on Warehouse was not reported; the walk is blind to components.schemas.');

        // Positive control (paths): a planted inline response schema is reported, so the empty `paths` set above can fail.
        $planted = $spec;
        self::assertArrayHasKey('200', $planted['paths']['/api/v1/warehouses']['get']['responses'] ?? [],
            'Control: GET /api/v1/warehouses has no 200, so the plant below would prove nothing.');
        $planted['paths']['/api/v1/warehouses']['get']['responses']['200']['content']['application/json']['schema'] =
            ['type' => 'object', 'properties' => ['unit_of_measure_code' => ['type' => 'string']]];
        // PHP holds the response code key `200` as an int, so the recorded path does too.
        self::assertContains(['/api/v1/warehouses', 'get', 'responses', 200, 'content', 'application/json', 'schema'],
            self::sites($planted)['paths'],
            'Control: a unit_of_measure_code planted on an inline response schema under paths was not reported; the walk is blind to paths.');
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array{components: list<list<int|string>>, paths: list<list<int|string>>}
     */
    private static function sites(array $spec): array
    {
        $components = [];
        self::walk($spec['components']['schemas'] ?? [], [], $components);
        $paths = [];
        self::walk($spec['paths'] ?? [], [], $paths);

        return ['components' => $components, 'paths' => $paths];
    }

    /**
     * @param list<int|string>       $path
     * @param list<list<int|string>> $found
     */
    private static function walk(mixed $node, array $path, array &$found): void
    {
        if (!\is_array($node)) {
            return;
        }
        if (\is_array($node['properties'] ?? null) && \array_key_exists('unit_of_measure_code', $node['properties'])) {
            $found[] = $path;
        }
        foreach ($node as $key => $child) {
            if (\is_array($child)) {
                self::walk($child, [...$path, $key], $found);
            }
        }
    }

    #[Test]
    public function theListIsTheServersUnitTable(): void
    {
        $served = array_map('strval', $this->app()->fetchFirstColumn('SELECT code FROM unit_of_measure'));
        self::assertNotSame([], $served, 'Control: unit_of_measure read back no rows, so the comparison below would be vacuous.');
        self::assertContains('EA', $served, 'Control: the seeded unit EA was not read back; the read is not the seeded table.');

        $list = UnitsOfMeasure::CODES;
        sort($list);
        sort($served);
        self::assertSame($served, $list,
            'UnitsOfMeasure::CODES is not the set of codes in unit_of_measure. Add or remove the unit in both, in one commit.');
        self::assertSame(\count(UnitsOfMeasure::CODES), \count(array_unique(UnitsOfMeasure::CODES)), 'UnitsOfMeasure::CODES repeats a code.');
    }

    #[Test]
    public function bothRequestEnumsArePinnedForTheTwoWayCheck(): void
    {
        $pinned = new \ReflectionClassConstant(OpenApiContractTest::class, 'REQUEST_ENUMS')->getValue();
        self::assertIsArray($pinned);
        foreach (['CreateItem.unit_of_measure_code', 'UpdateItem.unit_of_measure_code'] as $key) {
            self::assertSame(UnitsOfMeasure::CODES, $pinned[$key] ?? null,
                'OpenApiContractTest::REQUEST_ENUMS does not pin '.$key.' to UnitsOfMeasure::CODES.');
        }
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        static $memo = null;

        return $memo ??= GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
    }
}
