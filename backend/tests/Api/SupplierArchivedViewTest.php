<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Suppliers\Application\SupplierListCriteria;
use App\Platform\Http\Pagination\Cursor;
use PHPUnit\Framework\Attributes\Test;

/**
 * **C5 C2a — the `/suppliers` Archived view: `ListSuppliers?archived=true`** (`docs/session13/c5-design.md` §4.2, R1.1 L-2;
 * `IA.md` §5.6 row "`/suppliers` Archived", D-a). The API's first **paged** Archived view.
 *
 * `archived=true` returns the archived suppliers **instead of** the live ones (TB-3); the one order is `archived_at`,
 * most recently archived first (`deleted_at DESC, id DESC`); the cursor key is the column's text with microseconds.
 * `sort` is per view: `name`/`code` on the live view only, `archived_at` on the Archived view only (400-class refusal
 * `unserved_sort`); a live-view cursor sent to the Archived view is `malformed_cursor`, never a 500. Archived rows are
 * seeded by owner SQL until `DeleteSupplier` ships (C2b).
 */
final class SupplierArchivedViewTest extends PurchaseOrderTestCase
{
    /** TB-3: the Archived view holds the archived suppliers only, newest first; the live view is unchanged. */
    #[Test]
    public function theArchivedViewHoldsArchivedSuppliersOnlyNewestFirst(): void
    {
        $this->seedSupplier(self::TENANT_A, 'Still Live');
        $this->archive($this->seedSupplier(self::TENANT_A, 'Archived First'), '2026-10-01 10:00:00+00');
        $this->archive($this->seedSupplier(self::TENANT_A, 'Archived Last'), '2026-10-05 10:00:00+00');
        $this->archive($this->seedSupplier(self::TENANT_A, 'Archived Middle'), '2026-10-03 10:00:00+00');

        self::assertSame(['Archived Last', 'Archived Middle', 'Archived First'], $this->names('?archived=true'));
        self::assertSame(['Archived Last', 'Archived Middle', 'Archived First'], $this->names('?archived=1&sort=archived_at'));
        self::assertSame(['Still Live'], $this->names('?archived=false'));
        self::assertSame(['Still Live'], $this->names('?archived=0'));
        self::assertSame(['Still Live'], $this->names(''));

        $this->send('GET', '/suppliers?archived=true');
        foreach ($this->json()['items'] as $item) {
            self::assertNotNull($item['archived_at'], 'An item of the Archived view carries no archived_at.');
            $this->send('GET', '/suppliers/'.$item['id']);
            self::assertSame($this->json(), $item, 'An Archived-view item is not GetSupplier\'s body.');
        }
    }

