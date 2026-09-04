<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel\Faq;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(\Ahy\PDPRevamp\Model\Faq::class, \Ahy\PDPRevamp\Model\ResourceModel\Faq::class);
    }
}
