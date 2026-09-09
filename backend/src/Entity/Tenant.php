<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\IllegalTransition;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The registry. Not tenant-scoped and not RLS'd -- it is the table every other
 * table's tenant_id points at (ADR-006 §6.3, ARCHITECTURE S-10).
 *
 * `status` is the column ADR-006 V-11 requires and that nothing in the project
 * defined. It also answers ADR-007's open item: a deleted *tenant* is a
 * different problem from a deleted row, so tenant closure is a status
 * transition and never a DELETE. Removing the registry row would strand parked
 * envelopes in `messenger_messages` outside the tenant boundary (O-7) and would
 * let a replayed message run to completion against an empty world (O-8);
 * a status leaves both queryable and both fixable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenant')]
#[ORM\UniqueConstraint(name: 'tenant_slug_key', columns: ['slug'])]
class Tenant
{
    /** Provisioned, not yet usable. Login is refused. */
    public const STATUS_PENDING = 'pending';

    /** The only status that permits a login (ADR-006 V-11). */
    public const STATUS_ACTIVE = 'active';

    /** Billing or abuse. Login refused, data retained, queue drained. */
    public const STATUS_SUSPENDED = 'suspended';

    /** Terminated. Login refused; retention and purge are a W6 decision, deliberately not taken. */
    public const STATUS_CLOSED = 'closed';

    /**
     * ADR-006 §2.1 step 2's fixed sentinel: it exists in the registry, owns no
     * `app_user` rows, and gives an unresolved slug the same statement sequence
     * as a resolved one (V-8). Its own status keeps V-11 from having to special-case it.
     */
    public const STATUS_SENTINEL = 'sentinel';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * Lower-case by CHECK constraint. ADR-006 §6.4(a): the rate-limit key and
     * the lookup key have to agree, or `ACME` buys a fresh bucket for `acme`;
     * normalising once at the edge only works if the store refuses anything else.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'status_changed_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $statusChangedAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, string $slug, string $name)
    {
        $this->id = $id;
        $this->slug = $slug;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->statusChangedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** ADR-006 V-11's only consumer: a tenant that is not active cannot be logged into. */
    public function permitsLogin(): bool
    {
        return self::statusPermitsLogin($this->status);
    }

    /**
     * The same rule, over a status read as a scalar.
     *
     * `API.md` §7.1 step 4 reads `tenant.status` on the **`erp_auth`** connection,
     * which holds a column grant and no entity mapping — the pre-authentication
     * path never hydrates a `Tenant`, and it must not: step 6 *"costs no
     * statement"* precisely because the status came out of step 4's transaction
     * as a value, and an implementation that re-read the row to build an object
     * would add a statement to one branch and break E-7 by the clock.
     *
     * So V-11's rule needs a second *entry point* and must not acquire a second
     * *definition*: `permitsLogin()` delegates here, `TenantStatusTest` asserts
     * the two agree over every declared status, and there is exactly one
     * comparison in the codebase.
     *
     * **There is deliberately no `sentinel` branch, here or above.** ST-7.3
     * requires the sentinel to be routed to the unresolved-slug answer rather
     * than to the status answer, and a special case here is the one place that
     * routing could be quietly moved to the wrong side.
     */
    public static function statusPermitsLogin(string $status): bool
    {
        return self::STATUS_ACTIVE === $status;
    }

    /**
     * API-O-16, ruled here rather than left open.
     *
     * -----------------------------------------------------------------------
     * What is constrained, and what deliberately is not
     * -----------------------------------------------------------------------
     * `docs/API.md` §8.3 excludes `tenant` from API-15's domain **in writing**,
     * on a reachability argument: §4.3's first bullet says nothing in that
     * contract creates a tenant or changes its status, so no API operation can
     * call this. The exclusion is from API-15, not from having a rule — §8.3
     * says so in terms, and routes it as API-O-16.
     *
     * Two rules are enforced. Neither is a lifecycle policy.
     *
     * **1. The target must be a status this class declares**, derived by
     * reflection over its own `STATUS_*` constants and never restated. This is
     * the same value space `tenant_status_values` already encodes; all it does
     * is move the refusal from a deferred 23514 at `flush()` — opaque, far from
     * the call site, and rendered as a 500 — to the call itself. It cannot
     * refuse a caller the `CHECK` would have accepted, so it is free.
     *
     * **2. Nothing moves into or out of `sentinel`.** ADR-006 §2.1 step 2's
     * sentinel is a fixed registry row, seeded by Version20260906000100 with
     * `status = 'sentinel'` — it is not reached through this method by anything,
     * ever. The `CHECK` cannot see this rule: `sentinel` is one of its five
     * accepted values, so `changeStatus(self::STATUS_ACTIVE)` on the sentinel is
     * accepted by the database and makes `permitsLogin()` true for a tenant that
     * owns no `app_user` rows (`app_user_not_sentinel_tenant` guarantees it never
     * will), while the reverse turns a real tenant into a second sentinel and
     * breaks the V-8 slug-resolution invariant. That is a security rule, not a
     * lifecycle one, which is why it is taken here and the rest is not.
     *
     * **What is not enforced, and why that is a decision.** No legal-pair matrix
     * over `pending`/`active`/`suspended`/`closed` — in particular
     * `closed → active` is left open. `STATUS_CLOSED`'s own docblock records that
     * retention and purge after termination are *"a W6 decision, deliberately not
     * taken"*, and whether a terminated tenant can be resurrected is that
     * decision, not this one. Inventing the matrix inside a defect fix would be
     * `BE-LEAD` taking a `PO`/`ARCH` ruling by implication. It stays with
     * API-O-16, whose owners are `SEC` + `ARCH`.
     */
    public function changeStatus(string $status): void
    {
        if (!\in_array($status, self::declaredStatuses(), true)) {
            throw IllegalTransition::because(
                self::class,
                __FUNCTION__,
                $this->status,
                \sprintf('"%s" is not a status this entity declares.', $status),
            );
        }

        if (self::STATUS_SENTINEL === $status || self::STATUS_SENTINEL === $this->status) {
            throw IllegalTransition::because(
                self::class,
                __FUNCTION__,
                $this->status,
                'ADR-006 §2.1 step 2\'s sentinel is a fixed registry row; no tenant moves into or out of it.',
            );
        }

        $this->status = $status;
        $this->statusChangedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->statusChangedAt;
    }

    /**
     * The declared value space, read off this class rather than written down a
     * second time. A sixth status added above is admitted by this method the
     * moment it exists — and refused by `tenant_status_values` until a migration
     * catches up, which is the right order for the two to fail in.
     *
     * Typed `list<string>` rather than `non-empty-list<string>`: the `assert()`
     * below is a no-op under `zend.assertions=-1`, so the non-emptiness is a
     * development-time check and not a guarantee the type can carry. The
     * degradation is fail-closed — an empty set refuses every status — and
     * `TenantStatusTest::everyDeclaredStatusExceptTheSentinelIsAccepted` is what
     * would catch it.
     *
     * @return list<string>
     */
    private static function declaredStatuses(): array
    {
        $statuses = [];

        /** @var array<string, mixed> $constants */
        $constants = (new \ReflectionClass(self::class))->getConstants();

        foreach ($constants as $name => $value) {
            if (str_starts_with($name, 'STATUS_') && \is_string($value)) {
                $statuses[] = $value;
            }
        }

        \assert([] !== $statuses);

        return $statuses;
    }
}