    /** `archived` takes true/false/1/0 only (`WarehouseCollectionProvider::archived()`'s values). */
    #[Test]
    public function archivedIsABoolean(): void
    {
        $this->send('GET', '/suppliers?archived=yes');
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '?archived', 'code' => 'not_a_boolean', 'message' => 'archived must be true or false.']], $this->json()['errors']);
    }

    /**
     * Mutation M9: `sort` is per view — the live orders are refused on the Archived view and `archived_at` on the live view,
     * each naming the view's own values (RED L-2).
     */
    #[Test]
    public function eachViewServesOnlyItsOwnOrders(): void
    {
        $this->archive($this->seedSupplier(self::TENANT_A, 'Gone'), '2026-10-01 10:00:00+00');

        foreach (['name', 'code'] as $sort) {
            $this->send('GET', '/suppliers?archived=true&sort='.$sort);
            self::assertSame(422, $this->httpStatus(), $sort.' was served on the Archived view.');
            self::assertSame('unserved_sort', $this->json()['errors'][0]['code']);
            self::assertSame('The orders this view offers are archived_at.', $this->json()['errors'][0]['message']);
        }

        $this->send('GET', '/suppliers?sort=archived_at');
        self::assertSame(422, $this->httpStatus(), 'archived_at was served on the live view.');
        self::assertSame('The orders this view offers are name and code.', $this->json()['errors'][0]['message']);

        self::assertSame(['name', 'code', 'archived_at'], SupplierListCriteria::SORTS,
            'The declared enum must list a live order first (RED L-2: a client sending enum[0] alone, with no archived, is served).');
    }

    /**
     * Mutation M7: the cursor key keeps microseconds. Two suppliers archived within one millisecond are walked one page at
     * a time, forward and back, each visited exactly once; a millisecond key would skip one.
     */
    #[Test]
    public function aWalkAcrossOneMillisecondVisitsEachArchivedSupplierOnce(): void
    {
        $this->archive($this->seedSupplier(self::TENANT_A, 'Micro A'), '2026-10-05 10:00:00.123100+00');
        $this->archive($this->seedSupplier(self::TENANT_A, 'Micro B'), '2026-10-05 10:00:00.123400+00');
        $this->archive($this->seedSupplier(self::TENANT_A, 'Micro C'), '2026-10-05 10:00:00.123700+00');

        $seen = [];
        $cursor = null;
        $previous = null;
        do {
            $this->send('GET', '/suppliers?archived=true&limit=1'.(null === $cursor ? '' : '&after='.$cursor));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            $seen = [...$seen, ...array_column($page['items'], 'name')];
            $cursor = $page['next_cursor'];
            $previous = $page['previous_cursor'];
        } while (null !== $cursor && \count($seen) < 10);
        self::assertSame(['Micro C', 'Micro B', 'Micro A'], $seen, 'Forward walk across one millisecond skipped or repeated a supplier.');

        $back = [];
        $cursor = $previous;
        while (null !== $cursor && \count($back) < 10) {
            $this->send('GET', '/suppliers?archived=true&limit=1&before='.$cursor);
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            $back = [...$back, ...array_column($page['items'], 'name')];
            $cursor = $page['previous_cursor'];
        }
        self::assertSame(['Micro B', 'Micro C'], $back, 'Backward walk across one millisecond skipped or repeated a supplier.');
    }

    /** Equal archive instants are ordered by id, descending (TB-2a's tie), and paged without loss. */
    #[Test]
    public function equalArchiveInstantsAreTiedById(): void
    {
        $ids = [];
        foreach (['Tie A', 'Tie B', 'Tie C'] as $name) {
            $ids[$name] = $this->seedSupplier(self::TENANT_A, $name);
            $this->archive($ids[$name], '2026-10-05 10:00:00+00');
        }
        $expected = array_values($ids);
        rsort($expected);

        $seen = [];
        $cursor = null;
        do {
            $this->send('GET', '/suppliers?archived=true&limit=2'.(null === $cursor ? '' : '&after='.$cursor));
            $page = $this->json();
            $seen = [...$seen, ...array_column($page['items'], 'id')];
            $cursor = $page['next_cursor'];
        } while (null !== $cursor && \count($seen) < 10);

        self::assertSame($expected, $seen);
    }

    /**
     * Mutation M8 (design §4.2 trap 2): a live-view cursor (a name key) sent to the Archived view is refused as
     * `malformed_cursor`, never cast to a timestamp (a 500).
     */
    #[Test]
    public function aLiveViewCursorSentToTheArchivedViewIsMalformedNotA500(): void
    {
        $this->archive($this->seedSupplier(self::TENANT_A, 'Gone'), '2026-10-01 10:00:00+00');
        $cursor = new Cursor('Alpha Supplies', '6f1c0f8e-6c3b-4c39-9a5c-5d0f5b3e4a21')->encode();

        foreach (['after', 'before'] as $direction) {
            $this->send('GET', '/suppliers?archived=true&'.$direction.'='.$cursor);
            self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame('malformed_cursor', $this->json()['errors'][0]['code']);
        }
    }

    /** `q` is offered on the Archived view too (design §4.2; `UX`+`DATA` to sign): the same two leaves, over archived rows only. */
    #[Test]
    public function theArchivedViewCanBeSearched(): void
    {
        $this->seedSupplier(self::TENANT_A, 'Acme Live');
        $this->archive($this->seedSupplier(self::TENANT_A, 'Acme Archived'), '2026-10-01 10:00:00+00');
        $this->archive($this->seedSupplier(self::TENANT_A, 'Zeta Archived'), '2026-10-02 10:00:00+00');

        self::assertSame(['Acme Archived'], $this->names('?archived=true&q=acme'));
        self::assertSame(['Acme Live'], $this->names('?q=acme'));
    }

    /**
     * API-22: `tenant_has_rows` is the unfiltered probe — an empty Archived view in a workspace with live suppliers says
     * the workspace has suppliers ("none archived"; `UX` to confirm the empty-state copy).
     */
    #[Test]
    public function anEmptyArchivedViewStillSaysTheWorkspaceHasSuppliers(): void
    {
        $this->seedSupplier(self::TENANT_A, 'Only Live');

        self::assertSame([], $this->names('?archived=true'));
        self::assertTrue($this->json()['tenant_has_rows']);
    }

    /** RLS: another workspace's archived suppliers are not in the view. */
    #[Test]
    public function anotherWorkspacesArchivedSuppliersAreNotListed(): void
    {
        $this->archive($this->seedSupplier(self::TENANT_B, 'Theirs Archived'), '2026-10-01 10:00:00+00', self::TENANT_B);

        self::assertSame([], $this->names('?archived=true'));
    }

    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function names(string $query): array
    {
        $this->send('GET', '/suppliers'.$query);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'name');
    }

    private function archive(string $id, string $at, string $tenantId = self::TENANT_A): void
    {
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE supplier SET deleted_at = ?::timestamptz WHERE id = ?::uuid', [$at, $id]);
    }
}
