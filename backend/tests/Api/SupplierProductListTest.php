<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Suppliers slice 1 C3 — `ListSupplierProducts`**, *(bounded)*, loaded whole for now (owner Q-S3 (b); `RESIDUAL_GAPS.md`
 * G-42). `CustomerAddressListTest`'s shape.
 *
 * The whole catalogue in one response, ordered `supplier_sku, id` (the id breaks a tie: `supplier_sku` is not unique), both
 * cursors null; no query parameter accepted; an archived supplier's catalogue is listed (§5.2a) and an archived item stays
 * named (PC-15 condition 3); an unknown or foreign supplier is the same 404; another supplier's entries never appear. The
 * read is a COST read (PC-12): `viewer` is refused, `buyer` reads it.
 */
final class SupplierProductListTest extends PurchaseOrderTestCase
{
    #[Test]
    public function aSuppliersCatalogueIsListedWholeInSupplierSkuOrder(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Listed Catalogue');
        $other = $this->seedSupplier(self::TENANT_A, 'Not Listed Catalogue');
        $this->seedSupplierProduct(self::TENANT_A, $other, $this->seedItem(self::TENANT_A, 'SKU-OTHER'));
        $bolt = $this->seedItem(self::TENANT_A, 'SKU-BOLT');
        $nut = $this->seedItem(self::TENANT_A, 'SKU-NUT');
        $washer = $this->seedItem(self::TENANT_A, 'SKU-WASHER');
        $entries = [];
        foreach ([[$bolt, 'ZZ-SKU'], [$nut, 'AA-SKU'], [$washer, 'AA-SKU']] as [$item, $sku]) {
            $id = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);
            $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier_product SET supplier_sku = ? WHERE id = ?::uuid', [$sku, $id]);
            $entries[$item] = $id;
        }
        $tied = [$entries[$nut], $entries[$washer]];
        sort($tied);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::BUYER));

        $this->send('GET', '/suppliers/'.$supplier.'/products');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame([...$tied, $entries[$bolt]], array_column($body['items'], 'id'), 'Not ordered supplier_sku, id.');
        self::assertSame(['id' => $entries[$bolt], 'inventory_item' => ['id' => $bolt, 'sku' => 'SKU-BOLT', 'name' => 'SKU-BOLT'],
            'supplier_sku' => 'ZZ-SKU', 'unit_cost' => '1.0000', 'lead_time_days' => 7, 'minimum_order_quantity' => '1.000000',
            'currency_code' => 'EUR'], $body['items'][2]);
        self::assertSame([null, null, true], [$body['next_cursor'], $body['previous_cursor'], $body['tenant_has_rows']]);
    }

    /** §5.2a: an archived supplier's catalogue is read; PC-15 condition 3: an archived item stays named. */
    #[Test]
    public function anArchivedSupplierAndAnArchivedItemAreStillRead(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Archived Catalogue');
        $item = $this->seedItem(self::TENANT_A, 'SKU-GONE');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$supplier]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$item]);

        $this->send('GET', '/suppliers/'.$supplier.'/products');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([$entry], array_column($this->json()['items'], 'id'));
        self::assertSame(['id' => $item, 'sku' => 'SKU-GONE', 'name' => 'SKU-GONE'], $this->json()['items'][0]['inventory_item']);
    }

    /** API-22: an empty set probes the workspace. */
    #[Test]
    public function anEmptySetSaysWhetherTheWorkspaceHasAnyEntry(): void
    {
        $empty = $this->seedSupplier(self::TENANT_A, 'No Catalogue Yet');

        $this->send('GET', '/suppliers/'.$empty.'/products');
        self::assertSame([[], false], [$this->json()['items'], $this->json()['tenant_has_rows']]);

        $this->seedSupplierProduct(self::TENANT_A, $this->seedSupplier(self::TENANT_A, 'Has One'), $this->seedItem(self::TENANT_A, 'SKU-HAS'));
        $this->send('GET', '/suppliers/'.$empty.'/products');
        self::assertSame([[], true], [$this->json()['items'], $this->json()['tenant_has_rows']]);
    }

    /** ST-5: unknown, malformed and another workspace's supplier — the same 404. */
    #[Test]
    public function anUnknownSupplierIsTheSame404(): void
    {
        $foreign = $this->seedSupplier(self::TENANT_B, 'Foreign Catalogue');
        $this->seedSupplierProduct(self::TENANT_B, $foreign, $this->seedItem(self::TENANT_B, 'SKU-FOREIGN'));

        $bodies = [];
        foreach ([$foreign, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $supplier) {
            $this->send('GET', '/suppliers/'.$supplier.'/products');
            self::assertSame(404, $this->httpStatus(), $supplier);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
    }

    /** Bounded (TB-1c, Q-S3 (b)): paging and sorting parameters are refused, never ignored (API-19). */
    #[Test]
    public function aBoundedListRefusesPagingParameters(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Bounded Catalogue');

        foreach (['limit=10', 'sort=supplier_sku', 'archived=true', 'q=AA'] as $query) {
            $this->send('GET', '/suppliers/'.$supplier.'/products?'.$query);
            self::assertSame(422, $this->httpStatus(), $query);
        }
    }

    /** PC-12: the catalogue is purchase cost, and `viewer` is withheld `supplier_product.view` (§4). */
    #[Test]
    public function aViewerCannotReadTheCatalogue(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Cost Is Withheld');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));

        $this->send('GET', '/suppliers/'.$supplier.'/products');

        self::assertSame(403, $this->httpStatus());
        self::assertSame('supplier_product.view', $this->json()['required_permission']);
    }
}
