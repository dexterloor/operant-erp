<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use PHPUnit\Framework\Attributes\Test;

/**
 * **E9-3 and `⛔ D-a` for `customer`, as checks rather than sentences** (C5 C1, 2026-10-13; design Revision 1 R1.1 M-4,
 * from `suppliers-s1-final-red.md:98`'s observation that E9-3 was only a sentence for `customer`). The supplier twin is
 * `SupplierOperationContractTest`; the purchasing twins are `PurchaseOrderOperationContractTest`'s.
 *
 * Added while **both** sides are undeclared — `ListCustomers` offers no `archived` and `RestoreCustomer` is not built — so
 * the tie is born green on a true state and is not written to fit the slice that flips it (RED M-4: a twin born in the
 * commit that moves both sides proves nothing about the commit before). C5's C4a moves all three facts together
 * (restore declared ⇔ `archived` offered ⇔ the archived-ordering index migrated); moving one alone is red here.
 */
final class CustomerOperationContractTest extends PurchaseOrderTestCase
{
    /** The index the `/customers` Archived view (`IA.md` §5.6, `⛔ D-a`) waits for; by the house `<table>_tenant_archived_idx` name. */
    private const ARCHIVED_INDEX = 'customer_tenant_archived_idx';

    /**
     * **E9-3 / API-O-11.** `RestoreCustomer` ships exactly when an archived customer's id is obtainable through
     * `ListCustomers?archived=…`. `declaredParametersOf()` indexes the registry directly, so a missing or misspelt
     * `ListCustomers` errors rather than passing vacuously.
     */
    #[Test]
    public function theArchivedIdProblemIsWhyNoCustomerRestoreShips(): void
    {
        $reachable = \in_array('archived', self::declaredParametersOf('ListCustomers'), true);
        $declared = array_keys(TransactionBoundaryTest::contractOperations());

        self::assertContains('ListCustomers', $declared, 'The registry read is blind: ListCustomers is built and was not found.');

        self::assertSame(
            $reachable,
            \in_array('RestoreCustomer', $declared, true),
            'E9-3: `RestoreCustomer` ships exactly when an archived customer\'s id is obtainable (owner Q-S1 (d): with '
            .'DeleteCustomer and the supplier pair, in one archived-view slice, C5). A restore nobody can reach is not a '
            .'shipped operation, and it is the kind that passes every test because the test holds the id.',
        );
    }

    /** **`⛔ D-a`, tied to the migrations:** `/customers` offers `archived` exactly when the archived-ordering index exists. */
    #[Test]
    public function archivedIsOfferedOnCustomersExactlyWhenItsIndexIsMigrated(): void
    {
        $indexes = self::indexesCreatedOn('customer');
        self::assertContains('customer_tenant_code_live', $indexes,
            'The migration reader did not find an index known to exist on customer, so the condition below would be decided by a broken reader.');

        self::assertSame(
            \in_array(self::ARCHIVED_INDEX, $indexes, true),
            \in_array('archived', self::declaredParametersOf('ListCustomers'), true),
            'D-a: `/customers` must offer `archived` exactly when '.self::ARCHIVED_INDEX.' is migrated. Indexes found on customer: '
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
