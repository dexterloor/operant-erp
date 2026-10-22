<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Platform\Security\DerivedPermission;
use App\Platform\Security\PermissionRequirements;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * **A test-only derived-permission resolver (G5-D0).** No shipped operation is derived until G5-C2, so the gate's DERIVED
 * arm, the sweeps' DERIVED cases and the denial row are proven against this, through a `PermissionRequirements` built over
 * a hand-made locator ({@see self::requirements()}). It is never a container service: the real locator stays exactly the
 * set of resolvers some shipped operation names (`DerivedPermissionWiringTest`).
 */
final readonly class PlantedDerivedPermission implements DerivedPermission
{
    public const ID = 'app.planted_derived_permission';

    public const UNKNOWN = 'This kind of record is not planted.';

    /**
     * @param array<array-key, mixed> $map deliberately loose, so a contract violation can be planted
     */
    public function __construct(private array $map)
    {
    }

    /** A `PermissionRequirements` whose locator holds exactly these resolvers. */
    public static function requirements(DerivedPermission ...$resolvers): PermissionRequirements
    {
        $factories = [];
        foreach ($resolvers as $index => $resolver) {
            $factories[0 === $index ? self::ID : self::ID.'.'.$index] = static fn (): DerivedPermission => $resolver;
        }

        return new PermissionRequirements(new ServiceLocator($factories));
    }

    /** @return array<string, string> */
    public function codesByResource(): array
    {
        /** @var array<string, string> */
        return $this->map;
    }

    public function unknownResourceMessage(): string
    {
        return self::UNKNOWN;
    }
}
