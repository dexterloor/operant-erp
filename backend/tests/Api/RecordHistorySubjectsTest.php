<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Application\RecordHistorySubjects;
use App\Modules\UserManagement\Domain\PermissionCatalogue;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Support\SecurityModelDocument;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **Audit reads C2 — the History subject map** (`RecordHistorySubjects`; `docs/session13/audit-reads-design.md` §4.1 and
 * R1.5; `audit-reads-final-red.md` M-4, L-3, L-10). No database: the registry and the documents.
 *
 *  - **The domain is derived, never typed** (M-4): `AuditEvent::BUSINESS`' subject types ∪ the tables of every built item
 *    `GET` taking an `{id}` (`DenialSubjectTest::builtItemGets()`, the same derivation), minus
 *    {@see self::EXCLUDED_ITEM_GETS}. The map's keys EQUAL it — a new History subject or a new item `GET` arrives here red
 *    until it is given a read code — and every remaining item `GET`'s gate ends `.view` (N14: drop the exclusion → red).
 *  - **Every value is a catalogue `.view` code**, and **every table with an item `GET` is read on that `GET`'s own gate**: under
 *    this model's code-level permissions (H-3 (ii)) "may read its History" is "may open it".
 *  - **PC-9 (R-7):** every table PC-9 names is read on PC-9's parsed read code, every other on `t.view`; one planted control
 *    per cell form (N12, N13).
 *  - **G-35's carrier** ({@see self::aCustomerAndItsChildrenAreReadOnCustomerView()}): `customer` resolves through PC-9 to
 *    `customer.view`, never a naive `"{subject_type}.view"` that a child would fail.
 */
final class RecordHistorySubjectsTest extends KernelTestCase
{
    /**
     * Built item `GET`s whose table is NOT a History subject, each with its reason — named, so a non-`.view` item `GET` cannot
     * be filtered out silently (final RED M-4).
     *
     * @var array<string, string> table => why its History is not served under a `.view` holder's code
     */
    private const EXCLUDED_ITEM_GETS = [
        'role_grant_proposal' => 'GetRoleGrantProposal is gated role.assign, never a .view code: pending privilege history is not a .view holder\'s '
            .'(SECURITY_MODEL.md §5.2.3, PC-16). No event subjects it (role.* events subject app_user). `SEC` TO CONFIRM the reason text.',
    ];

    #[Test]
    public function theMapsKeysAreTheDerivedDomain(): void
    {
        self::bootKernel();

        $business = [];
        foreach (AuditEvent::BUSINESS as $event) {
            $business[] = AuditEvent::SUBJECT_TYPES[$event] ?? self::fail($event.' is BUSINESS but declares no subject type.');
        }
        $gets = DenialSubjectTest::builtItemGets();
        self::assertArrayHasKey('GetItem', $gets, 'Control: the item-GET derivation does not see GetItem, so the domain is short.');

        $nonView = [];
        $getTables = [];
        foreach ($gets as $operation => ['table' => $table, 'gate' => $gate]) {
            if (isset(self::EXCLUDED_ITEM_GETS[$table])) {
                continue;
            }
            $getTables[] = $table;
            if (!str_ends_with($gate, '.view')) {
                $nonView[] = $operation.' ('.$table.') is gated '.$gate;
            }
        }
        self::assertSame([], $nonView, "A built item GET is gated on a code that is not a .view, so its table's History has no read code to check.\n"
            ."Name its table in EXCLUDED_ITEM_GETS with the reason, or gate it on a .view:\n  ".implode("\n  ", $nonView));
        foreach (array_keys(self::EXCLUDED_ITEM_GETS) as $table) {
            self::assertContains($table, array_column($gets, 'table'), 'EXCLUDED_ITEM_GETS names '.$table.', which no built item GET addresses: a dead entry.');
        }

        $domain = array_values(array_unique([...$business, ...$getTables]));
        sort($domain);
        $keys = array_keys(RecordHistorySubjects::READ_CODES);
        sort($keys);

        // Controls: each half of the union is really in it.
        self::assertContains('customer_contact', $domain, 'Control: a BUSINESS-only subject (no item GET) is missing — the event half is not read.');
        self::assertContains('inventory_item', $domain, 'Control: an item-GET-only table (no event) is missing — the GET half is not read.');
        self::assertNotContains('role_grant_proposal', $domain, 'The exclusion is not applied.');

        self::assertSame($domain, $keys, 'RecordHistorySubjects::READ_CODES is not exactly the History subject domain (final RED M-4).');
    }

