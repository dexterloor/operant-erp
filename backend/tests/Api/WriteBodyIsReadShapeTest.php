<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;

/**
 * **PC-17 — a write's 2xx body is the resource it acted on, in that resource's read shape** (`SECURITY_MODEL.md` PC-17,
 * owner ruling 2026-10-01; `docs/session13/output9-sales-8-l5-1-sec.md` §M-4 (5), the spar judgement's §4: this test
 * REPLACES a hand-kept list of writes, so a write shipped tomorrow is held to the rule without anyone editing a list).
 *
 * **Measured first (2026-10-01, sales step 7 C0), as §M-4 (5) step one asks:** in the generated spec every write that
 * has a read serves the SAME component `$ref` as that read — the `Get`'s schema, or, where the resource has no `Get`
 * (`PurchaseOrderLine`, `SalesOrderLine`), the `$ref` of the `.view`-gated `List`'s `items` (`SEC`'s correction to the
 * judgement). So the comparison is `$ref` equality, with a property-name fallback (recursing into embedded `lines`
 * items) for any schema served inline.
 *
 * **The rule, derived, never listed:**
 *   (a) every operation that is not a `Get`/`List`, has a 2xx JSON body and is not excluded, serves its resource's read
 *       shape. "Its resource" is the `ApiResource` it is declared on (the spec's tag — NOT §4.2's `Verb / Resource`
 *       cell, which for `ReverseInvoicePayment` reads `InvoicePayment` though the resource acted on is the invoice:
 *       measured on the first run); "its read" is that resource's `Get`, else the `List` declared on its `*Collection`
 *       envelope, by its items — and that read must be gated on a `.view` code (`PermissionEnforcementContractTest::PINNED_PERMISSIONS`),
 *       or PC-17 does not apply to it (§M-4 Bounds: an administrative-code read is ruled by name);
 *   (b) the excluded set holds only writes with NO read (today `IssueToken`, `ChangePassword`), and never a write on a
 *       role-grant proposal (owner ruling Q-6: those bodies are ruled by name) — in it, or admitted under (a);
 *   (c) positive control: `PayInvoice` maps to `GetInvoice`.
 * Mutation (run, `output9-sales-8-build-progress.md` chunk B): give one write a different output → red.
 */
final class WriteBodyIsReadShapeTest extends PurchaseOrderTestCase
{
    /** Writes that have no read at all, so there is no read shape to serve. Each with its reason. */
    private const NO_READ = [
        'IssueToken' => 'returns a token, not a resource; there is no token read (and it carries no write code)',
        'ChangePassword' => 'returns a confirmation of a credential change; a password is never read',
    ];

    #[Test]
    public function everyWriteServesTheReadShapeOfTheResourceItActedOn(): void
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $byId = [];
        foreach (GeneratedSpec::operations($spec) as $data) {
            if (\is_string($id = $data['operation']['operationId'] ?? null)) {
                $byId[$id] = $data['operation'];
            }
        }
        $codes = PermissionEnforcementContractTest::PINNED_PERMISSIONS;

        $defects = [];
        $admitted = [];
        foreach ($byId as $id => $operation) {
            if (1 === preg_match('/^(Get|List)[A-Z]/', $id) || null === ($body = self::bodySchema($operation))) {
                continue;
            }
            if (str_ends_with($id, 'RoleGrantProposal')) {
                $defects[] = $id.' is a role-grant-proposal write: PC-17 excludes it and Q-6 rules its body by name; it may not be admitted here';
                continue;
            }
            if (isset(self::NO_READ[$id])) {
                continue;
            }
            self::assertCount(1, $operation['tags'] ?? [], $id.' is not declared on exactly one ApiResource (its tag), so its resource cannot be named.');
            $resource = (string) $operation['tags'][0];

            [$readId, $readSchema] = self::readOf($resource, $byId, $spec);
            if (null === $readId) {
                $defects[] = $id.' returns a body, and '.$resource.' has no Get and no List: PC-17 has no read shape to hold it to (add it to NO_READ with a reason, or build the read)';
                continue;
            }
            if (!str_ends_with((string) ($codes[$readId] ?? ''), '.view')) {
                $defects[] = $id.'\'s read '.$readId.' is gated on '.var_export($codes[$readId] ?? null, true).', not a .view code: PC-17 does not apply, so this body must be ruled by name';
                continue;
            }
            if (!self::sameShape($body, $readSchema, $spec)) {
                $defects[] = $id.' serves '.json_encode($body).', not '.$readId.'\'s '.json_encode($readSchema);
                continue;
            }
            $admitted[$id] = $readId;
        }

