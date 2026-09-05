<?php
declare(strict_types=1);

namespace FalcoSense\Search\Observer;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Service\CustomerEventService;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires on: customer_register_success (frontend scope)
 * Event data: $observer->getCustomer() — newly created Magento\Customer\Model\Customer
 */
class CustomerRegisterObserver implements ObserverInterface
{
    public function __construct(
        private readonly Data                  $helper,
        private readonly CustomerEventService  $eventService,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        try {
            $customer = $observer->getData('customer');
            if (!$customer || !$customer->getId()) {
                return;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();

            $this->eventService->trackRegister(
                customerId: (int) $customer->getId(),
                storeId:    $storeId
            );
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearch][Events] CustomerRegister observer error: ' . $e->getMessage());
        }
    }
}
