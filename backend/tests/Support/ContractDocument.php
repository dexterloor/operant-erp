<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * `docs/API.md`, read as data.
 *
 * ---------------------------------------------------------------------------
 * The availability problem, stated rather than hidden
 * ---------------------------------------------------------------------------
 * `docs/` is **gitignored** (repo policy: only runnable code, tests and infra
 * config are committed), so every check `docs/API.md` specifies for itself --
 * §3.3's extraction, §12.3's operationId derivation, §10.3's parameter
 * comparison, §5.2's catalogue -- has a domain that is not in the repository.
 * On a clean checkout those checks have nothing to read.
 *
 * The answer taken here is the `PINNED_` mechanism §12.3 already uses for its
 * singleton exception: each contract-derived test carries a **pin** in the test
 * file, and the pin is checked against the code on every run. Where the
 * document is present the pin is *also* checked against it, in both directions.
 * So a clean checkout still tests something real, a dev checkout additionally
 * catches drift between the pin and the contract, and nothing anywhere passes
 * by having no subject.
 */
final class ContractDocument
{
    public static function path(): string
    {
        return \dirname(__DIR__, 3).'/docs/API.md';
    }

    public static function isAvailable(): bool
    {
        return is_file(self::path());
    }

    /** @return list<string> */
    public static function lines(): array
    {
        return explode("\n", (string) file_get_contents(self::path()));
    }

    /**
     * §4.2's rows. The field-count rule is §3.3's own: with `-F'|'` a
     * well-formed row has 11 fields, so the columns are $2 … $10.
     *
     * @return array<string, array{operationId: string, verbResource: string, method: string, path: string, perm: string, idem: string, accepts: string, errors: string, query: string}>
     */
    public static function operationRows(): array
    {
        $rows = [];

        foreach (self::lines() as $line) {
            $cells = explode('|', $line);
            if (11 !== \count($cells)) {
                continue;
            }

            $cells = array_map(trim(...), $cells);
            $operationId = trim($cells[1], '`');

            // The header row and the `|---|` separator both have 11 fields.
            if ('operationId' === $operationId || 1 === preg_match('/^-*$/', $operationId)) {
                continue;
            }

            $rows[$operationId] = [
                'operationId' => $operationId,
                'verbResource' => $cells[2],
                'method' => $cells[3],
                'path' => trim($cells[4], '`'),
                'perm' => trim($cells[5], '`'),
                'idem' => strtolower(strip_tags(str_replace('*', '', $cells[6]))),
                'accepts' => $cells[7],
                'errors' => $cells[8],
                'query' => $cells[9],
            ];
        }

        return $rows;
    }

    /**
     * §5.2's catalogue: slug => status.
     *
     * @return array<string, int>
     */
    public static function problemCatalogue(): array
    {
        $catalogue = [];
        $inside = false;

        foreach (self::lines() as $line) {
            if (str_starts_with($line, '### 5.2 ')) {
                $inside = true;
                continue;
            }
            if ($inside && str_starts_with($line, '### 5.2a')) {
                break;
            }
            if (!$inside || !str_starts_with($line, '|')) {
                continue;
            }

            $cells = array_map(trim(...), explode('|', $line));
            if (\count($cells) < 4) {
                continue;
            }

            $slug = trim($cells[1], '`');
            if (1 !== preg_match('/^[a-z-]+$/', $slug) || 1 !== preg_match('/^\d{3}$/', $cells[2])) {
                continue;
            }

            $catalogue[$slug] = (int) $cells[2];
        }

        return $catalogue;
    }

    /**
     * The `Query parameters` cell of one row, as the set of parameter names it
     * declares. Prose in the cell (`*(bounded)*`, "required — exactly one of")
     * is stripped; the names are the backticked tokens.
     *
     * @return list<string>
     */
    public static function queryParameters(string $operationId): array
    {
        $cell = self::operationRows()[$operationId]['query'] ?? '';

        if ('—' === $cell || '' === $cell) {
            return [];
        }

        preg_match_all('/`([a-z_]+)`/', $cell, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The `Accepted request fields` cell of one row, as the set of top-level
     * field names it declares.
     *
     * @return list<string>
     */
    public static function acceptedFields(string $operationId): array
    {
        $cell = self::operationRows()[$operationId]['accepts'] ?? '';

        if (str_starts_with($cell, '—')) {
            return [];
        }

        preg_match_all('/`([a-z_]+(?:\[\]\.[a-z_]+)?)`/', $cell, $matches);

        return array_values(array_unique(array_filter($matches[1], static fn (string $f): bool => !str_contains($f, '[]'))));
    }
}
