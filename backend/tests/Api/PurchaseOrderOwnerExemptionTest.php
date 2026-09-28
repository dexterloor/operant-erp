<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Modules\UserManagement\Domain\PermissionCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * **DSoD-1's owner exemption is the owner MARKER, never a role's name** — V2-R-19 + V2-S-5, `SEC`'s Q8 ruling
 * (`output9-sales-7-sec.md` §3), shipped by sales step 6 C6a; the owner acknowledged the notice (`-ruling.md` (c)).
 *
 * "The caller is the owner" means: the permission set E-1 enforced for this request contains `OwnerMarker::CODE`
 * (`tenant_settings.edit`). Each case runs on BOTH of SoD-1's controlled codes — approval and cancellation — against an
 * order the caller raised, so the only variable is the extra role the caller holds.
 *
 * `SEC` §3.4's five red-first tests, and what the shipped name test (`OWNER_ROLE_NAME = 'owner'`) answered to each —
 * run and pasted in `docs/session13/output9-sales-7-progress.md` before the switch:
 *  1. a role named `owner` conferring purchasing codes but NOT the marker → refused  (was: exempt — RED)
 *  2. a `purchasing_manager` creator → refused                                        (was: refused — the A1.1 control)
 *  3. a role named `co-owner` conferring the marker → exempt                          (was: refused — RED)
 *  4. a role named `owner` conferring EVERY catalogue code (the seeded set), derived → exempt (was: exempt — the control)
 *  5. a role named `owner` with ZERO codes → refused                                   (was: exempt — RED; the shipped fixture)
 */
final class PurchaseOrderOwnerExemptionTest extends PurchaseOrderTestCase
{
    /** @return iterable<string, array{string}> */
    public static function controlledCodes(): iterable
    {
        yield 'approve' => ['approve'];
        yield 'cancel' => ['cancel'];
    }

    #[Test]
    #[DataProvider('controlledCodes')]
    public function aRoleNamedOwnerWithoutTheMarkerIsNotExempt(string $act): void
    {
        $order = $this->raisedByTheCaller();
        $this->grantRoleWithCodes(self::TENANT_A, (string) $this->actorId, DefaultRoles::OWNER,
            ['purchase_order.view', 'purchase_order.approve', 'purchase_order.cancel']);

        $this->act($act, $order);

        $this->assertRefused($act, $order);
    }

    #[Test]
    #[DataProvider('controlledCodes')]
    public function aPurchasingManagerCreatorIsNotExempt(string $act): void
    {
        $order = $this->raisedByTheCaller();
        $this->grantRoleWithCodes(self::TENANT_A, (string) $this->actorId, DefaultRoles::PURCHASING_MANAGER,
            DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER));

        $this->act($act, $order);

        $this->assertRefused($act, $order);
    }

    #[Test]
    #[DataProvider('controlledCodes')]
    public function aCoOwnerHoldingTheMarkerUnderAnotherNameIsExempt(string $act): void
    {
        $order = $this->raisedByTheCaller();
        $this->grantRoleWithCodes(self::TENANT_A, (string) $this->actorId, 'co-owner', [OwnerMarker::CODE]);

        $this->act($act, $order);

        $this->assertExempt($act);
    }

    #[Test]
    #[DataProvider('controlledCodes')]
    public function aRoleConferringEveryCatalogueCodeIsExempt(string $act): void
    {
        $order = $this->raisedByTheCaller();
        // Derived, never typed (RED-3 N-7): the seeded `owner`'s set is the whole catalogue.
        $this->grantRoleWithCodes(self::TENANT_A, (string) $this->actorId, DefaultRoles::OWNER, PermissionCatalogue::codes());

        $this->act($act, $order);

        $this->assertExempt($act);
    }

    #[Test]
    #[DataProvider('controlledCodes')]
    public function aRoleNamedOwnerWithNoCodesIsNotExempt(string $act): void
    {
        $order = $this->raisedByTheCaller();
        $this->grantRoleWithCodes(self::TENANT_A, (string) $this->actorId, DefaultRoles::OWNER, []);

        $this->act($act, $order);

        $this->assertRefused($act, $order);
    }

    /** An order the actor raised: a draft with a line (approvable) — cancellable from `draft` too (§8.3). */
    private function raisedByTheCaller(): string
    {
        $draft = $this->seedDraft(self::TENANT_A, self::freshNumber('PO'), (string) $this->actorId);
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        return $draft['order'];
    }

    private function act(string $act, string $order): void
    {
        'approve' === $act
            ? $this->send('POST', '/purchase-orders/'.$order.'/approval', [])
            : $this->send('POST', '/purchase-orders/'.$order.'/cancellation', ['reason' => 'C6a']);
    }

    private function assertRefused(string $act, string $order): void
    {
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('approve' === $act ? 'purchase_order.approver_is_not_the_creator' : 'purchase_order.canceller_is_not_the_creator',
            $this->json()['rule']);
        self::assertSame([['status' => PurchaseOrder::STATUS_DRAFT]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM purchase_order WHERE id = ?::uuid', [$order]));
    }

    private function assertExempt(string $act): void
    {
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('approve' === $act ? PurchaseOrder::STATUS_ORDERED : PurchaseOrder::STATUS_CANCELLED, $this->json()['status']);
    }
}
