<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Security\PermissionGate;
use App\Tests\Support\ContractDocument;
use App\Tests\Support\SecurityModelDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * §4.2's `Perm` column, reconciled against what the application declares — and
 * against §3's catalogue, which is where the codes come from.
 *
 * ---------------------------------------------------------------------------
 * Three separate claims, three separate ways to go red
 * ---------------------------------------------------------------------------
 *   1. **Every operation declares.** The registry is the domain; an operation
 *      with no declaration fails here rather than reaching production and
 *      raising a 500 on its first request.
 *   2. **What it declares is §4.2's cell.** Checked in both directions wherever
 *      the document is present, through the `PINNED_` mechanism §12.3 uses —
 *      `docs/` is gitignored, so the pin is what gives a clean checkout a
 *      subject.
 *   3. **The code exists.** A `Perm` cell naming a code §3's catalogue does not
 *      hold is a permission nobody can be granted, so the operation is
 *      unreachable by anyone — an outage that looks exactly like correct
 *      enforcement.
 *
 * `PermissionEnforcementTest` is the behavioural half: it drives every declared
 * code over HTTP. This file is about whether the declarations are the right ones.
 */
final class PermissionEnforcementContractTest extends ApiTestCase
{
    /**
     * §4.2's `Perm` cell for every operation this wave ships, transcribed once.
     * `null` is the column's `—`.
     *
     * @var array<string, string|null>
     */
    public const PINNED_PERMISSIONS = [
        'IssueToken' => null,
        'GetCurrentUser' => null,
        // §4.2's `—`, ruled on `PasswordResource`: §3's catalogue is closed at
        // sixty codes and none of them fits. `app_user.edit` is the near miss and
        // is the administrative code behind `UpdateUser`, held by `owner` and
        // `admin` alone — requiring it would stop most seats changing their own
        // password. The operation is self-service by construction instead: no
        // request field names a subject and the actor comes from the token.
        'ChangePassword' => null,
        'ListItems' => 'inventory_item.view',
        'GetItem' => 'inventory_item.view',
        // Sales step 5 C4 (2026-09-22): SoD-4 -- `fulfilment.create` is held by `owner` and `warehouse_operator` only.
        'CreateFulfilment' => 'fulfilment.create',
        'GetFulfilment' => 'fulfilment.view',
        // Sales step 6, C3: the invoice read and its issue (SoD-4: `invoice.issue` is never `warehouse_operator`'s).
        'GetInvoice' => 'invoice.view',
        'IssueInvoice' => 'invoice.issue',
        'ListInvoices' => 'invoice.view',
        'PayInvoice' => 'invoice.pay',
        'ReverseInvoicePayment' => 'invoice.pay',
        // Sales step 6 C1 (2026-09-23): the register, the row's own `Perm` cell. `warehouse_operator` holds it.
        'ListFulfilments' => 'fulfilment.view',
        // Sales step 5 C3 (2026-09-22): the pick read, `fulfilment.view` ALONE (V2-S-1b clause 3, owner ruling R7).
        'GetPickList' => 'fulfilment.view',
        // Roles slice 1 (2026-09-23): the three role reads are `role.view`, which `viewer` holds -- so nothing
        // they serve may carry privilege history (PC-16 condition 1, IA §6.8).
        'ListPermissions' => 'role.view',
        'GetRole' => 'role.view',
        'ListRoles' => 'role.view',
        'CreateItem' => 'inventory_item.create',
        'UpdateItem' => 'inventory_item.edit',
        'DeleteItem' => 'inventory_item.delete',
        'RestoreItem' => 'inventory_item.delete',
        // §4.2's Purchasing block, added 2026-09-10. Every one is the row's own
        // `Perm` cell. Two are worth reading rather than scanning:
        // `ApprovePurchaseOrder` is the only operation in the tree requiring
        // `purchase_order.approve`, which is SoD-1's controlled half and the code
        // `buyer` does not hold; and the three line operations require
        // `purchase_order.edit` rather than a `purchase_order_line.*` code,
        // because §3's catalogue has none — a line is not a resource anybody is
        // separately entitled to.
        'ListPurchaseOrders' => 'purchase_order.view',
        'GetPurchaseOrder' => 'purchase_order.view',
        // Added 2026-09-11. `purchase_order.create` is SoD-1's *create* side —
        // the code `buyer` holds and `purchasing_manager` also holds, which is
        // why DSoD-1 exists on top of it rather than instead of it — and
        // `.cancel` is the second code of SoD-1's **controlled** half, moved
        // there by ADR-008's third pass and given its dynamic check by
        // Amendment 1.
        'CreatePurchaseOrder' => 'purchase_order.create',
        'CreateSalesOrder' => 'sales_order.create',
        // Its own code, not `.edit`: disposing of a draft is `DeletePurchaseOrder`'s act one module over.
        // `sales_rep` holds it (`DefaultRoles`), and no DSoD rule applies -- ADR-008 §2 declines the sales duty
        // split, and a draft is not a commitment. Unattributed: `RESIDUAL_GAPS.md` G-9.
        'DeleteSalesOrder' => 'sales_order.delete',
        // `sales_order.edit`, not a code of its own: a line is part of the order, and §4.2 gives every
        // line write the parent's edit permission -- the purchase twin's rule.
        'CreateSalesOrderLine' => 'sales_order.edit',
        'UpdateSalesOrderLine' => 'sales_order.edit',
        'DeleteSalesOrderLine' => 'sales_order.edit',
        'RestoreSalesOrderLine' => 'sales_order.edit',
        // Added 2026-09-19 with the read. `sales_order.view` is the row's own `Perm` cell and it is the
        // only code this operation wants: `created_by`'s expansion needs no `app_user.view` (PC-15
        // condition 2, ruled for this field by B1N-2 on PC-11 (4a) ground) and neither customer nor
        // warehouse reference needs its own `.view` (PC-15 clause 4).
        'GetSalesOrder' => 'sales_order.view',
        // Added 2026-09-19. `sales_order.confirm` is the row's own `Perm` cell and a code of its own
        // rather than `.edit`: SoD-4 controls it against the movement codes (`SECURITY_MODEL.md` §4's
        // pair table), so an operation that confirmed under `.edit` would put a revenue-commitment
        // capability inside an editing one and quietly dissolve that pair. **No DSoD rule applies** —
        // ADR-008 names none on any sales document, and `sales_order.created_by_user_id` records the
        // creator, not the confirmer, so one could not be expressed on the columns that exist.
        'ConfirmSalesOrder' => 'sales_order.confirm',
        // Added 2026-09-19 with the operation. `sales_order.cancel` is the row's own `Perm` cell and a
        // code of its own in §3's catalogue. ADR-008 §2 records that `sales_rep` holds it **alongside**
        // `sales_order.confirm`, deliberately and asymmetrically with the buying side — so no
        // separation-of-duty check sits behind this code, and the audit row is the compensation.
        'CancelSalesOrder' => 'sales_order.cancel',
        // Added 2026-09-21 with the bounded sub-list. `sales_order.view` is the row's own `Perm` cell,
        // and it is the whole gate: the lines are read under the **parent document's** permission, not a
        // `sales_order_line.*` code — §3's catalogue mints none, because a line is not a document. The
        // expanded `inventory_item` needs no `inventory_item.view` either (PC-15 clause 4, the same
        // reading `GetSalesOrder`'s customer and warehouse references ride on).
        'ListSalesOrderLines' => 'sales_order.view',
        // Added 2026-09-21 with the document list. `sales_order.view` is the row's own `Perm` cell, and
        // the list returns the same document the direct hit does — including `created_by`, which rides on
        // owner ruling O-5 / PC-15 amendment 2 rather than extending it: §4.2 gates `GetSalesOrder` and
        // this row on the same code, so the disclosure condition is met identically.
        'ListSalesOrders' => 'sales_order.view',
        'CancelPurchaseOrder' => 'purchase_order.cancel',
        'UpdatePurchaseOrder' => 'purchase_order.edit',
        'DeletePurchaseOrder' => 'purchase_order.delete',
        'ApprovePurchaseOrder' => 'purchase_order.approve',
        'ListPurchaseOrderLines' => 'purchase_order.view',
        // Step 8 (2026-09-11), each the row's own `Perm` cell.
        // `goods_receipt.create` is SoD-2's controlled code, held by `owner` and
        // `warehouse_operator` only: whoever raised the order is not whoever
        // confirms the goods arrived. No dynamic rule (DSoD) applies to
        // receipts — ADR-008 and SECURITY_MODEL.md name DSoD-1 alone, on
        // `.approve` and `.cancel` — so the static split is the whole control.
        'CreateGoodsReceipt' => 'goods_receipt.create',
        'GetGoodsReceipt' => 'goods_receipt.view',
        'CreatePurchaseOrderLine' => 'purchase_order.edit',
        'UpdatePurchaseOrderLine' => 'purchase_order.edit',
        'DeletePurchaseOrderLine' => 'purchase_order.edit',
        // `sales_order.edit`, which is a seeded catalogue code -- measured, not assumed:
        // `SELECT code FROM permission WHERE code LIKE 'sales_order%'` returns
        // cancel, confirm, create, delete, edit, view.
        'UpdateSalesOrder' => 'sales_order.edit',
    ];

