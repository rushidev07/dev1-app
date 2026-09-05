<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Model;

use Magento\Framework\Model\AbstractModel;

class Product extends AbstractModel
{
    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    protected function _construct()
    {
        $this->_init(\Ahy\FlxPointApproval\Model\ResourceModel\Product::class);
    }
}
