<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * §4.4.3's wire-scale decision, held by measurement rather than by assertion.
 *
 * ---------------------------------------------------------------------------
 * What this pins, and why a sentence in the contract was not enough
 * ---------------------------------------------------------------------------
 * A decimal is carried on the wire as a **string at its column's scale** —
 * quantity `numeric(19,6)`, money `numeric(19,4)` — because it must never become
 * a float, and because a client that has to guess how many digits it will be
 * given cannot compare two responses. That is a **transport** decision: it is
 * not how a number is shown to a person, which is `docs/ux/STATES.md` §QR, and
 * QR-3 says so in the other direction (*"`"6.000000"` is a correct API response
 * and an incorrect table cell"*).
 *
 * **A prose guarantee nobody can show RED has no teeth.** The scale a field
 * emits is not written anywhere in the code: it falls out of the column type,
 * and for a derived value out of the SQL expression that produces it.
 * `quantity_outstanding` is the worked example — `PurchaseOrderLineRow::COLUMNS`
 * computes `quantity_ordered - quantity_received`, which is scale 6 because
 * Postgres says subtraction of two `numeric(19,6)` is, and that class's own
 * docblock records a `GREATEST(…, 0)` that *"would have rendered `"0"` where its
 * neighbour rendered `"6.000000"`"*. An aggregate, a division or a cast added
 * tomorrow moves a scale with nothing to stop it. This file makes that arrive as
 * a **red diff naming the field** instead of as silence.
 *
 * ---------------------------------------------------------------------------
 * How the domain is derived
 * ---------------------------------------------------------------------------
 * From the **registry**, never from a hand-written list:
 * `TransactionBoundaryTest::contractOperations()` is the built surface and
 * {@see OperationSuccessRecipes} drives each operation for real. The recipe keys
 * are held equal to the registry inside this test, so an operation built
 * tomorrow is red here until it has a recipe — the same construction
 * `ResponseStatusTest` and `ResponseShapeTest` use.
 *
 * Every response body is walked recursively; every **string** value that reads
 * as a decimal is recorded as `Operation $.path → scale`, with list indices
 * collapsed to `[]` so a two-row list and a one-row list produce one entry. A
 * decimal that arrived as a JSON **number** is not a decimal on this wire at all
 * and is reported separately, by name: that is the float this contract exists to
 * prevent.
 *
 * ---------------------------------------------------------------------------
 * The artefact
 * ---------------------------------------------------------------------------
 * {@see self::ARTEFACT} is generated and committed, the way the OpenAPI document
 * is generated and compared. Regenerate it deliberately with
 * `WIRE_SCALE_UPDATE=1 php bin/phpunit --filter WireScaleTest`, which rewrites
 * the file **and still fails**, so a run can never quietly re-baseline itself.
 * Every line of it is a decision somebody has to look at.
 */
final class WireScaleTest extends PurchaseOrderTestCase
{
    use OperationSuccessRecipes;

    private const ARTEFACT = __DIR__.'/wire-scale.json';

    /** A decimal as S-12 puts it on the wire: a string, optionally signed, with an optional fractional part. */
    private const DECIMAL = '/\A-?\d+(?:\.(\d+))?\z/';

    /**
     * **The pin.** Every decimal any built operation emits, at the scale it
     * emits it, against the committed artefact.
     *
     * The controls are inside this method: a sibling test does not protect a
     * `--filter` run of this one.
     *
     * **Mutation:** cast `quantity_outstanding` to `numeric(19,2)` in
     * `PurchaseOrderLineRow::COLUMNS`; red on the three operations that carry
     * it — `CreatePurchaseOrderLine $.quantity_outstanding`,
     * `UpdatePurchaseOrderLine $.quantity_outstanding` and
     * `ListPurchaseOrderLines $.items[].quantity_outstanding` — 6 against 2, and
     * on nothing else. **Mutation (empty the artefact):** `{}`; red, with every
     * field reported missing. **Mutation (blind the walker):** make
     * {@see self::walk()} skip strings; the first control is red before any
     * comparison runs.
     */
    #[Test]
    public function everyDecimalIsEmittedAtItsColumnsScale(): void
    {
        $operations = TransactionBoundaryTest::contractOperations();
        $recipes = $this->successRecipes();

        self::assertNotSame([], $operations, 'The registry produced no operations, so nothing was driven.');
        $names = array_keys($operations);
        sort($names);
        $recipeNames = array_keys($recipes);
        sort($recipeNames);
        self::assertSame($names, $recipeNames, 'Every operation in the registry must be driven here, and only those.');

        // Control 1 — the walker finds a decimal string at depth, collapses list
        // indices, ignores a timestamp and a document number, and reports a
        // decimal that arrived as a JSON number rather than recording its scale.
        $planted = $numeric = [];
        self::walk([
            'total_amount' => '12.3400',
            'receipt_number' => 'GR-000001',
            'occurred_at' => '2026-09-11T08:00:00.000+00:00',
            'lines' => [['quantity' => '1.000000'], ['quantity' => '2.500000']],
            'loose' => 1.25,
        ], '$', $planted, $numeric);
        self::assertSame(['$.total_amount' => [4], '$.lines[].quantity' => [6]], $planted,
            'The walker does not find a decimal string at depth, or does not collapse list indices.');
        self::assertSame(['$.loose'], $numeric, 'The walker does not report a decimal that arrived as a JSON number.');

        // Control 1b (audit reads C1, M-2) — an entry's opaque `metadata` is not recorded into the artefact, but a scale-4
        // decimal there is caught; a `metadata_x` beside it is an ordinary field.
        $planted = $numeric = $money = [];
        self::walk(['items' => [['metadata' => ['counted_quantity' => '1.000000', 'nested' => ['cost' => '1.0000']], 'metadata_x' => '2.0000']]],
            '$', $planted, $numeric, $money);
        self::assertSame(['$.items[].metadata_x' => [4]], $planted, 'The walker recorded an opaque metadata decimal, or skipped a sibling.');
        self::assertSame(['$.items[].metadata.nested.cost'], $money, 'The walker missed a planted money amount inside metadata.');

        $prepared = [];
        foreach ($recipes as $name => [$prepare]) {
            $prepared[$name] = $prepare();
        }

        $observed = [];
        $floats = [];
        $moneyInMetadata = [];
        $bodiesRead = 0;

        foreach ($recipes as $name => [, $drive]) {
            $drive($prepared[$name]);

            $body = json_decode((string) $this->client()->getResponse()->getContent(), true);
            if (!\is_array($body)) {
                continue; // A 204 has no body; §4.4.1 branch 3.
            }
            ++$bodiesRead;

            $fields = $numbers = $money = [];
            self::walk($body, '$', $fields, $numbers, $money);
            foreach ($money as $path) {
                $moneyInMetadata[] = $name.' '.$path;
            }

            foreach ($fields as $path => $scales) {
                $observed[$name.' '.$path] = $scales;
            }
            foreach ($numbers as $path) {
                $floats[] = $name.' '.$path;
            }
        }

        // Control 2 — the drive produced bodies to walk at all.
        self::assertGreaterThan(0, $bodiesRead, 'No operation returned a body, so no scale was observed.');
        self::assertNotSame([], $observed, 'No built operation emitted a decimal, so the artefact below has nothing to hold.');

        // Control 3 — both scales the decision names are actually represented,
        // so an artefact that happened to hold only one kind is not mistaken for
        // a check over both.
        self::assertContains(6, array_merge(...array_values($observed)), 'No field emitted a quantity at numeric(19,6).');
        self::assertContains(4, array_merge(...array_values($observed)), 'No field emitted money at numeric(19,4).');

        self::assertSame([], $moneyInMetadata,
            'An audit entry\'s opaque metadata carries a scale-4 decimal — a money amount. §4.4.3 says the trail\'s metadata never '
            .'carries one (PC-12; audit reads C1, final RED M-2): a cost or sale amount there makes ListAuditLogEntries a margin '
            .'source no derivation sees. '.implode(', ', $moneyInMetadata));

        self::assertSame([], $floats,
            'A decimal is carried on the wire as a string (S-12). A field emitted it as a JSON number, which is the float '
            .'this contract exists to prevent: '.implode(', ', $floats));

        ksort($observed);

        if ('' !== (string) getenv('WIRE_SCALE_UPDATE')) {
            file_put_contents(self::ARTEFACT, json_encode($observed, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
            self::fail('The wire-scale artefact was rewritten. Read the diff, then re-run without WIRE_SCALE_UPDATE.');
        }

        self::assertFileExists(self::ARTEFACT, 'The generated wire-scale artefact is missing; regenerate it with WIRE_SCALE_UPDATE=1.');
        $committed = json_decode((string) file_get_contents(self::ARTEFACT), true);
        self::assertIsArray($committed);

        self::assertSame($committed, $observed,
            'A built operation emits a decimal at a scale the committed artefact does not record. §4.4.3 carries a decimal '
            .'at its column\'s scale; an aggregate, a division or a cast that moves one is a transport change and is '
            .'declared here on purpose. Regenerate with WIRE_SCALE_UPDATE=1 once the move is intended.');
    }

    /**
     * **The sentence and the artefact are tied by number.** §4.4.3's API-23c
     * names two scales in prose; the committed artefact records the scales the
     * built surface actually emits. This asserts that those are the same two
     * sets, so the contract cannot state one scale while the wire carries
     * another — the failure mode a prose-only guarantee has.
     *
     * Skipped without the document, like every other check that reads it: the
     * pin above runs on the artefact alone and so runs on a clean checkout.
     *
     * **Mutation:** change `numeric(19,4)` to `numeric(19,2)` in API-23c's first
     * paragraph; red, 2 against the 4 the artefact holds.
     */
    #[Test]
    public function theScalesSection443NamesAreTheScalesTheWireCarries(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; the pin above holds the artefact without it.');
        }

        $section = (string) preg_replace('/\s+/', ' ', ContractDocument::sectionBetween('#### 4.4.3 ', '#### 4.4.4 '));
        self::assertSame(1, preg_match('/a quantity at `numeric\(19,(\d+)\)`, money at `numeric\(19,(\d+)\)`/', $section, $stated),
            '§4.4.3 does not state API-23c\'s two wire scales, so the artefact has no sentence to be held to.');

        $named = [(int) $stated[1], (int) $stated[2]];
        sort($named);

        $committed = json_decode((string) file_get_contents(self::ARTEFACT), true);
        self::assertIsArray($committed);
        self::assertNotSame([], $committed, 'The wire-scale artefact is empty, so any set of stated scales would match it.');

        $carried = array_values(array_unique(array_merge(...array_values($committed))));
        sort($carried);

        self::assertSame($named, $carried,
            'API-23c names scales the built surface does not carry, or the surface carries one the section does not name.');
    }

    /**
     * Every decimal string in `$body`, keyed by its path with list indices
     * collapsed to `[]`, mapped to the sorted distinct scales observed at that
     * path; and, separately, the paths where a decimal arrived as a JSON number.
     *
     * A string with no fractional part is scale 0 and is recorded as such — that
     * is the exact shape `PurchaseOrderLineRow`'s withdrawn `GREATEST(…, 0)`
     * would have produced, and leaving it out would make the check blind to the
     * one regression its own source file records.
     *
     * @param array<array-key, mixed>    $body
     * @param array<string, list<int>>   $into
     * @param list<string>               $numbers
     * @param list<string>               $moneyInMetadata scale-4 decimals found inside an audit entry's opaque `metadata`
     */
    private static function walk(array $body, string $path, array &$into, array &$numbers, array &$moneyInMetadata = []): void
    {
        foreach ($body as $key => $value) {
            $here = \is_int($key) ? $path.'[]' : $path.'.'.$key;

            if (\is_array($value)) {
                self::walk($value, $here, $into, $numbers, $moneyInMetadata);
                continue;
            }

            // §4.4.3 (audit reads C1, the M-3 ruling): an audit entry's `metadata` is OPAQUE — outside API-23c, so nothing
            // under `$.items[].metadata` is recorded into the artefact (its decimals are the writers', at any scale). **But
            // it never carries a money amount (PC-12; final RED M-2):** a scale-4 decimal there would make the trail a margin
            // source `MarginSourcesDerivationTest` cannot see, so it is collected here and refused by the caller.
            if (str_starts_with($here, '$.items[].metadata.') || '$.items[].metadata' === $here) {
                if (\is_string($value) && 1 === preg_match(self::DECIMAL, $value, $parts) && 4 === \strlen($parts[1] ?? '')) {
                    $moneyInMetadata[] = $here;
                }
                if (\is_float($value)) {
                    $numbers[] = $here;
                }
                continue;
            }

            if (\is_float($value)) {
                $numbers[] = $here;
                continue;
            }

            if (!\is_string($value) || 1 !== preg_match(self::DECIMAL, $value, $parts)) {
                continue;
            }

            $scale = \strlen($parts[1] ?? '');
            $into[$here] = $into[$here] ?? [];
            if (!\in_array($scale, $into[$here], true)) {
                $into[$here][] = $scale;
                sort($into[$here]);
            }
        }
    }
}
