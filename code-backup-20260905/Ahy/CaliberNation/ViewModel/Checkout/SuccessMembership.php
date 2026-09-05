<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\ViewModel\Checkout;

use Ahy\CaliberNation\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells the checkout success page whether the order that was JUST placed contained
 * the Caliber Nation membership product — so the "Thank you" page can congratulate
 * the shopper on becoming a member. Detection matches on the configured membership
 * SKU (same definition RestrictPaymentMethods / MembershipInCart use).
 */
class SuccessMembership implements ArgumentInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {}

    /** True when the just-placed order includes the membership product. */
    public function justBecameMember(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }
        try {
            $sku = $this->config->getMembershipSku();
            if (!$sku) {
                return false;
            }
            $order = $this->checkoutSession->getLastRealOrder();
            if (!$order || !$order->getId()) {
                return false;
            }
            foreach ($order->getAllItems() as $item) {
                if ($item->getSku() === $sku) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] SuccessMembership check failed: ' . $e->getMessage());
        }
        return false;
    }
}
