<?php
declare(strict_types=1);

namespace FalcoSense\Search\Observer;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Service\CustomerEventService;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class PurchaseObserver implements ObserverInterface
{
    private const LOG_FILE = BP . '/var/log/smartsearch-analytics.log';

    public function __construct(
        private readonly Data                  $helper,
        private readonly CustomerEventService  $eventService,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        $this->log('--- PurchaseObserver::execute() FIRED ---');
        $this->log('enabled=' . ($this->helper->isEnabled() ? 'yes' : 'no'));

        if (!$this->helper->isEnabled()) {
            $this->log('SKIP: SmartSearch disabled');
            return;
        }

        try {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $observer->getData('order');

            if (!$order || !$order->getIncrementId()) {
                $this->log('ERROR: No order object in event data. observer keys=' . implode(',', array_keys($observer->getData())));
                return;
            }

            // Entity ID may be null if order not yet persisted — use increment ID as primary key
            $orderId    = $order->getId() ?: $order->getIncrementId();
            $storeId    = (int) $this->storeManager->getStore()->getId();
            $customerId = $order->getCustomerId() !== null ? (int) $order->getCustomerId() : null;

            $this->log(sprintf(
                'order_id=%s increment=%s grand_total=%s store_id=%s customer_id=%s',
                $orderId,
                $order->getIncrementId(),
                $order->getGrandTotal(),
                $storeId,
                $customerId ?? 'guest'
            ));

            $items = [];
            foreach ($order->getAllVisibleItems() as $item) {
                $this->log('  item: product_id=' . $item->getProductId() . ' sku=' . $item->getSku() . ' qty=' . $item->getQtyOrdered());
                $items[] = [
                    'product_id'  => (string) $item->getProductId(),
                    'sku'         => (string) $item->getSku(),
                    'name'        => (string) $item->getName(),
                    'price'       => (float) $item->getPrice(),
                    'qty_ordered' => (float) $item->getQtyOrdered(),
                    'row_total'   => (float) $item->getRowTotal(),
                ];
            }

            if (empty($items)) {
                $this->log('SKIP: no visible items');
                return;
            }

            $this->log('Calling CustomerEventService::trackPurchase() with ' . count($items) . ' items');

            $this->eventService->trackPurchase(
                orderId:     (string) $orderId,
                orderNumber: (string) $order->getIncrementId(),
                grandTotal:  (float) $order->getGrandTotal(),
                currency:    (string) $order->getOrderCurrencyCode(),
                items:       $items,
                customerId:  $customerId,
                storeId:     $storeId
            );

            $this->log('trackPurchase() returned successfully');
        } catch (\Throwable $e) {
            $this->log('EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->logger->error('[SmartSearch][Events] Purchase observer error: ' . $e->getMessage());
        }
    }

    private function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] [PurchaseObserver] ' . $msg . PHP_EOL;
        file_put_contents(self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }
}
