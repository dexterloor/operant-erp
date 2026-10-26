<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\UserManagement\ApiResource\IssueTokenInput;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Tests\Api\ApiTestCase;
use App\Tests\Api\TransactionBoundaryTest;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * §4.2's `/auth/token` and `/me` rows, reconciled against what the application
 * declares — and, unlike `ItemOperationContractTest`, **the whole registry**.
 *
 * ---------------------------------------------------------------------------
 * Why the whole-registry pin moved here
 * ---------------------------------------------------------------------------
 * `ItemOperationContractTest::theRegistryDeclaresExactlyThePinnedOperations`
 * compared the **entire** operation registry against its six `/items` rows, so
 * it was two claims at once: *"these six are declared correctly"* and *"nothing
 * else is declared at all"*. The second is no longer that file's to make — this
 * wave adds two operations — and it must not simply be dropped, because *"an
 * operation appeared that nobody pinned"* is exactly what a per-resource pin
 * cannot see. So that method's domain is narrowed to its own path prefix,
 * matching its document-side counterpart which already filtered that way, and
 * the total is asserted **here**, over every shipped operation.
 *
 * `docs/` is gitignored, so each pin is checked against the code on every run and
 * against §4.2 only where the document is present — the mechanism §12.3 already
 * uses.
 */
final class AuthOperationContractTest extends ApiTestCase
{
    /**
     * §4.2's Authentication rows this wave ships. `RefreshToken` and
     * `RevokeSession` are **absent on purpose**: D-14 rules both out, and
     * `theShippedSurfaceIsExactly…` below asserts their absence rather than
     * leaving it implied.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    private const PINNED_AUTH_OPERATIONS = [
        'IssueToken' => ['method' => 'POST', 'path' => '/auth/token', 'idem' => false],
        'GetCurrentUser' => ['method' => 'GET', 'path' => '/me', 'idem' => false],
        // E-23, added 2026-09-09. `idem: false` is §4.2's `Idem: no` and is a
        // decision rather than an omission: a replay refuses itself, because the
        // second call presents a `current_password` that is no longer current.
        'ChangePassword' => ['method' => 'POST', 'path' => '/me/password', 'idem' => false],
        // Mail-C1, 2026-10-23. `idem: false`: a replay mails at most once more, under the worker's per-person cap; the
        // envelope store is post-authentication only.
        'CreatePasswordReset' => ['method' => 'POST', 'path' => '/auth/password-resets', 'idem' => false],
        // Mail-C2, 2026-10-24. `idem: false`: a replay refuses itself (the link is used); the envelope store is
        // post-authentication only.
        'CreatePasswordResetRedemption' => ['method' => 'POST', 'path' => '/auth/password-resets/redemption', 'idem' => false],
    ];

    /** §4.2's `Accepted request fields` for `IssueToken`, which is exhaustive. */
    private const PINNED_ACCEPTED_FIELDS = ['workspace', 'email', 'password'];

    /**
     * D-14: deliberately not shipped — and, since 2026-09-14, **withdrawn from
     * §4.2** by the owner (ruling C). Until then D-14 kept both as *declared*
     * residuals and `thePinsMatchTheContractDocument` demanded their rows. The
     * owner ruled that a row no build will ever satisfy is a promise, not a
     * deferral, so the rows left the table and the withdrawal is recorded
     * beside it. Both halves are still asserted: neither ships, and neither is
     * declared.
     */
    private const PINNED_UNSHIPPED = ['RefreshToken', 'RevokeSession'];

    #[Test]
    public function theRegistryDeclaresExactlyThePinnedAuthOperations(): void
    {
        $declared = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = str_replace(self::BASE, '', (string) $operation->getUriTemplate());

            // `/me(/|$)` rather than `/me$`: `ChangePassword` is at `/me/password`
            // and §4.2 files it in the Authentication block, so the pattern that
            // selects this file's domain has to reach it. A pattern that silently
            // stopped matching a row would take that row out of the pin without
            // anything going red — the same shape `AuthenticationGate`'s base-path
            // note records for its own prefix check.
            if (1 !== preg_match('#^(/auth/|/me(/|$)|/session/)#', $path)) {
                continue;
            }

            $declared[$name] = [
                'method' => $operation->getMethod(),
                'path' => $path,
                'idem' => true === ($operation->getExtraProperties()[IdempotencyKeyGuard::OPERATION_FLAG] ?? null),
            ];
        }

