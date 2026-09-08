<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\Inventory\Application\ItemListCriteria;
use App\Inventory\Application\ItemReader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * P-7 and API-8 — the transaction does not span serialization.
 *
 * ADR-002 A1.7 measured what the alternative costs: 1505 ms of pooled-backend
 * pinning at `default_pool_size=1`. The rule is therefore not a preference, and
 * the two tests below are the two halves of it -- nothing is left holding the
 * connection, and nothing that would need it is left in the response.
 */
final class TransactionBoundaryTest extends ApiTestCase
{
    /**
     * The half that would fail loudly in production and silently in a fixture:
     * after the commit there is no tenant context, so a lazy load returns zero
     * rows to the proxy initializer and raises. A response built from scalars
     * cannot reach a database at all, and this is what proves it did not.
     */
    #[Test]
    public function serializingAResponseIssuesNoQueryAfterTheTransactionHasClosed(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $id = $this->seedItem(self::TENANT_A, 'SKU-1', $supplier);

        $reader = self::getContainer()->get(ItemReader::class);
        \assert($reader instanceof ItemReader);
        $normalizer = self::getContainer()->get('serializer');
        \assert($normalizer instanceof NormalizerInterface);

        $item = $reader->get($id);
        $collection = $reader->list(new ItemListCriteria(limit: 50));

        self::assertNotNull($item);
        self::assertNotSame([], $collection->items, 'Nothing was hydrated, so the assertion below would pass over an empty object.');

        // The instrument, proven live in the same window shape it is about to
        // be trusted in. Without this, an unwired recorder would report zero
        // statements and the assertion below would be the comfortable green
        // API-10a names: a check that passes because it has no subject.
        $this->recorder()->start();
        $this->app()->fetchOne('SELECT 1');
        $this->recorder()->stop();
        self::assertCount(1, $this->recorder()->statements(), 'The SQL recorder is not wired.');

        $this->recorder()->start();
        $normalized = [$normalizer->normalize($item, 'json'), $normalizer->normalize($collection, 'json')];
        $this->recorder()->stop();

        self::assertSame([], $this->recorder()->statements(), 'Serialization reached the database.');
        self::assertSame('Acme', $normalized[0]['supplier']['name'], 'The expansion was not hydrated inside the transaction.');
    }

    /** Nothing is left holding the pooled backend when the reader returns. */
    #[Test]
    public function theTenancyTransactionIsClosedBeforeTheReaderReturns(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');

        $reader = self::getContainer()->get(ItemReader::class);
        \assert($reader instanceof ItemReader);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $reader->list(new ItemListCriteria(limit: 50));

        self::assertSame(0, $em->getConnection()->getTransactionNestingLevel());
    }

    /**
     * API-8 — no response is produced by serializing a Doctrine entity graph.
     *
     * The domain is the operation registry rather than a list of classes: an
     * operation added tomorrow is covered without anybody remembering to add it
     * here, which is the property that makes this worth writing at all.
     */
    #[Test]
    public function noOperationReturnsADoctrineEntity(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $metadata = $em->getMetadataFactory();

        $offenders = [];
        $examined = 0;

        foreach (self::contractOperations() as $name => $operation) {
            ++$examined;

            $output = $operation->getOutput()['class'] ?? $operation->getClass();
            $input = $operation->getInput()['class'] ?? null;

            foreach (['output' => $output, 'input' => $input] as $role => $class) {
                if (\is_string($class) && !$metadata->isTransient($class)) {
                    $offenders[] = $name.' '.$role.': '.$class;
                }
            }
        }

        self::assertGreaterThan(0, $examined, 'The registry produced no operations, so this test asserted nothing.');
        self::assertSame([], $offenders);
    }

    /**
     * Every operation this project declares, keyed by operation name. Vendor
     * error resources are excluded by namespace: they are response shapes API
     * Platform owns, not operations in §4.2's table.
     *
     * @return array<string, HttpOperation>
     */
    public static function contractOperations(): array
    {
        $names = self::getContainer()->get(ResourceNameCollectionFactoryInterface::class);
        $collections = self::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class);
        \assert($names instanceof ResourceNameCollectionFactoryInterface);
        \assert($collections instanceof ResourceMetadataCollectionFactoryInterface);

        $operations = [];

        foreach ($names->create() as $resourceClass) {
            if (!str_starts_with($resourceClass, 'App\\')) {
                continue;
            }

            foreach ($collections->create($resourceClass) as $resource) {
                foreach ($resource->getOperations() ?? [] as $name => $operation) {
                    if ($operation instanceof HttpOperation && !str_starts_with((string) $name, '_api_')) {
                        $operations[(string) $name] = $operation;
                    }
                }
            }
        }

        return $operations;
    }
}
