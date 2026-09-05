<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Forces the cart quote to recollect totals when the cart page is viewed, so the
 * ApplyMemberPrice observer re-applies the CURRENT member price.
 *
 * Without this, an item keeps the custom_price persisted when it was added, so a
 * changed applicable price does not show — e.g. a shopper who becomes a member
 * (or whose membership/discount rules change) while an item sits in the cart.
 *
 * Best-effort and gated on the pricing engine being enabled.
 */
class RecollectCartTotals implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        if (!$this->config->isPricingEnabled()) {
            return;
        }
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote->getId() || !$quote->getItemsCount()) {
                return;
            }
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            // Persist so the mini-cart / customer-data section reflect refreshed prices too.
            $this->cartRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] cart recollect failed: ' . $e->getMessage());
        }
    }
}
