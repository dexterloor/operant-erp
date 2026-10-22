<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;

/**
 * **`API.md` §4.3b's owed check** (G7b `UpdateSettings`, 2026-10-22; §4.3b's *fails if*): a Settings field the contract
 * declares writable has a column the application principal can actually write. Domain: the properties of `UpdateSettings`'
 * request schema in the GENERATED spec — never a typed list — each asked of the live server with `has_column_privilege`
 * (never `information_schema`, which resolves neither `PUBLIC` nor role inheritance).
 *
 * Mutation, run: adding `default_currency_code` to `UpdateSettingsInput` → red here (no column grant: the currency is
 * read-only, owner ruling RO-S1).
 */
final class SettingsContractTest extends ApiTestCase
{
    #[Test]
    public function everyDeclaredSettingsFieldHasAColumn(): void
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));

        $operation = null;
        foreach (GeneratedSpec::operations($spec) as $data) {
            if ('UpdateSettings' === ($data['operation']['operationId'] ?? null)) {
                $operation = $data['operation'];
            }
        }
        self::assertIsArray($operation, 'UpdateSettings is not in the generated spec.');

        $fields = [];
        foreach ($operation['requestBody']['content'] ?? [] as $content) {
            $schema = $content['schema'] ?? [];
            if (isset($schema['$ref'])) {
                $schema = $spec['components']['schemas'][substr((string) $schema['$ref'], 21)] ?? [];
            }
            foreach (array_keys($schema['properties'] ?? []) as $field) {
                $fields[$field] = true;
            }
        }
        $fields = array_keys($fields);
        sort($fields);

        // Positive control: the one field the operation does write is seen, so an empty domain cannot pass.
        self::assertContains('display_name', $fields, 'Control: UpdateSettings\' request schema does not declare display_name, so this check read nothing.');

        $unwritable = [];
        foreach ($fields as $field) {
            $writable = $this->owner()->fetchOne(<<<'SQL'
                SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'tenant_settings'::regclass AND attname = ? AND attnum > 0 AND NOT attisdropped)
                            THEN has_column_privilege('erp_app', 'tenant_settings', ?, 'UPDATE')
                            ELSE false END
                SQL, [$field, $field]);
            if (true !== $writable) {
                $unwritable[] = $field;
            }
        }

        self::assertSame([], $unwritable, 'UpdateSettings declares fields erp_app cannot write on tenant_settings (API.md §4.3b): '.implode(', ', $unwritable));

        // Negative control: the read-only currency is a column erp_app CANNOT update, so the probe can say "no".
        self::assertFalse($this->owner()->fetchOne("SELECT has_column_privilege('erp_app', 'tenant_settings', 'default_currency_code', 'UPDATE')"),
            'Control: the currency column is writable, so the probe above cannot tell a missing grant.');
    }
}
