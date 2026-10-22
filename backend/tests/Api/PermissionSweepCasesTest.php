<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Platform\Security\PermissionGate;
use App\Tests\Support\PermissionSweepCases;
use App\Tests\Support\PlantedDerivedPermission;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **The sweeps' derived arm, planted (G5-D0, final RED L-4).** No shipped operation is derived until G5-C2, so the four
 * HTTP sweeps cannot reach their DERIVED case; this is where it is shown to expand to EVERY key, byte-sorted, each with its
 * own resolved code and a body naming it -- and the ALL_OF and NONE cases to be exactly what the sweeps always sent.
 */
final class PermissionSweepCasesTest extends TestCase
{
    #[Test]
    public function aDerivedOperationIsDrivenOverEveryKeyInByteOrder(): void
    {
        $requirements = PlantedDerivedPermission::requirements(new PlantedDerivedPermission([
            'supplier' => 'supplier.delete', 'credit_note' => 'invoice.view', 'customer' => 'customer.delete',
        ]));
        $operation = new Post(name: 'PlantedBulk', extraProperties: [
            PermissionGate::OPERATION_PERMISSION => PermissionGate::DERIVED,
            PermissionGate::DERIVED_PERMISSION => 'app.planted_derived_permission',
        ]);

        self::assertSame([
            ['label' => 'PlantedBulk {resource: credit_note}', 'required' => ['invoice.view'], 'body' => ['resource' => 'credit_note'], 'resource' => 'credit_note'],
            ['label' => 'PlantedBulk {resource: customer}', 'required' => ['customer.delete'], 'body' => ['resource' => 'customer'], 'resource' => 'customer'],
            ['label' => 'PlantedBulk {resource: supplier}', 'required' => ['supplier.delete'], 'body' => ['resource' => 'supplier'], 'resource' => 'supplier'],
        ], PermissionSweepCases::for($operation, $requirements));
    }

    #[Test]
    public function staticAndUndeclaredCasesAreWhatTheSweepsAlwaysSent(): void
    {
        $requirements = PlantedDerivedPermission::requirements();

        self::assertSame([], PermissionSweepCases::for(new Get(name: 'N', extraProperties: [PermissionGate::OPERATION_PERMISSION => null]), $requirements));
        self::assertSame([['label' => 'G', 'required' => ['a.view'], 'body' => null, 'resource' => null]],
            PermissionSweepCases::for(new Get(name: 'G', extraProperties: [PermissionGate::OPERATION_PERMISSION => 'a.view']), $requirements));
        self::assertSame([['label' => 'P', 'required' => ['a.edit', 'b.view'], 'body' => [], 'resource' => null]],
            PermissionSweepCases::for(new Post(name: 'P', extraProperties: [PermissionGate::OPERATION_PERMISSION => ['a.edit', 'b.view']]), $requirements));

        $this->expectException(\LogicException::class);
        PermissionSweepCases::for(new Post(name: 'D', extraProperties: [
            PermissionGate::OPERATION_PERMISSION => PermissionGate::DERIVED,
            PermissionGate::DERIVED_PERMISSION => 'app.unwired',
        ]), $requirements);
    }
}
