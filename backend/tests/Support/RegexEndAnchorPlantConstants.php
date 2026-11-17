<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A constant another class's `preg_*` call reads ({@see RegexEndAnchorPlant}), so `RegexEndAnchorTest`'s walk must resolve a
 * cross-class constant to be reported. Its own file because PSR-4 autoloads one class per file. Nothing in `src/` refers to it.
 */
final class RegexEndAnchorPlantConstants
{
    public const OTHER_DOLLAR = '/^\d+$/';
}
