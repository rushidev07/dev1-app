<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Category- or product-scope member discount rule (additive layer).
 */
class MemberPriceRule extends AbstractModel
{
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_PRODUCT  = 'product';

    protected function _construct(): void
    {
        $this->_init(\Ahy\CaliberNation\Model\ResourceModel\MemberPriceRule::class);
    }
}
