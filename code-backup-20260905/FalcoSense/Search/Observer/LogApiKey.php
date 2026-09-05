<?php
/**
 * Copyright © FalcoSense All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace FalcoSense\Search\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use FalcoSense\Search\Helper\Data;
use Psr\Log\LoggerInterface;

class LogApiKey implements ObserverInterface
{
    public function __construct(
        private Data $helper,
        private LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        $apiKey = $this->helper->getApiKey();
        $this->logger->info('[SmartSearch] API Key: ' . $apiKey);
    }
}
