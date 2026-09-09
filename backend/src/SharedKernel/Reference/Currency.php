<?php

declare(strict_types=1);

namespace App\SharedKernel\Reference;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reference data (ADR-002 §6). No tenant_id, no RLS, and never the tenant side
 * of a composite foreign key -- an ISO 4217 code means the same thing in every
 * tenant, so duplicating the catalogue per tenant would only let two tenants
 * disagree about what `EUR` is.
 *
 * The primary key is the code itself. A surrogate uuid here would buy nothing:
 * the natural key is short, immutable, and already what every child column
 * stores, so children reference `currency (code)` directly and a join is
 * avoidable for the common read.
 *
 * `minor_unit` is what stops money formatting from guessing: JPY has 0 minor
 * units and EUR has 2, and S-12 keeps amounts in numeric(19,4) regardless.
 */
#[ORM\Entity]
#[ORM\Table(name: 'currency')]
class Currency
{
    #[ORM\Id]
    #[ORM\Column(type: Types::TEXT)]
    private string $code;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(name: 'minor_unit', type: Types::SMALLINT)]
    private int $minorUnit;

    public function __construct(string $code, string $name, int $minorUnit)
    {
        $this->code = $code;
        $this->name = $name;
        $this->minorUnit = $minorUnit;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMinorUnit(): int
    {
        return $this->minorUnit;
    }
}
