<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

/**
 * A message class outside {@see MessageCatalogue::ALL} reached the bus or the serializer. A programming error, never a
 * caller's: nothing outside the catalogue may be dispatched, encoded or queued.
 */
final class MessageNotCatalogued extends \LogicException
{
    public static function forClass(string $class): self
    {
        return new self(\sprintf('"%s" is not in App\Platform\Messaging\MessageCatalogue::ALL, so it may not be dispatched or queued.', $class));
    }
}
