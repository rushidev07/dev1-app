<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model\Discount;

/**
 * One discount band in the alert summary. Immutable value object.
 */
class Bracket
{
    /**
     * @param float      $from Inclusive lower bound, as a percentage.
     * @param float|null $to   Exclusive upper bound, or null for an open-ended band.
     */
    public function __construct(
        private readonly float $from,
        private readonly ?float $to,
        private readonly string $label
    ) {}

    public function getFrom(): float
    {
        return $this->from;
    }

    public function getTo(): ?float
    {
        return $this->to;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function contains(float $percentage): bool
    {
        return $percentage >= $this->from
            && ($this->to === null || $percentage < $this->to);
    }
}
