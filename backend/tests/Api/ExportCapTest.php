<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Application\ExportRunner;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

/**
 * **The export row cap (G6-C0; design §6.5, Response M-7).** Exactly the cap → 200 with the cap's rows; one more → 409
 * `business-rule-violated`, rule `export.too_many_rows`, decided in the same statement as the rows (`LIMIT cap + 1`), for a
 * paged List and for a bounded one. *Mutation:* `>=` for `>` in the over-cap decision → the cap itself is refused → red.
 *
 * The suite runs on a lowered cap (`services.yaml` `when@test`) so a fixture need not seed 10,001 rows; the production
 * figure is pinned separately, and stays a PROPOSAL pending `PO`/`DATA` (the measurement is in the progress note).
 */
final class ExportCapTest extends PurchaseOrderTestCase
{
    use ExportTestSupport;

    /** The figure §6.5 proposes. Changing it is a `PO`/`DATA` decision, made here and in `services.yaml` together. */
    private const PRODUCTION_CAP = 10000;

    #[Test]
    public function theProductionCapIsTheProposedFigure(): void
    {
        $services = Yaml::parseFile(\dirname(__DIR__, 2).'/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertSame(self::PRODUCTION_CAP, $services['parameters']['app.export.row_cap'] ?? null);
        self::assertLessThan(self::PRODUCTION_CAP, (int) self::getContainer()->getParameter('app.export.row_cap'),
            'The suite is meant to run on a lowered cap; it is running on the production one.');
    }

    /**
     * G6-C1: the lowered cap must still let `ListPermissions` export — the catalogue is global (IA §6.2), so no fixture can
     * make it smaller than it is, and a cap below it would leave that family refusable only. Derived from the server.
     */
    #[Test]
    public function theTestCapLetsTheGlobalCatalogueExport(): void
    {
        $catalogue = (int) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) AS n FROM permission', [])[0]['n'];
        self::assertGreaterThan(0, $catalogue, 'The catalogue is empty, so this holds over nothing.');
        self::assertGreaterThanOrEqual($catalogue, $this->cap(), 'The test cap is below the global permission catalogue, so ListPermissions can never export in the suite.');

        $this->export(['resource' => 'permission']);
        self::assertCount(1 + $catalogue, $this->exportRows());
    }

    #[Test]
    public function aPagedListAtTheCapExportsAndOneMoreIsRefused(): void
    {
        $cap = $this->cap();
        for ($i = 1; $i <= $cap; ++$i) {
            $this->seedItem(self::TENANT_A, \sprintf('SKU-%03d', $i));
        }

        $this->export(['resource' => 'inventory_item']);
        self::assertCount(1 + $cap, $this->exportRows(), 'Exactly the cap must export, every row.');

        $this->seedItem(self::TENANT_A, 'SKU-OVER');
        $this->export(['resource' => 'inventory_item']);
        $this->assertOverCap();
    }

    #[Test]
    public function aBoundedListAtTheCapExportsAndOneMoreIsRefused(): void
    {
        $cap = $this->cap();
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01');
        $stock = function (string $sku) use ($bin): void {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $this->seedItem(self::TENANT_A, $sku), $bin]);
        };
        for ($i = 1; $i <= $cap; ++$i) {
            $stock(\sprintf('SKU-%03d', $i));
        }

        $this->export(['resource' => 'stock_level', 'filters' => ['storage_location_id' => $bin]]);
        self::assertCount(1 + $cap, $this->exportRows());

        $stock('SKU-OVER');
        $this->export(['resource' => 'stock_level', 'filters' => ['storage_location_id' => $bin]]);
        $this->assertOverCap();
    }

    private function assertOverCap(): void
    {
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertStringStartsWith('application/problem+json', $this->contentType());
        self::assertSame(ExportRunner::TOO_MANY_ROWS, $this->json()['rule']);
        self::assertSame(\sprintf('This view has more than %s rows. Narrow the filters and export again.', number_format($this->cap())),
            $this->json()['detail'] ?? null);
    }

    private function cap(): int
    {
        return (int) self::getContainer()->getParameter('app.export.row_cap');
    }
}
