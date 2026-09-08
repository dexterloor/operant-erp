<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;

/**
 * `GetItem` — the read shape, and the three refusals §5.3 orders.
 */
final class ItemReadTest extends ApiTestCase
{
    /**
     * API-23's derivation for `Item`, spelled out because it is the thing under
     * test. Steps 1-3 of §4.4.3 over §4.2's `CreateItem` row, with `supplier_id`
     * expanded per API-23b; §4.4.3's additions table has no `Item` row, so this
     * is the whole shape.
     *
     * `ItemOperationContractTest` reconciles it against `docs/API.md`; here it
     * is the expectation the response is compared against, in **both**
     * directions -- a field added to the DTO and not here fails, and so does one
     * declared here and dropped from the DTO.
     */
    public const DECLARED_FIELDS = [
        'id', 'sku', 'name', 'unit_of_measure_code', 'supplier',
        'reorder_point', 'reorder_quantity', 'safety_stock', 'archived_at',
    ];

    #[Test]
    public function theResponseCarriesExactlyTheDeclaredFieldList(): void
    {
        $id = $this->seedItem(self::TENANT_A, 'SKU-1');

        $this->send('GET', '/items/'.$id);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(self::DECLARED_FIELDS, array_keys($this->json()));
    }

    /** API-6 / P-6 / T-1 — walked recursively, because a nested DTO is where one would hide. */
    #[Test]
    public function noResponseFieldNamesATenantAtAnyDepth(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $id = $this->seedItem(self::TENANT_A, 'SKU-1', $supplier);

        $this->send('GET', '/items/'.$id);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['id', 'name'], array_keys($this->json()['supplier']), 'API-23b: an expansion carries the id and the display field only.');
        self::assertSame([], self::tenantKeysIn($this->json()));
    }

    /** API-23b — a foreign key is expanded, never returned as a bare uuid. */
    #[Test]
    public function theSupplierReferenceIsExpanded(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $id = $this->seedItem(self::TENANT_A, 'SKU-1', $supplier);

        $this->send('GET', '/items/'.$id);

        self::assertSame(['id' => $supplier, 'name' => 'Acme'], $this->json()['supplier']);
        self::assertArrayNotHasKey('supplier_id', $this->json());
    }

    /**
     * API-13a / ST-9 — *"Never a 404"*. This is also the only path by which a
     * caller obtains the id `RestoreItem` takes, because §10.2 does not offer
     * the `⛔ D-a` archived list.
     */
    #[Test]
    public function anArchivedRowIsReadableByDirectHitAndCarriesItsArchivedAt(): void
    {
        $id = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->send('DELETE', '/items/'.$id);
        self::assertSame(204, $this->httpStatus());

        $this->send('GET', '/items/'.$id);

        self::assertSame(200, $this->httpStatus());
        self::assertNotNull($this->json()['archived_at']);
    }

    /**
     * §5.3 — a row outside the caller's tenant, a row that never existed and an
     * id that is not a uuid must all answer identically. Anything else is an
     * existence oracle.
     */
    #[Test]
    public function everyIdTheCallerCannotSeeGetsTheSameAnswer(): void
    {
        $elsewhere = $this->seedItem(self::TENANT_B, 'SKU-B');

        $answers = [];
        foreach ([$elsewhere, '00000000-0000-4000-8000-000000000000', 'not-a-uuid'] as $id) {
            $this->send('GET', '/items/'.$id);
            $answers[] = $this->httpStatus().' '.$this->contentType().' '.$this->client()->getResponse()->getContent();
        }

        self::assertCount(1, array_unique($answers), 'The three refusals differ, which tells a caller which ids exist.');
        self::assertStringStartsWith('404 application/problem+json', $answers[0]);
    }

    /** @param array<mixed> $value @return list<string> */
    public static function tenantKeysIn(array $value, string $path = ''): array
    {
        $found = [];

        foreach ($value as $key => $item) {
            $here = $path.'/'.$key;
            if (\in_array(strtolower((string) $key), ['tenant_id', 'tenantid'], true)) {
                $found[] = $here;
            }
            if (\is_array($item)) {
                $found = [...$found, ...self::tenantKeysIn($item, $here)];
            }
        }

        return $found;
    }
}
