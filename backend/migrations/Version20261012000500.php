<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Suppliers slice 1, C3 — the writes `CreateSupplierProduct` and `UpdateSupplierProduct` issue, and nothing more**
 * (ADR-002 A4.1: the grant ships with its caller, granting exactly what the caller issues; `Version20261012000200`'s twin).
 *
 * `erp_app` held SELECT only on `supplier_product`. `SupplierProductWriter` issues one INSERT and one UPDATE, both raw SQL
 * naming their columns, so the grants are **column-level**:
 *
 *   INSERT (id, tenant_id, supplier_id, inventory_item_id, supplier_sku, unit_cost, lead_time_days, min_order_quantity, currency_code)
 *   UPDATE (supplier_sku, unit_cost, lead_time_days, min_order_quantity, updated_at)
 *
 * **Why not table-level:** a table UPDATE would let SQL as `erp_app` re-point an entry at another supplier or item, or
 * relabel its `currency_code` without converting the number — `currency_code` is copied from the supplier at create and
 * never rewritten (owner Q-S4 (a)+(ii)). `min_order_quantity` is in the INSERT list although the writer omits it when the
 * body does (the column default 1 then applies): both statement shapes are the writer's. **No DELETE**:
 * `DeleteSupplierProduct` is C4, and its table-level DELETE lands with it.
 *
 * Nothing in this migration grants on `supplier`: `CreateSupplierProduct`'s `FOR SHARE` parent read is legal because C2
 * already granted a column UPDATE there (A4.3 constraint 1).
 *
 * `erp_ai` keeps exactly its inherited table SELECT (`unit_cost` included — PC-8's map line for `supplier_product` is owed
 * to `SEC`). `GrantFootprintTest` holds both lists against `SchemaManifest` and runs each statement as `erp_app`.
 */
final class Version20261012000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppliers slice 1 C3: erp_app column INSERT and column UPDATE on supplier_product (CreateSupplierProduct, UpdateSupplierProduct)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, supplier_id, inventory_item_id, supplier_sku, unit_cost, lead_time_days, min_order_quantity, currency_code) ON supplier_product TO erp_app');
        $this->addSql('GRANT UPDATE (supplier_sku, unit_cost, lead_time_days, min_order_quantity, updated_at) ON supplier_product TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (supplier_sku, unit_cost, lead_time_days, min_order_quantity, updated_at) ON supplier_product FROM erp_app');
        $this->addSql('REVOKE INSERT (id, tenant_id, supplier_id, inventory_item_id, supplier_sku, unit_cost, lead_time_days, min_order_quantity, currency_code) ON supplier_product FROM erp_app');
    }
}
