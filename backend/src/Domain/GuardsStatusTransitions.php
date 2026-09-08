<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * API-15's guard, in one place.
 *
 * Five of the six methods `docs/API.md` §8.2 found unguarded need the same three
 * lines, and five copies of them is five places for the sixth to be forgotten.
 *
 * **The legal source set is passed in, never held here.** §8.3's matrix is the
 * authority for which sources are legal, and `TransitionGuardTest` carries that
 * matrix as data. A table on this trait would give the guard and its test the
 * same source, and a check that reads its expectation out of the thing it is
 * checking is not a check.
 *
 * `self::class` rather than `static::class`: a trait is flattened into the using
 * class, so `self` is `PurchaseOrder` even when `$this` is a Doctrine proxy that
 * extends it. The proxy carries no `#[ORM\Table]` attribute of its own, and
 * `IllegalTransition` reads the rule code off that attribute.
 */
trait GuardsStatusTransitions
{
    abstract public function getStatus(): string;

    /**
     * Callers pass `__FUNCTION__`, so the method name in the refusal is the
     * method that refused rather than a string that can drift away from it.
     *
     * @param non-empty-list<string> $legalFrom `STATUS_*` constants of the using class — never literals
     */
    private function refuseUnlessStatusIsOneOf(string $method, array $legalFrom): void
    {
        if (!\in_array($this->getStatus(), $legalFrom, true)) {
            throw IllegalTransition::of(self::class, $method, $this->getStatus(), $legalFrom);
        }
    }
}
