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
 * Fires on: catalog_controller_product_view (frontend scope)
 * Event data: $observer->getProduct() — current Magento\Catalog\Model\Product
 *
 * Uses getFinalPrice() — reflects tier/special prices, not just the base price.
 * CustomerSession is injected as a proxy so it doesn't force session start on non-PDP pages.
 */
class ProductViewObserver implements ObserverInterface
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
            $product = $observer->getData('product');
            if (!$product || !$product->getId()) {
                return;
            }

            $customerId = $this->customerSession->isLoggedIn()
                ? (int) $this->customerSession->getCustomerId()
                : null;

            $storeId = (int) $this->storeManager->getStore()->getId();

            $this->eventService->trackProductView(
                productId:  (int) $product->getId(),
                sku:        (string) $product->getSku(),
                name:       (string) $product->getName(),
                price:      (float) $product->getFinalPrice(),
                customerId: $customerId,
                storeId:    $storeId
            );
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearch][Events] ProductView observer error: ' . $e->getMessage());
        }
    }
}
