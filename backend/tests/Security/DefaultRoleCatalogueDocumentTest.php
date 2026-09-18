<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Modules\UserManagement\Domain\AdministrativeCharter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Modules\UserManagement\Domain\PermissionCatalogue;
use App\Modules\UserManagement\Domain\SeparationOfDuty;
use App\Tests\Support\Adr008Document;
use App\Tests\Support\SecurityModelDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **The committed catalogue against the documents it is a copy of.**
 *
 * ---------------------------------------------------------------------------
 * Why this is a second file and not four more methods in the D2 port
 * ---------------------------------------------------------------------------
 * `SeparationOfDutyTest` discharges ADR-008 **D2**, which refuses *"a test that
 * fails on a fresh checkout (because it reads gitignored `docs/`), or one that
 * skips into a green"*. It therefore reads no document at all. This file reads
 * three sections of two documents and **skips** where they are absent, which is
 * the established pattern for a contract check — and it is kept apart so that a
 * green banner on a clean checkout is never the D2 port skipping.
 *
 * **Losing this file costs a cross-check, not the rule.** The rules are enforced
 * in `SeparationOfDutyTest` from committed code. What is enforced *here* is that
 * the committed code still says what `SECURITY_MODEL.md` §3, §4 and ADR-008 §2
 * say — which is this project's most-filed defect: a fix landing while a
 * neighbouring copy of the same fact goes on asserting the opposite.
 *
 * ---------------------------------------------------------------------------
 * It closes `sod_check.py`'s recorded limit 2
 * ---------------------------------------------------------------------------
 * That script's docstring: its check-back is `(a|b) <= expanded`, **a subset
 * test**, so *"extra tokens in the DOCUMENT are invisible to it … if A2.1 lands
 * in §2 and SOD here is not updated, this script still prints 'SoD encoding
 * checked back … OK'. CONSEQUENCE: this script cannot enforce A2.6's same-pass
 * clause, and cannot detect that A2.1 has entered §2 — the two conditions it is
 * most often cited for. A superset or equality test over the classified tokens
 * is what would close it."*
 *
 * `theCommittedSodTableIsAdr008Section2RowForRowAndHalfForHalf` is that equality
 * test, and it is taken **per half** rather than over the union, so a row written
 * the other way round fails too — which an equality test over `(a|b)` would still
 * miss and which PC-14 would silently invert.
 */
final class DefaultRoleCatalogueDocumentTest extends TestCase
{
    #[Test]
    public function theCommittedCatalogueIsSection3sCatalogueModuleForModule(): void
    {
        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored and absent.');
        }

        $published = SecurityModelDocument::catalogueByModule();

        self::assertNotSame([], $published, '§3s module table parsed to nothing, so this clause had no subject.');

        $committed = [];

        foreach (PermissionCatalogue::modules() as $module) {
            $committed[$module] = PermissionCatalogue::codesInModule($module);
        }

        self::assertSame(
            $published,
            $committed,
            'The committed catalogue and §3 disagree. PC-1a makes the module column the `permission.module` '
            .'value verbatim, so a difference here is a difference in the column, not in formatting.',
        );

