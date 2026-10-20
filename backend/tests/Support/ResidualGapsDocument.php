<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * `docs/RESIDUAL_GAPS.md`'s entries, read as text — one more gitignored-document reader, and it exists for one entry
 * (audit reads C1, 2026-10-21; `docs/session13/audit-reads-owner-q-ruling.md` §5 C1, final RED L-6).
 *
 * **G-35 is rated Low only on conditions, and the rating is a sentence in this document.** Until C1, G-35's mechanism was a
 * pin that went red when the first audit read was built; C1 built it and converted the pin into an IMPLICATION
 * (`OwedAmendmentTripwireTest`): while `ListAuditLogEntries` is in the spec, the control test must exist AND G-35 must carry
 * the Low ruling line. The second half reads this file, because the ruling lives here and nowhere in `backend/`.
 *
 * **The availability caveat every reader carries:** `docs/` is gitignored, so on a clean checkout there is nothing to read
 * and the check that uses this skips. `SkipInvariantTest` derives its document classes as *"every class declaring a
 * `function isAvailable`"*, so this reader is covered without that file being edited.
 */
final class ResidualGapsDocument
{
    public static function path(): string
    {
        return \dirname(__DIR__, 3).'/docs/RESIDUAL_GAPS.md';
    }

    public static function isAvailable(): bool
    {
        return is_file(self::path());
    }

    /**
     * One entry — from its `## <id> ·` heading to the next `## ` heading — as raw markdown. **The empty string when no
     * entry carries that id: the caller must treat it as a failure**, never as "nothing to check" (an entry that was
     * deleted or renumbered is the one case this must not pass).
     */
    public static function entry(string $id): string
    {
        $lines = explode("\n", (string) file_get_contents(self::path()));
        $out = [];
        $inside = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                if ($inside) {
                    break;
                }
                $inside = str_starts_with($line, '## '.$id.' ');
            }
            if ($inside) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }
}
