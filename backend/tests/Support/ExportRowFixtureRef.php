<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** The reference `ExportRowFixture` carries: two leaves, `ref.id` and `ref.label`. */
final readonly class ExportRowFixtureRef
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}
