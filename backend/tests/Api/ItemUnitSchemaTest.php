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
 *  - on `Item`, `Item.CreateItemInput` and the update patch, the served `enum` is exactly {@see UnitsOfMeasure::CODES},
 *    in order, and neither the type nor the enum admits `null` (create refuses a `null` unit `required`, update refuses it
 *    `not_nullable` — the writer accepts `null` for neither, so neither is served nullable);
 *  - no schema serves a `default` (a required picker starts unselected; an absent unit on a patch changes nothing);
 *  - the list is the set of codes in `unit_of_measure`, READ FROM THE SERVER as the application principal: a unit added to
 *    the table without the list, or to the list without the table, is red here in the same run;
 *  - `OpenApiContractTest::REQUEST_ENUMS` names both request enums, so the two-way request-enum check covers them.
 */
final class ItemUnitSchemaTest extends ApiTestCase
{
    private const SCHEMAS = [
        'Item',
        'Item.CreateItemInput',
        'Item.UpdateItemInput.jsonMergePatch',
    ];

    #[Test]
    public function theServedUnitIsTheBackendsListOnAllThreeSchemasAndNeverNull(): void
    {
        $schemas = $this->spec()['components']['schemas'];

        foreach (self::SCHEMAS as $name) {
            self::assertArrayHasKey($name, $schemas, $name.' is not served.');
            $unit = $schemas[$name]['properties']['unit_of_measure_code'] ?? null;
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
