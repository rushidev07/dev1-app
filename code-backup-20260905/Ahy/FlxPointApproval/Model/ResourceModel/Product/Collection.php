<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Model\ResourceModel\Product;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init(
            \Ahy\FlxPointApproval\Model\Product::class,
            \Ahy\FlxPointApproval\Model\ResourceModel\Product::class
        );
    }
}
