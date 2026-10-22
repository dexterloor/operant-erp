<?php

declare(strict_types=1);

namespace App\Tests\Platform\Http;

use App\Platform\Http\Export\CsvCell;
use App\Platform\Http\Export\CsvExportRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **The export's cell rule (G6-C0; design §6.6 + Response H-3; final RED L-9): formula escaping, then RFC 4180 quoting.**
 *
 * The exemption is by what the cell IS — a plain signed decimal, `/^-?\d{1,13}(\.\d{1,6})?\z/` — never by a column type
 * list, so no column can be "forgotten". Mutations (each run, see `docs/session13/g6-c0-progress.md`): widen the exemption to
 * `^-?[\d.+]+$` → `-2+3` stays bare → red; `$` for `\z` → `"-1\n"` exempt → red; drop TAB from the triggers → red; escape
 * decimals (no exemption) → `-2.000000` gains `'` → red.
 */
final class ExportEscapingTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cells(): iterable
    {
        // Escaped: every trigger, and every value starting with one that is not wholly a plain number.
        yield 'a formula' => ['=HYPERLINK("x","y")', '\'=HYPERLINK("x","y")'];
        yield 'plus' => ['+44 20 7946 0000', '\'+44 20 7946 0000'];
        yield 'minus arithmetic' => ['-2+3', '\'-2+3'];
        yield 'minus function' => ['-SUM(A1:A2)', '\'-SUM(A1:A2)'];
        yield 'at' => ['@cmd', '\'@cmd'];
        yield 'tab' => ["\tTabbed", "'\tTabbed"];
        yield 'carriage return' => ["\rReturned", "'\rReturned"];
        yield 'line feed (L-9 belt and braces)' => ["\nFed", "'\nFed"];
        yield 'a number with a trailing newline is not a plain number (\z, not $)' => ["-1\n", "'-1\n"];
        yield 'fourteen integer digits are past numeric(19,6)' => ['-12345678901234', '\'-12345678901234'];
        yield 'seven fractional digits are past numeric(19,6)' => ['-1.1234567', '\'-1.1234567'];
        yield 'a non-ASCII digit is not a plain number (no u flag)' => ['-٣', '\'-٣'];
        yield 'a minus with nothing after it' => ['-', '\'-'];
        yield 'equals one' => ['=1', '\'=1'];
        // Bare: plain numbers, including negatives, and text that starts with no trigger.
        yield 'a negative quantity' => ['-2.000000', '-2.000000'];
        yield 'a negative integer' => ['-5', '-5'];
        yield 'the widest plain number' => ['-1234567890123.123456', '-1234567890123.123456'];
        yield 'a positive decimal' => ['12.500000', '12.500000'];
        yield 'plain text' => ['Widget', 'Widget'];
        yield 'a formula sign later in the text' => ['A=1', 'A=1'];
        yield 'empty' => ['', ''];
        yield 'a quote and an equals are not a formula start' => ['\'=already', '\'=already'];
    }

    #[Test]
    #[DataProvider('cells')]
    public function aCellIsEscapedIffItStartsWithATriggerAndIsNotAPlainNumber(string $text, string $escaped): void
    {
        self::assertSame($escaped, CsvCell::escape($text));
    }

    #[Test]
    public function rfc4180Quoting(): void
    {
        self::assertSame('plain', CsvCell::quote('plain'));
        self::assertSame('"a,b"', CsvCell::quote('a,b'));
        self::assertSame('"say ""hi"""', CsvCell::quote('say "hi"'));
        self::assertSame("\"two\r\nlines\"", CsvCell::quote("two\r\nlines"));
        self::assertSame("\"lf\nonly\"", CsvCell::quote("lf\nonly"));
        self::assertSame("\"cr\ronly\"", CsvCell::quote("cr\ronly"));
        // The escape goes INSIDE the quotes.
        self::assertSame("\"'=1,2\"", CsvCell::render('=1,2'));
        self::assertSame("\"'\rx\"", CsvCell::render("\rx"));
    }

    /** Final RED L-9: a formula after an embedded newline stays inside its one quoted cell, and the row round-trips. */
    #[Test]
    public function anEmbeddedNewlineStaysInsideOneCell(): void
    {
        $line = CsvExportRenderer::line(['a"b'."\r\n".'=1', 'next']);

        self::assertSame("\"a\"\"b\r\n=1\",next\r\n", $line);

        $handle = fopen('php://memory', 'w+b');
        \assert(false !== $handle);
        fwrite($handle, $line);
        rewind($handle);
        self::assertSame(['a"b'."\r\n".'=1', 'next'], fgetcsv($handle, null, ',', '"', ''));
        self::assertFalse(fgetcsv($handle, null, ',', '"', ''), 'The embedded newline split the record.');
    }

    /** Final RED M-5: a leaf whose key is absent renders as an empty cell, exactly as null; a null reference empties its leaves. */
    #[Test]
    public function anAbsentKeyAndANullAreBothAnEmptyCell(): void
    {
        $row = ['id' => 'x', 'flag' => null, 'supplier' => null, 'nested' => ['a' => true, 'b' => false, 'n' => 3, 'list' => ['p', 'q']]];

        self::assertSame('x', CsvExportRenderer::cell($row, 'id'));
        self::assertSame('', CsvExportRenderer::cell($row, 'flag'));
        self::assertSame('', CsvExportRenderer::cell($row, 'missing'));
        self::assertSame('', CsvExportRenderer::cell($row, 'supplier.id'));
        self::assertSame('', CsvExportRenderer::cell($row, 'nested.missing'));
        self::assertSame('true', CsvExportRenderer::cell($row, 'nested.a'));
        self::assertSame('false', CsvExportRenderer::cell($row, 'nested.b'));
        self::assertSame('3', CsvExportRenderer::cell($row, 'nested.n'));
        self::assertSame('["p","q"]', CsvExportRenderer::cell($row, 'nested.list'));
    }
}
