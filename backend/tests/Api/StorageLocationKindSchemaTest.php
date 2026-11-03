<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Warehouses\Application\Contract\LocationKinds;
use App\Modules\Warehouses\Application\StorageLocationWriter;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;

/**
 * **BK-19 — a storage location's `kind` is served as a fixed list** (`docs/design/owed-to-backend.md` BK-19 and its
 * 2026-11-03 ruling amendment, D-3). The web app reads the five kinds, and the create form's starting value, from the
 * generated surface and never holds a copy, so the served schema must be the backend's own list and the server's own
 * column default.
 *
 * Held here:
 *  - on `StorageLocation.kind`, `CreateStorageLocationInput.kind` and the update patch's `kind`, the served `enum` is
 *    exactly {@see LocationKinds::ALL} (in order) and neither the type nor the enum admits `null` (the writer refuses an
 *    explicit `null` on both writes — `StorageLocationWriteTest`);
 *  - the create input's served `default` is the `storage_location.kind` column default READ FROM THE SERVER, never a PHP
 *    literal: an omitted `kind` falls to that column default (the writer leaves it out of the INSERT), so the two are the
 *    same fact;
 *  - the update patch serves NO `default`: an omitted `kind` on a patch leaves the kind as it is;
 *  - the writer's refusal sentence {@see StorageLocationWriter::KIND_SHAPE} lists exactly {@see LocationKinds::ALL}.
 *
 * `LocationKinds::ALL` is held equal to the live `storage_location_kind_values` CHECK by
 * `StorageLocationWriteTest::theKindsAreTheLiveCheckAndTheNeverPickableKindIsInventorysOwn`; the request enums are also
 * held to their constants by `OpenApiContractTest::everyRequestEnumIsItsWritersConstant`.
 */
final class StorageLocationKindSchemaTest extends ApiTestCase
{
    private const SCHEMAS = [
        'StorageLocation',
        'StorageLocation.CreateStorageLocationInput',
        'StorageLocation.UpdateStorageLocationInput.jsonMergePatch',
    ];

    private const CREATE_INPUT = 'StorageLocation.CreateStorageLocationInput';

    private const UPDATE_PATCH = 'StorageLocation.UpdateStorageLocationInput.jsonMergePatch';

    #[Test]
    public function theServedKindIsTheBackendsListOnAllThreeSchemasAndNeverNull(): void
    {
        $schemas = $this->spec()['components']['schemas'];

        foreach (self::SCHEMAS as $name) {
            self::assertArrayHasKey($name, $schemas, $name.' is not served.');
            $kind = $schemas[$name]['properties']['kind'] ?? null;
            self::assertIsArray($kind, $name.'.kind is not served.');
            self::assertSame('string', $kind['type'] ?? null, $name.'.kind is not served as a plain (non-null) string.');
            self::assertSame(LocationKinds::ALL, $kind['enum'] ?? null, $name.'.kind does not serve LocationKinds::ALL as its enum.');
            self::assertArrayNotHasKey('anyOf', $kind, $name.'.kind admits a second branch (null?).');
            self::assertArrayNotHasKey('oneOf', $kind, $name.'.kind admits a second branch (null?).');
            self::assertFalse($kind['nullable'] ?? false, $name.'.kind is marked nullable.');
        }
    }

    #[Test]
    public function theCreateDefaultIsTheServersColumnDefault(): void
    {
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT pg_get_expr(d.adbin, d.adrelid) AS expr
               FROM pg_attrdef d
               JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
              WHERE d.adrelid = 'storage_location'::regclass AND a.attname = 'kind'");
        self::assertCount(1, $rows, 'Control: storage_location.kind has no column default on the server.');
        $expression = (string) $rows[0]['expr'];
        self::assertSame(1, preg_match("/^'([a-z_]+)'(?:::[a-z ]+)?$/", $expression, $m),
            'storage_location.kind\'s column default is not a plain literal: '.$expression);
        $columnDefault = $m[1];
        self::assertContains($columnDefault, LocationKinds::ALL, 'Control: the column default is not one of the kinds.');

        $served = $this->spec()['components']['schemas'][self::CREATE_INPUT]['properties']['kind'] ?? [];
        self::assertArrayHasKey('default', $served, 'CreateStorageLocationInput.kind serves no default.');
        self::assertSame($columnDefault, $served['default'],
            'CreateStorageLocationInput.kind serves a default that is not the server\'s storage_location.kind column default.');
    }

    #[Test]
    public function theUpdatePatchServesNoDefault(): void
    {
        $served = $this->spec()['components']['schemas'][self::UPDATE_PATCH]['properties']['kind'] ?? null;
        self::assertIsArray($served, 'Control: the update patch\'s kind is not served.');
        self::assertArrayNotHasKey('default', $served,
            'The update patch\'s kind serves a default: an omitted kind on a patch leaves the kind as it is, it is not reset.');
        $response = $this->spec()['components']['schemas']['StorageLocation']['properties']['kind'] ?? [];
        self::assertArrayNotHasKey('default', $response, 'The StorageLocation read schema serves a default for kind.');
    }

    #[Test]
    public function theRefusalSentenceListsExactlyTheKinds(): void
    {
        self::assertSame('Use one of: '.implode(', ', LocationKinds::ALL).'.', StorageLocationWriter::KIND_SHAPE,
            'StorageLocationWriter::KIND_SHAPE does not list exactly LocationKinds::ALL, in order.');
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        static $memo = null;

        return $memo ??= GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
    }
}
