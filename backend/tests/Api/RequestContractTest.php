<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Tenancy\SchemaManifest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;

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
     * - **A `Restore…` operation is driven at an archived row.** At a live row its
     *   processor refuses before any write, so the no-write check would be
     *   vacuous for it; a control asserts the row really is archived.
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
        $n = 0;
        foreach ($writes as $name => $operation) {
            $paths[$name] = $this->pathFor((string) $operation->getUriTemplate(), 'RC-'.++$n, str_starts_with($name, 'Restore'));
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
     * operation. A parameter after a collection segment this method does not
     * know is a failure, not a skip: the operation it belongs to is new, and
     * this is where it has to be taught.
     */
    private function pathFor(string $uriTemplate, string $orderNumber, bool $archivedItem = false): string
    {
        $draft = $this->seedDraft(self::TENANT_A, $orderNumber);
        if ($archivedItem) {
            $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$draft['item']]);
            self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$draft['item']]),
                $uriTemplate.': the row a Restore operation is driven at is not archived, so its no-write check proves nothing.');
        }
        $rows = [
            'items' => $draft['item'],
            'purchase-orders' => $draft['order'],
            'lines' => $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']),
        ];

        $segments = explode('/', $uriTemplate);
        foreach ($segments as $i => $segment) {
            if (1 !== preg_match('/^\{\w+\}$/', $segment)) {
                continue;
            }
            $collection = $segments[$i - 1] ?? '';
            self::assertArrayHasKey($collection, $rows, \sprintf('%s: no seeded row answers the parameter after "/%s".', $uriTemplate, $collection));
            $segments[$i] = $rows[$collection];
        }

        return implode('/', $segments);
    }
}
