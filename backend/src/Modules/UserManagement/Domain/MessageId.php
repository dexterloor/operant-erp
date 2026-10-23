<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * **A queued message's own id** (Mail-C1, R1.5): minted at dispatch, stored on the token row it produces
 * (`password_reset_token_message_key`), so a redelivery after a lost acknowledgement mints nothing a second time (T-M8′).
 * A UUIDv4 in canonical form; the constructor re-checks it at decode.
 *
 * **Wire contract (M-4 (b)):** see {@see WorkspaceSlug}.
 */
final readonly class MessageId
{
    public string $value;

    public function __construct(string $value)
    {
        if (!Uuid::isValid($value) || 4 !== (int) Uuid::fromString($value)->toRfc4122()[14]) {
            throw new \InvalidArgumentException('A message id must be a version-4 UUID.');
        }
        $this->value = Uuid::fromString($value)->toRfc4122();
    }

    public static function new(): self
    {
        return new self(Uuid::v4()->toRfc4122());
    }
}
