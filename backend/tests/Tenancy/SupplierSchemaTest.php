<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Component\Uid\Uuid;

/**
 * **The schema change suppliers slice 1 lays before any supplier operation exists — C0** (`Version20261012000100`).
 * Every row here is written **as the owner**: the subject is the constraint, and constraints bind every role
 * (`attemptAsOwnerForTenant`'s docblock). `erp_app` holds SELECT only on `supplier` after this commit;
 * `GrantFootprintTest` holds that against the manifest.
 *
 * `supplier.country_code`: `text NOT NULL`, **no default** (a silent wrong country for every row is what the migration
 * refuses to invent), foreign-keyed onto the `country` reference table — so a code outside it is 23503. The
 * unsupported example is `ZZ`, never a real country, so seeding ISO 3166-1 (G-37) cannot turn this test green-for-
 * the-wrong-reason or red.
 */
final class SupplierSchemaTest extends TenancyTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function aSuppliersCountryIsRequiredHasNoDefaultAndIsAKnownCountry(): void
    {
        $column = $this->owner()->fetchAssociative(<<<'SQL'
            SELECT data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'supplier' AND column_name = 'country_code'
            SQL);
        self::assertSame(['data_type' => 'text', 'is_nullable' => 'NO', 'column_default' => null], $column,
            'supplier.country_code must be text NOT NULL with NO default: a default is a silent wrong country.');

        $fk = $this->owner()->fetchOne(<<<'SQL'
            SELECT pg_get_constraintdef(oid) FROM pg_constraint
            WHERE conrelid = 'supplier'::regclass AND conname = 'supplier_country_fk'
            SQL);
        self::assertSame('FOREIGN KEY (country_code) REFERENCES country(code)', $fk);

        self::assertSame('accepted', $this->attemptSupplier(self::TENANT_A, 'GB'), 'The control.');
        self::assertSame('23503', $this->attemptSupplier(self::TENANT_A, 'ZZ'),
            'A country the reference table does not hold was accepted.');
        self::assertSame('23502', $this->attemptSupplier(self::TENANT_A, null),
            'A supplier without a country was accepted.');
    }

    private function attemptSupplier(string $tenantId, ?string $countryCode): string
    {
        $id = Uuid::v4()->toRfc4122();

        return $this->attemptAsOwnerForTenant(
            $tenantId,
            'INSERT INTO supplier (id, tenant_id, code, name, country_code, currency_code) VALUES (?::uuid, ?::uuid, ?, ?, ?, ?)',
            [$id, $tenantId, 'S-'.substr($id, 0, 8), 'Schema Supplier '.substr($id, 0, 8), $countryCode, 'EUR'],
        );
    }
}
