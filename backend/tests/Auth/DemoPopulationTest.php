<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Application\RoleReconciliation;
use ErpDemoSeed\Locality;
use ErpDemoWalkthrough\Plan;
use PHPUnit\Framework\Attributes\Test;

/**
 * **2a C3: `demo-walkthrough.php populate` fills a seeded workspace through the API, as the people who would do each step**
 * (`demo-seed-2a-design.md` Revision 1 §R1-2…§R1-4, §R1-10; final RED M-1's order).
 *
 * Phase 1 is the real seeder run as a process (`DemoSeedProcessTest`'s precedent, a `demo-test-` workspace on the gate
 * database). The plan then runs through a test transport (this kernel's browser, one kernel for the whole test so the
 * in-memory mail queue survives — `UserCreateTest`'s precedent) and a test mailbox that drains the queue through the real
 * bus and reads the mail the worker sent. The real Mailpit client is not exercised here (one recorded manual run).
 *
 * Asserts: exit 0; the counts of §R1-2/§R1-3; every person's first authenticated call answered 200 (H-1 — the plan stops
 * otherwise); `app:roles:reconcile` reports nothing (arm H included) — the mechanism of `SECURITY_MODEL.md`'s demo-tenant
 * sentence; no request body carries a `*_at` key; the captured output carries neither the password nor any token; a second
 * run refuses with exit 3 and changes nothing.
 */
final class DemoPopulationTest extends AuthTestCase
{
    use DemoPlanRig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner()->executeStatement('DELETE FROM tenant WHERE slug LIKE ?', [Locality::TEST_SLUG_PREFIX.'%']);
        $this->client()->disableReboot();
        $this->client()->setServerParameter('REMOTE_ADDR', '127.0.0.1');
    }

    #[Test]
    public function aSeededWorkspaceIsFilledThroughTheApiAndRefusesASecondFill(): void
    {
        $slug = self::demoSlug();
        $password = bin2hex(random_bytes(12));
        $this->seed($slug, $password);
        $tenantId = (string) $this->owner()->fetchOne('SELECT id::text FROM tenant WHERE slug = ?', [$slug]);

        [$plan, $exit, $output] = $this->runPlan($slug, $password, fn (Plan $plan) => $plan->fillWorkspace($this->mailbox()));
        self::assertSame(Plan::EXIT_OK, $exit, $output);

        self::assertSame([
            'app_user' => 8, 'user_role' => 8, 'pending proposals' => 1, 'warehouse' => 2, 'storage_location' => 6,
            'inventory_item' => 8, 'archived items' => 1, 'supplier' => 2, 'supplier_product' => 4, 'customer' => 3,
            'customer_address' => 3, 'customer_contact' => 1, 'purchase_order' => 4, 'sales_order' => 3, 'fulfilment' => 1, 'paid invoices' => 1,
        ], $this->counts($tenantId));
        self::assertSame(['cancelled' => 1, 'draft' => 1, 'ordered' => 1, 'received' => 1], $this->byStatus($tenantId, 'purchase_order'));
        self::assertSame(['confirmed' => 1, 'draft' => 1, 'fulfilled' => 1], $this->byStatus($tenantId, 'sales_order'));
        self::assertSame(['A-01' => '35.000000', 'A-02' => '20.000000'], $this->onHand($tenantId));

        self::assertSame(7, substr_count(implode(',', $plan->called), 'CreatePasswordResetRedemption'));
        self::assertSame([], self::timestampKeys($plan->bodies), 'A request body carries a *_at field: the server\'s clock must decide every date.');
        foreach ($this->secrets as $secret) {
            self::assertStringNotContainsString($secret, $output, 'The output carries a secret.');
        }

        [$reconcile, $display] = $this->reconcile($tenantId);
        self::assertSame(0, $reconcile, $display);
        self::assertSame(RoleReconciliation::CADENCE, trim($display));

        // A second run refuses before writing anything (exit 3). The refusal is the script's own (it reads ListUsers' 200),
        // so no request is refused and the one kernel stays usable.
        $before = $this->counts($tenantId);
        [, $again, $output] = $this->runPlan($slug, $password, fn (Plan $plan) => $plan->fillWorkspace($this->mailbox()));
        self::assertSame(Plan::EXIT_POPULATED, $again, $output);
        self::assertSame($before, $this->counts($tenantId));
    }

    /** @return array<string, int> */
    private function counts(string $tenantId): array
    {
        $one = fn (string $sql): int => (int) $this->fetchAsOwnerForTenant($tenantId, $sql)[0]['n'];

        return [
            'app_user' => $one('SELECT count(*) AS n FROM app_user'),
            'user_role' => $one('SELECT count(*) AS n FROM user_role'),
            'pending proposals' => $one("SELECT count(*) AS n FROM role_grant_proposal WHERE status = 'pending'"),
            'warehouse' => $one('SELECT count(*) AS n FROM warehouse'),
            'storage_location' => $one('SELECT count(*) AS n FROM storage_location'),
            'inventory_item' => $one('SELECT count(*) AS n FROM inventory_item'),
            'archived items' => $one('SELECT count(*) AS n FROM inventory_item WHERE deleted_at IS NOT NULL'),
            'supplier' => $one('SELECT count(*) AS n FROM supplier'),
            'supplier_product' => $one('SELECT count(*) AS n FROM supplier_product'),
            'customer' => $one('SELECT count(*) AS n FROM customer'),
            'customer_address' => $one('SELECT count(*) AS n FROM customer_address'),
            'customer_contact' => $one('SELECT count(*) AS n FROM customer_contact'),
            'purchase_order' => $one('SELECT count(*) AS n FROM purchase_order'),
            'sales_order' => $one('SELECT count(*) AS n FROM sales_order'),
            'fulfilment' => $one('SELECT count(*) AS n FROM fulfilment'),
            'paid invoices' => $one("SELECT count(*) AS n FROM invoice WHERE status = 'paid'"),
        ];
    }

    /** @return array<string, int> */
    private function byStatus(string $tenantId, string $table): array
    {
        $out = [];
        foreach ($this->fetchAsOwnerForTenant($tenantId, 'SELECT status, count(*) AS n FROM '.$table.' GROUP BY status ORDER BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    /** @return array<string, string> location code => on-hand of everything held there */
    private function onHand(string $tenantId): array
    {
        $out = [];
        foreach ($this->fetchAsOwnerForTenant($tenantId, 'SELECT l.code, sum(s.qty_on_hand)::text AS q FROM stock_level s JOIN storage_location l ON l.id = s.storage_location_id GROUP BY l.code ORDER BY l.code') as $row) {
            $out[(string) $row['code']] = (string) $row['q'];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $bodies
     *
     * @return list<string>
     */
    private static function timestampKeys(array $bodies): array
    {
        $found = [];
        $walk = static function (mixed $value) use (&$walk, &$found): void {
            if (\is_array($value)) {
                foreach ($value as $key => $inner) {
                    if (\is_string($key) && str_ends_with($key, '_at')) {
                        $found[] = $key;
                    }
                    $walk($inner);
                }
            }
        };
        $walk($bodies);

        return $found;
    }
}