    /**
     * Claim 1. The domain is the registry and the pin has to cover all of it, so
     * an operation added without a `Perm` decision fails twice — once for having
     * no declaration, once for not being in the pin.
     *
     * *Mutation, run:* the `extraProperties` entry removed from `UpdateItem`. Red,
     * naming the operation. *Second mutation, run:* `PermissionGate`'s
     * `array_key_exists` guard changed to `?? null`, i.e. an absent declaration
     * silently meaning "no permission required". This test is still red — which is
     * the point of it being a separate check from the gate's own behaviour.
     */
    #[Test]
    public function everyOperationDeclaresItsRequiredPermission(): void
    {
        $declared = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $extra = $operation->getExtraProperties();

            self::assertArrayHasKey(PermissionGate::OPERATION_PERMISSION, $extra,
                $name.' declares no required permission. §4.2 gives every row a `Perm` cell, and an '
                .'operation that declares nothing is one nobody decided about — PermissionGate refuses '
                .'to guess, so this would be a 500 on its first request.');

            $code = $extra[PermissionGate::OPERATION_PERMISSION];
            self::assertTrue(null === $code || (\is_string($code) && '' !== $code),
                $name.' declares a required permission that is neither a code nor §4.2\'s `—`.');

            $declared[$name] = $code;
        }

