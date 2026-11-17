<?php

declare(strict_types=1);

namespace App\Tests\Support;

use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Validator\Constraints as Assert;
use function preg_match as matches;

/**
 * The positive control of {@see \App\Tests\Architecture\RegexEndAnchorTest}: scanned alone, with
 * {@see self::DENY_LIST_DEMO} as its only exemption, it must report exactly the test's `PLANT_FLAGGED` list, and read
 * every pattern of `PLANT_NOT_FLAGGED` without flagging it. Never called; nothing in `src/` refers to it.
 */
final class RegexEndAnchorPlant
{
    private const DOLLAR = '/^\d+$/';

    /** Exempt in the plant's own run, so it must NOT be flagged... */
    private const DENY_LIST_DEMO = '/_DEMO\n?\z/';

    /** ...while the same pattern, unexempted, must be. */
    private const NOT_EXEMPT_DEMO = '/_DEMO\n?\z/';

    /** Rule C's backstop: a validator `Regex` is compiled without `D`. */
    #[Assert\Regex('/^x$/')]
    public ?string $code = null;

    public function flagged(string $s): void
    {
        preg_match('/^\d+$/', $s);
        preg_match(self::DOLLAR, $s);
        preg_replace(RegexEndAnchorPlantConstants::OTHER_DOLLAR, '', $s);
        preg_match('/(a|b$)c/', $s);
        preg_match('/^x$/m', $s);
        preg_match('/(?m)^x$/D', $s);
        preg_match('/^x\Z/', $s);
        preg_match('/\n?\z/', $s);
        preg_match('/\A\d+\n*\z/', $s);
        preg_match('/\A\d+\R?\z/', $s);
        preg_match('/\A\d+\s*\z/', $s);
        preg_match('/\A\d+[\r\n]*\z/', $s);
        preg_match('/\A\d+\n?$/D', $s);
        preg_match('/\A\d+\n{0,1}\z/', $s);
        preg_match('/\A\d+(?:\n)?\z/', $s);
        preg_match('/\A\d+\x0a?\z/', $s);
        preg_match(self::NOT_EXEMPT_DEMO, $s);
        matches('/^\d+$/', $s);
    }

    public function unreadable(string $pattern, string $s): void
    {
        preg_match($pattern, $s);
    }

    public function notFlagged(string $s): string
    {
        preg_match('/\A\d+\z/', $s);
        preg_match('/^\d+$/D', $s);
        preg_match('/a\$b/', $s);
        preg_match('/[$]/', $s);
        preg_match('/[^$]/', $s);
        preg_match('/[]$]/', $s);
        preg_replace('/\A\s+|\s+\z/u', '', $s);
        preg_match('/\A\d+\s+\z/', $s);
        preg_match('/\A\d+(\.\d+)?\z/', $s);
        preg_match(self::DENY_LIST_DEMO, $s);

        return sprintf('(%1$s)', $s);
    }

    /** Rule C's backstop: a parameter schema `pattern` is compiled without `D`. */
    public function schema(): QueryParameter
    {
        return new QueryParameter(schema: ['type' => 'string', 'pattern' => '^x$']);
    }
}
