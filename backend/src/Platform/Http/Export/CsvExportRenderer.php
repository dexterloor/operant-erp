<?php

declare(strict_types=1);

namespace App\Platform\Http\Export;

use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * **Renders a List's rows as the CSV `CreateExport` returns (API-25; design §6.3, §6.4; Response H-2, M-7; final RED M-5).**
 *
 * **The header** is derived ONCE per row class from the SERIALIZER's own metadata — the attributes it serializes, each by
 * its serialized name (`#[SerializedName]`; no name converter is configured), ignored attributes left out — recursing into
 * a property whose declared type is a class (a `…Ref` / `…Reference`), whose leaves become dotted columns
 * (`supplier.id`, `supplier.name`). A nullable reference is still its leaves. Never from a row (a null reference would
 * shift every column after it) and never from PHP property names (`unitOfMeasureCode` is not a wire name). The pin is
 * `ExportCsvTest::theHeaderEqualsTheListItemSchemasLeaves`: two-way against the generated OpenAPI item schema.
 *
 * **Each row** is normalized by the same serializer the List uses, then flattened by the header: a leaf whose key is ABSENT
 * from the normalized row renders as an empty cell exactly as `null` does (final RED M-5 — the CSV cannot tell absent from
 * null, and neither discloses a withheld value), so every row is as wide as the header. A boolean is `true`/`false`, a
 * number its decimal text, a decimal the wire string unchanged (API-23c), a timestamp the wire RFC 3339 string, an array
 * (or an object under a leaf the metadata calls scalar) one cell of compact JSON. Then {@see CsvCell::render()}.
 *
 * **Memory and timing (M-7, L-6):** rows are normalized and written ONE AT A TIME into a `php://temp` stream that spills to
 * disk past 2 MiB, each released after it is written; nothing leaves before rendering completes, so a render failure is
 * still a 500. UTF-8 with a BOM (Excel's encoding hint; delegated to `UX`), CRLF line ends (RFC 4180).
 */
final readonly class CsvExportRenderer
{
    public const BOM = "\u{FEFF}";

    public const LINE_END = "\r\n";

    /** Bytes `php://temp` keeps in memory before it spills to a temporary file. */
    public const MEMORY_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private ClassMetadataFactoryInterface $metadata,
        private NormalizerInterface $normalizer,
    ) {
    }

    /**
     * The dotted leaf paths of `$class`, in the serializer's attribute order.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    public function header(string $class): array
    {
        return $this->leaves($class, '', []);
    }

    /**
     * @param class-string $rowClass
     * @param list<object> $rows     emptied as it is rendered: each row is released once written
     *
     * @return resource a readable stream, rewound, holding the whole file
     */
    public function render(string $rowClass, array &$rows)
    {
        $header = $this->header($rowClass);
        $stream = fopen('php://temp/maxmemory:'.self::MEMORY_BYTES, 'w+b');
        if (false === $stream) {
            throw new \RuntimeException('The export could not open its temporary stream.');
        }

        fwrite($stream, self::BOM.self::line($header));

        foreach (array_keys($rows) as $index) {
            $row = $rows[$index];
            unset($rows[$index]);
            if (!$row instanceof $rowClass) {
                throw new \LogicException(\sprintf('An export of %s rows was handed a %s.', $rowClass, get_debug_type($row)));
            }

            $normalized = $this->normalizer->normalize($row, 'json');
            unset($row);
            if (!\is_array($normalized)) {
                throw new \LogicException(\sprintf('A %s row did not normalize to an object.', $rowClass));
            }

            fwrite($stream, self::line(array_map(static fn (string $path): string => self::cell($normalized, $path), $header)));
        }

        rewind($stream);

        return $stream;
    }

    /**
     * The text of one header leaf in one normalized row: empty when the path is absent or crosses a null.
     *
     * @param array<array-key, mixed> $normalized
     */
    public static function cell(array $normalized, string $path): string
    {
        $value = $normalized;
        foreach (explode('.', $path) as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return '';
            }
            $value = $value[$key];
        }

        return match (true) {
            null === $value => '',
            true === $value => 'true',
            false === $value => 'false',
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            default => json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION),
        };
    }

    /** @param list<string> $cells */
    public static function line(array $cells): string
    {
        return implode(',', array_map(CsvCell::render(...), $cells)).self::LINE_END;
    }

    /**
     * @param class-string       $class
     * @param array<string, true> $seen  the classes on the current path, so a self-referencing class cannot recurse forever
     *
     * @return list<string>
     */
    private function leaves(string $class, string $prefix, array $seen): array
    {
        if (isset($seen[$class])) {
            throw new \LogicException(\sprintf('%s refers to itself, so its export header has no end.', $class));
        }
        $seen[$class] = true;

        $leaves = [];
        foreach ($this->metadata->getMetadataFor($class)->getAttributesMetadata() as $attribute) {
            // The serializer's metadata also lists private/static properties and get/is/has accessors (`AttributeLoader`);
            // a List row is a set of public promoted properties, so only those are columns (`g6-c0-red.md` L-1). The schema
            // pin (`ExportCsvTest::theHeaderEqualsTheListItemSchemasLeaves`) catches any other drift.
            if ($attribute->isIgnored() || !self::isPublicInstanceProperty($class, $attribute->getName())) {
                continue;
            }
            $name = $prefix.($attribute->getSerializedName() ?? $attribute->getName());
            $nested = self::referenceClass($class, $attribute->getName());

            if (null === $nested) {
                $leaves[] = $name;
                continue;
            }

            array_push($leaves, ...$this->leaves($nested, $name.'.', $seen));
        }

        if ([] === $leaves) {
            throw new \LogicException(\sprintf('%s serializes no attribute, so it has no export header.', $class));
        }

        return $leaves;
    }

    /** @param class-string $class */
    private static function isPublicInstanceProperty(string $class, string $property): bool
    {
        if (!property_exists($class, $property)) {
            return false;
        }
        $reflection = new \ReflectionProperty($class, $property);

        return $reflection->isPublic() && !$reflection->isStatic();
    }

    /**
     * The class a property's declared type names, if it is a single (optionally nullable) class — the reference that
     * becomes dotted columns. A scalar, an `array`, a union of several types or an enum is one cell.
     *
     * @param class-string $class
     *
     * @return class-string|null
     */
    private static function referenceClass(string $class, string $property): ?string
    {
        if (!property_exists($class, $property)) {
            return null;
        }

        $type = (new \ReflectionProperty($class, $property))->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();
        if (!class_exists($name) || enum_exists($name) || is_a($name, \DateTimeInterface::class, true)) {
            return null;
        }

        return $name;
    }
}
