<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\Contract\WorkspaceNotConfigured;
use App\Modules\UserManagement\Application\Contract\WorkspaceSettings;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;

/**
 * E4-3 — the consumer half. `inventory_item.currency_code` comes from the
 * workspace's own `tenant_settings` row and from nowhere else.
 *
 * ---------------------------------------------------------------------------
 * The defect these tests would have caught, and no existing test could
 * ---------------------------------------------------------------------------
 * `app.inventory.default_currency_code: 'EUR'` was an instance-wide container
 * parameter bound into every `INSERT INTO inventory_item`. Every tenant on the
 * deployment was priced in euros, and the whole W0-a isolation slice was blind
 * to it: RLS, `TenantContext`, the composite foreign keys and every
 * catalogue-derived guard describe the database, and a PHP constant never
 * reaches the database. It sat *above* the isolation boundary.
 *
 * So the fixture matters more than usual. `TenancyTestCase` seeds both
 * workspaces on `EUR`, which is the value the deployment was really running —
 * and **two workspaces on `EUR` is exactly the fixture a reverted `ItemWriter`
 * would still satisfy**. Every test below therefore restates the setting, and
 * restates it to something that is not `EUR` on both sides.
 */
final class ItemCurrencyTest extends ApiTestCase
{
    private const KEY = [IdempotencyKeyGuard::HEADER => 'a-client-generated-key'];

    /** @return array<string, mixed> */
    private static function body(string $sku): array
    {
        return ['sku' => $sku, 'name' => 'Widget', 'unit_of_measure_code' => 'EA'];
    }

    /** The stored currency of the one item in $tenantId. */
    private function storedCurrency(string $tenantId, string $id): string
    {
        $rows = $this->fetchAsOwnerForTenant(
            $tenantId,
            'SELECT currency_code FROM inventory_item WHERE id = ?::uuid',
            [$id],
        );

        self::assertCount(1, $rows, 'The item was not written, so there is no currency to read.');

        return (string) $rows[0]['currency_code'];
    }

    /**
     * The load-bearing test of this pass.
     *
     * Two workspaces, two different currencies, neither of them the constant
     * that used to be compiled in. Under the old `ItemWriter` both rows read
     * `EUR` and both assertions fail — so this is red on a revert, not merely
     * green on the fix.
     *
     * Mutation, run: restore `#[Autowire('%app.inventory.default_currency_code%')]
     * private string $defaultCurrencyCode` and bind it in the INSERT. Both
     * assertions fail (`EUR` for `USD`, `EUR` for `GBP`).
     */
    #[Test]
    public function anItemIsDenominatedInItsOwnWorkspacesCurrency(): void
    {
        $this->seedTenantSettings(self::TENANT_A, 'USD');
        $this->seedTenantSettings(self::TENANT_B, 'GBP');

        $this->actAs(self::TENANT_A);
        $this->send('POST', '/items', self::body('SKU-A'), self::KEY);
        self::assertSame(201, $this->httpStatus());
        $inA = (string) $this->json()['id'];

        $this->actAs(self::TENANT_B);
        $this->send('POST', '/items', self::body('SKU-B'), self::KEY);
        self::assertSame(201, $this->httpStatus());
        $inB = (string) $this->json()['id'];

        self::assertSame('USD', $this->storedCurrency(self::TENANT_A, $inA),
            'The item took a currency that is not its workspace\'s. If it reads EUR, the writer is back on '
            .'an instance-wide constant and every tenant on the deployment is priced the same way again.');
        self::assertSame('GBP', $this->storedCurrency(self::TENANT_B, $inB),
            'Two workspaces on the same deployment did not get their own currencies.');
    }

    /**
     * The refusal, and it is a designed behaviour rather than an accident.
     *
     * `default_currency_code` has no database default and nothing in the
     * application substitutes one, so a workspace nobody configured cannot
     * create an item. That is the same refusal `inventory_item.currency_code
     * NOT NULL` already makes at the column, moved to where it can say why.
     *
     * Mutation, run: `?? 'EUR'` on the reader's return. No exception is thrown
     * and this test fails — which is the whole point, because that fallback is
     * the original defect with a new address.
     */
    #[Test]
    public function aWorkspaceWithNoSettingsRowCannotCreateAnItem(): void
    {
        // erp_app holds no DELETE here by design, so the row goes as the owner
        // — which still has to declare a tenant, because FORCE ROW LEVEL
        // SECURITY binds the owner too (ADR-002 §C-2).
        $this->insertAsOwnerForTenant(self::TENANT_A, 'DELETE FROM tenant_settings');

        $settings = self::getContainer()->get(WorkspaceSettings::class);
        \assert($settings instanceof WorkspaceSettings);

        $this->expectException(WorkspaceNotConfigured::class);

        $this->tenantContext()->run(self::TENANT_A, static fn (): string => $settings->defaultCurrencyCode());
    }

    /**
     * The parameter is gone, and this is written so that it also catches the
     * mistake spelled differently.
     *
     * A test asserting `hasParameter('app.inventory.default_currency_code')` is
     * false pins one name; the defect was never about that name. So the domain
     * comes from the **server** — the seeded `currency` table — and the check
     * is that no container parameter holds any of those codes as its value. A
     * new `app.default_currency: 'USD'` fails this without anyone having to
     * remember to extend a list.
     *
     * Mutation, run: add `app.inventory.default_currency_code: 'EUR'` back to
     * `config/services.yaml` — red, naming the parameter.
     */
    #[Test]
    public function noContainerParameterCarriesACurrencyForEveryWorkspaceAtOnce(): void
    {
        $codes = $this->owner()->fetchFirstColumn('SELECT code FROM currency ORDER BY code');
        self::assertNotSame([], $codes, 'The currency table is empty, so the domain below is empty and this test proves nothing.');

        $offenders = [];
        foreach (self::getContainer()->getParameterBag()->all() as $name => $value) {
            if (\is_string($value) && \in_array($value, $codes, true)) {
                $offenders[] = $name.' = '.$value;
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders)
            ."\nA container parameter holds a currency code. A parameter is instance-wide and a currency is "
            .'per-workspace, so this is `app.inventory.default_currency_code` again under whatever name it '
            ."has now: every tenant on the deployment priced identically, above the isolation boundary "
            .'where no schema check can see it. The value belongs in tenant_settings (ledger E4-3).');
    }
}