        ksort($declared);
        $pinned = self::PINNED_PERMISSIONS;
        ksort($pinned);

        self::assertSame($pinned, $declared,
            'The registry and the pin disagree. Every shipped operation needs a reviewed `Perm` decision.');
    }

    /**
     * Claim 3, and it runs on **every** checkout because its domain is the code.
     *
     * A declared code must be a member of §3's catalogue. Where the document is
     * absent the check falls back to the shape §3's own rule fixes — `PC-1`'s
     * `<resource>.<action>` — which is weaker but is not nothing, and the
     * document-backed clause below is where the real comparison happens.
     */
    #[Test]
    public function everyDeclaredCodeIsShapedLikeACatalogueMember(): void
    {
        $examined = 0;

        foreach (self::everyDeclaredPermission() as $code) {
            ++$examined;
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $code,
                $code.' is not shaped like a §3 permission code.');
        }

        self::assertGreaterThan(0, $examined, 'No code was examined, so this assertion had no subject.');
    }

    /**
     * Claim 3, against the catalogue itself.
     *
     * The parser is checked against §3's own stated total before anything is
     * compared to it: a parser that found the wrong table, or stopped expanding
     * the `.suffix` shorthand, would otherwise turn this into a check that admits
     * everything it happens to have parsed.
     *
     * *Mutation, run:* `GetItem`'s declared code changed to
     * `inventory_item.read` — a plausible name §3 does not hold. Red.
     */
    #[Test]
    public function theCatalogueThisApiDrawsFromIsStillTheOneSecurityModelPublishes(): void
    {
        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored and absent from this checkout, so the declared codes are checked for shape only — by `everyDeclaredCodeIsShapedLikeACatalogueMember` in this file. §3\'s catalogue is the side that is unavailable here.');
        }

        $catalogue = SecurityModelDocument::permissionCodes();
        $stated = SecurityModelDocument::catalogueSizeFromTheHeading();

        self::assertNotNull($stated, '§3\'s heading no longer states a total, so the parser has nothing to be wrong against.');
        self::assertCount($stated, $catalogue,
            'The §3 parser produced '.\count($catalogue).' codes and the heading states '.$stated
            .'. Every comparison below would be against a catalogue that is not the catalogue.');

        foreach (self::everyDeclaredPermission() as $code) {
            self::assertContains($code, $catalogue,
                $code.' is declared by an operation and is not in §3\'s catalogue. Nobody can be granted it, '
                .'so the operation is unreachable by every caller — which looks exactly like correct enforcement.');
        }
    }

    /**
     * Claim 2 — the pin against the document, in both directions.
     *
     * *Mutation, run:* `DeleteItem`'s pin changed to `inventory_item.archive`;
     * red against §4.2's cell **and** red against the registry in
     * `everyOperationDeclaresItsRequiredPermission`, which is the two-sided
     * property a pin is for.
     */
    #[Test]
    public function thePinsMatchTheContractDocument(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout, so the pins above are checked against the code only — by `everyOperationDeclaresItsRequiredPermission` in this file, which holds `self::PINNED_PERMISSIONS` against the operation registry. §4.2\'s table is the side that is unavailable here.');
        }

        $rows = ContractDocument::operationRows();
        self::assertGreaterThan(50, \count($rows), 'The §4.2 parser found almost nothing, so every comparison below would be vacuous.');

        foreach (self::PINNED_PERMISSIONS as $operationId => $pinned) {
            self::assertArrayHasKey($operationId, $rows, $operationId.' is shipped and §4.2 declares no row for it.');

            $cell = $rows[$operationId]['perm'];

            self::assertSame($pinned ?? '—', $cell,
                $operationId.'\'s `Perm` cell is "'.$cell.'" and this suite enforces "'.($pinned ?? '—').'".');
        }
    }

    /**
     * §4.2's other rule about the same column, and it is the one a reader gets
     * wrong: `—` does **not** mean public.
     *
     * *"Whether it also requires a JWT is decided by the row's own `Path` …
     * `/auth/*` is pre-authentication; everything else requires a valid token."*
     * `GetCurrentUser` holds no code and is authenticated, and that pair is
     * exactly the case the sentence was written for.
     */
    #[Test]
    public function anOperationWithNoCodeIsNotThereforePublic(): void
    {
        $withoutACode = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            // `??` cannot be used to ask this question: it treats a declared
            // `null` as an absent key, which is precisely the distinction §4.2's
            // `—` turns on. `array_key_exists` is the whole reason the gate can
            // tell "no code required" from "nobody decided".
            $extra = $operation->getExtraProperties();

            if (!\array_key_exists(PermissionGate::OPERATION_PERMISSION, $extra)
                || null !== $extra[PermissionGate::OPERATION_PERMISSION]) {
                continue;
            }

            $path = (string) $operation->getUriTemplate();
            $path = str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path;
            $withoutACode[$name] = $path;
        }

        self::assertNotSame([], $withoutACode, 'No operation declares `—`, so this assertion had no subject.');
        self::assertArrayHasKey('GetCurrentUser', $withoutACode,
            'GetCurrentUser no longer declares `—`. It is the operation §4.2\'s sentence about `—` is written about.');

        // Driven rather than reasoned about: the one that is not under /auth/
        // still refuses an anonymous request.
        $this->actAsNobody();
        $this->send('GET', $withoutACode['GetCurrentUser']);

        self::assertSame(401, $this->httpStatus(),
            'An operation with no permission code was reachable without a token, so `—` is being read as "public".');
    }
}
