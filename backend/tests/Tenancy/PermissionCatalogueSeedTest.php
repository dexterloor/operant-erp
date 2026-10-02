<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\UserManagement\Domain\PermissionCatalogue;
use PHPUnit\Framework\Attributes\Test;

/**
 * Output 16 deliverable A — the seeded `permission` catalogue, reconciled against the
 * committed one.
 *
 * ---------------------------------------------------------------------------
 * What this class is for
 * ---------------------------------------------------------------------------
 * `Version20260918000100` writes `SECURITY_MODEL.md` §3's 60 codes into `permission`
 * as 60 **literal** rows, and deliberately does not import `PermissionCatalogue`. That
 * leaves two independent copies of the same fact — a frozen snapshot in a migration and
 * a live class in `src/` — which is exactly the arrangement this project has been burned
 * by whenever nothing compared them. **This class is the comparison.** It is the reason
 * the migration is allowed to hold literals at all: a source that cannot drift cannot be
 * reconciled, and a generated migration would make every assertion below compare the
 * catalogue to itself through one extra indirection.
 *
 * S-17 and ADR-002 Amendment 1 §A1.5: **an exhaustive check derives its domain from the
 * server.** Every method here reads `permission` back out of the database as the owner
 * and takes the answer from the rows, never from the migration file and never from a
 * list restated here.
 *
 * ---------------------------------------------------------------------------
 * SEC-O-11's live half
 * ---------------------------------------------------------------------------
 * `ROADMAP.md`'s M1 entry criterion fails if *"`permission` rows exist while
 * `Permission.php` documents a format the seeder did not use"*. That docblock documents
 * the `module` value as namespace-shaped and capitalised — `Modules\Purchasing`,
 * `Platform\Tenancy`, never lowercase — under PC-1a.
 * `everySeededModuleIsNamespaceShapedAndOneOfSectionThrees` is the gate, and it is the
 * half that could not be written before rows existed.
 *
 * **The domain is §3's eleven modules and not `SchemaManifest`'s.** The manifest
 * describes tables; two of §3's modules — `Modules\Analytics` and `Modules\Ai` — own no
 * table and appear nowhere in it, so a check written against the manifest would fail on
 * `forecast.view` and `ai_assistant.use`, which are correct rows. The domain therefore
 * comes from `PermissionCatalogue`, which is §3's committed copy, plus the shape rule
 * and the size pinned as a literal below.
 *
 * ---------------------------------------------------------------------------
 * The pins, and why literals are right here
 * ---------------------------------------------------------------------------
 * Rule 15 forbids restating a derived figure in prose. A **test file** is the one place
 * this project permits a literal count, because there it is a pin the code is measured
 * against rather than a figure the code feeds itself: `PermissionCatalogue` supplying
 * both sides of its own size check would be green on an emptied catalogue.
 *
 * ---------------------------------------------------------------------------
 * The fixtures that also write this table
 * ---------------------------------------------------------------------------
 * `permission` is reference data and nothing truncates it between tests, so what this
 * class reads is the seed **plus anything a fixture added earlier in the run**. Three
 * fixtures insert into it — `ApiTestCase::seedPrincipal`, `CurrentUserTest::seedPermission`
 * and one statement in `PermissionEnforcementTest` — each with `ON CONFLICT (code) DO
 * NOTHING`, so none of them can overwrite a seeded row. They only ever insert codes the
 * API declares, and the declared set is itself pinned to §3, so today they add nothing.
 * If one of them ever inserts a code that is not in the catalogue, the equality check
 * below goes red naming it — and that is the correct outcome, not a flake: a permission
 * code that exists only in a fixture belongs in §3, in `PermissionCatalogue` and in a
 * further forward migration.
 */
final class PermissionCatalogueSeedTest extends TenancyTestCase
{
    /**
     * §3's own heading states the total; `PermissionCatalogue`'s docblock refuses to
     * carry it. Pinned here so an emptied catalogue cannot pass by agreeing with an
     * emptied table.
     */
    // 62 since 2026-10-01 (sales step 7 R2): `sales_return.view` and `sales_return.create`, by forward migration
    // `Version20261001000300`, never by editing the seed in place.
    // 63 since 2026-10-01 (sales step 7 C1): `credit_note.issue` (`Version20261001000700`), with A2.1b and SoD-5.
    // 64 since 2026-10-01 (sales step 7 C1b): `credit_note.adjust` (`Version20261001000800`), with A2.1c.
    private const PINNED_CODE_COUNT = 64;

    /** §3's Module column, distinct. Two of them own no table (`Analytics`, `Ai`). */
    private const PINNED_MODULE_COUNT = 11;

    #[Test]
    public function theCommittedCatalogueIsStillTheSizeSectionThreePublishes(): void
    {
        // The positive control for everything below: both halves of every comparison in
        // this class run through PermissionCatalogue, so an emptied catalogue would make
        // an equality check green against an empty table. This is the assertion that
        // cannot.
        self::assertCount(self::PINNED_CODE_COUNT, PermissionCatalogue::codes());
        self::assertCount(self::PINNED_MODULE_COUNT, PermissionCatalogue::modules());
    }

