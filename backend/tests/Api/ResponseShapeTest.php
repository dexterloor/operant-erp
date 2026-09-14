<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * API-23 and API-23a (`docs/API.md` §4.4.3) — the anti-vacuity probe §15 item 11
 * names second.
 *
 * ---------------------------------------------------------------------------
 * The defect this exists for
 * ---------------------------------------------------------------------------
 * A read resource's declared field list is **derived**: the accepted fields of
 * its `Create*` row (minus a parent `_id` the path already implies), plus `id`,
 * plus `archived_at` for a soft-deleted table, plus the resource's row in
 * §4.4.3's additions table. Revision 2 shipped `StockMovement` with a `Create*`
 * row under a different resource name, deletion class `none` and no additions
 * row, so its whole derived response was `{id}` — and **a two-directional
 * comparison of an empty expectation against an empty shape passes in both
 * directions.** This probe is the check that the expectation has content before
 * anything is compared against it.
 *
 * ---------------------------------------------------------------------------
 * Scope, and one reading taken here
 * ---------------------------------------------------------------------------
 * The domain is the **built read shapes**: the resource each `GET` operation in
 * `TransactionBoundaryTest::contractOperations()` reads, named by that
 * operation's §4.2 `Verb / Resource` cell (`List / Items` → `Item`). It widens by
 * itself as reads are built. `::everyDeclaredFieldIsDerivedOrDeclaredAsAnAddition`,
 * the comparison this probe protects, does not exist yet.
 *
 * **The assertion is slightly stronger than "not `{id}` alone"**: steps 1 and 4
 * must contribute at least one field, so `{id, archived_at}` is vacuous too. Step
 * 3 only ever adds `archived_at`, and without this a soft-deleted resource whose
 * §4.2 and §4.4.3 reads both came back empty would pass — the parser failing
 * silently is the same vacuity arriving by a different road. Step 3 is therefore
 * not computed here: it cannot rescue an expectation.
 */
final class ResponseShapeTest extends ApiTestCase
{
    /** The fields every row may get without §4.2 or §4.4.3 saying anything about it. */
    private const FREE_FIELDS = ['id', 'archived_at'];

    /**
     * **The probe.** No built read resource's derived expectation is empty
     * beyond `id` and `archived_at`.
     *
     * The controls are inside this method: a sibling test does not protect a
     * `--filter` run of this one.
     *
     * **Mutation (empty an expectation):** make {@see self::expectation()} ignore
     * the additions table; `CurrentUser`, which has no `Create*` row and whose
     * additions row is its whole shape, derives to `{id}`; red. **Mutation
     * (delete a branch of the derivation):** delete the `CurrentUser` row from
     * §4.4.3's table in `docs/API.md`; red, by name — this is the built-surface
     * form of the `StockMovement` mutation item 11 names, which becomes runnable
     * when Output 9 builds `StockMovement`.
     */
    #[Test]
    public function everyReadResourceHasANonEmptyExpectation(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; the derivation reads §4.2 and §4.4.3 and has nothing to read without it.');
        }

        $rows = ContractDocument::operationRows();
        $additions = self::additionsTable();

        // Control 1 — both documents the derivation reads were actually read.
        self::assertNotSame([], $rows, '§4.2 yielded no operation rows.');
        self::assertNotSame([], $additions, '§4.4.3\'s additions table yielded no rows, so every expectation below would be Create-only.');

        // Control 2 — the detector fires on the defect's own shape: a resource
        // with no Create row and no additions row derives to {id} and is vacuous.
        self::assertSame(['id'], self::expectation('ResourceNoRowDescribes', $rows, $additions));
        self::assertTrue(self::isVacuous(['id']), 'An expectation of {id} alone was not recognised as vacuous, so the assertion below cannot go red.');

