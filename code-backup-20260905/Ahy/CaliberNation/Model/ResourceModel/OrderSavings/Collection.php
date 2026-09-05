<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\ResourceModel\OrderSavings;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct(): void
    {
        $this->_init(
            \Ahy\CaliberNation\Model\OrderSavings::class,
            \Ahy\CaliberNation\Model\ResourceModel\OrderSavings::class
        );
    }
}
