<?php

declare(strict_types=1);

namespace App\Tests\Platform\Http;

use App\Platform\Http\Pagination\TimestampSortKey;
use App\Tests\Tenancy\TenancyTestCase;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see TimestampSortKey}'s two-sided contract, held against the LIVE server rather than against a statement of what
 * Postgres accepts (shipped-defect fix, 2026-10-21 — the shipped check was a careful statement, and it was wrong about
 * the offset range).
 *
 * The HTTP side — that every timestamp-keyed list uses this check — is `CursorSortKeyContractTest`'s.
 */
final class TimestampSortKeyTest extends TenancyTestCase
{
    /**
     * **Everything the helper accepts, Postgres accepts** — over a generated corpus: every offset hour 00–99 of both
     * signs, the minute/second forms around the `±15:59:59` limit, every date/time field at and past its edges, the
     * fraction lengths, and shapes that are not timestamps at all. Each value is cast with `?::timestamptz` on the
     * owner connection, the cast every reader issues.
     *
     * Controls inside: the corpus holds values on both sides — the helper accepts some, refuses some, and Postgres
     * refuses some the shipped regex accepted (`+16`), so the comparison cannot pass by refusing everything or by
     * Postgres accepting everything.
     */
    #[Test]
    public function everythingAcceptedIsAcceptedByPostgres(): void
    {
        $accepted = 0;
        $refusedByPostgres = 0;
        $wronglyAccepted = [];

        foreach (self::corpus() as $value) {
            $helper = TimestampSortKey::isValid($value);
            $postgres = $this->postgresAccepts($value);
            $accepted += (int) $helper;
            $refusedByPostgres += (int) !$postgres;
            if ($helper && !$postgres) {
                $wronglyAccepted[] = $value;
            }
        }

        self::assertGreaterThan(100, $accepted, 'The helper accepted almost nothing in the corpus, so the comparison is near-vacuous.');
        self::assertGreaterThan(100, $refusedByPostgres, 'Postgres refused almost nothing in the corpus, so it probes no edge.');
        self::assertFalse($this->postgresAccepts('2026-01-01 00:00:00+16'), 'Control: Postgres no longer refuses +16, so the corpus\'s premise changed.');
        self::assertSame([], $wronglyAccepted, 'The helper accepts a key Postgres refuses as timestamptz -- that key is a 500 on a list.');
    }

    /**
     * **Everything Postgres renders is accepted** — instants rendered by the server itself as `timestamptz::text` under
     * zones whose offsets are whole hours, half hours, the extremes (`+14`, `-12`), and a historical LMT offset with
     * seconds (`Europe/Amsterdam` in 1800: `+00:17:30`, measured), plus fractions and the 0001/9999 ends. A refusal here is a
     * REAL cursor answered 422.
     */
    #[Test]
    public function everythingPostgresRendersIsAccepted(): void
    {
        $instants = ['2026-10-21 12:34:56.789012+00', '2026-01-01 00:00:00+00', '2026-06-30 23:59:59.5+00', '1900-06-01 12:00:00+00', '1800-06-01 12:00:00+00',
            '0001-01-02 00:00:00+00', '9999-12-30 00:00:00+00', '2026-03-29 01:30:00.000001+00'];
        $zones = ['UTC', 'Asia/Kolkata', 'Pacific/Kiritimati', 'Etc/GMT+12', 'America/St_Johns', 'Europe/Amsterdam', 'Asia/Kathmandu'];

        $rendered = [];
        $connection = $this->owner();
        try {
            foreach ($zones as $zone) {
                $connection->executeStatement('SELECT set_config(\'TimeZone\', ?, false)', [$zone]);
                foreach ($instants as $instant) {
                    $rendered[] = (string) $connection->fetchOne('SELECT ?::timestamptz::text', [$instant]);
                }
            }
            $rendered[] = (string) $connection->fetchOne('SELECT clock_timestamp()::text');
        } finally {
            $connection->executeStatement('RESET TimeZone');
        }

        self::assertContains('1800-06-01 12:17:30+00:17:30', $rendered, 'Control: the LMT case did not render with seconds, so the seconds form went unprobed.');
        $refused = array_values(array_filter($rendered, static fn (string $value): bool => !TimestampSortKey::isValid($value)));
        self::assertSame([], $refused, 'Postgres rendered a timestamptz the helper refuses -- a real cursor would be answered 422.');
    }

