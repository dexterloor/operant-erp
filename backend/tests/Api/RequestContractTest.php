<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Tenancy\SchemaManifest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * `docs/API.md` §2.3 (API-9) — every write operation refuses a request body
 * carrying a field it does not declare, with `422` and `validation-failed`.
 *
 * The per-operation tests (`ItemWriteTest::anUndeclaredFieldIsRefusedAndNamed`,
 * `PurchaseOrderWriteTest::anUndeclaredFieldIsRefused`, …) each cover the
 * operation their module built. **None named `CreateGoodsReceipt`** (C5
 * inventory row 6), which is the shape of gap a registry-derived check exists
 * for: the next write operation cannot ship without being driven here.
 */
final class RequestContractTest extends PurchaseOrderTestCase
{
    private const UNDECLARED = 'c5_undeclared_probe';

    /**
     * Domain: every `POST`, `PATCH` and `DELETE` in
     * `TransactionBoundaryTest::contractOperations()`. Each is sent its declared
     * body — the input DTO's fields, each with a value of its declared type, so
     * the deserializer reaches the attribute check rather than refusing a type —
     * plus one field no operation declares, at a path whose parameters name rows
     * seeded for that operation alone.
     *
     * **The refusal must name the field.** A bare `422` is not evidence: a
     * declared field refused for its value is also a `422`. So each response must
     * carry an `errors[]` entry whose pointer is `/c5_undeclared_probe` and whose
     * code is `undeclared_field`.
     *
     * Mutation (Rule 17 — cache deleted before the mutated and the revert run):
     * `CreateGoodsReceipt`'s `allow_extra_attributes` → `true` → RED, naming
     * `CreateGoodsReceipt`. Revert → GREEN.
     *
     * **It was red on the day it was written**, on the four writes that read no
     * body: `DeleteItem`, `DeletePurchaseOrder` and `DeletePurchaseOrderLine`
     * answered 204 and applied the delete, and `RestoreItem` answered its own
     * 409. `App\Platform\Http\BodylessWriteRefusal` is the fix. Second mutation:
     * that class made to skip `RestoreItem` by name → RED naming `RestoreItem`
     * alone. Revert → GREEN.
     *
     * **A refusal must also write nothing** (V1-R-1, V4 B4a). The defect was
     * "answered 204 **and applied** the delete", and a status check alone cannot
     * see a refusal that arrives after the write. So every tenant-scoped table
     * of the acting tenant is fingerprinted (row count and an ordered digest of
     * every row as `to_jsonb`, read as the owner with the tenant declared) before
     * and after each request, and any table that differs is named. That covers
     * the three shapes V1-R-1 names at once: a delete keeps `deleted_at IS NULL`,
     * `RestoreItem` leaves its row archived, a PATCH leaves `updated_at` and every
     * touched column as they were — and a create adds no row.
     * - **A `Restore…` operation is driven at an archived row — THE row it binds.**
     *   At a live row its processor refuses before any write, so the no-write check
     *   would be vacuous for it; a control asserts that very row is archived.
     *   ⚠ **Until 2026-09-21 this archived the inventory item for EVERY `Restore*`**,
     *   which is `RestoreItem`'s row and no other: `RestoreSalesOrderLine` was driven
     *   at a LIVE sales line, answered its own 409 (`sales_order_line.is_not_archived`),
     *   and the control passed for a row the operation never touches. `RED` measured
     *   it (sales slice 5, §6.1). The whole test still reddened on a bypass — through
     *   `$accepted`, because 409 is not the named 422 — but its **no-write clause could
     *   never fire** for that operation. Mutation (processor restores, then answers the
     *   named 422 — "applied and still refused"): before the fix, RED only through
     *   `$accepted` with that 409; after it, RED through the no-write clause naming
     *   `sales_order_line`.
     * - **Positive control inside:** the fingerprint taken before seeding must
     *   differ, after seeding, on `inventory_item`, `purchase_order` and
     *   `purchase_order_line`, so it can see a row change on the tables the
     *   built writes touch.
     *
     * Mutation (Rule 17 — cache deleted before and after, confirmed): the
     * `DELETE` half of `BodylessWriteRefusal`'s throw moved into a processor
     * decorator (priority 50, outside `WriteProcessor`) that runs `inner` and then
     * throws. Before this check: `OK (1 test, 47 assertions)` — the three deletes
     * applied and still answered the named 422. After: RED naming `DeleteItem`,
     * `DeletePurchaseOrder` and `DeletePurchaseOrderLine`. The same move for every
     * body-less write (Restore included): RED naming `RestoreItem` as well.
     */
    #[Test]
    public function everyWriteOperationRefusesAnUndeclaredField(): void
    {
        $writes = array_filter(
            TransactionBoundaryTest::contractOperations(),
            static fn (HttpOperation $operation): bool => \in_array($operation->getMethod(), ['POST', 'PATCH', 'DELETE'], true),
        );

        self::assertNotSame([], $writes, 'The registry declares no write operation, so there is no domain.');

        // Every row is seeded before the first request: a refused request can
        // close the shared entity manager, and a seed after it would error
        // rather than drive the operation.
        $unseeded = $this->tenantFingerprint();
        $paths = [];
        foreach ($writes as $name => $operation) {
            $paths[$name] = $this->pathFor((string) $operation->getUriTemplate(), str_starts_with($name, 'Restore'));
        }

        $seeded = $this->tenantFingerprint();
        foreach (['inventory_item', 'purchase_order', 'purchase_order_line'] as $table) {
            self::assertNotSame($unseeded[$table] ?? null, $seeded[$table] ?? null,
                'The fingerprint did not change on '.$table.' when rows were seeded, so "nothing was written" below proves nothing.');
        }

        $wrote = [];

        $driven = [];
        $accepted = [];
        $n = 0;

        foreach ($writes as $name => $operation) {
            ++$n;
            $fields = self::declaredBody($operation);
            self::assertArrayNotHasKey(self::UNDECLARED, $fields, $name.' declares the probe field, so the probe proves nothing.');

            $headers = true === ($operation->getExtraProperties()[IdempotencyKeyGuard::OPERATION_FLAG] ?? false)
                ? [IdempotencyKeyGuard::HEADER => 'c5-request-contract-'.$n]
                : [];

            $before = $this->tenantFingerprint();
            $this->send(
                (string) $operation->getMethod(),
                $paths[$name],
                [...$fields, self::UNDECLARED => 'x'],
                $headers,
            );
            $driven[] = $name;
            $after = $this->tenantFingerprint();

            $changed = array_keys(array_diff_assoc($after, $before) + array_diff_assoc($before, $after));
            if ([] !== $changed) {
                sort($changed);
                $wrote[] = \sprintf('%s %s %s → %d, changed: %s', $name, $operation->getMethod(), $operation->getUriTemplate(), $this->httpStatus(), implode(', ', $changed));
            }

            $status = $this->httpStatus();
            $body = (string) $this->client()->getResponse()->getContent();
            $named = false;
            if (422 === $status) {
                $problem = $this->json();
                foreach ($problem['errors'] ?? [] as $error) {
                    if ('/'.self::UNDECLARED === ($error['pointer'] ?? null) && 'undeclared_field' === ($error['code'] ?? null)) {
                        $named = true;
                    }
                }
                $named = $named && 'urn:erp:problem:validation-failed' === ($problem['type'] ?? null);
            }

            if (!$named) {
                $accepted[] = \sprintf('%s %s %s → %d %s', $name, $operation->getMethod(), $operation->getUriTemplate(), $status, substr($body, 0, 240));
            }
        }

        self::assertSame(array_keys($writes), $driven, 'The loop did not drive every write operation the registry declares.');
        self::assertSame([], $accepted, 'API-9: a write operation did not refuse an undeclared field by name.');
        self::assertSame([], $wrote, 'API-9: a write operation refused an undeclared field and still wrote.');
    }