        self::assertSame(
            SecurityModelDocument::catalogueSizeFromTheHeading(),
            \count(PermissionCatalogue::codes()),
            '§3s own heading states a total the committed catalogue does not match.',
        );
    }

    #[Test]
    public function theCommittedDefaultRolesAreSection4sRows(): void
    {
        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored and absent.');
        }

        $published = SecurityModelDocument::enumeratedRoles();

        self::assertNotSame([], $published, '§4s table parsed to no role at all.');

        foreach ($published as $name => $row) {
            self::assertNotSame([], $row['codes'], 'Role `'.$name.'` parsed out of §4 with no code.');
            self::assertCount(
                $row['count'],
                $row['codes'],
                'Row `'.$name.'` states '.$row['count'].' and lists '.\count($row['codes']).'. The document disagrees with itself.',
            );

            self::assertTrue(DefaultRoles::isDefault($name), '§4 publishes a role `'.$name.'` the committed catalogue does not carry.');

            $expected = $row['codes'];
            sort($expected);

            $actual = DefaultRoles::codesFor($name);
            sort($actual);

            self::assertSame($expected, $actual, 'Role `'.$name.'` differs between §4 and the committed catalogue.');
        }

        // §4's two derived rows are stated in prose, as sizes. The sets are
        // reconciled through PC-14 instead — see the last clause in this file.
        foreach ([DefaultRoles::OWNER, DefaultRoles::ADMIN] as $derived) {
            $stated = SecurityModelDocument::derivedRoleSize($derived);

            self::assertNotNull($stated, '§4 no longer states a size for `'.$derived.'`, so this clause stopped checking one.');
            self::assertCount($stated, DefaultRoles::codesFor($derived), '`'.$derived.'` is not the size §4 states.');
        }

        // And the two derived rows plus the six enumerated ones are all eight,
        // so a ninth role added to §4 cannot pass unnoticed.
        self::assertCount(
            \count(DefaultRoles::names()) - 2,
            $published,
            '§4s table and the committed catalogue disagree about how many roles are enumerated.',
        );
    }

    #[Test]
    public function theCommittedSodTableIsAdr008Section2RowForRowAndHalfForHalf(): void
    {
        if (!Adr008Document::isAvailable()) {
            self::markTestSkipped('docs/adr/ADR-008-separation-of-duty-and-role-expansion.md is gitignored and absent.');
        }

        $published = Adr008Document::separationOfDutyRows();

        self::assertNotSame([], $published, '§2s table parsed to no row at all.');
        self::assertSame(
            SeparationOfDuty::rowIds(),
            array_keys($published),
            'The committed table and §2 carry different rows. A row added to §2 without this class moving is '
            .'exactly what `sod_check.py`s subset check-back cannot see.',
        );

        foreach ($published as $id => $halves) {
            self::assertNotSame([], $halves['preparing'], $id.' parsed out of §2 with no preparing half.');
            self::assertNotSame([], $halves['controlled'], $id.' parsed out of §2 with no controlled half.');

            self::assertSame(
                $halves['preparing'],
                SeparationOfDuty::preparingHalf($id),
                $id."'s preparing half differs between §2 and the committed table.",
            );
            self::assertSame(
                $halves['controlled'],
                SeparationOfDuty::controlledHalf($id),
                $id."'s controlled half differs between §2 and the committed table. This is the side PC-14 reads, "
                .'so a difference here moves X(admin).',
            );
        }
    }

    #[Test]
    public function xAdminIsWhatPc14sDerivationYieldsOverTheDocumentsOwnTable(): void
    {
        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored and absent.');
        }
        if (!Adr008Document::isAvailable()) {
            self::markTestSkipped('docs/adr/ADR-008-separation-of-duty-and-role-expansion.md is gitignored and absent.');
        }

        $published = Adr008Document::separationOfDutyRows();

        self::assertNotSame([], $published, '§2s table parsed to no row, so X(admin) would be the marker alone.');

        // PC-14, run over the DOCUMENT rather than over the artifact: the
        // controlled half of every row, plus E-15b's marker.
        $derived = [];

        foreach ($published as $halves) {
            foreach ($halves['controlled'] as $code) {
                if (!\in_array($code, $derived, true)) {
                    $derived[] = $code;
                }
            }
        }

        self::assertNotSame([], $derived, 'No controlled half in §2 at all.');

        $derived[] = OwnerMarker::CODE;
        sort($derived);

        $committed = AdministrativeCharter::excludedCodes();
        sort($committed);

        self::assertSame($committed, $derived, 'X(admin) derived over §2 differs from X(admin) derived over the committed table.');

        // The arithmetic §4 states out loud — `60 − 6 = 54` — checked against the
        // derivation rather than against a second literal.
        self::assertSame(
            SecurityModelDocument::derivedRoleSize(DefaultRoles::ADMIN),
            \count(PermissionCatalogue::codes()) - \count($derived),
            '§4s stated `admin` size is not the catalogue minus X(admin) as §2 and E-15b define it.',
        );
    }
}
