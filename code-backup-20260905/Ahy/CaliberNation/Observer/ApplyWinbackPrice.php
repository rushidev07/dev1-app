<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\ExpiredMemberLocator;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Applies the admin-configured Day-31 win-back discount to the membership line
 * for lapsed members who are past the expiry threshold. Runs before totals are
 * collected so the discounted custom price is used everywhere (cart, checkout, order).
 */
class ApplyWinbackPrice implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ExpiredMemberLocator $expiredMemberLocator
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $quote = $observer->getEvent()->getData('quote');
        if (!$quote || !$quote->getCustomerId()) {
            return;
        }

        $sku      = $this->config->getMembershipSku();
        $eligible = $this->expiredMemberLocator->isWinbackEligible((int) $quote->getCustomerId());
        $price    = $this->config->getWinbackPrice();

        foreach ($quote->getAllItems() as $item) {
            if ($item->getSku() !== $sku) {
                continue;
            }

            if ($eligible) {
                $item->setCustomPrice($price);
                $item->setOriginalCustomPrice($price);
                if ($item->getProduct()) {
                    $item->getProduct()->setIsSuperMode(true);
                }
            } elseif ($item->getCustomPrice() !== null) {
                // No longer win-back eligible → drop any frozen win-back price so the
                // catalog price is used again on this recollection.
                $item->setCustomPrice(null);
                $item->setOriginalCustomPrice(null);
            }
        }
    }
}