    /**
     * Every tenant-scoped table of `TENANT_A`, as `table => "count:md5"` over
     * its rows in a fixed order. Read as the owner with the tenant declared
     * (FORCE ROW LEVEL SECURITY binds the owner too), outside any request.
     *
     * @return array<string, string>
     */
    private function tenantFingerprint(): array
    {
        $parts = [];
        foreach (SchemaManifest::tenantScopedTables() as $table) {
            $parts[] = \sprintf(
                "SELECT '%1\$s' AS t, count(*)::text || ':' || md5(coalesce(string_agg(to_jsonb(r)::text, '|' ORDER BY to_jsonb(r)::text), '')) AS f FROM %1\$s r",
                $table,
            );
        }

        $fingerprint = [];
        foreach ($this->fetchAsOwnerForTenant(self::TENANT_A, implode(' UNION ALL ', $parts)) as $row) {
            $fingerprint[(string) $row['t']] = (string) $row['f'];
        }
        self::assertCount(\count(SchemaManifest::tenantScopedTables()), $fingerprint, 'The fingerprint does not cover every tenant-scoped table.');

        return $fingerprint;
    }

    /**
     * The input DTO's fields, as the wire names the deserializer reads, each
     * with a value of its declared type.
     *
     * @return array<string, mixed>
     */
    private static function declaredBody(HttpOperation $operation): array
    {
        $input = $operation->getInput();
        $class = \is_array($input) ? ($input['class'] ?? null) : null;

        if (!\is_string($class)) {
            return [];
        }

        $reflection = new \ReflectionClass($class);
        $body = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $attribute = $property->getAttributes(SerializedName::class)[0] ?? null;
            $wire = null !== $attribute ? $attribute->newInstance()->serializedName : $property->getName();
            $type = $property->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'string';

            $body[$wire] = match ($typeName) {
                'array' => [],
                'int' => 1,
                'float' => 1.0,
                'bool' => false,
                default => 'x',
            };
        }