    /**
     * **One check, not copies.** The shipped defect lived in eight hand copies of `isTimestamp()` and two lists that had
     * none; a tenth copy would be the same defect waiting. Scans `src/` for a private copy by name (`isTimestamp(`,
     * `date_parse(`) and for a hand-written time-of-day regex (`\d{2}:` / `\d{2}):` — the shape the proposal list's copy
     * was, a regex constant with no name). **It does not see** a check spelt another way (`strtotime`, `DateTime`
     * parsing, a regex written with `[0-9]`); the HTTP sweep is what proves each list USES the helper, this only stops
     * the two known shapes coming back.
     */
    #[Test]
    public function noProviderKeepsItsOwnTimestampCheck(): void
    {
        $root = \dirname(__DIR__, 3).'/src';
        $copies = [];
        $scanned = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            ++$scanned;
            $source = (string) file_get_contents($file->getPathname());
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if ('Platform/Http/Pagination/TimestampSortKey.php' === $relative) {
                continue;
            }
            if (1 === preg_match('/function\s+isTimestamp\s*\(/i', $source) || 1 === preg_match('/date_parse\s*\(/', $source)
                || str_contains($source, '\\d{2}:') || str_contains($source, '\\d{2}):')) {
                $copies[] = $relative;
            }
        }

        self::assertGreaterThan(100, $scanned, 'The scan read almost no source, so it proves nothing.');
        self::assertSame([], $copies, 'A provider carries its own timestamp check; use App\Platform\Http\Pagination\TimestampSortKey.');
    }

    /**
     * **The acceptance half rests on the server's DateStyle** (fresh RED M-1): a real cursor carries `timestamptz::text`,
     * and that is the ISO shape only under `DateStyle` ISO. Nothing in this project's config sets it; it is the server
     * default. Pinned on the application's own connection, so a server or pooler configured otherwise reddens here
     * instead of turning every real timestamp cursor into a 422.
     */
    #[Test]
    public function theApplicationConnectionRendersTimestampsInIsoStyle(): void
    {
        self::assertStringStartsWith('ISO', (string) $this->app()->fetchOne('SHOW DateStyle'));
        self::assertTrue(TimestampSortKey::isValid((string) $this->app()->fetchOne('SELECT clock_timestamp()::text')),
            'The application connection renders a timestamptz the helper refuses.');
    }

    private function postgresAccepts(string $value): bool
    {
        try {
            $this->owner()->fetchOne('SELECT ?::timestamptz', [$value]);

            return true;
        } catch (DbalException) {
            return false;
        }
    }

    /** @return list<string> */
    private static function corpus(): array
    {
        $corpus = [];
        foreach (['+', '-'] as $sign) {
            for ($h = 0; $h <= 99; ++$h) {
                $corpus[] = \sprintf('2026-01-01 00:00:00%s%02d', $sign, $h);
            }
            foreach ([13, 14, 15, 16, 17] as $h) {
                foreach (['00', '30', '59', '60', '99'] as $mm) {
                    $corpus[] = \sprintf('2026-01-01 00:00:00%s%02d:%s', $sign, $h, $mm);
                    foreach (['00', '59', '60', '99'] as $ss) {
                        $corpus[] = \sprintf('2026-01-01 00:00:00%s%02d:%s:%s', $sign, $h, $mm, $ss);
                    }
                }
            }
        }

        foreach (['0000', '0001', '1970', '2024', '2026', '9999'] as $year) {
            foreach (['00', '01', '02', '12', '13', '99'] as $month) {
                foreach (['00', '01', '28', '29', '30', '31', '32', '99'] as $day) {
                    $corpus[] = \sprintf('%s-%s-%s 12:00:00+00', $year, $month, $day);
                }
            }
        }

        foreach (['00', '23', '24', '25', '99'] as $hour) {
            foreach (['00', '59', '60', '99'] as $minute) {
                foreach (['00', '59', '60', '61', '99'] as $second) {
                    $corpus[] = \sprintf('2026-01-01 %s:%s:%s+00', $hour, $minute, $second);
                    $corpus[] = \sprintf('9999-12-31 %s:%s:%s-15:59:59', $hour, $minute, $second);
                    $corpus[] = \sprintf('0001-01-01 %s:%s:%s+15:59:59', $hour, $minute, $second);
                }
            }
        }

        foreach (['', '.0', '.1', '.123456', '.999999', '.1234567', '.'] as $fraction) {
            $corpus[] = '2026-01-01 00:00:00'.$fraction.'+00';
            $corpus[] = '9999-12-31 23:59:59'.$fraction.'+00';
        }

        return array_merge($corpus, ['', 'x', 'not-a-time', 'infinity', 'epoch', 'now', '2026-01-01', '2026-01-01 00:00:00',
            '2026-01-01T00:00:00+00', ' 2026-01-01 00:00:00+00', "2026-01-01 00:00:00+00\n", '2026-01-01 00:00:00+00 BC',
            '2026-01-01 00:00:00 +00', '2026-1-1 0:0:0+00', '2026-01-01 00:00:00+0', '2026-01-01 00:00:00+000',
            '2026-01-01 00:00:00+00:0', '2026-01-01 00:00:00Z', '10000-01-01 00:00:00+00', '2026-01-01 00:00:00+00::00']);
    }
}
