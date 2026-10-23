<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Fixture;

use App\Platform\Messaging\PreAuthenticationMessage;

/** A planted catalogue member for tests that need a type the serializer accepts. Never in `MessageCatalogue::ALL`. */
final readonly class CataloguedProbe implements PreAuthenticationMessage
{
    public function __construct(public ProbeId $id)
    {
    }
}
