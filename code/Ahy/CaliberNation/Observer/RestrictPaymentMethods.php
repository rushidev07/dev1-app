<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Config;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * When the membership product is in the cart, only credit-card / vault payment
 * methods are offered — a vaultable card is required for auto-renewal.
 * Hooks the core `payment_method_is_active` event.
 */
class RestrictPaymentMethods implements ObserverInterface
{
    /** Payment methods permitted while a membership is in the cart. */
    private const ALLOWED_METHODS = [
        'authnetahypayment',
        'customervaultcards',
        'braintree',
        'braintree_cc_vault',
    ];

    public function __construct(
        private readonly Config $config
    ) {}

    public function execute(Observer $observer): void
    {
        $quote  = $observer->getEvent()->getData('quote');
        $method = $observer->getEvent()->getData('method_instance');
        $result = $observer->getEvent()->getData('result');

        if (!$quote || !$method || !$result) {
            return;
        }

        if (!$this->config->isEnabled()) {
            return;
        }

        if (!$this->quoteHasMembership($quote)) {
            return;
        }

        if (!\in_array($method->getCode(), self::ALLOWED_METHODS, true)) {
            $result->setData('is_available', false);
        }
    }

    private function quoteHasMembership($quote): bool
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
