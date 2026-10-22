<?php

declare(strict_types=1);

namespace App\Platform\Http\Export;

/**
 * **One CSV cell: formula escaping, then RFC 4180 quoting (G6-C0; design §6.6 + Response H-3; final RED L-9).**
 *
 * **Escaping (OWASP CSV injection).** A cell whose text starts with `=`, `+`, `-`, `@`, TAB (0x09), CR (0x0D) or LF (0x0A)
 * is prefixed with `'`, so a spreadsheet shows it as text and never evaluates it. LF is in the set as belt and braces (final
 * RED L-9: "consider adding LF"); a spreadsheet does not start a formula on it, so nothing a user sees changes (`UX`'s
 * notice text is unchanged).
 *
 * **The one exemption is by what the cell IS, never by a type list:** a cell whose WHOLE text is a plain signed decimal
 * within `numeric(19,6)`'s width — {@see self::PLAIN_NUMBER} — is not escaped. A wholly numeric value holds no operator,
 * function or reference, so nothing evaluates it: `-2.000000` (a stock movement out) stays `-2.000000`, while `-2+3`, `=1`
 * and `-SUM(A1)` do not match and are escaped. The pattern ends in `\z`, not `$` — in PHP `$` also matches before a final
 * newline, so `"-1\n"` would otherwise be exempt (L-9) — and carries no `u` flag, so `\d` is ASCII `0-9` only (an Arabic-
 * Indic or full-width digit is not a plain number, and such a cell is escaped if it starts with a trigger).
 *
 * The cost, recorded (Response H-3): a TEXT field whose value is wholly numeric (a code `-5`) exports bare and a spreadsheet
 * shows it as a number. Display only.
 *
 * **Quoting (RFC 4180).** A field is quoted iff it holds `"`, `,`, CR or LF; a `"` inside is doubled. A formula after an
 * embedded newline stays inside its quoted cell (`ExportCsvTest::anEmbeddedNewlineStaysInsideOneCell`).
 *
 * Escaping is not injective (a name that genuinely starts with `'=` exports as an escaped `=` does) — a recorded Low
 * residual for `SEC`, display only, together with full-width `＝` and a space before `=` (design §6.6).
 */
final class CsvCell
{
    /** A plain signed decimal: at most 13 integer and 6 fractional digits (`numeric(19,6)`). `\z`, ASCII digits. */
    public const PLAIN_NUMBER = '/^-?\d{1,13}(\.\d{1,6})?\z/';

    /** The characters a cell may not start with unless it is a plain number. */
    public const TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    public const ESCAPE_PREFIX = "'";

    public static function escape(string $text): string
    {
        if ('' === $text || !\in_array($text[0], self::TRIGGERS, true)) {
            return $text;
        }

        return 1 === preg_match(self::PLAIN_NUMBER, $text) ? $text : self::ESCAPE_PREFIX.$text;
    }

    public static function quote(string $text): string
    {
        if (strpbrk($text, "\",\r\n") === false) {
            return $text;
        }

        return '"'.str_replace('"', '""', $text).'"';
    }

    /** Escape, then quote: the order matters, because the `'` belongs inside the quotes. */
    public static function render(string $text): string
    {
        return self::quote(self::escape($text));
    }
}
