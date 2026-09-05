<?php
declare(strict_types=1);

namespace FalcoSense\Search\Observer;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Service\CustomerEventService;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires on: wishlist_add_product (frontend scope)
 * Event data: product — Magento\Catalog\Model\Product
 *             wishlist — Magento\Wishlist\Model\Wishlist
 */
class WishlistAddObserver implements ObserverInterface
{
    public function __construct(
        private readonly Data                  $helper,
        private readonly CustomerEventService  $eventService,
        private readonly CustomerSession       $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $observer->getData('product');
            if (!$product || !$product->getId()) {
                return;
            }

            $customerId = $this->customerSession->isLoggedIn()
                ? (int) $this->customerSession->getCustomerId()
                : null;

            $storeId = (int) $this->storeManager->getStore()->getId();

            $this->eventService->trackWishlistAdd(
                productId:  (int) $product->getId(),
                sku:        (string) $product->getSku(),
                name:       (string) $product->getName(),
                price:      (float) $product->getFinalPrice(),
                customerId: $customerId,
                storeId:    $storeId
            );
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearch][Events] WishlistAdd observer error: ' . $e->getMessage());
        }
    }
}
