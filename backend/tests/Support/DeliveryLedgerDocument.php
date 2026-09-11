<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * `docs/DELIVERY_LEDGER.md`'s entry- and exit-condition gates, read as data.
 *
 * The third gitignored-document reader, after {@see ContractDocument} and
 * {@see SecurityModelDocument}, and it exists for one reason:
 * **`API.md` §4.2 can now mark an operation `⛔ none` — *declared idempotent, no
 * mechanism exists* — and a cell that says "this is unsafe" is worth nothing
 * unless something refuses to let it sit there quietly.**
 *
 * `IdempotencyContractTest::everyBlockedOperationIsNamedByAGate` is that
 * refusal: every `⛔ none` operation must be named, **by name**, in the E9-2 row
 * of this file. So the vocabulary is self-declaring — an operation cannot be
 * marked unprotected without a gate somewhere saying it is blocked, and a gate
 * cannot quietly stop naming one.
 *
 * ---------------------------------------------------------------------------
 * Why by name rather than by category
 * ---------------------------------------------------------------------------
 * E9-2 originally read *"§4.2's six create-a-child or three bulk operations"* —
 * a **category**, and that is exactly how four operations with no refusing
 * mechanism fell outside it. `CreatePurchaseOrder` is neither create-a-child nor
 * bulk, so the clause did not reach it, and nothing anywhere noticed. A category
 * cannot be reconciled against a set; a list of names can. The row now names
 * every blocked operation and this reader is what holds it to that.
 *
 * ---------------------------------------------------------------------------
 * The availability caveat, which is the same one the other two carry
 * ---------------------------------------------------------------------------
 * `docs/` is gitignored, so on a clean checkout there is nothing to read and the
 * check that uses this skips. `SkipInvariantTest` derives its set of document
 * classes as *"every class declaring a `function isAvailable`"* precisely so
 * that a third reader is covered without that file being edited — so the skip
 * below is a legitimate contract-document skip and is recognised as one.
 */
final class DeliveryLedgerDocument
{
    public static function path(): string
    {
        return \dirname(__DIR__, 3).'/docs/DELIVERY_LEDGER.md';
    }

    public static function isAvailable(): bool
    {
        return is_file(self::path());
    }

    /**
     * The gate row for one condition code — `| **E9-2** | …` — as its raw
     * markdown.
     *
     * Located by its own first cell, on `SecurityModelDocument::enforcementRow()`'s
     * pattern, so a row that moves in the file is still found and a code that no
     * longer exists returns the empty string rather than the wrong row. **The
     * caller must treat the empty string as a failure**, never as "nothing to
     * check": a gate that has been deleted is the one case this must not pass.
     */
    public static function gateRow(string $code): string
    {
        $needle = '| **'.$code.'** |';

        foreach (explode("\n", (string) file_get_contents(self::path())) as $line) {
            if (str_starts_with($line, $needle)) {
                return $line;
            }
        }

        return '';
    }

    /**
     * The operation names a gate row blocks, as backticked tokens in `Verb ++
     * Resource` form.
     *
     * The row is prose with the names embedded in it, so they are extracted
     * rather than positioned: any `` `CreateSomething` `` token is an operation
     * this gate names. Matching is deliberately restricted to the operationId
     * shape §12.3 declares — an initial capital followed by letters — so that
     * the table names, column names and index names the same row cites
     * (`purchase_order`, `order_number`, `Version20260910000200`) are not read as
     * operations.
     *
     * @return list<string>
     */
    public static function operationsNamedBy(string $code): array
    {
        preg_match_all('/`([A-Z][A-Za-z]+)`/', self::gateRow($code), $matches);

        return array_values(array_unique($matches[1]));
    }
}