        self::assertSame([], $defects, "PC-17 (SECURITY_MODEL.md): a write's 2xx body is the one resource it acted on, in its read shape.\n  ".implode("\n  ", $defects));

        // (c) The positive control, and non-vacuity: the rule held over real writes, including the line writes that read
        // through their List and AssignRole, which reads through GetRole.
        self::assertSame('GetInvoice', $admitted['PayInvoice'] ?? null, 'Positive control: PayInvoice is not held to GetInvoice.');
        self::assertSame('ListPurchaseOrderLines', $admitted['UpdatePurchaseOrderLine'] ?? null, 'The List fallback did not apply to a line write.');
        self::assertSame('GetRole', $admitted['AssignRole'] ?? null);
        self::assertGreaterThan(20, \count($admitted), 'Almost no write was checked, so the rule held over nothing.');

        // (b) The excluded set: every entry is built, has no read, and none is a role-grant-proposal write.
        foreach (array_keys(self::NO_READ) as $id) {
            self::assertArrayHasKey($id, $byId, $id.' is excluded but not built: a dead exclusion.');
            self::assertStringEndsNotWith('RoleGrantProposal', $id);
        }

        // Planted controls on the comparison itself.
        self::assertTrue(self::sameShape(['$ref' => '#/components/schemas/X'], ['$ref' => '#/components/schemas/X'], $spec));
        self::assertFalse(self::sameShape(['$ref' => '#/components/schemas/Invoice'], ['$ref' => '#/components/schemas/SalesOrder'], $spec),
            'Two different components compared equal.');
        self::assertFalse(self::sameShape(['type' => 'object', 'properties' => ['id' => [], 'extra' => []]], ['type' => 'object', 'properties' => ['id' => []]], $spec),
            'An extra property on a write body was not seen.');
        self::assertFalse(self::sameShape(
            ['type' => 'object', 'properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => [], 'x' => []]]]]],
            ['type' => 'object', 'properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => []]]]]], $spec),
            'An extra property on an embedded line was not seen.');
    }

    /** @param array<string, mixed> $operation */
    private static function bodySchema(array $operation): ?array
    {
        foreach ($operation['responses'] ?? [] as $code => $response) {
            if (str_starts_with((string) $code, '2') && \is_array($response['content']['application/json']['schema'] ?? null)) {
                return $response['content']['application/json']['schema'];
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $byId
     * @param array<string, mixed>                $spec
     *
     * @return array{0: ?string, 1: ?array}
     */
    private static function readOf(string $resource, array $byId, array $spec): array
    {
        foreach ($byId as $id => $operation) {
            if (str_starts_with($id, 'Get') && [$resource] === ($operation['tags'] ?? null) && null !== ($schema = self::bodySchema($operation))) {
                return [$id, $schema];
            }
        }
        foreach ($byId as $id => $operation) {
            if (str_starts_with($id, 'List') && [$resource.'Collection'] === ($operation['tags'] ?? null) && null !== ($envelope = self::bodySchema($operation))) {
                return [$id, self::resolve($envelope, $spec)['properties']['items']['items'] ?? null];
            }
        }

        return [null, null];
    }

    /**
     * Same `$ref`, or — for an inline schema on either side — the same property names, recursively into objects and
     * array items.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param array<string, mixed> $spec
     */
    private static function sameShape(?array $a, ?array $b, array $spec): bool
    {
        if (null === $a || null === $b) {
            return false;
        }
        if (isset($a['$ref'], $b['$ref'])) {
            return $a['$ref'] === $b['$ref'];
        }
        $a = self::resolve($a, $spec);
        $b = self::resolve($b, $spec);
        if (isset($a['items']) || isset($b['items'])) {
            return self::sameShape($a['items'] ?? null, $b['items'] ?? null, $spec);
        }
        $pa = array_keys($a['properties'] ?? []);
        $pb = array_keys($b['properties'] ?? []);
        sort($pa);
        sort($pb);
        if ($pa !== $pb) {
            return false;
        }
        foreach ($pa as $name) {
            $x = $a['properties'][$name];
            $y = $b['properties'][$name];
            if ((isset($x['properties']) || isset($x['items']) || isset($y['properties']) || isset($y['items'])) && !self::sameShape($x, $y, $spec)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private static function resolve(array $schema, array $spec): array
    {
        while (isset($schema['$ref']) && 1 === preg_match('~^#/components/schemas/(.+)$~', (string) $schema['$ref'], $m)) {
            $schema = $spec['components']['schemas'][$m[1]] ?? [];
        }

        return $schema;
    }
}
