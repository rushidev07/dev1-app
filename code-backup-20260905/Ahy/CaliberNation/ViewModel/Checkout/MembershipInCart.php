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
 * Tells checkout templates whether the Caliber Nation membership product is in the
 * current cart. Used by the save-card checkbox to explain that the saved card also
 * powers the membership's annual auto-renewal. Detection mirrors
 * RestrictPaymentMethods (match on the configured membership SKU) so both agree on
 * what "membership in cart" means.
 */
class MembershipInCart implements ArgumentInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {}

    public function isInCart(): bool
    {
        try {
            $sku   = $this->config->getMembershipSku();
            $quote = $this->checkoutSession->getQuote();
            if (!$sku || !$quote) {
                return false;
            }
            foreach ($quote->getAllItems() as $item) {
                if ($item->getSku() === $sku) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] MembershipInCart check failed: ' . $e->getMessage());
        }
        return false;
    }
}
