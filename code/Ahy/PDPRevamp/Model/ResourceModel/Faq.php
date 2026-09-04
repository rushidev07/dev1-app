<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Faq extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ahy_pdprevamp_faq', 'faq_id');
    }
}
