<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Api\Idempotency\IdempotencyKeyGuard;
use App\Inventory\ApiResource\CreateItemInput;
use App\Inventory\ApiResource\UpdateItemInput;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * §4.2's `/items` rows, reconciled against what the application actually
 * declares.
 *
 * ---------------------------------------------------------------------------
 * Why there is a pin here at all
 * ---------------------------------------------------------------------------
 * `docs/` is gitignored, so a check whose only domain is the document has no
 * subject on a clean checkout — the NO ARTIFACT shape API-10a refuses. The
 * table below is therefore a **pin** in the §12.3 sense: it is checked against
 * the operation registry on every run, and, wherever the document is present,
 * against §4.2 as well, in both directions. Adding an operation to the code
 * without adding it here fails; adding it to §4.2 without adding it here fails
 * too, on any checkout that carries the document.
 */
final class ItemOperationContractTest extends ApiTestCase
{
    /**
     * The six rows §4.2 declares for `inventory_item`, transcribed once.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    private const PINNED_ITEM_OPERATIONS = [
        'ListItems' => ['method' => 'GET', 'path' => '/items', 'idem' => false],
        'GetItem' => ['method' => 'GET', 'path' => '/items/{id}', 'idem' => false],
        'CreateItem' => ['method' => 'POST', 'path' => '/items', 'idem' => true],
        'UpdateItem' => ['method' => 'PATCH', 'path' => '/items/{id}', 'idem' => false],
        'DeleteItem' => ['method' => 'DELETE', 'path' => '/items/{id}', 'idem' => false],
        'RestoreItem' => ['method' => 'POST', 'path' => '/items/{id}/restoration', 'idem' => true],
    ];

    /** §4.2's `Accepted request fields` for both write rows. They are the same seven. */
    private const PINNED_ACCEPTED_FIELDS = [
        'sku', 'name', 'unit_of_measure_code', 'supplier_id',
        'reorder_point', 'reorder_quantity', 'safety_stock',
    ];

    /** §4.2's `Query parameters` cell for `ListItems`. */
    private const PINNED_QUERY_PARAMETERS = ['supplier_id', 'q', 'sort', 'limit', 'after', 'before'];

    /** The pin against the registry: method, path and the `Idem` flag. */
    #[Test]
    public function theRegistryDeclaresExactlyThePinnedOperations(): void
    {
        $declared = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $declared[$name] = [
                'method' => $operation->getMethod(),
                'path' => str_replace(self::BASE, '', (string) $operation->getUriTemplate()),
                'idem' => true === ($operation->getExtraProperties()[IdempotencyKeyGuard::OPERATION_FLAG] ?? null),
            ];
        }

        ksort($declared);
        $pinned = self::PINNED_ITEM_OPERATIONS;
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /**
     * B-3, read off the input classes rather than off the spec: these are what
     * the deserializer will accept, and `additionalProperties: false` in the
     * generated schema is derived from them.
     */
    #[Test]
    public function theInputDtosDeclareExactlyTheAcceptedFieldsAndNoId(): void
    {
        foreach ([CreateItemInput::class, UpdateItemInput::class] as $input) {
            $serialized = [];

            foreach ((new \ReflectionClass($input))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $name = $property->getName();
                foreach ($property->getAttributes(\Symfony\Component\Serializer\Attribute\SerializedName::class) as $attribute) {
                    $name = $attribute->newInstance()->getSerializedName();
                }
                $serialized[] = $name;
            }

            self::assertSame(self::PINNED_ACCEPTED_FIELDS, $serialized, $input.' does not declare §4.2\'s accepted set.');
            self::assertNotContains('id', $serialized, 'B-3: '.$input.' declares an id.');
        }
    }

    /** API-19b — the declared parameter set is exactly §4.2's cell. */
    #[Test]
    public function listItemsDeclaresExactlyThePinnedQueryParameters(): void
    {
        $operation = TransactionBoundaryTest::contractOperations()['ListItems'];
        \assert($operation instanceof HttpOperation);

        $declared = [];
        foreach ($operation->getParameters() ?? [] as $key => $parameter) {
            $declared[] = (string) ($parameter->getKey() ?? $key);
        }

        self::assertSame(self::PINNED_QUERY_PARAMETERS, $declared);
    }

    // -----------------------------------------------------------------------
    // The same pins, against the document.
    // -----------------------------------------------------------------------

    #[Test]
    public function thePinsMatchTheContractDocument(): void
    {
        $this->requireDocument();

        $rows = ContractDocument::operationRows();
        self::assertGreaterThan(50, \count($rows), 'The §4.2 parser found almost nothing, so every comparison below would be vacuous.');

        // Both directions: §4.2's `/items` rows are exactly the pinned set. The
        // domain is chosen by the document's own `Path` column, so an operation
        // added to §4.2 for this resource and not implemented fails here.
        $documented = [];
        foreach ($rows as $operationId => $row) {
            if (1 === preg_match('#^/items(/|$)#', $row['path'])) {
                $documented[$operationId] = [
                    'method' => $row['method'],
                    'path' => $row['path'],
                    'idem' => 'yes' === $row['idem'],
                ];
            }
        }

        ksort($documented);
        $pinned = self::PINNED_ITEM_OPERATIONS;
        ksort($pinned);

        self::assertSame($pinned, $documented);
        self::assertSame(self::PINNED_ACCEPTED_FIELDS, ContractDocument::acceptedFields('CreateItem'));
        self::assertSame(self::PINNED_ACCEPTED_FIELDS, ContractDocument::acceptedFields('UpdateItem'));
        self::assertSame(self::PINNED_QUERY_PARAMETERS, ContractDocument::queryParameters('ListItems'));
    }

    /**
     * §12.3 — `operationId = Verb ++ Resource`, derived from the row's own two
     * columns rather than compared against a list, and `List` ⇒ plural.
     */
    #[Test]
    public function everyOperationIdIsItsOwnVerbAndResource(): void
    {
        $this->requireDocument();

        $rows = ContractDocument::operationRows();

        foreach (array_keys(self::PINNED_ITEM_OPERATIONS) as $operationId) {
            self::assertArrayHasKey($operationId, $rows);

            [$verb, $resource] = array_map(trim(...), explode('/', $rows[$operationId]['verbResource'], 2));
            self::assertSame($verb.$resource, $operationId);

            if ('List' === $verb) {
                self::assertStringEndsWith('s', $resource, 'A collection operation names a singular resource.');
            } else {
                self::assertStringEndsNotWith('s', $resource, 'A non-collection operation names a plural resource.');
            }
        }
    }

    /**
     * API-12 / API-12a — the `DELETE` and the `Restore*` exist because
     * `SchemaManifest` says `inventory_item` is class `soft`, and the manifest
     * is the source, not this test.
     */
    #[Test]
    public function theDeleteAndRestorePairFollowsTheDeclaredDeletionClass(): void
    {
        self::assertSame(
            \App\Tenancy\SchemaManifest::SOFT,
            \App\Tenancy\SchemaManifest::TABLES['inventory_item']['deletion'],
        );

        $declared = array_keys(TransactionBoundaryTest::contractOperations());
        self::assertContains('DeleteItem', $declared);
        self::assertContains('RestoreItem', $declared);
    }

    private function requireDocument(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout, so the pins above are checked against the code only. See the phase-2 report.');
        }
    }
}
