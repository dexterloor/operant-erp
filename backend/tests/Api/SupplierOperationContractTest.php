<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use PHPUnit\Framework\Attributes\Test;

/**
 * **E9-3 and `⛔ D-a` for `supplier`, as checks rather than sentences** (suppliers slice 1 C1; design FINAL F-2, final RED
 * C1-3). The purchasing twins are `PurchaseOrderOperationContractTest::theArchivedIdProblemIsWhyNoRestoreShips` and
 * `::archivedIsOfferedOnTheBoundedSubListAndOnTheDocumentListExactlyWithItsIndex` (renamed at order restores C3).
 *
 * The owner ruled 2026-10-12 (Q-S1 (d)) that `DeleteSupplier` and `RestoreSupplier` ship later, together with
 * `DeleteCustomer`/`RestoreCustomer`, in ONE archived-view slice (C5). These two methods tie the three facts together
 * (restore declared ⇔ `archived` offered ⇔ the archived-ordering index migrated). **C5 C2a moved all three together**
 * (`RestoreSupplier`, `ListSuppliers?archived`, `supplier_tenant_archived_idx` in `Version20261013000500`), so both ties now
 * hold on the TRUE side; moving any one back alone is red here. `DeleteSupplier` followed at C5 C2b (restore before delete,
 * so no window existed in which a supplier could be archived by the API and not restored).
 */
final class SupplierOperationContractTest extends PurchaseOrderTestCase
{
    /** The index the `/suppliers` Archived view (`IA.md` §5.6, `⛔ D-a`) waits for; by the house `<table>_tenant_archived_idx` name. */
    private const ARCHIVED_INDEX = 'supplier_tenant_archived_idx';

    /**
     * **E9-3 / API-O-11.** `RestoreSupplier` ships exactly when an archived supplier's id is obtainable through
     * `ListSuppliers?archived=…`. `declaredParametersOf()` indexes the registry directly, so a missing or misspelt
     * `ListSuppliers` errors rather than passing vacuously.
     */
    #[Test]
    public function theArchivedIdProblemIsWhyNoSupplierRestoreShips(): void
    {
        $reachable = \in_array('archived', self::declaredParametersOf('ListSuppliers'), true);
        $declared = array_keys(TransactionBoundaryTest::contractOperations());

        self::assertSame(
            $reachable,
            \in_array('RestoreSupplier', $declared, true),
            'E9-3: `RestoreSupplier` ships exactly when an archived supplier\'s id is obtainable (owner Q-S1 (d): with '
            .'DeleteSupplier and the customer pair, in one archived-view slice). A restore nobody can reach is not a '
            .'shipped operation, and it is the kind that passes every test because the test holds the id.',
        );
    }

    /** **`⛔ D-a`, tied to the migrations:** `/suppliers` offers `archived` exactly when the archived-ordering index exists. */
    #[Test]
    public function archivedIsOfferedOnSuppliersExactlyWhenItsIndexIsMigrated(): void
    {
        $indexes = self::indexesCreatedOn('supplier');
        self::assertContains('supplier_tenant_name_live', $indexes,
            'The migration reader did not find an index known to exist on supplier, so the condition below would be decided by a broken reader.');

        self::assertSame(
            \in_array(self::ARCHIVED_INDEX, $indexes, true),
            \in_array('archived', self::declaredParametersOf('ListSuppliers'), true),
            'D-a: `/suppliers` must offer `archived` exactly when '.self::ARCHIVED_INDEX.' is migrated. Indexes found on supplier: '
            .implode(', ', $indexes),
        );
    }

    /** @return list<string> */
    private static function declaredParametersOf(string $operationId): array
    {
        $operation = TransactionBoundaryTest::contractOperations()[$operationId];
        \assert($operation instanceof HttpOperation);

        $declared = [];
        foreach ($operation->getParameters() ?? [] as $key => $parameter) {
            $declared[] = (string) ($parameter->getKey() ?? $key);
        }

        return $declared;
    }

    /** @return list<string> the index names `CREATE [UNIQUE] INDEX … ON <table>` creates anywhere under `backend/migrations` */
    private static function indexesCreatedOn(string $table): array
    {
        $directory = \dirname(__DIR__, 2).'/migrations';
        $files = glob($directory.'/*.php');
        self::assertNotFalse($files);
        self::assertNotSame([], $files, 'No migration was found under '.$directory.'.');

        $names = [];
        foreach ($files as $file) {
            preg_match_all(
                '/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?(?:IF\s+NOT\s+EXISTS\s+)?([a-z0-9_]+)\s+ON\s+'.preg_quote($table, '/').'\b/i',
                (string) file_get_contents($file),
                $matches,
            );
            $names = [...$names, ...$matches[1]];
        }

        return array_values(array_unique($names));
    }
}
