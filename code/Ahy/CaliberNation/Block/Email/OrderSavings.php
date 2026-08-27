<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Block\Email;

use Ahy\CaliberNation\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Adds a "Caliber Nation member savings" line to the order totals in the
 * transactional order email — the same mechanism the core tax line uses
 * (child of `order_totals`, `initTotals()` called by the Totals block).
 *
 * Order-level total from ahy_caliber_nation_order_savings. Renders only when the
 * order has member savings > 0. (Per-line savings in the email would require an
 * items-template override — deferred; the total is the high-value signal.)
 */
class OrderSavings extends Template
{
    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Called by Magento\Sales\Block\Order\Totals on each child block.
     */
    public function initTotals(): self
    {
        $parent = $this->getParentBlock();
        if (!$parent || !$this->config->isPricingEnabled()) {
            return $this;
        }
        $order = $parent->getOrder();
        if (!$order || !$order->getId()) {
            return $this;
        }

        $savings = $this->getOrderSavings((int) $order->getId());
        if ($savings <= 0) {
            return $this;
        }

        // Negative value renders like a discount line ("-$X"), signalling money saved.
        $parent->addTotal(new DataObject([
            'code'        => 'caliber_member_savings',
            'label'       => __('Caliber Nation member savings'),
            'value'       => -1 * $savings,
            'is_formated' => false,
        ]));

        return $this;
    }

    private function getOrderSavings(int $orderId): float
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('ahy_caliber_nation_order_savings');
        $sum   = $conn->fetchOne(
            $conn->select()->from($table, ['s' => 'SUM(savings)'])->where('order_id = ?', $orderId)
        );
        return round((float) $sum, 2);
    }
}
