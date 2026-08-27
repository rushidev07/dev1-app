<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\ExpiredMemberLocator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * On login, auto-adds the membership product to an expired member's cart so they
 * can renew (spec §6). Non-fatal + deduped; the win-back price observer discounts
 * it if eligible.
 *
 * Uses CartRepositoryInterface directly instead of Checkout\Model\Cart because
 * Cart relies on CheckoutSession which is not yet hydrated with the customer's
 * quote at the moment customer_login fires — causing it to silently operate on
 * an empty guest quote and never actually add the product.
 */
class AutoAddMembershipForExpired implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ExpiredMemberLocator $expiredMemberLocator,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $customer = $observer->getEvent()->getData('customer');
        if (!$customer || !$customer->getId()) {
            return;
        }

        $customerId = (int) $customer->getId();

        if (!$this->expiredMemberLocator->isExpired($customerId)) {
            return;
        }

        try {
            $sku = $this->config->getMembershipSku();

            // Load the customer's active quote directly by customer ID.
            // This bypasses CheckoutSession which is not yet populated at login time.
            try {
                $quote = $this->cartRepository->getActiveForCustomer($customerId);
            } catch (NoSuchEntityException) {
                // No active quote — create one.
                $quoteId = $this->cartManagement->createEmptyCartForCustomer($customerId);
                $quote = $this->cartRepository->get($quoteId);
            }

            // Dedup: skip if membership already in cart.
            foreach ($quote->getAllItems() as $item) {
                if ($item->getSku() === $sku) {
                    return;
                }
            }

            $product = $this->productRepository->get($sku);
            $quote->addProduct($product, 1);
            // Force totals recollection so the win-back price observer applies
            // the discounted price immediately on the next page load.
            $quote->setTotalsCollectedFlag(false);
            $this->cartRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] auto-add membership for expired member failed: ' . $e->getMessage());
        }
    }
}
