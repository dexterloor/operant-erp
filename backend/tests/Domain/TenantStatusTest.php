<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Platform\Domain\IllegalTransition;
use App\Platform\Tenancy\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * API-O-16 — `Tenant::changeStatus()`, the setter §8.3 excluded from API-15 **in
 * writing** and explicitly did not exonerate.
 *
 * ---------------------------------------------------------------------------
 * The ruling this file enforces
 * ---------------------------------------------------------------------------
 * §8.3 excludes `tenant` from API-15's matrix because no §4.2 operation reaches
 * it, and routes the setter as API-O-16 because *"an unguarded status setter on
 * the one table whose status decides whether anybody may log in at all is worth a
 * rule"*. The rule taken is **two clauses, and deliberately not a lifecycle
 * matrix**:
 *
 * 1. **the target must be a status the entity declares** — the same value space
 *    `tenant_status_values` encodes, moved from a deferred 23514 at `flush()` to
 *    the call site, and derived by reflection so it cannot drift from the
 *    constants;
 * 2. **nothing moves into or out of `sentinel`** — the one transition rule the
 *    `CHECK` constraint structurally cannot express, because `sentinel` is one of
 *    its five accepted values. `changeStatus(STATUS_ACTIVE)` on ADR-006 §2.1
 *    step 2's sentinel is accepted by Postgres today and makes `permitsLogin()`
 *    true for a tenant that `app_user_not_sentinel_tenant` guarantees owns no
 *    users; the reverse mints a second sentinel and breaks V-8's slug resolution.
 *
 * **What is deliberately not enforced, so that the omission is a decision rather
 * than an oversight:** no legality matrix over `pending`/`active`/`suspended`/
 * `closed`, and in particular `closed → active` is left open. `STATUS_CLOSED`'s
 * own docblock records that what happens after termination is *"a W6 decision,
 * deliberately not taken"*. That decision is `PO`'s and `ARCH`'s, and taking it
 * by implication inside a defect fix is how a ruling gets made by nobody.
 * API-O-16 stays open for it.
 */
final class TenantStatusTest extends TestCase
{
    /**
     * The value space, by reflection. Every clause below iterates this rather than
     * a list, so a sixth status is in the domain the day it is declared.
     *
     * @return list<string>
     */
    private static function declaredStatuses(): array
    {
        $statuses = [];

        foreach ((new \ReflectionClass(Tenant::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'STATUS_') && \is_string($value)) {
                $statuses[] = $value;
            }
        }

        sort($statuses);

        return $statuses;
    }

    private static function tenantIn(string $status): Tenant
    {
        $tenant = new Tenant(Uuid::v4(), 'acme', 'Acme');

        (new \ReflectionClass(Tenant::class))->getProperty('status')->setValue($tenant, $status);

        return $tenant;
    }

    /**
     * Clause 1, the accepting half — a guard that refused everything would satisfy
     * the two refusal clauses below and lock the registry.
     *
     * **Mutation:** drop `STATUS_SUSPENDED` from the `CHECK`-equivalent by making
     * `declaredStatuses()` on the entity return a hard-coded pair; red.
     */
    #[Test]
    public function everyDeclaredStatusExceptTheSentinelIsAccepted(): void
    {
        $accepted = 0;

        foreach (self::declaredStatuses() as $status) {
            if (Tenant::STATUS_SENTINEL === $status) {
                continue;
            }

            $tenant = self::tenantIn(Tenant::STATUS_PENDING);
            $tenant->changeStatus($status);

            self::assertSame($status, $tenant->getStatus());
            ++$accepted;
        }

        self::assertGreaterThan(0, $accepted, 'No status was accepted, so this clause had no subject.');
    }

    /**
     * Clause 1, the refusing half.
     *
     * **Mutation:** delete the membership check from `Tenant::changeStatus()`;
     * red on the first value.
     */
    #[Test]
    public function aStatusTheEntityDoesNotDeclareIsRefused(): void
    {
        foreach (['actve', 'ACTIVE', '', 'deleted', 'Active '] as $undeclared) {
            $tenant = self::tenantIn(Tenant::STATUS_ACTIVE);

            try {
                $tenant->changeStatus($undeclared);
                self::fail(\sprintf('changeStatus("%s") was accepted; only tenant_status_values would have refused it, at flush.', $undeclared));
            } catch (IllegalTransition $e) {
                self::assertSame('tenant.'.IllegalTransition::RULE, $e->rule());
                self::assertSame(Tenant::STATUS_ACTIVE, $tenant->getStatus(), 'The refusal left the row modified.');
            }
        }
    }

