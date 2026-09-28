<?php

declare(strict_types=1);

namespace App\Platform\Persistence\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;

/**
 * **A `timestamptz` the ORM WRITES at microsecond precision — for a column a CHECK compares with a database-written
 * instant.** Sales step 6, C5 (2026-09-28), found by running `PayInvoice`, not by reading.
 *
 * DBAL's `datetimetz_immutable` (and `TimestampTzImmutableType`, which overrides only the READ side) formats a value
 * for the database with `'Y-m-d H:i:sO'`: **it drops the fraction of the second.** `invoice.issued_at` is written by
 * the database (`DEFAULT now()`, microseconds), and `invoice_paid_not_before_issue` compares `paid_at >= issued_at`.
 * So a payment recorded in the same second as the issue — the default, `now()` read from the database inside the
 * paying transaction, which IS after `issued_at` — was written truncated to the whole second, landed BEFORE
 * `issued_at`, and the CHECK answered 23514 as an HTTP 500. The same truncation turned a client's `paid_at` equal to
 * the invoice's own `issued_at` (as `GetInvoice` shows it, to the millisecond) into a 500. This is RED-3 F-1's defect
 * (two clocks disagreeing across a CHECK) arriving through the TYPE's precision rather than through a PHP clock.
 *
 * **Scoped to the column that needs it, by name, not overridden globally**: changing the global type would change
 * the stored precision of every ORM-written timestamp in shipped operations (and so what their reads return). The
 * SQL declaration is the parent's, so the schema comparator sees no difference (`DoctrineMappingTest`). The READ side is
 * `TimestampTzImmutableType`'s, repeated because that class is `final`.
 */
final class TimestampTzMicrosecondsImmutableType extends DateTimeTzImmutableType
{
    public const NAME = 'datetimetz_microseconds_immutable';

    /** `TimestampTzImmutableType::convertToPHPValue()`, verbatim: Postgres emits microseconds. */
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

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value->format('Y-m-d H:i:s.uO');
        }

        throw InvalidType::new($value, static::class, ['null', \DateTimeImmutable::class]);
    }
}