        return $body;
    }

    /**
     * The operation's path with each parameter bound to a row seeded for this
     * operation. A parameter after a collection path this method does not know
     * is a failure, not a skip: the operation it belongs to is new, and this is
     * where it has to be taught.
     *
     * ⚠ **The key is the WHOLE collection path, not the last segment, and that
     * is a correction rather than a style choice.** Until 2026-09-21 `$rows` was
     * keyed by the preceding segment alone, so `lines` meant one thing and the
     * map held exactly one of them — a `purchase_order_line`. The moment a
     * second resource offered a `/lines` sub-collection, every parameter under
     * it bound to a row belonging to **another order in another module**, and
     * the guard below could not see it: its own check is
     * `assertArrayHasKey($collection, $rows)`, and `lines` *is* a key.
     *
     * **`RED` measured both halves on 2026-09-21.** A probe printed
     * `{lineId} in sales_order_line=0 | in purchase_order_line=1` for
     * `/sales-orders/{id}/lines/{lineId}`, and the control — a template whose
     * collection is a name this map has never seen — failed **loudly**. So the
     * old keying was silent exactly and only for names it had already seen,
     * which is the worst possible failure surface: three of sales slice 4's
     * four operations would have been driven at a foreign row and answered
     * **404**, while `everyWriteOperationRefusesAnUndeclaredField` reported a
     * refusal that proved nothing. **A 404 wearing a refusal's clothes is not a
     * red; it is a green that means nothing.**
     */
    private function pathFor(string $uriTemplate, bool $restore = false): string
    {
        $draft = $this->seedDraft(self::TENANT_A, self::freshNumber('PO'));
        // Added 2026-09-19 with `ConfirmSalesOrder`. The order carries a line so that the request this
        // test sends is refused for the field it plants and not for the order being empty: a 409 from
        // the line guard would look like a refusal of the undeclared field and prove nothing.
        $sales = $this->seedConfirmableSalesOrder($draft['item']);

        $rows = [
            'items' => $draft['item'],
            'purchase-orders' => $draft['order'],
            'purchase-orders/lines' => $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']),
            'sales-orders' => $sales['order'],
            'sales-orders/lines' => $sales['line'],
            // Sales step 6, C3: `IssueInvoice` hangs off a shipment. Seeded as the owner against the same order,
            // so the path names a real fulfilment and the refusal is the planted field's, not a 404's.
            'fulfilments' => $fulfilment = $this->seedFulfilmentOf($sales['order']),
            // Sales step 6, C5: `PayInvoice` acts on an invoice. Seeded as the owner on that shipment, `issued`, so the
            // path names a real, payable invoice and the refusal is the planted field's, not a 404's or a 409's.
            'invoices' => $this->seedInvoiceOf($fulfilment),
            // G-29 / G-30 part 3 C1: `CreateAdjustmentDecision` acts on a return. A real one on that shipment (owner INSERT,
            // header only), so the refusal is the planted field's and not a 404's.
            'sales-returns' => $this->seedReturnOf($fulfilment),
            // Roles slice 2, C2: `RevokeRole` binds a real user and a role THAT USER HOLDS, so the refusal is the planted
            // field's and not a 404's (design §12 item 8).
            'users' => $user = $this->seedPrincipal(self::TENANT_A, ['role.view']),
            'users/roles' => $userRole = (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$user])[0]['r'],
            // Roles slice 3, C2: `ConfirmRoleGrantProposal` acts on a proposal. A real PENDING one (owner INSERT, for a
            // second user, of the first user's role), so the refusal is the planted field's and not a 404's.
            'role-grant-proposals' => $this->seedPendingProposalOf($userRole, $user),
            // Roles slice 4, C2: `UpdateRole` acts on a role. The same composed one-code role (`role.view`, not a system role),
            // so the refusal is the planted field's and not a 404's or an `is_system` 409.
            'roles' => $userRole,
            // Customers slice 1 C2: `UpdateCustomer` acts on a live customer, so the refusal is the planted field's and not a
            // 404's or a `customer.is_archived` 409.
            'customers' => $customer = $this->seedCustomer(self::TENANT_A, 'Request Contract Customer'),
            // Customers slice 1 C3: `UpdateCustomerAddress` acts on an address OF THAT customer (the pair names the row), so
            // the refusal is the planted field's and not a 404's.
            'customers/addresses' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            // Suppliers slice 1 C2: `UpdateSupplier` acts on a live supplier, so the refusal is the planted field's and not a
            // 404's or a `supplier.is_archived` 409.
            'suppliers' => $supplier = $this->seedSupplier(self::TENANT_A, 'Request Contract Supplier '.bin2hex(random_bytes(3))),
            // Suppliers slice 1 C3: `UpdateSupplierProduct` acts on an entry OF THAT supplier (the pair names the row), so the
            // refusal is the planted field's and not a 404's.
            // Warehouses slice 1 C2: `UpdateWarehouse` acts on a live warehouse, so the refusal is the planted field's and not a
            // 404's or a `warehouse.is_archived` 409.
            'warehouses' => $this->seedWarehouse(self::TENANT_A, 'Request Contract Warehouse'),
            'suppliers/products' => $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-RC-'.bin2hex(random_bytes(3)))),
        ];

        $segments = explode('/', $uriTemplate);
        $collections = [];
        $bound = null;
        foreach ($segments as $i => $segment) {
            if (1 !== preg_match('/^\{\w+\}$/', $segment)) {
                if ('' !== $segment) {
                    $collections[] = $segment;
                }

                continue;
            }

            $collection = implode('/', $collections);
            self::assertArrayHasKey($collection, $rows, \sprintf('%s: no seeded row answers the parameter after "/%s".', $uriTemplate, $collection));
            $segments[$i] = $rows[$collection];
            $bound = $collection;
        }

        if ($restore) {
            $this->archiveTheRowARestoreBinds($uriTemplate, $bound, $rows);
        }

        return implode('/', $segments);
    }

    /**
     * Archive the row a `Restore*` operation binds — the one its LAST path parameter names, which is the resource
     * being restored (`/items/{id}/restoration` → the item; `/sales-orders/{id}/lines/{lineId}/restoration` → the
     * line, not its order) — and assert that very row is archived.
     *
     * A collection this map does not know is a failure, not a skip: a new `Restore*` must be taught which table its
     * row lives in here, or its no-write check is vacuous again, which is exactly the defect this method replaced.
     *
     * @param array<string, string> $rows
     */
    private function archiveTheRowARestoreBinds(string $uriTemplate, ?string $bound, array $rows): void
    {
        $tables = [
            'items' => 'inventory_item',
            'purchase-orders' => 'purchase_order',
            'purchase-orders/lines' => 'purchase_order_line',
            'sales-orders' => 'sales_order',
            'sales-orders/lines' => 'sales_order_line',
        ];

        self::assertNotNull($bound, $uriTemplate.': a Restore operation binds no row, so there is nothing to archive.');
        self::assertArrayHasKey($bound, $tables, \sprintf('%s: no table is known for the row "/%s" binds; teach archiveTheRowARestoreBinds() it.', $uriTemplate, $bound));

        $table = $tables[$bound];
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE $table SET deleted_at = now() WHERE id = ?::uuid", [$rows[$bound]]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM $table WHERE id = ?::uuid AND deleted_at IS NOT NULL", [$rows[$bound]]),
            $uriTemplate.': the row a Restore operation binds ('.$table.') is not archived, so its no-write check proves nothing.');
    }

    /**
     * A live `draft` sales order with one live line, so a transition on it is not refused for being empty.
     *
     * Returns **both** ids: the line is what `/sales-orders/{id}/lines/{lineId}` binds, and before 2026-09-21
     * it was not returned at all, which is how that parameter came to be answered by a purchase-order line.
     *
     * @return array{order: string, line: string}
     */
    private function seedFulfilmentOf(string $orderId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number, shipped_by_user_id,
                                    idempotency_key, request_digest)
            SELECT ?::uuid, so.tenant_id, so.id, so.warehouse_id, ?, ?::uuid,
                   encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
              FROM sales_order so WHERE so.id = ?::uuid
            SQL, [$id, self::freshNumber('FUL'), (string) $this->actorId, $orderId]);

        return $id;
    }

    /** A header-only sales return of `$fulfilmentId`, as the owner (G-29 / G-30 part 3 C1). */
    private function seedReturnOf(string $fulfilmentId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO sales_return (id, tenant_id, fulfilment_id, sales_return_number, reason, returned_at, recorded_by_user_id,
                                      deducts, idempotency_key, request_digest)
            SELECT ?::uuid, f.tenant_id, f.id, ?, 'other', now(), ?::uuid, false,
                   encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
              FROM fulfilment f WHERE f.id = ?::uuid
            SQL, [$id, self::freshNumber('RET'), (string) $this->actorId, $fulfilmentId]);

        return $id;
    }

    /** A PENDING proposal of `$roleId` for a fresh user, proposed by `$proposerId` (owner INSERT; roles slice 3 C2). */
    private function seedPendingProposalOf(string $roleId, string $proposerId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
            SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid, '["role.view"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
              FROM (SELECT clock_timestamp() AS t) c
            SQL, [$id, self::TENANT_A, $this->seedPrincipal(self::TENANT_A, []), $roleId, $proposerId]);

        return $id;
    }

    private function seedInvoiceOf(string $fulfilmentId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO invoice (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number, currency_code,
                                 total_amount, due_at, issued_by_user_id)
            SELECT ?::uuid, f.tenant_id, f.id, so.id, so.customer_id, ?, so.currency_code, 1, now() + interval '30 days', ?::uuid
              FROM fulfilment f JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
             WHERE f.id = ?::uuid
            SQL, [$id, self::freshNumber('INV'), (string) $this->actorId, $fulfilmentId]);

        return $id;
    }

    private function seedConfirmableSalesOrder(string $itemId): array
    {
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'));

        return ['order' => $order, 'line' => $this->seedSalesOrderLine(self::TENANT_A, $order, $itemId)];
    }
}