        $known = self::knownResources($rows, $additions);
        $resources = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if ('GET' !== $operation->getMethod()) {
                continue;
            }
            self::assertArrayHasKey($name, $rows, \sprintf('%s is a built read with no §4.2 row, so its resource cannot be named.', $name));
            $resources[self::resourceOf($rows[$name]['verbResource'], $known)][] = $name;
        }

        // Control 3 — the domain is not empty.
        self::assertNotSame([], $resources, 'The registry produced no read operation, so the probe examined nothing.');

        $vacuous = [];
        foreach ($resources as $resource => $operations) {
            $expectation = self::expectation($resource, $rows, $additions);
            if (self::isVacuous($expectation)) {
                $vacuous[] = \sprintf('%s (read by %s) derives to {%s}', $resource, implode(', ', $operations), implode(', ', $expectation));
            }
        }

        self::assertSame([], $vacuous,
            'A built read resource has a derived expectation with nothing beyond id and archived_at. API-23a requires an '
            .'additions row for a resource with no Create* row, or with a Create* row under a different Resource name.');
    }

    /**
     * API-23 steps 1, 2 and 4 for one resource. Step 3 is left out on purpose —
     * see the class docblock.
     *
     * @param array<string, array{operationId: string, verbResource: string, method: string, path: string}> $rows
     * @param array<string, list<string>>                                                                    $additions
     *
     * @return list<string>
     */
    private static function expectation(string $resource, array $rows, array $additions): array
    {
        $fields = [];

        foreach ($rows as $operationId => $row) {
            if ('Create / '.$resource !== $row['verbResource']) {
                continue;
            }
            $parents = self::parentsImpliedBy($row['path']);
            foreach (ContractDocument::acceptedFields($operationId) as $field) {
                if (!(str_ends_with($field, '_id') && \in_array(substr($field, 0, -3), $parents, true))) {
                    $fields[] = $field;
                }
            }
        }

        return array_values(array_unique(['id', ...$fields, ...($additions[$resource] ?? [])]));
    }

    /** @param list<string> $expectation */
    private static function isVacuous(array $expectation): bool
    {
        return [] === array_diff($expectation, self::FREE_FIELDS);
    }

    /**
     * §4.4.3's additions table, as resource → field names.
     *
     * The cell is prose. The fields are the backticked names **before the first
     * ` — ` or sentence break**, with parenthesised asides removed first — every
     * row opens with its field list and then explains it. A row naming two
     * resources (`**GoodsReceipt** / **Fulfilment**`) gives both the same list.
     * Over-reading a cell can only make this probe weaker, never red, so the
     * rule errs toward reading less.
     *
     * @return array<string, list<string>>
     */
    private static function additionsTable(): array
    {
        $table = [];

        foreach (explode("\n", ContractDocument::sectionBetween('#### 4.4.3 ', '#### 4.4.4 ')) as $line) {
            if (!str_starts_with($line, '| **')) {
                continue;
            }
            $cells = explode('|', $line);
            if (\count($cells) < 4) {
                continue;
            }

            preg_match_all('/\*\*([A-Z][A-Za-z]+)\*\*/', $cells[1], $names);

            $prose = $cells[2];
            do {
                $prose = (string) preg_replace('/\([^()]*\)/', '', $before = $prose);
            } while ($prose !== $before);
            $prose = preg_split('/ — |\. /', $prose, 2)[0];

            preg_match_all('/`([a-z][a-z_]*)/', $prose, $tokens);
            $fields = array_values(array_unique(array_diff($tokens[1], ['null'])));

            foreach ($names[1] as $name) {
                $table[$name] = $fields;
            }
        }

        return $table;
    }

    /**
     * Every singular resource name §4.2's `Get` and `Create` rows or §4.4.3's
     * additions table use — the set a `List` row's plural is resolved against.
     *
     * @param array<string, array{verbResource: string}> $rows
     * @param array<string, list<string>>                $additions
     *
     * @return list<string>
     */
    private static function knownResources(array $rows, array $additions): array
    {
        $known = array_keys($additions);

        foreach ($rows as $row) {
            if (1 === preg_match('/^(?:Get|Create) \/ ([A-Za-z]+)$/', $row['verbResource'], $match)) {
                $known[] = $match[1];
            }
        }

        return array_values(array_unique($known));
    }

    /**
     * The resource a read's `Verb / Resource` cell names. A `List` cell is
     * plural, and the singular is whichever of `-s`, `-es`, `-ies → -y` another
     * row already uses; anything else is refused rather than guessed.
     *
     * @param list<string> $known
     */
    private static function resourceOf(string $verbResource, array $known): string
    {
        self::assertMatchesRegularExpression('/^(Get|List) \/ [A-Za-z]+$/', $verbResource, 'A built GET operation\'s Verb / Resource cell is neither Get nor List.');
        [$verb, $name] = explode(' / ', $verbResource);

        if ('Get' === $verb) {
            return $name;
        }

        $candidates = array_intersect([
            (string) preg_replace('/s$/', '', $name),
            (string) preg_replace('/es$/', '', $name),
            (string) preg_replace('/ies$/', 'y', $name),
        ], $known);
        $candidates = array_values(array_unique($candidates));

        self::assertCount(1, $candidates, \sprintf('"%s" does not resolve to exactly one singular resource another row names.', $verbResource));

        return $candidates[0];
    }

    /**
     * The parents a `Create*` path implies — `/purchase-orders/{id}/lines` implies
     * `purchase_order` — so step 1 can drop `purchase_order_id`.
     *
     * @return list<string>
     */
    private static function parentsImpliedBy(string $path): array
    {
        preg_match_all('#/([a-z-]+)/\{[^}]+\}#', $path, $matches);

        return array_map(
            static fn (string $segment): string => str_replace('-', '_', (string) preg_replace('/s$/', '', $segment)),
            $matches[1],
        );
    }
}
