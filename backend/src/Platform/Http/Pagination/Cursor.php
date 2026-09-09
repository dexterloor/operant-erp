<?php

declare(strict_types=1);

namespace App\Platform\Http\Pagination;

use Symfony\Component\Uid\Uuid;

/**
 * API-17 / API-18 / TB-2a — the keyset cursor.
 *
 * It carries the **sort key and the id**, never the sort key alone: no sort key
 * in the manifest is unique across every view, and a cursor on a non-unique key
 * either skips or repeats rows across a tie group.
 *
 * It carries **no tenant identifier** (API-6, P-6, T-1). It is opaque,
 * base64url, and unsigned -- unsigned because it is not a capability: it names a
 * position inside a result set RLS has already bounded to the caller's tenant,
 * so a forged cursor can seek only within rows the caller may already read.
 * Signing it would suggest otherwise.
 */
final readonly class Cursor
{
    public function __construct(
        public string $sortKey,
        public string $id,
    ) {
    }

    public function encode(): string
    {
        $json = json_encode(['k' => $this->sortKey, 'i' => $this->id], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @throws InvalidCursor */
    public static function decode(string $encoded): self
    {
        $padded = strtr($encoded, '-_', '+/');
        $binary = base64_decode($padded, true);
        if (false === $binary) {
            throw new InvalidCursor();
        }

        try {
            $decoded = json_decode($binary, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidCursor();
        }

        if (!\is_array($decoded)
            || ['i', 'k'] !== self::sortedKeys($decoded)
            || !\is_string($decoded['k'])
            || !\is_string($decoded['i'])
            || !Uuid::isValid($decoded['i'])
        ) {
            throw new InvalidCursor();
        }

        return new self($decoded['k'], $decoded['i']);
    }

    /** @param array<mixed> $decoded @return list<string> */
    private static function sortedKeys(array $decoded): array
    {
        $keys = array_map(strval(...), array_keys($decoded));
        sort($keys);

        return $keys;
    }
}
