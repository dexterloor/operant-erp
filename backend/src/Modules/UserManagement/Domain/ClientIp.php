<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **The address a password-reset request came from** (Mail-C1, R1.5), carried on the queued message so the worker's
 * `auth.password_reset.issued` / `auth.credential.identity_mismatch` rows record it (both IP-bearing). A value object for
 * the reason {@see WorkspaceSlug} is one: the worker re-checks it at decode. An address the server could not determine is
 * carried as no `ClientIp` at all, never as a placeholder.
 *
 * **Wire contract (M-4 (b)):** see {@see WorkspaceSlug}.
 */
final readonly class ClientIp
{
    public string $value;

    public function __construct(string $value)
    {
        if (false === filter_var($value, \FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('A client address must be an IPv4 or IPv6 address.');
        }
        $this->value = $value;
    }

    public static function fromRequest(?string $address): ?self
    {
        return null !== $address && false !== filter_var($address, \FILTER_VALIDATE_IP) ? new self($address) : null;
    }
}
