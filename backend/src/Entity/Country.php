<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reference data (ADR-002 §6). No tenant_id, no RLS, and never the tenant side
 * of a composite foreign key.
 *
 * The primary key is the ISO 3166-1 alpha-2 code, held to shape by a CHECK in
 * the migration. Addresses store the code, so `warehouse.country_code` is a
 * single-column association to this table and nothing more.
 */
#[ORM\Entity]
#[ORM\Table(name: 'country')]
class Country
{
    #[ORM\Id]
    #[ORM\Column(type: Types::TEXT)]
    private string $code;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    public function __construct(string $code, string $name)
    {
        $this->code = $code;
        $this->name = $name;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
