<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Inventory\Infrastructure\Entity\InventoryItem;
use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Bulk\BulkArchiveMember;
use App\Platform\Http\Bulk\BulkArchiveMembers;
use App\Platform\Http\Idempotency\IdempotencyEnvelopeStore;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`BulkArchival`'s own rules, on PLANTED members** (G5-C2; design L-3, final RED L-3 / L-6). The shipped members are DBAL
 * only and refuse only with 404 / 409, so these branches are unreachable through them — which is exactly why each is proven
 * here, with the real coordinator, the real tenancy transaction and the real store, against a member that misbehaves:
 *
 *  - a member that leaves an ORM change scheduled and then refuses → LogicException (500), nothing persisted (the runtime
 *    guard; *mutation:* remove it → the end-of-`run()` flush archives the item → red);
 *  - a member throwing anything else (a bug, a non-allow-listed problem) → rethrown, the WHOLE request rolled back, nothing
 *    stored (*mutation:* catch `\Throwable` as "not applied" → red);
 *  - a `40P01` inside a member → 409 `bulk.contention`, never "not applied", the earlier member's write undone, nothing stored
 *    (*mutation:* catch it per member → red);
 *  - a real `55P03` (`lock_timeout` set locally — none is set anywhere at run time, so this is the only way it can redden).
 */
final class BulkArchivalCoordinatorTest extends PurchaseOrderTestCase
{
    #[Test]
    public function aMemberLeavingAnOrmChangeScheduledIsAServerErrorAndPersistsNothing(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'BLK-ORM');
        $em = $this->em();
        $plant = $this->member(function (string $id) use ($item, $em): void {
            $entity = $em->find(InventoryItem::class, Uuid::fromString($item));
            \assert($entity instanceof InventoryItem);
            $entity->delete();

            throw ApiProblem::notFound();
        });

        try {
            $this->coordinator($plant)->run('planted_thing', [Uuid::v4()->toRfc4122()], Uuid::v4()->toRfc4122(), (string) $this->actorId);
            self::fail('A scheduled ORM change survived a refused member.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('left an ORM change scheduled', $e->getMessage());
        }

        $this->em()->clear();
        self::assertNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at FROM inventory_item WHERE id = ?::uuid', [$item])[0]['deleted_at']);
        self::assertSame(0, $this->envelopes());
    }