    #[Test]
    public function everyReadCodeIsACatalogueViewCode(): void
    {
        foreach (RecordHistorySubjects::READ_CODES as $table => $code) {
            self::assertTrue(PermissionCatalogue::has($code), $table.' is read on '.$code.', which is not in the catalogue.');
            self::assertStringEndsWith('.view', $code, $table.'\'s History is read on a write code.');
        }
    }

    /** Code-level (H-3 (ii)): the code that reads a table's History is the code that opens the table's row. */
    #[Test]
    public function aTableWithAnItemGetIsReadOnThatGetsOwnGate(): void
    {
        self::bootKernel();

        $checked = 0;
        foreach (DenialSubjectTest::builtItemGets() as $operation => ['table' => $table, 'gate' => $gate]) {
            if (isset(self::EXCLUDED_ITEM_GETS[$table])) {
                continue;
            }
            self::assertSame($gate, RecordHistorySubjects::readCodeOf($table), \sprintf('%s opens %s on %s; its History must be read on the same code.', $operation, $table, $gate));
            ++$checked;
        }
        self::assertGreaterThan(10, $checked, 'Control: almost no item GET was compared.');
        self::assertSame('invoice.view', RecordHistorySubjects::readCodeOf('credit_note'), 'Control: GetCreditNote (invoice.view) is among them.');
    }

    /** PC-9's map (R-7, L-10): the document's read code for every child table it names, `t.view` for every other. */
    #[Test]
    public function everyPc9TableIsReadOnPc9sReadCode(): void
    {
        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored and absent.');
        }

        $pc9 = SecurityModelDocument::pc9ReadCodes();
        // One planted control per cell form (L-10): `x.*`, `read:`, a `/`-list, a write-only row.
        self::assertSame('purchase_order.view', $pc9['purchase_order_line'] ?? 'missing', 'Control (x.*): the parse no longer reads PC-9\'s table.');
        self::assertSame('invoice.view', $pc9['credit_note'] ?? 'missing', 'Control (read:): the parse no longer reads PC-9\'s table.');
        self::assertSame('customer.view', $pc9['customer_contact'] ?? 'missing', 'Control (/-list): the parse no longer reads PC-9\'s table.');
        self::assertArrayHasKey('user_role', $pc9, 'Control (write-only): user_role is not parsed at all.');
        self::assertNull($pc9['user_role'], 'Control (write-only): user_role names only role.assign, a write code, so it has no read code.');

        foreach (RecordHistorySubjects::READ_CODES as $table => $code) {
            $expected = \array_key_exists($table, $pc9) ? $pc9[$table] : $table.'.view';
            self::assertSame($expected, $code, \sprintf('%s is read on %s; %s says %s.', $table, $code, \array_key_exists($table, $pc9) ? 'PC-9' : 'the default rule (t.view)', (string) $expected));
        }
    }

    /**
     * **G-35's carrier for `ListAuditRecords`** (the implication in `OwedAmendmentTripwireTest`, converted at C2 from the
     * pin's `ListAuditRecords` key): a customer, and its contacts and addresses, are read on `customer.view` through PC-9 —
     * never `"{subject_type}.view"`, which a child would fail closed.
     */
    #[Test]
    public function aCustomerAndItsChildrenAreReadOnCustomerView(): void
    {
        self::assertSame('customer.view', RecordHistorySubjects::readCodeOf('customer'));
        self::assertSame('customer.view', RecordHistorySubjects::readCodeOf('customer_contact'));
        self::assertSame('customer.view', RecordHistorySubjects::readCodeOf('customer_address'));
        self::assertFalse(PermissionCatalogue::has('customer_contact.view'), 'Control: the naive code must not exist, or this pin proves nothing.');
    }

    /** `?subject_type`'s declared enum is the map's keys (PHP refuses a call in an attribute, so it is a constant). */
    #[Test]
    public function theDeclaredEnumIsTheMapsKeys(): void
    {
        self::bootKernel();

        self::assertSame(array_keys(RecordHistorySubjects::READ_CODES), RecordHistorySubjects::TYPES);

        $operation = TransactionBoundaryTest::contractOperations()['ListAuditRecords'] ?? self::fail('ListAuditRecords is not in the registry.');
        $parameter = $operation->getParameters()?->get('subject_type', \ApiPlatform\Metadata\QueryParameter::class);
        self::assertNotNull($parameter, 'ListAuditRecords declares no subject_type parameter.');
        self::assertSame(RecordHistorySubjects::TYPES, $parameter->getSchema()['enum'] ?? null);
    }
}
