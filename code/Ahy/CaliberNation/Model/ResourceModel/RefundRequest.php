<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class RefundRequest extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ahy_caliber_nation_refund_request', 'entity_id');
    }
}
