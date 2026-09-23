<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `ListRoles` — `GET /api/v1/roles`, `role.view`, *(bounded)*, `archived`; `q`. Roles slice 1, C4
 * (`docs/session13/roles-s1-design.md` §2.3).
 *
 * The registry-wide machinery covers the envelope, the undeclared-parameter refusal, `SearchContractTest`'s
 * TB-5 column check and `QueryParameterContractTest`'s API-19c limb 1 for `archived`. What is here is what
 * those cannot see: the two views, the order, what `q` matches and does not, the empty-page probe, and that
 * every item is the `GetRole` body — one DTO in behaviour, not only in declaration. The "no privilege
 * history" rule is `RoleReadTest`'s, whose drives include this operation's three forms.
 *
 * The rig's own principal holds a `Fixture Role …` in tenant A, so the live view is never empty for a caller
 * who can reach it; the tests below compare only the roles they seed where that matters.
 */
final class RoleListTest extends ApiTestCase
{
    #[Test]
    public function theLiveViewExcludesArchivedRolesAndTheArchivedViewHoldsOnlyThem(): void
    {
        $live = $this->seedRole('Live role');
        $archived = $this->seedRole('Archived role');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->send('GET', '/roles');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $ids = array_column($this->json()['items'], 'id');
        self::assertContains($live, $ids);
        self::assertNotContains($archived, $ids);
        self::assertNull($this->json()['next_cursor']);
        self::assertNull($this->json()['previous_cursor']);

        $this->send('GET', '/roles?archived=true');
        self::assertSame(200, $this->httpStatus());
        $items = $this->json()['items'];
        self::assertSame([$archived], array_column($items, 'id'));
        self::assertNotNull($items[0]['archived_at']);

        $this->send('GET', '/roles?archived=false');
        self::assertSame($ids, array_column($this->json()['items'], 'id'), '`archived=false` is not the default live view.');
    }

    /** `name`, then `id`, ascending — the manifest's *"client-side by name"*. */
    #[Test]
    public function rolesComeBackInNameOrder(): void
    {
        foreach (['Order Charlie', 'Order Alpha', 'Order Bravo'] as $name) {
            $this->seedRole($name);
        }

        $this->send('GET', '/roles?q=Order');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['Order Alpha', 'Order Bravo', 'Order Charlie'], array_column($this->json()['items'], 'name'));

        $this->send('GET', '/roles');
        $names = array_column($this->json()['items'], 'name');
        $sorted = $names;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $names, 'The live view is not in name order.');
    }

    /** `q` is a case-sensitive prefix of `role.name`, and of nothing else — not the description, not the middle of a name. */
    #[Test]
    public function qIsANamePrefixAndMatchesNothingElse(): void
    {
        $match = $this->seedRole('Probe Buyer');
        $this->seedRole('Seller', 'Probe in the description only');
        $this->seedRole('Not a Probe prefix');

        $this->send('GET', '/roles?q=Probe');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([$match], array_column($this->json()['items'], 'id'));
    }

    /** `%` and `_` are escaped: they match themselves, never any character. */
    #[Test]
    public function qWithWildcardsMatchesThemLiterally(): void
    {
        $this->seedRole('Plain role');
        $percent = $this->seedRole('%Discount desk');

        $this->send('GET', '/roles?q=%25');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([$percent], array_column($this->json()['items'], 'id'), '`q=%` matched a name that does not start with %.');

        $this->send('GET', '/roles?q=_');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([], $this->json()['items'], '`q=_` matched a name that does not start with _.');
    }

    #[Test]
    public function archivedRefusesAnythingButABoolean(): void
    {
        $this->send('GET', '/roles?archived=maybe');

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * API-22: `tenant_has_rows` is probed only on an empty page, with no filter predicate. `?archived=true`
     * with nothing archived is the reachable empty page (the live view always holds the caller's own role):
     * one `EXISTS` probe, answering true because live roles exist. A populated page issues none.
     */
    #[Test]
    public function theEmptyProbeRunsOnlyOnAnEmptyPage(): void
    {
        $recorder = self::getContainer()->get('test.sql_recorder.app');
        \assert($recorder instanceof \App\Tests\Support\SqlRecorder);
        $probe = '/EXISTS\s*\(\s*SELECT 1 FROM role\s*\)/i';

        $recorder->start();
        $this->send('GET', '/roles?archived=true');
        $recorder->stop();
        self::assertSame(200, $this->httpStatus());
        self::assertSame([], $this->json()['items']);
        self::assertTrue($this->json()['tenant_has_rows'], 'The workspace has live roles, so the archived view\'s empty page must say tenant_has_rows.');
        self::assertCount(1, $recorder->matching($probe), 'The empty page did not run exactly one tenant_has_rows probe.');

        $recorder->start();
        $this->send('GET', '/roles');
        $recorder->stop();
        self::assertSame(200, $this->httpStatus());
        self::assertNotSame([], $this->json()['items']);
        self::assertTrue($this->json()['tenant_has_rows']);
        self::assertSame([], $recorder->matching($probe), 'A populated page ran the tenant_has_rows probe.');
    }

    /** One DTO in behaviour: every item equals that role's `GetRole` body, in both views. */
    #[Test]
    public function eachItemIsTheGetRoleBody(): void
    {
        $role = $this->seedRole('Compared role', 'Described', ['role.view', 'inventory_item.view']);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $this->seedPrincipal(self::TENANT_A, []), $role]);
        $gone = $this->seedRole('Gone role', '', ['role.view']);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$gone]);

        $compared = 0;
        foreach (['/roles', '/roles?archived=true'] as $path) {
            $this->send('GET', $path);
            self::assertSame(200, $this->httpStatus());
            foreach ($this->json()['items'] as $item) {
                $this->send('GET', '/roles/'.$item['id']);
                self::assertSame(200, $this->httpStatus());
                self::assertSame($this->json(), $item, $path.': an item is not its GetRole body.');
                ++$compared;
            }
        }
        self::assertGreaterThanOrEqual(3, $compared, 'Too few items were compared for the check to mean anything.');
    }

    /**
     * @param list<string> $codes
     */
    private function seedRole(string $name, string $description = '', array $codes = []): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name, description) VALUES (?::uuid, ?::uuid, ?, ?)',
            [$role, self::TENANT_A, $name, $description]);
        foreach ($codes as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $role, $code]);
        }

        return $role;
    }
}
