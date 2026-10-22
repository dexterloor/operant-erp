<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * A row class for `ExportCsvTest` with every shape a List row can have: a serialized name, a nullable reference, a key the
 * serializer OMITS when null (`SalesReturnResource`'s caller-dependent fields, `SKIP_NULL_VALUES`), a list, an integer
 * and a boolean.
 */
final readonly class ExportRowFixture
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        #[SerializedName('display_name')]
        public string $displayName,
        public ?ExportRowFixtureRef $ref,
        #[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?string $withheld,
        public array $tags,
        public int $count,
        public bool $active,
    ) {
    }
}
