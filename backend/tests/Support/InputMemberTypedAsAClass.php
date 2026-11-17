<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A planted request input with one member typed as a class: the positive control of
 * {@see \App\Tests\Api\NonObjectBodyRefusalTest::noInputMemberIsTypedAsAClass()}, which must report `$item` and nothing
 * else. Not an `input:` of any operation; nothing in `src/` refers to it.
 */
final class InputMemberTypedAsAClass
{
    public ?string $sku = null;

    public ?\DateTimeImmutable $item = null;
}
