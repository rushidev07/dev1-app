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
 * Fires on: checkout_cart_product_add_after (frontend scope)
 * Event data:
 *   quote_item — Magento\Quote\Model\Quote\Item (child item for configurables)
 *   product    — Magento\Catalog\Model\Product  (the product being added)
 *
 * For configurable products Magento passes the child (simple) item; we walk
 * up to the parent so the tracked product_id matches the configurable.
 */
class AddToCartObserver implements ObserverInterface
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
            /** @var \Magento\Quote\Model\Quote\Item $quoteItem */
            $quoteItem = $observer->getData('quote_item');
            if (!$quoteItem) {
                return;
            }

            // Walk up to the parent (configurable/bundle) so we track the
            // purchasable product, not an invisible child simple.
            $item = $quoteItem->getParentItem() ?? $quoteItem;

            if (!$item->getProductId()) {
                return;
            }

            $customerId = $this->customerSession->isLoggedIn()
                ? (int) $this->customerSession->getCustomerId()
                : null;

            $storeId = (int) $this->storeManager->getStore()->getId();

            $this->eventService->trackAddToCart(
                productId:  (int) $item->getProductId(),
                sku:        (string) $item->getSku(),
                name:       (string) $item->getName(),
                price:      (float) $item->getPrice(),
                qty:        (float) $item->getQty(),
                customerId: $customerId,
                storeId:    $storeId
            );
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearch][Events] AddToCart observer error: ' . $e->getMessage());
        }
    }
}
