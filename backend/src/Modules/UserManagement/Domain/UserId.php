<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * A person's id on the mail queue (U-C1b, D-U5; `MessengerConfigurationTest` #10: a catalogued payload holds no bare
 * scalar). A version-4 uuid, normalised (`app_user_id_is_uuid_v4`). **A wire contract** (`MessageCatalogue`, M-4 (b)):
 * changing this constructor or its invariant is a message-version change.
 */
final readonly class UserId
{
    public string $value;

    public function __construct(string $value)
    {
        if (!Uuid::isValid($value) || 4 !== (int) Uuid::fromString($value)->toRfc4122()[14]) {
            throw new \InvalidArgumentException('A user id must be a version-4 UUID.');
        }
        $this->value = Uuid::fromString($value)->toRfc4122();
    }
}
