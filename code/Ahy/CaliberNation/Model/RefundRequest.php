<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model;

use Magento\Framework\Model\AbstractModel;

class RefundRequest extends AbstractModel
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_REVIEWED   = 'reviewed';
    public const STATUS_REINSTATED = 'reinstated';

    protected function _construct(): void
    {
        $this->_init(\Ahy\CaliberNation\Model\ResourceModel\RefundRequest::class);
    }
}