    #[Test]
    public function theSeededCodesAreExactlyTheCommittedCatalogue(): void
    {
        $seeded = $this->seededCodes();
        $committed = PermissionCatalogue::codes();
        sort($committed);

        self::assertSame(
            $committed,
            $seeded,
            'The rows in `permission` and PermissionCatalogue disagree. Missing from the '
            .'database: '.implode(', ', array_diff($committed, $seeded)).'. Present in the '
            .'database but not in the catalogue: '.implode(', ', array_diff($seeded, $committed))
            .'. Adding a permission is three steps — SECURITY_MODEL.md §3, PermissionCatalogue, '
            .'and a further forward migration; editing Version20260918000100 in place is '
            .'invisible to every database that has already run it.',
        );
    }

    #[Test]
    public function everySeededCodeCarriesTheModuleItsCatalogueEntryDeclares(): void
    {
        $mismatched = [];

        foreach ($this->seededRows() as $code => $row) {
            $expected = PermissionCatalogue::moduleOf($code);

            if ($expected !== $row['module']) {
                $mismatched[] = sprintf('%s: seeded as `%s`, catalogued as `%s`', $code, $row['module'], $expected ?? 'nothing');
            }
        }

        self::assertSame([], $mismatched, 'Seeded `module` values that disagree with PermissionCatalogue: '.implode('; ', $mismatched));
    }

    /**
     * SEC-O-11's live half, and PC-1a's shape rule.
     *
     * Two assertions, because they fail on different things: the regex catches a
     * lowercase or flattened value that happens to be in the catalogue's domain by
     * accident, and the domain check catches a namespace-shaped value that is not one
     * of §3's eleven.
     */
    #[Test]
    public function everySeededModuleIsNamespaceShapedAndOneOfSectionThrees(): void
    {
        $modules = $this->seededModules();

        self::assertCount(self::PINNED_MODULE_COUNT, $modules, 'The seeded rows use '.\count($modules).' distinct module values: '.implode(', ', $modules));

        foreach ($modules as $module) {
            // `Modules\Inventory`, `Platform\Tenancy`, `SharedKernel\Reference` — one
            // backslash, both halves capitalised, nothing lowercase and nothing bare.
            self::assertMatchesRegularExpression(
                '/^(Modules|Platform|SharedKernel)\\\\[A-Z][A-Za-z]*$/',
                $module,
                sprintf('`permission.module` = `%s` is not the namespace-shaped form Permission.php documents (PC-1a).', $module),
            );
            self::assertContains(
                $module,
                PermissionCatalogue::modules(),
                sprintf('`permission.module` = `%s` is not one of SECURITY_MODEL.md §3\'s eleven modules.', $module),
            );
        }
    }

    /**
     * The description rule, checked by reversing it.
     *
     * `Version20260918000100` derives every description from its own code —
     * `inventory_item.view` -> `inventory item: view` — and the rule is lossless because
     * no resource and no verb contains a space. Reconstructing the code from the
     * description is therefore a total check on all sixty at once, and it fails on a
     * description that was hand-edited to describe something else.
     */
    #[Test]
    public function everySeededDescriptionReconstructsItsOwnCode(): void
    {
        $wrong = [];

        foreach ($this->seededRows() as $code => $row) {
            $halves = explode(': ', $row['description']);
            $reconstructed = 2 === \count($halves)
                ? str_replace(' ', '_', $halves[0]).'.'.str_replace(' ', '_', $halves[1])
                : '';

            if ($reconstructed !== $code) {
                $wrong[] = sprintf('%s: description `%s` reads back as `%s`', $code, $row['description'], $reconstructed);
            }
        }

        self::assertSame([], $wrong, 'Descriptions that do not derive from their own code: '.implode('; ', $wrong));
    }

    /** @return list<string> every seeded code, sorted */
    private function seededCodes(): array
    {
        $codes = array_keys($this->seededRows());
        sort($codes);

        return $codes;
    }

    /** @return list<string> the distinct `module` values the server holds, sorted */
    private function seededModules(): array
    {
        /** @var list<string> $modules */
        $modules = $this->owner()->fetchFirstColumn('SELECT DISTINCT module FROM permission ORDER BY 1');

        return $modules;
    }

    /**
     * Read as the owner, from the server, with no predicate — the domain is whatever
     * `permission` actually holds, which is the only way any of this is exhaustive.
     *
     * @return array<string, array{module: string, description: string}>
     */
    private function seededRows(): array
    {
        $rows = [];

        foreach ($this->owner()->fetchAllAssociative('SELECT code, module, description FROM permission') as $row) {
            $rows[(string) $row['code']] = ['module' => (string) $row['module'], 'description' => (string) $row['description']];
        }

        return $rows;
    }
}
