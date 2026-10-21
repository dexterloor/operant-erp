<?php

declare(strict_types=1);

namespace App\Platform\Http\Idempotency;

/**
 * A stored response envelope, as {@see IdempotencyEnvelopeStore} hands it back: the status, the body BYTES (never
 * re-serialised — the processor returns exactly these, so a first response and its replay are byte-identical by
 * construction), and §6.2's normative headers in lower case.
 */
final readonly class Replay
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers,
    ) {
    }
}
