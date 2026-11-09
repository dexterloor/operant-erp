<?php

declare(strict_types=1);

namespace App\SharedKernel\Reference;

/**
 * **The units of measure an item may be counted in, as one fixed list** (BK-22, items batch C2).
 *
 * The `unit_of_measure` table is reference data owned here (`SchemaManifest`: `SharedKernel\Reference`), seeded once by
 * `Version20260906000100` with exactly these five codes and never written by the application (`erp_app` holds SELECT
 * only). The API serves this list as the `enum` of every item `unit_of_measure_code` — the read shape and both inputs —
 * so the web app's unit picker reads it from the generated surface and never holds a copy.
 *
 * The order is the seed's, and it is the order a picker shows (`UX` to confirm). The table, not this list, stays the
 * validator: `ItemWriter` still looks a submitted code up in `unit_of_measure` and refuses an unknown one
 * (`unknown_reference`). The two are held equal, read from the server, by `ItemUnitSchemaTest`, so a unit added to the
 * table without this list (or the other way round) is red in the same run.
 *
 * This is not G-37's served units read: the owner chose a fixed enum over a read operation (BK-22).
 */
final class UnitsOfMeasure
{
    /** @var list<string> */
    public const CODES = ['EA', 'BOX', 'KG', 'L', 'M'];
}
