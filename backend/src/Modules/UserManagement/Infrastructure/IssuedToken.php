<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

/** A minted access token and the instant E-20 kills it. */
final readonly class IssuedToken
{
    public function __construct(public string $jwt, public \DateTimeImmutable $expiresAt)
    {
    }
}