    /**
     * A refused member's statements are undone by `ROLLBACK TO SAVEPOINT bulk_member` even when it wrote before refusing
     * (the shipped members refuse before writing, so this is unreachable through them; mutation M1 was GREEN over HTTP).
     * The planted member archives a real customer and THEN refuses; the request still commits (another member applies).
     */
    #[Test]
    public function aRefusedMembersEarlierWriteIsRolledBackToItsSavepoint(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Cus WTR');
        $other = $this->seedCustomer(self::TENANT_A, 'Cus WTR2');
        $plant = $this->member(function (string $id) use ($customer): void {
            $this->customers()->delete($id);
            if ($id === $customer) {
                throw ApiProblem::businessRuleViolated('planted.refusal', 'Refused after writing.');
            }
        });

        $replay = $this->coordinator($plant)->run('planted_thing', [$customer, $other], Uuid::v4()->toRfc4122(), (string) $this->actorId);

        self::assertSame('{"requested":2,"applied":1}', $replay->body);
        self::assertNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at FROM customer WHERE id = ?::uuid', [$customer])[0]['deleted_at'], 'The refused member\'s write stood.');
        self::assertNotNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at FROM customer WHERE id = ?::uuid', [$other])[0]['deleted_at']);
        self::assertSame([$other], array_column($this->auditRowsOf('customer.deleted'), 'subject_id'), 'The refused member\'s audit row stood.');
    }

    /**
     * G5-C3: {@see BulkArchival::restore()} runs the SAME engine, each member through `restore()` (never `archive()`), the
     * envelope under `CreateBulkRestoration` — a refusal after a write is rolled back to its savepoint there too.
     * *Mutation:* `restore()` passes the archival's apply → the planted archive throws → red.
     */
    #[Test]
    public function theRestorationRoutesEachMemberThroughItsRestoreUnderItsOwnOperation(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Cus RRT');
        $other = $this->seedCustomer(self::TENANT_A, 'Cus RRT2');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id IN (?::uuid, ?::uuid)', [$customer, $other]);
        $plant = $this->member(
            static fn (string $id): never => throw new \LogicException('The restoration ran a member\'s archive.'),
            function (string $id) use ($customer): void {
                $this->customers()->restore($id);
                if ($id === $customer) {
                    throw ApiProblem::businessRuleViolated('planted.refusal', 'Refused after writing.');
                }
            },
        );
        $key = Uuid::v4()->toRfc4122();

        $replay = $this->coordinator($plant)->restore('planted_thing', [$customer, $other], $key, (string) $this->actorId);

        self::assertSame('{"requested":2,"applied":1}', $replay->body);
        self::assertNotNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at FROM customer WHERE id = ?::uuid', [$customer])[0]['deleted_at'], 'The refused member\'s restore stood.');
        self::assertNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at FROM customer WHERE id = ?::uuid', [$other])[0]['deleted_at']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM idempotency_key WHERE operation_id = ?', [BulkArchival::RESTORATION_OPERATION]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM idempotency_key WHERE operation_id = ?', [BulkArchival::OPERATION]));
    }

    #[Test]
    public function anUnknownBugIsAServerErrorNotNotApplied(): void
    {
        foreach ([new \RuntimeException('planted bug'), ApiProblem::validationFailed([['pointer' => '', 'code' => 'x', 'message' => 'x']])] as $bug) {
            $customer = $this->seedCustomer(self::TENANT_A, 'Cus BUG '.$bug::class);
            [$first, $second] = $this->sortedPair($customer);
            $plant = $this->member(function (string $id) use ($customer, $bug): void {
                if ($id === $customer) {
                    $this->customers()->delete($customer);

                    return;
                }

                throw $bug;
            });

            try {
                $this->coordinator($plant)->run('planted_thing', [$first, $second], Uuid::v4()->toRfc4122(), (string) $this->actorId);
                self::fail('A member\'s bug was counted as "not applied".');
            } catch (\Throwable $e) {
                self::assertSame($bug, $e);
            }

            self::assertNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at FROM customer WHERE id = ?::uuid', [$customer])[0]['deleted_at'],
                'The whole request must roll back.');
            self::assertSame(0, $this->envelopes());
        }
    }

    #[Test]
    public function aDeadlockInsideAMemberIsContentionAndUndoesEverything(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Cus DL');
        [$first, $second] = $this->sortedPair($customer);
        $plant = $this->member(function (string $id) use ($customer): void {
            if ($id === $customer) {
                $this->customers()->delete($customer);

                return;
            }

            throw new DeadlockException(new class('deadlock detected', '40P01') extends AbstractException {}, null);
        });

        $this->assertContention(fn () => $this->coordinator($plant)->run('planted_thing', [$first, $second], Uuid::v4()->toRfc4122(), (string) $this->actorId));
        self::assertNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at FROM customer WHERE id = ?::uuid', [$customer])[0]['deleted_at']);
        self::assertSame(0, $this->envelopes());
    }

    /** A real `55P03`: another connection holds the customer `FOR UPDATE`; the member sets a local `lock_timeout` and waits. */
    #[Test]
    public function aLockTimeoutIsContention(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Cus LT');
        $holder = $this->freshUnpooledClient();
        $holder->beginTransaction();
        $holder->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [self::TENANT_A]);
        $holder->executeStatement('SELECT 1 FROM customer WHERE id = ?::uuid FOR UPDATE', [$customer]);

        $em = $this->em();
        $plant = $this->member(function (string $id) use ($em): void {
            $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '200ms'");
            $this->customers()->delete($id);
        });

        try {
            $this->assertContention(fn () => $this->coordinator($plant)->run('planted_thing', [$customer], Uuid::v4()->toRfc4122(), (string) $this->actorId));
        } finally {
            $holder->rollBack();
            $holder->close();
        }
        self::assertSame(0, $this->envelopes());
    }

    // -----------------------------------------------------------------------

    private function assertContention(callable $run): void
    {
        try {
            $run();
            self::fail('Contention was not translated.');
        } catch (ApiProblem $problem) {
            self::assertSame(409, $problem->getStatus());
            self::assertSame(BulkArchival::CONTENTION_RULE, $problem->getRule());
            self::assertSame(BulkArchival::CONTENTION_MESSAGE, $problem->getDetail());
        }
    }

    /** @return array{0: string, 1: string} the given id and a fresh one, byte-sorted (the coordinator's lock order) */
    private function sortedPair(string $id): array
    {
        $pair = [$id, Uuid::v4()->toRfc4122()];
        sort($pair, \SORT_STRING);

        // The real member must run FIRST, so its write is the one the failure has to undo.
        return $pair[0] === $id ? $pair : $this->sortedPair($id);
    }

    private function envelopes(): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM idempotency_key', []);
    }

    private function customers(): CustomerWriter
    {
        $writer = self::getContainer()->get(CustomerWriter::class);
        \assert($writer instanceof CustomerWriter);

        return $writer;
    }

    private function coordinator(BulkArchiveMember $member): BulkArchival
    {
        $container = self::getContainer();
        $tenants = $container->get(TenantContext::class);
        $tenant = $container->get(CurrentTenant::class);
        $store = $container->get(IdempotencyEnvelopeStore::class);
        \assert($tenants instanceof TenantContext && $tenant instanceof CurrentTenant && $store instanceof IdempotencyEnvelopeStore);

        return new BulkArchival($tenants, $tenant, $store, new BulkArchiveMembers([$member]));
    }

    /**
     * @param \Closure(string): void      $archive
     * @param (\Closure(string): void)|null $restore G5-C3: the restoration entry point routes here
     */
    private function member(\Closure $archive, ?\Closure $restore = null): BulkArchiveMember
    {
        return new class($archive, $restore) implements BulkArchiveMember {
            public function __construct(private readonly \Closure $archive, private readonly ?\Closure $restore)
            {
            }

            public function resource(): string
            {
                return 'planted_thing';
            }

            public function deleteResourceClass(): string
            {
                return self::class;
            }

            public function deleteOperationName(): string
            {
                return 'DeletePlantedThing';
            }

            public function lockOrder(array $ids): array
            {
                return $ids;
            }

            public function archive(string $id): void
            {
                ($this->archive)($id);
            }

            public function restoreOperationName(): string
            {
                return 'RestorePlantedThing';
            }

            public function restore(string $id): void
            {
                ($this->restore ?? throw new \LogicException('The restoration routed to a member with no restore.'))($id);
            }
        };
    }
}
