<?php

declare(strict_types=1);

namespace App\Platform\Persistence\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * Postgres emits timestamptz with microsecond precision; DBAL's built-in
 * datetimetz_immutable parses strictly against 'Y-m-d H:i:sO' and rejects it.
 *
 * The mismatch only shows up on rows written outside the ORM -- a column
 * DEFAULT now(), a worker's raw INSERT, a data migration -- which is exactly the
 * traffic a multi-tenant ERP has plenty of, so it is not something to dodge by
 * dropping the defaults.
 *
 * Registered as an override of the built-in type in config/packages/doctrine.yaml,
 * so every entity gets it without having to remember.
 */
final class TimestampTzImmutableType extends DateTimeTzImmutableType
{
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $dateTime = \DateTimeImmutable::createFromFormat($platform->getDateTimeTzFormatString(), (string) $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.uO', (string) $value);

        if (false !== $dateTime) {
            return $dateTime;
        }

        throw InvalidFormat::new((string) $value, static::class, $platform->getDateTimeTzFormatString());
    }
}
