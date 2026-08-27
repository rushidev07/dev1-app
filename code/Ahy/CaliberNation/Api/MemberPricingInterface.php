<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Api;

use Ahy\CaliberNation\Api\Data\MemberPriceInterface;

/**
 * Read-only member-pricing service contract for the current (token-authenticated)
 * customer. Returns the effective member price for a product plus whether this
 * customer is an active member. No write/payment surface.
 */
interface MemberPricingInterface
{
    /**
     * @param int $productId
     * @return \Ahy\CaliberNation\Api\Data\MemberPriceInterface
     */
    public function getMemberPrice(int $productId): MemberPriceInterface;
}
