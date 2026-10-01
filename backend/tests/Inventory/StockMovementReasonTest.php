<?php

declare(strict_types=1);

namespace App\Tests\Inventory;

use App\Modules\Inventory\Infrastructure\Entity\InventoryItem;
use App\Modules\Inventory\Infrastructure\Entity\StockMovement;
use App\Modules\Warehouses\Infrastructure\Entity\StorageLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * **`SECURITY_MODEL.md` E-26's entity half at the constructor** (E-27's refusal of
 * `return` lived here too until it lifted with `CreateSalesReturn`, 2026-10-01) — CD-2 closing work item 2 (*"bind `reason` … at the write path,
 * not only on the entity"*) read from the other side: the write-path half is
 * `WriteOwnershipTest::everyStockMovementReasonIsRegisteredToItsWriterAndPermission`,
 * and this is the entity half, because the two cover disjoint paths — the
 * shipped writers are raw SQL and never construct the entity, and a literal scan
 * is blind to an ORM writer.
 *
 * The accepted set is read off the entity's own `REASON_*` constants by
 * reflection, never listed here, so a reason added to the class without being
 * added to the constructor's list goes red below. (The registry test holds those
 * constants equal to the live CHECK.)
 */
final class StockMovementReasonTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function reasonsTheEntityDeclares(): iterable
    {
        foreach ((new \ReflectionClass(StockMovement::class))->getReflectionConstants() as $constant) {
            if (str_starts_with($constant->getName(), 'REASON_') && \is_string($value = $constant->getValue())) {
                yield $value => [$value];
            }
        }
    }

    /**
     * **Every declared reason is accepted** — `return` included since 2026-10-01 (sales step 7 R2), when E-27's
     * refusal lifted in the one commit it named: the one that built `CreateSalesReturn`. Rewritten from
     * `everyDeclaredReasonButReturnIsAcceptedAndReturnIsRefused` in that commit (design §9, `RED` M-8). Who may POST a
     * reason is the write-path half's question (`WriteOwnershipTest`: `return` → `StockReturner` → `sales_return.create`).
     */
    #[Test]
    #[DataProvider('reasonsTheEntityDeclares')]
    public function everyDeclaredReasonIsAccepted(string $reason): void
    {
        self::assertSame($reason, $this->movement($reason)->getReason());
    }

    #[Test]
    public function aReasonTheEntityDoesNotDeclareIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"bogus" is not a stock movement reason.');

        $this->movement('bogus');
    }

    /**
     * The provider above is the domain; it must not be empty, or both tests prove nothing. Anti-vacuity by a
     * known member rather than by a count -- the count is the live CHECK's, and the registry test holds it.
     */
    #[Test]
    public function theDomainIsReadAtAll(): void
    {
        self::assertArrayHasKey(StockMovement::REASON_RECEIPT, iterator_to_array(self::reasonsTheEntityDeclares()),
            'Reflection over StockMovement found no REASON_RECEIPT, so the reason domain above is not being read.');
        self::assertArrayHasKey(StockMovement::REASON_RETURN, iterator_to_array(self::reasonsTheEntityDeclares()),
            'The reason E-27 refused until 2026-10-01 is not in the domain, so its acceptance above is never exercised.');
    }

    private function movement(string $reason): StockMovement
    {
        $item = (new \ReflectionClass(InventoryItem::class))->newInstanceWithoutConstructor();
        $location = (new \ReflectionClass(StorageLocation::class))->newInstanceWithoutConstructor();

        return new StockMovement(Uuid::v4(), Uuid::v4(), $item, $location, '1', $reason);
    }
}
