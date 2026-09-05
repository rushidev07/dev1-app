<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Plugin;

use Hyva\Checkout\Observer\Frontend\HyvaCheckoutHyvaCheckoutInitAfter;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;

/**
 * Compatibility fix for Hyva checkout on a VIRTUAL cart (e.g. buying only the
 * Caliber Nation membership).
 *
 * Hyva\Checkout\...\HyvaCheckoutHyvaCheckoutInitAfter::processBillingAddressForVirtualCart()
 * assumes a logged-in customer always has at least one address: it calls
 * getPrimaryBillingAddress() and, when that returns `false` (a brand-new account
 * with no saved address), dereferences it — `->getData()` on a bool — and fatals.
 *
 * This around-plugin detects the "logged-in customer with no usable address"
 * case and skips the broken import path: it just ensures a default country on the
 * quote billing address and saves, letting the checkout collect the real billing
 * address normally. In every other case it defers to the original method.
 */
class HyvaCheckoutVirtualBillingFix
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly DirectoryHelper $directoryHelper,
        private readonly CartRepositoryInterface $quoteRepository
    ) {}

    public function aroundProcessBillingAddressForVirtualCart(
        HyvaCheckoutHyvaCheckoutInitAfter $subject,
        callable $proceed,
        Quote $quote
    ): Quote {
        $billing = $quote->getBillingAddress();

        if ($billing->validate() !== true && $this->customerSession->isLoggedIn()) {
            $customer      = $this->customerSession->getCustomer();
            $hasPrimary    = (bool) $customer->getPrimaryBillingAddress();
            $hasAdditional = \count($customer->getAdditionalAddresses() ?: []) > 0;

            // The exact case the core method cannot handle → guard it.
            if (!$hasPrimary && !$hasAdditional) {
                if ($billing->getCountryId() === null) {
                    $billing->setCountryId($this->directoryHelper->getDefaultCountry());
                }
                $this->quoteRepository->save($quote);
                return $quote;
            }
        }

        return $proceed($quote);
    }
}
