<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Application\ExportSources;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Export\CsvCell;
use App\Platform\Http\Export\CsvExportRenderer;
use App\Platform\Http\Export\ListDeclarations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **The four families with nested lines export their lines as their List serves them (G6-C1; `g6-c1-red.md` H-1).**
 *
 * `goods_receipt`, `fulfilment`, `sales_return` and `credit_note` rows carry a `lines` array, which the export renders as
 * one cell of compact JSON (design §6.3) from the same normalizer the List's response uses. The family drive in
 * `ExportContractTest` seeds line-less headers, so it never compares a line; this class books real documents THROUGH THE
 * API — a receipt with a line, a shipment, its invoice, an owner's adjustment, a return of the adjusted line and finance's
 * `same` decision on it — and compares each export with its List, cell by cell (`escape(list) == export`), with the lines
 * cell holding the lines.
 *
 * The line-level members the List hides from a caller without `invoice.view` (`quantity_covered_by_adjustment`,
 * `adjustment_decisions` on a return line; final RED M-5) are present in the export for an `invoice.view` holder and ABSENT
 * from it for `warehouse_operator` (who holds `sales_return.view` only), whose export still equals that caller's List.
 * *Mutation (run, `g6-c1-progress.md`):* the renderer writes every array cell as `[]` → no exported row holds a line → red.
 */
final class ExportNestedLinesTest extends PurchaseOrderTestCase
{
    use ExportTestSupport;
    use InvoiceFixtures;
    use CreditFixtures;
    use CreditNoteFixtures;

    #[Test]
    public function linesAndTheirCallerDependentMembersAreServedAsTheListServesThem(): void
    {
        // A receipt with one line, through the API.
        $supplier = $this->seedSupplier(self::TENANT_A, 'Lines Supplier');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Lines');
        $order = $this->seedOrder(self::TENANT_A, 'PO-318000', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);
        $orderLine = $this->seedLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-EXL'), quantity: '5.000000');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01');
        $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', ['lines' => [
            ['purchase_order_line_id' => $orderLine, 'quantity' => '2', 'storage_location_id' => $bin],
        ]], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the receipt. '.(string) $this->client()->getResponse()->getContent());

        // A shipment and its invoice, an owner's adjustment of 3, a return of 2 of that line, and finance's `same` 2.
        $shipment = $this->invoicedShipment('EXL-1', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$shipment['invoice'].'/credit-note-adjustments', [
            'reason' => 'damaged_goods_kept',
            'lines' => [['fulfilment_line_id' => $shipment['lines'][0], 'quantity' => '3']],
        ], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the adjustment. '.(string) $this->client()->getResponse()->getContent());
        $adjustmentLine = (string) $this->json()['lines'][0]['id'];
        $return = $this->returnOf($shipment, [[0, '2']]);
        $returnLine = (string) $this->json()['lines'][0]['id'];
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/sales-returns/'.$return.'/adjustment-decisions',
            ['kind' => 'same', 'sales_return_line_id' => $returnLine, 'credit_note_line_id' => $adjustmentLine, 'quantity' => '2'],
            ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the decision. '.(string) $this->client()->getResponse()->getContent());

        // A caller holding every declared code (so every one of the four Lists, and `invoice.view`): lines in full, decisions included.
        $this->actAs(self::TENANT_A);
        foreach (['goods_receipt', 'fulfilment', 'sales_return', 'credit_note'] as $resource) {
            $lines = $this->linesCells($resource);
            self::assertNotSame([], array_filter($lines, static fn (string $cell): bool => '[]' !== $cell && '' !== $cell),
                $resource.': no exported row holds a line, so the lines cell was never compared.');
        }
        $returnLines = implode('', $this->linesCells('sales_return'));
        self::assertStringContainsString('"adjustment_decisions":[{', $returnLines, 'An invoice.view holder is not served the return line\'s decision.');
        self::assertStringContainsString('"quantity_covered_by_adjustment":"2.000000"', $returnLines);
        self::assertStringContainsString('"return_decisions":[{', implode('', $this->linesCells('credit_note')),
            'An invoice.view holder is not served the adjustment line\'s return decision.');

        // `warehouse_operator`: `sales_return.view`, no `invoice.view` — the same List, and so the same export, without them.
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $returnLines = implode('', $this->linesCells('sales_return'));
        self::assertStringContainsString('"id":', $returnLines, 'Probe: the lines cell holds no line, so nothing is shown absent.');
        self::assertStringNotContainsString('adjustment_decisions', $returnLines, 'A caller without invoice.view was exported a decision.');
        self::assertStringNotContainsString('quantity_covered_by_adjustment', $returnLines);
    }

    /**
     * Exports `$resource` unfiltered, holds it equal to the List cell for cell, and returns the `lines` column's cells.
     *
     * @return list<string>
     */
    private function linesCells(string $resource): array
    {
        $sources = self::getContainer()->get(ExportSources::class);
        $declarations = self::getContainer()->get(ListDeclarations::class);
        $renderer = self::getContainer()->get(CsvExportRenderer::class);
        \assert($sources instanceof ExportSources && $declarations instanceof ListDeclarations && $renderer instanceof CsvExportRenderer);
        $source = $sources->get($resource);
        $header = $renderer->header($source->rowClass());

        $items = $this->listAll($declarations->path($source), []);
        $this->export(['resource' => $resource]);
        $rows = $this->exportRows();
        self::assertSame($header, $rows[0]);
        self::assertSame(array_map(static fn (array $item): array => array_map(
            static fn (string $leaf): string => CsvCell::escape(CsvExportRenderer::cell($item, $leaf)),
            $header,
        ), $items), \array_slice($rows, 1), $resource.': the export is not the List\'s view.');
        self::assertNotSame([], $items, $resource.': the List is empty, so the comparison holds over nothing.');

        $column = array_search('lines', $header, true);
        self::assertIsInt($column, $resource.': the header has no lines column.');

        return array_map(static fn (array $row): string => $row[$column], \array_slice($rows, 1));
    }
}
