<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Plugin;

use Magento\Framework\Event\Observer;
use Webkul\MpMultiShipping\Observer\SalesOrderPlaceAfterObserver;

/**
 * Compatibility fix for the Webkul MpMultiShipping order-placed observer on
 * VIRTUAL orders (e.g. a Caliber Nation membership bought on its own).
 *
 * That observer does `strpos($order->getShippingMethod(), 'mpmultishipping')`,
 * but a virtual order has NO shipping method (null) → PHP 8 TypeError. The whole
 * block only distributes shipping cost across sellers, which is irrelevant without
 * shipping. So we skip the observer when the order has no shipping method.
 */
class WebkulVirtualOrderShippingFix
{
    public function aroundExecute(
        SalesOrderPlaceAfterObserver $subject,
        callable $proceed,
        Observer $observer
    ) {
        $order = $observer->getOrder();

        // Single virtual / shipping-less order → nothing for multi-shipping to do.
        if ($order && ($order->getIsVirtual() || !$order->getShippingMethod())) {
            return null;
        }

        return $proceed($observer);
    }
}
