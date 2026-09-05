<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SellerParticipation extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ahy_caliber_nation_seller_participation', 'entity_id');
    }
}
