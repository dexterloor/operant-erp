<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

/**
 * **Which tables have a History, and which read code opens it** — `ListAuditRecords`' `subject_type` domain and the E-5
 * second check's map (audit reads C2; `docs/session13/audit-reads-design.md` §4.1 and R1.5; final RED M-4, L-3, L-10).
 *
 * **Written out, never computed as `"{subject_type}.view"`** (`SEC` R-7, `g29-g30-sec.md`): no `credit_note.view`,
 * `customer_contact.view` or `customer_address.view` exists, so the naive form fails closed and finance's credit-note
 * History is empty. PC-9's mapping applies instead — a child read on its parent's code.
 *
 * **The domain** (final RED M-4): the subject types of `AuditEvent::BUSINESS` ∪ the tables of every built item-`GET` that
 * takes an `{id}`, minus the named exclusion `role_grant_proposal` (its read is gated `role.assign`, PC-16 / §5.2.3 —
 * pending privilege history is never a `.view` holder's). `app_user` and `role` are inside it (`GetUser`, `GetRole`; L-3):
 * every `role.*` event is a SECURITY event, so their History is always an empty page — never a 422, so a detail page's
 * History section renders empty rather than broken (`STATES.md`).
 *
 * `RecordHistorySubjectsTest` holds this map to that domain (equal keys), every value to a catalogue `.view` code, every
 * PC-9 table to PC-9's parsed read code, and every table with an item `GET` to that `GET`'s own gate — so under this
 * model's code-level permissions (H-3 (ii)) "may read its History" and "may open it" are one test.
 */
final class RecordHistorySubjects
{
    /** @var array<string, string> subject table => the read code the caller must hold besides `audit_log.view_record` */
    public const READ_CODES = [
        'app_user' => 'app_user.view',
        // PC-9: a credit note is money, read under `invoice.view` (owner ruling Q-B) — never a `credit_note.view`.
        'credit_note' => 'invoice.view',
        'customer' => 'customer.view',
        // PC-9: a customer's children are read under `customer.view`.
        'customer_address' => 'customer.view',
        'customer_contact' => 'customer.view',
        'fulfilment' => 'fulfilment.view',
        'goods_receipt' => 'goods_receipt.view',
        'inventory_item' => 'inventory_item.view',
        'invoice' => 'invoice.view',
        'purchase_order' => 'purchase_order.view',
        'role' => 'role.view',
        'sales_order' => 'sales_order.view',
        'sales_return' => 'sales_return.view',
        'stock_movement' => 'stock_movement.view',
        'storage_location' => 'storage_location.view',
        'supplier' => 'supplier.view',
        'warehouse' => 'warehouse.view',
    ];

    /**
     * `READ_CODES`' keys, as a constant — PHP refuses a function call in an attribute, and `?subject_type`'s enum is
     * declared in one. `RecordHistorySubjectsTest::theDeclaredEnumIsTheMapsKeys` holds the two equal.
     *
     * @var list<string>
     */
    public const TYPES = ['app_user', 'credit_note', 'customer', 'customer_address', 'customer_contact', 'fulfilment',
        'goods_receipt', 'inventory_item', 'invoice', 'purchase_order', 'role', 'sales_order', 'sales_return', 'stock_movement',
        'storage_location', 'supplier', 'warehouse'];

    /** Not instantiable: a map, not a service. */
    private function __construct()
    {
    }

    /** The code the caller must hold to read `$subjectType`'s History, or null when the type has none. */
    public static function readCodeOf(string $subjectType): ?string
    {
        return self::READ_CODES[$subjectType] ?? null;
    }
}