    /**
     * Clause 2, inbound. The `CHECK` accepts every one of these.
     *
     * **Mutation:** delete the sentinel clause from `Tenant::changeStatus()`; red.
     */
    #[Test]
    public function noTenantMovesIntoTheSentinel(): void
    {
        $tried = 0;

        foreach (self::declaredStatuses() as $status) {
            if (Tenant::STATUS_SENTINEL === $status) {
                continue;
            }

            ++$tried;
            $tenant = self::tenantIn($status);

            $this->expectingRefusal($tenant, Tenant::STATUS_SENTINEL);
            self::assertSame($status, $tenant->getStatus());
        }

        self::assertGreaterThan(0, $tried, 'No inbound transition was tried, so this clause had no subject.');
    }

    /**
     * Clause 2, outbound — the branch that would make `permitsLogin()` true for
     * the sentinel.
     *
     * **Mutation:** narrow the sentinel clause to the inbound direction only; red.
     */
    #[Test]
    public function theSentinelDoesNotLeaveItsStatus(): void
    {
        $tried = 0;

        foreach (self::declaredStatuses() as $status) {
            ++$tried;
            $tenant = self::tenantIn(Tenant::STATUS_SENTINEL);

            $this->expectingRefusal($tenant, $status);
            self::assertSame(Tenant::STATUS_SENTINEL, $tenant->getStatus());
            self::assertFalse($tenant->permitsLogin(), 'V-11 would admit a login against the sentinel.');
        }

        self::assertGreaterThan(0, $tried, 'No outbound transition was tried, so this clause had no subject.');
    }

    /**
     * The entity's vocabulary and the column's `CHECK` are the same five values,
     * both read from their own source rather than from a third list. Clause 1 is
     * only equivalent to the constraint while this holds.
     *
     * **Mutation:** add a `STATUS_ARCHIVED` constant to `Tenant` without a
     * migration; red, and it names the value.
     */
    #[Test]
    public function theDeclaredVocabularyIsTheColumnsCheckConstraint(): void
    {
        $migrations = glob(\dirname(__DIR__, 2).'/migrations/*.php') ?: [];
        sort($migrations);

        $constrained = [];

        // **The last statement about the constraint wins, and a union does not
        // give that.** `RED`: a later forward migration re-issuing
        // `tenant_status_values` with a *narrower* set — which is exactly the
        // shape API-O-18 says this project uses for a `CHECK` change — would leave
        // the withdrawn values in a union, and the check would stay green while
        // the live column had become narrower than `Tenant::STATUS_*`. A `DROP`
        // was invisible for the same reason, so it clears the set here.
        foreach ($migrations as $path) {
            $source = (string) file_get_contents($path);

            if (str_contains($source, 'DROP CONSTRAINT tenant_status_values')) {
                $constrained = [];
            }
            if (1 === preg_match('/CONSTRAINT tenant_status_values CHECK \(status IN \(([^)]*)\)\)/', $source, $matches)) {
                preg_match_all("/'([a-z_]+)'/", $matches[1], $values);
                $constrained = $values[1];
            }
        }

        $constrained = array_values(array_unique($constrained));
        sort($constrained);

        self::assertNotSame([], $constrained, 'No migration declares tenant_status_values, so this clause had no subject.');
        self::assertSame(self::declaredStatuses(), $constrained, 'Tenant::STATUS_* and tenant_status_values disagree about the value space.');
    }

    private function expectingRefusal(Tenant $tenant, string $target): void
    {
        // Captured before the call: reading it in the failure message afterwards
        // reports the status that was just written as the one it came from.
        $from = $tenant->getStatus();

        try {
            $tenant->changeStatus($target);
            self::fail(\sprintf('changeStatus("%s") from "%s" was accepted.', $target, $from));
        } catch (IllegalTransition $e) {
            self::assertSame('tenant.'.IllegalTransition::RULE, $e->rule());
        }
    }
}
