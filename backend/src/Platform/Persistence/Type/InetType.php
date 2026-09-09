<?php

declare(strict_types=1);

namespace App\Platform\Persistence\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Postgres `inet`, as a first-class DBAL type.
 *
 * `audit_log.ip_address` records the address an event was observed from, and
 * ADR-006 §6.4(b) is the reason it is not a string: a per-address rate limit is
 * no limit at all over IPv6, so the questions asked of this column are network
 * questions -- "everything from this /64" -- and `inet` answers them with
 * containment operators while refusing to store a value that is not an address.
 *
 * Without a registered type the column can still be introspected (see
 * doctrine.yaml's mapping_types), but the ORM would then declare VARCHAR(255)
 * and every schema comparison would propose to rewrite the column. Registering
 * the type is what makes the mapping and the catalogue agree.
 *
 * Values stay PHP strings deliberately: an IP address value object belongs in
 * SharedKernel (Output 5), not in the type layer.
 */
final class InetType extends Type
{
    public const NAME = 'inet';

    public function getName(): string
    {
        return self::NAME;
    }

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'INET';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : (string) $value;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
