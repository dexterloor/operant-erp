<?php

declare(strict_types=1);

namespace App\Platform\Http;

/**
 * Marks a property as one instant, so the published spec says `format: date-time` (BK-18 R18-f + R18-g).
 *
 * - A **response member** of a served resource carries plain `#[Instant]`: {@see InstantMembers} adds the format.
 * - A **request field** of an `input:` class carries `#[Instant(inRequest: true)]`: it also gains the description of the
 *   shape the server accepts, {@see Rfc3339Timestamp::PROFILE}, unless it has a hand description.
 * - A **hand array schema** (a nested view written out in a resource's `#[ApiProperty(schema: …)]`) uses
 *   {@see self::SCHEMA} for its instant members; no other `'date-time'` literal may appear in `src/`
 *   (`InstantMembersTest`).
 *
 * The marker is description only: nothing reads `format` from a body schema to accept or refuse a request. Request
 * timestamps are refused by the writers through {@see Rfc3339Timestamp::parse()}; responses are formatted by the readers
 * with `RFC3339_EXTENDED` (or `RFC3339`), which that parser accepts.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Instant
{
    public const FORMAT = 'date-time';

    /** The schema of one instant in a hand array schema. */
    public const SCHEMA = ['type' => 'string', 'format' => self::FORMAT];

    public function __construct(public readonly bool $inRequest = false)
    {
    }
}
