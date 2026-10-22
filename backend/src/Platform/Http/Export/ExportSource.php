<?php

declare(strict_types=1);

namespace App\Platform\Http\Export;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * **One exportable list (G6-C0, `g5-g6-bulk-export-design.md` §6.1–§6.4; `g5-g6-final-red.md` M-5).**
 *
 * `CreateExport` renders "the same set the List returns" (TB-7) by asking the List's own module for it. A module that owns
 * a flat `List*` operation implements this interface beside that operation's provider; implementing it IS the wiring, as
 * {@see \App\Platform\Security\DerivedPermission} is for a resolver (the interface carries the tag). A Platform interface
 * implemented by modules, so Platform gains no edge to a module (A-1) and no module depends on the one that hosts the
 * export (A-5).
 *
 * What a source declares and what it does NOT:
 * - {@see self::resource()} — the List's primary table, the `resource` value a request names (D-19).
 * - {@see self::listResourceClass()} + {@see self::listOperationName()} — where the List operation is declared, so the
 *   export's permission is the List's DECLARED code (D-20; `credit_note` → `invoice.view` is not a concatenation), its
 *   `filters` members are the List's declared query parameters (API-14a) and its `sort` values the List's enum. A source
 *   declares none of those itself: they are read from the List operation, so the export cannot drift from the list.
 * - {@see self::rowClass()} — the class the List serializes each row as. The CSV header is derived from the serializer's
 *   metadata for that class (final RED M-5), never from a row and never from PHP reflection names.
 * - {@see self::prepare()} — parse `filters` (string values, exactly as the list's query string carries them) through THE
 *   SAME parse the List provider runs, and return the read to run. Parsing happens BEFORE the tenancy transaction opens (a
 *   refusal thrown inside `TenantContext::run()`'s own transaction closes the entity manager); a refusal is an
 *   `ApiProblem` whose pointers name query parameters (`?name`), and the export rewrites them to `/filters/name`.
 *   The returned read is called INSIDE the export's one tenancy transaction (the reader joins it), returns the List's row
 *   objects fully hydrated, and reports whether the view holds more than `$cap` rows — in the same statement (`LIMIT
 *   cap + 1`), under one snapshot (§6.4).
 */
#[AutoconfigureTag(self::TAG)]
interface ExportSource
{
    public const TAG = 'app.export_source';

    public function resource(): string;

    /** @return class-string */
    public function listResourceClass(): string;

    public function listOperationName(): string;

    /** @return class-string */
    public function rowClass(): string;

    /**
     * @param array<string, string> $filters the request's `filters`, already held to the List's declared members
     *
     * @return \Closure(): ExportedRows
     */
    public function prepare(array $filters, int $cap): \Closure;
}
