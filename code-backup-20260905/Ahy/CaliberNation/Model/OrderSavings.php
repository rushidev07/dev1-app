<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Per-line member savings recorded on a completed order.
 */
class OrderSavings extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(\Ahy\CaliberNation\Model\ResourceModel\OrderSavings::class);
    }
}
