<?php

declare(strict_types=1);

namespace App\SharedKernel\Reference;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reference data (ADR-002 §6). No tenant_id, no RLS, and never the tenant side
 * of a composite foreign key.
 *
 * `dimension` is held to a fixed set by a CHECK in the migration. It is the
 * column that makes a unit conversion refusable rather than wrong: kilograms
 * and litres are both quantities, and a stock movement that converts between
 * them is a bug, not a rounding question.
 */
#[ORM\Entity]
#[ORM\Table(name: 'unit_of_measure')]
class UnitOfMeasure
{
    public const DIMENSION_COUNT = 'count';
    public const DIMENSION_MASS = 'mass';
    public const DIMENSION_VOLUME = 'volume';
    public const DIMENSION_LENGTH = 'length';
    public const DIMENSION_TIME = 'time';

    #[ORM\Id]
    #[ORM\Column(type: Types::TEXT)]
    private string $code;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $dimension;

    public function __construct(string $code, string $name, string $dimension)
    {
        $this->code = $code;
        $this->name = $name;
        $this->dimension = $dimension;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDimension(): string
    {
        return $this->dimension;
    }
}
