<?php

declare(strict_types=1);

namespace App\Entity;

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
        return self::STATUS_ACTIVE === $this->status;
    }

    public function changeStatus(string $status): void
    {
        $this->status = $status;
        $this->statusChangedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->statusChangedAt;
    }
}
