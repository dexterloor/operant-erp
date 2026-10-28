<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * **One byte form for a JSON document, so a committed copy can be compared byte for byte** (W-4, `contracts/`).
 *
 * Object keys are sorted (byte order) at every depth; list order is kept, because the order of an `enum`, a `required`
 * list or a `parameters` list is not ours to change. The document is decoded to OBJECTS, never to associative arrays: an
 * associative decode turns `{}` into `[]` and an object keyed `"0","1"` into a list, and both change what the document
 * means. Numbers keep their shortest round-trip form (`serialize_precision = -1`, for this call only) and a float keeps
 * its fraction (`1.0` stays `1.0`, not `1`). Slashes and non-ASCII text are written unescaped, so a description reads
 * exactly as its PHP literal does.
 *
 * The keys are sorted so the bytes do not depend on the order in which the generator happens to walk its resources
 * (today class-name order; a filesystem order on another machine would otherwise move every path).
 *
 * *Mutations, run at W-4:* decode to arrays, sort lists too, or rebuild objects as arrays — each turns
 * `ContractArtefactTest::theCanonicalFormIsStable` red; drop the zero-fraction flag — the same test goes red on `1.0`.
 */
final class CanonicalJson
{
    public static function encode(string $json): string
    {
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            $decoded = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);

            return json_encode(
                self::sorted($decoded),
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
            )."\n";
        } finally {
            ini_set('serialize_precision', false === $precision ? '-1' : $precision);
        }
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, \SORT_STRING);
            foreach ($properties as $key => $item) {
                $properties[$key] = self::sorted($item);
            }

            // A cast, not property assignment: it keeps "0"-style keys as object members and allows any key text.
            return (object) $properties;
        }

        if (\is_array($value)) {
            return array_map(self::sorted(...), $value);
        }

        return $value;
    }
}