        ksort($declared);
        $pinned = self::PINNED_AUTH_OPERATIONS;
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /**
     * The claim `ItemOperationContractTest` used to make about the whole
     * registry, restored at the right scope.
     *
     * *Mutation, run:* add any operation to any resource; red until it is pinned
     * here. *Second mutation, run:* delete `TokenResource`; also red.
     */
    #[Test]
    public function theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned(): void
    {
        $expected = array_merge(
            array_keys(\App\Tests\Api\ItemOperationContractTest::PINNED_ITEM_OPERATIONS),
            array_keys(\App\Tests\Api\PurchaseOrderOperationContractTest::PINNED_PURCHASE_ORDER_OPERATIONS),
            // Step 8: `GetGoodsReceipt` lives at `/goods-receipts/{id}`, outside
            // the purchase-order pin's paths, so it has a pin of its own.
            array_keys(\App\Tests\Api\GoodsReceiptCreateTest::PINNED_GOODS_RECEIPT_OPERATIONS),
            // Output 9, sales slice 1: `/sales-orders` is its own path family, so it carries its own pin.
            array_keys(\App\Tests\Api\SalesOrderCreateTest::PINNED_SALES_ORDER_OPERATIONS),
            // Sales step 5, commit 4: `GetFulfilment` lives at `/fulfilments/{id}`, outside the sales-order pin's paths,
            // so it has a pin of its own -- `GetGoodsReceipt`'s shape exactly.
            array_keys(\App\Tests\Api\FulfilmentCreateTest::PINNED_FULFILMENT_OPERATIONS),
            // Sales step 6, C3: `/invoices` is its own path family (`IssueInvoice` is under `/fulfilments`).
            array_keys(\App\Tests\Api\InvoiceIssueTest::PINNED_INVOICE_OPERATIONS),
            // Sales step 7 R2: `/sales-returns` is its own path family (`CreateSalesReturn` is under `/fulfilments`).
            array_keys(\App\Tests\Api\SalesReturnCreateTest::PINNED_SALES_RETURN_OPERATIONS),
            // Sales step 7 C1: `/credit-notes` is its own path family (`IssueCreditNote` is under `/invoices`).
            array_keys(\App\Tests\Api\CreditNoteIssueTest::PINNED_CREDIT_NOTE_OPERATIONS),
            // Roles slice 1: `/permissions` is its own path family.
            array_keys(\App\Tests\Api\PermissionListTest::PINNED_PERMISSION_OPERATIONS),
            // Roles slice 1: `/roles` is its own path family.
            array_keys(\App\Tests\Api\RoleReadTest::PINNED_ROLE_OPERATIONS),
            // Roles slice 2, C2: `/users/{id}/roles/{roleId}` is its own path family (`RevokeRole`; `AssignRole` in C3).
            array_keys(\App\Tests\Api\RoleRevokeTest::PINNED_USER_ROLE_OPERATIONS),
            // Roles slice 3, C1: `/role-grant-proposals` is its own path family (`CreateRoleGrantProposal` is under `/users`).
            array_keys(\App\Tests\Api\RoleGrantProposalTest::PINNED_ROLE_GRANT_PROPOSAL_OPERATIONS),
            // Users slice 1, C1: `/users` and `/users/{id}` (the `/users/{id}/…` family stays `RoleRevokeTest`'s).
            array_keys(\App\Tests\Api\UserReadTest::PINNED_USER_OPERATIONS),
            // Customers slice 1, C1: the `/customers` path family (C2 and C3 add their operations to the same pin).
            array_keys(\App\Tests\Api\CustomerReadTest::PINNED_CUSTOMER_OPERATIONS),
            // Suppliers slice 1, C1: the `/suppliers` path family (later commits add their operations to the same pin).
            array_keys(\App\Tests\Api\SupplierReadTest::PINNED_SUPPLIER_OPERATIONS),
            // Warehouses slice 1, C1: the `/warehouses` path family (later commits add their operations to the same pin).
            array_keys(\App\Tests\Api\WarehouseReadTest::PINNED_WAREHOUSE_OPERATIONS),
            // Stock reads C1: the `/stock` path family (`/stock-movements` is C2's own family).
            array_keys(\App\Tests\Api\StockLevelReadTest::PINNED_STOCK_LEVEL_OPERATIONS),
            // Stock reads C2: the `/stock-movements` path family.
            array_keys(\App\Tests\Api\StockMovementReadTest::PINNED_STOCK_MOVEMENT_OPERATIONS),
            // Audit reads C1: the `/audit-log` path family (`ListAuditRecords` joined it at C2).
            array_keys(\App\Tests\Api\AuditLogReadTest::PINNED_AUDIT_OPERATIONS),
            // Remaining-ops G7a: the `/settings` path family (`UpdateSettings` joins it at G7b).
            array_keys(\App\Tests\Api\SettingsReadTest::PINNED_SETTINGS_OPERATIONS),
            // G6-C0: the `/exports` path family (`CreateExport`).
            array_keys(\App\Tests\Api\ExportContractTest::PINNED_EXPORT_OPERATIONS),
            // Remaining-ops G3-C1: the `/forecast` path family (`GetReorderSummary`, `ListReorderSuggestions`).
            array_keys(\App\Tests\Api\ReorderSuggestionTest::PINNED_REORDER_OPERATIONS),
            // G5-C2: the `/bulk` path family (`CreateBulkArchival`; restoration and role assignment join it).
            array_keys(\App\Tests\Api\BulkArchivalTest::PINNED_BULK_OPERATIONS),
            // G5-C1: `CreateBulkRoleAssignment` joins the `/bulk` family.
            array_keys(\App\Tests\Api\BulkRoleAssignmentTest::PINNED_BULK_OPERATIONS),
            array_keys(self::PINNED_AUTH_OPERATIONS),
        );
        sort($expected);

        $declared = array_keys(TransactionBoundaryTest::contractOperations());
        sort($declared);

        self::assertSame($expected, $declared,
            // ⚠ This message read *"the union of the TWO pins"* until 2026-09-19 and had been false since
            // step 8 added the goods-receipt pin. It is four now; it is written without a number so the
            // fifth does not have to remember to correct it.
            'The shipped operation set is not the union of the per-path-family pins. An operation nobody pinned is an '
            .'operation nobody reviewed against §4.2.');

        foreach (self::PINNED_UNSHIPPED as $residual) {
            self::assertNotContains($residual, $declared,
                $residual.' is shipped. D-14 rules it out: an endpoint named "revoke" that revokes nothing '
                .'is the fake-green shape this project has now found fifteen times, and RefreshToken is '
                .'bound by ADR-005 to a BFF cookie the browser half of which is deferred.');
        }

        // *(The purchasing block's deliberate absences were asserted here from `PurchaseOrderOperationContractTest::
        // PINNED_UNSHIPPED` until order restores C3 (2026-10-22), when its last member, `RestorePurchaseOrder`, shipped and
        // the constant was removed — an empty list would loop over nothing.)*
    }

