<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Warehouses slice 1 C1 — `ListWarehouses`**, `GET /api/v1/warehouses`, `warehouse.view`, *(bounded)*, `archived`; `q`
 * (`docs/session13/warehouses-s1-design.md` §1.6 and FINAL item 2; `RoleListTest`'s shape).
 *
 * The registry-wide machinery covers the envelope, the undeclared-parameter refusal, `SearchContractTest`'s TB-5 column
 * check and `QueryParameterContractTest`'s API-19c limb 1 for `archived`. What is here is what those cannot see: the two
 * views (mutations 1, 2), the order, what `q` matches and does not — a prefix (mutation 3) of the code as stored
 * (mutation 4), never the name — the empty-set probe, and that every item is the `GetWarehouse` body.
 */
final class WarehouseListTest extends PurchaseOrderTestCase
{
    /** Mutations 1 and 2: the live view holds live warehouses only; `archived=true` holds the archived ones instead. */
    #[Test]
    public function theLiveViewExcludesArchivedWarehousesAndTheArchivedViewHoldsOnlyThem(): void
    {
        $live = $this->warehouse('LIVE-1', 'Live depot');
        $archived = $this->warehouse('GONE-1', 'Archived depot');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->send('GET', '/warehouses');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$live], array_column($this->json()['items'], 'id'));
        self::assertSame([null, null, true], [$this->json()['next_cursor'], $this->json()['previous_cursor'], $this->json()['tenant_has_rows']]);

        $this->send('GET', '/warehouses?archived=true');
        self::assertSame(200, $this->httpStatus());
        $items = $this->json()['items'];
        self::assertSame([$archived], array_column($items, 'id'));
        self::assertNotNull($items[0]['archived_at']);

        $this->send('GET', '/warehouses?archived=false');
        self::assertSame([$live], array_column($this->json()['items'], 'id'), '`archived=false` is not the default live view.');
    }

    /** `code`, then `id`, ascending — seeded out of order, with names in the opposite order to the codes. */
    #[Test]
    public function warehousesComeBackInCodeOrder(): void
    {
        $c = $this->warehouse('WH-C', 'Alpha');
        $a = $this->warehouse('WH-A', 'Charlie');
        $b = $this->warehouse('WH-B', 'Bravo');

        $this->send('GET', '/warehouses');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([$a, $b, $c], array_column($this->json()['items'], 'id'), 'Not ordered by code.');
    }

    /** Mutation 3: `q` is a prefix of `warehouse.code`, and of nothing else — not the name, not the middle of a code. */
    #[Test]
    public function qIsACodePrefixAndMatchesNothingElse(): void
    {
        $match = $this->warehouse('EU-ROT', 'Rotterdam');
        $this->warehouse('X-EU', 'Middle of a code');
        $this->warehouse('NL-AMS', 'EU-AMS in the name only');

        $this->send('GET', '/warehouses?q=EU');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$match], array_column($this->json()['items'], 'id'));
    }

    /** Mutation 4: codes match **as stored** (TB-5a) — `q=eu` does not find `EU-ROT`, and `q=eu` finds `eu-lower`. */
    #[Test]
    public function qMatchesTheCodeAsStored(): void
    {
        $upper = $this->warehouse('EU-ROT', 'Upper');
        $lower = $this->warehouse('eu-low', 'Lower');

        $this->send('GET', '/warehouses?q=eu');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([$lower], array_column($this->json()['items'], 'id'), '`q=eu` folded case: codes match as stored (TB-5a).');

        $this->send('GET', '/warehouses?q=EU');
        self::assertSame([$upper], array_column($this->json()['items'], 'id'));
    }

    /** `%`, `_` and `\` are escaped: they match themselves, never any character. */
    #[Test]
    public function qWithWildcardsMatchesThemLiterally(): void
    {
        $this->warehouse('PLAIN', 'Plain');
        $percent = $this->warehouse('%OFF', 'Percent');
        $underscore = $this->warehouse('A_B', 'Underscore');
        $this->warehouse('AXB', 'Not an underscore');

        $this->send('GET', '/warehouses?q=%25');
        self::assertSame([$percent], array_column($this->json()['items'], 'id'), '`q=%` matched a code that does not start with %.');

        $this->send('GET', '/warehouses?q=A_');
        self::assertSame([$underscore], array_column($this->json()['items'], 'id'), '`q=A_` let `_` match any character.');
    }

    #[Test]
    public function archivedRefusesAnythingButABoolean(): void
    {
        $this->send('GET', '/warehouses?archived=maybe');

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** A bounded list: no paging or sort parameter is declared, so each is refused (§10.3 step 4, API-19). */
    #[Test]
    public function aBoundedListRefusesPagingParameters(): void
    {
        foreach (['limit=1', 'after=x', 'before=x', 'sort=code'] as $query) {
            $this->send('GET', '/warehouses?'.$query);
            self::assertSame(422, $this->httpStatus(), $query.': '.$this->client()->getResponse()->getContent());
        }
    }

    /**
     * API-22: `tenant_has_rows` is probed only on an empty set, with no filter predicate. An empty workspace says false; an
     * empty archived view of a workspace with live warehouses says true; a populated set runs no probe.
     */
    #[Test]
    public function theEmptyProbeRunsOnlyOnAnEmptySet(): void
    {
        $recorder = self::getContainer()->get('test.sql_recorder.app');
        \assert($recorder instanceof \App\Tests\Support\SqlRecorder);
        $probe = '/EXISTS\s*\(\s*SELECT 1 FROM warehouse\s*\)/i';

        $this->send('GET', '/warehouses');
        self::assertSame([[], false], [$this->json()['items'], $this->json()['tenant_has_rows']], 'An empty workspace must say tenant_has_rows false.');

        $this->warehouse('ONLY', 'Only');
        $recorder->start();
        $this->send('GET', '/warehouses?archived=true');
        $recorder->stop();
        self::assertSame([[], true], [$this->json()['items'], $this->json()['tenant_has_rows']]);
        self::assertCount(1, $recorder->matching($probe), 'The empty set did not run exactly one tenant_has_rows probe.');

        $recorder->start();
        $this->send('GET', '/warehouses');
        $recorder->stop();
        self::assertNotSame([], $this->json()['items']);
        self::assertSame([], $recorder->matching($probe), 'A populated set ran the tenant_has_rows probe.');
    }

    /** One DTO in behaviour: every item equals that warehouse's `GetWarehouse` body, in both views. */
    #[Test]
    public function eachItemIsTheGetWarehouseBody(): void
    {
        $this->warehouse('CMP-1', 'Compared');
        $gone = $this->warehouse('CMP-2', 'Gone');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$gone]);

        $compared = 0;
        foreach (['/warehouses', '/warehouses?archived=true'] as $path) {
            $this->send('GET', $path);
            self::assertSame(200, $this->httpStatus());
            foreach ($this->json()['items'] as $item) {
                $this->send('GET', '/warehouses/'.$item['id']);
                self::assertSame(200, $this->httpStatus());
                self::assertSame($this->json(), $item, $path.': an item is not its GetWarehouse body.');
                ++$compared;
            }
        }
        self::assertSame(2, $compared);
    }

    /** Another workspace's warehouses never appear. */
    #[Test]
    public function anotherWorkspacesWarehousesAreNotListed(): void
    {
        $mine = $this->warehouse('MINE', 'Mine');
        $this->seedWarehouse(self::TENANT_B, 'Theirs');

        $this->send('GET', '/warehouses');

        self::assertSame([$mine], array_column($this->json()['items'], 'id'));
    }

    private function warehouse(string $code, string $name): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, ?, ?, 'DE')",
            [$id, self::TENANT_A, $code, $name]);

        return $id;
    }
}
