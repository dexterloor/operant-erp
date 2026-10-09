<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Component\Uid\Uuid;

/**
 * **The two schema changes customers slice 1 lays before any customer operation exists — C0**
 * (`Version20261009000100`, `Version20261009000200`). Every row here is written **as the owner**: the subject is the
 * constraint, and constraints bind every role (`attemptAsOwnerForTenant`'s docblock). `erp_app` holds SELECT only on
 * both tables after this commit; `GrantFootprintTest` holds that against the manifest.
 *
 * - `customer.country_code`: `text NOT NULL`, **no default** (a silent wrong country for every row is what the
 *   migration refuses to invent), foreign-keyed onto the `country` reference table — so a code outside it is 23503.
 * - `customer_address`'s header retry key: both columns NOT NULL and 64 lower-case hex, and the unique on
 *   `(tenant_id, idempotency_key)` is TOTAL (class `hard`, no `deleted_at`) and per workspace.
 */
final class CustomerSchemaTest extends TenancyTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function aCustomersCountryIsRequiredHasNoDefaultAndIsAKnownCountry(): void
    {
        $column = $this->owner()->fetchAssociative(<<<'SQL'
            SELECT data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'customer' AND column_name = 'country_code'
            SQL);
        self::assertSame(['data_type' => 'text', 'is_nullable' => 'NO', 'column_default' => null], $column,
            'customer.country_code must be text NOT NULL with NO default: a default is a silent wrong country.');

        $fk = $this->owner()->fetchOne(<<<'SQL'
            SELECT pg_get_constraintdef(oid) FROM pg_constraint
            WHERE conrelid = 'customer'::regclass AND conname = 'customer_country_fk'
            SQL);
        self::assertSame('FOREIGN KEY (country_code) REFERENCES country(code)', $fk);

        self::assertSame('accepted', $this->attemptCustomer(self::TENANT_A, ['country_code' => 'GB']), 'The control.');
        self::assertSame('23503', $this->attemptCustomer(self::TENANT_A, ['country_code' => 'ZZ']),
            'A country the reference table does not hold was accepted.');
        self::assertSame('23502', $this->attemptCustomer(self::TENANT_A, ['country_code' => null]),
            'A customer without a country was accepted.');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anAddressRetryKeyIsAHexDigestUniquePerWorkspace(): void
    {
        $a = $this->seedCustomerRow(self::TENANT_A);
        $b = $this->seedCustomerRow(self::TENANT_B);
        $key = hash('sha256', 'one key');

        foreach (['idempotency_key', 'request_digest'] as $column) {
            self::assertSame('23502', $this->attemptAddress(self::TENANT_A, $a, [$column => null]), $column.' accepted NULL.');
            self::assertSame('23514', $this->attemptAddress(self::TENANT_A, $a, [$column => strtoupper($key)]), $column.' accepted upper-case hex.');
            self::assertSame('23514', $this->attemptAddress(self::TENANT_A, $a, [$column => substr($key, 1)]), $column.' accepted 63 characters.');
            self::assertSame('23514', $this->attemptAddress(self::TENANT_A, $a, [$column => 'abc']), $column.' accepted "abc".');
        }

        $this->insertAsOwnerForTenant(self::TENANT_A, ...$this->addressStatement(self::TENANT_A, $a, ['idempotency_key' => $key]));
        self::assertSame('23505', $this->attemptAddress(self::TENANT_A, $a, ['idempotency_key' => $key]),
            'A second address took a key already held in this workspace.');

        $other = $this->seedCustomerRow(self::TENANT_A);
        self::assertSame('23505', $this->attemptAddress(self::TENANT_A, $other, ['idempotency_key' => $key]),
            'The unique is per WORKSPACE, not per customer: another customer may not reuse a held key.');
        self::assertSame('accepted', $this->attemptAddress(self::TENANT_B, $b, ['idempotency_key' => $key]),
            'The control: another workspace may hold the same key hash.');

        $definition = $this->owner()->fetchOne(<<<'SQL'
            SELECT indexdef FROM pg_indexes WHERE tablename = 'customer_address' AND indexname = 'customer_address_idem_unique'
            SQL);
        self::assertSame(
            'CREATE UNIQUE INDEX customer_address_idem_unique ON public.customer_address USING btree (tenant_id, idempotency_key)',
            $definition,
            'The unique must be TOTAL (no WHERE): customer_address has no deleted_at.',
        );
    }

    private function seedCustomerRow(string $tenantId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, ...$this->customerStatement($tenantId, ['id' => $id]));

        return $id;
    }

    /**
     * @param array<string, string|null> $override
     *
     * @return array{string, list<mixed>}
     */
    private function customerStatement(string $tenantId, array $override): array
    {
        $id = $override['id'] ?? Uuid::v4()->toRfc4122();
        $v = $override + ['code' => 'C-'.substr($id, 0, 8), 'name' => 'Schema Customer', 'country_code' => 'GB', 'currency_code' => 'EUR'];

        return [
            'INSERT INTO customer (id, tenant_id, code, name, country_code, currency_code) VALUES (?::uuid, ?::uuid, ?, ?, ?, ?)',
            [$id, $tenantId, $v['code'], $v['name'], $v['country_code'], $v['currency_code']],
        ];
    }

    /** @param array<string, string|null> $override */
    private function attemptCustomer(string $tenantId, array $override): string
    {
        return $this->attemptAsOwnerForTenant($tenantId, ...$this->customerStatement($tenantId, $override));
    }

    /**
     * @param array<string, string|null> $override
     *
     * @return array{string, list<mixed>}
     */
    private function addressStatement(string $tenantId, string $customerId, array $override): array
    {
        $v = $override + [
            'idempotency_key' => hash('sha256', 'key:'.Uuid::v4()->toRfc4122()),
            'request_digest' => hash('sha256', 'digest:'.Uuid::v4()->toRfc4122()),
        ];

        return [<<<'SQL'
            INSERT INTO customer_address
                (id, tenant_id, customer_id, kind, address_line, city, postal_code, country_code, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?::uuid, 'shipping', '1 Test Street', 'London', 'SW1A 1AA', 'GB', ?, ?)
            SQL, [Uuid::v4()->toRfc4122(), $tenantId, $customerId, $v['idempotency_key'], $v['request_digest']]];
    }

    /** @param array<string, string|null> $override */
    private function attemptAddress(string $tenantId, string $customerId, array $override): string
    {
        return $this->attemptAsOwnerForTenant($tenantId, ...$this->addressStatement($tenantId, $customerId, $override));
    }
}
