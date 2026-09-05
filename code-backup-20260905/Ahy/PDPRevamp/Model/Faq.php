<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model;

use Magento\Framework\Model\AbstractModel;

class Faq extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(\Ahy\PDPRevamp\Model\ResourceModel\Faq::class);
    }
}
