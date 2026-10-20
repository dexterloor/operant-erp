<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use PHPUnit\Framework\Attributes\Test;

/**
 * **E9-3 for `warehouse`, as a check rather than a sentence** (warehouse/location delete-restore C1, 2026-10-20;
 * `docs/session13/wh-loc-delete-design.md` §1.4, §6.3). The supplier twin is
 * `SupplierOperationContractTest::theArchivedIdProblemIsWhyNoSupplierRestoreShips`.
 *
 * `ListWarehouses` has declared `archived` since warehouses slice 1 C1 (2026-10-13), so the restore became buildable then;
 * this ties the two facts on the TRUE side from `RestoreWarehouse`'s first commit. No archived-ordering index tie (`D-a`):
 * the `/warehouses` list is bounded and ordered by code (`DATA` D-4, none). C2 adds the location twin.
 */
final class WarehouseOperationContractTest extends PurchaseOrderTestCase
{
    /**
     * **E9-3 / API-O-11.** `RestoreWarehouse` ships exactly when an archived warehouse's id is obtainable through
     * `ListWarehouses?archived=…`. `declaredParametersOf()` indexes the registry directly, so a missing or misspelt
     * `ListWarehouses` errors rather than passing vacuously.
     */
    #[Test]
    public function theArchivedIdProblemIsWhyNoWarehouseRestoreShips(): void
    {
        $reachable = \in_array('archived', self::declaredParametersOf('ListWarehouses'), true);
        $declared = array_keys(TransactionBoundaryTest::contractOperations());

        self::assertTrue($reachable, 'Control: ListWarehouses no longer declares archived; RestoreWarehouse would be unreachable.');
        self::assertSame(
            $reachable,
            \in_array('RestoreWarehouse', $declared, true),
            'E9-3: `RestoreWarehouse` ships exactly when an archived warehouse\'s id is obtainable. A restore nobody can reach '
            .'is not a shipped operation, and it is the kind that passes every test because the test holds the id.',
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
}
