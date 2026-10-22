<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * **One admissible resource of `CreateBulkArchival`** (G5-C2, 2026-10-22; `g5-g6-bulk-export-design.md` §5.0/§5.2, D-12).
 *
 * Implemented in the resource's own module — in its `ApiResource\State` beside the single `Delete*` processor, the
 * `ExportSource` precedent (design §9 said `Application`; the member must name its Delete operation's resource class, an
 * ApiResource: `g5-c2-progress.md` D-2, `ARCH` to confirm) — so Platform names no module: the `ExportSource` inversion. Tagged by the interface itself, so implementing it IS the wiring; {@see BulkArchiveMembers} keys them by
 * {@see self::resource()} and `BulkAdmissibleSetTest` holds that key set equal, both ways, to the set DERIVED from the
 * operation registry (a built `Delete*` and `Restore*` at `<resource>.delete`, and a `List*` declaring `archived`, less
 * {@see BulkExclusions::RULED}). A member is never listed by hand anywhere else.
 *
 * **Parity by construction (D-12):** {@see self::archive()} runs EXACTLY the method the single `Delete*` operation's
 * processor runs — one copy of every refusal rule. The single writers call `TenantContext::run()`, which JOINS the bulk's
 * transaction; the coordinator wraps each call in its own savepoint, so a refused member leaves nothing behind. The one
 * deliberate divergence is the item's stock refusal (G-51, D-13), and it lives in that member, not in the coordinator.
 *
 * Contract, checked where it can be:
 *  - {@see self::archive()} refuses by throwing `ApiProblem` 404 (`not-found`) or 409 `business-rule-violated` — the only
 *    two kinds the coordinator counts as "not applied" (design L-3); anything else is a 500 and rolls the request back.
 *  - It issues DBAL statements only, never an ORM change (final RED L-6: `BulkMemberWritersAreDbalOnlyTest` scans the
 *    delegated writer method, and the coordinator asserts the unit of work is clean after every member).
 *  - {@see self::lockOrder()} returns the ids in the order their locks must be taken (§5.3). Most members: ascending id.
 *    `storage_location`: `(warehouse_id, id)`, so each member's warehouse share-lock is taken in ascending parent order.
 */
#[AutoconfigureTag(self::TAG)]
interface BulkArchiveMember
{
    public const TAG = 'app.bulk_archive_member';

    /** The table name, which is also the prefix of its `.delete` code (D-19): `inventory_item`, `storage_location`, … */
    public function resource(): string;

    /** @return class-string the API resource class declaring the single `Delete*` operation */
    public function deleteResourceClass(): string;

    /** The single `Delete*` operation's name (= its `operationId`), whose declared code the bulk demands. */
    public function deleteOperationName(): string;

    /**
     * The ids in lock order. Ids arrive lower-case, distinct and byte-sorted; a member may read (never lock) to order them,
     * and must return exactly the ids it was given. Runs inside the bulk's tenancy transaction.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function lockOrder(array $ids): array;

    /** The single `Delete*`'s own writer method for one id. Throws `ApiProblem` (404 / 409) to refuse. */
    public function archive(string $id): void;
}
