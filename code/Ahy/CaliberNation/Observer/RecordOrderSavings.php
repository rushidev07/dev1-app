<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\SavingsRecorder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Records member savings once an order is placed (P4). Delegates to SavingsRecorder,
 * which is idempotent, member-gated, and best-effort.
 */
class RecordOrderSavings implements ObserverInterface
{
    public function __construct(
        private readonly SavingsRecorder $savingsRecorder,
        private readonly Config $config
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        /** @var OrderInterface|null $order */
        $order = $observer->getEvent()->getData('order');
        if ($order) {
            $this->savingsRecorder->record($order);
        }
    }
}
