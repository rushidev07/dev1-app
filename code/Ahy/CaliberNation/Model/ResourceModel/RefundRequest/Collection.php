<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\ResourceModel\RefundRequest;

use Ahy\CaliberNation\Model\RefundRequest;
use Ahy\CaliberNation\Model\ResourceModel\RefundRequest as RefundRequestResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(RefundRequest::class, RefundRequestResource::class);
    }
}
