<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Plugin\Quote;

use Ahy\CaliberNation\Model\Config;
use Magento\Framework\Validation\ValidationResultFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ValidationRules\ShippingMethodValidationRule;

/**
 * Bypasses Magento's shipping-method-required validation when the membership
 * product is in the cart.
 *
 * Why it's needed: The Amasty promo auto-adds a physical free decal to the cart
 * alongside the virtual membership product. This makes Quote::isVirtual() return
 * false, causing ShippingMethodValidationRule to require a shipping method that
 * was never set (the checkout flow is virtual-only). The physical decal is a
 * $0 promotional gift — it is always shipped free by the seller and does not
 * need a shipping method selected at checkout.
 */
class BypassShippingForMembershipCart
{
    public function __construct(
        private readonly Config $config,
        private readonly ValidationResultFactory $validationResultFactory
    ) {}

    public function aroundValidate(
        ShippingMethodValidationRule $subject,
        callable $proceed,
        Quote $quote
    ): array {
        if ($this->cartHasMembership($quote)) {
            return [$this->validationResultFactory->create(['errors' => []])];
        }

        return $proceed($quote);
    }

    private function cartHasMembership(Quote $quote): bool
    {
        $sku = $this->config->getMembershipSku();
        foreach ($quote->getAllItems() as $item) {
            if ($item->getSku() === $sku) {
                return true;
            }
        }
        return false;
    }
}
