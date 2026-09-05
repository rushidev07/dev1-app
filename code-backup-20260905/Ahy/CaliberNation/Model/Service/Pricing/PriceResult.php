<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service\Pricing;

/**
 * Immutable result of a member-price resolution.
 *
 * @property-read array<int,array{layer:string,type:string,value:float,discount:float,target_id?:int}> $layers
 */
class PriceResult
{
    /**
     * @param array<int,array{layer:string,type:string,value:float,discount:float,target_id?:int}> $layers
     */
    public function __construct(
        public readonly float $regularPrice,
        public readonly float $memberPrice,
        public readonly float $rawDiscount,
        public readonly float $discount,
        public readonly bool $capped,
        public readonly array $layers
    ) {}

    public function hasDiscount(): bool
    {
        return $this->discount > 0 && $this->memberPrice < $this->regularPrice;
    }

    /** Savings amount for a given quantity. */
    public function savingsForQty(float $qty): float
    {
        return round(($this->regularPrice - $this->memberPrice) * $qty, 2);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'regular_price' => $this->regularPrice,
            'member_price'  => $this->memberPrice,
            'raw_discount'  => $this->rawDiscount,
            'discount'      => $this->discount,
            'capped'        => $this->capped,
            'layers'        => $this->layers,
        ];
    }
}
