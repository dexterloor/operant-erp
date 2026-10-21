<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\Contract\WorkspaceNotConfigured;
use App\Modules\UserManagement\Application\SettingsReader;
use PHPUnit\Framework\Attributes\Test;

/**
 * `GetSettings` (G7a, 2026-10-21; `docs/session13/remaining-ops-plan.md` Revision 1 FINAL §R1.2, final RED L-3).
 *
 * §4.4.3's Settings row: `display_name` (from `tenant.name`), `workspace` (from `tenant.slug`) and `default_currency_code`
 * (from `tenant_settings`) — and nothing else. **`tenant` has no RLS** (`SchemaManifest`: `'tenant' => false`), so the only
 * thing keeping another workspace's name out of this response is the reader's own predicate on the caller's tenant, which
 * comes from the signed token and never from the request (L-3). These tests are that predicate's evidence.
 */
final class SettingsReadTest extends ApiTestCase
{
    /** @var array<string, array{method: string, path: string, idem: bool}> */
    public const PINNED_SETTINGS_OPERATIONS = [
        'GetSettings' => ['method' => 'GET', 'path' => '/settings', 'idem' => false],
    ];

    #[Test]
    public function theSettingsPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/settings(/|$)#', $path)) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $path];
            }
        }
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_SETTINGS_OPERATIONS);

        self::assertSame($pinned, $declared);
    }

    #[Test]
    public function aWorkspaceReadsItsOwnNameHandleAndCurrencyAndNothingElse(): void
    {
        $this->seedTenantSettings(self::TENANT_A, 'GBP');

        $this->send('GET', '/settings');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['display_name' => 'Tenant A', 'workspace' => 'tenant-a', 'default_currency_code' => 'GBP'], $this->json());
    }

    /**
     * **L-3: another workspace's settings never appear.** Tenant B is given a name, handle and currency nothing of tenant
     * A's carries, so a reader that lost its predicate (and read "the first `tenant` row", or every row) shows up here
     * whichever row the server returns first. Then B's caller reads B's, so the predicate is the caller's tenant and not a
     * constant that happens to be A.
     */
    #[Test]
    public function anotherWorkspacesSettingsNeverAppear(): void
    {
        $this->owner()->executeStatement("UPDATE tenant SET name = 'Bravo Holdings', slug = 'bravo-holdings' WHERE id = ?::uuid", [self::TENANT_B]);
        $this->seedTenantSettings(self::TENANT_B, 'USD');

        foreach (['/settings', '/settings?tenant_id='.self::TENANT_B, '/settings?workspace=bravo-holdings'] as $path) {
            $this->send('GET', $path);
            $content = (string) $this->client()->getResponse()->getContent();
            foreach (['Bravo', 'bravo-holdings', 'USD', self::TENANT_B] as $foreign) {
                self::assertStringNotContainsString($foreign, $content, $path.': tenant B\'s settings reached tenant A\'s caller.');
            }
        }

        $this->send('GET', '/settings');
        self::assertSame(['display_name' => 'Tenant A', 'workspace' => 'tenant-a', 'default_currency_code' => 'EUR'], $this->json());

        $this->actAs(self::TENANT_B);
        $this->send('GET', '/settings');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['display_name' => 'Bravo Holdings', 'workspace' => 'bravo-holdings', 'default_currency_code' => 'USD'], $this->json());
    }

    /** The request cannot name a tenant: an undeclared parameter is refused (API-19), never read. */
    #[Test]
    public function theRequestCannotChooseTheWorkspace(): void
    {
        foreach (['tenant_id='.self::TENANT_B, 'workspace=tenant-b', 'id='.self::TENANT_B] as $query) {
            $this->send('GET', '/settings?'.$query);
            self::assertSame(422, $this->httpStatus(), $query.' was not refused.');
        }
    }

    #[Test]
    public function aCallerWithoutTheViewCodeIsRefused(): void
    {
        $this->actAs(self::TENANT_A, ['role.view']);
        $this->send('GET', '/settings');
        self::assertSame(403, $this->httpStatus());

        $this->actAs(self::TENANT_A, ['tenant_settings.view']);
        $this->send('GET', '/settings');
        self::assertSame(200, $this->httpStatus(), 'tenant_settings.view alone is enough; nothing else is required.');
    }

    /**
     * A workspace provisioned without its settings row is a server fault, never a defaulted currency: the house rule
     * (`WorkspaceNotConfigured`, ledger E4-3) — a 4xx would tell the caller to change a request no request can fix.
     */
    #[Test]
    public function aWorkspaceWithoutASettingsRowIsAServerFaultNotADefault(): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A, 'DELETE FROM tenant_settings WHERE tenant_id = ?::uuid', [self::TENANT_A]);

        $this->send('GET', '/settings');

        self::assertSame(500, $this->httpStatus());
        self::assertStringNotContainsString('EUR', (string) $this->client()->getResponse()->getContent());

        // And the 500 is the house's stated fault, not an accident (a TypeError on a null currency would also be a 500).
        $this->actAs(self::TENANT_A);
        $this->expectException(WorkspaceNotConfigured::class);
        self::getContainer()->get(SettingsReader::class)->read();
    }
}