    /**
     * B-3, read off the input class rather than off the spec: this is what the
     * deserializer will accept, and `additionalProperties: false` in the
     * generated schema is derived from it.
     *
     * *Mutation, run:* add a `tenant_id` property to `IssueTokenInput`; red —
     * and T-1a is what it would violate, not merely this pin.
     */
    #[Test]
    public function theInputDtoDeclaresExactlyTheAcceptedFieldsAndNoIdentifier(): void
    {
        $serialized = [];

        foreach ((new \ReflectionClass(IssueTokenInput::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            foreach ($property->getAttributes(SerializedName::class) as $attribute) {
                $name = $attribute->newInstance()->serializedName;
            }

            $serialized[] = $name;
        }

        self::assertSame(self::PINNED_ACCEPTED_FIELDS, $serialized);
        self::assertNotContains('id', $serialized, 'B-3.');
        self::assertNotContains('tenant_id', $serialized, 'T-1 / API-7: no request field carries a tenant.');
    }

    /**
     * API-9's runtime limb on this operation: an undeclared field is refused,
     * never ignored — and the refusal names it.
     *
     * *Mutation, run:* remove `allow_extra_attributes: false` from the operation;
     * the field is silently dropped, the response is a 401 rather than a 422, and
     * this is red.
     */
    #[Test]
    public function anUndeclaredFieldIsRefusedRatherThanIgnored(): void
    {
        $this->send('POST', '/auth/token', [
            'workspace' => 'anything',
            'email' => 'a@example.com',
            'password' => 'x',
            'tenant_id' => '11111111-1111-4111-8111-111111111111',
        ]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame('/tenant_id', $this->json()['errors'][0]['pointer']);
    }

    /**
     * API-21 branch 5: a `POST` that creates no row this contract addresses is
     * 200 with no `Location`. §4.4.1 names `IssueToken` in that branch in terms.
     *
     * *Mutation, run:* set `status: 201` on the operation; red.
     */
    #[Test]
    public function issueTokenIsBranchFiveAndCarriesNoLocation(): void
    {
        $operation = TransactionBoundaryTest::contractOperations()['IssueToken'];
        \assert($operation instanceof HttpOperation);

        self::assertSame(200, $operation->getStatus());

        // And on the wire, because a declared status and an emitted one are two
        // different claims.
        $this->send('POST', '/auth/token', ['workspace' => 'nope', 'email' => 'a@example.com', 'password' => 'x']);
        self::assertSame(401, $this->httpStatus());
        self::assertNull($this->client()->getResponse()->headers->get('Location'));
    }

    // -----------------------------------------------------------------------
    // The same pins, against the document.
    // -----------------------------------------------------------------------

    #[Test]
    public function thePinsMatchTheContractDocument(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout, so the pins above are checked against the code only. See the phase-2 report.');
        }

        $rows = ContractDocument::operationRows();
        self::assertGreaterThan(50, \count($rows), 'The §4.2 parser found almost nothing, so every comparison below would be vacuous.');

        foreach (self::PINNED_AUTH_OPERATIONS as $operationId => $pinned) {
            self::assertArrayHasKey($operationId, $rows, $operationId.' is shipped and §4.2 declares no row for it.');
            self::assertSame($pinned['method'], $rows[$operationId]['method']);
            self::assertSame($pinned['path'], $rows[$operationId]['path']);
            // §4.2's `Idem` cell names a mechanism since 2026-09-10, so the
            // header requirement is derived from it. These three rows are `no`
            // either way; the comparison is updated rather than left working by
            // accident, which is how a stale reader survives.
            self::assertSame($pinned['idem'], ContractDocument::headerIsRequiredFor($operationId));
        }

        self::assertSame(self::PINNED_ACCEPTED_FIELDS, ContractDocument::acceptedFields('IssueToken'));
        self::assertSame([], ContractDocument::queryParameters('IssueToken'));
        self::assertSame([], ContractDocument::queryParameters('GetCurrentUser'));

        // The residuals are WITHDRAWN from §4.2 (D-14, owner ruling C,
        // 2026-09-14) and unshipped in the code. The row-count guard above is
        // what stops this absence passing on a parser that found nothing.
        foreach (self::PINNED_UNSHIPPED as $residual) {
            self::assertArrayNotHasKey($residual, $rows,
                $residual.' is declared in §4.2 again. The owner withdrew it on 2026-09-14 (ruling C) because '
                .'D-14 ships neither operation; reinstating the row needs a ruling that reopens D-14 and E-20.');
        }

        // …and the withdrawal is RECORDED, so the absence is a ruling rather than
        // a row that silently went missing.
        $withdrawal = array_values(array_filter(ContractDocument::lines(),
            static fn (string $line): bool => str_contains($line, 'WITHDRAWN')
                && str_contains($line, '`RefreshToken`') && str_contains($line, '`RevokeSession`')));
        self::assertNotSame([], $withdrawal,
            'RefreshToken and RevokeSession are absent from §4.2 and no line records their withdrawal. An '
            .'undocumented absence is an omission, not a deferral.');
    }

    /**
     * §12.3 — `operationId = Verb ++ Resource`, derived from the row's own two
     * columns rather than compared against a list.
     */
    #[Test]
    public function everyOperationIdIsItsOwnVerbAndResource(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout. See the phase-2 report.');
        }

        $rows = ContractDocument::operationRows();

        foreach (array_keys(self::PINNED_AUTH_OPERATIONS) as $operationId) {
            self::assertArrayHasKey($operationId, $rows);

            [$verb, $resource] = array_map(trim(...), explode('/', $rows[$operationId]['verbResource'], 2));
            self::assertSame($verb.$resource, $operationId);
        }
    }
}
